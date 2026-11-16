<?php
/**
 * Pont sécurisé PHP -> Python.
 *
 * - proc_open avec un TABLEAU d'arguments : aucun passage par le shell,
 *   donc aucune injection de commande possible via un nom de fichier ;
 * - délai maximal configurable ;
 * - sortie JSON uniquement, codes d'erreur normalisés ;
 * - la phrase de passe du keystore, si utilisée, passe par l'environnement
 *   du processus (jamais dans la ligne de commande).
 */
declare(strict_types=1);

final class CryptoBridgeError extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message
    ) {
        parent::__construct($message);
    }
}

/**
 * Exécute un script Python du dossier crypto/ et retourne son JSON de sortie.
 *
 * @param list<string> $args
 * @return array<string,mixed>
 */
function run_python_script(string $script, array $args, ?int $timeoutSeconds = null): array
{
    $python = (string) config('python.bin');
    $isAbsolute = preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $script) === 1;
    $scriptPath = $isAbsolute
        ? $script
        : rtrim((string) config('crypto.dir'), '/\\') . DIRECTORY_SEPARATOR . $script;
    $timeout = $timeoutSeconds ?? (int) config('python.timeout', 60);

    if (!is_file($scriptPath)) {
        throw new CryptoBridgeError('CRYPTO_SCRIPT_MISSING', 'Script cryptographique introuvable.');
    }

    // -X utf8 : garantit un JSON UTF-8 quel que soit le locale Windows.
    $command = array_merge([$python, '-X', 'utf8', $scriptPath], $args);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = @proc_open(
        $command,
        $descriptors,
        $pipes,
        (string) config('root'),
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new CryptoBridgeError(
            'CRYPTO_PROCESS_ERROR',
            'Impossible de lancer le processus cryptographique. Vérifiez PYTHON_BIN.'
        );
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $exitCode = -1;
    $startedAt = microtime(true);

    while (true) {
        $status = proc_get_status($process);

        $read = [];
        if (!feof($pipes[1])) {
            $read[] = $pipes[1];
        }
        if (!feof($pipes[2])) {
            $read[] = $pipes[2];
        }
        if ($read !== []) {
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 0, 200000);
            if ($ready !== false && $ready > 0) {
                foreach ($read as $stream) {
                    $chunk = fread($stream, 8192);
                    if ($chunk === false || $chunk === '') {
                        continue;
                    }
                    if ($stream === $pipes[1]) {
                        $stdout .= $chunk;
                    } else {
                        $stderr .= $chunk;
                    }
                }
            }
        }

        if (!$status['running']) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $exitCode = (int) $status['exitcode'];
            break;
        }

        if ((microtime(true) - $startedAt) > $timeout) {
            proc_terminate($process, 9);
            foreach ([$pipes[1], $pipes[2]] as $pipe) {
                @fclose($pipe);
            }
            proc_close($process);
            throw new CryptoBridgeError(
                'CRYPTO_TIMEOUT',
                'Le traitement cryptographique a dépassé le délai autorisé.'
            );
        }
        usleep(10000);
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    $closedCode = proc_close($process);
    if ($exitCode < 0 && $closedCode >= 0) {
        $exitCode = $closedCode;
    }

    $decoded = json_decode(trim($stdout), true);
    if ($exitCode !== 0) {
        $errorCode = 'CRYPTO_PROCESS_ERROR';
        $message = 'Le traitement cryptographique a échoué.';
        $errorPayload = json_decode(trim($stderr), true);
        if (is_array($errorPayload)) {
            $errorCode = (string) ($errorPayload['error'] ?? $errorCode);
            $message = (string) ($errorPayload['message'] ?? $message);
        }
        error_log(sprintf(
            'CryptoBridge [%s] code=%d stderr=%s',
            $script,
            $exitCode,
            substr($stderr !== '' ? $stderr : $stdout, 0, 500)
        ));
        throw new CryptoBridgeError($errorCode, $message);
    }

    if (!is_array($decoded)) {
        error_log('CryptoBridge : sortie JSON invalide pour ' . $script . ' : ' . substr($stdout, 0, 300));
        throw new CryptoBridgeError(
            'CRYPTO_OUTPUT_INVALID',
            'Réponse invalide du module cryptographique.'
        );
    }
    return $decoded;
}

/** @return array<string,mixed> */
function crypto_encrypt(string $inputPath, string $outputPath): array
{
    if (!is_file($inputPath) || !is_readable($inputPath)) {
        throw new CryptoBridgeError('INPUT_NOT_FOUND', 'Fichier en clair introuvable.');
    }
    $result = run_python_script('chiffrer.py', [$inputPath, $outputPath]);

    foreach (['nonce', 'tag', 'sha256', 'chemin'] as $key) {
        if (!isset($result[$key]) || !is_string($result[$key])) {
            throw new CryptoBridgeError('CRYPTO_OUTPUT_INVALID', 'Métadonnées cryptographiques incomplètes.');
        }
    }
    if (!preg_match('/^[a-f0-9]{24}$/', $result['nonce'])
        || !preg_match('/^[a-f0-9]{32}$/', $result['tag'])
        || !preg_match('/^[a-f0-9]{64}$/', $result['sha256'])
    ) {
        throw new CryptoBridgeError('CRYPTO_OUTPUT_INVALID', 'Métadonnées cryptographiques mal formées.');
    }
    if (!is_file($outputPath)) {
        throw new CryptoBridgeError('CRYPTO_OUTPUT_MISSING', 'Le fichier chiffré n’a pas été créé.');
    }
    return $result;
}

/** @return array<string,mixed> */
function crypto_decrypt(string $encryptedPath, string $outputPath, ?string $expectedSha256 = null): array
{
    if (!is_file($encryptedPath) || !is_readable($encryptedPath)) {
        throw new CryptoBridgeError('ENCRYPTED_FILE_NOT_FOUND', 'Fichier chiffré introuvable.');
    }
    $args = [$encryptedPath, $outputPath];
    if ($expectedSha256 !== null && $expectedSha256 !== '') {
        $args[] = '--expected-sha256';
        $args[] = $expectedSha256;
    }
    return run_python_script('dechiffrer.py', $args);
}

/**
 * Génère la clé maître si elle n'existe pas encore (démo / premier démarrage).
 * En production, la clé est générée et sauvegardée hors ligne par un opérateur.
 */
function generate_master_key_if_missing(): void
{
    $path = (string) config('crypto.key_file');
    if (is_file($path)) {
        return;
    }
    $args = ['--key-file', $path];
    if (str_ends_with($path, '.enc')) {
        $args[] = '--openssl';
    }
    run_python_script('generate_key.py', $args, 120);
}

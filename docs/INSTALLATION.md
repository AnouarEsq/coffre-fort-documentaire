# Installation et exploitation

## 1. Prérequis

| Composant | Version minimale | Extensions / paquets |
|---|---|---|
| PHP | 8.1 (testé 8.3) | `pdo_pgsql`, `pgsql`, `fileinfo`, `mbstring`, `openssl`, `session`, `json` |
| PostgreSQL | 14 (testé 17) | rôle applicatif + base dédiée |
| Python | 3.10 (testé 3.12) | `cryptography` (`pip install -r requirements.txt`) |
| OpenSSL | 3.x (optionnel) | protection du keystore |
| Navigateur | récent | JavaScript activé pour les validations d'upload et les animations (facultatif : l'application fonctionne sans JavaScript) |

## 2. Configuration

```powershell
Copy-Item .env.example .env
```

Variables principales :

| Variable | Rôle | Exemple |
|---|---|---|
| `DB_HOST`, `DB_PORT` | serveur PostgreSQL | `127.0.0.1`, `5432` |
| `DB_NAME` | base | `coffre_fort` |
| `DB_USER` / `DB_PASSWORD` | rôle applicatif (créé par `init_db.php`) | `coffre_app` / mot de passe fort |
| `DB_ADMIN_USER` / `DB_ADMIN_PASSWORD` | superutilisateur utilisé **uniquement** par l'initialisation | `postgres` |
| `PYTHON_BIN` | interpréteur Python | `python` ou chemin absolu |
| `COFFRE_MASTER_KEY_FILE` | keystore (optionnel) | chemin absolu |
| `COFFRE_KEY_PASSPHRASE` | phrase de passe du keystore OpenSSL (optionnel) | ≥ 12 caractères |
| `UPLOAD_MAX_BYTES` | taille maximale d'upload | `10485760` (10 Mo) |

`.env` contient des secrets : il est exclu de Git (`.gitignore`) et ne doit
jamais être servi par le serveur web.

## 3. Extensions PHP (Windows)

Le paquet ZIP officiel de PHP n'active pas les extensions par défaut. Dans
`php.ini` :

```ini
extension_dir = "C:\chemin\vers\php\ext"
extension=fileinfo
extension=pdo_pgsql
extension=pgsql
extension=mbstring
extension=openssl
extension=curl
date.timezone = Europe/Paris
session.cookie_httponly = 1
session.use_strict_mode = 1
upload_max_filesize = 12M
post_max_size = 13M
memory_limit = 256M
```

Vérifier :

```powershell
php -m | Select-String "pgsql|fileinfo|mbstring|openssl"
```

## 4. Base de données

### Méthode automatique (recommandée)

```powershell
php scripts/init_db.php
```

Le script :

1. se connecte avec `DB_ADMIN_USER` à la base `postgres` ;
2. crée le rôle `DB_USER` s'il n'existe pas (ou synchronise son mot de passe) ;
3. crée la base `DB_NAME` si nécessaire ;
4. applique `schema_coffre_fort.sql`, `schema_hardening.sql`,
   `schema_extensions.sql`.

### Méthode manuelle

```sql
CREATE ROLE coffre_app LOGIN PASSWORD 'mot-de-passe-fort';
CREATE DATABASE coffre_fort OWNER coffre_app ENCODING 'UTF8' TEMPLATE template0;
\c coffre_fort
\i schema_coffre_fort.sql
\i schema_hardening.sql
\i schema_extensions.sql
```

Vérifier les triggers append-only :

```sql
SELECT tgname FROM pg_trigger WHERE tgrelid = 'audit_log'::regclass;
-- trg_audit_log_no_mutation, trg_audit_log_no_truncate

UPDATE audit_log SET action = 'LOGIN' WHERE id = 1;
-- ERREUR : audit_log est append-only : UPDATE est interdit
```

## 5. Clé maître

```powershell
python -m pip install -r requirements.txt

# Fichier brut 0600 (mode par défaut)
python crypto/generate_key.py

# OU keystore protégé par OpenSSL
$env:COFFRE_KEY_PASSPHRASE = "phrase-de-passe-de-12-caracteres"
python crypto/generate_key.py --openssl
```

En mode OpenSSL, renseigner dans `.env` :

```ini
COFFRE_MASTER_KEY_FILE=C:\Users\hp\Desktop\Stage\crypto\keystore\master.key.enc
COFFRE_KEY_PASSPHRASE=phrase-de-passe-de-12-caracteres
```

> **Sauvegardez la clé hors ligne.** Sans elle, les documents sont
> irrécupérables. Ne la committez jamais.

Vérification rapide :

```powershell
python crypto/chiffrer.py README.md "$env:TEMP\test.enc"
python crypto/dechiffrer.py "$env:TEMP\test.enc" "$env:TEMP\test.out"
Get-FileHash README.md, "$env:TEMP\test.out" -Algorithm SHA256
```

## 6. Données de démonstration

```powershell
php scripts/seed_demo.php
php scripts/export_matrix.php
```

`seed_demo.php` crée les 4 comptes, génère 3 PDF fictifs, les chiffre et insère
les métadonnées + les événements `UPLOAD`. Les PDF en clair sont supprimés.

## 7. Lancement

### Scripts locaux (Windows)

```powershell
.\demarrer.ps1   # PostgreSQL + serveur PHP + navigateur
.\arreter.ps1    # arrêt du serveur PHP et de PostgreSQL
```

Les chemins par défaut sont surchargeables par `PG_BIN`, `PG_DATA` et
`PHP_BIN`. Si l'exécution de scripts est bloquée :

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\demarrer.ps1
```

### Serveur PHP intégré (développement)

```powershell
php -S 127.0.0.1:8000 -t public
```

Puis ouvrir <http://127.0.0.1:8000>.

### Ressources front-end (aucune installation)

L'interface utilise :

- **Inter** et **JetBrains Mono** (fichiers `.woff2` variables), déjà présents
  dans `public/assets/fonts/` — 88 Ko au total, aucun appel à Google Fonts ;
- **GSAP 3.13**, **ScrollTrigger 3.13** et **Lenis 1.3.11**, déjà présents dans
  `public/assets/vendor/` ;
- `theme.js` (bascule clair/sombre) et `animations.js`, écrits pour le projet.

Il n'y a :

- ni `npm install`, ni `package.json`, ni étape de build ;
- ni CDN, ni requête réseau externe : la démonstration fonctionne hors ligne.

Lors d'un déploiement (copie vers un serveur, archive de rendu), il faut donc
inclure `public/assets/` **en entier**, y compris `vendor/` et `fonts/`. Si ces
fichiers manquent, l'application reste utilisable : la pile de polices système
prend le relais, le contenu est révélé par le filet de sécurité et seule
l'animation disparaît.

Pour modifier le rythme des animations, voir
[`INTERFACE_ANIMATIONS.md`](INTERFACE_ANIMATIONS.md) (constante `TEMPO`) ; pour
les couleurs, les polices et les composants, voir
[`DESIGN_SYSTEM.md`](DESIGN_SYSTEM.md).

### Apache (production locale)

Racine web **obligatoirement** `public/` :

```apache
<VirtualHost *:443>
    ServerName coffre.local
    DocumentRoot "C:/Users/hp/Desktop/Stage/public"

    SSLEngine on
    SSLCertificateFile  "conf/coffre.crt"
    SSLCertificateKeyFile "conf/coffre.key"

    <Directory "C:/Users/hp/Desktop/Stage/public">
        Options -Indexes
        AllowOverride All
        Require all granted
    </Directory>

    # Défense en profondeur : ces dossiers ne doivent jamais être servis
    <DirectoryMatch "C:/Users/hp/Desktop/Stage/(storage|crypto|config|src|scripts|tests|docs)">
        Require all denied
    </DirectoryMatch>
</VirtualHost>
```

Les fichiers `.htaccess` fournis bloquent déjà `storage/` et `crypto/`.

### Nginx + PHP-FPM

```nginx
server {
    listen 443 ssl;
    server_name coffre.local;
    root /var/www/coffre/public;
    index index.php;

    location / { try_files $uri $uri/ =404; }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~ ^/(storage|crypto|config|src|scripts|tests|docs)/ {
        deny all;
        return 404;
    }
}
```

> Le serveur PHP intégré ignore `.htaccess`. Il ne faut donc l'utiliser qu'en
> localhost, avec `-t public`, et jamais exposer la racine du projet.

### Instance PostgreSQL portable (Windows, sans droits administrateur)

Si le service PostgreSQL n'est pas installé sur le poste, une instance de test
peut être lancée depuis des binaires portables (PostgreSQL 17) :

```powershell
# Démarrage (données dans C:\Users\<vous>\pgdata, port 5432)
& "C:\Users\<vous>\pgportable\bin\pg_ctl.exe" -D "C:\Users\<vous>\pgdata" `
  -l "C:\Users\<vous>\pgdata\server.log" -o "-p 5432" start

# Arrêt
& "C:\Users\<vous>\pgportable\bin\pg_ctl.exe" -D "C:\Users\<vous>\pgdata" stop
```

L'instance doit être démarrée avant `init_db.php`, `seed_demo.php` et le serveur
PHP. Les identifiants correspondants se configurent dans `.env`
(`DB_HOST`, `DB_PORT`, `DB_ADMIN_USER`, `DB_ADMIN_PASSWORD`).

## 8. Permissions des fichiers (Linux)

```bash
chmod 700 storage storage/encrypted storage/tmp storage/uploads crypto/keystore
chmod 600 crypto/keystore/master.key
chown -R www-data:www-data storage crypto/keystore
```

Sous Windows, restreindre l'ACL du keystore au compte du service :

```powershell
icacls crypto\keystore /inheritance:r
icacls crypto\keystore /grant:r "$env:USERNAME:(OI)(CI)F"
```

## 9. Dépannage

| Symptôme | Cause probable | Solution |
|---|---|---|
| `Base de données indisponible` (503) | PostgreSQL arrêté, `.env` incorrect | démarrer le service, vérifier `DB_*` |
| `could not find driver` | `pdo_pgsql` non activé | activer l'extension dans `php.ini` |
| `MASTER_KEY_NOT_FOUND` | keystore absent | `python crypto/generate_key.py` |
| `MASTER_KEY_PASSPHRASE_REQUIRED` | keystore `.enc` sans phrase de passe | renseigner `COFFRE_KEY_PASSPHRASE` |
| `INTEGRITY_CHECK_FAILED` | fichier altéré ou mauvaise clé | restaurer le `.enc` depuis une sauvegarde, vérifier la clé |
| `CRYPTO_PROCESS_ERROR` | `PYTHON_BIN` incorrect / `cryptography` absent | tester `python -c "import cryptography"` |
| Upload refusé « type MIME » | fichier réellement différent de son extension | convertir en PDF ou corriger le fichier |
| `Le schéma n'est pas initialisé` | `init_db.php` non exécuté | lancer l'initialisation |
| Journal vide | aucun événement depuis l'installation | se connecter, déposer/consulter un document |
| Animations absentes, contenu normal | JavaScript désactivé, `prefers-reduced-motion` actif, ou `assets/vendor/` non déployé | comportement attendu (amélioration progressive) ; copier `public/assets/vendor/` si les animations sont souhaitées |
| Pages non stylées | `assets/style.css` inaccessible | vérifier que la racine web pointe bien sur `public/` |
| Polices système au lieu d'Inter | `public/assets/fonts/` non déployé | copier le dossier ; la pile de repli reste lisible |
| Thème clair inattendu | choix mémorisé dans le navigateur (`localStorage["coffre.theme"]`) | bouton de thème en haut à droite, ou vider la clé |
| Journal « en direct » absent du tableau de bord | rôle sans `audit.read` (médecin, agent) | comportement attendu ; se connecter en admin ou auditeur |

## 10. Sauvegarde / restauration

À sauvegarder ensemble et de façon chiffrée :

1. la base PostgreSQL (`pg_dump`) ;
2. `storage/encrypted/` ;
3. le keystore (`crypto/keystore/`) **séparément** de la sauvegarde des
   documents (principe de séparation).

Sans le keystore, la sauvegarde des `.enc` est inutilisable ; avec le keystore
et la base, la restauration est complète. Tester régulièrement une restauration
sur un environnement isolé.

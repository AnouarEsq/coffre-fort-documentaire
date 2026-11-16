# Fiche de démarrage — Coffre-fort documentaire

> À suivre dans l'ordre. Chaque commande est suivie d'une explication courte.
> Tous les chemins correspondent au poste actuel.

---

## 0. Résumé ultra-court

```powershell
cd C:\Users\hp\Desktop\Stage
.\demarrer.ps1
```

**Explication :** on se place dans le projet, puis le script démarre PostgreSQL,
lance le serveur PHP et ouvre le navigateur sur <http://127.0.0.1:8000>.

Si PowerShell bloque le script :

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\demarrer.ps1
```

**Explication :** on autorise l'exécution du script pour cette fois seulement.

### Si le message est « running scripts is disabled on this system »

C'est la politique de sécurité de PowerShell (par défaut sur Windows), pas un
problème du projet. Trois solutions, de la plus rapide à la plus durable :

```powershell
# 1. Ponctuel : autoriser les scripts pour cette fenêtre seulement
Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
.\demarrer.ps1
```

```powershell
# 2. Ponctuel sans changer la politique : lancer le fichier via powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\demarrer.ps1
```

```powershell
# 3. Durable : autoriser les scripts locaux pour l'utilisateur courant (sans admin)
Set-ExecutionPolicy -Scope CurrentUser -ExecutionPolicy RemoteSigned
```

**Explication :** `RemoteSigned` autorise les scripts écrits sur ce poste (comme
`demarrer.ps1`) mais exige une signature pour ceux téléchargés depuis Internet.
La portée `CurrentUser` évite d'avoir besoin des droits administrateur.
À refaire aussi pour `arreter.ps1` si Windows a marqué le fichier comme
« téléchargé » : `Unblock-File .\demarrer.ps1, .\arreter.ps1`.

---

## 1. Ouvrir le dossier du projet

```powershell
cd C:\Users\hp\Desktop\Stage
```

**Explication :** toutes les commandes suivantes doivent être lancées depuis la
racine du projet.

---

## 2. Vérifier les outils (une seule fois)

```powershell
Test-Path "C:\Users\hp\pgportable\bin\pg_ctl.exe"
```

**Explication :** doit renvoyer `True` → PostgreSQL portable est présent.

```powershell
Test-Path "C:\Users\hp\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"
```

**Explication :** doit renvoyer `True` → PHP 8.3 est installé.

```powershell
Test-Path "C:\Users\hp\AppData\Local\Programs\Python\Python312\python.exe"
```

**Explication :** doit renvoyer `True` → Python 3.12 est installé.

---

## 3. Démarrer PostgreSQL

```powershell
& "C:\Users\hp\pgportable\bin\pg_ctl.exe" -D "C:\Users\hp\pgdata" -l "C:\Users\hp\pgdata\server.log" -o "-p 5432" start
```

**Explication :** démarre la base de données sur le port 5432. Elle stocke les
utilisateurs, les métadonnées des documents et le journal d'audit.

Vérification :

```powershell
& "C:\Users\hp\pgportable\bin\pg_ctl.exe" -D "C:\Users\hp\pgdata" status
```

**Explication :** doit afficher `server is running`. Si le serveur tourne déjà,
cette commande le confirme sans rien casser.

> Le script `.\demarrer.ps1` fait cette étape automatiquement.

---

## 4. Démarrer le serveur web

```powershell
php -S 127.0.0.1:8000 -t public
```

**Explication :** lance le site PHP sur le port 8000. **Cette fenêtre doit
rester ouverte** pendant toute la démonstration : c'est elle qui sert les pages.

> `.\demarrer.ps1` ouvre cette fenêtre automatiquement.

Vérification (dans un autre terminal) :

```powershell
curl.exe -s -o NUL -w "%{http_code}" http://127.0.0.1:8000/login.php
```

**Explication :** doit afficher `200`. Cela prouve que le site répond.

---

## 5. Ouvrir le site et se connecter

Adresse :

```text
http://127.0.0.1:8000
```

**Explication :** page de connexion du coffre-fort.

| E-mail | Mot de passe | Rôle |
|---|---|---|
| `admin@test.com` | `Admin#2026!` | admin |
| `medecin@test.com` | `Medecin#2026!` | medecin |
| `agent@test.com` | `Agent#2026!` | agent |
| `auditeur@test.com` | `Auditeur#2026!` | auditeur |

**Explication :** ces comptes sont créés par `scripts/seed_demo.php`. Les mots de
passe sont hachés en bcrypt dans la base, jamais stockés en clair.

---

## 6. Scénario de démonstration (5 minutes)

1. **Connexion médecin** — `medecin@test.com`.
   **Explication :** le tableau de bord affiche les 3 PDF chiffrés de démo.

2. **Déposer un document** — bouton « Déposer un document », choisir un PDF
   (< 10 Mo), valider.
   **Explication :** le fichier est chiffré en AES-256-GCM puis le clair est
   supprimé immédiatement.

3. **Consulter le document** — cliquer sur « Déverrouiller » sur la ligne du
   document, puis « Consulter ».
   **Explication :** le cadenas s'ouvre et révèle les actions ; le `.enc` est
   vérifié (SHA-256 + tag GCM), déchiffré à la volée, puis le temporaire est
   supprimé. Ce déverrouillage est purement visuel : le serveur revérifie la
   permission à chaque requête.

4. **Se déconnecter**, puis **connexion admin** — `admin@test.com`.
   **Explication :** l'admin voit tous les documents et peut les retirer.

5. **Ouvrir « Journal d'audit »**.
   **Explication :** on y voit `LOGIN`, `UPLOAD`, `VIEW`, `DOWNLOAD` avec
   utilisateur, IP, horodatage et détails JSON. Le journal est en lecture seule.

6. **Tester un refus RBAC** — se déconnecter, se connecter en `agent@test.com`,
   puis ouvrir directement :

   ```text
   http://127.0.0.1:8000/view.php?id=1
   ```

   **Explication :** réponse **403 Accès refusé** : l'agent n'a pas
   `document.view`. La tentative apparaît dans le journal (`ACCESS_DENIED`).

7. **Vérifier l'auditeur** — `auditeur@test.com`, ouvrir « Journal d'audit ».
   **Explication :** il peut lire le journal, mais aucun document.

> **Note interface.** Le tableau de bord est en thème sombre « Terminal Vault »
> (fond `#0d1117`, accents cyan/vert/rouge). Trois détails à montrer au passage :
> les chips `AES-256-GCM` / `SHA-256` sous chaque document, le **journal en
> direct** en bas de page (visible pour les rôles qui ont `audit.read`) et le
> **bouton de thème** en haut à droite (basculer en clair puis revenir).
> Les animations d'entrée durent environ 1 seconde : attendre ~1,5 s avant une
> capture d'écran. Si elles ne se jouent pas (JavaScript désactivé, mouvement
> réduit dans le système), le contenu reste entièrement lisible : ce n'est pas
> un bug.

---

## 7. Optionnel : démontrer la détection d'altération (409)

```powershell
$f = Get-ChildItem .\storage\encrypted\*.enc | Select-Object -First 1
Copy-Item $f.FullName "$env:TEMP\sauvegarde.enc"
```

**Explication :** on sauvegarde un fichier chiffré avant de le modifier.

```powershell
$b = [IO.File]::ReadAllBytes($f.FullName)
$b[$b.Length-20] = $b[$b.Length-20] -bxor 0xFF
[IO.File]::WriteAllBytes($f.FullName, $b)
```

**Explication :** on inverse un octet du ciphertext pour simuler une attaque.

Recharger la consultation du document correspondant :

```text
http://127.0.0.1:8000/view.php?id=1
```

**Explication :** réponse **409 Intégrité compromise**. Aucun fichier clair n'est
produit et l'échec est journalisé (`INTEGRITY_CHECK_FAILED`).

Restaurer le fichier :

```powershell
Copy-Item "$env:TEMP\sauvegarde.enc" $f.FullName -Force
```

**Explication :** le document redevient consultable normalement.

---

## 8. Lancer les tests (preuve pour le professeur)

```powershell
python tests/test_crypto.py -v
```

**Explication :** 16 tests cryptographiques sans base de données.
Résultat attendu : `Ran 16 tests ... OK`.

```powershell
python tests/http_security_tests.py --base-url http://127.0.0.1:8000 --php "C:\Users\hp\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"
```

**Explication :** 27 scénarios HTTP/RBAC/audit. Résultat attendu :
`27 réussis, 0 échoués`.

```powershell
php scripts/test_audit_append_only.php
```

**Explication :** vérifie que `UPDATE`, `DELETE` et `TRUNCATE` sont bloqués sur
le journal d'audit. Résultat attendu : les trois champs `*_bloque` à `true`.

---

## 9. Arrêter le projet

```powershell
.\arreter.ps1
```

**Explication :** ferme le serveur PHP et arrête PostgreSQL proprement. Les
données restent dans `C:\Users\hp\pgdata`.

Équivalent manuel :

```powershell
& "C:\Users\hp\pgportable\bin\pg_ctl.exe" -D "C:\Users\hp\pgdata" stop
```

**Explication :** arrête uniquement la base de données.

---

## 10. Dépannage rapide

| Problème | Cause | Solution |
|---|---|---|
| `Port 5432 déjà utilisé` | PostgreSQL tourne déjà | continuer, c'est bon |
| `Port 8000 déjà utilisé` | serveur PHP déjà lancé | ouvrir directement l'URL |
| `Base de données indisponible` (503) | PostgreSQL arrêté | relancer `.\demarrer.ps1` |
| Page blanche / erreur 500 | PHP ou extensions | `php -v` puis `php -m` |
| `MASTER_KEY_NOT_FOUND` | clé absente | `python crypto/generate_key.py` |
| Connexion refusée | mauvais compte | utiliser le tableau ci-dessus |
| PowerShell bloque `.ps1` | policy | `powershell -ExecutionPolicy Bypass -File .\demarrer.ps1` |
| Polices « système » au lieu d'Inter | `public/assets/fonts/` absent ou bloqué | vérifier que `/assets/fonts/inter-latin-wght.woff2` répond en 200 ; la pile de repli reste lisible |
| Thème clair alors que je veux le sombre | choix mémorisé dans le navigateur | bouton en haut à droite, ou vider `localStorage["coffre.theme"]` |
| Animations absentes | mouvement réduit ou JS désactivé | comportement normal : le contenu est complet et statique |
| Cadre « journal en direct » absent | rôle sans `audit.read` (agent, médecin) | se connecter en admin ou auditeur |

Ne jamais supprimer :

- `crypto\keystore\master.key` → sans elle, les documents sont illisibles ;
- `.env` → configuration de la base et de Python ;
- `C:\Users\hp\pgdata` → toutes les données PostgreSQL.

---

## 11. Prompt prêt à copier pour une IA

```text
Tu es sur Windows PowerShell. Projet : C:\Users\hp\Desktop\Stage
(coffre-fort documentaire PHP + PostgreSQL + Python, déjà installé et initialisé).

Objectif : démarrer le projet et me donner l'URL, sans réinitialiser les données.

Exécute dans l'ordre :
1. cd C:\Users\hp\Desktop\Stage
2. .\demarrer.ps1
   (si bloqué : powershell -NoProfile -ExecutionPolicy Bypass -File .\demarrer.ps1)
3. Vérifie : curl.exe -s -o NUL -w "%{http_code}" http://127.0.0.1:8000/login.php
   doit renvoyer 200.
4. Vérifie PostgreSQL :
   & "C:\Users\hp\pgportable\bin\pg_ctl.exe" -D "C:\Users\hp\pgdata" status
   doit afficher "server is running".

Ne lance PAS init_db.php ni seed_demo.php (la base contient déjà les comptes et
les 3 PDF chiffrés). Ne supprime ni .env, ni crypto\keystore, ni pgdata.

Réponds-moi avec : état PostgreSQL, état du serveur web, URL à ouvrir, et les
comptes de démonstration.
```

**Explication :** ce texte donne à l'IA le contexte, les chemins, les commandes
et les interdictions, pour un démarrage fiable à chaque fois.

---

## 12. Aide-mémoire

| Action | Commande |
|---|---|
| Tout démarrer | `.\demarrer.ps1` |
| Tout arrêter | `.\arreter.ps1` |
| Base seulement | `& "C:\Users\hp\pgportable\bin\pg_ctl.exe" -D "C:\Users\hp\pgdata" start` |
| Serveur seulement | `php -S 127.0.0.1:8000 -t public` |
| Vérifier le site | `curl.exe -s -o NUL -w "%{http_code}" http://127.0.0.1:8000/login.php` |
| Tests crypto | `python tests/test_crypto.py -v` |
| Tests HTTP | `python tests/http_security_tests.py --php "<chemin php.exe>"` |
| Régénérer la démo | `php scripts/seed_demo.php` |
| Matrice des accès | `php scripts/export_matrix.php` |

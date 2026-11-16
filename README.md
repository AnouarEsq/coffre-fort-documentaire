# Coffre-fort documentaire sécurisé

Prototype de coffre-fort numérique pour documents administratifs et médicaux
sensibles, réalisé dans un cadre de stage en cybersécurité.

Quatre piliers sont implémentés et vérifiables :

| Pilier | Mécanisme |
|---|---|
| **Confidentialité** | Chiffrement AES-256-GCM (librairie Python `cryptography`) |
| **Intégrité** | Tag d'authentification GCM + empreinte SHA-256 du fichier chiffré |
| **Contrôle d'accès** | RBAC en base PostgreSQL (rôles × permissions), appliqué à chaque action |
| **Traçabilité** | Journal `audit_log` append-only (INSERT uniquement, triggers SQL) |

---

## 1. Fonctionnalités

- Authentification PHP avec `password_verify` (bcrypt coût 12), sessions durcies
  (`HttpOnly`, `SameSite`, `use_strict_mode`, régénération d'ID après login).
- Dépôt de PDF, images et documents bureautiques (10 Mo max), contrôle de
  l'extension **et** du type MIME réel, chiffrement via un subprocess Python.
- Suppression immédiate du fichier en clair après chiffrement.
- Métadonnées seules en base : SHA-256, nonce, tag GCM, taille, MIME, déposant.
- Consultation et téléchargement à la volée, après vérification des permissions,
  du SHA-256 et du tag GCM. Le fichier clair temporaire est supprimé après envoi.
- Journal d'audit filtrable (date, utilisateur, action, document, IP), en lecture
  seule, protégé par des triggers PostgreSQL contre `UPDATE`/`DELETE`/`TRUNCATE`.
- Refus RBAC et accès sans session systématiquement journalisés (`ACCESS_DENIED`).
- Interface web responsive avec animations GSAP 3.13 et défilement lissé Lenis,
  bibliothèques **auto-hébergées** (aucun CDN), CSP `script-src 'self'`
  inchangée ; l'application reste entièrement utilisable sans JavaScript.

## 2. Architecture

```
[Navigateur] ── PHP/JS ──▶ [Serveur PHP]
     │                       │      │
     │                  PDO  │      │ proc_open (tableau d'arguments, sans shell)
     │                       ▼      ▼
     │                  [PostgreSQL] [crypto/chiffrer.py | dechiffrer.py]
     │                  métadonnées        │
     │                                     ▼
     └── aucun accès direct ────── [storage/encrypted/*.enc]
                                        (AES-256-GCM)
```

- Racine web : `public/` uniquement.
- `storage/`, `crypto/`, `config/`, `src/`, `scripts/` sont hors racine web et
  protégés par `.htaccess` en défense en profondeur.
- Le contenu des documents n'est **jamais** stocké en base ni servi depuis le
  disque : il est déchiffré en mémoire/fichier temporaire 0600, puis détruit.

## 3. Arborescence

```
.
├── public/                 # racine web (pages + assets)
│   ├── login.php, logout.php, dashboard.php
│   ├── upload.php, view.php, download.php, delete.php
│   ├── audit.php, users.php
│   └── assets/
│       ├── style.css       # thème « Terminal Vault » (tokens + composants)
│       ├── theme.js        # bascule clair/sombre (appliquée avant le rendu)
│       ├── app.js          # validations, cadenas « déverrouiller »
│       ├── anim-flag.js    # filet de sécurité (contenu visible sans JS)
│       ├── animations.js   # animations GSAP + Lenis (tempo réglable)
│       ├── fonts/          # Inter + JetBrains Mono (woff2, auto-hébergées)
│       └── vendor/         # GSAP, ScrollTrigger, Lenis (versions figées)
├── src/                    # code applicatif partagé
│   ├── bootstrap.php, config.php (via config/)
│   ├── security.php        # sessions, CSRF, échappement, erreurs
│   ├── auth.php, rbac.php, audit.php
│   ├── crypto_bridge.php   # PHP -> Python (proc_open)
│   ├── documents.php       # intégrité, déchiffrement, streaming
│   └── layout.php
├── config/config.php       # configuration via .env
├── crypto/
│   ├── crypto.py           # cœur AES-256-GCM
│   ├── chiffrer.py, dechiffrer.py, generate_key.py
│   └── keystore/           # clé maître (non versionnée, 0600)
├── storage/
│   ├── encrypted/          # fichiers .enc
│   ├── uploads/            # temporaires en clair (très courte durée)
│   └── tmp/                # déchiffrement éphémère
├── scripts/
│   ├── init_db.php, seed_demo.php, export_matrix.php
│   ├── document_info.php
│   └── make_demo_pdfs.py
├── tests/test_crypto.py, tests/http_security_tests.py
├── docs/                   # architecture crypto, matrice, design, rapport de tests
├── schema_coffre_fort.sql  # schéma fourni
├── schema_hardening.sql    # triggers append-only
└── schema_extensions.sql   # retrait logique des documents
```

## 4. Démarrage rapide (Windows / PowerShell)

Prérequis :

- PHP 8.1+ avec les extensions `pdo_pgsql`, `pgsql`, `fileinfo`, `mbstring`,
  `openssl`, `session` ;
- Python 3.10+ et `pip install -r requirements.txt` ;
- PostgreSQL 14+ ;
- OpenSSL (optionnel, pour protéger le keystore).

### Démarrage en une commande

```powershell
.\demarrer.ps1
```

Le script démarre PostgreSQL (instance portable par défaut), lance le serveur
PHP dans une nouvelle fenêtre et ouvre <http://127.0.0.1:8000>. Arrêt :

```powershell
.\arreter.ps1
```

Si PowerShell refuse l'exécution des scripts :

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\demarrer.ps1
```

Les chemins utilisés par défaut sont `C:\Users\hp\pgportable`,
`C:\Users\hp\pgdata` et le PHP installé via winget. Ils sont surchargeables :

```powershell
$env:PG_BIN  = "D:\postgres\bin"      # dossier contenant pg_ctl.exe
$env:PG_DATA = "D:\postgres\data"     # dossier de données
$env:PHP_BIN = "C:\php\php.exe"       # interpréteur PHP
```

### Installation manuelle (première fois)

```powershell
# 1. Configuration
Copy-Item .env.example .env
# Éditer .env : DB_PASSWORD, DB_ADMIN_PASSWORD, PYTHON_BIN si nécessaire

# 2. Base de données (crée le rôle, la base, le schéma, les triggers)
php scripts/init_db.php

# 3. Clé maître (fichier 0600 ou keystore OpenSSL)
python crypto/generate_key.py
# ou, avec OpenSSL :
# $env:COFFRE_KEY_PASSPHRASE = "une-phrase-de-passe-de-12-caracteres-minimum"
# python crypto/generate_key.py --openssl

# 4. Données de démonstration (4 comptes + 3 PDF chiffrés)
php scripts/seed_demo.php

# 5. Serveur de développement
php -S 127.0.0.1:8000 -t public
```

Ouvrir <http://127.0.0.1:8000>.

### Comptes de démonstration

| E-mail | Rôle | Mot de passe |
|---|---|---|
| `admin@test.com` | admin | `Admin#2026!` |
| `medecin@test.com` | medecin | `Medecin#2026!` |
| `agent@test.com` | agent | `Agent#2026!` |
| `auditeur@test.com` | auditeur | `Auditeur#2026!` |

Ces mots de passe sont publics et réservés au prototype local. Ils doivent être
changés avant toute exposition réseau.

## 5. Interface et animations

### Direction artistique « Terminal Vault »

L'interface reprend les codes d'un poste de sécurité : fond sombre, surfaces
discrètes, bordures d'un pixel, données en monospace et accents sémantiques.

| Élément | Valeur |
|---|---|
| Fond / surfaces | `#0d1117` / `#131822` |
| Bordures | `rgba(255,255,255,0.08)` (1 px, aucune ombre lourde) |
| Texte principal / secondaire | `#e6edf3` / `#8b949e` |
| Succès / refus / info | `#22c55e` / `#f87171` / `#22d3ee` |
| Police de texte | Inter (auto-hébergée) |
| Métadonnées, hashes, IDs, journal | JetBrains Mono, chiffres alignés à droite |

- **Thème clair/sombre** : bascule dans la barre supérieure (et en haut à
  droite de la page de connexion). Le choix est mémorisé (`localStorage`) et
  appliqué *avant* le premier rendu par `assets/theme.js`, donc sans
  clignotement ; sans JavaScript, le thème sombre reste appliqué.
- **Composants** : chips `AES-256-GCM` / `SHA-256`, indicateur cadenas
  ouvert/fermé par document avec bouton « Déverrouiller » (les actions sont
  repliées puis révélées en 200 ms), journal d'audit « en direct » façon
  terminal (visible uniquement avec `audit.read`), état vide illustré.
- **Tokens CSS centralisés** dans `public/assets/style.css` (`--bg`,
  `--surface`, `--line`, `--success`…) : changer un thème ne touche aucun
  composant, et rien n'est codé en dur dans les pages.
- **Accessibilité** : contrastes vérifiés (texte principal ≈ 13:1 sur le fond,
  secondaire ≈ 6:1), focus visible, `aria-expanded`/`aria-pressed` sur les
  bascules, animations coupées sous `prefers-reduced-motion`.
- **CSP inchangée** : polices servies depuis `/assets/fonts`, aucune
  ressource distante, aucun style inline.

Détail (tokens, contrastes, composants) :
[`docs/DESIGN_SYSTEM.md`](docs/DESIGN_SYSTEM.md).

### Bibliothèques d'animation

La couche de présentation utilise deux bibliothèques, **copiées dans
`public/assets/vendor/`** (aucun CDN, aucun `npm`, aucun build) :

| Bibliothèque | Version | Rôle |
|---|---|---|
| GSAP | 3.13.0 | animations d'entrée, cascades, compteurs, soulignement de navigation |
| ScrollTrigger | 3.13.0 | barre de progression liée au défilement |
| Lenis | 1.3.11 | défilement lissé (inertie) |

Ce choix ne dégrade aucune garantie de sécurité :

- la CSP reste **`script-src 'self'`** — un CDN aurait été refusé par le
  navigateur, et l'assouplir aurait été une régression ;
- les fichiers sont figés dans le dépôt : pas de mise à jour silencieuse, pas
  de dépendance réseau pendant la démonstration (fonctionne hors ligne) ;
- aucun `eval()` ni `new Function()` dans ces fichiers : compatibles avec une
  CSP sans `unsafe-eval` ;
- les documents ne transitent **jamais** par JavaScript : consultation et
  téléchargement restent des flux PHP.

L'interface reste une **amélioration progressive** :

- sans JavaScript, aucune classe `has-anim` n'est posée : tout est visible ;
- si `animations.js` échoue, un filet de sécurité révèle le contenu en 3 s ;
- `prefers-reduced-motion: reduce` désactive toutes les animations ;
- seules `opacity` et `transform` sont animées (aucun recalcul de mise en page).

Réglage du tempo (une seule constante), dans `public/assets/animations.js` :

```js
var TEMPO = 1;      // 1 = sobre (défaut), 0.7 = vif, 1.4 = démonstratif
```

Détail complet (durées, garanties, désactivation) :
[`docs/INTERFACE_ANIMATIONS.md`](docs/INTERFACE_ANIMATIONS.md).

## 6. Matrice des accès

| Permission | admin | medecin | agent | auditeur |
|---|:---:|:---:|:---:|:---:|
| `document.upload` | ✅ | ✅ | ✅ | — |
| `document.view` | ✅ | ✅ | — | — |
| `document.download` | ✅ | ✅ | — | — |
| `document.delete` | ✅ | — | — | — |
| `audit.read` | ✅ | — | — | ✅ |
| `user.manage` | ✅ | — | — | — |

La matrice est extraite de la base avec `php scripts/export_matrix.php` et
documentée dans [`docs/MATRICE_ACCES.md`](docs/MATRICE_ACCES.md).

## 7. Où trouver quoi (modules du brief)

| Module | Implémentation principale |
|---|---|
| A — Crypto | `crypto/crypto.py`, `chiffrer.py`, `dechiffrer.py`, `generate_key.py` |
| B — Authentification | `public/login.php`, `src/auth.php`, `src/security.php` |
| C — RBAC | `src/rbac.php`, `schema_coffre_fort.sql` (matrice en base) |
| D — Dépôt | `public/upload.php`, `src/crypto_bridge.php` |
| E — Consultation | `public/view.php`, `public/download.php`, `src/documents.php` |
| F — Journal | `public/audit.php`, `src/audit.php`, `schema_hardening.sql` |
| G — Tests | `tests/test_crypto.py`, `tests/http_security_tests.py` |

La couche de présentation (animations GSAP + Lenis) est décrite en
[section 5](#5-interface-et-animations) ; elle ne fait pas partie du brief.

## 8. Tests rapides

```powershell
# Tests cryptographiques (aucune base nécessaire) : 16 tests
python tests/test_crypto.py -v

# Tests HTTP + RBAC + intégrité (serveur et base requis) : 27 scénarios
python tests/http_security_tests.py `
  --base-url http://127.0.0.1:8000 `
  --php "C:\chemin\vers\php.exe"
```

Dernière campagne : **16/16 tests crypto et 27/27 scénarios HTTP réussis**, plus
10 vérifications manuelles — soit **53 contrôles** (dont 6 sur l'interface,
le thème et l'accessibilité).
Le rapport détaillé (attendu / obtenu) se trouve dans
[`docs/RAPPORT_TESTS.md`](docs/RAPPORT_TESTS.md).

## 9. Choix de sécurité non négociables

1. Aucun mot de passe en clair : bcrypt coût 12 (`password_hash`/`password_verify`).
2. Aucune clé en dur : keystore externe (fichier 0600 ou OpenSSL + variable
   d'environnement), chargé par le module Python.
3. 100 % de requêtes préparées PDO ; aucune concaténation de valeurs SQL.
4. Échappement systématique via `e()` (`htmlspecialchars`) en sortie.
5. `audit_log` en INSERT seul, avec triggers PostgreSQL bloquant toute mutation.
6. Cookies de session `HttpOnly`, `SameSite=Lax`, `Secure` sous HTTPS,
   `use_strict_mode`, ID régénéré après login.
7. Nonce GCM aléatoire de 12 octets généré pour chaque chiffrement, stocké en
   base et dans le fichier `.enc`.
8. Aucun appel shell : `proc_open` reçoit un tableau d'arguments.
9. Contrôle du type MIME réel avec `finfo`, pas seulement l'extension.
10. En-têtes CSP, `X-Content-Type-Options`, `X-Frame-Options`, `no-store`.
11. Aucune ressource front-end distante : GSAP et Lenis sont copiés dans
    `public/assets/vendor/` (versions figées), la CSP `script-src 'self'`
    n'est pas assouplie et la démonstration fonctionne hors ligne.

## 10. Limites connues et durcissement production

- Le retrait d'un document est logique (`supprime_le`) pour préserver la clé
  étrangère du journal append-only ; le `.enc` est effacé. Une purge physique
  nécessiterait une politique d'archivage séparée.
- Un seul keystore maître : pas encore d'identifiant de clé dans l'en-tête
  `.enc`, donc la rotation nécessite de re-chiffrer tous les documents.
- `user.manage` n'a pas d'action d'audit dédiée dans l'enum fourni
  (`action_type`) ; la création de compte est tracée dans le log serveur.
- Prototype : pas de MFA, pas de verrouillage après N échecs, pas de rate
  limiting distribué, pas de clés par document, pas de chiffrement de la base.
- En production : HTTPS obligatoire, utilisateur PostgreSQL dédié
  (`INSERT`/`SELECT` sur `audit_log`, pas de `DELETE`), sauvegardes chiffrées,
  séparation des privilèges, coffre de clés matériel (HSM/KMS), tests de
  restauration, supervision des échecs d'intégrité.

## 11. Documentation

- [`docs/ARCHITECTURE_CRYPTO.md`](docs/ARCHITECTURE_CRYPTO.md) — cycle de vie
  de la clé et du document, format `.enc`, menaces et rotation.
- [`docs/MATRICE_ACCES.md`](docs/MATRICE_ACCES.md) — matrice extraite de la base.
- [`docs/RAPPORT_TESTS.md`](docs/RAPPORT_TESTS.md) — scénarios attendu/obtenu.
- [`docs/INSTALLATION.md`](docs/INSTALLATION.md) — installation détaillée,
  Apache/Nginx, dépannage.
- [`docs/DEMARRAGE_RAPIDE.md`](docs/DEMARRAGE_RAPIDE.md) — fiche pas-à-pas pour
  démarrer le projet (commandes + explications + prompt pour une IA).
- [`docs/INTERFACE_ANIMATIONS.md`](docs/INTERFACE_ANIMATIONS.md) — animations
  GSAP/Lenis : durées, garanties (sans JS, accessibilité), tempo, désactivation.
- [`docs/DESIGN_SYSTEM.md`](docs/DESIGN_SYSTEM.md) — direction artistique
  « Terminal Vault » : tokens, thème clair/sombre, composants, contrastes.

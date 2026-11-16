# Rapport de tests de sécurité

**Projet :** coffre-fort documentaire sécurisé (prototype de stage en cybersécurité)
**Date d'exécution :** 15/11/2026
**Verdict global :** ✅ **43 scénarios automatisés réussis, 0 échec**
(16 tests cryptographiques + 27 scénarios HTTP/RBAC/audit), complétés par
4 vérifications manuelles ciblées.

---

## 1. Périmètre

| Module | Ce qui est vérifié |
|---|---|
| A — Cryptographie | AES-256-GCM, format `.enc`, nonce unique, tag, altérations, clé absente, interface CLI |
| B — Authentification | refus sans session, CSRF, message générique, cookies durcis, journalisation des échecs |
| C — RBAC | matrice en base, 403 par rôle, journalisation des refus |
| D — Dépôt | upload PDF, chiffrement, suppression du clair, métadonnées, audit |
| E — Consultation | déchiffrement à la volée, intégrité, téléchargement, retrait logique |
| F — Audit | journal append-only, triggers PostgreSQL, filtres, traçabilité |
| G - Sécurité transverse | CSP, en-têtes, XSS, injections, gestion des erreurs |
| Interface (hors brief) | direction artistique « Terminal Vault », thèmes clair/sombre, polices auto-hébergées, bibliothèques d'animation sous CSP, amélioration progressive, `prefers-reduced-motion` |

---

## 2. Environnement de test

| Composant | Version / valeur |
|---|---|
| Système | Windows 11 (10.0.22000) |
| PHP | 8.3.36 (CLI + serveur intégré), extensions `pdo_pgsql`, `pgsql`, `fileinfo`, `mbstring`, `openssl` |
| PostgreSQL | 17.11 (instance locale, port 5432, base `coffre_fort`) |
| Python | 3.12.10 |
| `cryptography` | 50.0.2 |
| OpenSSL | Git for Windows (`openssl.exe`), pour le keystore protégé |
| Serveur web | `php -S 127.0.0.1:8000 -t public` |
| Navigateur de test | client HTTP Python (`urllib`), cookies et CSRF réels |
| Navigateur (interface) | Chromium, via outil d'automatisation : console, en-têtes CSP, styles calculés, polices chargées et interactions (thème, cadenas) inspectés |

Commandes de préparation :

```powershell
php scripts/init_db.php      # rôle + base + schéma + triggers
php scripts/seed_demo.php    # 4 comptes + 3 PDF chiffrés
php -S 127.0.0.1:8000 -t public
```

---

## 3. Synthèse des résultats

| Suite | Scénarios | Réussis | Échecs |
|---|---:|---:|---:|
| `tests/test_crypto.py` | 16 | 16 | 0 |
| `tests/http_security_tests.py` | 27 | 27 | 0 |
| Vérifications manuelles complémentaires | 10 | 10 | 0 |
| **Total** | **53** | **53** | **0** |

Points saillants :

- aucune altération (ciphertext, tag, nonce, magic, troncature, mauvaise clé)
  n'a produit de fichier clair ;
- 1 000 chiffrements successifs ont généré 1 000 nonces distincts ;
- les quatre rôles sont correctement cloisonnés, y compris par URL directe ;
- les triggers PostgreSQL rejettent `UPDATE`, `DELETE` **et** `TRUNCATE` sur
  `audit_log` ;
- aucun fichier en clair ne subsiste dans `storage/uploads` ni `storage/tmp`
  après les tests ;
- l'interface reste intégralement lisible sans JavaScript (filet de sécurité à
  2,5 s / 3 s) et respecte `prefers-reduced-motion`.

---

## 4. Tests cryptographiques — `tests/test_crypto.py`

Commande :

```powershell
python tests/test_crypto.py -v
```

Résultat : `Ran 16 tests in 8.618s — OK`

| # | Test | Attendu | Obtenu | Statut |
|---|---|---|---|---|
| C01 | `test_roundtrip` | clair identique après déchiffrement, SHA-256 cohérent | identique, `sha256_chiffre` = SHA-256 du `.enc` | ✅ |
| C02 | `test_empty_file_roundtrip` | fichier vide accepté et restitué vide | conforme | ✅ |
| C03 | `test_binary_format` | `CFV1` + version `0x01` + nonce 12 o + tag 16 o + SHA-256 64 hex ; clair absent du `.enc` | structure exacte, aucune fuite du clair | ✅ |
| C04 | `test_nonce_uniqueness` | 1 000 nonces distincts sur 1 000 chiffrements | 1 000 / 1 000 | ✅ |
| C05 | `test_tamper_ciphertext` | `INTEGRITY_CHECK_FAILED`, aucun fichier produit | exception typée, aucun clair | ✅ |
| C06 | `test_tamper_tag` | refus | refus | ✅ |
| C07 | `test_tamper_nonce` | refus | refus | ✅ |
| C08 | `test_tamper_magic` | refus | refus | ✅ |
| C09 | `test_truncated_file` | refus d'un fichier tronqué | refus | ✅ |
| C10 | `test_wrong_key` | refus avec une clé erronée | refus | ✅ |
| C11 | `test_missing_master_key` | `MASTER_KEY_NOT_FOUND` | code exact | ✅ |
| C12 | `test_expected_sha256_mismatch` | refus **avant** déchiffrement | refus | ✅ |
| C13 | `test_cli_success_json` | JSON `chemin/nonce/tag/sha256`, nonce 24 hex, tag 32 hex, sha256 64 hex | conforme, aller-retour CLI valide | ✅ |
| C14 | `test_cli_integrity_exit_code` | code de sortie `3`, `error=INTEGRITY_CHECK_FAILED`, aucun clair | code 3, aucun fichier | ✅ |
| C15 | `test_cli_missing_key_exit_code` | code de sortie `4`, `error=MASTER_KEY_NOT_FOUND` | conforme | ✅ |
| C16 | `test_generate_key_refuses_overwrite` | refus d'écrasement (`KEY_ALREADY_EXISTS`) | code 1, erreur exacte | ✅ |

---

## 5. Tests HTTP, RBAC, intégrité et audit — `tests/http_security_tests.py`

Commande :

```powershell
python tests/http_security_tests.py `
  --base-url http://127.0.0.1:8000 `
  --php "C:\...\php.exe"
```

Résultat : `27 réussis, 0 échoués, 27 scénarios`.

| # | Scénario | Attendu | Obtenu | Statut |
|---|---|---|---|---|
| T01 | Tableau de bord sans session | 302 vers `/login.php` | `HTTP 302, Location=/login.php` | ✅ |
| T02 | POST `/login.php` sans jeton CSRF | 403 + message CSRF | `HTTP 403`, message présent | ✅ |
| T03 | Mauvais mot de passe | refus + message générique | refus, « Email ou mot de passe incorrect » | ✅ |
| T04 | Connexion des 4 comptes de démo | 4 sessions | admin/medecin/agent/auditeur = True | ✅ |
| T05 | Cookie de session | `HttpOnly` + `SameSite` | `HttpOnly; SameSite=Lax` | ✅ |
| T06 | En-têtes de sécurité | CSP + `X-Frame-Options: DENY` + `nosniff` | conformes | ✅ |
| T07 | Métadonnées du document | `.enc` présent, nonce 24 hex, tag 32 hex, SHA-256 64 hex | conforme | ✅ |
| T08 | Agent → `view.php` direct | 403 « Accès refusé » | `HTTP 403` | ✅ |
| T08b | Audit du refus RBAC | `ACCESS_DENIED` + `agent@test.com` + motif `RBAC` | trouvé | ✅ |
| T09 | Auditeur : audit oui, documents non | audit=200, view=403, upload=403 | conforme | ✅ |
| T10 | Médecin → consultation PDF | 200 `application/pdf`, corps `%PDF` | conforme | ✅ |
| T10b | Audit `VIEW` | événement médecin | trouvé | ✅ |
| T11 | Médecin → téléchargement | 200 + `Content-Disposition: attachment`, `%PDF` | conforme | ✅ |
| T11b | Audit `DOWNLOAD` | événement médecin | trouvé | ✅ |
| T12 | Altération d'un octet du `.enc` | 409 « Intégrité compromise », aucun clair | `HTTP 409` | ✅ |
| T12b | Audit de l'échec d'intégrité | `ACCESS_DENIED` + `INTEGRITY_CHECK_FAILED` | trouvé | ✅ |
| T12c | Restauration du `.enc` | 200 + PDF | conforme | ✅ |
| T13 | Échec de connexion tracé | `LOGIN_FAILED` + e-mail tenté | trouvé | ✅ |
| T14 | Accès sans session / CSRF invalide tracés | `NO_SESSION` + `CSRF_TOKEN_INVALID` | les deux trouvés | ✅ |
| T15 | Dépôt PDF par médecin | 302 `/dashboard.php` | conforme | ✅ |
| T15b | Document visible au tableau de bord | nom + identifiant | `document_id=4` | ✅ |
| T15c | Consultation du document fraîchement chiffré | 200 + PDF | conforme | ✅ |
| T15d | Audit `UPLOAD` | nom du fichier | trouvé | ✅ |
| T15e | Retrait par l'admin | 302 | conforme | ✅ |
| T15f | Document retiré inaccessible | 410 Gone | `HTTP 410` | ✅ |
| T15g | Audit `DELETE` | admin identifié | trouvé | ✅ |
| T16 | Immutabilité `audit_log` | UPDATE/DELETE/TRUNCATE rejetés | 2 triggers, 3/3 bloqués, 81 lignes intactes | ✅ |

Détail T16 obtenu :

```json
{"triggers": ["trg_audit_log_no_mutation", "trg_audit_log_no_truncate"],
 "update_bloque": true, "delete_bloque": true, "truncate_bloque": true,
 "lignes_audit": 81}
```

---

## 6. Vérifications manuelles complémentaires

| # | Vérification | Résultat | Statut |
|---|---|---|---|
| M01 | Keystore protégé OpenSSL (`--openssl`, phrase de passe en variable d'environnement) | `protection=openssl-aes-256-cbc-pbkdf2`, chiffrement + déchiffrement OK, SHA-256 identiques | ✅ |
| M02 | Pont PHP → Python (`crypto_encrypt` / `crypto_decrypt`) | aller-retour OK ; altération → `INTEGRITY_CHECK_FAILED` | ✅ |
| M03 | Entrées hostiles en tableau (`?action[]=`, `?ip[]=`, `action[]` sur les filtres) | HTTP 200, aucune erreur « Array to string », filtres ignorés proprement | ✅ |
| M04 | Hygiène du stockage après tests | `storage/uploads` et `storage/tmp` ne contiennent que leur `.gitkeep` ; 4 fichiers `.enc` actifs (3 PDF de démo + 1 document utilisateur) | ✅ |
| M05 | Bibliothèques d'animation sous CSP `script-src 'self'` | `gsap`, `ScrollTrigger`, `Lenis` chargés, **0 erreur console** sur `/login.php`, `/dashboard.php`, `/upload.php`, `/audit.php`, `/users.php` | ✅ |
| M06 | CSP inchangée malgré les bibliothèques | en-tête identique avant/après : `script-src 'self'` ; aucun `eval()` ni `new Function()` dans les fichiers tiers | ✅ |
| M07 | Amélioration progressive (timeline figée / JS partiel) | contenu révélé par le chien de garde (2,5 s), compteurs restaurés à `4` et `6`, aucun texte faux affiché | ✅ |
| M08 | Accessibilité du mouvement | `prefers-reduced-motion: reduce` → `opacity: 1` forcé, défilement lissé et barre de progression désactivés | ✅ |
| M09 | Thème clair / sombre | `data-theme` appliqué avant le premier rendu (aucun flash), bascule persistée dans `localStorage`, `aria-pressed` et `theme-color` mis à jour, thème sombre par défaut sans JavaScript | ✅ |
| M10 | Cadenas « déverrouiller » et journal en direct | `aria-expanded` `false` → `true`, actions rendues tabulables, focus sur « Consulter » ; journal limité aux rôles `audit.read`, 8 derniers événements, aucune erreur console | ✅ |

Autres contrôles ponctuels effectués :

- `GET /view.php?id=99999` → 404 ; `?id=abc` → 400 ; `?id=0` → 400 ;
- `GET /users.php` avec le rôle médecin → 403 ;
- `POST /users.php` sans CSRF → 403 ; mot de passe faible → erreur affichée,
  aucun compte créé ;
- filtres d'audit par IP et par plage de dates → 200, sans erreur SQL ;
- tableau de bord de l'agent : aucun lien de consultation, dépôt autorisé.

---

## 7. État de la base après exécution

Relevé effectué le **15/11/2026**, après la campagne complète et le retrait des
documents de test (les tests HTTP ajoutent volontairement des lignes d'audit à
chaque exécution : les compteurs ci-dessous ne sont donc pas figés).

```text
documents
  #1 Certificat médical - Anouar Essadiq.pdf       1324 o  retiré=non
  #2 Attestation de domicile - Anouar Essadiq.pdf 1226 o  retiré=non
  #3 Ordonnance médicale - Dr Martin.pdf          1336 o  retiré=non
  #4 test_securite_<horodatage>.pdf               1324 o  retiré=oui
  #5 vSphere-EXAM-PreP.pdf                      251465 o  retiré=non
  #6 test_securite_<horodatage>.pdf               1324 o  retiré=oui
  #7 test_securite_<horodatage>.pdf               1324 o  retiré=oui
  #8 test_securite_<horodatage>.pdf               1324 o  retiré=oui

audit_log (86 entrées, append-only)
  LOGIN          26    ACCESS_DENIED  26    VIEW      12
  UPLOAD          8    LOGIN_FAILED    6    DOWNLOAD   4
  DELETE          4
```

Les documents de test (#4, #6, #7, #8) sont conservés en base (retrait logique)
pour que la clé étrangère `audit_log.document_id` reste valide, conformément à
la conception append-only. Leurs fichiers `.enc` ont bien été supprimés du
disque ; seuls les quatre documents actifs restent consultables.

---

## 8. Écarts détectés et corrigés pendant la campagne

| Écart | Impact | Correction |
|---|---|---|
| Trigger `TRUNCATE` référençant `OLD.id` au niveau instruction | erreur SQL différente du message « append-only » attendu | branche `IF TG_OP = 'TRUNCATE'` ajoutée dans `schema_hardening.sql` |
| Cast `(string)` d'entrées `$_GET`/`$_POST` potentiellement tableaux | avertissement PHP et bruit dans la réponse | helper `input_string()` dans `src/security.php`, appliqué à `login`, `users`, `audit`, `upload` |
| Libellé `FICHER` sur le tableau de bord | faute d'orthographe visible | corrigé en `FICHIER` |

---

## 9. Limites du test et points de vigilance

- **Pas de test de charge** : le serveur intégré PHP est mono-processus ; les
  performances ne sont pas représentatives d'Apache/Nginx + PHP-FPM.
- **Pas de test TLS** : les cookies sont `Secure` uniquement sous HTTPS ; le
  test local en HTTP ne peut pas le vérifier.
- **Pas de test MFA / anti-bruteforce** : fonctionnalités hors périmètre du
  prototype (voir README, section « Limites connues »).
- **Rotation de clé** non testée : elle nécessiterait de re-chiffrer tous les
  documents et d'ajouter un identifiant de clé dans l'en-tête `.enc`.
- Les triggers protègent la table, mais un superutilisateur PostgreSQL peut les
  désactiver (`ALTER TABLE ... DISABLE TRIGGER`) : en production, restreindre
  les privilèges et surveiller les changements de schéma.
- Le retrait est logique côté base ; une purge physique relèverait d'une
  politique d'archivage séparée.

---

## 10. Captures d'écran

Les captures ci-dessous sont à insérer dans le dossier `docs/captures/` lors de
la démonstration (les tests automatisés ne dépendent pas d'elles).

| Fichier attendu | Écran | Ce que la capture doit montrer |
|---|---|---|
| `01_connexion.png` | `/login.php` | formulaire de connexion, bascule de thème, mention du journal d'audit |
| `02_tableau_de_bord.png` | `/dashboard.php` (admin) | bandeau AES-256-GCM / SHA-256, cadenas « Déverrouiller », 4 documents, journal en direct, 6 permissions |
| `03_depot.png` | `/upload.php` | formulaire, limite 10 Mo, rappel du chiffrement |
| `04_audit.png` | `/audit.php` (admin) | événements UPLOAD/VIEW/DOWNLOAD/ACCESS_DENIED, badges d'action |
| `05_integrite_409.png` | `/view.php?id=N` après altération | page « Intégrité compromise » (HTTP 409) |
| `06_rbac_403.png` | `/users.php` (médecin) | page « Accès refusé » (HTTP 403) |
| `07_theme_clair.png` | `/dashboard.php` en thème clair | même écran après bascule (persistance du choix) |

Procédure de capture (démonstration) :

1. démarrer le serveur et se connecter avec `admin@test.com` / `Admin#2026!` ;
2. ouvrir chaque écran, redimensionner la fenêtre à 1440 px de large et
   **attendre ~1,5 s** que l'animation d'entrée soit terminée avant de capturer ;
3. capturer la fenêtre complète (Win + Maj + S) et enregistrer au nom indiqué ;
4. pour `05`, altérer un octet du `.enc` dans `storage/encrypted/`, recharger la
   consultation, capturer la page 409, puis restaurer le fichier depuis une
   sauvegarde ;
5. pour `07`, cliquer sur le bouton de thème en haut à droite, capturer, puis
   revenir au thème sombre.

> Les animations sont une amélioration progressive : si le navigateur de
> capture a `prefers-reduced-motion` actif ou JavaScript désactivé, les écrans
> sont identiques mais sans mouvement. Pour une capture « dynamique », filmer
> plutôt le parcours (connexion → dépôt → journal).

---

## 11. Reproductibilité complète

```powershell
# 1. Base et données
php scripts/init_db.php
php scripts/seed_demo.php
php scripts/export_matrix.php

# 2. Tests cryptographiques (16)
python tests/test_crypto.py -v

# 3. Serveur
php -S 127.0.0.1:8000 -t public

# 4. Tests HTTP/RBAC/audit (27) — dans un second terminal
python tests/http_security_tests.py `
  --base-url http://127.0.0.1:8000 `
  --php "C:\chemin\vers\php.exe"

# 5. Append-only seul (sortie JSON)
php scripts/test_audit_append_only.php
```

Les tests HTTP laissent volontairement des traces (`LOGIN`, `UPLOAD`,
`ACCESS_DENIED`, etc.) : c'est le comportement attendu d'un journal
append-only. Pour repartir d'une base vierge, recréer la base puis relancer
`init_db.php` et `seed_demo.php`.

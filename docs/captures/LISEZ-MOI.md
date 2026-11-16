# Captures d'écran de démonstration

Ce dossier reçoit les captures utilisées dans le rapport de tests
(`docs/RAPPORT_TESTS.md`, section 10). Il est vide dans la livraison : les
captures sont prises sur le poste de démonstration.

| Fichier | Écran | Points à montrer |
|---|---|---|
| `01_connexion.png` | `/login.php` | formulaire, bascule de thème, rappel « toutes les tentatives sont journalisées » |
| `02_tableau_de_bord.png` | `/dashboard.php` (admin) | bandeau AES-256-GCM / SHA-256, cadenas et bouton « Déverrouiller », 4 documents, journal en direct, 6 permissions |
| `03_depot.png` | `/upload.php` | limite 10 Mo, types acceptés, rappel du chiffrement |
| `04_audit.png` | `/audit.php` (admin) | actions UPLOAD/VIEW/DOWNLOAD/ACCESS_DENIED et détails JSON |
| `05_integrite_409.png` | `/view.php?id=N` après altération | page « Intégrité compromise » (HTTP 409) |
| `06_rbac_403.png` | `/users.php` (médecin) | page « Accès refusé » (HTTP 403) |
| `07_theme_clair.png` | `/dashboard.php` en thème clair | même écran après bascule (bouton en haut à droite) |

Procédure :

1. `php scripts/init_db.php`, `php scripts/seed_demo.php`, puis
   `php -S 127.0.0.1:8000 -t public` ;
2. se connecter avec `admin@test.com` / `Admin#2026!` (ou le rôle concerné) ;
3. fenêtre de 1440 px de large, **attendre ~1,5 s** que les animations d'entrée
   soient terminées, puis capture plein écran (Win + Maj + S) ;
4. pour `05` : sauvegarder un `.enc`, inverser un octet, recharger
   `/view.php?id=N`, capturer la page 409, puis restaurer le fichier ;
5. pour `07` : cliquer sur le bouton de thème (haut à droite), capturer, puis
   recliquer pour revenir au thème sombre.

> Ne jamais capturer le contenu d'un vrai document sensible ni le keystore.

# Matrice des accès (extraite de la base)

> Généré automatiquement le 15/11/2026 à 04:20 depuis PostgreSQL (coffre_fort).
> Source de vérité : tables `roles`, `permissions` et `role_permissions`.

| Permission | `admin` | `medecin` | `agent` | `auditeur` |
|---|:---:|:---:|:---:|:---:|
| `document.upload`<br>Deposer un document | ✅ | ✅ | ✅ | — |
| `document.view`<br>Consulter un document | ✅ | ✅ | — | — |
| `document.download`<br>Telecharger un document | ✅ | ✅ | — | — |
| `document.delete`<br>Supprimer un document | ✅ | — | — | — |
| `audit.read`<br>Consulter le journal | ✅ | — | — | ✅ |
| `user.manage`<br>Gerer les utilisateurs | ✅ | — | — | — |

## Synthèse

- **admin** : 6 permission(s) sur 6.
- **medecin** : 3 permission(s) sur 6.
- **agent** : 1 permission(s) sur 6.
- **auditeur** : 1 permission(s) sur 6.

## Règles d’application

- Chaque page sensible appelle `require_login()` puis `require_permission($code)`.
- La fonction `can($code)` interroge la base à chaque requête (pas de droits codés en dur côté page).
- Tout refus est journalisé dans `audit_log` avec l’action `ACCESS_DENIED`, l’utilisateur, l’IP et l’horodatage.
- Le rôle `auditeur` ne peut que lire le journal (`audit.read`) ; il ne voit aucun document.

## Commande de régénération

```bash
php scripts/export_matrix.php
```

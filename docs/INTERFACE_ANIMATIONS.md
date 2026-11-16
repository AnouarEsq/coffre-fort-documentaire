# Interface et animations

Ce document décrit la couche de présentation ajoutée pour la démonstration :
bibliothèques utilisées, garanties de robustesse, réglages et procédure de
désactivation. Il ne modifie **aucune** garantie de sécurité du prototype
(chiffrement, RBAC, journal d'audit restent inchangés).

---

## 1. Bibliothèques

| Bibliothèque | Version | Rôle | Fichier | Taille |
|---|---|---|---|---|
| GSAP | 3.13.0 | moteur d'animation (timelines, cascades) | `assets/vendor/gsap.min.js` | 71 Ko |
| ScrollTrigger | 3.13.0 | animations liées au défilement (plugin GSAP) | `assets/vendor/ScrollTrigger.min.js` | 43 Ko |
| Lenis | 1.3.11 | défilement lissé (inertie) | `assets/vendor/lenis.min.js` | 16 Ko |
| Lenis (styles) | 1.3.11 | classes `html.lenis` / `data-lenis-prevent` | `assets/vendor/lenis.css` | 0,4 Ko |

Licences : GSAP est distribué sous licence standard GreenSock (gratuite, y
compris pour un usage commercial) ; Lenis est sous licence MIT. Les deux
fichiers d'en-tête de licence sont conservés dans les fichiers minifiés.

### Pourquoi auto-hébergé et non un CDN

1. **CSP** : l'application applique `script-src 'self'`. Un script chargé depuis
   `cdn.jsdelivr.net` serait **refusé** par le navigateur. L'ajouter aurait
   imposé d'assouplir la politique — régression inacceptable sur ce projet.
2. **Hors ligne** : la démonstration fonctionne sans connexion Internet.
3. **Chaîne d'approvisionnement** : versions figées, fichiers présents dans le
   dépôt, aucun code tiers exécuté à distance, aucune mise à jour silencieuse.

Les fichiers ont été vérifiés : **aucun `eval()` ni `new Function()`**, donc
compatibles avec une CSP sans `unsafe-eval`.

Aucun gestionnaire de paquets, aucun build, aucune étape de compilation : les
fichiers sont servis tels quels.

---

## 2. Fichiers et responsabilités

| Fichier | Rôle |
|---|---|
| `public/assets/vendor/*` | bibliothèques tierces, versions figées |
| `public/assets/theme.js` | thème clair/sombre appliqué avant le rendu (indépendant des animations) |
| `public/assets/anim-flag.js` | ajout de `html.has-anim` **avant le premier rendu** + filet de sécurité à 3 s |
| `public/assets/animations.js` | toutes les animations (tempo, timelines, compteurs, navigation, barre de progression) |
| `src/layout.php` | `render_assets()` : ordre de chargement ; classe `page-<slug>` sur `<body>` |
| `public/assets/style.css` (fin de fichier) | états initiaux, soulignement de navigation, barre de progression, `prefers-reduced-motion` |

Ordre de chargement (dans `<head>`) :

```text
theme.js              (bloquant, ~2 Ko : pose data-theme, anti-flash)
anim-flag.js          (bloquant, 1 Ko : pose has-anim)
lenis.css / style.css
gsap.min.js           (defer)
ScrollTrigger.min.js  (defer)
lenis.min.js          (defer)
app.js                (defer : validations de formulaires existantes)
animations.js         (defer)
```

---

## 3. Spécification des animations

Tempo par défaut : `TEMPO = 1`. Toutes les durées sont multipliées par cette
constante.

| Élément | Durée | Cascade | Courbe |
|---|---|---|---|
| Titre de page et sous-titre | 500 ms | — | `power3.out` |
| Cartes de statistiques | 520 ms | 80 ms | `power3.out` |
| Compteur 0 → N | 900 ms | départ à +200 ms | `power2.out` |
| Panneau de filtres | 450 ms | — | `power2.out` |
| Tableau / état vide | 450 ms | 100 ms | `power2.out` |
| Lignes de tableau | 350 ms | 20 ms (12 lignes max) | `power2.out` |
| Panneaux secondaires | 480 ms | 120 ms | `power3.out` |
| Bandeau « état du coffre » (`vault-strip`) | 480 ms | 120 ms | `power3.out` |
| Terminal du journal (`terminal-panel`) | 480 ms | 120 ms | `power3.out` |
| Lignes du journal (`term-line`) | 280 ms | 30 ms | `power3.out` |
| Carte de connexion | 600 ms | 120 ms | `power3.out` |
| Bandeaux d'alerte | 350 ms | 60 ms | `power2.out` |
| Soulignement de navigation | 320 ms | — | `power3.out` |
| Barre de progression | liée au scroll | — | `none` (linéaire) |
| Défilement lissé (Lenis) | inertie 1,05 s | — | exponentielle |

Règles appliquées :

- durée totale de l'introduction **≤ 1,1 s** ;
- seules les propriétés `opacity` et `transform` sont animées (composition GPU,
  aucun recalcul de mise en page) ;
- aucun rebond (`bounce`, `elastic`, `back`) : registre sobre ;
- les éléments restent cliquables pendant l'animation (aucun `pointer-events`
  désactivé, aucune attente avant interaction) ;
- l'effet « scan » au survol des cartes et des lignes de document est en CSS
  pur (`@keyframes scan-sweep`, 1 s) : il n'ajoute aucun écouteur JavaScript.

---

## 4. Garanties de robustesse

| Situation | Comportement |
|---|---|
| JavaScript désactivé | `anim-flag.js` ne s'exécute pas → aucune classe `has-anim` → contenu entièrement visible et fonctionnel |
| `animations.js` absent ou en erreur | à 3 s, `anim-flag.js` retire `has-anim` → contenu visible |
| Timeline figée (onglet en arrière-plan, `requestAnimationFrame` suspendu) | chien de garde à 2,5 s dans `animations.js` → contenu visible, **compteurs remis à leur valeur réelle** |
| `prefers-reduced-motion: reduce` | aucune animation, aucun défilement lissé ; la feuille de style force `opacity: 1` |
| Écran tactile | Lenis ne prend pas la main sur le toucher (`syncTouch: false`) |
| Zone à défilement interne (détails JSON de l'audit) | `data-lenis-prevent` : le défilement natif est conservé |
| Zone à défilement interne (journal du terminal) | `data-lenis-prevent` également |
| Lien d'évitement « Aller au contenu » | défilement animé puis focus clavier sur `#contenu` |

Aucune animation ne porte sur un contenu métier sensible : les documents ne
transitent jamais par JavaScript (le téléchargement reste un flux PHP).

---

## 5. Réglage du tempo

Dans `public/assets/animations.js`, en tête de fichier :

```js
var TEMPO = 1;      // 1 = sobre (défaut)
                    // 0.7 = plus vif
                    // 1.4 = plus démonstratif (amplitude ×1,4)
```

`TEMPO` module à la fois les durées et l'amplitude des déplacements
(`DIST = 18 * TEMPO`). Aucun autre fichier à modifier.

---

## 6. Désactivation complète (retour à l'interface d'origine)

1. Dans `src/layout.php`, remplacer le corps de `render_assets()` par :

   ```php
   echo '<link rel="stylesheet" href="/assets/style.css">';
   echo '<script src="/assets/app.js" defer></script>';
   ```

2. Supprimer le bloc « Couche d'animation » en fin de `public/assets/style.css`.
3. Supprimer `public/assets/vendor/`, `anim-flag.js` et `animations.js`.

L'application retrouve exactement son rendu précédent : aucun code PHP,
aucune requête SQL, aucun réglage de sécurité ne dépend de ces fichiers.

---

## 7. Vérifications effectuées

| Vérification | Résultat |
|---|---|
| Bibliothèques chargées sous CSP `script-src 'self'` | `gsap`, `ScrollTrigger`, `Lenis` disponibles, **0 erreur console** |
| CSP inchangée | `default-src 'self'; script-src 'self'; …` (identique avant/après) |
| Aucun `eval()` / `new Function()` dans les fichiers tiers | 0 occurrence |
| Contenu masqué au chargement (`has-anim`) puis révélé | 8 → 0 éléments à `opacity: 0` après exécution |
| Chien de garde (timeline figée) | contenu visible, compteurs `4` et `6` restaurés |
| Thème appliqué avant le premier rendu | `data-theme` posé par `theme.js`, aucun flash, bascule persistée |
| Pages sans erreur console | `login`, `dashboard`, `upload`, `audit`, `users` : 0 erreur |
| Chiffrement, RBAC, journal | 16/16 tests crypto, 27/27 scénarios HTTP (aucune régression) |

La vérification visuelle (captures) reste manuelle : voir
[`RAPPORT_TESTS.md`](RAPPORT_TESTS.md), section « Captures d'écran ».

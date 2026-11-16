# Direction artistique — « Terminal Vault »

Document de référence de l'interface du coffre-fort documentaire : thème,
tokens, composants, accessibilité. Il complète
[`INTERFACE_ANIMATIONS.md`](INTERFACE_ANIMATIONS.md), qui traite du mouvement.

## 1. Intention

L'interface doit donner à voir ce que fait le logiciel : un coffre. D'où un
vocabulaire visuel de **poste de sécurité** — fond sombre, surfaces presque
invisibles, bordures d'un pixel, données techniques en monospace (hashes,
identifiants, tailles, journal) et trois accents sémantiques seulement :
vert = sécurisé, rouge doux = refus, cyan = chiffrement / information.

Aucune décoration gratuite : chaque couleur porte un sens, chaque animation
sert la compréhension (voir le document d'animations).

## 2. Tokens

Tout est centralisé dans `public/assets/style.css`, bloc `:root` (thème sombre,
valeur par défaut) et `html[data-theme="light"]` (surcharge). Les composants
n'utilisent **que** ces variables : aucune couleur codée en dur dans les pages.

### Couleurs — thème sombre

| Token | Valeur | Usage |
|---|---|---|
| `--bg` | `#0d1117` | fond principal |
| `--surface` | `#131822` | cartes, panneaux, tableaux |
| `--surface-2` | `#171d29` | en-têtes de tableau, champs, survol |
| `--surface-3` | `#1d2532` | badges neutres, boutons fantômes |
| `--line` | `rgba(255,255,255,0.08)` | bordure fine 1 px (jamais d'ombre lourde) |
| `--line-strong` | `rgba(255,255,255,0.16)` | bordure de champ actif |
| `--ink` | `#e6edf3` | texte principal |
| `--ink-soft` | `#c3ccd8` | texte courant secondaire |
| `--ink-muted` | `#8b949e` | métadonnées, légendes |
| `--primary` | `#22d3ee` | chiffrement, information, focus |
| `--accent` | `#a78bfa` | hash SHA-256, complément |
| `--success` | `#22c55e` | document sécurisé, action validée |
| `--danger` | `#f87171` | accès refusé, action destructive |
| `--warning` | `#fbbf24` | échec de connexion, avertissement |

### Couleurs — thème clair

Les mêmes noms de tokens sont redéfinis : `--bg #f6f8fb`, `--surface #ffffff`,
`--ink #0d1117`, `--ink-muted #5b6674`, `--primary #0e7490`,
`--accent #7c3aed`, `--success #15803d`, `--danger #b91c1c`. Les fonds de badges
sont des variantes `*-soft` (même teinte, alpha ~10 %) pour rester lisibles dans
les deux thèmes sans dupliquer les règles.

Le terminal (`--term-bg #080b11`) reste sombre dans les deux thèmes : c'est un
choix de réalisme (un journal système s'affiche sur fond noir) et il garantit
le contraste du vert `#22c55e` et du rouge `#f87171`.

### Autres tokens

| Token | Rôle |
|---|---|
| `--radius`, `--radius-sm`, `--radius-lg` | 12 / 8 / 16 px |
| `--glow-primary`, `--glow-danger` | halo de focus / survol (bordure + diffuse) |
| `--shadow-sm`, `--shadow` | réservés aux cartes flottantes et à la page de connexion |
| `--font` | Inter, puis pile système |
| `--mono` | JetBrains Mono, puis Consolas / Menlo |
| `--scan` | teinte du balayage « scan » au survol |

## 3. Typographie

| Rôle | Police | Réglages |
|---|---|---|
| Texte courant | **Inter** (variable, 100–900) | 15 px, interligne 1.55 |
| Titres | Inter | 700, `letter-spacing` −0.025em |
| Métadonnées, hashes, IDs, dates, tailles, journal | **JetBrains Mono** (variable) | 0.68–0.84rem, chiffres tabulaires, alignés à droite (`class="num"`) |

Les deux polices sont **auto-hébergées** dans `public/assets/fonts/`
(`inter-latin-wght.woff2` 48 Ko, `jetbrains-mono-latin-wght.woff2` 40 Ko,
`font-display: swap`). Aucun appel à Google Fonts : la CSP
`font-src` héritée de `default-src 'self'` reste valable et la démonstration
fonctionne hors ligne. Si les fichiers sont absents, la pile de repli système
prend le relais sans casser la mise en page.

## 4. Composants

### Cartes et surfaces

Une seule bordure `1px solid var(--line)`, fond `--surface`, rayon 12 px.
Au survol : `translateY(-3px)` et halo `--glow-primary` (180–200 ms). Aucune
ombre portée lourde : la profondeur vient du contraste de surface.

### Boutons

Quatre variantes : `btn-primary` (aplat cyan, texte sombre), `btn-secondary`
(surface + bordure), `btn-ghost`, `btn-danger` (contour rouge, fond rouge
translucide au survol). Transitions de 180 ms `ease-out`, léger
`translateY(-1px)` et halo au survol, `:active` redescend d'un pixel.

### Chips de chiffrement

`.chip` (neutre), `.chip-crypto` (cyan, `AES-256`), `.chip-hash` (violet,
`SHA-256`), `.chip-lock` (vert, `journal append-only`). Elles apparaissent :

- dans le bandeau `.vault-strip` du tableau de bord (« Coffre opérationnel ») ;
- sous le nom de chaque document (`AES-256`, `SHA-256`).

### Cadenas et déverrouillage

Chaque ligne de document porte un `.lock-indicator` : cadenas **ouvert** par
défaut, **fermé** (vert) quand la ligne est verrouillée. Le bouton
« Déverrouiller » replie/déplie les actions (`Consulter`, `Télécharger`,
`Retirer`) en 200 ms.

C'est une **commodité d'interface**, pas un contrôle de sécurité : sans
JavaScript, tous les boutons restent visibles ; côté serveur, chaque requête
revérifie la permission (`document.view`, `document.download`, `document.delete`).
L'état est exposé aux lecteurs d'écran (`aria-expanded` sur le bouton,
`aria-hidden` et `tabindex="-1"` sur les actions repliées) et le focus suit
l'ouverture.

### Journal en direct

`.terminal-panel` affiche les 8 derniers événements d'audit, en monospace :
heure, marque `✓`/`✗`, libellé lisible (« Document chiffré », « Accès
autorisé », « Accès refusé »…) et cible. Vert pour les actions validées, rouge
pour les refus. Un curseur clignotant termine la dernière ligne.

Le panneau n'est rendu **que** pour les rôles disposant de `audit.read`
(admin, auditeur) : il ne crée aucune fuite d'information pour les autres, et
il est servi par PHP (aucun nouvel endpoint, donc aucune surface d'attaque
supplémentaire). L'effet « direct » vient de l'animation d'apparition, pas d'un
sondage réseau.

### État vide

Icône de coffre dessinée en SVG inline, message explicite :
« Aucun document — déposez votre premier fichier dans le coffre », rappel du
format et de la limite de 10 Mo, et bouton de dépôt si le rôle y est autorisé.

### Effet « scan »

Au survol d'une carte ou d'une ligne de document, un dégradé cyan traverse
l'élément en ~1 s (`@keyframes scan-sweep`, propriété `background-position`).
Même famille de teinte que l'accent « chiffrement », désactivé sous
`prefers-reduced-motion`.

## 5. Thème clair / sombre

- `public/assets/theme.js` est chargé **en bloquant** dans `<head>` : il lit
  `localStorage["coffre.theme"]`, pose `data-theme` sur `<html>` et met à jour
  `<meta name="theme-color">` **avant le premier rendu** (pas de flash).
- Défaut : **sombre**, quel que soit le système — la direction artistique de la
  démonstration reste maîtrisée. Le choix de l'utilisateur est ensuite mémorisé.
- La bascule est un bouton unique (`[data-theme-toggle]`), présent dans la barre
  supérieure et, sur la page de connexion, en haut à droite. Il expose
  `aria-pressed` et un libellé explicite (« Passer en thème clair »).
- Sans JavaScript : `data-theme` n'est jamais posé, le thème sombre s'applique,
  tout le reste fonctionne.
- Le changement de thème ne recharge pas la page et ne touche pas aux animations
  GSAP (aucune couleur animée en JavaScript).

### Changer ou retirer un thème

1. Modifier les variables dans `:root` et `html[data-theme="light"]`
   (`style.css`) ; aucun autre fichier n'est concerné.
2. Pour ajouter un thème, dupliquer le bloc `html[data-theme="X"]` et autoriser
   la valeur dans `theme.js` (liste `stored === "light" || stored === "dark"`).
3. Pour supprimer la bascule : retirer `render_theme_toggle()` de
   `src/layout.php` et le `<script src="/assets/theme.js">` de `render_assets()`.

## 6. Accessibilité

| Vérification | Résultat |
|---|---|
| Texte principal `#e6edf3` sur `#0d1117` | ≈ 16:1 (AAA) |
| Texte secondaire `#8b949e` sur `#131822` | ≈ 5,8:1 (AA) |
| Vert `#22c55e` sur `#0d1117` | ≈ 8,3:1 (AAA) |
| Rouge `#f87171` sur `#0d1117` | ≈ 6,9:1 (AA) |
| Cyan `#22d3ee` sur `#0d1117` | ≈ 10,5:1 (AAA) |
| Thème clair : `#0d1117` sur `#f6f8fb` | ≈ 17,9:1 (AAA) |
| Thème clair : `#5b6674` sur `#ffffff` | ≈ 5,8:1 (AA) |

- Aucune information ne repose sur la seule couleur : chaque badge a un
  libellé, chaque état de cadenas a un titre/aria.
- `:focus-visible` = contour 2 px cyan, décalé de 2 px, sur tous les éléments
  interactifs.
- Toutes les bascules sont des `<button type="button">` avec `aria-expanded`
  ou `aria-pressed` ; le lien d'évitement « Aller au contenu » est conservé.
- `prefers-reduced-motion: reduce` supprime transitions, balayages, curseur
  clignotant et animations d'entrée.
- Structure sémantique inchangée : `table` avec `th scope="col"`, `aria-label`
  sur le terminal, pas de `div` cliquable.

## 7. Contraintes respectées

- **CSP** : `default-src 'self'` — polices, feuilles et scripts sont locaux ;
  aucun style inline, aucun script inline, aucune ressource distante.
- **Aucune dépendance de build** : CSS et JS natifs, pas de préprocesseur.
- **Aucun changement fonctionnel** : les permissions, les routes et les tests
  HTTP restent identiques ; le thème et les composants ne sont que du rendu.

## 8. Vérifications effectuées

- `php -l` : 19 fichiers, 0 erreur.
- Pages `login.php`, `dashboard.php`, `upload.php`, `audit.php`, `users.php` :
  HTTP 200, aucune notice PHP, aucune erreur console.
- `getComputedStyle` : fond `rgb(19,24,34)`, bordure `rgba(255,255,255,0.08)`,
  chips et journal en `JetBrains Mono`, polices `Inter` et `JetBrains Mono`
  chargées (`document.fonts.check`).
- Bascule de thème : `data-theme` passe à `light`, `aria-pressed=false`,
  `localStorage` mis à jour, `theme-color` à `#f6f8fb`.
- Bouton « Déverrouiller » : `aria-expanded` passe de `false` à `true`, les
  actions deviennent tabulables, le focus se place sur « Consulter ».

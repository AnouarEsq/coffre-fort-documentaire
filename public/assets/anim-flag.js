/**
 * Drapeau « animations disponibles », chargé de façon bloquante dans <head>.
 *
 * Pourquoi ce fichier existe :
 *  - il ajoute la classe `has-anim` sur <html> AVANT le premier rendu, ce qui
 *    permet à style.css de masquer temporairement les blocs animés et d'éviter
 *    un clignotement (FOUC) au chargement ;
 *  - il pose un filet de sécurité : si animations.js ne s'exécute pas ou si sa
 *    timeline ne peut pas avancer (fichier absent, erreur JS, onglet en
 *    arrière-plan), la classe est retirée au bout de 3 s et la page redevient
 *    entièrement visible et fonctionnelle.
 *
 * Le marqueur `window.__animDone` est posé par animations.js dès que le
 * contenu a été rendu visible. Aucun contenu n'est donc jamais perdu à cause
 * de l'animation.
 */
document.documentElement.classList.add("has-anim");
window.setTimeout(function () {
    if (!window.__animDone) {
        document.documentElement.classList.remove("has-anim");
    }
}, 3000);

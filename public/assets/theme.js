/**
 * Thème clair / sombre — « Terminal Vault ».
 *
 * Chargé de façon bloquante dans <head> : le thème est appliqué AVANT le
 * premier rendu, ce qui évite un flash blanc au chargement.
 *
 * - thème par défaut : sombre (direction artistique de la démonstration) ;
 * - choix mémorisé dans localStorage (`coffre.theme`) ;
 * - bascule accessible : bouton avec `aria-pressed` et libellé explicite ;
 * - compatible CSP : aucun style inline injecté, seulement un attribut
 *   `data-theme` sur <html>, lu par style.css.
 */
(function () {
    "use strict";

    var KEY = "coffre.theme";
    var root = document.documentElement;
    var stored = null;

    try {
        stored = window.localStorage.getItem(KEY);
    } catch (err) {
        // Navigation privée ou stockage désactivé : on garde le défaut.
    }

    var theme = (stored === "light" || stored === "dark") ? stored : "dark";

    function paint() {
        root.setAttribute("data-theme", theme);

        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) {
            meta.setAttribute("content", theme === "dark" ? "#0d1117" : "#f6f8fb");
        }

        var buttons = document.querySelectorAll("[data-theme-toggle]");
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].setAttribute("aria-pressed", theme === "dark" ? "true" : "false");
            buttons[i].setAttribute(
                "aria-label",
                theme === "dark" ? "Passer en thème clair" : "Passer en thème sombre"
            );
            buttons[i].setAttribute(
                "title",
                theme === "dark" ? "Thème clair" : "Thème sombre"
            );
        }
    }

    paint();

    function toggle() {
        theme = (theme === "dark") ? "light" : "dark";
        try {
            window.localStorage.setItem(KEY, theme);
        } catch (err) {
            // Sans stockage, la bascule reste valable pour la page courante.
        }
        paint();
        // Permet aux autres scripts (GSAP) de réagir sans couplage direct.
        window.dispatchEvent(new CustomEvent("vault:theme", { detail: { theme: theme } }));
    }

    document.addEventListener("DOMContentLoaded", function () {
        paint();
        var buttons = document.querySelectorAll("[data-theme-toggle]");
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].addEventListener("click", toggle);
        }
    });
})();

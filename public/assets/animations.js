/**
 * Couche d'animation — GSAP 3.13 + ScrollTrigger + Lenis.
 *
 * Principes de conception :
 *  1. Amélioration progressive : sans JavaScript, la page est complète et
 *     utilisable (voir anim-flag.js et style.css).
 *  2. Aucune donnée métier manipulée : on n'anime que `opacity` et
 *     `transform` (composées par le GPU, aucun recalcul de mise en page).
 *  3. `prefers-reduced-motion: reduce` respecté : tout est instantané.
 *  4. Compatible CSP `script-src 'self'` : bibliothèques auto-hébergées
 *     dans public/assets/vendor, aucun CDN, aucune ressource distante.
 *
 * Réglage du tempo : 1 = sobre (par défaut), 0.7 = vif, 1.4 = démonstratif.
 */
(function () {
    "use strict";

    var TEMPO = 1;
    var DIST = 18 * TEMPO;          // amplitude verticale des entrées (px)
    var root = document.documentElement;

    // Blocs pré-masqués par style.css sous `html.has-anim`. Cette liste doit
    // rester identique à celle du CSS : tout élément listé ici est rendu
    // visible en fin d'animation, et de force par revealAll().
    var REVEAL = ".page-head, .stat-card, .panel, .table-wrap, .filter-panel, "
        + ".empty-state, .auth-card, .auth-brand-panel > *, .vault-strip, .terminal-panel";

    /** Dernier filet de sécurité : tout est visible, quoi qu'il arrive. */
    function revealAll() {
        var nodes = document.querySelectorAll(REVEAL);
        for (var i = 0; i < nodes.length; i++) {
            nodes[i].style.opacity = "1";
        }
        // Un compteur interrompu afficherait « 0 » : on rétablit la vraie
        // valeur plutôt que de laisser un chiffre faux à l'écran.
        var counters = document.querySelectorAll(".stat-value[data-count-target]");
        for (var c = 0; c < counters.length; c++) {
            counters[c].textContent = counters[c].getAttribute("data-count-target");
        }
        root.classList.remove("has-anim");
        window.__animDone = true;
    }

    /** Durée mise à l'échelle du tempo (en secondes). */
    function d(seconds) {
        return seconds * TEMPO;
    }

    window.__animReady = true;

    var gsap = window.gsap;
    var reduce = !!(window.matchMedia
        && window.matchMedia("(prefers-reduced-motion: reduce)").matches);

    if (!gsap) {
        revealAll();
        return;
    }

    var ST = window.ScrollTrigger || null;
    if (ST) {
        gsap.registerPlugin(ST);
    }

    if (reduce) {
        revealAll();
        return;
    }

    /* ------------------------------------------------------------------
       1. Défilement lissé (Lenis) synchronisé avec ScrollTrigger
       ------------------------------------------------------------------ */
    var lenis = null;
    if (window.Lenis && ST) {
        lenis = new window.Lenis({
            duration: d(1.05),
            easing: function (t) {
                return Math.min(1, 1.001 - Math.pow(2, -10 * t));
            },
            smoothWheel: true,
            syncTouch: false,
            touchMultiplier: 1.6
        });
        lenis.on("scroll", ST.update);
        gsap.ticker.add(function (time) {
            lenis.raf(time * 1000);
        });
        gsap.ticker.lagSmoothing(0);
        root.classList.add("has-smooth");
    }

    // Le lien d'évitement (« Aller au contenu ») et les ancres internes
    // passent par Lenis pour rester cohérents avec le défilement lissé.
    document.addEventListener("click", function (event) {
        var link = event.target && event.target.closest
            ? event.target.closest('a[href^="#"]')
            : null;
        if (!link) {
            return;
        }
        var hash = link.getAttribute("href");
        if (!hash || hash === "#") {
            return;
        }
        var target = null;
        try {
            target = document.querySelector(hash);
        } catch (err) {
            return;
        }
        if (!target) {
            return;
        }
        event.preventDefault();
        if (lenis) {
            lenis.scrollTo(target.getBoundingClientRect().top
                + window.pageYOffset - 72);
        } else if (target.scrollIntoView) {
            target.scrollIntoView({ behavior: "smooth", block: "start" });
        }
        if (!target.hasAttribute("tabindex")) {
            target.setAttribute("tabindex", "-1");
        }
        target.focus({ preventScroll: true });
    });

    // Zones à défilement interne : Lenis doit les laisser au navigateur.
    var innerScroll = document.querySelectorAll(".details-cell pre, .terminal-body");
    for (var s = 0; s < innerScroll.length; s++) {
        innerScroll[s].setAttribute("data-lenis-prevent", "");
    }

    /* ------------------------------------------------------------------
       2. Barre de progression de lecture (haut de page)
       ------------------------------------------------------------------ */
    if (ST) {
        var bar = document.createElement("div");
        bar.className = "scroll-progress";
        bar.setAttribute("aria-hidden", "true");
        document.body.appendChild(bar);
        gsap.set(bar, { scaleX: 0 });
        gsap.to(bar, {
            scaleX: 1,
            ease: "none",           // courbe linéaire : indispensable ici
            scrollTrigger: {
                trigger: document.documentElement,
                start: "top top",
                end: "bottom bottom",
                scrub: 0.35
            }
        });
    }

    /* ------------------------------------------------------------------
       3. Soulignement animé de la navigation principale
       ------------------------------------------------------------------ */
    var nav = document.querySelector(".main-nav");
    var navActive = nav ? nav.querySelector("a.active") : null;
    var navBar = null;

    function placeNavBar(link, animate) {
        if (!nav || !navBar || !link) {
            return;
        }
        var n = nav.getBoundingClientRect();
        var r = link.getBoundingClientRect();
        gsap.to(navBar, {
            x: r.left - n.left,
            y: r.top - n.top + r.height - 3,
            width: r.width,
            opacity: 1,
            duration: animate ? d(0.32) : 0,
            ease: "power3.out",
            overwrite: "auto"
        });
    }

    if (nav && navActive) {
        navBar = document.createElement("span");
        navBar.className = "nav-underline";
        navBar.setAttribute("aria-hidden", "true");
        nav.appendChild(navBar);
        nav.classList.add("has-underline");

        var links = nav.querySelectorAll("a");
        for (var i = 0; i < links.length; i++) {
            links[i].addEventListener("mouseenter", function () {
                placeNavBar(this, true);
            });
            links[i].addEventListener("focus", function () {
                placeNavBar(this, true);
            });
        }
        nav.addEventListener("mouseleave", function () {
            placeNavBar(navActive, true);
        });
        window.addEventListener("resize", function () {
            placeNavBar(navActive, false);
        });
        // Position calculée une fois les polices appliquées.
        window.requestAnimationFrame(function () {
            placeNavBar(navActive, false);
        });
    }

    /* ------------------------------------------------------------------
       4. Compteurs des cartes de statistiques (0 → valeur)
       ------------------------------------------------------------------ */
    function animateCounters(offset) {
        var values = document.querySelectorAll(".stat-value");
        for (var i = 0; i < values.length; i++) {
            (function (el, index) {
                var raw = (el.textContent || "").replace(/\s/g, "");
                if (!/^\d+$/.test(raw)) {
                    return;                 // ex. nom de rôle : on n'y touche pas
                }
                var target = parseInt(raw, 10);
                if (target <= 0) {
                    return;
                }
                var box = { v: 0 };
                el.setAttribute("data-count-target", String(target));
                el.textContent = "0";
                gsap.to(box, {
                    v: target,
                    duration: d(0.9),
                    delay: offset + index * d(0.06),
                    ease: "power2.out",
                    onUpdate: function () {
                        el.textContent = String(Math.round(box.v));
                    },
                    onComplete: function () {
                        el.textContent = String(target);
                    }
                });
            })(values[i], i);
        }
    }

    /* ------------------------------------------------------------------
       5. Entrée de page (timeline courte, ≤ 1,1 s au total)
       ------------------------------------------------------------------ */
    function playIntro() {
        var tl = gsap.timeline({
            defaults: { ease: "power3.out" },
            onComplete: revealAll
        });

        var head = document.querySelectorAll(".page-head, .auth-brand-panel > *");
        if (head.length) {
            tl.fromTo(head,
                { opacity: 0, y: DIST },
                { opacity: 1, y: 0, duration: d(0.5), stagger: d(0.07) }, 0);
        }

        var alerts = document.querySelectorAll(".alert");
        if (alerts.length) {
            tl.fromTo(alerts,
                { opacity: 0, y: -6 },
                { opacity: 1, y: 0, duration: d(0.35), stagger: d(0.06) }, d(0.05));
        }

        var stats = document.querySelectorAll(".stat-card");
        if (stats.length) {
            tl.fromTo(stats,
                { opacity: 0, y: DIST, scale: 0.985 },
                { opacity: 1, y: 0, scale: 1, duration: d(0.52), stagger: d(0.08) }, d(0.1));
            animateCounters(d(0.2));
        }

        var surfaces = document.querySelectorAll(
            ".filter-panel, .table-wrap, .empty-state, .panel, .vault-strip, .terminal-panel"
        );
        if (surfaces.length) {
            tl.fromTo(surfaces,
                { opacity: 0, y: DIST * 0.6 },
                { opacity: 1, y: 0, duration: d(0.48), stagger: d(0.12) }, d(0.16));
        }

        var termLines = document.querySelectorAll(".term-line");
        if (termLines.length) {
            tl.fromTo(termLines,
                { opacity: 0, x: -6 },
                { opacity: 1, x: 0, duration: d(0.28), stagger: d(0.03) }, d(0.34));
        }

        var rows = document.querySelectorAll(".data-table tbody tr");
        if (rows.length) {
            var first = [];
            for (var r = 0; r < rows.length && r < 12; r++) {
                first.push(rows[r]);
            }
            tl.fromTo(first,
                { opacity: 0, y: 10 },
                { opacity: 1, y: 0, duration: d(0.35), stagger: d(0.02) }, d(0.26));

            if (rows.length > 12) {
                var rest = [];
                for (var k = 12; k < rows.length; k++) {
                    rest.push(rows[k]);
                }
                tl.fromTo(rest,
                    { opacity: 0 },
                    { opacity: 1, duration: d(0.3) }, d(0.4));
            }
        }

        var authCard = document.querySelector(".auth-card");
        if (authCard) {
            tl.fromTo(authCard,
                { opacity: 0, y: DIST * 1.4, scale: 0.99 },
                { opacity: 1, y: 0, scale: 1, duration: d(0.6) }, d(0.12));
        }

        if (tl.duration() === 0) {
            revealAll();
        }
    }

    /* ------------------------------------------------------------------
       6. Parallaxe discrète du panneau de présentation (page de connexion)
       ------------------------------------------------------------------ */
    function initParallax() {
        var panel = document.querySelector(".auth-brand-panel");
        if (!panel || !window.matchMedia || !window.matchMedia("(pointer: fine)").matches) {
            return;
        }
        var xTo = gsap.quickTo(panel, "x", { duration: d(0.6), ease: "power3.out" });
        var yTo = gsap.quickTo(panel, "y", { duration: d(0.6), ease: "power3.out" });

        window.addEventListener("mousemove", function (event) {
            var nx = (event.clientX / window.innerWidth - 0.5) * 2;
            var ny = (event.clientY / window.innerHeight - 0.5) * 2;
            xTo(nx * 8);
            yTo(ny * 6);
        });
    }

    // Le script est chargé en `defer` : le DOM est déjà analysé.
    playIntro();
    initParallax();

    // Chien de garde : si la timeline n'a pas pu s'exécuter (onglet en
    // arrière-plan, rAF suspendu, erreur imprévue), le contenu est rendu
    // visible au bout de 2,5 s. Le filet de anim-flag.js (3 s) couvre le
    // cas où ce fichier ne s'exécuterait pas du tout.
    window.setTimeout(function () {
        if (!window.__animDone) {
            revealAll();
        }
    }, 2500);
})();

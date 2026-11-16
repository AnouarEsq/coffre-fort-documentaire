/**
 * Validations côté navigateur (confort d'usage).
 *
 * IMPORTANT : ces contrôles ne remplacent JAMAIS les validations PHP.
 * Ils donnent un retour immédiat sur la taille et l'extension du fichier,
 * ainsi qu'une confirmation avant les actions destructrices.
 */
(function () {
    "use strict";

    var allowedExtensions = [
        "pdf", "png", "jpg", "jpeg", "gif", "webp",
        "doc", "docx", "xls", "xlsx", "ppt", "pptx", "odt", "ods"
    ];

    function formatBytes(bytes) {
        if (bytes < 1024) return bytes + " o";
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1).replace(".", ",") + " Ko";
        return (bytes / (1024 * 1024)).toFixed(1).replace(".", ",") + " Mo";
    }

    var uploadForm = document.getElementById("upload-form");
    if (uploadForm) {
        var input = document.getElementById("document");
        var feedback = document.getElementById("file-check");
        var maxBytes = parseInt(uploadForm.dataset.maxBytes || "10485760", 10);

        if (input && feedback) {
            input.addEventListener("change", function () {
                feedback.textContent = "";
                feedback.classList.remove("text-danger", "text-ok");
                var file = input.files && input.files[0];
                if (!file) return;

                var extension = file.name.split(".").pop().toLowerCase();
                if (allowedExtensions.indexOf(extension) === -1) {
                    feedback.textContent = "Extension non autorisée : ." + extension;
                    feedback.classList.add("text-danger");
                    input.value = "";
                    return;
                }
                if (file.size === 0) {
                    feedback.textContent = "Le fichier est vide.";
                    feedback.classList.add("text-danger");
                    input.value = "";
                    return;
                }
                if (file.size > maxBytes) {
                    feedback.textContent = "Fichier trop volumineux (" + formatBytes(file.size)
                        + "). Maximum : " + formatBytes(maxBytes) + ".";
                    feedback.classList.add("text-danger");
                    input.value = "";
                    return;
                }
                feedback.textContent = "Prêt à chiffrer : " + file.name + " (" + formatBytes(file.size) + ")";
                feedback.classList.add("text-ok");
            });
        }
    }

    // Confirmation explicite avant une action destructive.
    document.querySelectorAll("form[data-confirm]").forEach(function (form) {
        form.addEventListener("submit", function (event) {
            var message = form.getAttribute("data-confirm") || "Confirmer cette action ?";
            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });

    // Vérification de correspondance des mots de passe (page utilisateurs).
    var password = document.getElementById("password");
    var confirm = document.getElementById("password_confirm");
    if (password && confirm) {
        var check = function () {
            confirm.setCustomValidity(
                confirm.value && password.value !== confirm.value
                    ? "Les mots de passe ne correspondent pas."
                    : ""
            );
        };
        password.addEventListener("input", check);
        confirm.addEventListener("input", check);
    }
})();

/**
 * Cadenas de document : repli/dépli des actions (« déverrouiller »).
 *
 * Amélioration progressive : sans JavaScript, tous les boutons restent
 * visibles et utilisables. Avec JavaScript, les actions sont repliées
 * derrière un cadenas fermé, ce qui matérialise l'état du coffre.
 * Aucun contrôle de sécurité ici : les permissions restent vérifiées
 * côté serveur à chaque requête.
 */
(function () {
    "use strict";

    var toggles = document.querySelectorAll("[data-lock-toggle]");
    if (!toggles.length) return;

    function setLocked(row, button, actions, locked, moveFocus) {
        row.classList.toggle("is-locked", locked);
        button.textContent = locked ? "Déverrouiller" : "Verrouiller";
        button.setAttribute("aria-expanded", locked ? "false" : "true");
        actions.setAttribute("aria-hidden", locked ? "true" : "false");

        var focusable = actions.querySelectorAll("a, button, input");
        Array.prototype.forEach.call(focusable, function (element) {
            if (locked) {
                element.setAttribute("tabindex", "-1");
            } else {
                element.removeAttribute("tabindex");
            }
        });

        if (!locked && moveFocus && focusable.length) {
            focusable[0].focus({ preventScroll: true });
        }
    }

    Array.prototype.forEach.call(toggles, function (button) {
        var row = button.closest("tr");
        var actions = document.getElementById(button.getAttribute("aria-controls") || "");
        if (!row || !actions) return;

        button.hidden = false;
        setLocked(row, button, actions, true, false);

        button.addEventListener("click", function () {
            var locked = !row.classList.contains("is-locked");
            setLocked(row, button, actions, locked, !locked);
        });
    });
})();

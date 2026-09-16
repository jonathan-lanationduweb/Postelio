/**
 * Définition / réinitialisation de mot de passe (flux natif sécurisé).
 * Sert le lien « Mot de passe oublié » ET la réclamation d'un compte candidat invité
 * (claim_url du parcours guest). Lit login + key depuis l'URL, poste vers
 * POST /auth/reset-password. Le mot de passe n'est JAMAIS mis dans l'URL ni stocké
 * (localStorage) : il n'est transmis que dans le corps de la requête.
 */
(function () {
  "use strict";

  document.addEventListener("DOMContentLoaded", function () {
    if (!window.PostelioAPI) { return; }
    var params = new URLSearchParams(window.location.search);
    var login = params.get("login") || "";
    var key = params.get("key") || "";

    var form = document.getElementById("reset-form");
    var invalid = document.getElementById("rp-invalid");
    var success = document.getElementById("rp-success");
    var errBox = document.getElementById("rp-error");
    var pw = document.getElementById("rp-password");
    var pw2 = document.getElementById("rp-password2");
    if (!form) { return; }

    if (!login || !key) {
      if (invalid) { invalid.hidden = false; }
      return;
    }
    form.hidden = false;

    function showError(msg) {
      if (!errBox) { return; }
      errBox.textContent = msg;
      errBox.hidden = false;
    }

    form.addEventListener("submit", function (ev) {
      ev.preventDefault();
      if (errBox) { errBox.hidden = true; }
      var p = pw ? pw.value : "";
      var p2 = pw2 ? pw2.value : "";
      if (p.length < 8) { showError("Mot de passe trop court (8 caractères minimum)."); return; }
      if (p !== p2) { showError("Les deux mots de passe ne correspondent pas."); return; }

      var btn = form.querySelector('button[type="submit"]');
      if (btn) { btn.disabled = true; btn.setAttribute("aria-busy", "true"); }

      PostelioAPI.client.post("/auth/reset-password", { body: { login: login, key: key, password: p } }).then(function () {
        form.hidden = true;
        if (success) { success.hidden = false; success.focus(); }
      }, function (err) {
        if (btn) { btn.disabled = false; btn.removeAttribute("aria-busy"); }
        var msg;
        if (err.status === 409) {
          msg = "Ce lien est invalide ou a expiré. Depuis la page de connexion, demandez un nouveau lien via « Mot de passe oublié ».";
        } else if (err.status === 422) {
          var f = err.firstFieldError && err.firstFieldError();
          msg = f ? String(f.reason) : "Mot de passe invalide.";
        } else if (err.status === 429) {
          msg = "Trop de tentatives. Réessayez dans quelques minutes.";
        } else if (err.status === 0) {
          msg = "Impossible de contacter Postelio. Vérifiez votre connexion et réessayez.";
        } else {
          msg = err.userMessage ? err.userMessage() : "Une erreur est survenue. Réessayez plus tard.";
        }
        showError(msg);
      });
    });
  });
})();

/**
 * Page de confirmation de candidature guest (double opt-in).
 * Lit uuid + token depuis l'URL, appelle POST /applications/guest/confirm, et affiche
 * chargement / succès / lien expiré ou déjà utilisé / erreur générique. Aucune donnée
 * sensible n'est stockée ; le jeton n'est utilisé que pour cet appel.
 */
(function () {
  "use strict";

  var UUID_RE = /^[0-9a-fA-F-]{36}$/;

  function e(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  function card(title, text, actionsHtml, tone) {
    return '<div class="gconf-status gconf-status--' + tone + '">' +
      "<h1>" + e(title) + "</h1>" +
      "<p>" + e(text) + "</p>" +
      (actionsHtml || "") +
      "</div>";
  }

  document.addEventListener("DOMContentLoaded", function () {
    var root = document.getElementById("gconf");
    if (!root || !window.PostelioAPI) { return; }

    var params = new URLSearchParams(window.location.search);
    var uuid = params.get("uuid") || "";
    var token = params.get("token") || "";

    if (!UUID_RE.test(uuid) || !token) {
      root.innerHTML = card("Lien invalide", "Ce lien de confirmation est incomplet ou invalide. Vous pouvez postuler à nouveau depuis l'offre.", '<div class="gconf-actions"><a class="btn btn-outline" href="offres.html">Voir les offres</a></div>', "error");
      return;
    }

    root.innerHTML = "<p>Confirmation de votre candidature en cours…</p>";

    PostelioAPI.client.post("/applications/guest/confirm", { body: { uuid: uuid, token: token } }).then(function (res) {
      var d = res.data || {};
      var claim = d.claim_url || "";
      var actions = '<div class="gconf-actions">' +
        '<a class="btn btn-outline" href="offres.html">Voir les offres</a>' +
        (claim
          ? '<a class="btn btn-accent" href="' + e(claim) + '">Créer / activer mon espace candidat</a>'
          : '<a class="btn btn-accent" href="connexion.html">Accéder à mon espace</a>') +
        "</div>";
      root.innerHTML = card(
        "Candidature confirmée",
        "Votre candidature a bien été transmise au recruteur. " + (claim ? "Vous pouvez maintenant créer votre espace candidat pour suivre son avancement." : "Connectez-vous à votre espace pour suivre son avancement."),
        actions,
        "success"
      );
    }, function (err) {
      var title = "Confirmation impossible";
      var text;
      var actions = '<div class="gconf-actions"><a class="btn btn-outline" href="offres.html">Voir les offres</a></div>';
      if (err.status === 409) {
        title = "Lien expiré ou déjà utilisé";
        text = (err.message ? String(err.message) : "Ce lien de confirmation a expiré ou a déjà été utilisé.") + " Vous pouvez postuler à nouveau depuis l'offre.";
      } else if (err.status === 429) {
        var wait = err.details && err.details.retry_after ? Math.ceil(err.details.retry_after / 60) : 0;
        text = "Trop de tentatives." + (wait ? " Réessayez dans environ " + wait + " minute(s)." : " Réessayez dans quelques minutes.");
      } else if (err.status === 422) {
        text = "Ce lien de confirmation est invalide.";
      } else if (err.status === 0) {
        text = "Impossible de contacter Postelio. Vérifiez votre connexion et réessayez.";
      } else {
        text = err.userMessage ? err.userMessage() : "Une erreur est survenue. Réessayez plus tard.";
      }
      root.innerHTML = card(title, text, actions, "error");
    });
  });
})();

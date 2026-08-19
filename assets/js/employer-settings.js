/**
 * Espace recruteur — Paramètres (espace-entreprise-parametres.html).
 * Préférences (boutons fictifs gérés par employer-shell via data-toast) et
 * déconnexion.
 */
(function () {
  "use strict";

  document.addEventListener("DOMContentLoaded", function () {
    if (!window.EMP || !EMP.ready) { return; }
    if (!document.getElementById("logout-btn")) { return; }
    initLogout();
  });

  function initLogout() {
    var btn = document.getElementById("logout-btn");
    if (btn) {
      btn.addEventListener("click", function () { SS.auth.logout(); });
    }
  }
})();

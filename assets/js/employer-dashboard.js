/**
 * Espace recruteur — Tableau de bord (espace-entreprise.html).
 *
 * Greeting personnalisé, liste « À faire aujourd'hui » et carte contextuelle
 * de renouvellement (10 €) affichée si une offre expire bientôt / a expiré.
 * Les indicateurs (offres actives, candidatures…) sont des valeurs fixes de
 * démonstration inscrites dans le HTML.
 */
(function () {
  "use strict";

  document.addEventListener("DOMContentLoaded", function () {
    if (!window.EMP || !EMP.ready) { return; }
    if (!document.getElementById("tableau")) { return; }

    fillGreeting();
    initTodo();
    renderRenewal();
  });

  function fillGreeting() {
    var el = document.getElementById("hello-name");
    if (!el) { return; }
    var s = SS.auth.get() || {};
    el.textContent = s.firstName || SS.auth.displayName() || "";
  }

  /* ---- À faire aujourd'hui : liste d'actions prioritaires cliquables ----
     Les liens pointent vers les PAGES dédiées (plus d'ancres internes). */
  function initTodo() {
    var list = document.getElementById("dash-todo");
    if (!list) { return; }
    var e = SS.escapeHtml;

    var items = [
      { level: "warn",   texte: "3 nouvelles candidatures à examiner", href: "espace-entreprise-candidatures.html", action: "Examiner" },
      { level: "urgent", texte: "1 candidat attend une réponse depuis 4 jours", href: "espace-entreprise-candidatures.html", action: "Répondre" },
      { level: "ok",     texte: "2 entretiens cette semaine", href: "espace-entreprise-entretiens.html", action: "Voir les entretiens" },
      { level: "warn",   texte: "1 offre expire dans 3 jours", href: "espace-entreprise-offres.html", action: "Renouveler" }
    ];

    var labels = { urgent: "Urgent", warn: "À traiter", ok: "À venir" };

    list.innerHTML = items.map(function (it) {
      return '<li class="todo-item">' +
          '<span class="todo-dot todo-dot--' + it.level + '" aria-hidden="true"></span>' +
          '<span class="todo-item__text">' + e(it.texte) + '</span>' +
          '<span class="todo-item__tag todo-item__tag--' + it.level + '">' + e(labels[it.level]) + '</span>' +
          '<a class="btn btn-outline btn-sm todo-item__action" href="' + it.href + '">' + e(it.action) + '</a>' +
        '</li>';
    }).join("");
  }

  /* ---- Carte contextuelle 10 € : offres expirées / proches d'expiration ---- */
  function renderRenewal() {
    var box = document.getElementById("renewal-alert");
    if (!box) { return; }
    EMP.getCompanyOffers().then(function (offers) {
      renderRenewalAlert(box, offers.filter(EMP.needsRenewal));
    }).catch(function () { /* silencieux : le tableau de bord reste utilisable */ });
  }

  function renderRenewalAlert(box, toRenew) {
    if (!toRenew.length) { box.innerHTML = ""; return; }
    var target = toRenew.slice().sort(function (a, b) {
      return new Date(a.dateExpiration) - new Date(b.dateExpiration);
    })[0];
    var e = SS.escapeHtml;
    var expired = target.statut === "expiree";

    box.innerHTML =
      '<div class="notice notice--demo" style="margin-top:var(--sp-4);">' +
        "<strong>Votre offre expire bientôt — " + e(target.titre) + "</strong><br>" +
        (expired
          ? "Cette offre a expiré et n'est plus visible des candidats. "
          : "Elle arrive à expiration le " + e(SS.formatDate(target.dateExpiration)) + ". ") +
        "Renouvelez-la pendant 30&nbsp;jours pour 10&nbsp;€.<br>" +
        '<a class="btn btn-accent btn-sm" style="margin-top:var(--sp-3);" href="paiement.html?offre=' +
          encodeURIComponent(target.id) + '">Renouveler pour 10&nbsp;€</a>' +
      "</div>";
  }
})();

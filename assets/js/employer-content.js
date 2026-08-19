/**
 * Espace recruteur — Contenus entreprise (espace-entreprise-contenus.html).
 * Contenus « marque employeur » de démonstration.
 */
(function () {
  "use strict";

  document.addEventListener("DOMContentLoaded", function () {
    if (!window.EMP || !EMP.ready) { return; }
    if (!document.getElementById("contents-list")) { return; }
    seedContents();
  });

  function seedContents() {
    var box = document.getElementById("contents-list");
    if (!box) { return; }
    var e = SS.escapeHtml;

    var items = [
      { titre: "Une journée avec notre conducteur de travaux", type: "Reportage", jours: 5 },
      { titre: "Découvrez notre atelier et nos équipements", type: "Visite", jours: 18 },
      { titre: "Comment travaille notre équipe support", type: "Coulisses", jours: 40 }
    ];

    if (!items.length) {
      box.innerHTML = '<div class="empty-state"><h3>Aucun contenu publié</h3>' +
        '<p>Partagez les coulisses de votre entreprise pour attirer les bons profils.</p>' +
        '<p><a class="btn btn-accent" href="publier-savoir-faire.html">Publier un contenu</a></p></div>';
      return;
    }

    box.innerHTML = items.map(function (it) {
      return '<article class="card dash-card content-card">' +
          '<div class="content-card__body">' +
            '<span class="badge badge--neutral">' + e(it.type) + '</span>' +
            '<h3 class="content-card__title">' + e(it.titre) + '</h3>' +
            '<p class="text-muted">Publié ' + e(SS.relativeDate(EMP.dateFromToday(-it.jours))) + '</p>' +
          '</div>' +
          '<div class="row-actions">' +
            '<a class="btn btn-outline btn-sm" href="savoir-faire.html">Voir</a>' +
            '<button type="button" class="btn btn-ghost btn-sm" data-toast="Modification du contenu (démonstration).">Modifier</button>' +
          '</div>' +
        '</article>';
    }).join("");
  }
})();

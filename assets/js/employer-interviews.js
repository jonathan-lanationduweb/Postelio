/**
 * Espace recruteur — Entretiens (espace-entreprise-entretiens.html).
 * Liste d'entretiens de démonstration.
 */
(function () {
  "use strict";

  document.addEventListener("DOMContentLoaded", function () {
    if (!window.EMP || !EMP.ready) { return; }
    if (!document.getElementById("interviews-list")) { return; }
    seedInterviews();
  });

  function seedInterviews() {
    var box = document.getElementById("interviews-list");
    if (!box) { return; }
    var e = SS.escapeHtml;

    var items = [
      { quand: "Mardi 25 août · 14:30", nom: "Julie Martin", poste: "Assistante commerciale", mode: "Visioconférence" },
      { quand: "Mercredi 26 août · 10:00", nom: "Thomas Ravel", poste: "Préparateur de commandes", mode: "Dans nos locaux — Lyon 3e" },
      { quand: "Jeudi 27 août · 16:15", nom: "Inès Fabre", poste: "Chargée de communication", mode: "Téléphone" }
    ];

    if (!items.length) {
      box.innerHTML = '<div class="empty-state"><h3>Aucun entretien planifié</h3>' +
        '<p>Proposez un entretien depuis le pipeline de candidatures pour le voir apparaître ici.</p></div>';
      return;
    }

    box.innerHTML = items.map(function (it) {
      return '<article class="appli-card interview-card">' +
          '<div class="interview-card__when">' + e(it.quand) + '</div>' +
          '<div class="interview-card__who"><strong>' + e(it.nom) + '</strong>' +
            '<span class="text-muted">' + e(it.poste) + '</span></div>' +
          '<p class="interview-card__mode">' + e(it.mode) + '</p>' +
          '<div class="row-actions">' +
            '<button type="button" class="btn btn-outline btn-sm" data-toast="Ouverture du profil candidat (démonstration).">Voir le profil</button>' +
            '<a class="btn btn-ghost btn-sm" href="espace-entreprise-messages.html">Message</a>' +
            '<button type="button" class="btn btn-ghost btn-sm" data-toast="Modification de l\'entretien (démonstration).">Modifier</button>' +
          '</div>' +
        '</article>';
    }).join("");
  }
})();

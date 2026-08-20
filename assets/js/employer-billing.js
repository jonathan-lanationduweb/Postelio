/**
 * Espace recruteur — Facturation (espace-entreprise-facturation.html).
 * Historique de renouvellements de démonstration.
 */
(function () {
  "use strict";

  document.addEventListener("DOMContentLoaded", function () {
    if (!window.EMP || !EMP.ready) { return; }
    if (!document.getElementById("billing-history")) { return; }
    seedBilling();
  });

  function seedBilling() {
    var tbody = document.getElementById("billing-history");
    if (!tbody) { return; }
    var e = SS.escapeHtml;

    var rows = [
      { date: EMP.dateFromToday(-12), offre: "Assistant(e) commercial(e) — CDI" },
      { date: EMP.dateFromToday(-48), offre: "Gestionnaire de paie — CDI" },
      { date: EMP.dateFromToday(-95), offre: "Conducteur de travaux — CDI" }
    ];

    tbody.innerHTML = rows.map(function (r) {
      /* Le bouton [Télécharger] est fictif : aucun fichier réel n'est produit.
         Le toast est géré par employer-shell (data-toast). */
      return "<tr>" +
        '<td data-label="Date">' + e(SS.formatDate(r.date)) + "</td>" +
        '<td data-label="Offre">' + e(r.offre) + "</td>" +
        '<td data-label="Montant">10&nbsp;€</td>' +
        '<td data-label="Statut"><span class="badge badge--remote">Payé</span></td>' +
        '<td data-label="Facture"><button type="button" class="btn btn-outline btn-sm" data-toast="Facture téléchargée (démonstration).">Télécharger</button></td>' +
      "</tr>";
    }).join("");
  }
})();

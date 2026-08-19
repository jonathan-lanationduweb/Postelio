/**
 * Espace recruteur — Mes offres (espace-entreprise-offres.html).
 *
 * Cartes d'offres enrichies (stats + actions). Les actions (désactiver /
 * réactiver / archiver) transitent par le stockage local ss_offer_overrides
 * (clé partagée, inchangée) ; le renouvellement renvoie vers
 * paiement.html?offre=<id>.
 */
(function () {
  "use strict";

  document.addEventListener("DOMContentLoaded", function () {
    if (!window.EMP || !EMP.ready) { return; }
    if (!document.getElementById("dashboard-offers")) { return; }
    render();
  });

  function render() {
    EMP.getCompanyOffers().then(function (offers) {
      renderOffers(offers);
    }).catch(function () {
      SS.dataError(document.getElementById("dashboard-offers"));
    });
  }

  function statusBadge(o) {
    if (o.statut === "active") {
      if (o.dateExpiration && EMP.daysUntil(o.dateExpiration) <= 7) {
        return '<span class="badge badge--accent">Expire bientôt</span>';
      }
      return '<span class="badge badge--remote">Publiée</span>';
    }
    if (o.statut === "desactivee") {
      return '<span class="badge badge--neutral">Désactivée</span>';
    }
    return '<span class="badge badge--expired">Expirée</span>';
  }

  function expiryLabel(o) {
    if (o.statut === "desactivee") { return "Hors ligne (désactivée)"; }
    if (o.statut === "expiree" || !o.dateExpiration) { return "Offre expirée"; }
    var d = EMP.daysUntil(o.dateExpiration);
    if (d <= 0) { return "Expire aujourd'hui"; }
    if (d === 1) { return "Expire demain"; }
    return "Expire dans " + d + " jours";
  }

  function profilesViewed(id) {
    return Math.max(1, Math.round(EMP.stableViews(id) / 12));
  }

  function interviewsForOffer(id) {
    return SS.fakeApplicationCount(id) % 3;
  }

  function renderOffers(offers) {
    var box = document.getElementById("dashboard-offers");
    if (!box) { return; }
    var e = SS.escapeHtml;

    if (!offers.length) {
      box.innerHTML =
        '<div class="empty-state"><h3>Vous n\'avez pas encore publié d\'offre</h3>' +
        '<p>Publiez une annonce pour commencer à recevoir des candidatures.</p>' +
        '<p><a class="btn btn-accent" href="publier-offre.html">Créer ma première offre</a></p></div>';
      return;
    }

    box.innerHTML = offers.map(function (o) {
      var renewable = EMP.needsRenewal(o);
      var apps = SS.fakeApplicationCount(o.id);
      var expClass = (o.statut === "active" && o.dateExpiration && EMP.daysUntil(o.dateExpiration) <= 7) ||
                     o.statut === "expiree" ? " offer-card__expiry--warn" : "";

      var actions =
        '<a class="btn btn-outline btn-sm" href="offres.html">Voir</a>' +
        '<a class="btn btn-outline btn-sm" href="publier-offre.html?modifier=' + encodeURIComponent(o.id) + '">Modifier</a>' +
        '<a class="btn btn-ghost btn-sm" href="espace-entreprise-candidatures.html">Candidatures</a>' +
        '<button type="button" class="btn btn-ghost btn-sm" data-toast="Offre dupliquée (démonstration).">Dupliquer</button>';
      if (o.statut === "active") {
        actions += '<button type="button" class="btn btn-ghost btn-sm" data-offer-action="disable" data-id="' + e(o.id) + '">Désactiver</button>';
      } else if (o.statut === "desactivee") {
        actions += '<button type="button" class="btn btn-primary btn-sm" data-offer-action="enable" data-id="' + e(o.id) + '">Réactiver</button>';
      }
      if (renewable) {
        actions += '<a class="btn btn-accent btn-sm" href="paiement.html?offre=' + encodeURIComponent(o.id) + '">Renouveler</a>';
      }
      actions += '<button type="button" class="btn btn-danger btn-sm" data-offer-action="archive" data-id="' + e(o.id) + '">Archiver</button>';

      return '<article class="card dash-card offer-card">' +
          '<div class="offer-card__head">' +
            '<div><h3 class="offer-card__title">' + e(o.titre) + '</h3>' +
              '<p class="offer-card__meta">' + e(o.ville) + ' — ' + e(o.contrat) + ' · publiée le ' + e(SS.formatDate(o.datePublication)) + '</p></div>' +
            statusBadge(o) +
          '</div>' +
          '<ul class="offer-stats">' +
            '<li><b>' + EMP.stableViews(o.id) + '</b><span>vues</span></li>' +
            '<li><b>' + apps + '</b><span>candidatures</span></li>' +
            '<li><b>' + profilesViewed(o.id) + '</b><span>profils consultés</span></li>' +
            '<li><b>' + interviewsForOffer(o.id) + '</b><span>entretiens</span></li>' +
          '</ul>' +
          '<p class="offer-card__expiry' + expClass + '">' + e(expiryLabel(o)) + '</p>' +
          '<div class="row-actions">' + actions + '</div>' +
        '</article>';
    }).join("");

    box.querySelectorAll("button[data-offer-action]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var id = btn.getAttribute("data-id");
        var action = btn.getAttribute("data-offer-action");
        var overrides = SS.store.get(APP_CONFIG.storage.offerOverrides, {});
        overrides[id] = overrides[id] || {};

        if (action === "disable") {
          overrides[id].statut = "desactivee";
          SS.toast("Offre désactivée.");
        } else if (action === "enable") {
          overrides[id].statut = "active";
          var next = new Date();
          next.setDate(next.getDate() + APP_CONFIG.payment.renewal.durationDays);
          overrides[id].dateExpiration = next.toISOString().slice(0, 10);
          SS.toast("Offre réactivée.");
        } else if (action === "archive") {
          overrides[id].archived = true;
          SS.toast("Offre archivée.");
        }
        SS.store.set(APP_CONFIG.storage.offerOverrides, overrides);
        render();
      });
    });
  }
})();

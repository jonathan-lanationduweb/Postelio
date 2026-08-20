/**
 * Espace recruteur — Mes offres (espace-entreprise-offres.html).
 *
 * Cartes d'offres enrichies (stats + actions), classées par onglets :
 *   Brouillons · Publiées · Expirées · Archivées.
 * Actions rapides en tête : publier, dupliquer une offre existante, partir d'un
 * modèle. La duplication (§21) renvoie vers publier-offre.html?dupliquer=<id>
 * (préremplissage complet côté publish.js) ; le brouillon (§22-23) est lu depuis
 * ss_offre_brouillon. Les statuts (désactivation / renouvellement / archivage)
 * transitent par ss_offer_overrides (clé partagée, inchangée).
 */
(function () {
  "use strict";

  var DRAFT_KEY = "ss_offre_brouillon";
  /* Libellés de modèles (miroir léger de publish.js pour l'action rapide). */
  var TEMPLATES = [
    ["comptable", "Comptable"], ["gestionnaire-paie", "Gestionnaire de paie"],
    ["assistant-administratif", "Assistant administratif"], ["commercial", "Commercial"],
    ["developpeur", "Développeur web"], ["conseiller-vente", "Conseiller de vente"]
  ];

  var currentTab = "publiees";

  document.addEventListener("DOMContentLoaded", function () {
    if (!window.EMP || !EMP.ready) { return; }
    if (!document.getElementById("dashboard-offers")) { return; }
    renderQuickActions();
    load();
  });

  function load() {
    var s = SS.auth.get() || {};
    var companyId = s.companyId || APP_CONFIG.demoCompany.id;
    SS.getOffers().then(function (offers) {
      var mine = offers.filter(function (o) { return o.entrepriseId === companyId; });
      if (!mine.length) { mine = EMP.buildDemoOffers(offers); }
      renderTabs(mine);
      renderList(mine);
    }).catch(function () {
      SS.dataError(document.getElementById("dashboard-offers"));
    });
  }

  function getDraft() {
    var d = SS.store.get(DRAFT_KEY, null);
    return d && d.state ? d : null;
  }

  function categorize(offers) {
    return {
      brouillons: getDraft() ? 1 : 0,
      publiees: offers.filter(function (o) { return !o.archived && (o.statut === "active" || o.statut === "desactivee"); }),
      expirees: offers.filter(function (o) { return !o.archived && o.statut === "expiree"; }),
      archivees: offers.filter(function (o) { return o.archived; })
    };
  }

  /* ---- Actions rapides (§24) ---- */
  function renderQuickActions() {
    var box = document.getElementById("offers-quick");
    if (!box || box.querySelector("[data-dup-menu]")) { return; }
    var e = SS.escapeHtml;
    var dup = document.createElement("details");
    dup.className = "offers-menu";
    dup.setAttribute("data-dup-menu", "");
    dup.innerHTML = '<summary class="btn btn-outline btn-sm">Dupliquer une offre</summary>' +
      '<div class="offers-menu__pop" id="dup-pop"><p class="text-muted">Chargement…</p></div>';
    var tpl = document.createElement("details");
    tpl.className = "offers-menu";
    tpl.innerHTML = '<summary class="btn btn-outline btn-sm">Utiliser un modèle</summary>' +
      '<div class="offers-menu__pop">' + TEMPLATES.map(function (t) {
        return '<a href="publier-offre.html?modele=' + t[0] + '">' + e(t[1]) + "</a>";
      }).join("") + "</div>";
    box.appendChild(dup);
    box.appendChild(tpl);

    /* Remplir le menu « Dupliquer » avec les offres publiées de l'entreprise. */
    dup.addEventListener("toggle", function () {
      if (!dup.open) { return; }
      var s = SS.auth.get() || {};
      var companyId = s.companyId || APP_CONFIG.demoCompany.id;
      SS.getOffers().then(function (offers) {
        var mine = offers.filter(function (o) { return o.entrepriseId === companyId && !o.archived; });
        if (!mine.length) { mine = EMP.buildDemoOffers(offers); }
        var pop = document.getElementById("dup-pop");
        pop.innerHTML = mine.length
          ? mine.map(function (o) { return '<a href="publier-offre.html?dupliquer=' + encodeURIComponent(o.id) + '">' + e(o.titre) + "</a>"; }).join("")
          : '<p class="text-muted">Aucune offre à dupliquer.</p>';
      });
    });
  }

  /* ---- Onglets ---- */
  function renderTabs(offers) {
    var box = document.getElementById("offers-tabs");
    if (!box) { return; }
    var c = categorize(offers);
    var TABS = [
      ["publiees", "Publiées", (c.publiees.length)],
      ["brouillons", "Brouillons", c.brouillons],
      ["expirees", "Expirées", c.expirees.length],
      ["archivees", "Archivées", c.archivees.length]
    ];
    box.innerHTML = TABS.map(function (t) {
      var on = t[0] === currentTab;
      return '<button type="button" class="offers-tab chip" role="tab" aria-selected="' + on + '" data-tab="' + t[0] + '">' +
        SS.escapeHtml(t[1]) + ' <span class="offers-tab__count">' + t[2] + "</span></button>";
    }).join("");
    box.querySelectorAll("[data-tab]").forEach(function (btn) {
      btn.addEventListener("click", function () { currentTab = btn.getAttribute("data-tab"); load(); });
    });
  }

  /* ---- Liste selon l'onglet actif ---- */
  function renderList(offers) {
    var box = document.getElementById("dashboard-offers");
    if (!box) { return; }
    var c = categorize(offers);

    if (currentTab === "brouillons") { renderDrafts(box); return; }

    var list = c[currentTab] || [];
    if (!list.length) { box.innerHTML = emptyFor(currentTab); return; }
    box.innerHTML = list.map(cardHtml).join("");
    wireActions(box);
  }

  function emptyFor(tab) {
    if (tab === "publiees") {
      return '<div class="empty-state"><h3>Aucune offre publiée</h3>' +
        '<p>Publiez une annonce pour commencer à recevoir des candidatures.</p>' +
        '<p><a class="btn btn-accent" href="publier-offre.html">Créer une offre</a></p></div>';
    }
    if (tab === "expirees") { return '<div class="empty-state"><h3>Aucune offre expirée</h3><p>Vos offres actives apparaissent dans l\'onglet « Publiées ».</p></div>'; }
    return '<div class="empty-state"><h3>Aucune offre archivée</h3><p>Les offres que vous archivez sont conservées ici.</p></div>';
  }

  function renderDrafts(box) {
    var d = getDraft();
    if (!d) {
      box.innerHTML = '<div class="empty-state"><h3>Aucun brouillon</h3>' +
        '<p>Commencez une offre et enregistrez-la comme brouillon pour la reprendre plus tard.</p>' +
        '<p><a class="btn btn-accent" href="publier-offre.html">Commencer une offre</a></p></div>';
      return;
    }
    var e = SS.escapeHtml;
    var st = d.state || {};
    box.innerHTML = '<article class="card dash-card offer-card offer-card--draft">' +
        '<div class="offer-card__head"><div>' +
          '<h3 class="offer-card__title">' + e(st.titre || "Offre sans titre") + "</h3>" +
          '<p class="offer-card__meta">Brouillon' + (st.ville ? " · " + e(st.ville) : "") + (st.contrat ? " · " + e(st.contrat) : "") + "</p>" +
        "</div><span class=\"badge badge--moderation\">Brouillon</span></div>" +
        '<div class="row-actions">' +
          '<a class="btn btn-primary btn-sm" href="publier-offre.html">Continuer mon offre</a>' +
          '<button type="button" class="btn btn-ghost btn-sm" data-draft-delete>Supprimer le brouillon</button>' +
        "</div>" +
      "</article>";
    var del = box.querySelector("[data-draft-delete]");
    if (del) {
      del.addEventListener("click", function () {
        SS.store.remove(DRAFT_KEY); SS.toast("Brouillon supprimé."); load();
      });
    }
  }

  function statusBadge(o) {
    if (o.archived) { return '<span class="badge badge--neutral">Archivée</span>'; }
    if (o.statut === "active") {
      if (o.dateExpiration && EMP.daysUntil(o.dateExpiration) <= 7) { return '<span class="badge badge--accent">Expire bientôt</span>'; }
      return '<span class="badge badge--remote">Publiée</span>';
    }
    if (o.statut === "desactivee") { return '<span class="badge badge--neutral">Désactivée</span>'; }
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

  function profilesViewed(id) { return Math.max(1, Math.round(EMP.stableViews(id) / 12)); }
  function interviewsForOffer(id) { return SS.fakeApplicationCount(id) % 3; }

  function cardHtml(o) {
    var e = SS.escapeHtml;
    var renewable = EMP.needsRenewal(o);
    var apps = SS.fakeApplicationCount(o.id);
    var expClass = (o.statut === "active" && o.dateExpiration && EMP.daysUntil(o.dateExpiration) <= 7) ||
                   o.statut === "expiree" ? " offer-card__expiry--warn" : "";

    var actions =
      '<a class="btn btn-outline btn-sm" href="offre-detail.html?id=' + encodeURIComponent(o.id) + '">Voir</a>' +
      '<a class="btn btn-outline btn-sm" href="publier-offre.html?modifier=' + encodeURIComponent(o.id) + '">Modifier</a>' +
      '<a class="btn btn-ghost btn-sm" href="publier-offre.html?dupliquer=' + encodeURIComponent(o.id) + '">Dupliquer</a>' +
      '<a class="btn btn-ghost btn-sm" href="espace-entreprise-candidatures.html">Candidatures</a>';
    if (!o.archived) {
      if (o.statut === "active") {
        actions += '<button type="button" class="btn btn-ghost btn-sm" data-offer-action="disable" data-id="' + e(o.id) + '">Désactiver</button>';
      } else if (o.statut === "desactivee") {
        actions += '<button type="button" class="btn btn-primary btn-sm" data-offer-action="enable" data-id="' + e(o.id) + '">Réactiver</button>';
      }
      if (renewable) { actions += '<a class="btn btn-accent btn-sm" href="paiement.html?offre=' + encodeURIComponent(o.id) + '">Renouveler</a>'; }
      actions += '<button type="button" class="btn btn-danger btn-sm" data-offer-action="archive" data-id="' + e(o.id) + '">Archiver</button>';
    } else {
      actions += '<button type="button" class="btn btn-primary btn-sm" data-offer-action="unarchive" data-id="' + e(o.id) + '">Restaurer</button>';
    }

    return '<article class="card dash-card offer-card">' +
        '<div class="offer-card__head"><div>' +
          '<h3 class="offer-card__title">' + e(o.titre) + "</h3>" +
          '<p class="offer-card__meta">' + e(o.ville) + " — " + e(o.contrat) + " · publiée le " + e(SS.formatDate(o.datePublication)) + "</p></div>" +
          statusBadge(o) +
        "</div>" +
        '<ul class="offer-stats">' +
          "<li><b>" + EMP.stableViews(o.id) + "</b><span>vues</span></li>" +
          "<li><b>" + apps + "</b><span>candidatures</span></li>" +
          "<li><b>" + profilesViewed(o.id) + "</b><span>profils consultés</span></li>" +
          "<li><b>" + interviewsForOffer(o.id) + "</b><span>entretiens</span></li>" +
        "</ul>" +
        '<p class="offer-card__expiry' + expClass + '">' + e(expiryLabel(o)) + "</p>" +
        '<div class="row-actions">' + actions + "</div>" +
      "</article>";
  }

  function wireActions(box) {
    box.querySelectorAll("button[data-offer-action]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var id = btn.getAttribute("data-id");
        var action = btn.getAttribute("data-offer-action");
        var overrides = SS.store.get(APP_CONFIG.storage.offerOverrides, {});
        overrides[id] = overrides[id] || {};

        if (action === "disable") { overrides[id].statut = "desactivee"; SS.toast("Offre désactivée."); }
        else if (action === "enable") {
          overrides[id].statut = "active";
          overrides[id].dateExpiration = EMP.dateFromToday(APP_CONFIG.payment.renewal.durationDays);
          SS.toast("Offre réactivée.");
        } else if (action === "archive") { overrides[id].archived = true; SS.toast("Offre archivée."); }
        else if (action === "unarchive") { overrides[id].archived = false; SS.toast("Offre restaurée."); }
        SS.store.set(APP_CONFIG.storage.offerOverrides, overrides);
        load();
      });
    });
  }
})();

/**
 * Coquille partagée de l'espace recruteur (démonstration).
 *
 * Chargée sur TOUTES les pages recruteur, AVANT le module de page. Elle :
 *   1. redirige les anciennes URL à ancre (§47) vers les pages dédiées ;
 *   2. applique la garde d'accès (rôle « employer ») ;
 *   3. rend la barre latérale (identité personne + navigation groupée) dans
 *      l'aside marqué [data-employer-shell] ;
 *   4. gère le tiroir (drawer) mobile, sans routage par ancre (chaque page
 *      est autonome — contrairement à dash-shell.js réservé au candidat) ;
 *   5. remplace le gros footer marketing par la version compacte ;
 *   6. gère les boutons fictifs [data-toast] pour toute la page ;
 *   7. expose les helpers partagés sous window.EMP (source unique, évite la
 *      duplication entre modules — §45/§46).
 *
 * Clés de stockage local partagées (inchangées) :
 *   ss_offer_overrides — statut/expiration/archivage des offres.
 * (Les autres clés — ss_pipeline_v1, ss_refus_demo, ss_company_profile — sont
 *  gérées par les modules de page concernés.)
 */
(function () {
  "use strict";

  var base = (location.pathname.split("/").pop() || "").toLowerCase();

  /* ============================================================
     1. Redirections de compatibilité (§47)
     Anciennes ancres espace-entreprise.html#<section> → page dédiée.
     Ainsi notifications, menu compte et liens croisés restent valides.
     ============================================================ */
  if (base === "espace-entreprise.html" && location.hash) {
    var LEGACY = {
      "#offres": "espace-entreprise-offres.html",
      "#candidatures": "espace-entreprise-candidatures.html",
      "#entretiens": "espace-entreprise-entretiens.html",
      "#messages": "espace-entreprise-messages.html",
      "#profil": "espace-entreprise-profil.html",
      "#contenus": "espace-entreprise-contenus.html",
      "#facturation": "espace-entreprise-facturation.html",
      "#parametres": "espace-entreprise-parametres.html"
    };
    var legacyTarget = LEGACY[location.hash];
    if (legacyTarget) { location.replace(legacyTarget); return; }
  }

  /* ============================================================
     2. Helpers partagés (window.EMP) — source unique
     Définis au niveau du script (pas dans DOMContentLoaded) afin d'être
     disponibles quand les modules de page s'exécutent.
     ============================================================ */
  function companyInitials(name) {
    var skip = { de: 1, du: 1, des: 1, la: 1, le: 1, les: 1, "et": 1, "&": 1, "d'": 1 };
    var words = String(name || "").trim().split(/[\s'-]+/).filter(function (w) {
      return w && !skip[w.toLowerCase()];
    });
    var letters = words.slice(0, 2).map(function (w) { return w.charAt(0); }).join("");
    return (letters || String(name || "?").charAt(0)).toUpperCase();
  }

  /* Nombre de vues fictif mais stable, dérivé de l'identifiant de l'offre. */
  function stableViews(id) {
    var h = 0;
    for (var i = 0; i < id.length; i++) { h = (h * 33 + id.charCodeAt(i)) % 9973; }
    return (h % 460) + 40; /* ~40 à 500 vues */
  }

  function dateFromToday(offsetDays) {
    var d = new Date();
    d.setDate(d.getDate() + offsetDays);
    return d.toISOString().slice(0, 10);
  }

  function daysUntil(iso) {
    return Math.floor((new Date(iso).getTime() - Date.now()) / 86400000);
  }

  /* Une offre est « à renouveler » si elle est expirée ou proche de l'être. */
  function needsRenewal(o) {
    if (o.statut === "expiree") { return true; }
    if (o.statut !== "active" || !o.dateExpiration) { return false; }
    return daysUntil(o.dateExpiration) <= 7;
  }

  /* Sous-ensemble d'offres de démonstration (déterministe), avec un mélange
     de statuts. Les modifications de l'utilisateur (ss_offer_overrides)
     restent prioritaires. */
  function buildDemoOffers(all) {
    var overrides = SS.store.get(APP_CONFIG.storage.offerOverrides, {});
    var pick = all.slice().sort(function (a, b) {
      return a.id < b.id ? -1 : (a.id > b.id ? 1 : 0);
    }).slice(0, 4);

    var expOffsets = [-6, 3, 22, 45];   /* jours avant/après aujourd'hui */
    var pubOffsets = [-52, -34, -21, -9];

    return pick.map(function (o, i) {
      if (overrides[o.id]) { return o; } /* l'utilisateur a déjà agi */
      var copy = Object.assign({}, o);
      copy.dateExpiration = dateFromToday(expOffsets[i]);
      copy.datePublication = dateFromToday(pubOffsets[i]);
      copy.statut = expOffsets[i] < 0 ? "expiree" : "active";
      return copy;
    });
  }

  /* Offres de l'entreprise connectée (avec repli sur des offres de démo). */
  function getCompanyOffers() {
    var s = SS.auth.get() || {};
    var companyId = s.companyId || APP_CONFIG.demoCompany.id;
    return SS.getOffers().then(function (offers) {
      var mine = offers.filter(function (o) { return o.entrepriseId === companyId; });
      var list = mine.length ? mine : buildDemoOffers(offers);
      return list.filter(function (o) { return !o.archived; });
    });
  }

  window.EMP = {
    ready: false,
    companyInitials: companyInitials,
    stableViews: stableViews,
    dateFromToday: dateFromToday,
    daysUntil: daysUntil,
    needsRenewal: needsRenewal,
    buildDemoOffers: buildDemoOffers,
    getCompanyOffers: getCompanyOffers
  };

  /* ============================================================
     Données de navigation de la barre latérale (§30)
     ============================================================ */
  var NAV = [
    { type: "link", label: "Tableau de bord", href: "espace-entreprise.html" },
    { type: "group", label: "Recrutement", items: [
      ["Mes offres", "espace-entreprise-offres.html"],
      ["Candidatures", "espace-entreprise-candidatures.html"],
      ["Entretiens", "espace-entreprise-entretiens.html"],
      ["Messages", "espace-entreprise-messages.html"]
    ] },
    { type: "group", label: "Entreprise", items: [
      ["Profil entreprise", "espace-entreprise-profil.html"],
      ["Contenus", "espace-entreprise-contenus.html"]
    ] },
    { type: "group", label: "Gestion", items: [
      ["Facturation", "espace-entreprise-facturation.html"],
      ["Paramètres", "espace-entreprise-parametres.html"]
    ] }
  ];

  /* ============================================================
     3-6. Rendu au chargement du DOM
     ============================================================ */
  document.addEventListener("DOMContentLoaded", function () {
    if (!window.SS || !SS.auth) { return; }
    /* Garde : visiteur → connexion ; candidat → son espace. */
    if (!SS.auth.require("employer")) { return; }
    window.EMP.ready = true;

    var layout = document.querySelector(".dash-layout");
    if (!layout) { return; }
    var main = layout.querySelector(".dash-main");
    var sidebar = layout.querySelector(".dash-sidebar[data-employer-shell]") ||
                  layout.querySelector(".dash-sidebar");
    if (!main || !sidebar) { return; }
    if (!sidebar.id) { sidebar.id = "dash-sidebar"; }

    renderSidebar(sidebar);
    compactFooter();
    initDrawer(layout, main, sidebar);
    initToasts();
  });

  /* ---- Barre latérale : identité (personne) + navigation groupée ---- */
  function renderSidebar(sidebar) {
    var e = SS.escapeHtml;
    var s = SS.auth.get() || {};
    var company = s.company || APP_CONFIG.demoCompany.nom;
    var name = SS.auth.displayName() || company;
    var initials = SS.auth.initials();

    var navHtml = NAV.map(function (entry) {
      if (entry.type === "link") {
        return navLink(entry.label, entry.href, e);
      }
      var items = entry.items.map(function (it) {
        return navLink(it[0], it[1], e);
      }).join("");
      return '<p class="dash-nav__group">' + e(entry.label) + "</p>" + items;
    }).join("");

    sidebar.innerHTML =
      '<div class="dash-sidebar__id">' +
        '<span class="avatar" aria-hidden="true">' + e(initials) + "</span>" +
        "<span>" +
          "<strong>" + e(name) + "</strong>" +
          "<span>" + e(company) + "</span>" +
        "</span>" +
      "</div>" +
      '<nav class="dash-nav" aria-label="Sections de l\'espace entreprise">' + navHtml + "</nav>";
  }

  /* Un lien de navigation ; marqué actif si son fichier == page courante. */
  function navLink(label, href, e) {
    var active = href.toLowerCase() === base;
    return '<a href="' + href + '"' +
      (active ? ' class="is-active" aria-current="page"' : "") +
      ">" + e(label) + "</a>";
  }

  /* ---- Footer compact des espaces connectés (repris de dash-shell.js) ---- */
  function compactFooter() {
    var footer = document.querySelector(".site-footer");
    if (!footer) { return; }
    footer.classList.add("site-footer--app");
    footer.innerHTML =
      '<div class="container app-footer">' +
        '<span class="app-footer__brand">© <span data-year>2026</span> Postelio</span>' +
        '<nav class="app-footer__links" aria-label="Liens utiles">' +
          '<a href="contact.html">Aide</a>' +
          '<a href="contact.html">Contact</a>' +
          '<a href="confidentialite.html">Confidentialité</a>' +
          '<a href="mentions-legales.html">Mentions légales</a>' +
        "</nav>" +
      "</div>";
    var y = footer.querySelector("[data-year]");
    if (y) { y.textContent = String(new Date().getFullYear()); }
  }

  /* ---- Tiroir mobile (repris de dash-shell.js, SANS routage par ancre) ---- */
  function initDrawer(layout, main, sidebar) {
    var drawerBtn = document.createElement("button");
    drawerBtn.type = "button";
    drawerBtn.className = "dash-drawer-toggle";
    drawerBtn.setAttribute("aria-expanded", "false");
    drawerBtn.setAttribute("aria-controls", sidebar.id || "dash-sidebar");
    drawerBtn.innerHTML = '<span class="dash-drawer-toggle__label">Menu de mon espace</span>' +
      '<span class="dash-drawer-toggle__caret" aria-hidden="true">▾</span>';
    main.insertBefore(drawerBtn, main.firstChild);

    function openDrawer(o) {
      layout.classList.toggle("dash-drawer-open", o);
      drawerBtn.setAttribute("aria-expanded", o ? "true" : "false");
    }
    drawerBtn.addEventListener("click", function () {
      openDrawer(!layout.classList.contains("dash-drawer-open"));
    });

    /* Fermer le tiroir en cliquant hors de la barre latérale (mobile). */
    document.addEventListener("click", function (ev) {
      if (!layout.classList.contains("dash-drawer-open")) { return; }
      if (sidebar.contains(ev.target) || drawerBtn.contains(ev.target)) { return; }
      openDrawer(false);
    });
  }

  /* ---- Boutons fictifs (data-toast) : profil, facturation, contenus… ---- */
  function initToasts() {
    document.addEventListener("click", function (ev) {
      var btn = ev.target.closest ? ev.target.closest("[data-toast]") : null;
      if (btn) { SS.toast(btn.getAttribute("data-toast")); }
    });
  }
})();

/**
 * Annuaire des entreprises — données RÉELLES (I2), via PostelioDirectory (GET /companies,
 * GET /companies/{uuid}). Aucune donnée JSON locale, aucun repli.
 *
 * Limites V1 assumées (cf. docs) :
 *  - le backend n'expose PAS de recherche d'entreprises : la liste réelle est chargée puis
 *    filtrée CÔTÉ CLIENT (nom/secteur/ville) sur l'ensemble récupéré (borné) ;
 *  - il n'existe pas d'endpoint « offres d'une entreprise » : les compteurs d'offres et la
 *    liste d'offres par entreprise sont OMIS (renvoi vers la page Offres). Gap documenté.
 */
(function () {
  "use strict";

  function svgIcon(paths) {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" ' +
      'stroke-linecap="round" stroke-linejoin="round">' + paths + "</svg>";
  }
  var ICONS = {
    pin: svgIcon('<path d="M12 21s-6-5.2-6-10a6 6 0 0 1 12 0c0 4.8-6 10-6 10z"/><circle cx="12" cy="11" r="2.2"/>'),
    users: svgIcon('<path d="M17 20v-1.7a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4V20"/><circle cx="9.5" cy="7.5" r="3.3"/><path d="M22 20v-1.7a4 4 0 0 0-3-3.85"/><path d="M16 4.15a4 4 0 0 1 0 7.7"/>'),
    briefcase: svgIcon('<rect x="2.5" y="7" width="19" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M2.5 12.5h19"/>'),
    phone: svgIcon('<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2 4.2 2 2 0 0 1 4 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.2-1.2a2 2 0 0 1 2.1-.5c.9.3 1.9.6 2.8.7A2 2 0 0 1 22 16.9z"/>'),
    mail: svgIcon('<rect x="2.5" y="4.5" width="19" height="15" rx="2"/><path d="M3 6.5l9 6 9-6"/>'),
    globe: svgIcon('<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3c2.5 2.5 3.8 5.7 3.8 9s-1.3 6.5-3.8 9c-2.5-2.5-3.8-5.7-3.8-9S9.5 5.5 12 3z"/>'),
    building: svgIcon('<rect x="4.5" y="3" width="15" height="18" rx="1.5"/><path d="M9 7h.01M15 7h.01M9 11h.01M15 11h.01M9 15h.01M15 15h.01"/><path d="M10 21v-3.5h4V21"/>'),
    check: svgIcon('<circle cx="12" cy="12" r="9"/><path d="M8 12.3l2.7 2.7L16 9.5"/>')
  };
  function iconEl(name, cls) {
    return '<span class="icon' + (cls ? " " + cls : "") + '" aria-hidden="true">' + (ICONS[name] || "") + "</span>";
  }

  document.addEventListener("DOMContentLoaded", function () {
    renderFeatured();
    initDirectory();
    renderCompanyDetail();
  });

  /* Carte d'annuaire — champs manquants (données réelles parfois partielles) omis. */
  function companyCard(company) {
    var e = SS.escapeHtml;
    var metaParts = [];
    if (company.secteur) { metaParts.push(e(company.secteur)); }
    if (company.ville) { metaParts.push(e(company.ville)); }
    var distinct = (company.avantages && company.avantages[0]) || (company.valeurs && company.valeurs[0]) || "";
    return '<article class="card company-card">' +
      '<div class="company-card__head">' +
        '<span class="logo-bubble" style="background:' + e(company.couleur) + '" aria-hidden="true">' + e(company.initiales) + "</span>" +
        "<div>" +
          "<h3>" + e(company.nom) + (company.verifie ? ' <span class="verified-tick" title="' + e(company.verifieLabel || "Entreprise vérifiée") + '" aria-label="' + e(company.verifieLabel || "Entreprise vérifiée") + '">✓</span>' : "") + "</h3>" +
          (metaParts.length ? '<p class="company-card__meta">' + metaParts.join(" · ") + "</p>" : "") +
        "</div>" +
        '<span class="hiring-flag hiring-flag--off">Annuaire</span>' +
      "</div>" +
      (company.activite ? '<p class="company-card__activity">' + e(company.activite) + "</p>" : "") +
      (distinct ? '<p class="company-card__distinct">' + e(distinct) + "</p>" : "") +
      '<div class="company-card__foot">' +
        '<span class="text-muted">' + e(company.taille) + "</span>" +
        '<a class="link-more" href="entreprise-detail.html?id=' + encodeURIComponent(company.id) + '">Voir la fiche</a>' +
      "</div>" +
    "</article>";
  }

  /* Charge TOUTES les entreprises publiques (borné) pour l'annuaire — pas de recherche serveur. */
  var SAFETY_PAGES = 10;
  function loadAllCompanies() {
    var all = [];
    function page(p) {
      return window.PostelioDirectory.companies.list(p, 24).then(function (res) {
        all = all.concat(res.items);
        if (p < res.totalPages && p < SAFETY_PAGES) { return page(p + 1); }
        return all;
      });
    }
    return page(1);
  }

  /* ---- Accueil : entreprises mises en avant ---- */
  function renderFeatured() {
    var container = document.getElementById("featured-companies");
    if (!container) { return; }
    window.PostelioDirectory.companies.list(1, 4).then(function (res) {
      if (!res.items.length) { container.innerHTML = '<div class="empty-state"><p>Aucune entreprise pour le moment.</p></div>'; return; }
      container.innerHTML = res.items.map(companyCard).join("");
    }, function () { SS.dataError(container); });
  }

  /* ---- Annuaire ---- */
  function initDirectory() {
    var grid = document.getElementById("companies-grid");
    if (!grid) { return; }
    grid.setAttribute("aria-busy", "true");

    loadAllCompanies().then(function (companies) {
      grid.removeAttribute("aria-busy");
      var sectorSelect = document.getElementById("directory-sector");
      var form = document.getElementById("directory-form");

      if (sectorSelect) {
        var sectors = [];
        companies.forEach(function (c) { if (c.secteur && sectors.indexOf(c.secteur) === -1) { sectors.push(c.secteur); } });
        sectors.sort().forEach(function (s) {
          var option = document.createElement("option");
          option.value = s; option.textContent = s;
          sectorSelect.appendChild(option);
        });
      }

      function apply() {
        var name = normalize(val("directory-name"));
        var sector = sectorSelect ? sectorSelect.value : "";
        var city = normalize(val("directory-city"));

        var filtered = companies.filter(function (c) {
          if (name && normalize(c.nom + " " + c.activite).indexOf(name) === -1) { return false; }
          if (sector && c.secteur !== sector) { return false; }
          if (city && normalize(c.ville + " " + c.departement).indexOf(city) === -1) { return false; }
          return true;
        });

        var countEl = document.getElementById("directory-count");
        if (countEl) {
          countEl.textContent = filtered.length === 0 ? "Aucune entreprise trouvée"
            : filtered.length + (filtered.length > 1 ? " entreprises référencées" : " entreprise référencée");
        }
        grid.innerHTML = filtered.length
          ? filtered.map(companyCard).join("")
          : '<div class="empty-state"><h3>Aucun résultat</h3><p>Modifiez votre recherche ou effacez les filtres.</p></div>';
      }

      if (form) {
        form.addEventListener("submit", function (e) { e.preventDefault(); apply(); });
        form.querySelectorAll("input, select").forEach(function (input) {
          input.addEventListener("change", apply);
          input.addEventListener("input", apply);
        });
      }
      apply();
    }, function () { grid.removeAttribute("aria-busy"); SS.dataError(grid); });
  }

  /* ---- Fiche entreprise ---- */
  function renderCompanyDetail() {
    var root = document.getElementById("company-detail");
    if (!root) { return; }
    var id = SS.param("id");
    if (!id) { showCompanyMessage(root, "Entreprise introuvable", "Cette entreprise n'existe pas ou n'est plus référencée."); return; }

    window.PostelioDirectory.companies.get(id).then(function (company) {
      fillCompany(company);
    }, function (err) {
      if (err && err.status === 404) { showCompanyMessage(root, "Entreprise introuvable", "Cette entreprise n'existe pas ou n'est plus référencée."); }
      else {
        var box = root.querySelector(".container") || root;
        box.innerHTML = '<div class="empty-state" role="alert" style="margin:2rem auto;max-width:640px;text-align:center"><h1>Impossible de charger l\'entreprise</h1><p>' +
          SS.escapeHtml(err && err.userMessage ? err.userMessage() : "Réessayez plus tard.") + '</p><p><a class="btn btn-primary" href="entreprises.html">Retour à l\'annuaire</a></p></div>';
      }
    });
  }

  function showCompanyMessage(root, title, text) {
    var box = root.querySelector(".container") || root;
    box.innerHTML = '<div class="empty-state" style="margin:2rem auto;max-width:640px;text-align:center"><h1>' +
      SS.escapeHtml(title) + "</h1><p>" + SS.escapeHtml(text) + '</p><p><a class="btn btn-primary" href="entreprises.html">Retour à l\'annuaire</a></p></div>';
  }

  function fillCompany(company) {
    var e = SS.escapeHtml;
    document.title = company.nom + " – recrutement | Postelio";
    setText("company-name", company.nom);
    setText("company-activity", company.activite);
    setText("company-description", company.description);

    var bubble = document.getElementById("company-bubble");
    if (bubble) { bubble.style.background = company.couleur; bubble.textContent = company.initiales; }

    var heroBadges = document.getElementById("company-hero-badges");
    if (heroBadges) {
      heroBadges.innerHTML =
        (company.secteur ? '<span class="badge badge--accent">' + e(company.secteur) + "</span>" : "") +
        (company.verifie ? '<span class="badge badge--verified" title="' + e(company.verifieLabel) + '">✓ ' + e(company.verifieLabel) + "</span>" : "");
    }

    var meta = document.getElementById("company-hero-meta");
    if (meta) {
      var rows = "";
      if (company.ville) { rows += "<li>" + iconEl("pin") + e(company.ville) + (company.departement ? " · " + e(company.departement) : "") + "</li>"; }
      if (company.taille) { rows += "<li>" + iconEl("users") + e(company.taille) + "</li>"; }
      meta.innerHTML = rows;
    }

    /* Coordonnées + identité légale publique (SIREN public une fois vérifié). */
    var coords = document.getElementById("company-coordinates");
    if (coords) {
      var l = company.legal || {};
      coords.innerHTML =
        row("pin", "Adresse", e(company.adresse)) +
        row("pin", "Ville", company.ville ? (e(company.ville) + (company.departement ? " — " + e(company.departement) : "")) : "") +
        row("phone", "Téléphone", e(company.telephone)) +
        row("mail", "E-mail", e(company.email)) +
        row("globe", "Site internet", company.siteWeb ? ('<a href="' + e(company.siteWeb) + '" rel="nofollow noopener">' + e(company.siteWeb.replace(/^https?:\/\//, "")) + "</a>") : "") +
        row("building", "Secteur", e(company.secteur)) +
        row("building", "Raison sociale", e(l.raison_sociale)) +
        row("building", "SIREN", e(l.siren)) +
        row("users", "Effectif", e(company.taille));
      if (!coords.innerHTML) { coords.innerHTML = '<li class="text-muted">Coordonnées non communiquées.</li>'; }
    }

    renderCards("company-values", company.valeurs, "value-card", true);
    renderCards("company-benefits", company.avantages, "perk-card", false);

    var contactHref = "contact.html?entreprise=" + encodeURIComponent(company.nom);
    ["company-contact-btn", "cta-contact-btn"].forEach(function (bid) {
      var b = document.getElementById(bid); if (b) { b.href = contactHref; }
    });

    setupFollow(company);

    /* Offres de l'entreprise : pas d'endpoint public dédié (gap V1) → renvoi vers la page Offres. */
    var offersBox = document.getElementById("company-offers");
    if (offersBox) {
      offersBox.innerHTML =
        '<div class="empty-state"><h3>Retrouvez ses offres sur Postelio</h3>' +
        "<p>Consultez toutes les offres publiées sur la plateforme et filtrez par mot-clé ou par ville.</p>" +
        '<p><a class="btn btn-outline btn-sm" href="offres.html?q=' + encodeURIComponent(company.nom) + '">Voir les offres</a></p></div>';
    }
    var ctaOffers = document.getElementById("cta-offers-btn");
    if (ctaOffers) { ctaOffers.textContent = "Voir toutes les offres"; ctaOffers.href = "offres.html"; }
  }

  function setupFollow(company) {
    var followBtn = document.getElementById("company-follow-btn");
    if (!followBtn || !(window.SS && SS.auth && SS.auth.isCandidate && SS.auth.isCandidate())) { return; }
    var KEY = "ss_candidate_followed"; // conservé en localStorage (branchement réel prévu en lot ultérieur)
    followBtn.hidden = false;
    var sync = function () {
      var list = SS.store.get(KEY, []) || [];
      var on = list.indexOf(company.id) !== -1;
      followBtn.textContent = on ? "✓ Entreprise suivie" : "Suivre cette entreprise";
      followBtn.setAttribute("aria-pressed", on ? "true" : "false");
      followBtn.classList.toggle("is-following", on);
    };
    sync();
    followBtn.addEventListener("click", function () {
      var list = SS.store.get(KEY, []) || [];
      var i = list.indexOf(company.id);
      if (i === -1) { list.push(company.id); SS.toast("Vous suivez désormais " + company.nom + "."); }
      else { list.splice(i, 1); SS.toast("Vous ne suivez plus " + company.nom + "."); }
      SS.store.set(KEY, list);
      sync();
    });
  }

  /* ---- Helpers ---- */
  function val(id) { var el = document.getElementById(id); return el ? el.value : ""; }
  function setText(id, value) { var el = document.getElementById(id); if (el) { el.textContent = value || "—"; } }
  function row(iconName, label, valueHtml) {
    if (!valueHtml) { return ""; }
    return '<li class="company-coords__row">' +
      '<span class="company-coords__icon" aria-hidden="true"><span class="icon">' + (ICONS[iconName] || "") + "</span></span>" +
      '<span class="company-coords__body"><span class="company-coords__label">' + label + "</span>" +
      '<span class="company-coords__value">' + valueHtml + "</span></span></li>";
  }
  function renderCards(id, items, cls, isValue) {
    var el = document.getElementById(id);
    if (!el) { return; }
    if (!items || !items.length) { el.innerHTML = ""; return; }
    var e = SS.escapeHtml;
    el.innerHTML = items.map(function (v) {
      return isValue
        ? '<div class="value-card"><span class="value-card__check" aria-hidden="true">✓</span><p>' + e(v) + "</p></div>"
        : '<div class="perk-card"><span class="perk-card__icon icon" aria-hidden="true">' + (ICONS.check || "") + "</span><span>" + e(v) + "</span></div>";
    }).join("");
  }
  function normalize(text) {
    return (text || "").toString().toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "");
  }
})();

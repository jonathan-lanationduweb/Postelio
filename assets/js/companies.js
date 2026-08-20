/**
 * Annuaire des entreprises : sélection mise en avant (accueil),
 * annuaire avec recherche et filtres, fiche entreprise détaillée.
 */
(function () {
  "use strict";

  /* Famille d'icônes SVG « line » (trait fin, currentColor) — voir la classe
     .icon dans components.css. Un même concept réutilise toujours le même tracé. */
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
    home: svgIcon('<path d="M4 11.5 12 4l8 7.5"/><path d="M6 10v10h12V10"/><path d="M10 20v-6h4v6"/>'),
    heart: svgIcon('<path d="M12 20s-7-4.6-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.4-7 10-7 10z"/>'),
    cap: svgIcon('<path d="M2.5 8.5 12 4.5l9.5 4-9.5 4-9.5-4z"/><path d="M6.5 10.3V15c0 1.2 2.5 2.3 5.5 2.3s5.5-1.1 5.5-2.3v-4.7"/><path d="M21.5 8.5v5"/>'),
    transport: svgIcon('<rect x="6" y="3.5" width="12" height="13" rx="2.5"/><path d="M6 11h12"/><path d="M9.5 16.5 7.5 20M14.5 16.5 16.5 20"/><path d="M9 13.7h.01M15 13.7h.01"/>'),
    calendar: svgIcon('<rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 9.5h17"/><path d="M8 3v4M16 3v4"/>'),
    euro: svgIcon('<circle cx="12" cy="12" r="9"/><path d="M15.5 8.8a4.5 4.5 0 1 0 0 6.4"/><path d="M7 11h6M7 13.4h5"/>'),
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

  /* Fiche d'annuaire : monogramme à gauche, informations à droite,
     un trait distinctif de l'entreprise, statut de recrutement. */
  function companyCard(company, offerCount) {
    var e = SS.escapeHtml;
    var distinct = (company.avantages && company.avantages[0]) || company.valeurs && company.valeurs[0] || "";
    return '<article class="card company-card' + (offerCount ? " company-card--hiring" : "") + '">' +
      '<div class="company-card__head">' +
        '<span class="logo-bubble" style="background:' + e(company.couleur) + '" aria-hidden="true">' + e(company.initiales) + "</span>" +
        "<div>" +
          "<h3>" + e(company.nom) + (company.verifie ? ' <span class="verified-tick" title="' + e(company.verifieLabel || "Entreprise vérifiée") + '" aria-label="' + e(company.verifieLabel || "Entreprise vérifiée") + '">✓</span>' : "") + "</h3>" +
          '<p class="company-card__meta">' + e(company.secteur) + " · " + e(company.ville) + "</p>" +
        "</div>" +
        (offerCount
          ? '<span class="hiring-flag">Recrute · ' + offerCount + (offerCount > 1 ? " offres" : " offre") + "</span>"
          : '<span class="hiring-flag hiring-flag--off">Annuaire</span>') +
      "</div>" +
      '<p class="company-card__activity">' + e(company.activite) + "</p>" +
      (distinct ? '<p class="company-card__distinct">' + e(distinct) + "</p>" : "") +
      '<div class="company-card__foot">' +
        '<span class="text-muted">' + e(company.taille) + "</span>" +
        '<a class="link-more" href="entreprise-detail.html?id=' + encodeURIComponent(company.id) + '">Voir la fiche</a>' +
      "</div>" +
    "</article>";
  }

  function withOfferCounts(callback) {
    return Promise.all([SS.getCompanies(), SS.getActiveOffers()])
      .then(function (results) {
        var counts = {};
        results[1].forEach(function (o) {
          counts[o.entrepriseId] = (counts[o.entrepriseId] || 0) + 1;
        });
        return callback(results[0], counts, results[1]);
      });
  }

  /* ---- Accueil : entreprises mises en avant ---- */
  function renderFeatured() {
    var container = document.getElementById("featured-companies");
    if (!container) { return; }
    withOfferCounts(function (companies, counts) {
      var featured = companies.slice()
        .sort(function (a, b) { return (counts[b.id] || 0) - (counts[a.id] || 0); })
        .slice(0, 4);
      container.innerHTML = featured.map(function (c) {
        return companyCard(c, counts[c.id] || 0);
      }).join("");
    }).catch(function () { SS.dataError(container); });
  }

  /* ---- Annuaire ---- */
  function initDirectory() {
    var grid = document.getElementById("companies-grid");
    if (!grid) { return; }

    withOfferCounts(function (companies, counts) {
      var form = document.getElementById("directory-form");
      var sectorSelect = document.getElementById("directory-sector");

      /* Alimente la liste des secteurs à partir des données. */
      var sectors = [];
      companies.forEach(function (c) {
        if (sectors.indexOf(c.secteur) === -1) { sectors.push(c.secteur); }
      });
      sectors.sort().forEach(function (s) {
        var option = document.createElement("option");
        option.value = s;
        option.textContent = s;
        sectorSelect.appendChild(option);
      });

      function apply() {
        var name = normalize(document.getElementById("directory-name").value);
        var sector = sectorSelect.value;
        var city = normalize(document.getElementById("directory-city").value);

        var filtered = companies.filter(function (c) {
          if (name && normalize(c.nom + " " + c.activite).indexOf(name) === -1) { return false; }
          if (sector && c.secteur !== sector) { return false; }
          if (city && normalize(c.ville + " " + c.departement).indexOf(city) === -1) { return false; }
          return true;
        });

        var countEl = document.getElementById("directory-count");
        countEl.textContent = filtered.length === 0 ? "Aucune entreprise trouvée"
          : filtered.length + (filtered.length > 1 ? " entreprises référencées" : " entreprise référencée");

        grid.innerHTML = filtered.length
          ? filtered.map(function (c) { return companyCard(c, counts[c.id] || 0); }).join("")
          : '<div class="empty-state"><h3>Aucun résultat</h3><p>Modifiez votre recherche ou effacez les filtres.</p></div>';
      }

      form.addEventListener("submit", function (event) {
        event.preventDefault();
        apply();
      });
      form.querySelectorAll("input, select").forEach(function (input) {
        input.addEventListener("change", apply);
        input.addEventListener("input", apply);
      });

      apply();
    }).catch(function () { SS.dataError(grid); });
  }

  /* ---- Fiche entreprise ---- */
  function renderCompanyDetail() {
    var root = document.getElementById("company-detail");
    if (!root) { return; }
    var id = SS.param("id");

    withOfferCounts(function (companies, counts, offers) {
      var company = companies.find(function (c) { return c.id === id; }) || companies[0];
      var e = SS.escapeHtml;
      var offerCount = counts[company.id] || 0;

      document.title = company.nom + " – recrutement | Postelio";

      document.getElementById("company-name").textContent = company.nom;
      document.getElementById("company-activity").textContent = company.activite;
      document.getElementById("company-description").textContent = company.description;

      var bubble = document.getElementById("company-bubble");
      bubble.style.background = company.couleur;
      bubble.textContent = company.initiales;

      /* Badges du hero : secteur + statut de recrutement. */
      var heroBadges = document.getElementById("company-hero-badges");
      if (heroBadges) {
        heroBadges.innerHTML =
          '<span class="badge badge--accent">' + e(company.secteur) + "</span>" +
          (offerCount
            ? '<span class="badge badge--remote">Recrute actuellement</span>'
            : "") +
          (company.verifie
            ? '<span class="badge badge--verified" title="' + e(company.verifieLabel || "Entreprise vérifiée") + '">✓ ' + e(company.verifieLabel || "Entreprise vérifiée") + "</span>"
            : "");
      }

      /* Ligne de méta : ville, effectif, offres. */
      var meta = document.getElementById("company-hero-meta");
      if (meta) {
        var offerLabel = offerCount
          ? offerCount + (offerCount > 1 ? " offres en ligne" : " offre en ligne")
          : "Aucune offre en ce moment";
        meta.innerHTML =
          "<li>" + iconEl("pin") + e(company.ville) + " · " + e(company.departement) + "</li>" +
          "<li>" + iconEl("users") + e(company.taille) + "</li>" +
          "<li>" + iconEl("briefcase") + e(offerLabel) + "</li>";
      }

      /* Chiffres clés. */
      var stats = document.getElementById("company-stats");
      if (stats) {
        stats.innerHTML = [
          statTile(company.taille.replace(/\s*salariés?/i, ""), "salariés"),
          statTile(String(offerCount), offerCount > 1 ? "offres actives" : "offre active"),
          statTile(company.ville, "en " + company.departement.replace(/\s*\(.*\)/, "")),
          statTile(company.secteur, "secteur d'activité")
        ].join("");
      }

      /* Coordonnées : carte à icônes, plus aérée. */
      var coords = document.getElementById("company-coordinates");
      if (coords) {
        coords.innerHTML =
          coordRow("pin", "Adresse", e(company.adresse)) +
          coordRow("pin", "Ville", e(company.ville) + " — " + e(company.departement)) +
          coordRow("phone", "Téléphone", e(company.telephone)) +
          coordRow("mail", "E-mail", e(company.email)) +
          coordRow("globe", "Site internet",
            '<a href="' + e(company.siteWeb) + '" rel="nofollow">' + e(company.siteWeb.replace("https://", "")) + "</a>") +
          coordRow("building", "Secteur", e(company.secteur)) +
          coordRow("users", "Effectif", e(company.taille));
      }

      /* Valeurs → cartes ; avantages → cartes à icône. */
      renderValueCards("company-values", company.valeurs);
      renderPerkCards("company-benefits", company.avantages);

      var contactHref = "contact.html?entreprise=" + encodeURIComponent(company.nom);
      ["company-contact-btn", "cta-contact-btn"].forEach(function (bid) {
        var b = document.getElementById(bid);
        if (b) { b.href = contactHref; }
      });

      /* Suivre l'entreprise (candidats connectés uniquement, §23). */
      var followBtn = document.getElementById("company-follow-btn");
      if (followBtn && window.SS && SS.auth && SS.auth.isCandidate && SS.auth.isCandidate()) {
        var FOLLOWED_KEY = "ss_candidate_followed";
        followBtn.hidden = false;
        var syncFollow = function () {
          var list = SS.store.get(FOLLOWED_KEY, []) || [];
          var on = list.indexOf(company.id) !== -1;
          followBtn.textContent = on ? "✓ Entreprise suivie" : "Suivre cette entreprise";
          followBtn.setAttribute("aria-pressed", on ? "true" : "false");
          followBtn.classList.toggle("is-following", on);
        };
        syncFollow();
        followBtn.addEventListener("click", function () {
          var list = SS.store.get(FOLLOWED_KEY, []) || [];
          var i = list.indexOf(company.id);
          if (i === -1) { list.push(company.id); SS.toast("Vous suivez désormais " + company.nom + "."); }
          else { list.splice(i, 1); SS.toast("Vous ne suivez plus " + company.nom + "."); }
          SS.store.set(FOLLOWED_KEY, list);
          syncFollow();
        });
      }

      /* Offres actuellement disponibles — même composant que la page Offres. */
      var offersBox = document.getElementById("company-offers");
      var companyOffers = offers.filter(function (o) { return o.entrepriseId === company.id; });
      companyOffers.forEach(function (o) { o.couleur = company.couleur; });
      offersBox.innerHTML = companyOffers.length
        ? companyOffers.map(SS.offerCard).join("")
        : '<div class="empty-state"><h3>Pas d\'offre en ce moment</h3>' +
          "<p>Cette entreprise n'a pas d'offre active aujourd'hui. Revenez bientôt ou " +
          '<a href="offres.html">consultez les autres offres</a>.</p></div>';

      /* Sans offre : le CTA « Postuler » invite plutôt à contacter. */
      if (!companyOffers.length) {
        var ctaOffers = document.getElementById("cta-offers-btn");
        if (ctaOffers) {
          ctaOffers.textContent = "Voir toutes les offres";
          ctaOffers.href = "offres.html";
        }
      }

      /* « Ses conseils et savoir-faire » : publications de l'entreprise. */
      renderCompanyKnowhow(company);
    }).catch(function () { SS.dataError(root.querySelector(".container") || root); });
  }

  function statTile(value, label) {
    var e = SS.escapeHtml;
    return '<div class="stat-tile"><strong>' + e(value) + "</strong><span>" + e(label) + "</span></div>";
  }

  function coordRow(iconName, label, valueHtml) {
    return '<li class="company-coords__row">' +
      '<span class="company-coords__icon" aria-hidden="true"><span class="icon">' + (ICONS[iconName] || "") + "</span></span>" +
      '<span class="company-coords__body"><span class="company-coords__label">' + label + "</span>" +
      '<span class="company-coords__value">' + valueHtml + "</span></span></li>";
  }

  function renderValueCards(id, values) {
    var el = document.getElementById(id);
    if (!el || !values) { return; }
    var e = SS.escapeHtml;
    el.innerHTML = values.map(function (v) {
      return '<div class="value-card"><span class="value-card__check" aria-hidden="true">✓</span>' +
        "<p>" + e(v) + "</p></div>";
    }).join("");
  }

  /* Icône déduite du libellé de l'avantage (sobre, sans surcharge). */
  function perkIcon(text) {
    var t = (text || "").toLowerCase();
    if (/t[ée]l[ée]travail|distance|remote/.test(t)) { return "home"; }
    if (/mutuelle|sant[ée]|pr[ée]voyance/.test(t)) { return "heart"; }
    if (/formation|mont[ée]e|comp[ée]tence/.test(t)) { return "cap"; }
    if (/parking|v[ée]lo|transport|m[ée]tro|acc[èe]s/.test(t)) { return "transport"; }
    if (/horaire|planning|temps|flex/.test(t)) { return "calendar"; }
    if (/13|prime|salaire|r[ée]mun[ée]ration|ticket|repas/.test(t)) { return "euro"; }
    if (/locaux|bureau|espace|cadre/.test(t)) { return "building"; }
    if (/[ée]quipe|ambiance|convivial/.test(t)) { return "users"; }
    return "check";
  }

  function renderPerkCards(id, perks) {
    var el = document.getElementById(id);
    if (!el || !perks) { return; }
    var e = SS.escapeHtml;
    el.innerHTML = perks.map(function (p) {
      return '<div class="perk-card"><span class="perk-card__icon icon" aria-hidden="true">' +
        (ICONS[perkIcon(p)] || "") + "</span><span>" + e(p) + "</span></div>";
    }).join("");
  }

  function renderCompanyKnowhow(company) {
    var section = document.getElementById("company-knowhow-section");
    var box = document.getElementById("company-knowhow");
    if (!section || !box || typeof SS.getKnowhow !== "function") { return; }
    SS.getKnowhow().then(function (items) {
      var mine = items.filter(function (p) {
        return p.auteur && p.auteur.entrepriseId === company.id;
      });
      if (!mine.length) { return; }
      section.hidden = false;
      box.innerHTML = mine.map(SS.knowhowCard).join("");
    }).catch(function () { /* section simplement absente en cas d'erreur */ });
  }

  function fillList(id, items) {
    var el = document.getElementById(id);
    if (el && items) {
      el.innerHTML = items.map(function (item) {
        return "<li>" + SS.escapeHtml(item) + "</li>";
      }).join("");
    }
  }

  function normalize(text) {
    return (text || "").toString().toLowerCase()
      .normalize("NFD").replace(/[\u0300-\u036f]/g, "");
  }
})();


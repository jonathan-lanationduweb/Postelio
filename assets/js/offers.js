/**
 * Offres d'emploi : gabarit de carte partagé, offres récentes (accueil),
 * fiche détaillée, candidature, partage et offres similaires.
 */
(function () {
  "use strict";

  var SAVE_KEY = "ss_offres_enregistrees";
  var BOOKMARK_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
    'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
    '<path d="M6 4h12v17l-6-4.5L6 21z"/></svg>';

  function isSaved(id) {
    return SS.store.get(SAVE_KEY, []).indexOf(id) !== -1;
  }

  function saveButton(offer) {
    var saved = isSaved(offer.id);
    return '<button type="button" class="save-btn" data-save-offer="' + SS.escapeHtml(offer.id) + '"' +
      ' aria-pressed="' + saved + '" aria-label="Enregistrer l\'offre ' + SS.escapeHtml(offer.titre) + '">' +
      BOOKMARK_SVG + "</button>";
  }

  /* Rangée d'offre — liste à filets, réutilisée par l'accueil, la recherche,
     la fiche entreprise et les offres similaires. */
  SS.offerCard = function (offer) {
    var e = SS.escapeHtml;
    var remote = SS.teletravailLabel(offer.teletravail);
    var initials = e((offer.entrepriseNom || "??").split(/\s+/).slice(0, 2)
      .map(function (w) { return w.charAt(0); }).join("").toUpperCase());
    return '<article class="offer-row">' +
      '<span class="logo-bubble" style="background:' + e(offer.couleur || "#1E4F46") + '" aria-hidden="true">' + initials + "</span>" +
      "<div>" +
        '<h3 class="offer-row__title"><a href="offre-detail.html?id=' + encodeURIComponent(offer.id) + '">' + e(offer.titre) + "</a></h3>" +
        '<p class="offer-row__company"><strong>' + e(offer.entrepriseNom) + "</strong> · " + e(offer.ville) + "</p>" +
        '<p class="offer-row__tags">' + e(offer.contrat) + (offer.duree ? " " + e(offer.duree) : "") +
          " · " + e(offer.tempsTravail) +
          (offer.experienceLabel ? " · " + e(offer.experienceLabel) : "") +
          (remote ? ' · <span class="badge badge--remote">' + e(remote) + "</span>" : "") +
        "</p>" +
      "</div>" +
      '<div class="offer-row__side">' +
        '<span class="offer-row__salary">' + e(offer.salaire || "Salaire selon profil") + "</span>" +
        '<span class="offer-row__date">Publiée ' + e(SS.relativeDate(offer.datePublication)) + "</span>" +
        '<div class="offer-row__actions">' + saveButton(offer) +
          '<a class="btn btn-outline btn-sm" href="offre-detail.html?id=' + encodeURIComponent(offer.id) + '">Voir l\'offre</a>' +
        "</div>" +
      "</div>" +
    "</article>";
  };

  /* Offre mise en avant (accueil) : contenu complet + vraies actions. */
  SS.offerFeatured = function (offer) {
    var e = SS.escapeHtml;
    var remote = SS.teletravailLabel(offer.teletravail);
    var url = "offre-detail.html?id=" + encodeURIComponent(offer.id);
    return '<article class="offer-featured">' +
      '<span class="offer-featured__badge">★ Offre premium</span>' +
      '<h3><a href="' + url + '">' + e(offer.titre) + "</a></h3>" +
      '<p class="offer-featured__company"><strong>' + e(offer.entrepriseNom) + "</strong> · " + e(offer.ville) +
        " · " + e(offer.contrat) + (remote ? " · " + e(remote) : "") + "</p>" +
      "<p>" + e(offer.resume || offer.description || "") + "</p>" +
      '<p class="offer-featured__meta">' +
        '<span class="offer-featured__salary">' + e(offer.salaire || "Salaire selon profil") + "</span>" +
        "<span>" + e(offer.tempsTravail) + "</span>" +
        "<span>Publiée " + e(SS.relativeDate(offer.datePublication)) + "</span>" +
      "</p>" +
      '<div class="offer-featured__actions">' +
        '<a class="btn btn-accent" href="' + url + '">Voir l\'offre</a>' +
        '<a class="link-save" href="' + url + '#candidater">Candidater en 2 minutes</a>' +
      "</div>" +
    "</article>";
  };

  /* Bouton « enregistrer » : bascule visuelle conservée dans le navigateur. */
  document.addEventListener("click", function (event) {
    var btn = event.target.closest("[data-save-offer]");
    if (!btn) { return; }
    var id = btn.getAttribute("data-save-offer");
    var saved = SS.store.get(SAVE_KEY, []);
    var index = saved.indexOf(id);
    if (index === -1) { saved.push(id); } else { saved.splice(index, 1); }
    SS.store.set(SAVE_KEY, saved);
    btn.setAttribute("aria-pressed", index === -1 ? "true" : "false");
    document.dispatchEvent(new CustomEvent("ss:saved-changed"));
    SS.toast(index === -1
      ? "Offre enregistrée — retrouvez-la via le filtre « ★ Enregistrées »."
      : "Offre retirée de vos enregistrements.");
  });

  /* Associe la couleur du logo de l'entreprise à chaque offre. */
  SS.decorateOffers = function (offers) {
    return SS.getCompanies().then(function (companies) {
      var byId = {};
      companies.forEach(function (c) { byId[c.id] = c; });
      offers.forEach(function (o) {
        var c = byId[o.entrepriseId];
        if (c) { o.couleur = c.couleur; }
      });
      return offers;
    });
  };

  document.addEventListener("DOMContentLoaded", function () {
    renderRecentOffers();
    renderOfferDetail();
  });

  /* ---- Accueil : une offre vedette + une liste ---- */
  function renderRecentOffers() {
    var featuredBox = document.getElementById("home-offer-featured");
    var listBox = document.getElementById("home-offers-list");
    var legacy = document.getElementById("recent-offers");
    if (!featuredBox && !legacy) { return; }
    SS.getActiveOffers()
      .then(SS.decorateOffers)
      .then(function (offers) {
        var recent = offers.sort(function (a, b) {
          return new Date(b.datePublication) - new Date(a.datePublication);
        });
        /* Compteur honnête dans le hero : le vrai nombre d'offres actives. */
        var counter = document.getElementById("hero-offers-count");
        if (counter) {
          counter.textContent = recent.length + " offres en ligne aujourd'hui.";
        }
        if (featuredBox && listBox) {
          featuredBox.innerHTML = SS.offerFeatured(recent[0]);
          listBox.innerHTML = recent.slice(1, 5).map(SS.offerCard).join("");
        } else if (legacy) {
          legacy.innerHTML = recent.slice(0, 6).map(SS.offerCard).join("");
        }
      })
      .catch(function () { SS.dataError(featuredBox || legacy); });
  }

  /* ---- Fiche offre ---- */
  function renderOfferDetail() {
    var root = document.getElementById("offer-detail");
    if (!root) { return; }
    var id = SS.param("id");

    Promise.all([SS.getOffers(), SS.getCompanies()])
      .then(function (results) {
        var offers = results[0];
        var companies = results[1];
        var offer = offers.find(function (o) { return o.id === id; }) ||
          offers.filter(function (o) { return o.statut === "active"; })[0];
        if (!offer) { throw new Error("Aucune offre"); }
        var company = companies.find(function (c) { return c.id === offer.entrepriseId; });
        fillDetail(offer, company);
        setupApplyModal(offer);
        setupShare(offer);
        setupDetailSave(offer);
        setupCopyLink();
        renderSimilar(offer, offers, companies);
        injectJobPostingSchema(offer, company);
      })
      .catch(function () { SS.dataError(root.querySelector(".container") || root); });
  }

  function fillDetail(offer, company) {
    var e = SS.escapeHtml;
    var set = function (idSel, value) {
      var el = document.getElementById(idSel);
      if (el) { el.textContent = value || "—"; }
    };

    document.title = offer.titre + " – " + offer.ville + " | Postelio";

    set("offer-title", offer.titre);
    set("offer-city", offer.ville + " · " + offer.departement);
    set("offer-date", "Publiée " + SS.relativeDate(offer.datePublication));
    set("offer-description", offer.description);
    set("summary-contract", offer.contrat + (offer.duree ? " — " + offer.duree : ""));
    set("summary-time", offer.tempsTravail);
    set("summary-salary", offer.salaire);
    set("summary-remote", SS.teletravailLabel(offer.teletravail) || "Sur site");
    set("summary-expiry", "Jusqu'au " + SS.formatDate(offer.dateExpiration));
    set("offer-hero-salary", offer.salaire || "Salaire selon profil");

    var companyLink = document.getElementById("offer-company-link");
    if (companyLink) {
      companyLink.textContent = offer.entrepriseNom;
      companyLink.href = "entreprise-detail.html?id=" + encodeURIComponent(offer.entrepriseId);
    }

    var badges = document.getElementById("offer-badges");
    if (badges) {
      var remote = SS.teletravailLabel(offer.teletravail);
      badges.innerHTML =
        '<span class="badge">' + e(offer.contrat) + "</span>" +
        '<span class="badge badge--neutral">' + e(offer.tempsTravail) + "</span>" +
        (offer.experienceLabel ? '<span class="badge badge--neutral">' + e(offer.experienceLabel) + "</span>" : "") +
        (offer.niveauEtudeLabel ? '<span class="badge badge--neutral">' + e(offer.niveauEtudeLabel) + "</span>" : "") +
        (remote ? '<span class="badge badge--remote">' + e(remote) + "</span>" : "") +
        (offer.statut !== "active" ? '<span class="badge badge--expired">Offre expirée</span>' : "");
    }

    fillList("offer-missions", offer.missions);
    fillList("offer-profile", offer.profil);
    fillList("offer-benefits", offer.avantages);

    var skills = document.getElementById("offer-skills");
    if (skills && offer.competences) {
      skills.innerHTML = offer.competences.map(function (s) {
        return '<li><span class="chip">' + e(s) + "</span></li>";
      }).join("");
    }

    /* Encadré entreprise */
    if (company) {
      var box = document.getElementById("offer-company-card");
      if (box) {
        box.innerHTML =
          '<div class="company-card__top">' +
            '<span class="logo-bubble" style="background:' + e(company.couleur) + '" aria-hidden="true">' + e(company.initiales) + "</span>" +
            "<div><h3>" + e(company.nom) + "</h3>" +
            '<p class="text-muted">' + e(company.activite) + "</p></div>" +
          "</div>" +
          "<p>" + e(company.description) + "</p>" +
          '<p class="company-card-link"><a class="btn btn-outline btn-sm" href="entreprise-detail.html?id=' +
            encodeURIComponent(company.id) + '">Voir la fiche entreprise</a></p>';
      }
    }
  }

  function fillList(id, items) {
    var el = document.getElementById(id);
    if (el && items) {
      el.innerHTML = items.map(function (item) {
        return "<li>" + SS.escapeHtml(item) + "</li>";
      }).join("");
    }
  }

  /* ---- Candidature (modale) ---- */
  function setupApplyModal(offer) {
    var dialog = document.getElementById("apply-modal");
    var openBtn = document.getElementById("apply-button");
    if (!dialog || !openBtn) { return; }

    if (offer.statut !== "active") {
      openBtn.textContent = "Offre expirée";
      openBtn.setAttribute("disabled", "");
      return;
    }

    var titleEl = dialog.querySelector("#apply-offer-title");
    if (titleEl) { titleEl.textContent = offer.titre + " — " + offer.entrepriseNom; }

    openBtn.addEventListener("click", function () { SS.openModal(dialog); });

    /* Nom du fichier CV simulé. */
    var fileInput = dialog.querySelector("#apply-cv");
    var fileName = dialog.querySelector(".file-name");
    if (fileInput && fileName) {
      fileInput.addEventListener("change", function () {
        fileName.textContent = fileInput.files.length
          ? fileInput.files[0].name : "";
      });
    }

    var form = dialog.querySelector("form");
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      if (!validateForm(form)) { return; }
      /* Démo : aucune donnée n'est envoyée. En production, appel à
         APP_CONFIG.api.endpoints.applications. */
      form.hidden = true;
      var success = dialog.querySelector(".apply-success");
      if (success) { success.hidden = false; }
    });
  }

  /* Validation simple et messages en français. */
  function validateForm(form) {
    var valid = true;
    form.querySelectorAll("[required]").forEach(function (input) {
      var field = input.closest(".field");
      var error = field ? field.querySelector(".field-error") : null;
      var ok = input.type === "checkbox" ? input.checked : input.value.trim() !== "";
      if (ok && input.type === "email") {
        ok = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(input.value.trim());
      }
      if (field) { field.classList.toggle("has-error", !ok); }
      if (error) { error.hidden = ok; }
      if (!ok) { valid = false; }
    });
    return valid;
  }
  SS.validateForm = validateForm;

  /* ---- Enregistrer (fiche offre) : même stockage que la liste ---- */
  function setupDetailSave(offer) {
    var btn = document.getElementById("save-button");
    if (!btn) { return; }
    var label = btn.querySelector(".offer-tool__label");
    var icon = btn.querySelector(".offer-tool__icon");
    var sync = function () {
      var saved = isSaved(offer.id);
      btn.setAttribute("aria-pressed", saved ? "true" : "false");
      btn.classList.toggle("is-active", saved);
      if (icon) { icon.textContent = saved ? "★" : "☆"; }
      if (label) { label.textContent = saved ? "Enregistrée" : "Enregistrer"; }
    };
    sync();
    btn.addEventListener("click", function () {
      var list = SS.store.get(SAVE_KEY, []);
      var i = list.indexOf(offer.id);
      if (i === -1) { list.push(offer.id); } else { list.splice(i, 1); }
      SS.store.set(SAVE_KEY, list);
      sync();
      SS.toast(i === -1 ? "Offre enregistrée." : "Offre retirée de vos enregistrements.");
    });
  }

  /* ---- Copier le lien de l'offre ---- */
  function setupCopyLink() {
    var btn = document.getElementById("copy-link-button");
    if (!btn || !navigator.clipboard) { if (btn) { btn.hidden = true; } return; }
    var label = btn.querySelector(".offer-tool__label");
    btn.addEventListener("click", function () {
      navigator.clipboard.writeText(window.location.href).then(function () {
        if (label) { label.textContent = "Lien copié !"; }
        SS.toast("Lien de l'offre copié dans le presse-papiers.");
        setTimeout(function () { if (label) { label.textContent = "Copier le lien"; } }, 2500);
      });
    });
  }

  /* ---- Partage ---- */
  function setupShare(offer) {
    var btn = document.getElementById("share-button");
    if (!btn) { return; }
    /* Partage natif si disponible ; sinon le bouton « Copier le lien »
       voisin couvre déjà le besoin, on masque celui-ci. */
    if (!navigator.share) { btn.hidden = true; return; }
    btn.addEventListener("click", function () {
      navigator.share({
        title: offer.titre + " — Postelio",
        text: "Offre d'emploi : " + offer.titre + " à " + offer.ville,
        url: window.location.href
      }).catch(function () { /* partage annulé */ });
    });
  }

  /* ---- Offres similaires (même catégorie ou même ville) ---- */
  function renderSimilar(offer, offers, companies) {
    var container = document.getElementById("similar-offers");
    if (!container) { return; }
    var byId = {};
    companies.forEach(function (c) { byId[c.id] = c; });
    var similar = offers.filter(function (o) {
      return o.id !== offer.id && o.statut === "active" &&
        (o.categorie === offer.categorie || o.ville === offer.ville);
    }).slice(0, 3);
    similar.forEach(function (o) {
      var c = byId[o.entrepriseId];
      if (c) { o.couleur = c.couleur; }
    });
    if (!similar.length) {
      container.closest("section").hidden = true;
      return;
    }
    container.innerHTML = similar.map(similarCard).join("");
  }

  /* Carte compacte, distincte des rangées de la liste principale :
     monogramme, titre, entreprise, salaire, puis lien fléché. */
  function similarCard(offer) {
    var e = SS.escapeHtml;
    var url = "offre-detail.html?id=" + encodeURIComponent(offer.id);
    var initials = e((offer.entrepriseNom || "??").split(/\s+/).slice(0, 2)
      .map(function (w) { return w.charAt(0); }).join("").toUpperCase());
    var remote = SS.teletravailLabel(offer.teletravail);
    return '<article class="similar-card">' +
      '<div class="similar-card__head">' +
        '<span class="logo-bubble" style="background:' + e(offer.couleur || "#1E4F46") + '" aria-hidden="true">' + initials + "</span>" +
        '<span class="similar-card__contract">' + e(offer.contrat) + (remote ? " · " + e(remote) : "") + "</span>" +
      "</div>" +
      '<h3 class="similar-card__title"><a href="' + url + '">' + e(offer.titre) + "</a></h3>" +
      '<p class="similar-card__company">' + e(offer.entrepriseNom) + " · " + e(offer.ville) + "</p>" +
      '<p class="similar-card__salary">' + e(offer.salaire || "Salaire selon profil") + "</p>" +
      '<span class="similar-card__cta" aria-hidden="true">Voir l\'offre →</span>' +
    "</article>";
  }

  /* ---- Données structurées JobPosting (SEO) ---- */
  function injectJobPostingSchema(offer, company) {
    var schema = {
      "@context": "https://schema.org",
      "@type": "JobPosting",
      "title": offer.titre,
      "description": offer.description,
      "datePosted": offer.datePublication,
      "validThrough": offer.dateExpiration,
      "employmentType": offer.contrat,
      "hiringOrganization": {
        "@type": "Organization",
        "name": offer.entrepriseNom,
        "sameAs": company ? company.siteWeb : undefined
      },
      "jobLocation": {
        "@type": "Place",
        "address": {
          "@type": "PostalAddress",
          "addressLocality": offer.ville,
          "addressCountry": "FR"
        }
      },
      "baseSalary": offer.salaire
    };
    var script = document.createElement("script");
    script.type = "application/ld+json";
    script.textContent = JSON.stringify(schema);
    document.head.appendChild(script);
  }
})();

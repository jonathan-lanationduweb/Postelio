/**
 * Espace recruteur — Profil entreprise ASSISTÉ (espace-entreprise-profil.html).
 *
 * Objectif (Brief « profil assisté ») : faire gagner du temps au recruteur.
 *   - informations déjà connues = préremplies ;
 *   - valeurs courantes = chips / choix rapides ;
 *   - texte libre uniquement quand il apporte quelque chose ;
 *   - édition en ligne pour les champs simples, sauvegarde automatique ;
 *   - présentation proposée automatiquement (« Utiliser cette proposition ») ;
 *   - identité légale + contact + vérification (prépare une future vérification
 *     back-office — AUCUNE API branchée ici, données fictives de démonstration).
 *
 * Persistance : ss_company_profile (clé inchangée, compatible `description`).
 * Le modèle complet est amorcé (« seedé ») au 1er chargement afin que les autres
 * écrans (publication d'offre) lisent la même source. window.EMP l'expose aussi.
 */
(function () {
  "use strict";

  var PROFILE_KEY = "ss_company_profile";
  var MODEL_VERSION = 2; /* incrémenter pour réamorcer le modèle de démo */

  /* ---- Vocabulaires de choix rapides (chips) ---- */
  var AVANTAGES_PRESET = ["Télétravail", "Horaires flexibles", "Tickets restaurant",
    "Mutuelle", "RTT", "Prime", "Transport", "Formation", "Intéressement",
    "Participation", "Épargne salariale", "Restaurant d'entreprise"];

  var VALEURS_PRESET = ["Esprit d'équipe", "Proximité", "Autonomie", "Innovation",
    "Transmission", "Bienveillance", "Rigueur", "Responsabilité", "Diversité",
    "Engagement environnemental"];

  var TELETRAVAIL_OPTS = ["Aucun", "Occasionnel", "1 jour / semaine",
    "2 jours / semaine", "3 jours ou plus", "100 % télétravail"];
  var HORAIRES_OPTS = ["Fixes", "Flexibles", "Variables", "Travail posté"];
  var ORG_TAGS = ["Travail en équipe", "Autonomie", "Déplacements",
    "Contact client", "Travail hybride"];

  var EFFECTIF_OPTS = ["1–9", "10–49", "50–249", "250–999", "1000+"];

  var FORME_OPTS = ["SARL", "SAS", "SASU", "EURL", "SA", "SELARL", "SCP",
    "Entreprise individuelle", "Association", "Autre"];

  /* ---- Modèle de démonstration : valeurs préremplies ---- */
  /* Coordonnées / avantages / valeurs sont dérivés de companies.json (source
     canonique). L'identité légale et le contact sont des données FICTIVES de
     démonstration, clairement signalées comme telles à l'écran. */
  function buildDefaults(company) {
    company = company || {};
    return {
      _v: MODEL_VERSION,
      /* Informations générales */
      nom: company.nom || "Fiduciaire Bellecour",
      secteur: company.secteur || "Finance & Comptabilité",
      ville: company.ville || "Lyon",
      effectif: "10–49",
      /* Identité légale (fictive — démonstration) */
      raisonSociale: "Fiduciaire Bellecour",
      nomCommercial: "Fiduciaire Bellecour",
      formeJuridique: "SARL",
      siren: "801 234 567",
      siret: "801 234 567 00018",
      tva: "FR32 801234567",
      adresseSiege: "18 rue de la République",
      cpSiege: "69002",
      villeSiege: "Lyon",
      pays: "France",
      dateCreation: "2009",
      /* Contact professionnel */
      contactPrenom: "Claire",
      contactNom: "Martin",
      contactFonction: "Responsable recrutement & RH",
      contactEmail: "claire.martin@fiduciaire-bellecour.exemple.fr",
      contactTel: "04 00 00 00 00",
      emailVerifie: true,
      /* Présentation */
      description: company.description ||
        "Fiduciaire Bellecour est un cabinet d'expertise comptable basé à Lyon, accompagnant TPE, PME et professions libérales en comptabilité, paie et conseil.",
      /* Coordonnées */
      adresse: company.adresse || "18 rue de la République, 69002 Lyon",
      telephone: company.telephone || "04 00 00 00 00",
      email: company.email || "recrutement@fiduciaire-bellecour.exemple.fr",
      site: (company.siteWeb || "https://www.fiduciaire-bellecour.exemple.fr").replace(/^https?:\/\//, ""),
      /* Avantages / valeurs (chips) */
      avantagesList: ["Télétravail", "Horaires flexibles", "Tickets restaurant",
        "Mutuelle", "Formation", "Transport"],
      valeursList: ["Proximité", "Esprit d'équipe", "Transmission", "Rigueur"],
      /* Organisation du travail */
      orgTeletravail: "2 jours / semaine",
      orgHoraires: "Flexibles",
      orgTags: ["Travail en équipe", "Contact client", "Autonomie"],
      orgPrecisions: "",
      /* Réseaux */
      linkedin: "linkedin.com/company/fiduciaire-bellecour",
      siteReseau: "www.fiduciaire-bellecour.exemple.fr",
      instagram: "",
      facebook: "",
      /* Médias (pour la jauge de complétion) */
      hasLogo: false,
      hasPhoto: false
    };
  }

  var model = null;   /* modèle courant (merge défauts + enregistré) */
  var defaults = null;

  document.addEventListener("DOMContentLoaded", function () {
    if (!window.EMP || !EMP.ready) { return; }
    if (!document.getElementById("profil-sections")) { return; }

    SS.getCompanies().then(function (companies) {
      var s = SS.auth.get() || {};
      var company = (companies || []).find(function (c) {
        return c.id === (s.companyId || APP_CONFIG.demoCompany.id);
      }) || {};
      defaults = buildDefaults(company);

      /* Amorçage / migration du modèle stocké. */
      var stored = SS.store.get(PROFILE_KEY, null);
      if (!stored || stored._v !== MODEL_VERSION) {
        model = Object.assign({}, defaults, stored || {}, { _v: MODEL_VERSION });
        SS.store.set(PROFILE_KEY, model);
      } else {
        model = Object.assign({}, defaults, stored);
      }

      renderAll();
    }).catch(function () {
      defaults = buildDefaults({});
      model = Object.assign({}, defaults, SS.store.get(PROFILE_KEY, {}) || {});
      renderAll();
    });
  });

  /* Sauvegarde d'un fragment + retour visuel « ✓ Enregistré ». */
  function save(patch, badgeEl) {
    Object.assign(model, patch);
    SS.store.set(PROFILE_KEY, model);
    if (badgeEl) { flashSaved(badgeEl); }
  }

  function flashSaved(el) {
    el.textContent = "✓ Enregistré";
    el.hidden = false;
    el.classList.add("is-shown");
    clearTimeout(el._t);
    el._t = setTimeout(function () { el.classList.remove("is-shown"); }, 1800);
  }

  /* ============================================================
     Rendu global
     ============================================================ */
  function renderAll() {
    renderIdentity();
    renderMeter();
    renderVerification();
    renderSections();
  }

  function renderIdentity() {
    var e = SS.escapeHtml;
    var box = document.getElementById("profil-identity");
    if (!box) { return; }
    box.innerHTML =
      '<span class="avatar profile-logo" aria-hidden="true">' + e(EMP.companyInitials(model.nom)) + "</span>" +
      "<span>" +
        '<span class="profile-head__name">' + e(model.nom) + "</span>" +
        '<span class="profile-head__meta">' + e(model.secteur) + " · " + e(model.ville) + "</span>" +
      "</span>";
  }

  /* ---- Jauge de complétion cliquable (§10) ---- */
  function completion() {
    var checks = [
      { key: "logo", label: "Ajouter votre logo", ok: !!model.hasLogo, target: "logo" },
      { key: "photo", label: "Ajouter une photo de vos locaux", ok: !!model.hasPhoto, target: "photo" },
      { key: "presentation", label: "Compléter votre présentation", ok: (model.description || "").length > 40, target: "presentation" },
      { key: "avantages", label: "Indiquer vos avantages", ok: (model.avantagesList || []).length >= 3, target: "avantages" },
      { key: "valeurs", label: "Indiquer vos valeurs", ok: (model.valeursList || []).length >= 2, target: "valeurs" },
      { key: "legal", label: "Compléter l'identité légale", ok: !!(model.siren && model.raisonSociale), target: "legal" },
      { key: "contact", label: "Renseigner le contact", ok: !!(model.contactEmail && model.contactNom), target: "contact" }
    ];
    var done = checks.filter(function (c) { return c.ok; }).length;
    return { pct: Math.round((done / checks.length) * 100), missing: checks.filter(function (c) { return !c.ok; }) };
  }

  function renderMeter() {
    var box = document.getElementById("profil-meter");
    if (!box) { return; }
    var c = completion();
    var e = SS.escapeHtml;
    var missing = c.missing.length
      ? '<p class="profile-meter__label">Pour atteindre 100&nbsp;% :</p><ul class="profil-todo">' +
          c.missing.map(function (m) {
            return '<li><button type="button" class="profil-todo__btn" data-goto="' + m.target + '">+ ' + e(m.label) + "</button></li>";
          }).join("") + "</ul>"
      : '<p class="profile-meter__label">Profil complet — bravo&nbsp;! Votre vitrine est prête.</p>';
    box.innerHTML =
      '<div class="profile-meter" role="img" aria-label="Profil complété à ' + c.pct + ' %">' +
        '<span style="width:' + c.pct + '%"></span>' +
      "</div>" +
      '<p class="profile-meter__pct"><strong>' + c.pct + "&nbsp;%</strong> complété</p>" +
      missing;

    box.querySelectorAll("[data-goto]").forEach(function (btn) {
      btn.addEventListener("click", function () { gotoTarget(btn.getAttribute("data-goto")); });
    });
  }

  /* Cible d'un item « à compléter » : logo/photo se simulent, le reste défile. */
  function gotoTarget(target) {
    if (target === "logo" || target === "photo") {
      var patch = {};
      patch[target === "logo" ? "hasLogo" : "hasPhoto"] = true;
      save(patch);
      renderMeter();
      SS.toast(target === "logo" ? "Logo ajouté (démonstration)." : "Photo ajoutée (démonstration).");
      return;
    }
    var block = document.querySelector('.profil-block[data-section="' + target + '"]');
    if (block) {
      block.scrollIntoView({ behavior: "smooth", block: "center" });
      block.classList.add("is-flash");
      setTimeout(function () { block.classList.remove("is-flash"); }, 1200);
    }
  }

  /* ---- Bloc de vérification d'entreprise (§4-5) ---- */
  function renderVerification() {
    var box = document.getElementById("profil-verification");
    if (!box) { return; }
    var e = SS.escapeHtml;
    var items = [
      { label: "Informations légales renseignées", ok: !!(model.siren && model.formeJuridique) },
      { label: "Adresse du siège renseignée", ok: !!(model.adresseSiege && model.cpSiege) },
      { label: "Contact professionnel renseigné", ok: !!(model.contactEmail && model.contactNom) },
      { label: "E-mail professionnel vérifié", ok: !!model.emailVerifie }
    ];
    var allOk = items.every(function (i) { return i.ok; });
    var statut = allOk ? "verifie" : (items.some(function (i) { return i.ok; }) ? "en-cours" : "incomplet");
    var STATUT = {
      "verifie": { txt: "Entreprise vérifiée — démonstration", cls: "is-verifie" },
      "en-cours": { txt: "En cours de vérification", cls: "is-encours" },
      "incomplet": { txt: "Profil incomplet", cls: "is-incomplet" }
    }[statut];

    box.innerHTML =
      '<div class="card dash-card profil-verif ' + STATUT.cls + '" data-section="verification">' +
        '<div class="profil-verif__head">' +
          "<h3>Vérification de l'entreprise</h3>" +
          '<span class="verif-badge ' + STATUT.cls + '">' + (statut === "verifie" ? "✓ " : "") + e(STATUT.txt) + "</span>" +
        "</div>" +
        '<ul class="profil-verif__list">' +
          items.map(function (i) {
            return '<li class="' + (i.ok ? "is-ok" : "is-todo") + '">' +
              '<span aria-hidden="true">' + (i.ok ? "✓" : "○") + "</span> " + e(i.label) + "</li>";
          }).join("") +
        "</ul>" +
        (allOk
          ? '<p class="form-hint">Ce badge est une simulation de démonstration : aucune vérification administrative réelle n\'est effectuée.</p>'
          : '<button type="button" class="btn btn-outline btn-sm" data-goto="legal">Compléter la vérification</button>') +
      "</div>";

    var b = box.querySelector("[data-goto]");
    if (b) { b.addEventListener("click", function () { gotoTarget("legal"); }); }
  }

  /* ============================================================
     Sections éditables
     ============================================================ */
  /* Chaque section est décrite de façon déclarative ; le rendu et le câblage
     sont génériques (champs simples en ligne, chips, radios, présentation). */
  var SECTIONS = [
    { id: "infos", title: "Informations générales", fields: [
      { key: "nom", label: "Nom", type: "text", identity: true },
      { key: "secteur", label: "Secteur", type: "text", identity: true },
      { key: "ville", label: "Ville", type: "text", identity: true },
      { key: "effectif", label: "Effectif", type: "buttons", opts: EFFECTIF_OPTS, allowOther: true }
    ] },
    { id: "presentation", title: "Présentation", type: "presentation" },
    { id: "legal", title: "Identité légale", note: "Données fictives de démonstration — une future vérification back-office pourra les contrôler.", fields: [
      { key: "raisonSociale", label: "Raison sociale", type: "text" },
      { key: "nomCommercial", label: "Nom commercial", type: "text" },
      { key: "formeJuridique", label: "Forme juridique", type: "select", opts: FORME_OPTS },
      { key: "siren", label: "SIREN", type: "text" },
      { key: "siret", label: "SIRET", type: "text" },
      { key: "tva", label: "N° TVA intracommunautaire", type: "text" },
      { key: "adresseSiege", label: "Adresse du siège", type: "text" },
      { key: "cpSiege", label: "Code postal", type: "text" },
      { key: "villeSiege", label: "Ville", type: "text" },
      { key: "pays", label: "Pays", type: "text" },
      { key: "dateCreation", label: "Date de création", type: "text" }
    ] },
    { id: "contact", title: "Contact de l'entreprise", fields: [
      { key: "contactPrenom", label: "Prénom du responsable", type: "text" },
      { key: "contactNom", label: "Nom", type: "text" },
      { key: "contactFonction", label: "Fonction", type: "text" },
      { key: "contactEmail", label: "E-mail professionnel", type: "email", verifiable: true },
      { key: "contactTel", label: "Téléphone professionnel", type: "tel" }
    ] },
    { id: "coordonnees", title: "Coordonnées", fields: [
      { key: "adresse", label: "Adresse", type: "text" },
      { key: "telephone", label: "Téléphone", type: "tel" },
      { key: "email", label: "E-mail de contact", type: "email" },
      { key: "site", label: "Site web", type: "text" }
    ] },
    { id: "avantages", title: "Avantages", type: "chips", listKey: "avantagesList", preset: AVANTAGES_PRESET,
      question: "Quels avantages proposez-vous ?", addLabel: "Ajouter un avantage personnalisé" },
    { id: "valeurs", title: "Valeurs", type: "chips", listKey: "valeursList", preset: VALEURS_PRESET,
      question: "Quelles sont vos valeurs ?", addLabel: "Ajouter une valeur" },
    { id: "organisation", title: "Organisation du travail", type: "organisation" },
    { id: "reseaux", title: "Réseaux", fields: [
      { key: "linkedin", label: "LinkedIn", type: "text" },
      { key: "siteReseau", label: "Site internet", type: "text" },
      { key: "instagram", label: "Instagram (optionnel)", type: "text" },
      { key: "facebook", label: "Facebook (optionnel)", type: "text" }
    ] }
  ];

  function renderSections() {
    var box = document.getElementById("profil-sections");
    if (!box) { return; }
    box.innerHTML = SECTIONS.map(sectionShell).join("");
    SECTIONS.forEach(function (sec) {
      var block = box.querySelector('.profil-block[data-section="' + sec.id + '"]');
      var body = block.querySelector(".profil-block__body");
      if (sec.type === "chips") { renderChips(sec, body); }
      else if (sec.type === "presentation") { renderPresentation(body); }
      else if (sec.type === "organisation") { renderOrganisation(body); }
      else { renderFields(sec, body); }
    });
  }

  function sectionShell(sec) {
    var e = SS.escapeHtml;
    return '<div class="card dash-card profil-block" data-section="' + sec.id + '">' +
        '<div class="profil-section__head">' +
          "<h3>" + e(sec.title) + "</h3>" +
          '<span class="save-badge" role="status" hidden></span>' +
        "</div>" +
        (sec.note ? '<p class="form-hint profil-note">' + e(sec.note) + "</p>" : "") +
        '<div class="profil-block__body"></div>' +
      "</div>";
  }

  function badgeOf(sec) {
    var block = document.querySelector('.profil-block[data-section="' + sec.id + '"]');
    return block ? block.querySelector(".save-badge") : null;
  }

  /* ---- Champs simples : édition EN LIGNE, sauvegarde auto (§11-12) ---- */
  function renderFields(sec, body) {
    var e = SS.escapeHtml;
    body.className = "profil-block__body profil-fields";
    body.innerHTML = sec.fields.map(function (f) {
      var v = model[f.key];
      var display = v && String(v).trim() ? e(v) : '<span class="text-muted">Non renseigné</span>';
      var verif = "";
      if (f.verifiable) {
        verif = model.emailVerifie
          ? ' <span class="verif-badge verif-badge--inline is-verifie">✓ Vérifié</span>'
          : ' <button type="button" class="btn btn-outline btn-xs" data-verify>À vérifier</button>';
      }
      return '<div class="profil-field" data-key="' + e(f.key) + '">' +
          "<dt>" + e(f.label) + "</dt>" +
          '<dd class="profil-field__row">' +
            '<span class="profil-field__val">' + display + verif + "</span>" +
            '<button type="button" class="btn btn-ghost btn-xs profil-field__edit" data-edit>Modifier</button>' +
          "</dd>" +
        "</div>";
    }).join("");

    sec.fields.forEach(function (f) { wireField(sec, body, f); });
  }

  function wireField(sec, body, f) {
    var wrap = body.querySelector('.profil-field[data-key="' + f.key + '"]');
    if (!wrap) { return; }
    var dd = wrap.querySelector("dd");

    if (f.verifiable) {
      var vb = dd.querySelector("[data-verify]");
      if (vb) {
        vb.addEventListener("click", function () {
          save({ emailVerifie: true }, badgeOf(sec));
          renderFields(sec, body);
          renderVerification();
          renderMeter();
          SS.toast("E-mail professionnel vérifié (simulation de démonstration).");
        });
      }
    }

    wrap.querySelector("[data-edit]").addEventListener("click", function () {
      openInline(sec, body, f, wrap, dd);
    });
  }

  function openInline(sec, body, f, wrap, dd) {
    var e = SS.escapeHtml;
    var control;
    if (f.type === "select") {
      control = '<select class="profil-inline__input">' + f.opts.map(function (o) {
        return '<option value="' + e(o) + '"' + (o === model[f.key] ? " selected" : "") + ">" + e(o) + "</option>";
      }).join("") + "</select>";
    } else {
      control = '<input type="' + (f.type || "text") + '" class="profil-inline__input" value="' + e(model[f.key] || "") + '">';
    }
    dd.innerHTML = '<span class="profil-inline">' + control +
      '<button type="button" class="btn btn-primary btn-xs" data-save aria-label="Enregistrer">✓</button>' +
      '<button type="button" class="btn btn-ghost btn-xs" data-cancel aria-label="Annuler">✕</button></span>';
    var input = dd.querySelector(".profil-inline__input");
    input.focus();
    if (input.select) { input.select(); }

    function commit() {
      var patch = {};
      patch[f.key] = (input.value || "").trim();
      save(patch, badgeOf(sec));
      renderFields(sec, body);
      if (f.identity) { renderIdentity(); }
      renderMeter();
      renderVerification();
    }
    dd.querySelector("[data-save]").addEventListener("click", commit);
    dd.querySelector("[data-cancel]").addEventListener("click", function () { renderFields(sec, body); });
    input.addEventListener("keydown", function (ev) {
      if (ev.key === "Enter") { ev.preventDefault(); commit(); }
      if (ev.key === "Escape") { renderFields(sec, body); }
    });
  }

  /* ---- Chips sélectionnables + ajout personnalisé (§5-6) ---- */
  function renderChips(sec, body) {
    var e = SS.escapeHtml;
    var selected = (model[sec.listKey] || []).slice();
    /* on affiche les presets + les valeurs personnalisées déjà choisies */
    var all = sec.preset.slice();
    selected.forEach(function (v) { if (all.indexOf(v) === -1) { all.push(v); } });

    body.className = "profil-block__body";
    body.innerHTML =
      '<p class="profil-chips__q">' + e(sec.question) + "</p>" +
      '<div class="profil-chips">' +
        all.map(function (v) {
          var on = selected.indexOf(v) !== -1;
          return '<button type="button" class="chip" aria-pressed="' + on + '" data-chip="' + e(v) + '">' + e(v) + "</button>";
        }).join("") +
      "</div>" +
      '<form class="profil-chips__add" novalidate>' +
        '<input type="text" placeholder="' + e(sec.addLabel) + '" aria-label="' + e(sec.addLabel) + '">' +
        '<button type="submit" class="btn btn-outline btn-sm">+ Ajouter</button>' +
      "</form>";

    body.querySelectorAll("[data-chip]").forEach(function (chip) {
      chip.addEventListener("click", function () {
        var val = chip.getAttribute("data-chip");
        var list = (model[sec.listKey] || []).slice();
        var i = list.indexOf(val);
        if (i === -1) { list.push(val); } else { list.splice(i, 1); }
        var patch = {}; patch[sec.listKey] = list;
        save(patch, badgeOf(sec));
        chip.setAttribute("aria-pressed", i === -1 ? "true" : "false");
        renderMeter();
      });
    });

    var addForm = body.querySelector(".profil-chips__add");
    addForm.addEventListener("submit", function (ev) {
      ev.preventDefault();
      var input = addForm.querySelector("input");
      var val = (input.value || "").trim();
      if (!val) { return; }
      var list = (model[sec.listKey] || []).slice();
      if (list.indexOf(val) === -1) { list.push(val); }
      var patch = {}; patch[sec.listKey] = list;
      save(patch, badgeOf(sec));
      input.value = "";
      renderChips(sec, body);
      renderMeter();
    });
  }

  /* ---- Présentation proposée automatiquement (§3) ---- */
  function suggestPresentation() {
    var effectif = model.effectif && model.effectif !== "10–49" ? " (" + model.effectif + " salariés)" : "";
    return model.nom + " est un cabinet d'expertise comptable basé à " + model.ville +
      ", accompagnant TPE, PME et professions libérales" + effectif +
      " en comptabilité, gestion sociale et conseil.";
  }

  function renderPresentation(body) {
    var e = SS.escapeHtml;
    var current = (model.description || "").trim();
    body.className = "profil-block__body";
    if (current) {
      body.innerHTML =
        '<p class="profil-presentation__text">' + e(current) + "</p>" +
        '<div class="profil-presentation__actions">' +
          '<button type="button" class="btn btn-ghost btn-sm" data-edit-pres>Modifier</button>' +
        "</div>";
      body.querySelector("[data-edit-pres]").addEventListener("click", function () { editPresentation(body); });
    } else {
      var suggestion = suggestPresentation();
      body.innerHTML =
        '<p class="form-hint">Présentation proposée à partir de vos informations :</p>' +
        '<blockquote class="profil-presentation__suggest">' + e(suggestion) + "</blockquote>" +
        '<div class="profil-presentation__actions">' +
          '<button type="button" class="btn btn-primary btn-sm" data-use>Utiliser cette proposition</button>' +
          '<button type="button" class="btn btn-outline btn-sm" data-edit-pres>Écrire moi-même</button>' +
        "</div>";
      body.querySelector("[data-use]").addEventListener("click", function () {
        save({ description: suggestion }, badgeOf({ id: "presentation" }));
        renderPresentation(body);
        renderMeter();
      });
      body.querySelector("[data-edit-pres]").addEventListener("click", function () { editPresentation(body); });
    }
  }

  function editPresentation(body) {
    var e = SS.escapeHtml;
    var current = (model.description || "").trim() || suggestPresentation();
    body.innerHTML =
      '<form class="profil-presentation__form">' +
        '<label class="sr-only" for="pf-presentation">Présentation de l\'entreprise</label>' +
        '<textarea id="pf-presentation" rows="5">' + e(current) + "</textarea>" +
        '<div class="form-actions">' +
          '<button type="submit" class="btn btn-primary btn-sm">Enregistrer</button>' +
          '<button type="button" class="btn btn-ghost btn-sm" data-cancel>Annuler</button>' +
        "</div>" +
      "</form>";
    var form = body.querySelector("form");
    form.querySelector("textarea").focus();
    form.addEventListener("submit", function (ev) {
      ev.preventDefault();
      save({ description: (form.querySelector("textarea").value || "").trim() }, badgeOf({ id: "presentation" }));
      renderPresentation(body);
      renderMeter();
    });
    form.querySelector("[data-cancel]").addEventListener("click", function () { renderPresentation(body); });
  }

  /* ---- Organisation du travail : radios + chips + précisions (§7) ---- */
  function renderOrganisation(body) {
    var e = SS.escapeHtml;
    body.className = "profil-block__body profil-org";
    body.innerHTML =
      radioGroup("Télétravail", "orgTeletravail", TELETRAVAIL_OPTS) +
      radioGroup("Horaires", "orgHoraires", HORAIRES_OPTS) +
      '<div class="profil-org__group"><span class="profil-org__legend">Organisation</span>' +
        '<div class="profil-chips">' + ORG_TAGS.map(function (t) {
          var on = (model.orgTags || []).indexOf(t) !== -1;
          return '<button type="button" class="chip" aria-pressed="' + on + '" data-orgtag="' + e(t) + '">' + e(t) + "</button>";
        }).join("") + "</div></div>" +
      '<div class="profil-org__group"><label class="profil-org__legend" for="pf-org-prec">Précisions (optionnel)</label>' +
        '<textarea id="pf-org-prec" rows="2" placeholder="Ex. : équipe de 28 personnes, locaux accessibles PMR…">' + e(model.orgPrecisions || "") + "</textarea></div>";

    function radioGroup(legend, key, opts) {
      return '<div class="profil-org__group" data-radio="' + key + '"><span class="profil-org__legend">' + e(legend) + "</span>" +
        '<div class="profil-radios">' + opts.map(function (o) {
          var on = model[key] === o;
          return '<button type="button" class="chip" aria-pressed="' + on + '" data-radioval="' + e(o) + '">' + e(o) + "</button>";
        }).join("") + "</div></div>";
    }

    body.querySelectorAll("[data-radio]").forEach(function (group) {
      var key = group.getAttribute("data-radio");
      group.querySelectorAll("[data-radioval]").forEach(function (btn) {
        btn.addEventListener("click", function () {
          group.querySelectorAll("[data-radioval]").forEach(function (b) { b.setAttribute("aria-pressed", "false"); });
          btn.setAttribute("aria-pressed", "true");
          var patch = {}; patch[key] = btn.getAttribute("data-radioval");
          save(patch, badgeOf({ id: "organisation" }));
        });
      });
    });
    body.querySelectorAll("[data-orgtag]").forEach(function (chip) {
      chip.addEventListener("click", function () {
        var val = chip.getAttribute("data-orgtag");
        var list = (model.orgTags || []).slice();
        var i = list.indexOf(val);
        if (i === -1) { list.push(val); } else { list.splice(i, 1); }
        chip.setAttribute("aria-pressed", i === -1 ? "true" : "false");
        save({ orgTags: list }, badgeOf({ id: "organisation" }));
      });
    });
    var prec = body.querySelector("#pf-org-prec");
    prec.addEventListener("change", function () {
      save({ orgPrecisions: (prec.value || "").trim() }, badgeOf({ id: "organisation" }));
    });
  }

  /* ============================================================
     Assistant « Compléter en 2 minutes » (§9) — 3 étapes max
     ============================================================ */
  document.addEventListener("click", function (ev) {
    if (ev.target && ev.target.id === "profil-quickstart") { openQuickstart(); }
  });

  function openQuickstart() {
    var e = SS.escapeHtml;
    var overlay = document.createElement("div");
    overlay.className = "modal-overlay profil-wizard";
    overlay.setAttribute("role", "dialog");
    overlay.setAttribute("aria-modal", "true");
    overlay.setAttribute("aria-label", "Compléter mon profil");

    var step = 1;
    overlay.innerHTML =
      '<div class="modal modal--wizard">' +
        '<div class="modal__head">' +
          '<div class="wizard-progress" aria-hidden="true"><span class="wp-dot is-on">1</span><span class="wp-dot">2</span><span class="wp-dot">3</span></div>' +
          '<button type="button" class="modal-close" data-close aria-label="Fermer">✕</button>' +
        "</div>" +
        '<div class="modal__body wizard-body"></div>' +
        '<div class="modal__actions wizard-foot">' +
          '<button type="button" class="btn btn-ghost btn-sm" data-back hidden>Retour</button>' +
          '<button type="button" class="btn btn-primary" data-next>Continuer</button>' +
        "</div>" +
      "</div>";
    document.body.appendChild(overlay);
    document.body.classList.add("modal-open");

    var body = overlay.querySelector(".wizard-body");
    var backBtn = overlay.querySelector("[data-back]");
    var nextBtn = overlay.querySelector("[data-next]");

    function close() {
      overlay.remove();
      document.body.classList.remove("modal-open");
      renderAll();
    }
    overlay.querySelector("[data-close]").addEventListener("click", close);
    overlay.addEventListener("click", function (ev2) { if (ev2.target === overlay) { close(); } });

    function drawStep() {
      overlay.querySelectorAll(".wp-dot").forEach(function (d, i) { d.classList.toggle("is-on", i < step); });
      backBtn.hidden = step === 1;
      nextBtn.textContent = step === 3 ? "Terminer" : "Continuer";
      if (step === 1) {
        body.innerHTML = '<h3>1. Entreprise</h3>' +
          field("Nom", "nom") + field("Ville", "ville") + effectifButtons();
      } else if (step === 2) {
        body.innerHTML = '<h3>2. Organisation & avantages</h3>' +
          '<p class="form-hint">Sélectionnez ce qui s\'applique.</p>' +
          chipMini("Avantages", "avantagesList", AVANTAGES_PRESET) +
          chipMini("Valeurs", "valeursList", VALEURS_PRESET);
        wireMiniChips();
      } else {
        var suggestion = (model.description || "").trim() || suggestPresentation();
        body.innerHTML = '<h3>3. Présentation</h3>' +
          '<p class="form-hint">Proposition automatique — modifiable :</p>' +
          '<textarea id="wz-desc" rows="5">' + e(suggestion) + "</textarea>";
      }
    }

    function field(label, key) {
      return '<div class="field"><label>' + e(label) + '</label>' +
        '<input type="text" data-wz="' + key + '" value="' + e(model[key] || "") + '"></div>';
    }
    function effectifButtons() {
      return '<div class="field"><label>Effectif</label><div class="profil-radios" data-wz-eff>' +
        EFFECTIF_OPTS.map(function (o) {
          return '<button type="button" class="chip" aria-pressed="' + (model.effectif === o) + '" data-eff="' + e(o) + '">' + e(o) + "</button>";
        }).join("") + "</div></div>";
    }
    function chipMini(label, key, preset) {
      return '<div class="field"><label>' + e(label) + '</label><div class="profil-chips" data-wz-chips="' + key + '">' +
        preset.map(function (v) {
          var on = (model[key] || []).indexOf(v) !== -1;
          return '<button type="button" class="chip" aria-pressed="' + on + '" data-v="' + e(v) + '">' + e(v) + "</button>";
        }).join("") + "</div></div>";
    }
    function wireMiniChips() {
      body.querySelectorAll("[data-wz-chips]").forEach(function (grp) {
        var key = grp.getAttribute("data-wz-chips");
        grp.querySelectorAll("[data-v]").forEach(function (chip) {
          chip.addEventListener("click", function () {
            var list = (model[key] || []).slice();
            var v = chip.getAttribute("data-v");
            var i = list.indexOf(v);
            if (i === -1) { list.push(v); } else { list.splice(i, 1); }
            chip.setAttribute("aria-pressed", i === -1 ? "true" : "false");
            var patch = {}; patch[key] = list; save(patch);
          });
        });
      });
    }

    function persistStep() {
      if (step === 1) {
        var patch = {};
        body.querySelectorAll("[data-wz]").forEach(function (i) { patch[i.getAttribute("data-wz")] = (i.value || "").trim(); });
        var eff = body.querySelector('[data-wz-eff] [aria-pressed="true"]');
        if (eff) { patch.effectif = eff.getAttribute("data-eff"); }
        save(patch);
      } else if (step === 3) {
        var ta = body.querySelector("#wz-desc");
        if (ta) { save({ description: (ta.value || "").trim() }); }
      }
    }

    /* Choix d'effectif dans l'assistant (délégation). */
    body.addEventListener("click", function (ev3) {
      var b = ev3.target.closest ? ev3.target.closest("[data-eff]") : null;
      if (!b) { return; }
      b.parentNode.querySelectorAll("[data-eff]").forEach(function (x) { x.setAttribute("aria-pressed", "false"); });
      b.setAttribute("aria-pressed", "true");
    });

    nextBtn.addEventListener("click", function () {
      persistStep();
      if (step === 3) { SS.toast("Profil complété (démonstration)."); close(); return; }
      step++; drawStep();
    });
    backBtn.addEventListener("click", function () { persistStep(); step--; drawStep(); });

    drawStep();
  }
})();

/**
 * Espace recruteur — Profil entreprise (espace-entreprise-profil.html).
 *
 * En-tête d'identité (logo à initiales de l'ENTREPRISE, nom, secteur, ville,
 * jauge de complétion) puis plusieurs sections affichées EN LECTURE, chacune
 * dotée d'un bouton [Modifier] (divulgation progressive : les champs de saisie
 * n'apparaissent qu'à l'édition).
 *
 * Persistance dans ss_company_profile (clé inchangée). La clé `description`
 * reste lue/écrite comme avant (§47) ; les autres champs sont ajoutés au même
 * objet.
 */
(function () {
  "use strict";

  var PROFILE_KEY = "ss_company_profile";

  var DEFAULT_DESC = "Cabinet d'expertise comptable lyonnais accompagnant TPE, PME et professions libérales : comptabilité, paie, conseil et gestion sociale.";

  /* Définition déclarative des sections (lecture + édition génériques). */
  var SECTIONS = [
    { id: "infos", title: "Informations générales", identity: true, fields: [
      { key: "nom", label: "Nom", def: "Fiduciaire Bellecour" },
      { key: "secteur", label: "Secteur", def: "Finance & Comptabilité" },
      { key: "ville", label: "Ville", def: "Lyon" },
      { key: "taille", label: "Effectif", type: "select", def: "10 à 49 salariés",
        options: ["1 à 9 salariés", "10 à 49 salariés", "50 à 249 salariés", "250 salariés et plus"] }
    ] },
    { id: "presentation", title: "Présentation", fields: [
      { key: "description", label: "Présentation de l'entreprise", type: "textarea", def: DEFAULT_DESC }
    ] },
    { id: "coordonnees", title: "Coordonnées", fullWidth: true, fields: [
      { key: "adresse", label: "Adresse", def: "12 quai Général Sarrail, 69006 Lyon" },
      { key: "telephone", label: "Téléphone", type: "tel", def: "04 72 00 00 00" },
      { key: "email", label: "E-mail", type: "email", def: "contact@fiduciaire-bellecour.exemple.fr" },
      { key: "site", label: "Site web", def: "www.fiduciaire-bellecour.exemple.fr" }
    ] },
    { id: "reseaux", title: "Réseaux sociaux", fields: [
      { key: "linkedin", label: "LinkedIn", def: "linkedin.com/company/fiduciaire-bellecour" },
      { key: "instagram", label: "Instagram", def: "" }
    ] },
    { id: "avantages", title: "Avantages", fields: [
      { key: "avantages", label: "Avantages proposés", type: "textarea",
        def: "Tickets restaurant\nMutuelle prise en charge à 60 %\nTélétravail partiel (2 j/semaine)\nPrime de fin d'année" }
    ] },
    { id: "valeurs", title: "Valeurs", fields: [
      { key: "valeurs", label: "Vos valeurs", type: "textarea",
        def: "Rigueur\nProximité client\nConfidentialité" }
    ] },
    { id: "organisation", title: "Organisation du travail", fields: [
      { key: "organisation", label: "Organisation du travail", type: "textarea",
        def: "Horaires flexibles · Télétravail partiel · Locaux accessibles · Équipe de 24 personnes" }
    ] }
  ];

  document.addEventListener("DOMContentLoaded", function () {
    if (!window.EMP || !EMP.ready) { return; }
    if (!document.getElementById("profil-sections")) { return; }
    fillIdentity();
    renderSections();
  });

  function data() { return SS.store.get(PROFILE_KEY, {}) || {}; }

  function valueOf(field) {
    var stored = data();
    var v = stored[field.key];
    if (typeof v === "string" && v.trim()) { return v; }
    if (v === "") { return ""; }
    return field.def;
  }

  /* Le logo représente l'ENTREPRISE (initiales, ex. « FB »). */
  function fillIdentity() {
    var stored = data();
    var s = SS.auth.get() || {};
    var company = stored.nom || s.company || APP_CONFIG.demoCompany.nom;
    setText("profil-logo", EMP.companyInitials(company));
    setText("profil-name", company);
    setText("profil-sector", stored.secteur || s.secteur || "Finance & Comptabilité");
    setText("profil-city", stored.ville || s.city || "Lyon");
  }

  /* ---- Rendu de toutes les sections ---- */
  function renderSections() {
    var box = document.getElementById("profil-sections");
    box.innerHTML = SECTIONS.map(sectionHtml).join("");
    SECTIONS.forEach(function (sec) { wireSection(sec); });
  }

  function sectionHtml(sec) {
    var e = SS.escapeHtml;
    return '<div class="card dash-card profil-block" data-section="' + sec.id + '">' +
        '<div class="profil-section__head">' +
          "<h3>" + e(sec.title) + "</h3>" +
          '<button type="button" class="btn btn-outline btn-sm" data-edit>Modifier</button>' +
        "</div>" +
        '<div class="profil-block__view">' + viewHtml(sec) + "</div>" +
        '<form class="profil-block__form" hidden>' + formHtml(sec) +
          '<div class="form-actions">' +
            '<button type="submit" class="btn btn-primary btn-sm">Enregistrer</button>' +
            '<button type="button" class="btn btn-ghost btn-sm" data-cancel>Annuler</button>' +
          "</div>" +
        "</form>" +
      "</div>";
  }

  function viewHtml(sec) {
    var e = SS.escapeHtml;
    var cls = "profil-fields" + (sec.fullWidth ? " profil-fields--full" : "");
    return '<dl class="' + cls + '">' + sec.fields.map(function (f) {
      var v = valueOf(f);
      var dd = v ? '<dd>' + e(v) + "</dd>" : '<dd class="text-muted">Non renseigné</dd>';
      return "<div><dt>" + e(f.label) + "</dt>" + dd + "</div>";
    }).join("") + "</dl>";
  }

  function formHtml(sec) {
    var e = SS.escapeHtml;
    return sec.fields.map(function (f) {
      var id = "pf-" + sec.id + "-" + f.key;
      var v = valueOf(f);
      var control;
      if (f.type === "textarea") {
        control = '<textarea id="' + id + '" name="' + e(f.key) + '" rows="4">' + e(v) + "</textarea>";
      } else if (f.type === "select") {
        control = '<select id="' + id + '" name="' + e(f.key) + '">' + f.options.map(function (opt) {
          return '<option value="' + e(opt) + '"' + (opt === v ? " selected" : "") + ">" + e(opt) + "</option>";
        }).join("") + "</select>";
      } else {
        control = '<input type="' + (f.type || "text") + '" id="' + id + '" name="' + e(f.key) + '" value="' + e(v) + '">';
      }
      return '<div class="field"><label for="' + id + '">' + e(f.label) + "</label>" + control + "</div>";
    }).join("");
  }

  /* ---- Édition à la demande (une section à la fois) ---- */
  function wireSection(sec) {
    var block = document.querySelector('.profil-block[data-section="' + sec.id + '"]');
    if (!block) { return; }
    var view = block.querySelector(".profil-block__view");
    var form = block.querySelector(".profil-block__form");
    var editBtn = block.querySelector("[data-edit]");
    var cancelBtn = block.querySelector("[data-cancel]");
    var head = block.querySelector(".profil-section__head");

    /* Actions fixes du formulaire (Enregistrer / Annuler), réinsérées après
       chaque régénération des champs. */
    var actions = form.querySelector(".form-actions");

    function fillForm() {
      /* (Re)génère les champs depuis les valeurs enregistrées, puis remet
         les actions à la fin. */
      form.innerHTML = formHtml(sec);
      form.appendChild(actions);
    }
    function openEdit() {
      fillForm();
      view.hidden = true;
      head.hidden = true;
      form.hidden = false;
      var first = form.querySelector("input, textarea, select");
      if (first) { first.focus(); }
    }
    function closeEdit(refocus) {
      form.hidden = true;
      view.hidden = false;
      head.hidden = false;
      if (refocus) { editBtn.focus(); }
    }

    editBtn.addEventListener("click", openEdit);
    cancelBtn.addEventListener("click", function () { closeEdit(true); });
    form.addEventListener("submit", function (ev) {
      ev.preventDefault();
      var stored = data();
      sec.fields.forEach(function (f) {
        var el = form.elements[f.key];
        if (el) { stored[f.key] = (el.value || "").trim(); }
      });
      SS.store.set(PROFILE_KEY, stored);
      /* Met à jour la vue de la section et, si besoin, l'en-tête d'identité. */
      view.innerHTML = viewHtml(sec);
      if (sec.identity) { fillIdentity(); }
      closeEdit(true);
      SS.toast("Profil enregistré (démonstration).");
    });
  }

  function setText(id, value) {
    var el = document.getElementById(id);
    if (el) { el.textContent = String(value); }
  }
})();

/**
 * Espace candidat (démonstration) : tableau de bord d'un chercheur d'emploi.
 * Session simulée via SS.auth ; toutes les données (candidatures, favoris,
 * alertes, profil, savoir-faire) sont conservées dans le stockage local du
 * navigateur. Aucune donnée réelle n'est envoyée ni enregistrée côté serveur.
 *
 * Sections rendues : #apercu (pilotage : ma recherche, indicateurs,
 * candidatures récentes avec frise, recommandations « pourquoi cette offre »),
 * #profil (profil professionnel + complétion), #candidatures, #favoris,
 * #alertes, #savoir-faire, #entretiens, #messages, #parametres.
 */
(function () {
  "use strict";

  var S = APP_CONFIG.storage;
  /* Clé locale dédiée aux savoir-faire du candidat (prototype) — non déclarée
     dans config.js, réservée à cet espace. */
  var SF_KEY = "ss_candidate_knowhow";

  document.addEventListener("DOMContentLoaded", function () {
    /* 1. Garde d'accès : réservé aux candidats connectés. */
    if (!SS.auth.require("candidate")) { return; }

    /* 2. Identité (avatar, nom, salutation). */
    fillIdentity();

    /* 3. Données de démonstration. */
    seedIfEmpty();

    /* 4. Rendu des sections. */
    renderSearchCriteria();
    renderStatusNotification();
    renderRecentApplications();
    renderApplications();
    initToasts();
    renderRecommendations();
    renderFavorites();
    renderAlerts();
    bindAlertForm();
    renderProfile();
    renderSavoirFaire();
    renderInterviews();
    renderMessages();
    fillSettings();

    /* 5. Navigation latérale + déconnexion. */
    setupNav();
    var logout = document.getElementById("logout-btn");
    if (logout) {
      logout.addEventListener("click", function () { SS.auth.logout(); });
    }

    /* 6. Indicateurs cohérents avec les données. */
    updateMetrics();
  });

  /* ============================================================
     Identité
     ============================================================ */
  function fillIdentity() {
    var s = SS.auth.get() || {};
    setText("dash-avatar", SS.auth.initials());
    setText("dash-name", SS.auth.displayName() || "Candidat");
    setText("hello-name", (s.firstName || SS.auth.displayName() || "").split(" ")[0] || "");
  }

  /* ============================================================
     Données de démonstration (seed au 1er chargement)
     ============================================================ */
  /* Version du jeu de démonstration : à incrémenter dès que la structure des
     candidatures ou du profil change. Les navigateurs ayant un ancien seed en
     cache sont ainsi régénérés, sinon les nouveautés (profil enrichi, message
     reçu, statut « non retenue ») resteraient invisibles. */
  var SEED_VERSION = "2026-08-19-tdb-candidat";
  var SEED_KEY = "ss_seed_version";

  function seedIfEmpty() {
    var staleSeed = SS.store.get(SEED_KEY, null) !== SEED_VERSION;

    if (staleSeed || !SS.store.get(S.applications, null)) {
      SS.store.set(S.applications, defaultApplications());
    }
    if (staleSeed || !SS.store.get(S.candidateProfile, null)) {
      SS.store.set(S.candidateProfile, defaultProfile());
    }
    if (staleSeed || !SS.store.get(SF_KEY, null)) {
      SS.store.set(SF_KEY, defaultSavoirFaire());
    }
    if (staleSeed) { SS.store.set(SEED_KEY, SEED_VERSION); }

    if (!SS.store.get(S.favorites, null)) {
      SS.store.set(S.favorites, [
        "dev-web-junior-pixel-lille",
        "chef-projet-digital-pixel-bordeaux",
        "office-manager-technexis-lille"
      ]);
    }
    if (!SS.store.get(S.alerts, null)) {
      SS.store.set(S.alerts, [
        { metier: "Développeur web", lieu: "Lyon", rayon: "30", contrat: "CDI", teletravail: true, frequence: "quotidienne" }
      ]);
    }
  }

  function defaultApplications() {
    return [
      {
        id: "app-1",
        offreId: "dev-web-junior-pixel-lille",
        offreTitre: "Développeur web junior",
        entrepriseId: "pixel-and-co",
        entreprise: "Pixel & Co",
        ville: "Lille",
        dateEnvoi: "2026-08-06",
        statut: "entretien",
        note: "",
        timeline: [
          { label: "Candidature envoyée", date: "2026-08-06" },
          { label: "Vue par l'entreprise", date: "2026-08-07" },
          { label: "Présélection", date: "2026-08-11" },
          { label: "Entretien proposé", date: "2026-08-14" },
          { label: "Entretien le 21 août à 14 h", date: "2026-08-21", next: true }
        ]
      },
      {
        id: "app-2",
        offreId: "office-manager-technexis-lille",
        offreTitre: "Office manager",
        entrepriseId: "technexis",
        entreprise: "TechNexis",
        ville: "Lille",
        dateEnvoi: "2026-08-09",
        statut: "preselection",
        note: "Poste polyvalent, équipe sympathique repérée sur leur page entreprise.",
        timeline: [
          { label: "Candidature envoyée", date: "2026-08-09" },
          { label: "Vue par l'entreprise", date: "2026-08-10" },
          { label: "Présélection", date: "2026-08-13" },
          { label: "En attente de réponse", date: null, next: true }
        ]
      },
      {
        id: "app-3",
        offreId: "technicien-support-technexis-lille",
        offreTitre: "Technicien support logiciel",
        entrepriseId: "technexis",
        entreprise: "TechNexis",
        ville: "Lille",
        dateEnvoi: "2026-08-12",
        statut: "vue",
        note: "",
        timeline: [
          { label: "Candidature envoyée", date: "2026-08-12" },
          { label: "Vue par l'entreprise", date: "2026-08-13" },
          { label: "En cours d'examen", date: null, next: true }
        ]
      },
      {
        id: "app-4",
        offreId: "assistant-administratif-horizon-nantes",
        offreTitre: "Assistant administratif",
        entrepriseId: "horizon-btp",
        entreprise: "Groupe Horizon BTP",
        ville: "Nantes",
        dateEnvoi: "2026-08-15",
        statut: "envoyee",
        note: "",
        timeline: [
          { label: "Candidature envoyée", date: "2026-08-15" },
          { label: "En attente de lecture", date: null, next: true }
        ]
      },
      {
        id: "app-5",
        offreId: "chef-projet-digital-pixel-bordeaux",
        offreTitre: "Chef de projet digital",
        entrepriseId: "pixel-and-co",
        entreprise: "Pixel & Co",
        ville: "Bordeaux",
        dateEnvoi: "2026-07-28",
        statut: "non-retenue",
        note: "Profil un peu trop junior pour ce poste — à retenter plus tard.",
        messageEntreprise: "Bonjour, nous vous remercions pour votre candidature. Le poste vient malheureusement d'être pourvu. Nous ne manquerons pas de revenir vers vous si une opportunité similaire se présente. Bonne continuation à vous.",
        dateMaj: "2026-08-04",
        timeline: [
          { label: "Candidature envoyée", date: "2026-07-28" },
          { label: "Vue par l'entreprise", date: "2026-07-30" },
          { label: "Candidature non retenue", date: "2026-08-04" }
        ]
      }
    ];
  }

  function defaultProfile() {
    var s = SS.auth.get() || {};
    return {
      metier: s.metier || "Développeur web",
      ville: s.city || "Lyon",
      rayon: "30",
      contrat: "CDI",
      salaireMin: 28000,
      presentation: "Développeur web junior motivé, deux ans d'expérience en intégration et développement front-end. Je cherche un poste en CDI autour de Lyon pour continuer à monter en compétences en équipe.",
      cv: "",
      experiences: "Intégrateur web — Studio Digital Lyon (2024 → 2026)\nStage développement front — Agence Pixel (2023, 4 mois)",
      formation: "BUT Métiers du Multimédia et de l'Internet (MMI) — IUT de Lyon",
      competences: ["HTML / CSS", "JavaScript", "Git", "Travail en équipe"],
      disponibilite: "Immédiate",
      mobilite: "30 km autour de Lyon",
      realisations: "",
      recommandations: "« Jonathan est rigoureux et curieux. Il a su prendre en main nos projets rapidement. » — Responsable technique, Studio Digital Lyon"
    };
  }

  function defaultSavoirFaire() {
    return [
      { id: "sf-1", titre: "Comment je structure un projet web from scratch", resume: "Ma méthode pour démarrer un projet front-end proprement : arborescence, conventions de nommage et outillage minimal.", note: 4.9, avis: 52, vues: 71, date: "2026-07-20" },
      { id: "sf-2", titre: "Déboguer efficacement avec les DevTools", resume: "Les réflexes que j'utilise au quotidien pour retrouver l'origine d'un bug rapidement, sans y passer la journée.", note: 4.7, avis: 38, vues: 44, date: "2026-06-30" },
      { id: "sf-3", titre: "Rendre un site accessible : mes 5 vérifications", resume: "Une check-list concrète pour améliorer l'accessibilité d'un site existant sans tout réécrire.", note: 4.8, avis: 37, vues: 33, date: "2026-06-10" }
    ];
  }

  function getProfile() { return SS.store.get(S.candidateProfile, null) || defaultProfile(); }
  function setProfile(p) { SS.store.set(S.candidateProfile, p); }

  /* ============================================================
     Ma recherche (critères actifs + édition inline)
     ============================================================ */
  function renderSearchCriteria() {
    var box = document.getElementById("search-criteria");
    if (!box) { return; }
    var p = getProfile();
    var e = SS.escapeHtml;
    var lieu = (p.ville || "—") + (p.rayon ? " + " + e(p.rayon) + " km" : "");

    box.innerHTML =
      '<div class="cand-search__head">' +
        '<h2 class="cand-card__title">Ma recherche</h2>' +
        '<button type="button" class="btn btn-outline btn-sm" data-action="edit-search" aria-expanded="false" aria-controls="search-edit">Modifier mes critères</button>' +
      "</div>" +
      '<dl class="cand-search__grid">' +
        "<div><dt>Métier</dt><dd>" + e(p.metier || "À préciser") + "</dd></div>" +
        "<div><dt>Localisation</dt><dd>" + e(lieu) + "</dd></div>" +
        "<div><dt>Type de contrat</dt><dd>" + e(p.contrat || "Tous") + "</dd></div>" +
      "</dl>" +
      '<form id="search-edit" class="cand-search__edit" hidden novalidate>' +
        '<div class="form-row">' +
          '<div class="field"><label for="sc-metier">Métier</label><input id="sc-metier" value="' + e(p.metier || "") + '"></div>' +
          '<div class="field"><label for="sc-ville">Localisation</label><input id="sc-ville" value="' + e(p.ville || "") + '"></div>' +
        "</div>" +
        '<div class="form-row">' +
          '<div class="field"><label for="sc-rayon">Rayon</label><select id="sc-rayon">' +
            rayonOptions(p.rayon) +
          "</select></div>" +
          '<div class="field"><label for="sc-contrat">Type de contrat</label><select id="sc-contrat">' +
            contratOptions(p.contrat) +
          "</select></div>" +
        "</div>" +
        '<div class="form-actions">' +
          '<button type="submit" class="btn btn-primary btn-sm">Enregistrer</button>' +
          '<button type="button" class="btn btn-ghost btn-sm" data-action="cancel-search">Annuler</button>' +
        "</div>" +
      "</form>";

    var editBtn = box.querySelector('[data-action="edit-search"]');
    var form = box.querySelector("#search-edit");
    var cancelBtn = box.querySelector('[data-action="cancel-search"]');

    function toggle(open) {
      form.hidden = !open;
      editBtn.setAttribute("aria-expanded", String(open));
      if (open) { var f = form.querySelector("input"); if (f) { f.focus(); } }
    }
    editBtn.addEventListener("click", function () { toggle(form.hidden); });
    cancelBtn.addEventListener("click", function () { toggle(false); });

    form.addEventListener("submit", function (ev) {
      ev.preventDefault();
      var next = getProfile();
      next.metier = val("sc-metier");
      next.ville = val("sc-ville");
      next.rayon = val("sc-rayon");
      next.contrat = val("sc-contrat");
      setProfile(next);
      renderSearchCriteria();
      renderRecommendations();
      updateMetrics();
      SS.toast("Critères de recherche mis à jour.");
    });
  }

  function rayonOptions(current) {
    return ["10", "30", "50", "100"].map(function (r) {
      return '<option value="' + r + '"' + (String(current) === r ? " selected" : "") + ">" + r + " km</option>";
    }).join("");
  }
  function contratOptions(current) {
    return ["CDI", "CDD", "Intérim", "Alternance", "Tous"].map(function (c) {
      var v = c === "Tous" ? "" : c;
      return '<option value="' + v + '"' + ((current || "") === v ? " selected" : "") + ">" + c + "</option>";
    }).join("");
  }

  /* ============================================================
     Frise de progression (5 étapes)
     ============================================================ */
  var FRISE_STEPS = ["Envoyée", "Consultée", "Présélection", "Entretien", "Décision"];

  function progressionIndex(statut) {
    switch (statut) {
      case "envoyee": return 0;
      case "vue": return 1;
      case "preselection": return 2;
      case "entretien":
      case "entretien-realise": return 3;
      case "offre-recue":
      case "recue":
      case "non-retenue":
      case "refusee":
      case "retiree": return 4;
      default: return 0;
    }
  }

  function decisionOutcome(statut) {
    if (statut === "offre-recue" || statut === "recue") { return "positive"; }
    if (statut === "non-retenue" || statut === "refusee") { return "negative"; }
    if (statut === "retiree") { return "neutral"; }
    return "";
  }

  function friseHtml(statut) {
    var idx = progressionIndex(statut);
    var outcome = decisionOutcome(statut);
    var items = FRISE_STEPS.map(function (label, i) {
      var cls = "frise__step";
      if (i < idx) { cls += " is-done"; }
      else if (i === idx) { cls += " is-current"; }
      /* Dernière étape (Décision) colorée selon l'issue. */
      if (i === 4 && i <= idx && outcome) { cls += " is-" + outcome; }
      return '<li class="' + cls + '"><span class="frise__dot" aria-hidden="true"></span>' +
        '<span class="frise__label">' + label + "</span></li>";
    }).join("");
    var currentLabel = FRISE_STEPS[idx];
    return '<ol class="frise" aria-label="Progression : étape actuelle, ' + currentLabel + '">' + items + "</ol>";
  }

  /* ============================================================
     Candidatures récentes (aperçu, tableau de bord)
     ============================================================ */
  function getApplications() { return SS.store.get(S.applications, []); }

  function renderRecentApplications() {
    var box = document.getElementById("recent-applications");
    if (!box) { return; }
    var apps = getApplications().slice().sort(function (a, b) {
      return new Date(b.dateEnvoi) - new Date(a.dateEnvoi);
    });

    if (!apps.length) {
      box.innerHTML = emptyState("Aucune candidature pour l'instant",
        "Parcourez les offres et postulez : le suivi s'affichera ici.",
        "offres.html", "Trouver des offres");
      return;
    }

    var e = SS.escapeHtml;
    box.innerHTML = apps.slice(0, 3).map(function (a) {
      var statut = a.statut || "envoyee";
      return '<article class="appli-card appli-card--compact">' +
        '<div class="appli-card__top">' +
          "<div><strong>" + e(a.offreTitre) + "</strong><br>" +
          '<span class="text-muted">' + e(a.entreprise) + " — " + e(a.ville) + "</span></div>" +
          '<span class="status-badge status-' + e(statut) + '">' + e(STATUT_LABEL[statut] || statut) + "</span>" +
        "</div>" +
        friseHtml(statut) +
        '<div class="form-actions" style="margin-top: var(--sp-3);">' +
          (a.offreId ? '<a class="btn btn-outline btn-sm" href="offre-detail.html?id=' + encodeURIComponent(a.offreId) + '">Voir l\'offre</a>' : "") +
          '<a class="btn btn-ghost btn-sm" href="#candidatures">Détail du suivi</a>' +
        "</div>" +
      "</article>";
    }).join("");
  }

  /* ============================================================
     Candidatures (liste détaillée)
     ============================================================ */
  /* Vocabulaire de statuts unifié (ordre logique du suivi de candidature).
     Les clés « recue » et « refusee » restent mappées pour d'anciennes
     données éventuellement déjà présentes en stockage local. */
  var STATUT_LABEL = {
    envoyee: "Candidature envoyée",
    vue: "Vue par l'entreprise",
    preselection: "Présélection",
    entretien: "Entretien proposé",
    "entretien-realise": "Entretien réalisé",
    "offre-recue": "Offre reçue",
    "non-retenue": "Candidature non retenue",
    retiree: "Candidature retirée",
    recue: "Offre reçue",
    refusee: "Candidature non retenue"
  };

  function renderApplications() {
    var box = document.getElementById("applications-list");
    if (!box) { return; }
    var apps = getApplications();

    if (!apps.length) {
      box.innerHTML = emptyState("Vous n'avez encore envoyé aucune candidature",
        "Parcourez les offres et postulez pour suivre l'avancement ici.",
        "offres.html", "Trouver des offres");
      return;
    }

    var e = SS.escapeHtml;
    box.innerHTML = apps.map(function (a) {
      var statut = a.statut || "envoyee";
      var timeline = (a.timeline || []).map(function (step) {
        var when = step.date ? " — " + e(SS.formatDate(step.date)) : "";
        return '<li class="' + (step.next ? "is-next" : "") + '">' + e(step.label) + when + "</li>";
      }).join("");

      var noteBlock = a.note
        ? '<p class="notice" data-note style="margin-top: var(--sp-3);"><strong>Ma note :</strong> ' + e(a.note) + "</p>"
        : '<p data-note hidden></p>';

      /* Message courtois reçu de l'entreprise : masqué derrière un disclosure. */
      var messageBlock = a.messageEntreprise
        ? '<div class="msg-disclosure">' +
            '<button type="button" class="btn btn-outline btn-sm" data-action="toggle-message" data-id="' + e(a.id) + '" aria-expanded="false" aria-controls="msg-' + e(a.id) + '">Voir le message</button>' +
            '<div class="msg-disclosure__panel" id="msg-' + e(a.id) + '" hidden>' +
              (a.dateMaj ? '<p class="text-muted" style="margin:0 0 var(--sp-2);">Réponse reçue le ' + e(SS.formatDate(a.dateMaj)) + "</p>" : "") +
              '<p style="margin:0;">' + e(a.messageEntreprise) + "</p>" +
            "</div>" +
          "</div>"
        : "";

      return '<article class="appli-card" data-app="' + e(a.id) + '">' +
        '<div class="appli-card__top">' +
          "<div><strong>" + e(a.offreTitre) + "</strong><br>" +
          '<span class="text-muted">' + e(a.entreprise) + " — " + e(a.ville) +
          " · Envoyée " + e(SS.relativeDate(a.dateEnvoi)) + "</span></div>" +
          '<span class="status-badge status-' + e(statut) + '">' + e(STATUT_LABEL[statut] || statut) + "</span>" +
        "</div>" +
        friseHtml(statut) +
        '<ul class="appli-timeline">' + timeline + "</ul>" +
        noteBlock +
        messageBlock +
        '<div class="form-actions" style="margin-top: var(--sp-3); display:flex; gap: var(--sp-2); flex-wrap: wrap;">' +
          (a.offreId ? '<a class="btn btn-outline btn-sm" href="offre-detail.html?id=' + encodeURIComponent(a.offreId) + '">Voir l\'offre</a>' : "") +
          (a.entrepriseId ? '<a class="btn btn-ghost btn-sm" href="entreprise-detail.html?id=' + encodeURIComponent(a.entrepriseId) + '">Voir l\'entreprise</a>' : "") +
          '<a class="btn btn-ghost btn-sm" href="#messages">Envoyer un message</a>' +
          '<button type="button" class="btn btn-ghost btn-sm" data-action="note" data-id="' + e(a.id) + '">Ajouter une note</button>' +
          '<button type="button" class="btn btn-ghost btn-sm" data-action="withdraw" data-id="' + e(a.id) + '">Retirer ma candidature</button>' +
        "</div>" +
        '<div data-note-editor hidden style="margin-top: var(--sp-3);">' +
          '<div class="field"><label for="note-' + e(a.id) + '">Note personnelle</label>' +
          '<textarea id="note-' + e(a.id) + '">' + e(a.note || "") + "</textarea></div>" +
          '<button type="button" class="btn btn-primary btn-sm" data-action="save-note" data-id="' + e(a.id) + '">Enregistrer la note</button>' +
        "</div>" +
      "</article>";
    }).join("");

    box.querySelectorAll("button[data-action]").forEach(function (btn) {
      btn.addEventListener("click", function () { onAppAction(btn); });
    });
  }

  function onAppAction(btn) {
    var id = btn.getAttribute("data-id");
    var action = btn.getAttribute("data-action");
    var card = btn.closest(".appli-card");

    if (action === "withdraw") {
      var apps = getApplications().filter(function (a) { return a.id !== id; });
      SS.store.set(S.applications, apps);
      renderApplications();
      renderRecentApplications();
      updateMetrics();
      SS.toast("Candidature retirée.");
      return;
    }

    if (action === "note") {
      var editor = card.querySelector("[data-note-editor]");
      if (editor) { editor.hidden = !editor.hidden; }
      return;
    }

    if (action === "toggle-message") {
      var panel = card.querySelector(".msg-disclosure__panel");
      if (panel) {
        var wasHidden = panel.hidden;
        panel.hidden = !wasHidden;
        btn.setAttribute("aria-expanded", String(wasHidden));
        btn.textContent = wasHidden ? "Masquer le message" : "Voir le message";
      }
      return;
    }

    if (action === "save-note") {
      var ta = card.querySelector("textarea");
      var value = ta ? ta.value.trim() : "";
      var list = getApplications().map(function (a) {
        if (a.id === id) { a.note = value; }
        return a;
      });
      SS.store.set(S.applications, list);
      renderApplications();
      SS.toast("Note enregistrée.");
    }
  }

  /* Bandeau d'aperçu : signale une candidature dont le statut a évolué. */
  function renderStatusNotification() {
    var box = document.getElementById("status-notification");
    if (!box) { return; }
    var updated = getApplications().filter(function (a) {
      return a.statut === "non-retenue" || a.statut === "offre-recue" || a.statut === "refusee";
    });
    if (!updated.length) { box.innerHTML = ""; return; }
    var a = updated[0];
    var e = SS.escapeHtml;
    box.innerHTML =
      '<div class="notice notice--demo" style="margin-bottom: var(--sp-4);">' +
        "<strong>Votre candidature chez " + e(a.entreprise) + " a été mise à jour.</strong> " +
        '<a href="#candidatures">Voir mes candidatures</a>' +
      "</div>";
  }

  /* Boutons fictifs (data-toast) de l'espace candidat (ex. « Répondre »). */
  function initToasts() {
    document.addEventListener("click", function (ev) {
      var btn = ev.target.closest ? ev.target.closest("[data-toast]") : null;
      if (btn) { SS.toast(btn.getAttribute("data-toast")); }
    });
  }

  /* ============================================================
     Recommandations (offres actives priorisées + « pourquoi »)
     ============================================================ */
  function normalize(str) {
    return (str || "").toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "");
  }

  /* Distances routières approximatives depuis les grandes villes de la démo
     jusqu'à Lyon (km). Sert à expliquer factuellement l'éloignement d'une
     offre par rapport à la localisation du candidat. */
  var DIST_LYON = {
    lyon: 0, villeurbanne: 5, "saint-etienne": 60, grenoble: 105, valence: 100,
    paris: 465, lille: 690, bordeaux: 555, nantes: 630, rennes: 700,
    strasbourg: 490, toulouse: 535, montpellier: 300, marseille: 315
  };

  function distanceFromProfile(ville, profileVille) {
    var from = normalize(profileVille);
    var to = normalize(ville);
    if (from === to) { return 0; }
    /* On ne sait calculer que depuis Lyon dans ce prototype. */
    if (from === "lyon" && DIST_LYON[to] != null) { return DIST_LYON[to]; }
    return null;
  }

  function metierMatches(offer, profile) {
    var tokens = normalize(profile.metier).split(/[^a-z0-9]+/).filter(function (t) { return t.length >= 3; });
    var hay = normalize(offer.titre + " " + (offer.categorieLabel || ""));
    var byToken = tokens.some(function (t) { return hay.indexOf(t) !== -1; });
    /* Rapprochement par famille de métier : un développeur voit l'ensemble
       des offres « Informatique & Digital ». */
    var byCategory = normalize(offer.categorieLabel).indexOf("informatique") !== -1 &&
      tokens.some(function (t) { return ["developpeur", "developpeuse", "web", "informatique", "digital", "developpement"].indexOf(t) !== -1; });
    return byToken || byCategory;
  }

  function offerReasons(offer, profile) {
    var reasons = [];
    var e = SS.escapeHtml;

    /* Métier */
    reasons.push({
      ok: metierMatches(offer, profile),
      text: metierMatches(offer, profile)
        ? "Métier correspondant à votre recherche"
        : "Métier différent de votre recherche"
    });

    /* Distance */
    var km = distanceFromProfile(offer.ville, profile.ville);
    var rayon = parseInt(profile.rayon, 10) || 30;
    if (km === 0) {
      reasons.push({ ok: true, text: "Dans votre ville (" + e(profile.ville) + ")" });
    } else if (km != null) {
      reasons.push({
        ok: km <= rayon,
        text: km <= rayon
          ? "À moins de " + rayon + " km de votre localisation"
          : "À environ " + km + " km de " + e(profile.ville) + " (au-delà de votre rayon de " + rayon + " km)"
      });
    } else {
      reasons.push({ ok: true, text: "À " + e(offer.ville) });
    }

    /* Contrat */
    var wantContrat = (profile.contrat || "").trim();
    if (wantContrat) {
      reasons.push({
        ok: offer.contrat === wantContrat,
        text: offer.contrat === wantContrat
          ? wantContrat + " recherché"
          : "Contrat " + e(offer.contrat) + " (vous cherchez un " + e(wantContrat) + ")"
      });
    }

    /* Salaire */
    if (profile.salaireMin && offer.salaireAnnuel) {
      reasons.push({
        ok: offer.salaireAnnuel >= profile.salaireMin,
        text: offer.salaireAnnuel >= profile.salaireMin
          ? "Salaire dans vos critères (dès " + Math.round(profile.salaireMin / 1000) + " k€ souhaités)"
          : "Salaire un peu sous vos attentes"
      });
    }

    /* Compétences manquantes dans le profil */
    var missing = missingCompetences(offer.competences || [], profile.competences || []);
    if (missing.length) {
      reasons.push({ ok: false, text: "Une compétence demandée à valoriser : " + e(missing[0]) });
    } else if ((offer.competences || []).length) {
      reasons.push({ ok: true, text: "Vos compétences couvrent le poste" });
    }

    return reasons;
  }

  function missingCompetences(offerComp, profileComp) {
    var have = profileComp.map(normalize);
    return offerComp.filter(function (c) {
      var n = normalize(c);
      /* Considérée acquise si un mot du libellé recoupe une compétence du profil. */
      return !have.some(function (h) {
        return h.indexOf(n) !== -1 || n.indexOf(h) !== -1 ||
          n.split(/[^a-z0-9]+/).some(function (w) { return w.length >= 3 && h.indexOf(w) !== -1; });
      });
    });
  }

  var ICON_CHECK = '<svg class="why__icon why__icon--ok" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M4 10.5l4 4 8-9" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  var ICON_TRI = '<svg class="why__icon why__icon--warn" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M10 3l7.5 13H2.5L10 3z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M10 8.5v3.2M10 13.6v.1" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';

  function renderRecommendations() {
    var box = document.getElementById("reco-list");
    if (!box) { return; }

    SS.getActiveOffers().then(function (offers) {
      var profile = getProfile();

      var matches = offers.filter(function (o) { return metierMatches(o, profile); });
      var pool = matches.length ? matches : offers.slice();
      pool.sort(function (a, b) { return new Date(b.datePublication) - new Date(a.datePublication); });

      box.dataset.recoCount = String(matches.length || pool.length);
      updateMetrics();

      var top = pool.slice(0, 4);
      if (!top.length) {
        box.innerHTML = '<div class="empty-state"><p>Aucune offre à recommander pour le moment.</p></div>';
        return;
      }

      var favorites = SS.store.get(S.favorites, []);
      var e = SS.escapeHtml;
      box.innerHTML = top.map(function (o) {
        var remote = SS.teletravailLabel(o.teletravail);
        var isFav = favorites.indexOf(o.id) !== -1;
        var reasons = offerReasons(o, profile);
        var reasonsHtml = reasons.map(function (r) {
          return '<li class="why__item ' + (r.ok ? "is-ok" : "is-warn") + '">' +
            (r.ok ? ICON_CHECK : ICON_TRI) + "<span>" + r.text + "</span></li>";
        }).join("");

        return '<article class="reco-card">' +
          '<div class="reco-card__head">' +
            "<strong>" + e(o.titre) + "</strong>" +
            '<button type="button" class="fav-btn" data-fav="' + e(o.id) + '" aria-pressed="' + isFav + '" aria-label="' +
              (isFav ? "Retirer des favoris" : "Enregistrer en favori") + '">' + (isFav ? "♥" : "♡") + "</button>" +
          "</div>" +
          '<span class="text-muted">' + e(o.entrepriseNom) + " · " + e(o.ville) + "</span>" +
          '<div class="reco-card__tags">' +
            '<span class="badge badge--accent">' + e(o.contrat) + "</span>" +
            (remote ? '<span class="badge badge--remote">' + e(remote) + "</span>" : "") +
          "</div>" +
          '<span class="reco-card__salaire">' + e(o.salaire || "") + "</span>" +
          '<div class="msg-disclosure">' +
            '<button type="button" class="btn btn-ghost btn-sm why__toggle" data-action="why" aria-expanded="false" aria-controls="why-' + e(o.id) + '">Pourquoi cette offre ?</button>' +
            '<div class="msg-disclosure__panel why__panel" id="why-' + e(o.id) + '" hidden>' +
              '<ul class="why__list">' + reasonsHtml + "</ul>" +
            "</div>" +
          "</div>" +
          '<a class="btn btn-outline btn-sm reco-card__cta" href="offre-detail.html?id=' + encodeURIComponent(o.id) + '">Voir l\'offre</a>' +
        "</article>";
      }).join("");

      box.querySelectorAll("button[data-fav]").forEach(function (btn) {
        btn.addEventListener("click", function () { toggleFavorite(btn.getAttribute("data-fav")); });
      });
      box.querySelectorAll('[data-action="why"]').forEach(function (btn) {
        btn.addEventListener("click", function () {
          var panel = document.getElementById(btn.getAttribute("aria-controls"));
          if (!panel) { return; }
          var wasHidden = panel.hidden;
          panel.hidden = !wasHidden;
          btn.setAttribute("aria-expanded", String(wasHidden));
          btn.textContent = wasHidden ? "Masquer les raisons" : "Pourquoi cette offre ?";
        });
      });
    }).catch(function () {
      SS.dataError(box);
    });
  }

  function toggleFavorite(id) {
    var favorites = SS.store.get(S.favorites, []);
    var i = favorites.indexOf(id);
    if (i === -1) { favorites.push(id); SS.toast("Offre ajoutée à vos favoris."); }
    else { favorites.splice(i, 1); SS.toast("Offre retirée de vos favoris."); }
    SS.store.set(S.favorites, favorites);
    renderRecommendations();
    renderFavorites();
    updateMetrics();
  }

  /* ============================================================
     Favoris
     ============================================================ */
  function renderFavorites() {
    var box = document.getElementById("favorites-list");
    if (!box) { return; }
    var favorites = SS.store.get(S.favorites, []);

    if (!favorites.length) {
      box.innerHTML = emptyState("Aucun favori pour l'instant",
        "Cliquez sur le cœur d'une offre pour la retrouver ici.",
        "offres.html", "Parcourir les offres");
      return;
    }

    SS.getOffers().then(function (offers) {
      var byId = {};
      offers.forEach(function (o) { byId[o.id] = o; });
      var e = SS.escapeHtml;
      var soon = new Date();
      soon.setDate(soon.getDate() + 7);

      var html = favorites.map(function (id) {
        var o = byId[id];
        if (!o) { return ""; }
        var expiresSoon = o.dateExpiration && new Date(o.dateExpiration) <= soon;
        var expired = o.statut === "expiree";
        return '<article class="card" style="padding: var(--sp-4); display:flex; justify-content:space-between; gap: var(--sp-3); align-items:center; flex-wrap:wrap;">' +
          "<div><strong>" + e(o.titre) + "</strong><br>" +
          '<span class="text-muted">' + e(o.entrepriseNom) + " · " + e(o.ville) + " — " + e(o.contrat) + "</span>" +
          (expired ? ' <span class="badge badge--expired">Expirée</span>'
            : expiresSoon ? ' <span class="badge badge--expired">Cette offre expire bientôt</span>' : "") +
          "</div>" +
          '<div style="display:flex; gap: var(--sp-2); flex-wrap:wrap;">' +
            '<a class="btn btn-outline btn-sm" href="offre-detail.html?id=' + encodeURIComponent(o.id) + '">Voir l\'offre</a>' +
            '<button type="button" class="btn btn-ghost btn-sm" data-unfav="' + e(o.id) + '">Retirer</button>' +
          "</div>" +
        "</article>";
      }).join("");

      box.innerHTML = html || '<div class="empty-state"><p>Vos offres favorites ne sont plus disponibles.</p></div>';
      box.querySelectorAll("[data-unfav]").forEach(function (btn) {
        btn.style.marginTop = "0";
        btn.addEventListener("click", function () { toggleFavorite(btn.getAttribute("data-unfav")); });
      });
    }).catch(function () { SS.dataError(box); });
  }

  /* ============================================================
     Alertes emploi (avec fréquence de notification)
     ============================================================ */
  var FREQ_LABEL = { immediate: "Immédiatement", quotidienne: "Chaque jour", hebdomadaire: "Chaque semaine" };

  function renderAlerts() {
    var box = document.getElementById("alerts-list");
    if (!box) { return; }
    var alerts = SS.store.get(S.alerts, []);

    if (!alerts.length) {
      box.innerHTML = '<div class="empty-state"><p>Aucune alerte enregistrée. Créez-en une ci-dessus pour être prévenu(e) des nouvelles offres.</p></div>';
      return;
    }

    var e = SS.escapeHtml;
    box.innerHTML = alerts.map(function (al, i) {
      var parts = [];
      if (al.metier) { parts.push(e(al.metier)); }
      if (al.lieu) { parts.push(e(al.lieu) + (al.rayon ? " + " + e(al.rayon) + " km" : "")); }
      if (al.contrat) { parts.push(e(al.contrat)); }
      if (al.teletravail) { parts.push("Télétravail"); }
      var fresh = ((i + 3) % 5) + 1; /* nombre fictif mais stable */
      var freq = al.frequence || "quotidienne";
      var freqOptions = Object.keys(FREQ_LABEL).map(function (k) {
        return '<option value="' + k + '"' + (k === freq ? " selected" : "") + ">" + FREQ_LABEL[k] + "</option>";
      }).join("");

      return '<article class="card alert-card">' +
        '<div class="alert-card__main">' +
          "<div><strong>" + (parts.join(" — ") || "Alerte") + "</strong><br>" +
          '<span class="badge badge--accent">' + fresh + " nouvelle" + (fresh > 1 ? "s" : "") + " offre" + (fresh > 1 ? "s" : "") + " depuis votre dernière visite</span></div>" +
          "<span class=\"status-badge status-vue\">Alerte activée</span>" +
        "</div>" +
        '<div class="alert-card__foot">' +
          '<div class="field field--inline"><label for="freq-' + i + '">Fréquence</label>' +
            '<select id="freq-' + i + '" data-freq="' + i + '">' + freqOptions + "</select></div>" +
          '<button type="button" class="btn btn-ghost btn-sm" data-del-alert="' + i + '">Supprimer</button>' +
        "</div>" +
      "</article>";
    }).join("");

    box.querySelectorAll("[data-del-alert]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var idx = parseInt(btn.getAttribute("data-del-alert"), 10);
        var list = SS.store.get(S.alerts, []);
        list.splice(idx, 1);
        SS.store.set(S.alerts, list);
        renderAlerts();
        SS.toast("Alerte supprimée.");
      });
    });
    box.querySelectorAll("[data-freq]").forEach(function (sel) {
      sel.addEventListener("change", function () {
        var idx = parseInt(sel.getAttribute("data-freq"), 10);
        var list = SS.store.get(S.alerts, []);
        if (list[idx]) { list[idx].frequence = sel.value; SS.store.set(S.alerts, list); }
        SS.toast("Fréquence mise à jour : " + (FREQ_LABEL[sel.value] || sel.value).toLowerCase() + ".");
      });
    });
  }

  function bindAlertForm() {
    var form = document.getElementById("alert-form");
    if (!form) { return; }
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      var alert = {
        metier: val("alert-metier"),
        lieu: val("alert-lieu"),
        rayon: val("alert-rayon"),
        contrat: val("alert-contrat"),
        teletravail: document.getElementById("alert-teletravail").checked,
        frequence: val("alert-frequence") || "quotidienne"
      };
      if (!alert.metier && !alert.lieu) {
        SS.toast("Indiquez au moins un métier ou une localisation.");
        return;
      }
      var list = SS.store.get(S.alerts, []);
      list.push(alert);
      SS.store.set(S.alerts, list);
      form.reset();
      renderAlerts();
      SS.toast("Alerte créée — vous serez prévenu(e) des nouvelles offres.");
    });
  }

  /* ============================================================
     Profil professionnel (complétion + sections)
     ============================================================ */
  /* Champs pondérés (total = 100). Sert à calculer la complétion et à
     proposer des conseils actionnables. */
  var PROFILE_FIELDS = [
    { key: "presentation", label: "Présentation", type: "textarea", weight: 15, tip: "Compléter votre présentation", placeholder: "Quelques lignes sur votre parcours et vos objectifs…" },
    { key: "cv", label: "CV", type: "text", weight: 15, tip: "Ajouter votre CV", placeholder: "Lien ou nom de votre fichier CV" },
    { key: "experiences", label: "Expériences", type: "textarea", weight: 12, tip: "Détailler vos expériences", placeholder: "Vos expériences professionnelles" },
    { key: "formation", label: "Formation", type: "text", weight: 10, tip: "Ajouter votre formation", placeholder: "Votre diplôme le plus élevé" },
    { key: "competences", label: "Compétences", type: "tags", weight: 12, tip: "Ajouter vos compétences", placeholder: "HTML, CSS, JavaScript, travail en équipe…" },
    { key: "disponibilite", label: "Disponibilité", type: "text", weight: 8, tip: "Préciser votre disponibilité", placeholder: "Ex. immédiate, sous 1 mois…" },
    { key: "mobilite", label: "Mobilité", type: "text", weight: 8, tip: "Indiquer votre mobilité", placeholder: "Ex. 30 km autour de Lyon" },
    { key: "realisations", label: "Réalisations", type: "textarea", weight: 10, tip: "Présenter une réalisation", placeholder: "Un projet ou une réussite dont vous êtes fier(e)" }
  ];

  function fieldFilled(profile, f) {
    var v = profile[f.key];
    if (f.type === "tags") { return Array.isArray(v) && v.length > 0; }
    return !!(v && String(v).trim());
  }

  function computeCompletion(profile) {
    var pct = 0;
    var missing = [];
    PROFILE_FIELDS.forEach(function (f) {
      if (fieldFilled(profile, f)) { pct += f.weight; }
      else { missing.push(f); }
    });
    /* Savoir-faire : +5 % si au moins un est publié. */
    var hasSF = SS.store.get(SF_KEY, []).length > 0;
    if (hasSF) { pct += 5; } else { missing.push({ key: "savoirFaire", label: "Savoir-faire", weight: 5, link: "#savoir-faire", verb: "Publier un savoir-faire" }); }
    /* Recommandation : +5 % si au moins une. */
    if (profile.recommandations && String(profile.recommandations).trim()) { pct += 5; }
    else { missing.push({ key: "recommandations", label: "Recommandation", weight: 5, verb: "Ajouter une recommandation" }); }
    return { pct: Math.min(100, pct), missing: missing };
  }

  function renderProfile() {
    renderProfileCompletion();
    renderProfileSections();
  }

  function renderProfileCompletion() {
    var box = document.getElementById("profile-completion");
    if (!box) { return; }
    var profile = getProfile();
    var res = computeCompletion(profile);
    var e = SS.escapeHtml;

    var tips = res.missing.slice().sort(function (a, b) { return b.weight - a.weight; }).slice(0, 3);
    var tipsHtml = tips.map(function (f) {
      var label = f.tip || f.verb || ("Compléter : " + f.label);
      var target = f.link || "";
      var content = "→ +" + f.weight + " %";
      return "<li>" + (target
        ? '<a href="' + target + '">' + e(label) + "</a> " + content
        : "<span>" + e(label) + "</span> " + content) + "</li>";
    }).join("");

    box.innerHTML =
      '<div class="profile-meter" aria-hidden="true"><span style="width:' + res.pct + '%"></span></div>' +
      '<p class="profile-meter__label">Profil complété à <strong>' + res.pct + ' %</strong></p>' +
      (tips.length
        ? '<div class="profile-tips"><p class="profile-tips__title">Pour aller plus loin :</p><ul>' + tipsHtml + "</ul></div>"
        : '<p class="notice notice--demo" style="margin-top:var(--sp-3);">Bravo, votre profil est complet !</p>');
  }

  function renderProfileSections() {
    var box = document.getElementById("profile-sections");
    if (!box) { return; }
    var profile = getProfile();
    var e = SS.escapeHtml;

    var rows = PROFILE_FIELDS.map(function (f) {
      var filled = fieldFilled(profile, f);
      var display;
      if (!filled) { display = '<span class="profile-row__empty">Non renseigné</span>'; }
      else if (f.type === "tags") {
        display = '<div class="profile-row__tags">' + profile[f.key].map(function (c) {
          return '<span class="badge badge--neutral">' + e(c) + "</span>";
        }).join("") + "</div>";
      } else {
        display = '<span class="profile-row__value">' + e(profile[f.key]).replace(/\n/g, "<br>") + "</span>";
      }

      var inputHtml = f.type === "textarea"
        ? '<textarea id="pf-' + f.key + '" rows="3" placeholder="' + e(f.placeholder || "") + '">' + (f.type === "tags" ? "" : e(profile[f.key] || "")) + "</textarea>"
        : '<input id="pf-' + f.key + '" value="' + (f.type === "tags" ? e((profile[f.key] || []).join(", ")) : e(profile[f.key] || "")) + '" placeholder="' + e(f.placeholder || "") + '">';

      return '<div class="profile-row" data-key="' + f.key + '">' +
        '<div class="profile-row__head">' +
          "<h3>" + e(f.label) + "</h3>" +
          '<button type="button" class="btn btn-ghost btn-sm" data-action="edit-field" data-key="' + f.key + '" aria-expanded="false">' +
            (filled ? "Modifier" : "Ajouter") + "</button>" +
        "</div>" +
        '<div class="profile-row__body">' + display + "</div>" +
        '<div class="profile-row__edit" hidden>' +
          '<div class="field">' +
            '<label for="pf-' + f.key + '">' + e(f.label) + (f.type === "tags" ? " (séparées par des virgules)" : "") + "</label>" +
            inputHtml +
          "</div>" +
          '<div class="form-actions">' +
            '<button type="button" class="btn btn-primary btn-sm" data-action="save-field" data-key="' + f.key + '">Enregistrer</button>' +
            '<button type="button" class="btn btn-ghost btn-sm" data-action="cancel-field" data-key="' + f.key + '">Annuler</button>' +
          "</div>" +
        "</div>" +
      "</div>";
    }).join("");

    /* Deux sections en lecture seule : savoir-faire (édités via la page dédiée)
       et recommandations. */
    var sfCount = SS.store.get(SF_KEY, []).length;
    var sfRow = '<div class="profile-row">' +
      '<div class="profile-row__head"><h3>Savoir-faire</h3>' +
      '<a class="btn btn-ghost btn-sm" href="#savoir-faire">Gérer</a></div>' +
      '<div class="profile-row__body">' + (sfCount
        ? '<span class="profile-row__value">' + sfCount + " savoir-faire publié" + (sfCount > 1 ? "s" : "") + " — visibles par les recruteurs.</span>"
        : '<span class="profile-row__empty">Aucun savoir-faire publié pour l\'instant.</span>') + "</div></div>";

    var recoRow = '<div class="profile-row">' +
      '<div class="profile-row__head"><h3>Recommandations</h3>' +
      '<button type="button" class="btn btn-ghost btn-sm" data-action="edit-field" data-key="recommandations" aria-expanded="false">' +
        (profile.recommandations ? "Modifier" : "Ajouter") + "</button></div>" +
      '<div class="profile-row__body">' + (profile.recommandations
        ? '<span class="profile-row__value">' + e(profile.recommandations).replace(/\n/g, "<br>") + "</span>"
        : '<span class="profile-row__empty">Non renseigné</span>') + "</div>" +
      '<div class="profile-row__edit" hidden>' +
        '<div class="field"><label for="pf-recommandations">Recommandation d\'un ancien employeur ou collègue</label>' +
        '<textarea id="pf-recommandations" rows="3">' + e(profile.recommandations || "") + "</textarea></div>" +
        '<div class="form-actions">' +
          '<button type="button" class="btn btn-primary btn-sm" data-action="save-field" data-key="recommandations">Enregistrer</button>' +
          '<button type="button" class="btn btn-ghost btn-sm" data-action="cancel-field" data-key="recommandations">Annuler</button>' +
        "</div></div></div>";

    box.innerHTML = rows + sfRow + recoRow;

    box.querySelectorAll("[data-action]").forEach(function (btn) {
      btn.addEventListener("click", function () { onProfileAction(btn); });
    });
  }

  function onProfileAction(btn) {
    var action = btn.getAttribute("data-action");
    var key = btn.getAttribute("data-key");
    var row = btn.closest(".profile-row");
    if (!row) { return; }
    var editor = row.querySelector(".profile-row__edit");
    var toggle = row.querySelector('[data-action="edit-field"]');

    if (action === "edit-field") {
      var open = editor.hidden;
      editor.hidden = !open;
      btn.setAttribute("aria-expanded", String(open));
      if (open) { var f = editor.querySelector("input, textarea"); if (f) { f.focus(); } }
      return;
    }
    if (action === "cancel-field") {
      editor.hidden = true;
      if (toggle) { toggle.setAttribute("aria-expanded", "false"); }
      return;
    }
    if (action === "save-field") {
      var input = document.getElementById("pf-" + key);
      var profile = getProfile();
      var fieldDef = PROFILE_FIELDS.filter(function (x) { return x.key === key; })[0];
      if (fieldDef && fieldDef.type === "tags") {
        profile[key] = input.value.split(",").map(function (s) { return s.trim(); }).filter(Boolean);
      } else {
        profile[key] = input.value.trim();
      }
      setProfile(profile);
      renderProfile();
      /* Le métier / la localisation du profil alimentent aussi la recherche. */
      SS.toast("Profil mis à jour.");
    }
  }

  /* ============================================================
     Mes savoir-faire (fonctionnalité différenciante)
     ============================================================ */
  function renderSavoirFaire() {
    var aggBox = document.getElementById("savoirfaire-aggregate");
    var listBox = document.getElementById("savoirfaire-list");
    if (!listBox) { return; }
    var list = SS.store.get(SF_KEY, []);
    var e = SS.escapeHtml;

    if (!list.length) {
      if (aggBox) { aggBox.innerHTML = ""; }
      listBox.innerHTML =
        '<div class="empty-state"><h3>Montrez ce que vous savez faire</h3>' +
        "<p>Publiez votre premier savoir-faire : une méthode, un tour de main, un retour d'expérience. " +
        "C'est ce qui vous distingue des autres candidats.</p>" +
        '<p><a class="btn btn-primary" href="publier-savoir-faire.html?type=candidat">Publier un savoir-faire</a></p></div>';
      return;
    }

    if (aggBox) {
      var totalUseful = list.reduce(function (sum, sf) { return sum + (sf.avis || 0); }, 0);
      var avg = list.reduce(function (sum, sf) { return sum + (sf.note || 0); }, 0) / list.length;
      var avgStr = avg.toFixed(1).replace(".", ",");
      aggBox.innerHTML =
        '<div class="sf-aggregate">' +
          '<div class="sf-aggregate__stat"><b>' + list.length + "</b><span>savoir-faire publié" + (list.length > 1 ? "s" : "") + "</span></div>" +
          '<div class="sf-aggregate__stat"><b>' + avgStr + "/5</b><span>note moyenne</span></div>" +
          '<div class="sf-aggregate__stat"><b>' + totalUseful + "</b><span>personnes ont trouvé vos contenus utiles</span></div>" +
        "</div>";
    }

    listBox.innerHTML = list.map(function (sf) {
      return '<article class="sf-card">' +
        '<div class="sf-card__body">' +
          "<h3>" + e(sf.titre) + "</h3>" +
          '<p class="text-muted">' + e(sf.resume) + "</p>" +
          '<div class="sf-card__meta">' +
            '<span class="badge badge--accent">' + String(sf.note).replace(".", ",") + "/5</span>" +
            "<span>" + (sf.avis || 0) + " avis</span>" +
            "<span>" + (sf.vues || 0) + " vues</span>" +
            "<span>Publié le " + e(SS.formatDate(sf.date)) + "</span>" +
          "</div>" +
        "</div>" +
        '<div class="sf-card__actions">' +
          '<a class="btn btn-outline btn-sm" href="savoir-faire.html">Voir la page publique</a>' +
          '<a class="btn btn-ghost btn-sm" href="publier-savoir-faire.html?type=candidat">Modifier</a>' +
        "</div>" +
      "</article>";
    }).join("");
  }

  /* ============================================================
     Mes entretiens
     ============================================================ */
  var CONFIRM_KEY = "ss_cand_interviews_confirmed";

  function defaultInterviews() {
    return [
      { id: "civ1", date: "2026-08-21", heure: "14:00", poste: "Développeur web junior", entreprise: "Pixel & Co", entrepriseId: "pixel-and-co", offreId: "dev-web-junior-pixel-lille", mode: "Visioconférence", statut: "propose" },
      { id: "civ2", date: "2026-08-26", heure: "10:30", poste: "Office manager", entreprise: "TechNexis", entrepriseId: "technexis", offreId: "office-manager-technexis-lille", mode: "Sur site — Lille", statut: "confirme" }
    ];
  }

  function renderInterviews() {
    var box = document.getElementById("interviews-list");
    if (!box) { return; }
    var list = defaultInterviews();
    var e = SS.escapeHtml;
    var confirmed = SS.store.get(CONFIRM_KEY, {});

    if (!list.length) {
      box.innerHTML = '<div class="empty-state"><h3>Aucun entretien programmé</h3>' +
        "<p>Vos rendez-vous avec les entreprises apparaîtront ici dès qu'un entretien sera fixé.</p></div>";
      return;
    }

    box.innerHTML = list.map(function (it) {
      var jour = new Date(it.date);
      var jourLabel = ["dimanche", "lundi", "mardi", "mercredi", "jeudi", "vendredi", "samedi"][jour.getDay()];
      var isConfirmed = it.statut === "confirme" || confirmed[it.id];
      var badge = isConfirmed
        ? '<span class="status-badge status-preselection">Confirmé</span>'
        : '<span class="status-badge status-envoyee">À confirmer</span>';
      var confirmBtn = isConfirmed ? "" :
        '<button type="button" class="btn btn-primary btn-sm" data-confirm-iv="' + e(it.id) + '">Confirmer ce rendez-vous</button>';
      return '<article class="interview-card">' +
        '<div class="interview-card__date"><span class="interview-card__day">' + e(jourLabel) + "</span>" +
          "<b>" + e(SS.formatDate(it.date)) + "</b><span>" + e(it.heure) + "</span></div>" +
        '<div class="interview-card__info">' +
          "<strong>" + e(it.poste) + "</strong> " + badge + "<br>" +
          '<span class="text-muted">' + e(it.entreprise) + "</span>" +
          '<div class="interview-card__mode"><span class="badge badge--remote">' + e(it.mode) + "</span></div>" +
        "</div>" +
        '<div class="interview-card__actions">' +
          confirmBtn +
          '<a class="btn btn-outline btn-sm" href="offre-detail.html?id=' + encodeURIComponent(it.offreId) + '">Voir l\'offre</a>' +
          '<a class="btn btn-ghost btn-sm" href="#messages">Message</a>' +
        "</div>" +
      "</article>";
    }).join("");

    box.querySelectorAll("[data-confirm-iv]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var id = btn.getAttribute("data-confirm-iv");
        var c = SS.store.get(CONFIRM_KEY, {});
        c[id] = true;
        SS.store.set(CONFIRM_KEY, c);
        renderInterviews();
        SS.toast("Rendez-vous confirmé — l'entreprise en est informée.");
      });
    });
  }

  /* ============================================================
     Messages (messagerie simple, 2 conversations démo)
     ============================================================ */
  function defaultConversations() {
    return [
      {
        id: "conv-pixel", entreprise: "Pixel & Co", poste: "Développeur web junior", entrepriseId: "pixel-and-co",
        messages: [
          { from: "them", date: "2026-08-14", text: "Bonjour Jonathan, nous avons bien reçu votre candidature et souhaiterions vous rencontrer. Seriez-vous disponible le 21 août à 14 h en visioconférence ?" },
          { from: "me", date: "2026-08-14", text: "Bonjour, merci beaucoup ! Le 21 août à 14 h me convient parfaitement." }
        ]
      },
      {
        id: "conv-technexis", entreprise: "TechNexis", poste: "Office manager", entrepriseId: "technexis",
        messages: [
          { from: "them", date: "2026-08-13", text: "Bonjour, votre profil a retenu notre attention pour le poste d'office manager. Pourriez-vous nous préciser vos disponibilités pour un premier échange ?" }
        ]
      }
    ];
  }

  var _conversations = null;
  var _activeConv = null;

  function renderMessages() {
    var box = document.getElementById("messages-app");
    if (!box) { return; }
    _conversations = _conversations || defaultConversations();

    if (!_conversations.length) {
      box.innerHTML = '<div class="empty-state"><p>Aucune conversation pour le moment.</p></div>';
      return;
    }
    if (!_activeConv) { _activeConv = _conversations[0].id; }

    var e = SS.escapeHtml;
    var listHtml = _conversations.map(function (c) {
      var last = c.messages[c.messages.length - 1];
      var active = c.id === _activeConv;
      return '<button type="button" class="msg-conv' + (active ? " is-active" : "") + '" data-conv="' + e(c.id) + '" aria-pressed="' + active + '">' +
        '<span class="msg-conv__name">' + e(c.entreprise) + "</span>" +
        '<span class="msg-conv__poste">' + e(c.poste) + "</span>" +
        '<span class="msg-conv__preview">' + e(last.text.slice(0, 60)) + (last.text.length > 60 ? "…" : "") + "</span>" +
      "</button>";
    }).join("");

    var conv = _conversations.filter(function (c) { return c.id === _activeConv; })[0] || _conversations[0];
    var bubbles = conv.messages.map(function (m) {
      return '<div class="msg-bubble msg-bubble--' + (m.from === "me" ? "me" : "them") + '">' +
        "<p>" + e(m.text) + "</p>" +
        '<span class="msg-bubble__date">' + e(SS.formatDate(m.date)) + "</span>" +
      "</div>";
    }).join("");

    box.innerHTML =
      '<div class="msg-list" role="list" aria-label="Conversations">' + listHtml + "</div>" +
      '<div class="msg-thread">' +
        '<div class="msg-thread__head"><strong>' + e(conv.entreprise) + "</strong>" +
          '<span class="text-muted">' + e(conv.poste) + "</span></div>" +
        '<div class="msg-thread__body">' + bubbles + "</div>" +
        '<form class="msg-reply" data-conv="' + e(conv.id) + '">' +
          '<label class="sr-only" for="msg-reply-input">Votre réponse</label>' +
          '<textarea id="msg-reply-input" rows="2" placeholder="Écrire une réponse…"></textarea>' +
          '<button type="submit" class="btn btn-primary btn-sm">Envoyer</button>' +
        "</form>" +
      "</div>";

    box.querySelectorAll("[data-conv]").forEach(function (el) {
      if (el.classList.contains("msg-conv")) {
        el.addEventListener("click", function () { _activeConv = el.getAttribute("data-conv"); renderMessages(); });
      }
    });

    var form = box.querySelector(".msg-reply");
    if (form) {
      form.addEventListener("submit", function (ev) {
        ev.preventDefault();
        var ta = form.querySelector("textarea");
        var text = ta ? ta.value.trim() : "";
        if (!text) { SS.toast("Écrivez un message avant d'envoyer."); return; }
        conv.messages.push({ from: "me", date: new Date().toISOString().slice(0, 10), text: text });
        renderMessages();
        SS.toast("Message envoyé (démonstration).");
      });
    }
  }

  /* ============================================================
     Paramètres
     ============================================================ */
  function fillSettings() {
    var s = SS.auth.get() || {};
    setValue("set-email", s.email || "");
  }

  /* ============================================================
     Indicateurs (cohérents avec les données seedées)
     ============================================================ */
  function updateMetrics() {
    var apps = getApplications();
    setText("metric-applications", apps.length);
    /* Consultées : candidatures ayant dépassé le stade « envoyée ». */
    var consultees = apps.filter(function (a) { return (a.statut || "envoyee") !== "envoyee"; }).length;
    setText("metric-views", consultees);
    /* Entretiens : rendez-vous programmés. */
    setText("metric-entretiens", defaultInterviews().length);
    setText("metric-favorites", SS.store.get(S.favorites, []).length);
    var recoBox = document.getElementById("reco-list");
    var reco = recoBox && recoBox.dataset.recoCount ? recoBox.dataset.recoCount : 4;
    setText("metric-reco", reco);
  }

  /* ============================================================
     Navigation latérale (lien actif au défilement / au clic)
     ============================================================ */
  function setupNav() {
    var links = Array.prototype.slice.call(document.querySelectorAll(".dash-nav a"));
    if (!links.length) { return; }

    function activate(id) {
      links.forEach(function (a) {
        a.classList.toggle("is-active", a.getAttribute("href") === "#" + id);
      });
    }

    links.forEach(function (a) {
      a.addEventListener("click", function () {
        activate(a.getAttribute("href").slice(1));
      });
    });

    var sections = links.map(function (a) {
      return document.getElementById(a.getAttribute("href").slice(1));
    }).filter(Boolean);

    if ("IntersectionObserver" in window) {
      var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) { activate(entry.target.id); }
        });
      }, { rootMargin: "-45% 0px -50% 0px", threshold: 0 });
      sections.forEach(function (sec) { observer.observe(sec); });
    }
  }

  /* ============================================================
     Utilitaires locaux
     ============================================================ */
  function emptyState(title, text, href, cta) {
    var e = SS.escapeHtml;
    return '<div class="empty-state"><h3>' + e(title) + "</h3><p>" + e(text) + "</p>" +
      (href ? '<p><a class="btn btn-primary" href="' + href + '">' + e(cta) + "</a></p>" : "") +
      "</div>";
  }
  function setText(id, value) {
    var el = document.getElementById(id);
    if (el) { el.textContent = String(value); }
  }
  function setValue(id, value) {
    var el = document.getElementById(id);
    if (el && "value" in el) { el.value = value == null ? "" : value; }
  }
  function val(id) {
    var el = document.getElementById(id);
    return el ? el.value.trim() : "";
  }
})();

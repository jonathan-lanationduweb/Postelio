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
  /* Clé locale du CV du candidat (prototype) : on n'enregistre QUE le nom du
     fichier et la date de mise à jour, jamais le contenu du fichier. */
  var CV_KEY = "ss_candidate_cv";

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
    renderTodayTodo();
    renderProfileSummary();
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
  var SEED_VERSION = "2026-08-20-candidat-structure";
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
    if (staleSeed || !SS.store.get(CV_KEY, null)) {
      SS.store.set(CV_KEY, defaultCv());
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
      realisationsList: [
        { titre: "Site vitrine — Studio Digital Lyon", lien: "https://exemple.fr", description: "Intégration responsive d'un site vitrine (HTML/CSS/JS), optimisation des performances et de l'accessibilité." }
      ],
      recommandationsList: [
        { nom: "Marie Dupont", role: "Responsable technique", entreprise: "Studio Digital Lyon", texte: "Jonathan est rigoureux et curieux. Il a su prendre en main nos projets rapidement et livrer dans les délais." }
      ],
      dateMaj: "2026-08-15"
    };
  }

  /* CV de démonstration : nom de fichier + date récente (aucun contenu stocké). */
  function defaultCv() {
    return { name: "CV_Jonathan_Davy.pdf", date: "2026-08-12" };
  }
  function getCv() { return SS.store.get(CV_KEY, null); }
  function setCv(v) { SS.store.set(CV_KEY, v); }
  function hasCv() { var c = getCv(); return !!(c && c.name); }
  function today() { return new Date().toISOString().slice(0, 10); }

  function defaultSavoirFaire() {
    return [
      { id: "sf-1", titre: "Comment je structure un projet web from scratch", resume: "Ma méthode pour démarrer un projet front-end proprement : arborescence, conventions de nommage et outillage minimal.", categorie: "Organisation & méthodes", note: 4.9, avis: 52, vues: 71, date: "2026-07-20" },
      { id: "sf-2", titre: "Déboguer efficacement avec les DevTools", resume: "Les réflexes que j'utilise au quotidien pour retrouver l'origine d'un bug rapidement, sans y passer la journée.", categorie: "Développement web", note: 4.7, avis: 38, vues: 44, date: "2026-06-30" },
      { id: "sf-3", titre: "Rendre un site accessible : mes 5 vérifications", resume: "Une check-list concrète pour améliorer l'accessibilité d'un site existant sans tout réécrire.", categorie: "Développement web", note: 4.8, avis: 37, vues: 33, date: "2026-06-10" }
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
      next.dateMaj = today();
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
  /* Clé PARTAGÉE écrite quand le candidat postule depuis une offre
     (offers.js). On fusionne ici les candidatures RÉELLEMENT envoyées par le
     candidat courant avec le jeu de démonstration, sans doublon. */
  var SENT_KEY = "ss_applications_sent";

  /* Suivi côté recruteur (clé ss_pipeline_v1). On le relit pour refléter la
     progression dans la frise du candidat — SANS jamais exposer les notes ni
     les motifs internes (§20). */
  var PIPELINE_KEY = "ss_pipeline_v1";
  /* Statut recruteur → vocabulaire candidat. */
  var RSTATUT_MAP = {
    nouveau: "envoyee", examiner: "vue", preselection: "preselection",
    entretien: "entretien", retenu: "offre-recue", refuse: "non-retenue"
  };
  /* Étape ajoutée à la frise du candidat pour le statut courant. */
  var RSTATUT_STEP = {
    vue: "Vue par l'entreprise", preselection: "Présélection",
    entretien: "Entretien proposé", "offre-recue": "Offre reçue",
    "non-retenue": "Candidature non retenue"
  };

  function recruiterStatusFor(id) {
    var s = SS.store.get(PIPELINE_KEY, null);
    return (s && s.status) ? (s.status[id] || null) : null;
  }

  /* Convertit une entrée `ss_applications_sent` au format des candidatures
     de l'espace candidat (offreId / offreTitre / entreprise / timeline…). */
  function sentToApplication(s) {
    var date = (s.date || "").slice(0, 10);
    var rstat = recruiterStatusFor(s.id);
    /* Vocabulaire candidat : « nouveau » (recruteur) → « envoyee ». */
    var statut = (rstat && RSTATUT_MAP[rstat]) ? RSTATUT_MAP[rstat] : "envoyee";

    var timeline = (s.timeline && s.timeline.length)
      ? s.timeline.slice()
      : [{ label: "Candidature envoyée", date: date || today() }];
    /* Reflète l'avancée du recruteur (progression uniquement). */
    if (statut !== "envoyee" && RSTATUT_STEP[statut]) {
      timeline.push({ label: RSTATUT_STEP[statut], date: null, next: true });
    }

    return {
      id: s.id,
      offreId: s.offerId,
      offreTitre: s.offerTitle,
      entrepriseId: s.companyId,
      entreprise: s.companyName,
      ville: s.offerCity || s.candidateCity || "",
      dateEnvoi: date || today(),
      statut: statut,
      note: s.note || "",
      timeline: timeline,
      _sent: true
    };
  }

  /* Fusionne le seed (S.applications) avec les candidatures envoyées par le
     candidat courant. Dédoublonnage par offre : une candidature déjà présente
     (même offreId) n'est pas ajoutée une seconde fois. */
  function mergeSentApplications(base) {
    var s = SS.auth.get() || {};
    var email = s.email || "";
    var sent = SS.store.get(SENT_KEY, []);
    if (!Array.isArray(sent) || !sent.length) { return base; }

    var seenOffers = {};
    base.forEach(function (a) { if (a.offreId) { seenOffers[a.offreId] = true; } });

    var mine = [];
    sent.forEach(function (item) {
      if (email && item.candidateEmail && item.candidateEmail !== email) { return; }
      if (item.offerId && seenOffers[item.offerId]) { return; }
      if (item.offerId) { seenOffers[item.offerId] = true; }
      mine.push(sentToApplication(item));
    });
    /* Les candidatures envoyées (les plus récentes) apparaissent en tête. */
    return mine.concat(base);
  }

  function getApplications() {
    return mergeSentApplications(SS.store.get(S.applications, []));
  }

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
        ? '<div class="notice appli-note" data-note style="margin-top: var(--sp-3);"><p class="appli-note__label"><strong>Note personnelle</strong> · visible uniquement par vous</p><p style="margin:0;">' + e(a.note) + "</p></div>"
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
          '<div class="field"><label for="note-' + e(a.id) + '">Note personnelle <span class="text-muted">(visible uniquement par vous)</span></label>' +
          '<textarea id="note-' + e(a.id) + '" placeholder="Ex. : relancer après l\'entretien.">' + e(a.note || "") + "</textarea></div>" +
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
      /* Retirer depuis le bon magasin : seed (S.applications) OU candidatures
         réellement envoyées (SENT_KEY), sans polluer l'autre. */
      var seed = SS.store.get(S.applications, []).filter(function (a) { return a.id !== id; });
      SS.store.set(S.applications, seed);
      var sent = SS.store.get(SENT_KEY, []).filter(function (a) { return a.id !== id; });
      SS.store.set(SENT_KEY, sent);
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
      /* Écrire la note dans le magasin qui contient la candidature. */
      var seed = SS.store.get(S.applications, []);
      var inSeed = false;
      seed = seed.map(function (a) { if (a.id === id) { a.note = value; inSeed = true; } return a; });
      if (inSeed) {
        SS.store.set(S.applications, seed);
      } else {
        var sent = SS.store.get(SENT_KEY, []).map(function (a) { if (a.id === id) { a.note = value; } return a; });
        SS.store.set(SENT_KEY, sent);
      }
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

  /* ============================================================
     À faire aujourd'hui (§8) — priorités actionnables du jour
     ============================================================ */
  function daysBetween(iso) {
    if (!iso) { return null; }
    return Math.floor((Date.now() - new Date(iso).getTime()) / 86400000);
  }

  function renderTodayTodo() {
    var box = document.getElementById("today-todo");
    if (!box) { return; }
    var e = SS.escapeHtml;
    var items = [];

    /* Entretien à confirmer */
    var store = SS.store.get(CONFIRM_KEY, {});
    defaultInterviews().forEach(function (it) {
      if (ivStatus(it, store).status === "propose") {
        items.push({ level: "urgent", text: "Entretien à confirmer pour le " + SS.formatDate(it.date), href: "#entretiens" });
      }
    });

    /* Nouveau message (dernier message reçu, hors refus) */
    var convs = _conversations || defaultConversations();
    convs.forEach(function (c) {
      var last = c.messages[c.messages.length - 1];
      if (last && last.from === "them" && c.id !== "conv-refus") {
        items.push({ level: "warn", text: "1 nouveau message de " + c.entreprise, href: "#messages" });
      }
    });

    /* Nouvelles offres correspondantes (compte des recommandations) */
    var recoBox = document.getElementById("reco-list");
    var recoN = recoBox && recoBox.dataset.recoCount ? parseInt(recoBox.dataset.recoCount, 10) : 0;
    if (recoN > 0) {
      items.push({ level: "info", text: recoN + " offre" + (recoN > 1 ? "s" : "") + " correspond" + (recoN > 1 ? "ent" : "") + " à votre recherche", href: "offres.html" });
    }

    /* CV ancien (plus de 2 mois) */
    var cv = getCv();
    var cvAge = cv && cv.date ? daysBetween(cv.date) : null;
    if (cvAge != null && cvAge > 60) {
      items.push({ level: "muted", text: "Votre CV date de plus de 2 mois", href: "#profil" });
    }

    if (!items.length) {
      box.innerHTML = '<div class="cand-card today-todo"><h2 class="cand-card__title">À faire aujourd\'hui</h2>' +
        '<p class="text-muted" style="margin:0;">Rien d\'urgent aujourd\'hui — vous êtes à jour. 👌</p></div>';
      return;
    }

    box.innerHTML = '<div class="cand-card today-todo"><h2 class="cand-card__title">À faire aujourd\'hui</h2>' +
      '<ul class="todo-list">' + items.map(function (it) {
        return '<li class="todo-item todo-item--' + it.level + '">' +
          '<span class="todo-dot" aria-hidden="true"></span>' +
          '<a href="' + e(it.href) + '">' + e(it.text) + "</a></li>";
      }).join("") + "</ul></div>";
  }

  /* Résumé de profil sur le tableau de bord (§41). */
  function renderProfileSummary() {
    var box = document.getElementById("profile-summary");
    if (!box) { return; }
    var profile = getProfile();
    var res = computeCompletion(profile);
    var cv = getCv();
    var e = SS.escapeHtml;
    var cvAge = cv && cv.date ? daysBetween(cv.date) : null;
    var cvLine = (cv && cv.name)
      ? "CV mis à jour " + (cvAge === 0 ? "aujourd'hui" : "il y a " + cvAge + " jour" + (cvAge > 1 ? "s" : ""))
      : "Aucun CV importé";
    var stale = daysBetween(profile.dateMaj);
    var warn = (stale != null && stale > 45)
      ? '<p class="profile-summary__warn">Vérifiez que vos informations sont toujours à jour.</p>' : "";

    box.innerHTML = '<div class="cand-card profile-summary">' +
        '<div class="profile-summary__body">' +
          '<div class="profile-meter" aria-hidden="true"><span style="width:' + res.pct + '%"></span></div>' +
          '<p class="profile-summary__pct"><strong>' + res.pct + '&nbsp;%</strong> de profil complété · <span class="text-muted">' + e(cvLine) + "</span></p>" +
          warn +
        "</div>" +
        '<a class="btn btn-outline btn-sm" href="#profil">Voir mon profil</a>' +
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
      renderTodayTodo(); /* le nombre d'offres n'est connu qu'ici (chargement async) */

      var top = pool.slice(0, 3);
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
        var expiresSoon = o.statut === "active" && o.dateExpiration && new Date(o.dateExpiration) <= soon;
        var expired = o.statut === "expiree";
        var remote = SS.teletravailLabel(o.teletravail);
        var tags = '<span class="badge badge--accent">' + e(o.contrat) + "</span>" +
          (remote ? '<span class="badge badge--remote">' + e(remote) + "</span>" : "") +
          (expired ? '<span class="badge badge--expired">Expirée</span>'
            : expiresSoon ? '<span class="badge badge--expired">Expire bientôt</span>' : "");
        return '<article class="card fav-card">' +
          '<div class="fav-card__body">' +
            "<strong>" + e(o.titre) + "</strong>" +
            '<span class="text-muted">' + e(o.entrepriseNom) + " · " + e(o.ville) + "</span>" +
            '<div class="fav-card__tags">' + tags + "</div>" +
            (o.salaire ? '<span class="fav-card__salary">' + e(o.salaire) + "</span>" : "") +
            '<span class="fav-card__pub text-muted">Publiée ' + e(SS.relativeDate(o.datePublication)) + "</span>" +
          "</div>" +
          '<div class="fav-card__actions">' +
            '<a class="btn btn-outline btn-sm" href="offre-detail.html?id=' + encodeURIComponent(o.id) + '">Voir l\'offre</a>' +
            '<details class="fav-card__menu"><summary class="btn btn-ghost btn-sm">…</summary>' +
              '<div class="fav-card__menu-pop"><button type="button" data-unfav="' + e(o.id) + '">Retirer des favoris</button></div>' +
            "</details>" +
          "</div>" +
        "</article>";
      }).join("");

      box.innerHTML = html || '<div class="empty-state"><p>Vos offres favorites ne sont plus disponibles.</p></div>';
      box.querySelectorAll("[data-unfav]").forEach(function (btn) {
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
      if (al.niveau) { parts.push(e(al.niveau)); }
      if (al.experience) { parts.push(e(al.experience)); }
      if (al.salaireMin) { parts.push("dès " + e(al.salaireMin)); }
      if (al.teletravail) { parts.push("Télétravail"); }
      var active = al.active !== false;
      var fresh = ((i + 3) % 5) + 1; /* nombre fictif mais stable */
      var freq = al.frequence || "quotidienne";
      var freqOptions = Object.keys(FREQ_LABEL).map(function (k) {
        return '<option value="' + k + '"' + (k === freq ? " selected" : "") + ">" + FREQ_LABEL[k] + "</option>";
      }).join("");
      /* Lien « voir les nouvelles offres » : préfiltre la page offres. */
      var searchHref = "offres.html?q=" + encodeURIComponent(al.metier || "") + "&lieu=" + encodeURIComponent(al.lieu || "");

      return '<article class="card alert-card' + (active ? "" : " is-inactive") + '">' +
        '<div class="alert-card__main">' +
          "<div><strong>" + (parts.join(" — ") || "Alerte") + "</strong><br>" +
          (active
            ? '<span class="badge badge--accent">' + fresh + " nouvelle" + (fresh > 1 ? "s" : "") + " offre" + (fresh > 1 ? "s" : "") + " depuis votre dernière visite</span>"
            : '<span class="text-muted">Alerte en pause — vous ne recevez plus de notification.</span>') + "</div>" +
          '<span class="status-badge ' + (active ? "status-vue" : "status-envoyee") + '">' + (active ? "Activée" : "Désactivée") + "</span>" +
        "</div>" +
        '<div class="alert-card__foot">' +
          (active ? '<a class="btn btn-outline btn-sm" href="' + searchHref + '">Voir les ' + fresh + " nouvelle" + (fresh > 1 ? "s" : "") + " offre" + (fresh > 1 ? "s" : "") + "</a>" : "") +
          '<div class="field field--inline"><label for="freq-' + i + '">Fréquence</label>' +
            '<select id="freq-' + i + '" data-freq="' + i + '">' + freqOptions + "</select></div>" +
          '<details class="alert-card__menu"><summary class="btn btn-ghost btn-sm">…</summary>' +
            '<div class="alert-card__menu-pop">' +
              '<button type="button" data-toggle-alert="' + i + '">' + (active ? "Désactiver" : "Réactiver") + "</button>" +
              '<button type="button" class="is-danger" data-del-alert="' + i + '">Supprimer</button>' +
            "</div></details>" +
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
    box.querySelectorAll("[data-toggle-alert]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var idx = parseInt(btn.getAttribute("data-toggle-alert"), 10);
        var list = SS.store.get(S.alerts, []);
        if (list[idx]) { list[idx].active = list[idx].active === false; SS.store.set(S.alerts, list); }
        renderAlerts();
        SS.toast(list[idx] && list[idx].active ? "Alerte réactivée." : "Alerte désactivée.");
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
        niveau: val("alert-niveau"),
        experience: val("alert-experience"),
        salaireMin: val("alert-salaire"),
        datePub: val("alert-datepub"),
        teletravail: document.getElementById("alert-teletravail").checked,
        frequence: val("alert-frequence") || "quotidienne",
        active: true
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
    { key: "experiences", label: "Expériences", type: "textarea", weight: 12, tip: "Détailler vos expériences", placeholder: "Vos expériences professionnelles" },
    { key: "formation", label: "Formation", type: "text", weight: 10, tip: "Ajouter votre formation", placeholder: "Votre diplôme le plus élevé" },
    { key: "competences", label: "Compétences", type: "tags", weight: 12, tip: "Ajouter vos compétences", placeholder: "HTML, CSS, JavaScript, travail en équipe…" },
    { key: "disponibilite", label: "Disponibilité", type: "text", weight: 8, tip: "Préciser votre disponibilité", placeholder: "Ex. immédiate, sous 1 mois…" },
    { key: "mobilite", label: "Mobilité", type: "text", weight: 8, tip: "Indiquer votre mobilité", placeholder: "Ex. 30 km autour de Lyon" }
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
    /* CV : +15 % s'il est importé (basé sur ss_candidate_cv, pas sur le profil). */
    if (hasCv()) { pct += 15; }
    else { missing.push({ key: "cv", label: "CV", weight: 15, tip: "Importer votre CV" }); }
    /* Réalisations : +10 % si au moins une (section structurée). */
    if ((profile.realisationsList || []).length) { pct += 10; }
    else { missing.push({ key: "realisations", label: "Réalisations", weight: 10, tip: "Ajouter une réalisation" }); }
    /* Savoir-faire : +5 % si au moins un est publié. */
    var hasSF = SS.store.get(SF_KEY, []).length > 0;
    if (hasSF) { pct += 5; } else { missing.push({ key: "savoirFaire", label: "Savoir-faire", weight: 5, link: "#savoir-faire", verb: "Publier un savoir-faire" }); }
    /* Recommandation : +5 % si au moins une. */
    if ((profile.recommandationsList || []).length) { pct += 5; }
    else { missing.push({ key: "recommandations", label: "Recommandation", weight: 5, verb: "Ajouter une recommandation" }); }
    return { pct: Math.min(100, pct), missing: missing };
  }

  function renderProfile() {
    renderProfileIdentity();
    renderProfileCompletion();
    renderProfileDates();
    renderCv();
    renderProfileSections();
  }

  /* Bloc d'identité en tête du profil (§25). */
  function renderProfileIdentity() {
    var box = document.getElementById("profile-identity");
    if (!box) { return; }
    var profile = getProfile();
    var res = computeCompletion(profile);
    var e = SS.escapeHtml;
    var dispo = (profile.disponibilite || "").trim();
    var dispoTxt = /imm[ée]diat/i.test(dispo) ? "Disponible immédiatement" : "Disponible : " + dispo;
    box.innerHTML =
      '<div class="profile-identity__head">' +
        '<span class="avatar profile-identity__avatar" aria-hidden="true">' + e(SS.auth.initials()) + "</span>" +
        "<div class=\"profile-identity__info\">" +
          '<strong class="profile-identity__name">' + e(SS.auth.displayName() || "Candidat") + "</strong>" +
          '<span class="profile-identity__role">' + e(profile.metier || "") + (profile.ville ? " · " + e(profile.ville) : "") + "</span>" +
          (dispo ? '<span class="badge badge--remote profile-identity__dispo">' + e(dispoTxt) + "</span>" : "") +
        "</div>" +
      "</div>" +
      '<div class="profile-identity__meter">' +
        '<div class="profile-meter" aria-hidden="true"><span style="width:' + res.pct + '%"></span></div>' +
        '<p class="profile-meter__label">Profil complété à <strong>' + res.pct + "&nbsp;%</strong></p>" +
      "</div>";
  }

  /* Dates de mise à jour (profil + CV). Simple affichage — prépare une future
     relance ; la logique de relance n'est pas développée ici. */
  function renderProfileDates() {
    var box = document.getElementById("profile-dates");
    if (!box) { return; }
    var profile = getProfile();
    var cv = getCv();
    var e = SS.escapeHtml;
    var profDate = profile.dateMaj ? SS.formatDate(profile.dateMaj) : "—";
    var cvDate = (cv && cv.name && cv.date) ? SS.formatDate(cv.date) : "—";
    box.innerHTML =
      '<p class="profile-dates__item">Profil mis à jour le : <strong>' + e(profDate) + "</strong></p>" +
      '<p class="profile-dates__item">CV mis à jour le : <strong>' + e(cvDate) + "</strong></p>";
  }

  /* Sous-section « Mon CV » : import simulé (nom du fichier lu côté navigateur,
     aucun envoi ni stockage du contenu). */
  /* Icône document (remplace l'emoji 📄 pour rester cohérent avec le système
     d'icônes SVG du site). */
  var ICON_DOC = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h6"/></svg>';

  function renderCv() {
    var box = document.getElementById("profile-cv");
    if (!box) { return; }
    var cv = getCv();
    var e = SS.escapeHtml;
    var head = '<div class="profile-row__head"><h3>Mon CV</h3></div>';
    var input = '<input type="file" id="cv-file-input" accept=".pdf,.doc,.docx" class="sr-only" tabindex="-1" aria-hidden="true">';

    if (!hasCv()) {
      box.innerHTML = head +
        '<div class="empty-state empty-state--inline">' +
          "<p>Aucun CV importé.</p>" +
          '<p><button type="button" class="btn btn-primary btn-sm" data-cv-action="import">Importer mon CV</button></p>' +
        "</div>" + input;
    } else {
      box.innerHTML = head +
        '<div class="cand-cv__file">' +
          '<span class="cand-cv__icon" aria-hidden="true">' + ICON_DOC + "</span>" +
          '<span class="cand-cv__meta"><strong>' + e(cv.name) + "</strong>" +
          '<span class="text-muted">Mis à jour le ' + e(SS.formatDate(cv.date)) + "</span></span>" +
        "</div>" +
        '<div class="form-actions cand-cv__actions">' +
          '<button type="button" class="btn btn-primary btn-sm" data-cv-action="preview">Aperçu</button>' +
          '<button type="button" class="btn btn-outline btn-sm" data-cv-action="replace">Remplacer</button>' +
          '<details class="cand-cv__menu"><summary class="btn btn-ghost btn-sm">…</summary>' +
            '<div class="cand-cv__menu-pop">' +
              '<button type="button" data-cv-action="download">Télécharger</button>' +
              '<button type="button" class="is-danger" data-cv-action="delete">Supprimer</button>' +
            "</div></details>" +
        "</div>" + input;
    }

    var fileInput = box.querySelector("#cv-file-input");
    box.querySelectorAll("[data-cv-action]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var action = btn.getAttribute("data-cv-action");
        if (action === "import" || action === "replace") {
          if (fileInput) { fileInput.click(); }
        } else if (action === "preview") {
          openCvViewer();
        } else if (action === "download") {
          SS.toast("Téléchargement du CV (démonstration).");
        } else if (action === "delete") {
          if (!window.confirm("Supprimer votre CV ?")) { return; }
          setCv({ name: "", date: "" });
          renderCv();
          renderProfileDates();
          renderProfileIdentity();
          renderProfileCompletion();
          renderProfileSummary();
          SS.toast("CV supprimé.");
        }
      });
    });

    if (fileInput) {
      fileInput.addEventListener("change", function () {
        var file = fileInput.files && fileInput.files[0];
        if (!file) { return; }
        /* On ne lit QUE le nom du fichier : aucun contenu n'est envoyé ni stocké. */
        setCv({ name: file.name, date: today() });
        fileInput.value = "";
        renderCv();
        renderProfileDates();
        renderProfileIdentity();
        renderProfileCompletion();
        renderProfileSummary();
        SS.toast("CV importé : " + file.name);
      });
    }
  }

  /* Aperçu simulé du CV du candidat + visionneuse (zoom), comme côté recruteur
     (§27). Aucun contenu réel : document factice stylé « démonstration ». */
  function cvDocHtml() {
    var e = SS.escapeHtml;
    var p = getProfile();
    var s = SS.auth.get() || {};
    var name = SS.auth.displayName() || "Candidat";
    var contact = [p.ville || s.city, s.email].filter(Boolean).join(" · ");
    var skills = (p.competences || []).slice(0, 5).map(function (c) { return '<span class="cv-doc__chip">' + e(c) + "</span>"; }).join("");
    return '<div class="cv-doc cv-doc--full" role="img" aria-label="Aperçu simulé de votre CV">' +
      '<span class="cv-doc__demo">Aperçu de démonstration</span>' +
      '<div class="cv-doc__head"><h4>' + e(name) + "</h4><p>" + e(p.metier || "") + "</p>" +
        (contact ? '<p class="cv-doc__contact">' + e(contact) + "</p>" : "") + "</div>" +
      '<div class="cv-doc__sec"><span class="cv-doc__label">Expérience</span>' +
        (p.experiences ? '<p class="cv-doc__text">' + e(p.experiences) + "</p>" : "") + "</div>" +
      '<div class="cv-doc__sec"><span class="cv-doc__label">Formation</span>' +
        (p.formation ? '<p class="cv-doc__text">' + e(p.formation) + "</p>" : "") + "</div>" +
      '<div class="cv-doc__sec"><span class="cv-doc__label">Compétences</span>' +
        (skills ? '<div class="cv-doc__chips">' + skills + "</div>" : "") + "</div>" +
      '<p class="cv-doc__file">' + e((getCv() || {}).name || "") + "</p>" +
    "</div>";
  }

  function openCvViewer() {
    var e = SS.escapeHtml;
    var overlay = document.createElement("div");
    overlay.className = "modal-overlay";
    overlay.setAttribute("role", "dialog");
    overlay.setAttribute("aria-modal", "true");
    overlay.innerHTML =
      '<div class="modal modal--wide cv-viewer" role="document">' +
        '<div class="modal__head"><h2 class="modal__title">CV de ' + e(SS.auth.displayName() || "vous") + "</h2>" +
          '<button type="button" class="modal-close" data-close aria-label="Fermer">✕</button></div>' +
        '<div class="modal__body cv-viewer__body">' +
          '<div class="cv-viewer__toolbar">' +
            '<div class="cv-viewer__zoom" role="group" aria-label="Zoom du CV">' +
              '<button type="button" class="btn btn-outline btn-sm" data-zoom="out" aria-label="Réduire">Zoom −</button>' +
              '<span class="cv-viewer__level" aria-live="polite">100 %</span>' +
              '<button type="button" class="btn btn-outline btn-sm" data-zoom="in" aria-label="Agrandir">Zoom +</button>' +
            "</div>" +
            '<span class="cv-viewer__pages text-muted">Page 1 / 1</span>' +
          "</div>" +
          '<div class="cv-viewer__stage"><div class="cv-viewer__page">' + cvDocHtml() + "</div></div>" +
        "</div>" +
        '<div class="modal__actions"><button type="button" class="btn btn-primary" data-close>Fermer</button></div>' +
      "</div>";
    document.body.appendChild(overlay);
    document.body.classList.add("modal-open");

    var zoom = 1;
    var page = overlay.querySelector(".cv-viewer__page");
    var level = overlay.querySelector(".cv-viewer__level");
    function apply() { page.style.transform = "scale(" + zoom + ")"; level.textContent = Math.round(zoom * 100) + " %"; }
    overlay.querySelectorAll("[data-zoom]").forEach(function (b) {
      b.addEventListener("click", function () {
        zoom = b.getAttribute("data-zoom") === "in" ? Math.min(1.6, zoom + 0.15) : Math.max(0.7, zoom - 0.15);
        apply();
      });
    });
    function close() { overlay.remove(); document.body.classList.remove("modal-open"); }
    overlay.querySelectorAll("[data-close]").forEach(function (b) { b.addEventListener("click", close); });
    overlay.addEventListener("click", function (ev) { if (ev.target === overlay) { close(); } });
    document.addEventListener("keydown", function onEsc(ev) { if (ev.key === "Escape") { close(); document.removeEventListener("keydown", onEsc); } });
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
      (tips.length
        ? '<div class="profile-tips"><p class="profile-tips__title">Pour compléter votre profil :</p><ul>' + tipsHtml + "</ul></div>"
        : '<p class="notice notice--demo" style="margin:0;">Bravo, votre profil est complet !</p>');
    box.hidden = false;
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

    box.innerHTML = rows + realisationsSectionHtml(profile) + sfRow + recommandationsSectionHtml(profile);

    wireRealisations(box);
    wireRecommandations(box);

    /* Suggestions de compétences selon le métier (§29). */
    var compRow = box.querySelector('.profile-row[data-key="competences"] .profile-row__edit .field');
    if (compRow) {
      var suggestions = skillSuggestions(profile.metier).filter(function (s) {
        return (profile.competences || []).map(normalize).indexOf(normalize(s)) === -1;
      });
      if (suggestions.length) {
        var wrap = document.createElement("div");
        wrap.className = "skill-suggest";
        wrap.innerHTML = '<span class="skill-suggest__label">Suggestions :</span> ' +
          suggestions.map(function (s) { return '<button type="button" class="chip" data-skill="' + e(s) + '">+ ' + e(s) + "</button>"; }).join("");
        compRow.appendChild(wrap);
        wrap.querySelectorAll("[data-skill]").forEach(function (b) {
          b.addEventListener("click", function () {
            var input = document.getElementById("pf-competences");
            if (!input) { return; }
            var vals = input.value.split(",").map(function (x) { return x.trim(); }).filter(Boolean);
            vals.push(b.getAttribute("data-skill"));
            input.value = vals.join(", ");
            b.remove();
            input.focus();
          });
        });
      }
    }

    box.querySelectorAll("[data-action]").forEach(function (btn) {
      btn.addEventListener("click", function () { onProfileAction(btn); });
    });
  }

  /* Petit référentiel de compétences suggérées par famille de métier. */
  function skillSuggestions(metier) {
    var m = normalize(metier);
    if (/develop|web|informat|digital/.test(m)) { return ["HTML / CSS", "JavaScript", "Git", "React", "PHP", "SQL", "Travail en équipe"]; }
    if (/comptab|paie|financ|gestion/.test(m)) { return ["Comptabilité générale", "Fiscalité", "Sage / Cegid", "Excel", "Paie", "Rigueur"]; }
    if (/commerc|vente|conseil/.test(m)) { return ["Négociation", "Prospection", "CRM", "Relation client", "Sens du service"]; }
    if (/assist|secret|administ|office/.test(m)) { return ["Pack Office", "Accueil", "Organisation", "Orthographe", "Gestion d'agenda"]; }
    return ["Travail en équipe", "Autonomie", "Organisation", "Communication", "Rigueur"];
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
      profile.dateMaj = today();
      setProfile(profile);
      renderProfile();
      /* Le métier / la localisation du profil alimentent aussi la recherche. */
      SS.toast("Profil mis à jour.");
    }
  }

  /* ---- Réalisations (section structurée : projet, lien, description) §30 ---- */
  function realisationsSectionHtml(profile) {
    var e = SS.escapeHtml;
    var list = profile.realisationsList || [];
    var cards = list.length ? list.map(function (r, i) {
      return '<div class="realisation-item">' +
        "<strong>" + e(r.titre || "Réalisation") + "</strong>" +
        (r.lien ? ' <a class="realisation-item__link" href="' + e(r.lien) + '" target="_blank" rel="noopener">Voir le lien ↗</a>' : "") +
        (r.description ? '<p class="realisation-item__desc">' + e(r.description) + "</p>" : "") +
        '<button type="button" class="btn btn-ghost btn-xs realisation-item__del" data-real-del="' + i + '">Retirer</button>' +
      "</div>";
    }).join("") : '<p class="profile-row__empty">Aucune réalisation pour l\'instant.</p>';

    return '<div class="profile-row" data-section="realisations">' +
      '<div class="profile-row__head"><h3>Réalisations</h3>' +
        '<button type="button" class="btn btn-ghost btn-sm" data-real-toggle aria-expanded="false">+ Ajouter une réalisation</button></div>' +
      '<div class="profile-row__body">' + cards + "</div>" +
      '<div class="profile-row__edit realisation-form" hidden>' +
        '<div class="field"><label for="real-titre">Titre du projet</label><input id="real-titre" placeholder="Ex. : Site e-commerce"></div>' +
        '<div class="field"><label for="real-lien">Lien (optionnel)</label><input id="real-lien" placeholder="https://…"></div>' +
        '<div class="field"><label for="real-desc">Description</label><textarea id="real-desc" rows="2" placeholder="Ce que vous avez réalisé, les technologies utilisées…"></textarea></div>' +
        '<div class="form-actions"><button type="button" class="btn btn-primary btn-sm" data-real-add>Ajouter</button>' +
          '<button type="button" class="btn btn-ghost btn-sm" data-real-cancel>Annuler</button></div>' +
      "</div></div>";
  }

  function wireRealisations(box) {
    var section = box.querySelector('[data-section="realisations"]');
    if (!section) { return; }
    var form = section.querySelector(".realisation-form");
    var toggle = section.querySelector("[data-real-toggle]");
    toggle.addEventListener("click", function () {
      var open = form.hidden; form.hidden = !open; toggle.setAttribute("aria-expanded", String(open));
      if (open) { var f = form.querySelector("input"); if (f) { f.focus(); } }
    });
    var cancel = section.querySelector("[data-real-cancel]");
    if (cancel) { cancel.addEventListener("click", function () { form.hidden = true; toggle.setAttribute("aria-expanded", "false"); }); }
    var add = section.querySelector("[data-real-add]");
    if (add) {
      add.addEventListener("click", function () {
        var titre = (document.getElementById("real-titre").value || "").trim();
        if (!titre) { SS.toast("Indiquez au moins un titre."); return; }
        var profile = getProfile();
        profile.realisationsList = (profile.realisationsList || []).concat([{
          titre: titre,
          lien: (document.getElementById("real-lien").value || "").trim(),
          description: (document.getElementById("real-desc").value || "").trim()
        }]);
        profile.dateMaj = today();
        setProfile(profile);
        renderProfile();
        SS.toast("Réalisation ajoutée.");
      });
    }
    section.querySelectorAll("[data-real-del]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var i = parseInt(btn.getAttribute("data-real-del"), 10);
        var profile = getProfile();
        (profile.realisationsList || []).splice(i, 1);
        profile.dateMaj = today();
        setProfile(profile);
        renderProfile();
        SS.toast("Réalisation retirée.");
      });
    });
  }

  /* ---- Recommandations (auteur : nom, fonction, entreprise) §33 ---- */
  function recommandationsSectionHtml(profile) {
    var e = SS.escapeHtml;
    var list = profile.recommandationsList || [];
    var cards = list.length ? list.map(function (r, i) {
      var author = [r.nom, r.role, r.entreprise].filter(Boolean).join(" · ");
      return '<figure class="reco-item">' +
        '<blockquote>« ' + e(r.texte || "") + " »</blockquote>" +
        '<figcaption>' + e(author) + "</figcaption>" +
        '<button type="button" class="btn btn-ghost btn-xs reco-item__del" data-reco-del="' + i + '">Retirer</button>' +
      "</figure>";
    }).join("") : '<p class="profile-row__empty">Aucune recommandation pour l\'instant.</p>';

    return '<div class="profile-row" data-section="recommandations">' +
      '<div class="profile-row__head"><h3>Recommandations</h3>' +
        '<button type="button" class="btn btn-ghost btn-sm" data-reco-toggle aria-expanded="false">+ Ajouter une recommandation</button></div>' +
      '<div class="profile-row__body">' + cards + "</div>" +
      '<div class="profile-row__edit reco-form" hidden>' +
        '<div class="form-row">' +
          '<div class="field"><label for="reco-nom">Qui vous recommande ?</label><input id="reco-nom" placeholder="Prénom Nom"></div>' +
          '<div class="field"><label for="reco-role">Fonction</label><input id="reco-role" placeholder="Ex. : Responsable technique"></div>' +
        "</div>" +
        '<div class="field"><label for="reco-entreprise">Entreprise</label><input id="reco-entreprise" placeholder="Ex. : Studio Digital Lyon"></div>' +
        '<div class="field"><label for="reco-texte">Recommandation</label><textarea id="reco-texte" rows="2"></textarea></div>' +
        '<div class="form-actions"><button type="button" class="btn btn-primary btn-sm" data-reco-add>Ajouter</button>' +
          '<button type="button" class="btn btn-ghost btn-sm" data-reco-cancel>Annuler</button></div>' +
      "</div></div>";
  }

  function wireRecommandations(box) {
    var section = box.querySelector('[data-section="recommandations"]');
    if (!section) { return; }
    var form = section.querySelector(".reco-form");
    var toggle = section.querySelector("[data-reco-toggle]");
    toggle.addEventListener("click", function () {
      var open = form.hidden; form.hidden = !open; toggle.setAttribute("aria-expanded", String(open));
      if (open) { var f = form.querySelector("input"); if (f) { f.focus(); } }
    });
    var cancel = section.querySelector("[data-reco-cancel]");
    if (cancel) { cancel.addEventListener("click", function () { form.hidden = true; toggle.setAttribute("aria-expanded", "false"); }); }
    var add = section.querySelector("[data-reco-add]");
    if (add) {
      add.addEventListener("click", function () {
        var texte = (document.getElementById("reco-texte").value || "").trim();
        var nom = (document.getElementById("reco-nom").value || "").trim();
        if (!texte || !nom) { SS.toast("Indiquez au moins le nom et le texte."); return; }
        var profile = getProfile();
        profile.recommandationsList = (profile.recommandationsList || []).concat([{
          nom: nom,
          role: (document.getElementById("reco-role").value || "").trim(),
          entreprise: (document.getElementById("reco-entreprise").value || "").trim(),
          texte: texte
        }]);
        profile.dateMaj = today();
        setProfile(profile);
        renderProfile();
        SS.toast("Recommandation ajoutée.");
      });
    }
    section.querySelectorAll("[data-reco-del]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var i = parseInt(btn.getAttribute("data-reco-del"), 10);
        var profile = getProfile();
        (profile.recommandationsList || []).splice(i, 1);
        profile.dateMaj = today();
        setProfile(profile);
        renderProfile();
        SS.toast("Recommandation retirée.");
      });
    });
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
        '<p><a class="btn btn-primary" href="publier-savoir-faire.html?type=candidat">Ajouter un savoir-faire</a></p></div>';
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
            (sf.categorie ? '<span class="badge badge--neutral">' + e(sf.categorie) + "</span>" : "") +
            '<span class="badge badge--accent">' + String(sf.note).replace(".", ",") + "/5</span>" +
            "<span>" + (sf.avis || 0) + " avis</span>" +
            "<span>" + (sf.vues || 0) + " vues</span>" +
            "<span>Publié le " + e(SS.formatDate(sf.date)) + "</span>" +
          "</div>" +
        "</div>" +
        '<div class="sf-card__actions">' +
          '<a class="btn btn-outline btn-sm" href="savoir-faire.html">Voir</a>' +
          '<a class="btn btn-ghost btn-sm" href="publier-savoir-faire.html?type=candidat">Modifier</a>' +
          '<details class="sf-card__menu"><summary class="btn btn-ghost btn-sm">…</summary>' +
            '<div class="fav-card__menu-pop">' +
              '<button type="button" data-sf-action="unpublish" data-sf="' + e(sf.id) + '">Dépublier</button>' +
              '<button type="button" class="is-danger" data-sf-action="delete" data-sf="' + e(sf.id) + '">Supprimer</button>' +
            "</div></details>" +
        "</div>" +
      "</article>";
    }).join("");

    listBox.querySelectorAll("[data-sf-action]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var id = btn.getAttribute("data-sf");
        var action = btn.getAttribute("data-sf-action");
        if (action === "delete" && !window.confirm("Supprimer définitivement ce savoir-faire ?")) { return; }
        var next = SS.store.get(SF_KEY, []).filter(function (x) { return x.id !== id; });
        SS.store.set(SF_KEY, next);
        renderSavoirFaire();
        renderProfile();
        SS.toast(action === "delete" ? "Savoir-faire supprimé." : "Savoir-faire dépublié.");
      });
    });
  }

  /* ============================================================
     Mes entretiens
     ============================================================ */
  var CONFIRM_KEY = "ss_cand_interviews_confirmed";

  function defaultInterviews() {
    return [
      { id: "civ1", date: "2026-08-21", heure: "14:00", poste: "Développeur web junior", entreprise: "Pixel & Co", entrepriseId: "pixel-and-co", offreId: "dev-web-junior-pixel-lille", format: "Visioconférence", lien: "Lien de connexion envoyé par e-mail avant le rendez-vous", statut: "propose" },
      { id: "civ2", date: "2026-08-26", heure: "10:30", poste: "Office manager", entreprise: "TechNexis", entrepriseId: "technexis", offreId: "office-manager-technexis-lille", format: "Sur place", adresse: "45 rue Nationale, 59000 Lille", contact: "Camille Roy, responsable RH", instructions: "Présentez-vous à l'accueil au 2e étage, muni d'une pièce d'identité.", statut: "confirme" },
      { id: "civ3", date: "2026-07-30", heure: "11:00", poste: "Technicien support logiciel", entreprise: "TechNexis", entrepriseId: "technexis", offreId: "technicien-support-technexis-lille", format: "Téléphone", statut: "passe" }
    ];
  }

  /* État effectif d'un entretien : combine le statut seedé et l'action du
     candidat persistée dans CONFIRM_KEY. Rétro-compat : une ancienne valeur
     `true` (avant enrichissement) signifie « confirmé ». */
  function ivStatus(it, store) {
    var rec = store[it.id];
    if (rec === true) { return { status: "confirme" }; }
    if (rec && rec.status) { return rec; }
    return { status: it.statut || "propose" };
  }

  function setIvStatus(id, rec) {
    var store = SS.store.get(CONFIRM_KEY, {});
    store[id] = rec;
    SS.store.set(CONFIRM_KEY, store);
  }

  function ivBadge(status) {
    switch (status) {
      case "confirme": return '<span class="status-badge status-recue">Confirmé</span>';
      case "nouveau-creneau": return '<span class="status-badge status-preselection">Nouveau créneau proposé</span>';
      case "refuse": return '<span class="status-badge status-refusee">Refusé</span>';
      default: return '<span class="status-badge status-envoyee">À confirmer</span>';
    }
  }

  var currentIvTab = "confirmer";
  var IV_TABS = [["confirmer", "À confirmer"], ["avenir", "À venir"], ["passes", "Passés"], ["annules", "Annulés"]];

  /* Onglet d'un entretien selon son statut effectif et sa date. */
  function ivTabOf(it, store) {
    var status = ivStatus(it, store).status;
    if (status === "refuse") { return "annules"; }
    if (status === "propose" || status === "nouveau-creneau") { return "confirmer"; }
    /* confirmé : à venir ou passé selon la date */
    if (status === "passe") { return "passes"; }
    return (daysBetween(it.date) != null && daysBetween(it.date) > 0) ? "passes" : "avenir";
  }

  /* Bloc d'informations propre au format de l'entretien (§15). */
  function ivFormatInfo(it) {
    var e = SS.escapeHtml;
    if (it.format === "Sur place") {
      return '<ul class="interview-card__details">' +
        (it.adresse ? "<li><strong>Adresse :</strong> " + e(it.adresse) + "</li>" : "") +
        (it.contact ? "<li><strong>Contact :</strong> " + e(it.contact) + "</li>" : "") +
        (it.instructions ? "<li><strong>Accès :</strong> " + e(it.instructions) + "</li>" : "") +
      "</ul>";
    }
    if (it.format === "Téléphone") {
      return '<p class="interview-card__lieu text-muted">L\'entreprise vous contactera au numéro indiqué dans votre profil.</p>';
    }
    /* Visioconférence */
    return '<p class="interview-card__lieu text-muted">' + e(it.lien || "Lien de connexion disponible avant le rendez-vous.") + "</p>";
  }

  function renderInterviews() {
    var box = document.getElementById("interviews-list");
    if (!box) { return; }
    var list = defaultInterviews();
    var e = SS.escapeHtml;
    var store = SS.store.get(CONFIRM_KEY, {});

    /* Répartition par onglet + compteurs. */
    var counts = { confirmer: 0, avenir: 0, passes: 0, annules: 0 };
    list.forEach(function (it) { counts[ivTabOf(it, store)]++; });
    if (!counts[currentIvTab]) {
      var first = IV_TABS.filter(function (t) { return counts[t[0]]; })[0];
      if (first) { currentIvTab = first[0]; }
    }

    var tabsHtml = '<div class="iv-tabs" role="tablist" aria-label="Filtrer les entretiens">' +
      IV_TABS.map(function (t) {
        var on = t[0] === currentIvTab;
        return '<button type="button" class="offers-tab chip" role="tab" aria-selected="' + on + '" data-iv-tab="' + t[0] + '">' +
          e(t[1]) + ' <span class="offers-tab__count">' + counts[t[0]] + "</span></button>";
      }).join("") + "</div>";

    var visible = list.filter(function (it) { return ivTabOf(it, store) === currentIvTab; });

    var cardsHtml = visible.length ? visible.map(function (it) {
      var st = ivStatus(it, store);
      var status = st.status;
      var isPropose = status === "propose";
      var jour = new Date(it.date);
      var jourLabel = ["dimanche", "lundi", "mardi", "mercredi", "jeudi", "vendredi", "samedi"][jour.getDay()];

      var proposalText = isPropose
        ? '<p class="interview-card__propose"><strong>' + e(it.entreprise) + " vous propose un entretien.</strong></p>"
        : "";
      var creneauInfo = (status === "nouveau-creneau" && st.creneau)
        ? '<p class="interview-card__note text-muted">Vous avez proposé : ' + e(SS.formatDate(st.creneau.date)) +
            (st.creneau.heure ? " à " + e(st.creneau.heure) : "") + ".</p>"
        : "";

      var actions = isPropose
        ? '<button type="button" class="btn btn-primary btn-sm" data-iv-action="confirm" data-iv="' + e(it.id) + '">Confirmer</button>' +
          '<button type="button" class="btn btn-outline btn-sm" data-iv-action="reschedule" data-iv="' + e(it.id) + '" aria-expanded="false" aria-controls="iv-slot-' + e(it.id) + '">Proposer un autre créneau</button>' +
          '<button type="button" class="btn btn-ghost btn-sm" data-iv-action="refuse" data-iv="' + e(it.id) + '">Refuser</button>'
        : (status === "confirme" ? '<button type="button" class="btn btn-outline btn-sm" data-iv-action="calendar" data-iv="' + e(it.id) + '">Ajouter à mon calendrier</button>' : "") +
          '<a class="btn btn-ghost btn-sm" href="offre-detail.html?id=' + encodeURIComponent(it.offreId) + '">Voir l\'offre</a>' +
          '<a class="btn btn-ghost btn-sm" href="#messages">Message</a>';

      var slotForm = isPropose
        ? '<form class="interview-card__slot" id="iv-slot-' + e(it.id) + '" data-iv="' + e(it.id) + '" novalidate hidden>' +
            '<div class="form-row">' +
              '<div class="field"><label for="iv-date-' + e(it.id) + '">Nouvelle date</label><input type="date" id="iv-date-' + e(it.id) + '"></div>' +
              '<div class="field"><label for="iv-heure-' + e(it.id) + '">Heure</label><input type="time" id="iv-heure-' + e(it.id) + '"></div>' +
            "</div>" +
            '<div class="form-actions">' +
              '<button type="submit" class="btn btn-primary btn-sm">Envoyer la proposition</button>' +
              '<button type="button" class="btn btn-ghost btn-sm" data-iv-action="cancel-slot" data-iv="' + e(it.id) + '">Annuler</button>' +
            "</div>" +
          "</form>"
        : "";

      return '<article class="interview-card">' +
        '<div class="interview-card__date"><span class="interview-card__day">' + e(jourLabel) + "</span>" +
          "<b>" + e(SS.formatDate(it.date)) + "</b><span>" + e(it.heure) + "</span></div>" +
        '<div class="interview-card__info">' +
          "<strong>" + e(it.poste) + "</strong> " + ivBadge(status) + "<br>" +
          '<span class="text-muted">' + e(it.entreprise) + "</span>" +
          proposalText +
          '<div class="interview-card__mode"><span class="badge badge--remote">' + e(it.format) + "</span></div>" +
          ivFormatInfo(it) +
          creneauInfo +
        "</div>" +
        '<div class="interview-card__actions">' + actions + "</div>" +
        slotForm +
      "</article>";
    }).join("") : '<div class="empty-state empty-state--inline"><p>Aucun entretien dans cet onglet.</p></div>';

    box.innerHTML = tabsHtml + cardsHtml;

    box.querySelectorAll("[data-iv-tab]").forEach(function (btn) {
      btn.addEventListener("click", function () { currentIvTab = btn.getAttribute("data-iv-tab"); renderInterviews(); });
    });
    box.querySelectorAll("[data-iv-action]").forEach(function (btn) {
      btn.addEventListener("click", function () { onInterviewAction(btn); });
    });
    box.querySelectorAll(".interview-card__slot").forEach(function (form) {
      form.addEventListener("submit", function (ev) {
        ev.preventDefault();
        var id = form.getAttribute("data-iv");
        var d = document.getElementById("iv-date-" + id);
        var h = document.getElementById("iv-heure-" + id);
        var dateVal = d ? d.value : "";
        if (!dateVal) {
          SS.toast("Choisissez une date pour votre proposition.");
          if (d) { d.focus(); }
          return;
        }
        setIvStatus(id, { status: "nouveau-creneau", creneau: { date: dateVal, heure: h ? h.value : "" } });
        renderInterviews();
        SS.toast("Nouveau créneau proposé (démonstration).");
      });
    });
  }

  function onInterviewAction(btn) {
    var id = btn.getAttribute("data-iv");
    var action = btn.getAttribute("data-iv-action");

    if (action === "confirm") {
      setIvStatus(id, { status: "confirme" });
      renderInterviews();
      updateMetrics();
      SS.toast("Rendez-vous confirmé — l'entreprise en est informée.");
      return;
    }
    if (action === "refuse") {
      if (!window.confirm("Refuser cette proposition d'entretien ?")) { return; }
      setIvStatus(id, { status: "refuse" });
      renderInterviews();
      SS.toast("Proposition d'entretien refusée.");
      return;
    }
    if (action === "calendar") {
      addToCalendar(id);
      return;
    }
    if (action === "reschedule") {
      var form = document.getElementById("iv-slot-" + id);
      if (form) {
        var open = form.hidden;
        form.hidden = !open;
        btn.setAttribute("aria-expanded", String(open));
        if (open) { var f = form.querySelector("input"); if (f) { f.focus(); } }
      }
      return;
    }
    if (action === "cancel-slot") {
      var form2 = document.getElementById("iv-slot-" + id);
      if (form2) { form2.hidden = true; }
      var toggle = document.querySelector('[data-iv-action="reschedule"][data-iv="' + id + '"]');
      if (toggle) { toggle.setAttribute("aria-expanded", "false"); toggle.focus(); }
      return;
    }
  }

  /* Génère un fichier .ics et déclenche son téléchargement (§16, local, sans
     aucune connexion à un agenda externe). */
  function addToCalendar(id) {
    var it = defaultInterviews().filter(function (x) { return x.id === id; })[0];
    if (!it) { return; }
    var start = (it.date || "").replace(/-/g, "") + "T" + (it.heure || "09:00").replace(":", "") + "00";
    var endH = String((parseInt((it.heure || "09:00").split(":")[0], 10) || 9) + 1).padStart(2, "0");
    var end = (it.date || "").replace(/-/g, "") + "T" + endH + (it.heure || "09:00").split(":")[1] + "00";
    var loc = it.adresse || (it.format === "Visioconférence" ? "Visioconférence" : it.format);
    var ics = [
      "BEGIN:VCALENDAR", "VERSION:2.0", "PRODID:-//Postelio//Demo//FR", "BEGIN:VEVENT",
      "UID:" + it.id + "@postelio.demo",
      "DTSTART:" + start, "DTEND:" + end,
      "SUMMARY:Entretien " + it.poste + " — " + it.entreprise,
      "LOCATION:" + loc,
      "DESCRIPTION:Entretien Postelio (démonstration).",
      "END:VEVENT", "END:VCALENDAR"
    ].join("\r\n");
    try {
      var blob = new Blob([ics], { type: "text/calendar" });
      var url = URL.createObjectURL(blob);
      var a = document.createElement("a");
      a.href = url; a.download = "entretien-" + it.id + ".ics";
      document.body.appendChild(a); a.click(); document.body.removeChild(a);
      setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
      SS.toast("Entretien ajouté à votre calendrier (fichier .ics téléchargé).");
    } catch (err) {
      SS.toast("Ajout au calendrier (démonstration).");
    }
  }

  /* ============================================================
     Messages (messagerie simple, 2 conversations démo)
     ============================================================ */
  /* Récupère un message de refus courtois côté recruteur s'il en existe un
     (clé partagée ss_refus_demo, écrite par l'espace entreprise). */
  function refusFromStore() {
    var r = SS.store.get("ss_refus_demo", {}) || {};
    var keys = Object.keys(r);
    for (var i = 0; i < keys.length; i++) {
      if (r[keys[i]] && r[keys[i]].message) { return r[keys[i]]; }
    }
    return null;
  }

  function defaultConversations() {
    var refus = refusFromStore();
    var refusText = (refus && refus.message)
      ? refus.message
      : "Bonjour Jonathan, nous vous remercions sincèrement pour l'intérêt porté à notre entreprise. Après une étude attentive, nous avons retenu un autre profil pour ce poste. Nous conservons votre candidature et reviendrons vers vous si une opportunité correspond à votre parcours. Nous vous souhaitons une pleine réussite dans vos recherches.";
    var refusDate = (refus && refus.date) ? refus.date : "2026-08-04";
    return [
      {
        id: "conv-pixel", entreprise: "Pixel & Co", poste: "Développeur web junior", candidature: "Développeur web junior",
        entrepriseId: "pixel-and-co", offreId: "dev-web-junior-pixel-lille", statut: "entretien",
        messages: [
          { from: "them", date: "2026-08-14", text: "Bonjour Jonathan, nous avons bien reçu votre candidature et souhaiterions vous rencontrer. Seriez-vous disponible le 21 août à 14 h en visioconférence ?" },
          { from: "me", date: "2026-08-14", text: "Bonjour, merci beaucoup ! Le 21 août à 14 h me convient parfaitement." },
          { type: "system", date: "2026-08-14", text: "Entretien proposé — 21 août à 14:00 (visioconférence)" }
        ]
      },
      {
        id: "conv-technexis", entreprise: "TechNexis", poste: "Office manager", candidature: "Office manager",
        entrepriseId: "technexis", offreId: "office-manager-technexis-lille", statut: "preselection",
        messages: [
          { from: "them", date: "2026-08-13", text: "Bonjour, votre profil a retenu notre attention pour le poste d'office manager. Pourriez-vous nous préciser vos disponibilités pour un premier échange ?" }
        ]
      },
      {
        id: "conv-refus", entreprise: "Pixel & Co", poste: "Chef de projet digital", candidature: "Chef de projet digital",
        entrepriseId: "pixel-and-co", offreId: "chef-projet-digital-pixel-bordeaux", statut: "non-retenue",
        messages: [
          { from: "me", date: "2026-07-28", text: "Bonjour, je vous adresse ma candidature pour le poste de chef de projet digital. Je reste à votre disposition pour tout complément." },
          { from: "them", date: refusDate, text: refusText }
        ]
      }
    ];
  }

  var _conversations = null;
  var _activeConv = null;
  var _msgFilter = "tous";
  var _msgSearch = "";
  var _readConvs = {};

  var MSG_STATUT_LABEL = { entretien: "Entretien proposé", preselection: "Présélection", "non-retenue": "Candidature non retenue" };

  function convUnread(c) {
    var last = c.messages.filter(function (m) { return m.type !== "system"; }).slice(-1)[0];
    return last && last.from === "them" && !_readConvs[c.id];
  }

  function convMatchesFilter(c) {
    if (_msgFilter === "nonlus") { return convUnread(c); }
    if (_msgFilter === "entretiens") { return c.statut === "entretien"; }
    if (_msgFilter === "candidatures") { return !!c.offreId; }
    return true;
  }

  function convMatchesSearch(c) {
    if (!_msgSearch) { return true; }
    var q = _msgSearch.toLowerCase();
    var hay = (c.entreprise + " " + c.poste + " " + c.messages.map(function (m) { return m.text; }).join(" ")).toLowerCase();
    return hay.indexOf(q) !== -1;
  }

  function renderMessages() {
    var box = document.getElementById("messages-app");
    if (!box) { return; }
    _conversations = _conversations || defaultConversations();

    if (!_conversations.length) {
      box.classList.remove("is-thread-open");
      box.innerHTML = '<div class="empty-state"><h3>Aucune conversation pour le moment.</h3>' +
        "<p>Vos échanges avec les entreprises apparaîtront ici.</p></div>";
      return;
    }

    var e = SS.escapeHtml;
    var FILTERS = [["tous", "Tous"], ["nonlus", "Non lus"], ["entretiens", "Entretiens"], ["candidatures", "Candidatures"]];
    var visibleConvs = _conversations.filter(function (c) { return convMatchesFilter(c) && convMatchesSearch(c); });

    if (!_activeConv || !visibleConvs.some(function (c) { return c.id === _activeConv; })) {
      _activeConv = visibleConvs.length ? visibleConvs[0].id : _conversations[0].id;
    }

    var toolbar =
      '<div class="msg-toolbar">' +
        '<input type="search" class="msg-search" id="msg-search" placeholder="Rechercher une conversation…" value="' + e(_msgSearch) + '" aria-label="Rechercher une conversation">' +
        '<div class="msg-filters" role="group" aria-label="Filtrer les messages">' +
          FILTERS.map(function (f) {
            return '<button type="button" class="chip" aria-pressed="' + (f[0] === _msgFilter) + '" data-msg-filter="' + f[0] + '">' + e(f[1]) + "</button>";
          }).join("") +
        "</div>" +
      "</div>";

    var listHtml = visibleConvs.length ? visibleConvs.map(function (c) {
      var last = c.messages.filter(function (m) { return m.type !== "system"; }).slice(-1)[0] || c.messages[c.messages.length - 1];
      var active = c.id === _activeConv;
      var unread = convUnread(c);
      return '<button type="button" class="msg-conv' + (active ? " is-active" : "") + (unread ? " is-unread" : "") + '" data-conv="' + e(c.id) + '" aria-pressed="' + active + '">' +
        '<span class="msg-conv__name">' + e(c.entreprise) + (unread ? ' <span class="msg-conv__badge" aria-label="Non lu">1</span>' : "") + "</span>" +
        '<span class="msg-conv__poste">' + e(c.poste) + "</span>" +
        '<span class="msg-conv__preview">' + e(last.text.slice(0, 60)) + (last.text.length > 60 ? "…" : "") + "</span>" +
      "</button>";
    }).join("") : '<p class="msg-list__empty text-muted">Aucune conversation ne correspond.</p>';

    var conv = _conversations.filter(function (c) { return c.id === _activeConv; })[0] || _conversations[0];
    var bubbles = conv.messages.map(function (m) {
      if (m.type === "system") {
        return '<div class="msg-system"><span>' + e(m.text) + "</span></div>";
      }
      return '<div class="msg-bubble msg-bubble--' + (m.from === "me" ? "me" : "them") + '">' +
        "<p>" + e(m.text) + "</p>" +
        '<span class="msg-bubble__date">' + e(SS.formatDate(m.date)) + "</span>" +
      "</div>";
    }).join("");

    var statutLabel = conv.statut ? (MSG_STATUT_LABEL[conv.statut] || conv.statut) : "";

    box.innerHTML = toolbar +
      '<div class="msg-panels">' +
      '<div class="msg-list" role="list" aria-label="Conversations">' + listHtml + "</div>" +
      '<div class="msg-thread">' +
        '<div class="msg-thread__head">' +
          '<button type="button" class="btn btn-ghost btn-sm msg-back" data-msg-back>← Conversations</button>' +
          '<strong class="msg-thread__name" tabindex="-1">' + e(conv.entreprise) + "</strong>" +
          '<span class="text-muted">' + e(conv.poste) + "</span>" +
          '<div class="msg-thread__meta">' +
            '<span class="msg-thread__context">Candidature : ' + e(conv.candidature || conv.poste) + "</span>" +
            (statutLabel ? '<span class="status-badge status-' + e(conv.statut) + '">' + e(statutLabel) + "</span>" : "") +
            (conv.offreId ? '<a class="msg-thread__link" href="#candidatures">Voir la candidature</a>' : "") +
          "</div>" +
        "</div>" +
        '<div class="msg-thread__body">' + bubbles + "</div>" +
        '<form class="msg-reply" data-conv="' + e(conv.id) + '">' +
          '<label class="sr-only" for="msg-reply-input">Votre réponse</label>' +
          '<textarea id="msg-reply-input" rows="2" placeholder="Écrire une réponse…"></textarea>' +
          '<button type="submit" class="btn btn-primary btn-sm">Envoyer</button>' +
        "</form>" +
      "</div>" +
      "</div>";

    /* Recherche + filtres */
    var search = box.querySelector("#msg-search");
    if (search) {
      search.addEventListener("input", function () {
        _msgSearch = search.value;
        var pos = search.selectionStart;
        renderMessages();
        var s2 = document.getElementById("msg-search");
        if (s2) { s2.focus(); try { s2.setSelectionRange(pos, pos); } catch (e2) {} }
      });
    }
    box.querySelectorAll("[data-msg-filter]").forEach(function (btn) {
      btn.addEventListener("click", function () { _msgFilter = btn.getAttribute("data-msg-filter"); renderMessages(); });
    });

    box.querySelectorAll(".msg-conv").forEach(function (el) {
      el.addEventListener("click", function () {
        _activeConv = el.getAttribute("data-conv");
        _readConvs[_activeConv] = true; /* marque lu (§38) */
        renderMessages();
        box.classList.add("is-thread-open"); /* mobile : bascule vers le fil */
        var name = box.querySelector(".msg-thread__name");
        if (name) { name.focus(); }
      });
    });

    var back = box.querySelector("[data-msg-back]");
    if (back) {
      back.addEventListener("click", function () { box.classList.remove("is-thread-open"); });
    }

    var form = box.querySelector(".msg-reply");
    if (form) {
      form.addEventListener("submit", function (ev) {
        ev.preventDefault();
        var ta = form.querySelector("textarea");
        var text = ta ? ta.value.trim() : "";
        if (!text) { SS.toast("Écrivez un message avant d'envoyer."); return; }
        conv.messages.push({ from: "me", date: today(), text: text });
        var wasOpen = box.classList.contains("is-thread-open");
        renderMessages();
        if (wasOpen) { box.classList.add("is-thread-open"); }
        SS.toast("Message envoyé (démonstration).");
      });
    }
  }

  /* ============================================================
     Paramètres
     ============================================================ */
  var SETTINGS_KEY = "ss_candidate_settings";
  function fillSettings() {
    var s = SS.auth.get() || {};
    var saved = SS.store.get(SETTINGS_KEY, {}) || {};
    setValue("set-email", saved.email || s.email || "");
    setValue("set-tel", saved.tel || "");
    ["set-notif-offers", "set-notif-status", "set-notif-message", "set-notif-entretien", "set-notif-rappel", "set-notif-news"].forEach(function (id) {
      var el = document.getElementById(id);
      if (el && saved[id] != null) { el.checked = !!saved[id]; }
    });
    setValue("set-langue", saved.langue || "fr");
    setValue("set-comm", saved.comm || "quotidienne");

    /* Sauvegarde automatique de chaque réglage (démonstration). */
    var ids = ["set-email", "set-tel", "set-langue", "set-comm",
      "set-notif-offers", "set-notif-status", "set-notif-message", "set-notif-entretien", "set-notif-rappel", "set-notif-news"];
    ids.forEach(function (id) {
      var el = document.getElementById(id);
      if (!el) { return; }
      var evt = (el.type === "checkbox" || el.tagName === "SELECT") ? "change" : "blur";
      el.addEventListener(evt, function () {
        var store = SS.store.get(SETTINGS_KEY, {}) || {};
        store[id] = el.type === "checkbox" ? el.checked : el.value;
        SS.store.set(SETTINGS_KEY, store);
        SS.toast("Préférences enregistrées.");
      });
    });
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
    /* Entretiens : rendez-vous à venir ou à confirmer (hors passés/annulés). */
    var ivStore = SS.store.get(CONFIRM_KEY, {});
    var ivActive = defaultInterviews().filter(function (it) {
      var t = ivTabOf(it, ivStore); return t === "confirmer" || t === "avenir";
    }).length;
    setText("metric-entretiens", ivActive);
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

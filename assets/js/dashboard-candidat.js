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
    renderFollowedCompanies();
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
  var SEED_VERSION = "2026-08-20-sf-competences";
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
    if (staleSeed || !SS.store.get(CVS_KEY, null)) {
      var d = defaultCv();
      SS.store.set(CVS_KEY, [{ id: "cv-1", name: d.name, date: d.date, principal: true }]);
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
    if (!SS.store.get(FOLLOWED_KEY, null)) {
      SS.store.set(FOLLOWED_KEY, ["fiduciaire-bellecour"]);
    }
  }
  var FOLLOWED_KEY = "ss_candidate_followed";

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
      /* Recherche (champs partagés avec « Ma recherche » du tableau de bord) */
      metier: s.metier || "Développeur web",
      ville: s.city || "Lyon",
      rayon: "30",
      contrat: "CDI",
      tempsTravail: "Temps plein",
      teletravail: "hybride",
      salaireSouhaite: "30 000–36 000 € brut/an",
      niveauEtude: "Bac+3",
      disponibilite: "Immédiate",
      dispoDate: "",
      statut: "active",
      statutVisible: true,
      alternance: {},
      mobilite: { permisB: true, vehicule: false, national: false },
      /* Présentation */
      presentation: "Développeur web junior motivé, deux ans d'expérience en intégration et développement front-end. Je cherche un poste en CDI autour de Lyon pour continuer à monter en compétences en équipe.",
      /* Expériences (cartes structurées) */
      experiences: [
        { poste: "Intégrateur web", entreprise: "Studio Digital Lyon", ville: "Lyon", debut: "Septembre 2024", fin: "Aujourd'hui",
          description: "Intégration de maquettes et développement front-end au sein d'une équipe de 6 personnes.",
          missions: ["Intégration responsive HTML/CSS", "Développement de composants JavaScript", "Maintenance", "Collaboration avec l'équipe design"],
          competences: ["HTML / CSS", "JavaScript", "Git"] },
        { poste: "Stage développement front", entreprise: "Agence Pixel", ville: "Lyon", debut: "2023", fin: "2023 (4 mois)",
          description: "Participation au développement de sites vitrines clients.",
          missions: ["Intégration de pages", "Corrections de bugs"], competences: ["HTML / CSS", "JavaScript"] }
      ],
      /* Formation (cartes structurées) */
      formations: [
        { diplome: "BUT Métiers du Multimédia et de l'Internet (MMI)", ecole: "IUT de Lyon", debut: "2021", fin: "2024", niveau: "Bac+3" }
      ],
      /* Compétences */
      competencesPrincipales: [
        { nom: "HTML / CSS", niveau: "Avancé" }, { nom: "JavaScript", niveau: "Intermédiaire" },
        { nom: "Git", niveau: "Avancé" }, { nom: "Accessibilité", niveau: "Intermédiaire" }
      ],
      competencesComplementaires: ["Travail en équipe", "Organisation", "Communication"],
      /* Réalisations */
      realisationsList: [
        { titre: "Projet e-commerce", description: "Site front-end développé en JavaScript, panier et responsive.",
          competences: ["JavaScript", "Responsive", "Git"], lien: "https://exemple.fr", image: "" }
      ],
      /* Recommandations */
      recommandationsList: [
        { nom: "Marie Dupont", role: "Responsable technique", entreprise: "Studio Digital Lyon",
          texte: "Jonathan est rigoureux et curieux. Il a su prendre en main nos projets rapidement et livrer dans les délais.", date: "mars 2026" }
      ],
      /* Langues, certifications, liens, photo */
      langues: [
        { langue: "Français", niveau: "Langue maternelle" },
        { langue: "Anglais", niveau: "Professionnel" }
      ],
      certifications: [],
      liens: { linkedin: "linkedin.com/in/jonathan-davy", portfolio: "", github: "github.com/jdavy", site: "" },
      telephone: "06 12 34 56 78",
      /* Visibilité des coordonnées (le candidat choisit ; par défaut privé). */
      visibility: { email: "prive", tel: "apres-candidature" },
      hasPhoto: false,
      dateMaj: "2026-08-15"
    };
  }

  /* CV de démonstration : nom de fichier + date récente (aucun contenu stocké). */
  function defaultCv() {
    return { name: "CV_Jonathan_Davy.pdf", date: "2026-08-12" };
  }
  /* Gestion de plusieurs CV (§9) : liste ss_candidate_cvs. Le CV principal est
     resynchronisé dans ss_candidate_cv (clé lue par la candidature et le
     recruteur) pour rester compatible. */
  var CVS_KEY = "ss_candidate_cvs";
  function getCvs() {
    var list = SS.store.get(CVS_KEY, null);
    if (!list) {
      var legacy = SS.store.get(CV_KEY, null);
      list = (legacy && legacy.name) ? [{ id: "cv-1", name: legacy.name, date: legacy.date, principal: true }] : [];
      SS.store.set(CVS_KEY, list);
    }
    return list;
  }
  function setCvs(list) {
    /* Un seul principal ; à défaut, le premier. */
    if (list.length && !list.some(function (c) { return c.principal; })) { list[0].principal = true; }
    SS.store.set(CVS_KEY, list);
    var princ = list.filter(function (c) { return c.principal; })[0] || list[0] || null;
    SS.store.set(CV_KEY, princ ? { name: princ.name, date: princ.date } : { name: "", date: "" });
  }
  function getCv() { var l = getCvs(); var princ = l.filter(function (c) { return c.principal; })[0] || l[0]; return princ ? { name: princ.name, date: princ.date } : null; }
  function setCv(v) { SS.store.set(CV_KEY, v); } /* compat (import initial) */
  function hasCv() { return getCvs().some(function (c) { return c.name; }); }
  function today() { return new Date().toISOString().slice(0, 10); }

  function defaultSavoirFaire() {
    return [
      { id: "sf-1", titre: "Comment je structure un projet web from scratch", resume: "Ma méthode pour démarrer un projet front-end proprement : arborescence, conventions de nommage et outillage minimal.", categorie: "Organisation & méthodes", competences: ["JavaScript", "Git", "Organisation"], note: 4.9, avis: 52, vues: 71, date: "2026-07-20" },
      { id: "sf-2", titre: "Déboguer efficacement avec les DevTools", resume: "Les réflexes que j'utilise au quotidien pour retrouver l'origine d'un bug rapidement, sans y passer la journée.", categorie: "Développement web", competences: ["JavaScript", "Débogage"], note: 4.7, avis: 38, vues: 44, date: "2026-06-30" },
      { id: "sf-3", titre: "Rendre un site accessible : mes 5 vérifications", resume: "Une check-list concrète pour améliorer l'accessibilité d'un site existant sans tout réécrire.", categorie: "Développement web", competences: ["Accessibilité", "HTML / CSS"], note: 4.8, avis: 37, vues: 33, date: "2026-06-10" }
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

  var currentAppFilter = "toutes";
  var APP_FILTERS = [["toutes", "Toutes"], ["encours", "En cours"], ["entretien", "Entretien"], ["decision", "Décision"], ["cloturees", "Clôturées"]];
  var RELANCE_KEY = "ss_cand_relances";
  var RELANCE_DELAY = 7; /* jours sans activité avant de proposer une relance */

  function appCategory(statut) {
    if (statut === "entretien" || statut === "entretien-realise") { return "entretien"; }
    if (statut === "offre-recue" || statut === "recue") { return "decision"; }
    if (statut === "non-retenue" || statut === "refusee" || statut === "retiree") { return "cloturees"; }
    return "encours";
  }
  function lastActivityDate(a) {
    var dates = (a.timeline || []).map(function (s) { return s.date; }).filter(Boolean);
    if (a.dateMaj) { dates.push(a.dateMaj); }
    if (a.dateEnvoi) { dates.push(a.dateEnvoi); }
    dates.sort();
    return dates.length ? dates[dates.length - 1] : a.dateEnvoi;
  }

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
    var relances = SS.store.get(RELANCE_KEY, {}) || {};

    /* Onglets de filtre + compteurs (§8). */
    var counts = { toutes: apps.length, encours: 0, entretien: 0, decision: 0, cloturees: 0 };
    apps.forEach(function (a) { counts[appCategory(a.statut || "envoyee")]++; });
    if (!counts[currentAppFilter]) { currentAppFilter = "toutes"; }
    var tabs = '<div class="appli-tabs" role="tablist" aria-label="Filtrer mes candidatures">' +
      APP_FILTERS.map(function (fl) {
        var on = fl[0] === currentAppFilter;
        return '<button type="button" class="offers-tab chip" role="tab" aria-selected="' + on + '" data-appfilter="' + fl[0] + '">' +
          e(fl[1]) + ' <span class="offers-tab__count">' + counts[fl[0]] + "</span></button>";
      }).join("") + "</div>";

    var visible = apps.filter(function (a) { return currentAppFilter === "toutes" || appCategory(a.statut || "envoyee") === currentAppFilter; });

    var cardsHtml = visible.length ? visible.map(function (a) {
      var statut = a.statut || "envoyee";
      var lastAct = lastActivityDate(a);
      var staleDays = daysBetween(lastAct);
      var canRelance = appCategory(statut) === "encours" && staleDays != null && staleDays >= RELANCE_DELAY;
      var relanceBlock = "";
      if (relances[a.id]) {
        relanceBlock = '<p class="appli-relance appli-relance--done text-muted">Relance envoyée le ' + e(SS.formatDate(relances[a.id])) + ".</p>";
      } else if (canRelance) {
        relanceBlock = '<div class="appli-relance notice"><span>Sans nouvelle depuis ' + staleDays + " jours.</span>" +
          '<button type="button" class="btn btn-outline btn-sm" data-relance="' + e(a.id) + '">Envoyer une relance</button></div>';
      }
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
          " · Envoyée " + e(SS.relativeDate(a.dateEnvoi)) + "</span>" +
          '<span class="appli-card__activity text-muted">Dernière activité : ' + e(SS.relativeDate(lastAct)) + "</span></div>" +
          '<span class="status-badge status-' + e(statut) + '">' + e(STATUT_LABEL[statut] || statut) + "</span>" +
        "</div>" +
        friseHtml(statut) +
        '<ul class="appli-timeline">' + timeline + "</ul>" +
        relanceBlock +
        noteBlock +
        messageBlock +
        '<div class="appli-actions">' +
          (a.offreId ? '<a class="btn btn-outline btn-sm" href="offre-detail.html?id=' + encodeURIComponent(a.offreId) + '">Voir l\'offre</a>' : "") +
          '<details class="appli-menu"><summary class="btn btn-ghost btn-sm">…</summary>' +
            '<div class="fav-card__menu-pop">' +
              (a.entrepriseId ? '<a href="entreprise-detail.html?id=' + encodeURIComponent(a.entrepriseId) + '">Voir l\'entreprise</a>' : "") +
              '<a href="#messages">Envoyer un message</a>' +
              '<button type="button" data-action="note" data-id="' + e(a.id) + '">Ajouter une note</button>' +
              '<button type="button" class="is-danger" data-action="withdraw" data-id="' + e(a.id) + '">Retirer ma candidature</button>' +
            "</div></details>" +
        "</div>" +
        '<div data-note-editor hidden style="margin-top: var(--sp-3);">' +
          '<div class="field"><label for="note-' + e(a.id) + '">Note personnelle <span class="text-muted">(visible uniquement par vous)</span></label>' +
          '<textarea id="note-' + e(a.id) + '" placeholder="Ex. : relancer après l\'entretien.">' + e(a.note || "") + "</textarea></div>" +
          '<button type="button" class="btn btn-primary btn-sm" data-action="save-note" data-id="' + e(a.id) + '">Enregistrer la note</button>' +
        "</div>" +
      "</article>";
    }).join("") : '<div class="empty-state empty-state--inline"><p>Aucune candidature dans cette catégorie.</p></div>';

    box.innerHTML = tabs + cardsHtml;

    box.querySelectorAll("[data-appfilter]").forEach(function (b) {
      b.addEventListener("click", function () { currentAppFilter = b.getAttribute("data-appfilter"); renderApplications(); });
    });
    box.querySelectorAll("[data-relance]").forEach(function (b) {
      b.addEventListener("click", function () {
        var id = b.getAttribute("data-relance");
        var store = SS.store.get(RELANCE_KEY, {}) || {};
        store[id] = today();
        SS.store.set(RELANCE_KEY, store);
        renderApplications();
        SS.toast("Relance envoyée à l'entreprise (démonstration).");
      });
    });
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
        /* Label de correspondance qualitatif (§5), fondé sur les critères,
           sans score opaque. */
        var okCount = reasons.filter(function (r) { return r.ok; }).length;
        var warnCount = reasons.length - okCount;
        var matchLabel, matchCls;
        if (warnCount === 0 && okCount >= 4) { matchLabel = "Correspondance élevée"; matchCls = "is-high"; }
        else if (okCount >= warnCount) { matchLabel = "Correspondance moyenne"; matchCls = "is-mid"; }
        else { matchLabel = "Correspondance partielle"; matchCls = "is-low"; }

        return '<article class="reco-card">' +
          '<div class="reco-card__head">' +
            "<strong>" + e(o.titre) + "</strong>" +
            '<button type="button" class="fav-btn" data-fav="' + e(o.id) + '" aria-pressed="' + isFav + '" aria-label="' +
              (isFav ? "Retirer des favoris" : "Enregistrer en favori") + '">' + (isFav ? "♥" : "♡") + "</button>" +
          "</div>" +
          '<span class="text-muted">' + e(o.entrepriseNom) + " · " + e(o.ville) + "</span>" +
          '<span class="reco-card__match ' + matchCls + '">' + matchLabel + "</span>" +
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

  /* ---- Entreprises suivies (§23) ---- */
  function renderFollowedCompanies() {
    var box = document.getElementById("followed-companies");
    if (!box) { return; }
    var followed = SS.store.get(FOLLOWED_KEY, []) || [];
    if (!followed.length) {
      box.innerHTML = '<div class="empty-state empty-state--inline"><p>Vous ne suivez aucune entreprise.</p>' +
        '<p><a class="btn btn-outline btn-sm" href="entreprises.html">Découvrir des entreprises</a></p></div>';
      return;
    }
    var e = SS.escapeHtml;
    Promise.all([SS.getCompanies(), SS.getActiveOffers()]).then(function (res) {
      var companies = res[0] || [], offers = res[1] || [];
      var byId = {}; companies.forEach(function (c) { byId[c.id] = c; });
      box.innerHTML = followed.map(function (id) {
        var c = byId[id];
        if (!c) { return ""; }
        var comp = offers.filter(function (o) { return o.entrepriseId === id; })
          .sort(function (a, b) { return new Date(b.datePublication) - new Date(a.datePublication); });
        var newest = comp[0];
        var hint = comp.length
          ? '<span class="followed-card__new">' + comp.length + " offre" + (comp.length > 1 ? "s" : "") + " en ligne" + (newest ? " · dernière publiée " + e(SS.relativeDate(newest.datePublication)) : "") + "</span>"
          : '<span class="text-muted">Aucune offre en ce moment.</span>';
        return '<article class="card followed-card">' +
            '<div class="followed-card__body">' +
              "<strong>" + e(c.nom) + (c.verifie ? ' <span class="verified-tick" title="' + e(c.verifieLabel || "Entreprise vérifiée") + '">✓</span>' : "") + "</strong>" +
              '<span class="text-muted">' + e(c.secteur) + " · " + e(c.ville) + "</span>" +
              hint +
            "</div>" +
            '<div class="followed-card__actions">' +
              (newest ? '<a class="btn btn-outline btn-sm" href="offre-detail.html?id=' + encodeURIComponent(newest.id) + '">Voir l\'offre</a>' : "") +
              '<a class="btn btn-ghost btn-sm" href="entreprise-detail.html?id=' + encodeURIComponent(c.id) + '">Voir la fiche</a>' +
              '<button type="button" class="btn btn-ghost btn-sm" data-unfollow="' + e(c.id) + '">Ne plus suivre</button>' +
            "</div>" +
          "</article>";
      }).join("") || '<div class="empty-state empty-state--inline"><p>Ces entreprises ne sont plus disponibles.</p></div>';
      box.querySelectorAll("[data-unfollow]").forEach(function (btn) {
        btn.addEventListener("click", function () {
          var next = (SS.store.get(FOLLOWED_KEY, []) || []).filter(function (x) { return x !== btn.getAttribute("data-unfollow"); });
          SS.store.set(FOLLOWED_KEY, next);
          renderFollowedCompanies();
          SS.toast("Vous ne suivez plus cette entreprise.");
        });
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
  /* ============================================================
     Profil professionnel Postelio : modèle riche + rendu par sections
     (§1-28). Édition en ligne (petits champs, sauvegarde auto) ou formulaire
     par section (contenus plus longs). Aucune donnée réelle n'est envoyée.
     ============================================================ */
  var DISPO_OPTS = ["Immédiate", "Sous 1 mois", "Sous 3 mois", "À partir d'une date"];
  var STATUT_OPTS = [
    { v: "active", l: "En recherche active" },
    { v: "ecoute", l: "À l'écoute d'opportunités" },
    { v: "indispo", l: "Pas disponible actuellement" }
  ];
  var TELE_LABEL = { non: "Sur site", hybride: "Hybride", partiel: "Télétravail partiel", complet: "Télétravail complet" };
  var TELE_BADGE = { hybride: "Télétravail hybride accepté", partiel: "Télétravail partiel", complet: "Télétravail complet" };
  var NIVEAU_COMP = ["Débutant", "Intermédiaire", "Avancé"];
  var LANGUE_NIV = ["Langue maternelle", "Courant", "Professionnel", "Intermédiaire", "Notions"];

  /* Complétion du profil (total = 100 %), avec cibles cliquables. */
  function computeCompletion(profile) {
    var checks = [
      { ok: !!(profile.presentation && profile.presentation.trim()), w: 10, label: "Compléter votre présentation", goto: "presentation" },
      { ok: !!(profile.metier && profile.ville), w: 10, label: "Préciser ce que vous recherchez", goto: "recherche" },
      { ok: (profile.experiences || []).length > 0, w: 15, label: "Ajouter une expérience", goto: "experiences" },
      { ok: (profile.formations || []).length > 0, w: 10, label: "Ajouter votre formation", goto: "formations" },
      { ok: (profile.competencesPrincipales || []).length >= 3, w: 12, label: "Ajouter vos compétences", goto: "competences" },
      { ok: (profile.realisationsList || []).length > 0, w: 10, label: "Ajouter une réalisation", goto: "realisations" },
      { ok: hasCv(), w: 13, label: "Importer votre CV", goto: "cv" },
      { ok: (SS.store.get(SF_KEY, []) || []).length > 0, w: 5, label: "Publier un savoir-faire", goto: "savoirfaire" },
      { ok: (profile.recommandationsList || []).length > 0, w: 5, label: "Ajouter une recommandation", goto: "recommandations" },
      { ok: (profile.langues || []).length > 0, w: 5, label: "Indiquer vos langues", goto: "langues" },
      { ok: !!profile.hasPhoto, w: 5, label: "Ajouter une photo", goto: "photo" }
    ];
    var pct = 0, missing = [];
    checks.forEach(function (c) { if (c.ok) { pct += c.w; } else { missing.push(c); } });
    return { pct: Math.min(100, pct), missing: missing };
  }

  function renderProfile() {
    renderProfileIdentity();
    renderProfileCompletion();
    renderProfileSections();
  }

  /* Sauvegarde du profil + rafraîchissements liés (recherche/reco du tableau
     de bord partagent les mêmes champs). */
  function saveProfile(profile, opts) {
    profile.dateMaj = today();
    setProfile(profile);
    renderProfile();
    if (opts && opts.search) { renderSearchCriteria(); renderRecommendations(); }
    renderProfileSummary();
    if (!opts || opts.toast !== false) { SS.toast((opts && opts.toast) || "Profil mis à jour."); }
  }

  function gotoSection(target) {
    if (target === "photo") {
      var p = getProfile(); p.hasPhoto = !p.hasPhoto;
      saveProfile(p, { toast: p.hasPhoto ? "Photo ajoutée (démonstration)." : "Photo retirée." });
      return;
    }
    if (target === "cv" && !hasCv()) { var i = document.getElementById("cv-file-input"); if (i) { i.click(); return; } }
    if (target === "savoirfaire") { location.hash = "#savoir-faire"; return; }
    var sec = document.querySelector('.profile-sec[data-section="' + target + '"]');
    if (sec) {
      sec.scrollIntoView({ behavior: "smooth", block: "center" });
      sec.classList.add("is-flash");
      setTimeout(function () { sec.classList.remove("is-flash"); }, 1200);
      var editBtn = sec.querySelector("[data-edit], [data-add-toggle]");
      if (editBtn) { setTimeout(function () { editBtn.focus(); }, 350); }
    }
  }

  /* ---- 1-2-18-19 : en-tête d'identité, statut, badges, actions ---- */
  function renderProfileIdentity() {
    var box = document.getElementById("profile-identity");
    if (!box) { return; }
    var p = getProfile();
    var e = SS.escapeHtml;
    var res = computeCompletion(p);

    var dispo = (p.disponibilite || "").trim();
    var dispoTxt = /imm[ée]diat/i.test(dispo) ? "Disponible immédiatement"
      : (dispo === "À partir d'une date" && p.dispoDate) ? "Disponible à partir du " + SS.formatDate(p.dispoDate)
      : dispo ? "Disponible " + dispo.toLowerCase() : "";

    var badges = [];
    if (p.contrat) { badges.push(e(p.contrat) + " recherché"); }
    if (p.ville) { badges.push(e(p.ville) + (p.rayon ? " +" + e(p.rayon) + " km" : "")); }
    if (TELE_BADGE[p.teletravail]) { badges.push(TELE_BADGE[p.teletravail]); }
    var badgesHtml = badges.map(function (b) { return '<span class="badge badge--accent">' + b + "</span>"; }).join("");

    var statutHtml = STATUT_OPTS.map(function (o) {
      var on = (p.statut || "active") === o.v;
      return '<button type="button" class="chip profile-statut__opt" aria-pressed="' + on + '" data-statut="' + o.v + '">' + e(o.l) + "</button>";
    }).join("");

    var avatar = p.hasPhoto
      ? '<span class="avatar profile-identity__avatar profile-identity__avatar--photo" aria-hidden="true">' + e(SS.auth.initials()) + "</span>"
      : '<span class="avatar profile-identity__avatar" aria-hidden="true">' + e(SS.auth.initials()) + "</span>";

    box.innerHTML =
      '<div class="profile-identity__top">' +
        '<div class="profile-identity__head">' +
          avatar +
          '<div class="profile-identity__info">' +
            '<strong class="profile-identity__name">' + e(SS.auth.displayName() || "Candidat") + "</strong>" +
            '<span class="profile-identity__role">' + e(p.metier || "Métier à préciser") + (p.ville ? " · " + e(p.ville) : "") + "</span>" +
            (dispoTxt ? '<span class="badge badge--remote profile-identity__dispo">' + e(dispoTxt) + "</span>" : "") +
          "</div>" +
        "</div>" +
        '<div class="profile-identity__actions">' +
          '<button type="button" class="btn btn-outline btn-sm" data-profile-public>Voir mon profil comme un recruteur</button>' +
          '<button type="button" class="btn btn-ghost btn-sm" data-goto="recherche">Modifier mes informations principales</button>' +
        "</div>" +
      "</div>" +
      (badgesHtml ? '<div class="profile-identity__badges">' + badgesHtml + "</div>" : "") +
      '<div class="profile-statut">' +
        '<span class="profile-statut__label">Statut professionnel</span>' +
        '<div class="profile-statut__opts" role="group" aria-label="Statut de recherche">' + statutHtml + "</div>" +
        '<label class="field--checkbox profile-statut__vis"><input type="checkbox" data-statut-vis' + (p.statutVisible !== false ? " checked" : "") + "><span>Rendre mon statut visible par les recruteurs</span></label>" +
      "</div>" +
      '<div class="profile-identity__meter">' +
        '<div class="profile-meter" aria-hidden="true"><span style="width:' + res.pct + '%"></span></div>' +
        '<p class="profile-meter__label">Profil complété à <strong>' + res.pct + "&nbsp;%</strong></p>" +
      "</div>";

    var pub = box.querySelector("[data-profile-public]");
    if (pub) { pub.addEventListener("click", openPublicProfile); }
    box.querySelectorAll("[data-goto]").forEach(function (b) { b.addEventListener("click", function () { gotoSection(b.getAttribute("data-goto")); }); });
    box.querySelectorAll("[data-statut]").forEach(function (b) {
      b.addEventListener("click", function () {
        var prof = getProfile(); prof.statut = b.getAttribute("data-statut");
        setProfile(prof); renderProfileIdentity();
        SS.toast("Statut mis à jour : " + STATUT_OPTS.filter(function (o) { return o.v === prof.statut; })[0].l.toLowerCase() + ".");
      });
    });
    var vis = box.querySelector("[data-statut-vis]");
    if (vis) {
      vis.addEventListener("change", function () {
        var prof = getProfile(); prof.statutVisible = vis.checked; setProfile(prof);
        SS.toast(vis.checked ? "Statut visible par les recruteurs." : "Statut masqué aux recruteurs.");
      });
    }
  }

  /* ---- 17 : conseils de complétion cliquables ---- */
  function renderProfileCompletion() {
    var box = document.getElementById("profile-completion");
    if (!box) { return; }
    var res = computeCompletion(getProfile());
    var e = SS.escapeHtml;
    if (!res.missing.length) {
      box.hidden = false;
      box.innerHTML = '<p class="notice notice--demo" style="margin:0;">Bravo, votre profil est complet — les recruteurs ont toutes les informations utiles.</p>';
      return;
    }
    var tips = res.missing.slice().sort(function (a, b) { return b.w - a.w; }).slice(0, 3);
    box.hidden = false;
    box.innerHTML = '<p class="profile-tips__title">Pour atteindre 100 % :</p>' +
      '<ul class="profil-todo">' + tips.map(function (c) {
        return '<li><button type="button" class="profil-todo__btn" data-goto="' + c.goto + '">+ ' + e(c.label) + " <span class=\"text-muted\">+" + c.w + " %</span></button></li>";
      }).join("") + "</ul>";
    box.querySelectorAll("[data-goto]").forEach(function (b) { b.addEventListener("click", function () { gotoSection(b.getAttribute("data-goto")); }); });
  }

  /* ============================================================
     Rendu de toutes les sections (ordre §24)
     ============================================================ */
  function renderProfileSections() {
    var box = document.getElementById("profile-sections");
    if (!box) { return; }
    box.innerHTML =
      sectionRecherche() +
      sectionCv() +
      sectionPresentation() +
      sectionExperiences() +
      sectionFormations() +
      sectionCompetences() +
      sectionRealisations() +
      sectionSavoirFaire() +
      sectionLangues() +
      sectionCertifications() +
      sectionRecommandations() +
      sectionLiens() +
      sectionVisibilite();
    wireRecherche(box);
    wireCv(box);
    wirePresentation(box);
    wireList(box, "experiences", experienceFormHtml, readExperienceForm);
    wireList(box, "formations", formationFormHtml, readFormationForm);
    wireCompetences(box);
    wireList(box, "realisations", realisationFormHtml, readRealisationForm);
    wireLangues(box);
    wireCertifications(box);
    wireRecommandations2(box);
    wireLiens(box);
    wireVisibilite(box);
  }

  function secCard(id, title, action, body, note) {
    var e = SS.escapeHtml;
    return '<div class="profile-sec card" data-section="' + id + '">' +
      '<div class="profile-sec__head"><h3>' + e(title) + "</h3>" + (action || "") + "</div>" +
      (note ? '<p class="profile-sec__note text-muted">' + note + "</p>" : "") +
      '<div class="profile-sec__body">' + body + "</div></div>";
  }
  function editBtn() { return '<button type="button" class="btn btn-ghost btn-sm" data-edit>Modifier</button>'; }
  function addBtn(label) { return '<button type="button" class="btn btn-ghost btn-sm" data-add-toggle aria-expanded="false">' + label + "</button>"; }
  function empty(txt) { return '<p class="profile-row__empty">' + txt + "</p>"; }
  function formActions() { return '<div class="form-actions"><button type="button" class="btn btn-primary btn-sm" data-save>Enregistrer</button><button type="button" class="btn btn-ghost btn-sm" data-cancel>Annuler</button></div>'; }

  /* ---- 4 + 20 : Ce que je recherche (inclut la mobilité, une seule fois) ---- */
  var NIVEAU_ETUDE = ["", "Sans diplôme", "CAP / BEP", "Bac", "Bac+2", "Bac+3", "Bac+5 et plus"];
  var TEMPS_OPTS = ["Temps plein", "Temps partiel"];
  var RYTHME_OPTS = ["", "1 semaine / 1 semaine", "2 jours / 3 jours", "3 jours / 2 jours", "1 jour / semaine", "Autre"];

  function sectionRecherche() {
    var p = getProfile();
    var e = SS.escapeHtml;
    var mob = p.mobilite || {};
    var alt = p.alternance || {};
    var isAlt = p.contrat === "Alternance";
    var dispo = p.disponibilite === "À partir d'une date" && p.dispoDate ? "À partir du " + SS.formatDate(p.dispoDate) : (p.disponibilite || "—");
    var view = '<dl class="profile-def">' +
      row("Métier recherché", p.metier) +
      row("Localisation", p.ville) +
      row("Rayon", p.rayon ? p.rayon + " km" : "") +
      row("Contrat", p.contrat) +
      row("Temps de travail", p.tempsTravail) +
      row("Télétravail", TELE_LABEL[p.teletravail] || "") +
      row("Salaire souhaité", p.salaireSouhaite) +
      row("Niveau d'étude", p.niveauEtude) +
      row("Disponibilité", dispo) +
      row("Mobilité", (p.rayon ? p.rayon + " km autour de " + (p.ville || "") : "") +
        [mob.permisB ? "Permis B" : "", mob.vehicule ? "Véhicule" : "", mob.national ? "Mobilité nationale" : ""].filter(Boolean).map(function (x) { return " · " + x; }).join("")) +
      "</dl>" +
      /* Bloc alternance affiché uniquement pour un contrat en alternance (§6). */
      (isAlt && (alt.formation || alt.ecole || alt.rythme) ?
        '<div class="profile-alt"><h4 class="profile-subh">Alternance</h4><dl class="profile-def">' +
          row("Formation préparée", alt.formation) + row("École", alt.ecole) + row("Niveau visé", alt.niveau) +
          row("Rythme", alt.rythme) + row("Début", alt.debut) + row("Durée", alt.duree) +
        "</dl></div>" : "");
    function row(l, v) { return "<div><dt>" + e(l) + "</dt><dd>" + (v && String(v).trim() ? e(v) : '<span class="profile-row__empty">Non renseigné</span>') + "</dd></div>"; }

    var edit =
      '<div class="profile-sec__edit" hidden><div class="form-row">' +
        f("Métier recherché", '<input id="rq-metier" value="' + e(p.metier || "") + '">') +
        f("Localisation", '<input id="rq-ville" value="' + e(p.ville || "") + '">') +
      "</div><div class=\"form-row\">" +
        f("Rayon", sel("rq-rayon", ["10", "30", "50", "100"], String(p.rayon || "30"), function (r) { return r + " km"; })) +
        f("Contrat", sel("rq-contrat", ["CDI", "CDD", "Intérim", "Alternance", "Stage", ""], p.contrat || "", function (c) { return c || "Indifférent"; })) +
      "</div><div class=\"form-row\">" +
        f("Temps de travail", sel("rq-temps", TEMPS_OPTS, p.tempsTravail || "Temps plein", null)) +
        f("Télétravail", sel("rq-tele", ["non", "hybride", "partiel", "complet"], p.teletravail || "non", function (v) { return TELE_LABEL[v]; })) +
      "</div><div class=\"form-row\">" +
        f("Salaire souhaité (optionnel)", '<input id="rq-salaire" value="' + e(p.salaireSouhaite || "") + '" placeholder="Ex. : 30 000 €">') +
        f("Niveau d'étude", sel("rq-niveau", NIVEAU_ETUDE, p.niveauEtude || "", function (n) { return n || "À préciser"; })) +
      "</div><div class=\"form-row\">" +
        f("Disponibilité", sel("rq-dispo", DISPO_OPTS, p.disponibilite || "Immédiate", null)) +
        f("Date (si applicable)", '<input type="date" id="rq-dispodate" value="' + e(p.dispoDate || "") + '">') +
      "</div>" +
      '<fieldset class="profile-alt-fields" id="rq-alt"' + (isAlt ? "" : " hidden") + '><legend>Alternance</legend><div class="form-row">' +
        f("Formation préparée", '<input id="rq-alt-formation" value="' + e(alt.formation || "") + '" placeholder="Ex. : BUT MMI 3e année">') +
        f("École", '<input id="rq-alt-ecole" value="' + e(alt.ecole || "") + '">') +
      "</div><div class=\"form-row\">" +
        f("Niveau visé", sel("rq-alt-niveau", NIVEAU_ETUDE, alt.niveau || "", function (n) { return n || "À préciser"; })) +
        f("Rythme", sel("rq-alt-rythme", RYTHME_OPTS, alt.rythme || "", function (r) { return r || "À préciser"; })) +
      "</div><div class=\"form-row\">" +
        f("Date de début", '<input id="rq-alt-debut" value="' + e(alt.debut || "") + '" placeholder="Ex. : septembre 2026">') +
        f("Durée", '<input id="rq-alt-duree" value="' + e(alt.duree || "") + '" placeholder="Ex. : 12 mois">') +
      "</div></fieldset>" +
      '<fieldset class="profile-mob"><legend>Mobilité</legend>' +
        cb("rq-permis", "Permis B", mob.permisB) +
        cb("rq-vehicule", "Véhicule personnel", mob.vehicule) +
        cb("rq-national", "Mobilité nationale", mob.national) +
      "</fieldset>" +
      formActions() + "</div>";

    return secCard("recherche", "Ma recherche", editBtn(), view + edit,
      "Ces informations disent aux recruteurs ce que vous cherchez — distinctes de votre expérience.");
  }
  function wireRecherche(box) {
    var sec = box.querySelector('.profile-sec[data-section="recherche"]');
    toggleEditor(sec);
    /* Affiche/masque le bloc alternance selon le contrat choisi (§6). */
    var contratSel = sec.querySelector("#rq-contrat");
    var altBlock = sec.querySelector("#rq-alt");
    if (contratSel && altBlock) {
      contratSel.addEventListener("change", function () { altBlock.hidden = contratSel.value !== "Alternance"; });
    }
    var save = sec.querySelector("[data-save]");
    if (save) {
      save.addEventListener("click", function () {
        var p = getProfile();
        p.metier = valOf("rq-metier"); p.ville = valOf("rq-ville"); p.rayon = valOf("rq-rayon");
        p.contrat = valOf("rq-contrat"); p.tempsTravail = valOf("rq-temps"); p.teletravail = valOf("rq-tele");
        p.salaireSouhaite = valOf("rq-salaire"); p.niveauEtude = valOf("rq-niveau");
        p.disponibilite = valOf("rq-dispo"); p.dispoDate = valOf("rq-dispodate");
        p.mobilite = { permisB: chk("rq-permis"), vehicule: chk("rq-vehicule"), national: chk("rq-national") };
        if (p.contrat === "Alternance") {
          p.alternance = { formation: valOf("rq-alt-formation"), ecole: valOf("rq-alt-ecole"), niveau: valOf("rq-alt-niveau"),
            rythme: valOf("rq-alt-rythme"), debut: valOf("rq-alt-debut"), duree: valOf("rq-alt-duree") };
        }
        saveProfile(p, { search: true });
      });
    }
  }

  /* ---- 7-8-9-10 : Gestion de CV (1-2 max, principal, aperçu, import simulé) ---- */
  var MAX_CV = 2;
  function sectionCv() {
    var e = SS.escapeHtml;
    var cvs = getCvs();
    var body;
    if (!cvs.length) {
      body = '<div class="empty-state empty-state--inline"><p>Aucun CV importé.</p>' +
        '<p><button type="button" class="btn btn-primary btn-sm" data-cv-import>Importer mon CV</button></p></div>';
    } else {
      body = '<p class="cv-visibility">Visible par les recruteurs : <strong>Oui</strong></p>' +
        cvs.map(function (cv) {
          return '<div class="cand-cv__file" data-cv-id="' + e(cv.id) + '">' +
              '<span class="cand-cv__icon" aria-hidden="true">' + ICON_DOC + "</span>" +
              '<span class="cand-cv__meta"><strong>' + e(cv.name) + (cv.principal ? ' <span class="badge badge--accent cv-principal">Principal</span>' : "") + "</strong>" +
              '<span class="text-muted">Mis à jour le ' + e(SS.formatDate(cv.date)) + "</span></span>" +
              '<div class="cand-cv__actions">' +
                '<button type="button" class="btn btn-outline btn-xs" data-cv-preview="' + e(cv.id) + '">Aperçu</button>' +
                (cv.principal ? "" : '<button type="button" class="btn btn-ghost btn-xs" data-cv-principal="' + e(cv.id) + '">Définir principal</button>') +
                '<details class="cand-cv__menu"><summary class="btn btn-ghost btn-xs">…</summary>' +
                  '<div class="cand-cv__menu-pop">' +
                    '<button type="button" data-cv-download="' + e(cv.id) + '">Télécharger</button>' +
                    '<button type="button" class="is-danger" data-cv-del="' + e(cv.id) + '">Supprimer</button>' +
                  "</div></details>" +
              "</div>" +
            "</div>";
        }).join("") +
        (cvs.length < MAX_CV
          ? '<button type="button" class="btn btn-outline btn-sm cv-add" data-cv-import>Importer un autre CV</button>'
          : '<p class="form-hint">Vous pouvez conserver jusqu\'à ' + MAX_CV + " CV.</p>");
    }
    return secCard("cv", "Mon CV", "", body +
      '<div class="cv-import-hint" hidden><p class="notice notice--demo">Bientôt : Postelio pourra détecter automatiquement les informations de votre CV et vous proposer de compléter votre profil.</p></div>' +
      '<input type="file" id="cv-file-input" accept=".pdf,.doc,.docx" class="sr-only" tabindex="-1" aria-hidden="true">');
  }
  function wireCv(box) {
    var sec = box.querySelector('.profile-sec[data-section="cv"]');
    if (!sec) { return; }
    var fileInput = sec.querySelector("#cv-file-input");
    sec.querySelectorAll("[data-cv-import]").forEach(function (b) { b.addEventListener("click", function () { if (fileInput) { fileInput.click(); } }); });
    sec.querySelectorAll("[data-cv-preview]").forEach(function (b) { b.addEventListener("click", function () { openCvViewer(cvById(b.getAttribute("data-cv-preview"))); }); });
    sec.querySelectorAll("[data-cv-download]").forEach(function (b) { b.addEventListener("click", function () { SS.toast("Téléchargement du CV (démonstration)."); }); });
    sec.querySelectorAll("[data-cv-principal]").forEach(function (b) {
      b.addEventListener("click", function () {
        var id = b.getAttribute("data-cv-principal");
        var list = getCvs().map(function (c) { c.principal = (c.id === id); return c; });
        setCvs(list); renderProfile(); renderProfileSummary(); SS.toast("CV principal mis à jour.");
      });
    });
    sec.querySelectorAll("[data-cv-del]").forEach(function (b) {
      b.addEventListener("click", function () {
        if (!window.confirm("Supprimer ce CV ?")) { return; }
        var id = b.getAttribute("data-cv-del");
        setCvs(getCvs().filter(function (c) { return c.id !== id; }));
        renderProfile(); renderProfileSummary(); SS.toast("CV supprimé.");
      });
    });
    if (fileInput) {
      fileInput.addEventListener("change", function () {
        var file = fileInput.files && fileInput.files[0];
        if (!file) { return; }
        var list = getCvs();
        if (list.length >= MAX_CV) { SS.toast("Vous avez atteint le maximum de " + MAX_CV + " CV."); fileInput.value = ""; return; }
        list.push({ id: "cv-" + (Date.now()), name: file.name, date: today(), principal: list.length === 0 });
        setCvs(list);
        fileInput.value = "";
        renderProfile(); renderProfileSummary();
        SS.toast("CV importé : " + file.name);
        var hint = document.querySelector(".cv-import-hint"); if (hint) { hint.hidden = false; }
      });
    }
  }
  function cvById(id) { return getCvs().filter(function (c) { return c.id === id; })[0] || null; }

  /* ---- 7 : Présentation (texte libre + aide + limite) ---- */
  function sectionPresentation() {
    var p = getProfile();
    var e = SS.escapeHtml;
    var view = p.presentation && p.presentation.trim()
      ? '<p class="profile-row__value">' + e(p.presentation).replace(/\n/g, "<br>") + "</p>"
      : empty("Non renseigné");
    var edit = '<div class="profile-sec__edit" hidden>' +
      '<div class="field"><label for="pf-presentation">À propos de moi</label>' +
      '<p class="form-hint">Présentez votre métier, votre expérience et ce que vous recherchez en quelques lignes.</p>' +
      '<textarea id="pf-presentation" rows="4" maxlength="600">' + e(p.presentation || "") + "</textarea>" +
      '<p class="field-hint text-muted" id="pres-count"></p></div>' + formActions() + "</div>";
    return secCard("presentation", "À propos", editBtn(), view + edit);
  }
  function wirePresentation(box) {
    var sec = box.querySelector('.profile-sec[data-section="presentation"]');
    toggleEditor(sec);
    var ta = sec.querySelector("#pf-presentation");
    var count = sec.querySelector("#pres-count");
    function upd() { if (count && ta) { count.textContent = (ta.value.length) + " / 600 caractères"; } }
    if (ta) { ta.addEventListener("input", upd); upd(); }
    var save = sec.querySelector("[data-save]");
    if (save) { save.addEventListener("click", function () { var p = getProfile(); p.presentation = (ta.value || "").trim(); saveProfile(p); }); }
  }

  /* ---- 8 : Expériences (cartes) ---- */
  function sectionExperiences() {
    var p = getProfile();
    var e = SS.escapeHtml;
    var list = p.experiences || [];
    var cards = list.length ? list.map(function (x, i) {
      var comps = (x.competences || []).map(function (c) { return '<span class="badge badge--neutral">' + e(c) + "</span>"; }).join("");
      var miss = (x.missions || []).length ? "<ul class=\"profile-card__missions\">" + x.missions.map(function (m) { return "<li>" + e(m) + "</li>"; }).join("") + "</ul>" : "";
      return '<article class="profile-card" data-item="' + i + '">' +
        '<div class="profile-card__head"><div><strong>' + e(x.poste || "") + "</strong>" +
          '<span class="profile-card__org">' + e(x.entreprise || "") + (x.ville ? " · " + e(x.ville) : "") + "</span></div>" +
          '<span class="profile-card__dates">' + e((x.debut || "") + (x.fin ? " — " + x.fin : "")) + "</span></div>" +
        (x.description ? '<p class="profile-card__desc">' + e(x.description) + "</p>" : "") +
        miss +
        (comps ? '<div class="profile-card__tags">' + comps + "</div>" : "") +
        '<div class="profile-card__actions"><button type="button" class="btn btn-ghost btn-xs" data-item-edit="' + i + '">Modifier</button>' +
          '<button type="button" class="btn btn-ghost btn-xs is-danger" data-item-del="' + i + '">Retirer</button></div>' +
      "</article>";
    }).join("") : empty("Aucune expérience pour l'instant.");
    var form = '<div class="profile-sec__edit profile-item-form" hidden>' + experienceFormHtml({}) + formActions() + "</div>";
    return secCard("experiences", "Expériences", addBtn("+ Ajouter une expérience"), cards + form);
  }
  function experienceFormHtml(x) {
    var e = SS.escapeHtml; x = x || {};
    var current = (x.fin || "").toLowerCase().indexOf("aujourd") !== -1;
    return '<div class="form-row">' +
        f("Poste", '<input data-fld="poste" value="' + e(x.poste || "") + '">') +
        f("Entreprise", '<input data-fld="entreprise" value="' + e(x.entreprise || "") + '">') +
      "</div><div class=\"form-row\">" +
        f("Ville", '<input data-fld="ville" value="' + e(x.ville || "") + '">') +
        f("Début", '<input data-fld="debut" value="' + e(x.debut || "") + '" placeholder="Septembre 2024">') +
      "</div><div class=\"form-row\">" +
        f("Fin", '<input data-fld="fin" value="' + e(x.fin || "") + '" placeholder="2026">') +
        '<label class="field--checkbox exp-current"><input type="checkbox" data-fld-current' + (current ? " checked" : "") + "><span>Poste actuel</span></label>" +
      "</div>" +
      f("Description", '<textarea data-fld="description" rows="2">' + e(x.description || "") + "</textarea>") +
      f("Missions principales (une par ligne)", '<textarea data-fld="missions" rows="3">' + e((x.missions || []).join("\n")) + "</textarea>") +
      f("Compétences utilisées (séparées par des virgules)", '<input data-fld="competences" value="' + e((x.competences || []).join(", ")) + '">');
  }
  function readExperienceForm(scope) {
    var current = scope.querySelector("[data-fld-current]");
    return {
      poste: fld(scope, "poste"), entreprise: fld(scope, "entreprise"), ville: fld(scope, "ville"),
      debut: fld(scope, "debut"),
      fin: (current && current.checked) ? "Aujourd'hui" : fld(scope, "fin"),
      description: fld(scope, "description"),
      missions: lines(fld(scope, "missions")),
      competences: commas(fld(scope, "competences"))
    };
  }

  /* ---- 9 : Formation (cartes) ---- */
  function sectionFormations() {
    var p = getProfile();
    var e = SS.escapeHtml;
    var list = p.formations || [];
    var cards = list.length ? list.map(function (x, i) {
      return '<article class="profile-card" data-item="' + i + '">' +
        '<div class="profile-card__head"><div><strong>' + e(x.diplome || "") + "</strong>" +
          '<span class="profile-card__org">' + e(x.ecole || "") + "</span></div>" +
          '<span class="profile-card__dates">' + e((x.debut || "") + (x.fin ? " — " + x.fin : "")) + "</span></div>" +
        (x.niveau ? '<p class="profile-card__desc">Niveau : ' + e(x.niveau) + "</p>" : "") +
        '<div class="profile-card__actions"><button type="button" class="btn btn-ghost btn-xs" data-item-edit="' + i + '">Modifier</button>' +
          '<button type="button" class="btn btn-ghost btn-xs is-danger" data-item-del="' + i + '">Retirer</button></div>' +
      "</article>";
    }).join("") : empty("Aucune formation pour l'instant.");
    var form = '<div class="profile-sec__edit profile-item-form" hidden>' + formationFormHtml({}) + formActions() + "</div>";
    return secCard("formations", "Formation", addBtn("+ Ajouter une formation"), cards + form);
  }
  function formationFormHtml(x) {
    var e = SS.escapeHtml; x = x || {};
    return '<div class="form-row">' +
        f("Diplôme", '<input data-fld="diplome" value="' + e(x.diplome || "") + '">') +
        f("Établissement", '<input data-fld="ecole" value="' + e(x.ecole || "") + '">') +
      "</div><div class=\"form-row\">" +
        f("Début", '<input data-fld="debut" value="' + e(x.debut || "") + '" placeholder="2021">') +
        f("Fin", '<input data-fld="fin" value="' + e(x.fin || "") + '" placeholder="2024">') +
      "</div>" +
      f("Niveau", sel2("niveau", ["", "CAP / BEP", "Bac", "Bac+2", "Bac+3", "Bac+5 et plus"], x.niveau || "", function (n) { return n || "À préciser"; }));
  }
  function readFormationForm(scope) {
    return { diplome: fld(scope, "diplome"), ecole: fld(scope, "ecole"), debut: fld(scope, "debut"), fin: fld(scope, "fin"), niveau: fld(scope, "niveau") };
  }

  /* ---- 10-11 : Compétences (principales avec niveau + complémentaires) ---- */
  function sectionCompetences() {
    var p = getProfile();
    var e = SS.escapeHtml;
    var princ = p.competencesPrincipales || [];
    var compl = p.competencesComplementaires || [];
    var principHtml = princ.length ? '<div class="profile-row__tags">' + princ.map(function (c) {
      return '<span class="skill-badge">' + e(c.nom) + (c.niveau ? ' <span class="skill-badge__lvl">' + e(c.niveau) + "</span>" : "") + "</span>";
    }).join("") + "</div>" : empty("Aucune compétence principale.");
    var complHtml = compl.length ? '<div class="profile-row__tags">' + compl.map(function (c) { return '<span class="badge badge--neutral">' + e(c) + "</span>"; }).join("") + "</div>" : "";

    var rows = "";
    for (var i = 0; i < 5; i++) {
      var it = princ[i] || {};
      rows += '<div class="skill-row"><input class="skill-row__name" data-skill-name value="' + e(it.nom || "") + '" placeholder="Compétence ' + (i + 1) + '">' +
        sel3("skill-lvl", ["", "Débutant", "Intermédiaire", "Avancé"], it.niveau || "", function (n) { return n || "Niveau (optionnel)"; }) + "</div>";
    }
    var edit = '<div class="profile-sec__edit" hidden>' +
      '<p class="form-hint">Mettez en avant jusqu\'à 5 compétences principales (niveau optionnel).</p>' +
      '<div class="skill-rows">' + rows + "</div>" +
      f("Compétences complémentaires (séparées par des virgules)", '<input id="comp-compl" value="' + e(compl.join(", ")) + '">') +
      '<div class="skill-suggest" id="comp-suggest"></div>' +
      formActions() + "</div>";

    return secCard("competences", "Compétences",
      editBtn(),
      '<h4 class="profile-subh">Compétences principales</h4>' + principHtml +
      (complHtml ? '<h4 class="profile-subh">Compétences complémentaires</h4>' + complHtml : "") + edit);
  }
  function wireCompetences(box) {
    var sec = box.querySelector('.profile-sec[data-section="competences"]');
    toggleEditor(sec);
    /* Suggestions selon le métier */
    var sug = sec.querySelector("#comp-suggest");
    if (sug) {
      var p = getProfile();
      var have = (p.competencesPrincipales || []).map(function (c) { return normalize(c.nom); });
      var list = skillSuggestions(p.metier).filter(function (s) { return have.indexOf(normalize(s)) === -1; });
      if (list.length) {
        sug.innerHTML = '<span class="skill-suggest__label">Suggestions :</span> ' + list.map(function (s) { return '<button type="button" class="chip" data-sug="' + SS.escapeHtml(s) + '">+ ' + SS.escapeHtml(s) + "</button>"; }).join("");
        sug.querySelectorAll("[data-sug]").forEach(function (b) {
          b.addEventListener("click", function () {
            var rows = sec.querySelectorAll(".skill-row");
            for (var i = 0; i < rows.length; i++) {
              var nameInput = rows[i].querySelector("[data-skill-name]");
              if (!nameInput.value.trim()) { nameInput.value = b.getAttribute("data-sug"); b.remove(); break; }
            }
          });
        });
      }
    }
    var save = sec.querySelector("[data-save]");
    if (save) {
      save.addEventListener("click", function () {
        var p = getProfile();
        var princ = [];
        sec.querySelectorAll(".skill-row").forEach(function (r) {
          var nom = (r.querySelector("[data-skill-name]").value || "").trim();
          var niveau = (r.querySelector("select").value || "").trim();
          if (nom) { princ.push({ nom: nom, niveau: niveau }); }
        });
        p.competencesPrincipales = princ.slice(0, 5);
        p.competencesComplementaires = commas(valOf("comp-compl"));
        saveProfile(p);
      });
    }
  }

  /* ---- 12 : Réalisations (cartes enrichies) ---- */
  function sectionRealisations() {
    var p = getProfile();
    var e = SS.escapeHtml;
    var list = p.realisationsList || [];
    var cards = list.length ? list.map(function (x, i) {
      var comps = (x.competences || []).map(function (c) { return '<span class="badge badge--neutral">' + e(c) + "</span>"; }).join("");
      return '<article class="profile-card" data-item="' + i + '">' +
        '<div class="profile-card__head"><strong>' + e(x.titre || "Réalisation") + "</strong></div>" +
        (x.description ? '<p class="profile-card__desc">' + e(x.description) + "</p>" : "") +
        (comps ? '<div class="profile-card__tags">' + comps + "</div>" : "") +
        (x.image ? '<p class="profile-card__img text-muted">Image : ' + e(x.image) + "</p>" : "") +
        (x.lien ? '<p><a class="profile-card__link" href="' + e(x.lien) + '" target="_blank" rel="noopener">Voir le projet ↗</a></p>' : "") +
        '<div class="profile-card__actions"><button type="button" class="btn btn-ghost btn-xs" data-item-edit="' + i + '">Modifier</button>' +
          '<button type="button" class="btn btn-ghost btn-xs is-danger" data-item-del="' + i + '">Retirer</button></div>' +
      "</article>";
    }).join("") : empty("Aucune réalisation pour l'instant.");
    var form = '<div class="profile-sec__edit profile-item-form" hidden>' + realisationFormHtml({}) + formActions() + "</div>";
    return secCard("realisations", "Réalisations", addBtn("+ Ajouter une réalisation"), cards + form);
  }
  function realisationFormHtml(x) {
    var e = SS.escapeHtml; x = x || {};
    return f("Titre du projet", '<input data-fld="titre" value="' + e(x.titre || "") + '" placeholder="Ex. : Projet e-commerce">') +
      f("Description", '<textarea data-fld="description" rows="2" placeholder="Ce que vous avez réalisé…">' + e(x.description || "") + "</textarea>") +
      f("Compétences (séparées par des virgules)", '<input data-fld="competences" value="' + e((x.competences || []).join(", ")) + '" placeholder="JavaScript, Responsive, Git">') +
      '<div class="form-row">' +
        f("Lien (optionnel)", '<input data-fld="lien" value="' + e(x.lien || "") + '" placeholder="https://…">') +
        f("Image (optionnel)", '<input data-fld="image" value="' + e(x.image || "") + '" placeholder="nom-image.jpg">') +
      "</div>";
  }
  function readRealisationForm(scope) {
    return { titre: fld(scope, "titre"), description: fld(scope, "description"), competences: commas(fld(scope, "competences")), lien: fld(scope, "lien"), image: fld(scope, "image") };
  }

  /* ---- 13 : Savoir-faire (3 meilleurs, lecture seule) ---- */
  function sectionSavoirFaire() {
    var e = SS.escapeHtml;
    var list = (SS.store.get(SF_KEY, []) || []).slice().sort(function (a, b) { return (b.note || 0) - (a.note || 0); });
    var body;
    if (!list.length) {
      body = '<div class="empty-state empty-state--inline"><p>Aucun savoir-faire publié.</p>' +
        '<p><a class="btn btn-primary btn-sm" href="publier-savoir-faire.html?type=candidat">Publier mon premier savoir-faire</a></p></div>';
    } else {
      /* Compétences démontrées via les savoir-faire (§32). */
      var demo = [];
      list.forEach(function (sf) { (sf.competences || []).forEach(function (c) { if (demo.indexOf(c) === -1) { demo.push(c); } }); });
      var demoBlock = demo.length
        ? '<p class="profile-sf__demo"><strong>' + demo.length + " compétence" + (demo.length > 1 ? "s" : "") + " démontrée" + (demo.length > 1 ? "s" : "") + " via vos savoir-faire</strong></p>" +
          '<div class="profile-row__tags">' + demo.map(function (c) { return '<span class="badge badge--neutral">' + e(c) + "</span>"; }).join("") + "</div>"
        : "";
      body = '<ul class="profile-sf">' + list.slice(0, 3).map(function (sf) {
        return '<li><span class="profile-sf__t">' + e(sf.titre) + "</span>" +
          '<span class="profile-sf__meta"><span class="badge badge--accent">' + String(sf.note).replace(".", ",") + "/5</span>" +
          '<span class="text-muted">' + (sf.avis || 0) + " avis · " + (sf.vues || 0) + " vues</span></span></li>";
      }).join("") + "</ul>" + demoBlock +
      '<p><a class="btn btn-outline btn-sm" href="#savoir-faire">Voir tous mes savoir-faire</a></p>';
    }
    return secCard("savoirfaire", "Savoir-faire Postelio", "", body);
  }

  /* ---- 21 : Langues ---- */
  function sectionLangues() {
    var p = getProfile();
    var e = SS.escapeHtml;
    var list = p.langues || [];
    var cards = list.length ? '<ul class="profile-inline-list">' + list.map(function (x, i) {
      return '<li><span>' + e(x.langue) + " — " + e(x.niveau) + "</span>" +
        '<button type="button" class="btn btn-ghost btn-xs is-danger" data-lang-del="' + i + '">Retirer</button></li>';
    }).join("") + "</ul>" : empty("Aucune langue indiquée.");
    var form = '<div class="profile-sec__edit" hidden><div class="form-row">' +
      f("Langue", '<input id="lang-nom" placeholder="Ex. : Anglais">') +
      f("Niveau", sel2("langniv", LANGUE_NIV, "Courant", null)) +
      "</div><div class=\"form-actions\"><button type=\"button\" class=\"btn btn-primary btn-sm\" data-lang-add>Ajouter</button>" +
      "<button type=\"button\" class=\"btn btn-ghost btn-sm\" data-cancel>Annuler</button></div></div>";
    return secCard("langues", "Langues", addBtn("+ Ajouter une langue"), cards + form);
  }
  function wireLangues(box) {
    var sec = box.querySelector('.profile-sec[data-section="langues"]');
    toggleEditor(sec, "[data-add-toggle]");
    var add = sec.querySelector("[data-lang-add]");
    if (add) {
      add.addEventListener("click", function () {
        var nom = valOf("lang-nom"), niv = valOf("langniv");
        if (!nom) { SS.toast("Indiquez une langue."); return; }
        var p = getProfile(); p.langues = (p.langues || []).concat([{ langue: nom, niveau: niv }]); saveProfile(p);
      });
    }
    sec.querySelectorAll("[data-lang-del]").forEach(function (b) {
      b.addEventListener("click", function () { var p = getProfile(); (p.langues || []).splice(parseInt(b.getAttribute("data-lang-del"), 10), 1); saveProfile(p, { toast: "Langue retirée." }); });
    });
  }

  /* ---- 22 : Certifications / habilitations (optionnel) ---- */
  function sectionCertifications() {
    var p = getProfile();
    var e = SS.escapeHtml;
    var list = p.certifications || [];
    var cards = list.length ? '<ul class="profile-inline-list">' + list.map(function (x, i) {
      return "<li><span>" + e(x) + "</span><button type=\"button\" class=\"btn btn-ghost btn-xs is-danger\" data-cert-del=\"" + i + "\">Retirer</button></li>";
    }).join("") + "</ul>" : empty("Aucune certification (facultatif).");
    var form = '<div class="profile-sec__edit" hidden>' +
      f("Certification / habilitation", '<input id="cert-nom" placeholder="Ex. : TOEIC, CACES, Permis B…">') +
      '<div class="form-actions"><button type="button" class="btn btn-primary btn-sm" data-cert-add>Ajouter</button>' +
      '<button type="button" class="btn btn-ghost btn-sm" data-cancel>Annuler</button></div></div>';
    return secCard("certifications", "Certifications / habilitations", addBtn("+ Ajouter"), cards + form);
  }
  function wireCertifications(box) {
    var sec = box.querySelector('.profile-sec[data-section="certifications"]');
    toggleEditor(sec, "[data-add-toggle]");
    var add = sec.querySelector("[data-cert-add]");
    if (add) {
      add.addEventListener("click", function () {
        var nom = valOf("cert-nom");
        if (!nom) { SS.toast("Indiquez une certification."); return; }
        var p = getProfile(); p.certifications = (p.certifications || []).concat([nom]); saveProfile(p);
      });
    }
    sec.querySelectorAll("[data-cert-del]").forEach(function (b) {
      b.addEventListener("click", function () { var p = getProfile(); (p.certifications || []).splice(parseInt(b.getAttribute("data-cert-del"), 10), 1); saveProfile(p, { toast: "Certification retirée." }); });
    });
  }

  /* ---- 14-15 : Recommandations (auteur + date, ajout simulé) ---- */
  /* Champs d'une recommandation (partagés ajout + modification). Lecture par
     data-rf dans un conteneur donné, pour pouvoir avoir plusieurs formulaires. */
  function recoFormHtml(r) {
    var e = SS.escapeHtml; r = r || {};
    return '<div class="form-row">' +
        '<div class="field"><label>Qui vous recommande ?</label><input data-rf="nom" value="' + e(r.nom || "") + '" placeholder="Prénom Nom"></div>' +
        '<div class="field"><label>Fonction</label><input data-rf="role" value="' + e(r.role || "") + '" placeholder="Ex. : Responsable technique"></div>' +
      "</div>" +
      '<div class="field"><label>Entreprise</label><input data-rf="entreprise" value="' + e(r.entreprise || "") + '" placeholder="Ex. : Studio Digital Lyon"></div>' +
      '<div class="field"><label>Recommandation (texte — optionnel si vous joignez une lettre PDF)</label><textarea data-rf="texte" rows="2">' + e(r.texte || "") + "</textarea></div>" +
      '<div class="field"><label>Lettre de recommandation (PDF, optionnel)</label>' +
        '<div class="reco-pdf-row">' +
          '<span class="reco-pdf-current"' + (r.pdf ? "" : " hidden") + ">" + ICON_DOC + ' <strong data-rf-pdf-name>' + e(r.pdf || "") + "</strong>" +
            ' <button type="button" class="btn btn-ghost btn-xs" data-rf-pdf-clear>Retirer</button></span>' +
          '<button type="button" class="btn btn-outline btn-sm" data-rf-pdf-btn>' + (r.pdf ? "Remplacer le PDF" : "Joindre un PDF") + "</button>" +
          '<input type="file" accept=".pdf" class="sr-only" data-rf-pdf-input tabindex="-1" aria-hidden="true">' +
          '<input type="hidden" data-rf="pdf" value="' + e(r.pdf || "") + '">' +
        "</div></div>" +
      '<label class="field--checkbox reco-vis-choice"><input type="checkbox" data-rf="visible"' + (r.visible !== false ? " checked" : "") + "><span>Visible par les recruteurs</span></label>";
  }
  function readRecoForm(scope) {
    function v(n) { var el = scope.querySelector('[data-rf="' + n + '"]'); return el ? (el.value || "").trim() : ""; }
    var vis = scope.querySelector('[data-rf="visible"]');
    return { nom: v("nom"), role: v("role"), entreprise: v("entreprise"), texte: v("texte"), pdf: v("pdf"), visible: vis ? vis.checked : true };
  }
  /* Câble l'import PDF simulé (nom de fichier uniquement) d'un formulaire reco. */
  function wireRecoPdf(scope) {
    var btn = scope.querySelector("[data-rf-pdf-btn]");
    var input = scope.querySelector("[data-rf-pdf-input]");
    var hidden = scope.querySelector('[data-rf="pdf"]');
    var current = scope.querySelector(".reco-pdf-current");
    var nameEl = scope.querySelector("[data-rf-pdf-name]");
    var clear = scope.querySelector("[data-rf-pdf-clear]");
    if (btn && input) { btn.addEventListener("click", function () { input.click(); }); }
    if (input) {
      input.addEventListener("change", function () {
        var file = input.files && input.files[0];
        if (!file) { return; }
        hidden.value = file.name;
        if (nameEl) { nameEl.textContent = file.name; }
        if (current) { current.hidden = false; }
        if (btn) { btn.textContent = "Remplacer le PDF"; }
        input.value = "";
      });
    }
    if (clear) {
      clear.addEventListener("click", function () {
        hidden.value = "";
        if (current) { current.hidden = true; }
        if (btn) { btn.textContent = "Joindre un PDF"; }
      });
    }
  }

  function sectionRecommandations() {
    var p = getProfile();
    var e = SS.escapeHtml;
    var list = p.recommandationsList || [];
    var cards = list.length ? list.map(function (r, i) {
      var visible = r.visible !== false;
      return '<figure class="reco-item" data-item="' + i + '">' +
        '<figcaption class="reco-item__author"><strong>' + e(r.nom || "") + "</strong>" +
          (r.role ? '<span>' + e(r.role) + "</span>" : "") +
          (r.entreprise ? '<span>' + e(r.entreprise) + "</span>" : "") +
          (r.date ? '<span class="reco-item__date">' + e(r.date) + "</span>" : "") + "</figcaption>" +
        (r.texte ? '<blockquote>« ' + e(r.texte) + " »</blockquote>" : "") +
        (r.pdf ? '<p class="reco-item__pdf">' + ICON_DOC + " <strong>" + e(r.pdf) + '</strong> <span class="text-muted">— lettre de recommandation</span></p>' : "") +
        '<div class="reco-item__foot">' +
          '<span class="badge ' + (visible ? "badge--remote" : "badge--neutral") + ' reco-item__vis">' + (visible ? "Visible par les recruteurs" : "Masquée") + "</span>" +
          '<div class="reco-item__actions">' +
            '<button type="button" class="btn btn-ghost btn-xs" data-reco-vis="' + i + '">' + (visible ? "Masquer" : "Rendre visible") + "</button>" +
            '<button type="button" class="btn btn-ghost btn-xs" data-reco-edit="' + i + '">Modifier</button>' +
            '<button type="button" class="btn btn-ghost btn-xs is-danger" data-reco-del="' + i + '">Retirer</button>' +
          "</div>" +
        "</div>" +
        '<div class="reco-edit-inline profile-sec__edit" hidden></div>' +
      "</figure>";
    }).join("") : empty("Aucune recommandation pour l'instant.");
    var form = '<div class="profile-sec__edit reco-add-form" hidden>' + recoFormHtml({ visible: true }) +
      '<div class="form-actions"><button type="button" class="btn btn-primary btn-sm" data-reco-add>Ajouter</button>' +
      '<button type="button" class="btn btn-ghost btn-sm" data-cancel>Annuler</button></div></div>';
    return secCard("recommandations", "Recommandations", addBtn("+ Ajouter une recommandation"), cards + form,
      "Prototype : l'ajout est simulé (le PDF n'est pas envoyé, seul son nom est conservé).");
  }
  function monthNow() {
    var mois = ["janvier", "février", "mars", "avril", "mai", "juin", "juillet", "août", "septembre", "octobre", "novembre", "décembre"];
    var now = new Date();
    return mois[now.getMonth()] + " " + now.getFullYear();
  }
  function wireRecommandations2(box) {
    var sec = box.querySelector('.profile-sec[data-section="recommandations"]');
    toggleEditor(sec, "[data-add-toggle]");
    var addForm = sec.querySelector(".reco-add-form");
    if (addForm) { wireRecoPdf(addForm); }
    var add = sec.querySelector("[data-reco-add]");
    if (add) {
      add.addEventListener("click", function () {
        var data = readRecoForm(addForm);
        if (!data.nom || (!data.texte && !data.pdf)) { SS.toast("Indiquez le nom et un texte ou une lettre PDF."); return; }
        data.date = monthNow();
        var p = getProfile();
        p.recommandationsList = (p.recommandationsList || []).concat([data]);
        saveProfile(p, { toast: "Recommandation ajoutée (démonstration)." });
      });
    }
    sec.querySelectorAll("[data-reco-del]").forEach(function (b) {
      b.addEventListener("click", function () { var p = getProfile(); (p.recommandationsList || []).splice(parseInt(b.getAttribute("data-reco-del"), 10), 1); saveProfile(p, { toast: "Recommandation retirée." }); });
    });
    sec.querySelectorAll("[data-reco-vis]").forEach(function (b) {
      b.addEventListener("click", function () {
        var i = parseInt(b.getAttribute("data-reco-vis"), 10);
        var p = getProfile(); var r = (p.recommandationsList || [])[i];
        if (!r) { return; }
        r.visible = r.visible === false ? true : false;
        saveProfile(p, { toast: r.visible ? "Recommandation visible par les recruteurs." : "Recommandation masquée." });
      });
    });
    sec.querySelectorAll("[data-reco-edit]").forEach(function (b) {
      b.addEventListener("click", function () {
        var i = parseInt(b.getAttribute("data-reco-edit"), 10);
        var fig = sec.querySelector('.reco-item[data-item="' + i + '"]');
        var editor = fig ? fig.querySelector(".reco-edit-inline") : null;
        if (!editor) { return; }
        if (!editor.hidden) { editor.hidden = true; editor.innerHTML = ""; return; }
        var r = (getProfile().recommandationsList || [])[i] || {};
        editor.innerHTML = recoFormHtml(r) +
          '<div class="form-actions"><button type="button" class="btn btn-primary btn-sm" data-reco-save>Enregistrer</button>' +
          '<button type="button" class="btn btn-ghost btn-sm" data-reco-editcancel>Annuler</button></div>';
        editor.hidden = false;
        wireRecoPdf(editor);
        editor.querySelector("input").focus();
        editor.querySelector("[data-reco-editcancel]").addEventListener("click", function () { editor.hidden = true; editor.innerHTML = ""; });
        editor.querySelector("[data-reco-save]").addEventListener("click", function () {
          var data = readRecoForm(editor);
          if (!data.nom || (!data.texte && !data.pdf)) { SS.toast("Indiquez le nom et un texte ou une lettre PDF."); return; }
          var p = getProfile();
          var cur = (p.recommandationsList || [])[i] || {};
          data.date = cur.date || monthNow();
          p.recommandationsList[i] = data;
          saveProfile(p, { toast: "Recommandation modifiée." });
        });
      });
    });
  }

  /* ---- 23 : Liens professionnels ---- */
  function sectionLiens() {
    var p = getProfile();
    var e = SS.escapeHtml;
    var l = p.liens || {};
    var items = [["LinkedIn", l.linkedin], ["Portfolio", l.portfolio], ["GitHub", l.github], ["Site personnel", l.site]]
      .filter(function (x) { return x[1]; });
    var view = items.length ? '<ul class="profile-links">' + items.map(function (x) {
      return '<li><a href="' + e(x[1]) + '" target="_blank" rel="noopener">' + e(x[0]) + " ↗</a></li>";
    }).join("") + "</ul>" : empty("Aucun lien professionnel.");
    var edit = '<div class="profile-sec__edit" hidden>' +
      f("LinkedIn", '<input id="lnk-linkedin" value="' + e(l.linkedin || "") + '" placeholder="linkedin.com/in/…">') +
      f("Portfolio", '<input id="lnk-portfolio" value="' + e(l.portfolio || "") + '" placeholder="https://…">') +
      f("GitHub", '<input id="lnk-github" value="' + e(l.github || "") + '" placeholder="github.com/…">') +
      f("Site personnel", '<input id="lnk-site" value="' + e(l.site || "") + '" placeholder="https://…">') +
      formActions() + "</div>";
    return secCard("liens", "Liens professionnels", editBtn(), view + edit);
  }
  function wireLiens(box) {
    var sec = box.querySelector('.profile-sec[data-section="liens"]');
    toggleEditor(sec);
    var save = sec.querySelector("[data-save]");
    if (save) {
      save.addEventListener("click", function () {
        var p = getProfile();
        p.liens = { linkedin: valOf("lnk-linkedin"), portfolio: valOf("lnk-portfolio"), github: valOf("lnk-github"), site: valOf("lnk-site") };
        saveProfile(p);
      });
    }
  }

  /* ---- 16 : Visibilité des informations (lecture, concis) ---- */
  var VIS_EMAIL = [["prive", "Privé"], ["recruteurs", "Visible par les recruteurs"]];
  var VIS_TEL = [["apres-candidature", "Visible après candidature"], ["recruteurs", "Visible par les recruteurs"], ["masque", "Masqué"]];
  function sectionVisibilite() {
    var p = getProfile();
    var vis = p.visibility || {};
    function opt(list, cur) { return list.map(function (o) { return '<option value="' + o[0] + '"' + (o[0] === (cur || list[0][0]) ? " selected" : "") + ">" + SS.escapeHtml(o[1]) + "</option>"; }).join(""); }
    var body = '<ul class="profile-visibility">' +
      '<li><span>CV</span><span class="badge badge--remote">Visible par les recruteurs</span></li>' +
      '<li><span>Disponibilité &amp; statut</span><span class="badge badge--remote">Visible</span></li>' +
      '<li><span>Savoir-faire &amp; réalisations</span><span class="badge badge--remote">Public</span></li>' +
      '<li class="profile-visibility__ctrl"><label for="vis-email">E-mail</label>' +
        '<select id="vis-email" data-vis="email">' + opt(VIS_EMAIL, vis.email) + "</select></li>" +
      '<li class="profile-visibility__ctrl"><label for="vis-tel">Téléphone</label>' +
        '<select id="vis-tel" data-vis="tel">' + opt(VIS_TEL, vis.tel) + "</select></li>" +
      "</ul>" +
      '<p class="profile-sec__note text-muted">Vous choisissez si votre e-mail et votre téléphone sont visibles. ' +
      '<strong>Important :</strong> lorsqu\'un recruteur consulte votre CV, il y voit toutes vos coordonnées, quel que soit ce réglage.</p>';
    return secCard("visibilite", "Paramètres de visibilité", "", body);
  }
  function wireVisibilite(box) {
    var sec = box.querySelector('.profile-sec[data-section="visibilite"]');
    if (!sec) { return; }
    sec.querySelectorAll("[data-vis]").forEach(function (selEl) {
      selEl.addEventListener("change", function () {
        var p = getProfile(); p.visibility = p.visibility || {};
        p.visibility[selEl.getAttribute("data-vis")] = selEl.value;
        setProfile(p);
        SS.toast("Préférence de visibilité enregistrée.");
      });
    });
  }

  /* ---- 3 : Profil public (ce que voit un recruteur) ---- */
  function openPublicProfile() {
    var e = SS.escapeHtml;
    var p = getProfile();
    var sf = (SS.store.get(SF_KEY, []) || []).slice().sort(function (a, b) { return (b.note || 0) - (a.note || 0); }).slice(0, 3);
    function block(title, inner) { return inner ? '<section class="pub-profile__sec"><h3>' + e(title) + "</h3>" + inner + "</section>" : ""; }
    var badges = [];
    if (p.contrat) { badges.push(p.contrat + " recherché"); }
    if (p.ville) { badges.push(p.ville + (p.rayon ? " +" + p.rayon + " km" : "")); }
    if (TELE_BADGE[p.teletravail]) { badges.push(TELE_BADGE[p.teletravail]); }
    /* Statut affiché seulement si le candidat l'autorise (§3, §28). */
    var statutLabel = (p.statutVisible !== false) ? (STATUT_OPTS.filter(function (o) { return o.v === (p.statut || "active"); })[0] || {}).l : null;
    var dispoLabel = p.disponibilite === "À partir d'une date" && p.dispoDate ? "Disponible à partir du " + SS.formatDate(p.dispoDate) : (p.disponibilite ? "Disponible " + p.disponibilite.toLowerCase() : "");
    var cvName = (getCv() || {}).name;
    var sess = SS.auth.get() || {};
    var vis = p.visibility || {};
    var coord = '<ul class="pub-summary">' +
      "<li><strong>E-mail</strong> " + (vis.email === "recruteurs" ? e(sess.email || "—") : '<span class="text-muted">Communiqué après mise en relation</span>') + "</li>" +
      "<li><strong>Téléphone</strong> " + (vis.tel === "recruteurs" ? e(p.telephone || "—") : (vis.tel === "masque" ? '<span class="text-muted">Non communiqué</span>' : '<span class="text-muted">Communiqué après candidature</span>')) + "</li>" +
      "</ul><p class=\"form-hint\">Ces coordonnées figurent aussi sur le CV, que le recruteur peut consulter.</p>";
    var resume = (p.presentation ? "<p>" + e(p.presentation.split(".")[0] + ".") + "</p>" : "") +
      '<ul class="pub-summary">' +
        "<li><strong>Poste recherché</strong> " + e(p.metier || "—") + "</li>" +
        "<li><strong>Localisation</strong> " + e((p.ville || "—") + (p.rayon ? " +" + p.rayon + " km" : "")) + "</li>" +
        "<li><strong>Contrat</strong> " + e((p.contrat || "—") + (p.tempsTravail ? " · " + p.tempsTravail : "")) + "</li>" +
        "<li><strong>Disponibilité</strong> " + e(p.disponibilite || "—") + "</li>" +
      "</ul>";
    var cvBlock = cvName ? '<p class="pub-cv">' + ICON_DOC + " " + e(cvName) + ' <span class="text-muted">— disponible pour les recruteurs</span></p>' : "";

    var recherche = '<dl class="profile-def">' +
      "<div><dt>Métier</dt><dd>" + e(p.metier || "—") + "</dd></div>" +
      "<div><dt>Localisation</dt><dd>" + e((p.ville || "—") + (p.rayon ? " · " + p.rayon + " km" : "")) + "</dd></div>" +
      "<div><dt>Contrat</dt><dd>" + e(p.contrat || "—") + "</dd></div>" +
      "<div><dt>Télétravail</dt><dd>" + e(TELE_LABEL[p.teletravail] || "—") + "</dd></div>" +
      "<div><dt>Disponibilité</dt><dd>" + e(p.disponibilite || "—") + "</dd></div>" + "</dl>";

    var exp = (p.experiences || []).map(function (x) {
      return '<div class="pub-card"><strong>' + e(x.poste || "") + "</strong> — " + e(x.entreprise || "") +
        '<span class="pub-card__dates">' + e((x.debut || "") + (x.fin ? " — " + x.fin : "")) + "</span>" +
        (x.description ? "<p>" + e(x.description) + "</p>" : "") + "</div>";
    }).join("");
    var form = (p.formations || []).map(function (x) { return '<div class="pub-card"><strong>' + e(x.diplome || "") + "</strong> — " + e(x.ecole || "") + (x.niveau ? " (" + e(x.niveau) + ")" : "") + "</div>"; }).join("");
    var comp = (p.competencesPrincipales || []).map(function (c) { return '<span class="skill-badge">' + e(c.nom) + (c.niveau ? ' <span class="skill-badge__lvl">' + e(c.niveau) + "</span>" : "") + "</span>"; }).join("") +
      (p.competencesComplementaires || []).map(function (c) { return '<span class="badge badge--neutral">' + e(c) + "</span>"; }).join("");
    var real = (p.realisationsList || []).map(function (x) { return '<div class="pub-card"><strong>' + e(x.titre || "") + "</strong>" + (x.description ? "<p>" + e(x.description) + "</p>" : "") + (x.lien ? '<a href="' + e(x.lien) + '" target="_blank" rel="noopener">Voir le projet ↗</a>' : "") + "</div>"; }).join("");
    var sfHtml = sf.length ? '<ul class="profile-sf">' + sf.map(function (s) { return "<li><span class=\"profile-sf__t\">" + e(s.titre) + "</span><span class=\"badge badge--accent\">" + String(s.note).replace(".", ",") + "/5</span></li>"; }).join("") + "</ul>" : "";
    var langs = (p.langues || []).map(function (x) { return "<li>" + e(x.langue + " — " + x.niveau) + "</li>"; }).join("");
    var certs = (p.certifications || []).map(function (x) { return "<li>" + e(x) + "</li>"; }).join("");
    /* Seules les recommandations rendues visibles sont montrées au recruteur. */
    var recos = (p.recommandationsList || []).filter(function (r) { return r.visible !== false; }).map(function (r) {
      return '<figure class="reco-item"><figcaption class="reco-item__author"><strong>' + e(r.nom || "") + "</strong>" +
        (r.role ? "<span>" + e(r.role) + "</span>" : "") + (r.entreprise ? "<span>" + e(r.entreprise) + "</span>" : "") + (r.date ? '<span class="reco-item__date">' + e(r.date) + "</span>" : "") +
        "</figcaption>" + (r.texte ? "<blockquote>« " + e(r.texte) + " »</blockquote>" : "") +
        (r.pdf ? '<p class="reco-item__pdf">' + ICON_DOC + " <strong>" + e(r.pdf) + '</strong> <span class="text-muted">— lettre de recommandation</span></p>' : "") +
        "</figure>";
    }).join("");
    var liens = [["LinkedIn", (p.liens || {}).linkedin], ["Portfolio", (p.liens || {}).portfolio], ["GitHub", (p.liens || {}).github], ["Site", (p.liens || {}).site]].filter(function (x) { return x[1]; });
    var liensHtml = liens.length ? '<ul class="profile-links">' + liens.map(function (x) { return '<li><a href="' + e(x[1]) + '" target="_blank" rel="noopener">' + e(x[0]) + " ↗</a></li>"; }).join("") + "</ul>" : "";

    var overlay = document.createElement("div");
    overlay.className = "modal-overlay";
    overlay.setAttribute("role", "dialog");
    overlay.setAttribute("aria-modal", "true");
    overlay.innerHTML = '<div class="modal modal--wide pub-profile" role="document">' +
      '<div class="modal__head"><h2 class="modal__title">Mon profil vu par un recruteur</h2>' +
        '<button type="button" class="modal-close" data-close aria-label="Fermer">✕</button></div>' +
      '<div class="modal__body">' +
        '<p class="form-hint">Voici exactement ce qu\'un recruteur voit — vos coordonnées personnelles (e-mail, téléphone) et vos notes restent privées.</p>' +
        '<div class="pub-profile__head">' +
          '<span class="avatar" aria-hidden="true">' + e(SS.auth.initials()) + "</span>" +
          "<div><strong class=\"pub-profile__name\">" + e(SS.auth.displayName() || "Candidat") + "</strong>" +
          '<span class="text-muted">' + e((p.metier || "") + (p.ville ? " · " + p.ville : "")) + "</span>" +
          '<div class="pub-profile__flags">' +
            (dispoLabel ? '<span class="badge badge--remote">' + e(dispoLabel) + "</span>" : "") +
            (statutLabel ? '<span class="badge badge--accent">' + e(statutLabel) + "</span>" : "") +
          "</div></div>" +
        "</div>" +
        (badges.length ? '<div class="profile-identity__badges">' + badges.map(function (b) { return '<span class="badge badge--accent">' + e(b) + "</span>"; }).join("") + "</div>" : "") +
        block("Résumé", resume) +
        block("CV", cvBlock) +
        block("Coordonnées", coord) +
        block("Ce que je recherche", recherche) +
        block("À propos", p.presentation ? "<p>" + e(p.presentation).replace(/\n/g, "<br>") + "</p>" : "") +
        block("Expériences", exp) +
        block("Formation", form) +
        block("Compétences", comp ? '<div class="profile-row__tags">' + comp + "</div>" : "") +
        block("Réalisations", real) +
        block("Savoir-faire Postelio", sfHtml) +
        block("Langues", langs ? "<ul class=\"profile-inline-list profile-inline-list--plain\">" + langs + "</ul>" : "") +
        block("Certifications", certs ? "<ul class=\"profile-inline-list profile-inline-list--plain\">" + certs + "</ul>" : "") +
        block("Recommandations", recos) +
        block("Liens professionnels", liensHtml) +
      "</div>" +
      '<div class="modal__actions"><button type="button" class="btn btn-primary" data-close>Fermer l\'aperçu</button></div>' +
      "</div>";
    document.body.appendChild(overlay);
    document.body.classList.add("modal-open");
    function close() { overlay.remove(); document.body.classList.remove("modal-open"); }
    overlay.querySelectorAll("[data-close]").forEach(function (b) { b.addEventListener("click", close); });
    overlay.addEventListener("click", function (ev) { if (ev.target === overlay) { close(); } });
    document.addEventListener("keydown", function onEsc(ev) { if (ev.key === "Escape") { close(); document.removeEventListener("keydown", onEsc); } });
  }

  /* ---- Cadre générique d'édition d'une liste de cartes (exp/formation/réal) ---- */
  function wireList(box, id, formHtmlFn, readFn) {
    var sec = box.querySelector('.profile-sec[data-section="' + id + '"]');
    if (!sec) { return; }
    var addForm = sec.querySelector(".profile-item-form");
    var addToggle = sec.querySelector("[data-add-toggle]");
    if (addToggle && addForm) {
      addToggle.addEventListener("click", function () {
        var open = addForm.hidden; addForm.hidden = !open; addToggle.setAttribute("aria-expanded", String(open));
        if (open) { var fi = addForm.querySelector("input, textarea"); if (fi) { fi.focus(); } }
      });
      var cancel = addForm.querySelector("[data-cancel]");
      if (cancel) { cancel.addEventListener("click", function () { addForm.hidden = true; addToggle.setAttribute("aria-expanded", "false"); }); }
      var save = addForm.querySelector("[data-save]");
      if (save) {
        save.addEventListener("click", function () {
          var data = readFn(addForm);
          if (!firstFilled(data)) { SS.toast("Complétez au moins un champ."); return; }
          var p = getProfile(); p[id] = (p[id] || []).concat([data]); saveProfile(p, { toast: "Ajouté." });
        });
      }
    }
    /* Édition / suppression par carte */
    sec.querySelectorAll("[data-item-del]").forEach(function (b) {
      b.addEventListener("click", function () { var p = getProfile(); (p[id] || []).splice(parseInt(b.getAttribute("data-item-del"), 10), 1); saveProfile(p, { toast: "Retiré." }); });
    });
    sec.querySelectorAll("[data-item-edit]").forEach(function (b) {
      b.addEventListener("click", function () {
        var i = parseInt(b.getAttribute("data-item-edit"), 10);
        var card = sec.querySelector('.profile-card[data-item="' + i + '"]');
        if (!card || card.querySelector(".profile-item-form")) { return; }
        var p = getProfile();
        var inline = document.createElement("div");
        inline.className = "profile-sec__edit profile-item-form";
        inline.innerHTML = formHtmlFn((p[id] || [])[i]) + formActions();
        card.appendChild(inline);
        inline.querySelector("input, textarea").focus();
        inline.querySelector("[data-cancel]").addEventListener("click", function () { inline.remove(); });
        inline.querySelector("[data-save]").addEventListener("click", function () {
          var data = readFn(inline);
          var prof = getProfile(); (prof[id] || [])[i] = data; saveProfile(prof, { toast: "Modifié." });
        });
      });
    });
  }

  /* ---- Petits utilitaires d'édition ---- */
  function toggleEditor(sec, toggleSel) {
    if (!sec) { return; }
    var editor = sec.querySelector(".profile-sec__edit");
    var toggle = sec.querySelector(toggleSel || "[data-edit]");
    if (!editor || !toggle) { return; }
    toggle.addEventListener("click", function () {
      var open = editor.hidden; editor.hidden = !open;
      if (toggle.hasAttribute("aria-expanded")) { toggle.setAttribute("aria-expanded", String(open)); }
      if (open) { var fi = editor.querySelector("input, textarea, select"); if (fi) { fi.focus(); } }
    });
    var cancel = editor.querySelector("[data-cancel]");
    if (cancel) { cancel.addEventListener("click", function () { editor.hidden = true; if (toggle.hasAttribute("aria-expanded")) { toggle.setAttribute("aria-expanded", "false"); } }); }
  }
  function f(label, control) { return '<div class="field"><label>' + SS.escapeHtml(label) + "</label>" + control + "</div>"; }
  function sel(id, opts, cur, fmt) { return '<select id="' + id + '">' + opts.map(function (o) { return '<option value="' + SS.escapeHtml(o) + '"' + (o === cur ? " selected" : "") + ">" + SS.escapeHtml(fmt ? fmt(o) : o) + "</option>"; }).join("") + "</select>"; }
  function sel2(id, opts, cur, fmt) { return sel(id, opts, cur, fmt); }
  function sel3(dataAttr, opts, cur, fmt) { return '<select class="skill-row__lvl">' + opts.map(function (o) { return '<option value="' + SS.escapeHtml(o) + '"' + (o === cur ? " selected" : "") + ">" + SS.escapeHtml(fmt ? fmt(o) : o) + "</option>"; }).join("") + "</select>"; }
  function cb(id, label, on) { return '<label class="field--checkbox"><input type="checkbox" id="' + id + '"' + (on ? " checked" : "") + "><span>" + SS.escapeHtml(label) + "</span></label>"; }
  function valOf(id) { var el = document.getElementById(id); return el ? (el.value || "").trim() : ""; }
  function chk(id) { var el = document.getElementById(id); return !!(el && el.checked); }
  function fld(scope, name) { var el = scope.querySelector('[data-fld="' + name + '"]'); return el ? (el.value || "").trim() : ""; }
  function lines(v) { return (v || "").split("\n").map(function (x) { return x.trim(); }).filter(Boolean); }
  function commas(v) { return (v || "").split(",").map(function (x) { return x.trim(); }).filter(Boolean); }
  function firstFilled(obj) { return Object.keys(obj).some(function (k) { var v = obj[k]; return Array.isArray(v) ? v.length : (v && String(v).trim()); }); }

  /* Petit référentiel de compétences suggérées par famille de métier. */
  function skillSuggestions(metier) {
    var m = normalize(metier);
    if (/develop|web|informat|digital/.test(m)) { return ["HTML / CSS", "JavaScript", "Git", "React", "PHP", "SQL", "Accessibilité"]; }
    if (/comptab|paie|financ|gestion/.test(m)) { return ["Comptabilité générale", "Fiscalité", "Sage / Cegid", "Excel", "Paie", "Rigueur"]; }
    if (/commerc|vente|conseil/.test(m)) { return ["Négociation", "Prospection", "CRM", "Relation client", "Sens du service"]; }
    if (/assist|secret|administ|office/.test(m)) { return ["Pack Office", "Accueil", "Organisation", "Orthographe", "Gestion d'agenda"]; }
    return ["Travail en équipe", "Autonomie", "Organisation", "Communication", "Rigueur"];
  }

  /* Icône document (SVG, remplace l'emoji). */
  var ICON_DOC = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h6"/></svg>';

  /* Aperçu simulé du CV du candidat (document factice stylé « démonstration »). */
  function cvDocHtml(cvObj) {
    var e = SS.escapeHtml;
    var p = getProfile();
    var s = SS.auth.get() || {};
    var fileName = (cvObj && cvObj.name) || (getCv() || {}).name || "";
    var name = SS.auth.displayName() || "Candidat";
    var contact = [p.ville || s.city, s.email].filter(Boolean).join(" · ");
    var exp0 = (p.experiences || [])[0];
    var form0 = (p.formations || [])[0];
    var skills = (p.competencesPrincipales || []).slice(0, 5).map(function (c) { return '<span class="cv-doc__chip">' + e(c.nom) + "</span>"; }).join("");
    return '<div class="cv-doc cv-doc--full" role="img" aria-label="Aperçu simulé de votre CV">' +
      '<span class="cv-doc__demo">Aperçu de démonstration</span>' +
      '<div class="cv-doc__head"><h4>' + e(name) + "</h4><p>" + e(p.metier || "") + "</p>" +
        (contact ? '<p class="cv-doc__contact">' + e(contact) + "</p>" : "") + "</div>" +
      '<div class="cv-doc__sec"><span class="cv-doc__label">Expérience</span>' +
        (exp0 ? '<p class="cv-doc__text">' + e((exp0.poste || "") + " — " + (exp0.entreprise || "")) + "</p>" : "") + "</div>" +
      '<div class="cv-doc__sec"><span class="cv-doc__label">Formation</span>' +
        (form0 ? '<p class="cv-doc__text">' + e((form0.diplome || "") + " — " + (form0.ecole || "")) + "</p>" : "") + "</div>" +
      '<div class="cv-doc__sec"><span class="cv-doc__label">Compétences</span>' +
        (skills ? '<div class="cv-doc__chips">' + skills + "</div>" : "") + "</div>" +
      '<p class="cv-doc__file">' + e(fileName) + "</p>" +
    "</div>";
  }

  function openCvViewer(cvObj) {
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
            '<div class="cv-viewer__pager" role="group" aria-label="Pages">' +
              '<button type="button" class="btn btn-ghost btn-sm" data-page="prev" disabled aria-label="Page précédente">‹</button>' +
              '<span class="cv-viewer__pages text-muted">Page 1 / 1</span>' +
              '<button type="button" class="btn btn-ghost btn-sm" data-page="next" disabled aria-label="Page suivante">›</button>' +
            "</div>" +
            '<button type="button" class="btn btn-ghost btn-sm" data-cv-dl>Télécharger</button>' +
          "</div>" +
          '<div class="cv-viewer__stage"><div class="cv-viewer__page">' + cvDocHtml(cvObj) + "</div></div>" +
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
    var dl = overlay.querySelector("[data-cv-dl]");
    if (dl) { dl.addEventListener("click", function () { SS.toast("Téléchargement du CV (démonstration)."); }); }
    function close() { overlay.remove(); document.body.classList.remove("modal-open"); }
    overlay.querySelectorAll("[data-close]").forEach(function (b) { b.addEventListener("click", close); });
    overlay.addEventListener("click", function (ev) { if (ev.target === overlay) { close(); } });
    document.addEventListener("keydown", function onEsc(ev) { if (ev.key === "Escape") { close(); document.removeEventListener("keydown", onEsc); } });
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

  /* Préparation d'entretien (§16) + débrief après entretien (§17). */
  var IV_PREP_KEY = "ss_cand_iv_prep";
  var IV_DEBRIEF_KEY = "ss_cand_iv_debrief";
  var IV_PREP_ITEMS = [["offre", "Relire l'offre"], ["entreprise", "Consulter l'entreprise"], ["candidature", "Relire ma candidature"], ["questions", "Préparer mes questions"]];
  var IV_RATINGS = ["Très bien", "Bien", "Moyen", "Difficile"];
  function ivPrepHtml(it) {
    var prep = (SS.store.get(IV_PREP_KEY, {}) || {})[it.id] || {};
    return '<div class="interview-prep" data-iv="' + it.id + '"><h4>Préparer mon entretien</h4>' +
      '<ul class="interview-prep__list">' + IV_PREP_ITEMS.map(function (p) {
        return '<li><label class="field--checkbox"><input type="checkbox" data-prep="' + p[0] + '"' + (prep[p[0]] ? " checked" : "") + "><span>" + SS.escapeHtml(p[1]) + "</span></label></li>";
      }).join("") + "</ul>" +
      '<a class="btn btn-ghost btn-sm" href="blog.html">Voir les conseils</a></div>';
  }
  function ivDebriefHtml(it) {
    var d = (SS.store.get(IV_DEBRIEF_KEY, {}) || {})[it.id] || {};
    return '<div class="interview-debrief" data-iv="' + it.id + '"><h4>Comment s\'est passé votre entretien&nbsp;?</h4>' +
      '<div class="iv-rating">' + IV_RATINGS.map(function (r) {
        return '<button type="button" class="chip" aria-pressed="' + (d.rating === r) + '" data-iv-rating="' + SS.escapeHtml(r) + '">' + SS.escapeHtml(r) + "</button>";
      }).join("") + "</div>" +
      '<div class="interview-debrief__note">' +
        (d.note ? '<p class="appli-note__label"><strong>Note personnelle</strong> · visible uniquement par vous</p><p>' + SS.escapeHtml(d.note) + "</p>" : "") +
        '<button type="button" class="btn btn-ghost btn-sm" data-iv-note-toggle>' + (d.note ? "Modifier ma note" : "Ajouter une note personnelle") + "</button>" +
        '<div class="interview-debrief__editor" hidden><textarea rows="2" placeholder="Vos impressions (privé)">' + SS.escapeHtml(d.note || "") + "</textarea>" +
        '<button type="button" class="btn btn-primary btn-sm" data-iv-note-save>Enregistrer</button></div>' +
      "</div></div>";
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

      var tab = ivTabOf(it, store);
      var prepBlock = (tab === "confirmer" || tab === "avenir") ? ivPrepHtml(it) : "";
      var debriefBlock = (tab === "passes") ? ivDebriefHtml(it) : "";

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
        prepBlock +
        debriefBlock +
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

    /* Préparation d'entretien : cases à cocher persistées (§16). */
    box.querySelectorAll(".interview-prep").forEach(function (block) {
      var id = block.getAttribute("data-iv");
      block.querySelectorAll("[data-prep]").forEach(function (cb) {
        cb.addEventListener("change", function () {
          var store = SS.store.get(IV_PREP_KEY, {}) || {};
          store[id] = store[id] || {};
          store[id][cb.getAttribute("data-prep")] = cb.checked;
          SS.store.set(IV_PREP_KEY, store);
        });
      });
    });

    /* Débrief après entretien : ressenti + note privée (§17). */
    box.querySelectorAll(".interview-debrief").forEach(function (block) {
      var id = block.getAttribute("data-iv");
      block.querySelectorAll("[data-iv-rating]").forEach(function (b) {
        b.addEventListener("click", function () {
          var store = SS.store.get(IV_DEBRIEF_KEY, {}) || {};
          store[id] = store[id] || {};
          store[id].rating = b.getAttribute("data-iv-rating");
          SS.store.set(IV_DEBRIEF_KEY, store);
          block.querySelectorAll("[data-iv-rating]").forEach(function (x) { x.setAttribute("aria-pressed", "false"); });
          b.setAttribute("aria-pressed", "true");
          SS.toast("Ressenti enregistré (privé).");
        });
      });
      var toggle = block.querySelector("[data-iv-note-toggle]");
      var editor = block.querySelector(".interview-debrief__editor");
      if (toggle && editor) {
        toggle.addEventListener("click", function () {
          editor.hidden = !editor.hidden;
          if (!editor.hidden) { var ta = editor.querySelector("textarea"); if (ta) { ta.focus(); } }
        });
      }
      var save = block.querySelector("[data-iv-note-save]");
      if (save) {
        save.addEventListener("click", function () {
          var store = SS.store.get(IV_DEBRIEF_KEY, {}) || {};
          store[id] = store[id] || {};
          store[id].note = (block.querySelector("textarea").value || "").trim();
          SS.store.set(IV_DEBRIEF_KEY, store);
          renderInterviews();
          SS.toast("Note enregistrée (privée).");
        });
      }
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

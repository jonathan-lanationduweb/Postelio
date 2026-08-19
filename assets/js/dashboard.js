/**
 * Espace entreprise (démonstration) — centre de pilotage du recrutement.
 *
 * Garde de session (SS.auth) : réservé au rôle "employer".
 * Réutilise la logique de fond existante : les offres de l'entreprise
 * sont récupérées via SS.getOffers() filtré sur l'identifiant de la
 * société connectée (companyId), les statuts modifiés (désactivation /
 * renouvellement / archivage) transitent par le stockage local
 * (ss_offer_overrides), et le renouvellement renvoie vers
 * paiement.html?offre=<id>.
 *
 * Note prototype : le compte de démonstration « Fiduciaire Bellecour »
 * ne possède aucune offre dans les données JSON. Lorsque le filtre par
 * société ne renvoie rien, on adopte un sous-ensemble d'offres de
 * démonstration (déterministe) afin que l'espace reste illustratif.
 *
 * Clés de stockage local utilisées ici :
 *   ss_refus_demo       — message courtois final d'un refus (lu côté candidat).
 *   ss_pipeline_v1      — étape du pipeline de chaque candidat (avancement).
 *   ss_company_profile  — présentation d'entreprise éditée dans le profil.
 *   ss_offer_overrides  — statut/expiration/archivage des offres (partagé).
 */
(function () {
  "use strict";

  /* Clé de stockage du refus (message courtois final), lue côté candidat. */
  var REFUS_KEY = "ss_refus_demo";

  /* Pipeline : étape courante de chaque candidat + version du seed.
     Le versionnage permet de régénérer proprement si la structure change. */
  var PIPELINE_KEY = "ss_pipeline_v1";
  var PIPELINE_SEED_VERSION = 1;

  /* Présentation d'entreprise éditée (mode Modifier du profil). */
  var PROFILE_KEY = "ss_company_profile";

  /* Messages courtois prédéfinis, indexés par motif interne. Le motif brut
     (ex. « Expérience insuffisante ») n'est JAMAIS transmis : seul l'un de
     ces messages (ou sa version éditée) est envoyé au candidat. */
  var COURTOIS = {
    profil: "Bonjour, nous vous remercions pour votre candidature et l'intérêt porté à notre entreprise. Après étude attentive de votre profil, nous avons décidé de ne pas y donner suite pour ce poste. Nous vous souhaitons une pleine réussite dans vos recherches.",
    experience: "Bonjour, merci beaucoup pour votre candidature. Nous avons retenu d'autres profils dont le parcours correspondait davantage aux attentes de ce poste. Nous conservons votre candidature et vous souhaitons une belle continuation.",
    pourvu: "Bonjour, nous vous remercions pour votre candidature. Le poste vient malheureusement d'être pourvu. Nous ne manquerons pas de revenir vers vous si une opportunité similaire se présente. Bonne continuation à vous.",
    dispo: "Bonjour, merci pour votre candidature et pour le temps consacré à notre échange. Les disponibilités ne correspondent pas au besoin actuel de l'équipe. Nous vous souhaitons pleine réussite dans la suite de vos démarches.",
    autre: "Bonjour, nous vous remercions sincèrement pour votre candidature. Nous ne sommes pas en mesure d'y donner une suite favorable pour le moment. Nous vous souhaitons une excellente continuation."
  };

  /* Ouvre la modale de refus ; renseignée par initRefusModal(). */
  var openRefusModal = null;

  /* Observateurs de mutation des boutons « Refuser » du pipeline : lorsqu'un
     bouton est désactivé (refus confirmé dans la modale), on régénère le
     pipeline pour déplacer la carte vers la colonne « Refusé ». */
  var pipelineObservers = [];

  document.addEventListener("DOMContentLoaded", function () {
    if (!window.SS || !SS.auth) { return; }
    /* Garde : visiteur → connexion ; candidat → son espace. */
    if (!SS.auth.require("employer")) { return; }

    var layout = document.querySelector(".dash-layout");
    if (!layout) { return; }

    fillIdentity();
    renderDashboard();
    initRefusModal();   /* modale de refus courtois — inchangée, réutilisée */
    initTodo();
    renderPipeline();
    seedInterviews();
    initMessages();
    seedContents();
    initProfilEdit();
    seedBilling();
    initNav();
    initLogout();
    initToasts();
  });

  /* ---- Identité (avatar, nom, entreprise) depuis la session ---- */
  function fillIdentity() {
    var s = SS.auth.get() || {};
    var company = s.company || APP_CONFIG.demoCompany.nom;
    var initials = SS.auth.initials();

    setText("dash-avatar", initials);
    setText("dash-name", SS.auth.displayName() || company);
    setText("dash-company", company);
    setText("hello-name", s.firstName || SS.auth.displayName() || "");

    /* Le logo du profil représente l'ENTREPRISE : initiales de la société
       (ex. « FB »), et non celles de la personne connectée (« CM »). */
    setText("profil-logo", companyInitials(company));
    setText("profil-name", company);
    if (s.secteur) { setText("profil-sector", s.secteur); }
    if (s.city) { setText("profil-city", s.city); }
  }

  /* ---- Récupération des offres de l'entreprise connectée ---- */
  function getCompanyOffers() {
    var s = SS.auth.get() || {};
    var companyId = s.companyId || APP_CONFIG.demoCompany.id;
    return SS.getOffers().then(function (offers) {
      var mine = offers.filter(function (o) { return o.entrepriseId === companyId; });
      /* Données de démonstration : le compte n'a pas d'offres propres. */
      var list = mine.length ? mine : buildDemoOffers(offers);
      /* Les offres archivées (action locale) disparaissent de la liste. */
      return list.filter(function (o) { return !o.archived; });
    });
  }

  /* Sous-ensemble d'offres de démonstration, avec un mélange de statuts
     (active / proche expiration / expirée) calculé côté client. Les
     modifications de l'utilisateur (ss_offer_overrides) restent prioritaires. */
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

  /* ---- Rendu principal : cartes d'offres + alerte 10 € ----
     Les indicateurs du tableau de bord sont des valeurs fixes de
     démonstration inscrites directement dans le HTML (jamais de « — »). */
  function renderDashboard() {
    getCompanyOffers().then(function (offers) {
      var toRenew = offers.filter(needsRenewal);
      renderOffers(offers);
      renderRenewalAlert(toRenew);
    }).catch(function () {
      SS.dataError(document.getElementById("dashboard-offers"));
    });
  }

  /* Une offre est « à renouveler » si elle est expirée ou proche de l'être. */
  function needsRenewal(o) {
    if (o.statut === "expiree") { return true; }
    if (o.statut !== "active" || !o.dateExpiration) { return false; }
    return daysUntil(o.dateExpiration) <= 7;
  }

  function statusBadge(o) {
    if (o.statut === "active") {
      if (o.dateExpiration && daysUntil(o.dateExpiration) <= 7) {
        return '<span class="badge badge--accent">Expire bientôt</span>';
      }
      return '<span class="badge badge--remote">Publiée</span>';
    }
    if (o.statut === "desactivee") {
      return '<span class="badge badge--neutral">Désactivée</span>';
    }
    return '<span class="badge badge--expired">Expirée</span>';
  }

  /* Libellé « Expire dans X jours » (ou état expiré / désactivé). */
  function expiryLabel(o) {
    if (o.statut === "desactivee") { return "Hors ligne (désactivée)"; }
    if (o.statut === "expiree" || !o.dateExpiration) { return "Offre expirée"; }
    var d = daysUntil(o.dateExpiration);
    if (d <= 0) { return "Expire aujourd'hui"; }
    if (d === 1) { return "Expire demain"; }
    return "Expire dans " + d + " jours";
  }

  /* ---- Mes offres : cartes enrichies (stats + actions) ---- */
  function renderOffers(offers) {
    var box = document.getElementById("dashboard-offers");
    if (!box) { return; }
    var e = SS.escapeHtml;

    if (!offers.length) {
      box.innerHTML =
        '<div class="empty-state"><h3>Vous n\'avez pas encore publié d\'offre</h3>' +
        '<p>Publiez une annonce pour commencer à recevoir des candidatures.</p>' +
        '<p><a class="btn btn-accent" href="publier-offre.html">Créer ma première offre</a></p></div>';
      return;
    }

    box.innerHTML = offers.map(function (o) {
      var renewable = needsRenewal(o);
      var apps = SS.fakeApplicationCount(o.id);
      var expClass = (o.statut === "active" && o.dateExpiration && daysUntil(o.dateExpiration) <= 7) ||
                     o.statut === "expiree" ? " offer-card__expiry--warn" : "";

      var actions =
        '<a class="btn btn-outline btn-sm" href="offres.html">Voir</a>' +
        '<a class="btn btn-outline btn-sm" href="publier-offre.html?modifier=' + encodeURIComponent(o.id) + '">Modifier</a>' +
        '<a class="btn btn-ghost btn-sm" href="#candidatures">Candidatures</a>' +
        '<button type="button" class="btn btn-ghost btn-sm" data-toast="Offre dupliquée (démonstration).">Dupliquer</button>';
      if (o.statut === "active") {
        actions += '<button type="button" class="btn btn-ghost btn-sm" data-offer-action="disable" data-id="' + e(o.id) + '">Désactiver</button>';
      } else if (o.statut === "desactivee") {
        actions += '<button type="button" class="btn btn-primary btn-sm" data-offer-action="enable" data-id="' + e(o.id) + '">Réactiver</button>';
      }
      if (renewable) {
        actions += '<a class="btn btn-accent btn-sm" href="paiement.html?offre=' + encodeURIComponent(o.id) + '">Renouveler</a>';
      }
      actions += '<button type="button" class="btn btn-danger btn-sm" data-offer-action="archive" data-id="' + e(o.id) + '">Archiver</button>';

      return '<article class="card dash-card offer-card">' +
          '<div class="offer-card__head">' +
            '<div><h3 class="offer-card__title">' + e(o.titre) + '</h3>' +
              '<p class="offer-card__meta">' + e(o.ville) + ' — ' + e(o.contrat) + ' · publiée le ' + e(SS.formatDate(o.datePublication)) + '</p></div>' +
            statusBadge(o) +
          '</div>' +
          '<ul class="offer-stats">' +
            '<li><b>' + stableViews(o.id) + '</b><span>vues</span></li>' +
            '<li><b>' + apps + '</b><span>candidatures</span></li>' +
            '<li><b>' + profilesViewed(o.id) + '</b><span>profils consultés</span></li>' +
            '<li><b>' + interviewsForOffer(o.id) + '</b><span>entretiens</span></li>' +
          '</ul>' +
          '<p class="offer-card__expiry' + expClass + '">' + e(expiryLabel(o)) + '</p>' +
          '<div class="row-actions">' + actions + '</div>' +
        '</article>';
    }).join("");

    box.querySelectorAll("button[data-offer-action]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var id = btn.getAttribute("data-id");
        var action = btn.getAttribute("data-offer-action");
        var overrides = SS.store.get(APP_CONFIG.storage.offerOverrides, {});
        overrides[id] = overrides[id] || {};

        if (action === "disable") {
          overrides[id].statut = "desactivee";
          SS.toast("Offre désactivée.");
        } else if (action === "enable") {
          overrides[id].statut = "active";
          var next = new Date();
          next.setDate(next.getDate() + APP_CONFIG.payment.renewal.durationDays);
          overrides[id].dateExpiration = next.toISOString().slice(0, 10);
          SS.toast("Offre réactivée.");
        } else if (action === "archive") {
          overrides[id].archived = true;
          SS.toast("Offre archivée.");
        }
        SS.store.set(APP_CONFIG.storage.offerOverrides, overrides);
        renderDashboard();
      });
    });
  }

  /* Nombre de profils consultés (fictif, stable) — dérivé des vues. */
  function profilesViewed(id) {
    return Math.max(1, Math.round(stableViews(id) / 12));
  }

  /* Nombre d'entretiens liés à une offre (fictif, stable). */
  function interviewsForOffer(id) {
    return SS.fakeApplicationCount(id) % 3;
  }

  /* Carte contextuelle 10 € : affichée si une offre expire bientôt / a expiré. */
  function renderRenewalAlert(toRenew) {
    var box = document.getElementById("renewal-alert");
    if (!box) { return; }
    if (!toRenew.length) { box.innerHTML = ""; return; }

    /* Priorité aux offres déjà expirées, sinon celle qui expire le plus tôt. */
    var target = toRenew.slice().sort(function (a, b) {
      return new Date(a.dateExpiration) - new Date(b.dateExpiration);
    })[0];
    var e = SS.escapeHtml;
    var expired = target.statut === "expiree";

    box.innerHTML =
      '<div class="notice notice--demo" style="margin-top:var(--sp-4);">' +
        "<strong>Votre offre expire bientôt — " + e(target.titre) + "</strong><br>" +
        (expired
          ? "Cette offre a expiré et n'est plus visible des candidats. "
          : "Elle arrive à expiration le " + e(SS.formatDate(target.dateExpiration)) + ". ") +
        "Renouvelez-la pendant 30&nbsp;jours pour 10&nbsp;€.<br>" +
        '<a class="btn btn-accent btn-sm" style="margin-top:var(--sp-3);" href="paiement.html?offre=' +
          encodeURIComponent(target.id) + '">Renouveler pour 10&nbsp;€</a>' +
      "</div>";
  }

  /* ---- À faire aujourd'hui : liste d'actions prioritaires cliquables ---- */
  function initTodo() {
    var list = document.getElementById("dash-todo");
    if (!list) { return; }
    var e = SS.escapeHtml;

    var items = [
      { level: "warn",   texte: "3 nouvelles candidatures à examiner", ancre: "#candidatures", action: "Examiner" },
      { level: "urgent", texte: "1 candidat attend une réponse depuis 4 jours", ancre: "#candidatures", action: "Répondre" },
      { level: "ok",     texte: "2 entretiens cette semaine", ancre: "#entretiens", action: "Voir les entretiens" },
      { level: "warn",   texte: "1 offre expire dans 3 jours", ancre: "#offres", action: "Renouveler" }
    ];

    var labels = { urgent: "Urgent", warn: "À traiter", ok: "À venir" };

    list.innerHTML = items.map(function (it) {
      return '<li class="todo-item">' +
          '<span class="todo-dot todo-dot--' + it.level + '" aria-hidden="true"></span>' +
          '<span class="todo-item__text">' + e(it.texte) + '</span>' +
          '<span class="todo-item__tag todo-item__tag--' + it.level + '">' + e(labels[it.level]) + '</span>' +
          '<a class="btn btn-outline btn-sm todo-item__action" href="' + it.ancre + '">' + e(it.action) + '</a>' +
        '</li>';
    }).join("");
  }

  /* ============================================================
     Pipeline de candidatures (kanban léger, persistant)
     ============================================================ */
  var PIPELINE_COLUMNS = [
    { key: "nouveau", label: "Nouveau" },
    { key: "a-examiner", label: "À examiner" },
    { key: "preselection", label: "Présélectionné" },
    { key: "entretien", label: "Entretien" },
    { key: "retenu", label: "Retenu" },
    { key: "refuse", label: "Refusé" }
  ];

  /* Seed de candidats — métiers généralistes, répartis dans les étapes. */
  function pipelineSeed() {
    return [
      { id: "p1", nom: "Julie Martin", poste: "Assistante commerciale", offre: "Assistant(e) commercial(e) — CDI", ville: "Lyon", exp: 4, skills: ["Relation client", "CRM", "Anglais"], jours: 1, statut: "nouveau" },
      { id: "p2", nom: "Karim Haddad", poste: "Développeur web", offre: "Développeur web full-stack — CDI", ville: "Villeurbanne", exp: 6, skills: ["JavaScript", "PHP", "React"], jours: 1, statut: "nouveau" },
      { id: "p3", nom: "Camille Reynaud", poste: "Comptable", offre: "Collaborateur comptable — CDI", ville: "Lyon", exp: 3, skills: ["Sage", "Bilan", "TVA"], jours: 2, statut: "a-examiner" },
      { id: "p4", nom: "Léa Dubois", poste: "Conductrice de travaux", offre: "Conducteur de travaux — CDI", ville: "Saint-Étienne", exp: 8, skills: ["Chantier", "Gros œuvre", "Sécurité"], jours: 3, statut: "a-examiner" },
      { id: "p5", nom: "Malik Benhaddou", poste: "Gestionnaire de paie", offre: "Gestionnaire de paie — CDI", ville: "Lyon", exp: 5, skills: ["Paie", "Silae", "Social"], jours: 3, statut: "preselection" },
      { id: "p6", nom: "Awa Diallo", poste: "Aide-soignante", offre: "Aide-soignant(e) — CDI", ville: "Bron", exp: 7, skills: ["Soins", "Gériatrie", "Écoute"], jours: 4, statut: "preselection" },
      { id: "p7", nom: "Thomas Ravel", poste: "Préparateur de commandes", offre: "Préparateur de commandes — CDD", ville: "Corbas", exp: 2, skills: ["CACES 1", "Logistique", "Rigueur"], jours: 5, statut: "entretien" },
      { id: "p8", nom: "Inès Fabre", poste: "Chargée de communication", offre: "Chargé(e) de communication — CDI", ville: "Lyon", exp: 4, skills: ["Réseaux sociaux", "Rédaction", "PAO"], jours: 6, statut: "entretien" },
      { id: "p9", nom: "Yannick Perrot", poste: "Technicien de maintenance", offre: "Technicien de maintenance — CDI", ville: "Vénissieux", exp: 9, skills: ["Électromécanique", "Dépannage", "GMAO"], jours: 8, statut: "retenu" },
      { id: "p10", nom: "Sophie Lemaire", poste: "Secrétaire administrative", offre: "Secrétaire administrative — CDD", ville: "Lyon", exp: 3, skills: ["Word", "Accueil", "Planning"], jours: 10, statut: "nouveau" }
    ];
  }

  /* Ordre d'avancement des étapes (hors « refuse »). */
  var PIPELINE_ORDER = ["nouveau", "a-examiner", "preselection", "entretien", "retenu"];

  /* Lit l'état persistant du pipeline (versionné). */
  function pipelineStore() {
    var s = SS.store.get(PIPELINE_KEY, null);
    if (!s || s.v !== PIPELINE_SEED_VERSION || !s.status) {
      return { v: PIPELINE_SEED_VERSION, status: {} };
    }
    return s;
  }

  function setPipelineStatus(id, statut) {
    var s = pipelineStore();
    s.status[id] = statut;
    SS.store.set(PIPELINE_KEY, s);
  }

  /* Statut effectif d'un candidat : refus (par nom, via la modale) prioritaire,
     puis avancement persistant, puis statut du seed. */
  function effectiveStatus(cand, store, refus) {
    if (refus[cand.nom]) { return "refuse"; }
    return store.status[cand.id] || cand.statut;
  }

  function renderPipeline() {
    var board = document.getElementById("pipeline-board");
    if (!board) { return; }
    var e = SS.escapeHtml;

    /* Nettoie les observateurs de la génération précédente. */
    pipelineObservers.forEach(function (o) { o.disconnect(); });
    pipelineObservers = [];

    var store = pipelineStore();
    var refus = SS.store.get(REFUS_KEY, {});
    var seed = pipelineSeed();

    var byCol = {};
    PIPELINE_COLUMNS.forEach(function (c) { byCol[c.key] = []; });
    seed.forEach(function (cand) {
      var st = effectiveStatus(cand, store, refus);
      if (!byCol[st]) { st = "nouveau"; }
      byCol[st].push(cand);
    });

    board.innerHTML = PIPELINE_COLUMNS.map(function (col) {
      var cards = byCol[col.key];
      var body = cards.length
        ? cards.map(function (c) { return pipelineCard(c, col.key); }).join("")
        : '<p class="pipeline-empty">Aucun candidat.</p>';
      return '<div class="pipeline-col" data-col="' + col.key + '">' +
          '<div class="pipeline-col__head">' + e(col.label) +
            '<span class="pipeline-col__count">' + cards.length + '</span></div>' +
          '<div class="pipeline-col__list">' + body + '</div>' +
        '</div>';
    }).join("");

    wirePipeline(board);
  }

  function pipelineCard(c, status) {
    var e = SS.escapeHtml;
    var skills = c.skills.slice(0, 3).map(function (s) {
      return '<span class="skill-tag">' + e(s) + '</span>';
    }).join("");

    var actions = '<button type="button" class="btn btn-outline btn-sm" data-toast="Ouverture du profil candidat (démonstration).">Voir le profil</button>' +
                  '<a class="btn btn-ghost btn-sm" href="#messages">Message</a>';
    if (status === "nouveau" || status === "a-examiner") {
      actions += '<button type="button" class="btn btn-ghost btn-sm" data-advance="preselection" data-id="' + e(c.id) + '" data-nom="' + e(c.nom) + '">Présélectionner</button>';
    }
    if (status === "nouveau" || status === "a-examiner" || status === "preselection") {
      actions += '<button type="button" class="btn btn-primary btn-sm" data-advance="entretien" data-id="' + e(c.id) + '" data-nom="' + e(c.nom) + '">Proposer un entretien</button>';
    }
    if (status === "entretien") {
      actions += '<button type="button" class="btn btn-primary btn-sm" data-advance="retenu" data-id="' + e(c.id) + '" data-nom="' + e(c.nom) + '">Retenir</button>';
    }
    if (status !== "refuse" && status !== "retenu") {
      actions += '<button type="button" class="btn btn-danger btn-sm" data-refus data-nom="' + e(c.nom) + '" data-offre="' + e(c.offre) + '">Refuser</button>';
    }

    var refusedNote = status === "refuse"
      ? '<p class="pipeline-card__note">Candidat informé avec un message courtois.</p>'
      : '';

    return '<article class="appli-card pipeline-card" data-cand="' + e(c.id) + '" tabindex="-1">' +
        '<div class="pipeline-card__id">' +
          '<strong>' + e(c.nom) + '</strong>' +
          '<span class="pipeline-card__poste">' + e(c.poste) + '</span>' +
        '</div>' +
        '<p class="pipeline-card__meta">' + e(c.ville) + ' · ' + c.exp + ' ans d\'expérience</p>' +
        '<div class="pipeline-card__skills">' + skills + '</div>' +
        '<p class="pipeline-card__offer">' + e(c.offre) + '</p>' +
        '<p class="pipeline-card__date">Candidature reçue ' + e(SS.relativeDate(dateFromToday(-c.jours))) + '</p>' +
        refusedNote +
        '<div class="row-actions">' + actions + '</div>' +
      '</article>';
  }

  function wirePipeline(board) {
    /* Avancement d'étape : présélection / entretien / retenu. */
    board.querySelectorAll("button[data-advance]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var id = btn.getAttribute("data-id");
        var to = btn.getAttribute("data-advance");
        var nom = btn.getAttribute("data-nom") || "";
        setPipelineStatus(id, to);
        var msg = to === "preselection" ? "Candidat présélectionné."
                : to === "entretien" ? "Entretien proposé à " + nom + "."
                : "Candidat retenu.";
        renderPipeline();
        focusCard(id);
        SS.toast(msg);
      });
    });

    /* Refus : réutilise la modale courtoise existante (inchangée). Lorsque le
       bouton est désactivé par la modale (refus confirmé), on régénère le
       pipeline pour déplacer la carte dans la colonne « Refusé ». */
    board.querySelectorAll("[data-refus]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        if (openRefusModal) { openRefusModal(btn); }
      });
      if ("MutationObserver" in window) {
        var card = btn.closest(".pipeline-card");
        var id = card ? card.getAttribute("data-cand") : null;
        var obs = new MutationObserver(function () {
          if (btn.disabled) {
            obs.disconnect();
            renderPipeline();
            if (id) { focusCard(id); }
          }
        });
        obs.observe(btn, { attributes: true, attributeFilter: ["disabled"] });
        pipelineObservers.push(obs);
      }
    });
  }

  function focusCard(id) {
    var el = document.querySelector('.pipeline-card[data-cand="' + id + '"]');
    if (el) { el.focus(); }
  }

  /* ============================================================
     Entretiens
     ============================================================ */
  function seedInterviews() {
    var box = document.getElementById("interviews-list");
    if (!box) { return; }
    var e = SS.escapeHtml;

    var items = [
      { quand: "Mardi 25 août · 14:30", nom: "Julie Martin", poste: "Assistante commerciale", mode: "Visioconférence" },
      { quand: "Mercredi 26 août · 10:00", nom: "Thomas Ravel", poste: "Préparateur de commandes", mode: "Dans nos locaux — Lyon 3e" },
      { quand: "Jeudi 27 août · 16:15", nom: "Inès Fabre", poste: "Chargée de communication", mode: "Téléphone" }
    ];

    if (!items.length) {
      box.innerHTML = '<div class="empty-state"><h3>Aucun entretien planifié</h3>' +
        '<p>Proposez un entretien depuis le pipeline de candidatures pour le voir apparaître ici.</p></div>';
      return;
    }

    box.innerHTML = items.map(function (it) {
      return '<article class="appli-card interview-card">' +
          '<div class="interview-card__when">' + e(it.quand) + '</div>' +
          '<div class="interview-card__who"><strong>' + e(it.nom) + '</strong>' +
            '<span class="text-muted">' + e(it.poste) + '</span></div>' +
          '<p class="interview-card__mode">' + e(it.mode) + '</p>' +
          '<div class="row-actions">' +
            '<button type="button" class="btn btn-outline btn-sm" data-toast="Ouverture du profil candidat (démonstration).">Voir le profil</button>' +
            '<a class="btn btn-ghost btn-sm" href="#messages">Message</a>' +
            '<button type="button" class="btn btn-ghost btn-sm" data-toast="Modification de l\'entretien (démonstration).">Modifier</button>' +
          '</div>' +
        '</article>';
    }).join("");
  }

  /* ============================================================
     Messagerie simple (liste + conversation)
     ============================================================ */
  var conversations = null;
  var currentConvId = null;

  function seedConversations() {
    var today = new Date();
    function iso(offset) { var d = new Date(today); d.setDate(d.getDate() + offset); return d.toISOString().slice(0, 10); }
    return [
      {
        id: "m1", nom: "Julie Martin", poste: "Assistante commerciale", offre: "Assistant(e) commercial(e) — CDI",
        messages: [
          { from: "them", texte: "Bonjour, je vous remercie pour votre retour. Je reste disponible pour un entretien la semaine prochaine.", date: iso(-2) },
          { from: "me", texte: "Bonjour Julie, avec plaisir. Seriez-vous disponible mardi à 14h30 en visioconférence ?", date: iso(-1) },
          { from: "them", texte: "C'est parfait pour moi, je note le rendez-vous. À mardi !", date: iso(0) }
        ]
      },
      {
        id: "m2", nom: "Karim Haddad", poste: "Développeur web", offre: "Développeur web full-stack — CDI",
        messages: [
          { from: "them", texte: "Bonjour, ma candidature au poste de développeur est-elle toujours à l'étude ?", date: iso(-1) }
        ]
      },
      {
        id: "m3", nom: "Awa Diallo", poste: "Aide-soignante", offre: "Aide-soignant(e) — CDI",
        messages: [
          { from: "me", texte: "Bonjour Awa, votre profil nous intéresse. Pourrions-nous échanger par téléphone cette semaine ?", date: iso(-3) },
          { from: "them", texte: "Bonjour, merci beaucoup. Je suis joignable tous les après-midi. Bien à vous.", date: iso(-2) }
        ]
      }
    ];
  }

  function initMessages() {
    var wrap = document.getElementById("messaging");
    var listBox = document.getElementById("messaging-list");
    var threadBox = document.getElementById("messaging-thread");
    if (!wrap || !listBox || !threadBox) { return; }

    conversations = seedConversations();

    if (!conversations.length) {
      wrap.innerHTML = '<div class="empty-state"><h3>Aucune conversation pour le moment.</h3>' +
        '<p>Vos échanges avec les candidats apparaîtront ici.</p></div>';
      return;
    }

    currentConvId = conversations[0].id;
    renderConvList();
    renderThread();

    listBox.addEventListener("click", function (ev) {
      var btn = ev.target.closest("[data-conv]");
      if (!btn) { return; }
      currentConvId = btn.getAttribute("data-conv");
      renderConvList();
      renderThread();
      wrap.classList.add("is-thread-open"); /* mobile : bascule vers le fil */
      var t = threadBox.querySelector("h3");
      if (t) { t.setAttribute("tabindex", "-1"); t.focus(); }
    });
  }

  function convInitials(nom) {
    var parts = String(nom).trim().split(/\s+/);
    var a = parts[0] ? parts[0][0] : "";
    var b = parts[1] ? parts[1][0] : "";
    return (a + b).toUpperCase();
  }

  function renderConvList() {
    var listBox = document.getElementById("messaging-list");
    if (!listBox) { return; }
    var e = SS.escapeHtml;
    listBox.innerHTML = conversations.map(function (c) {
      var last = c.messages[c.messages.length - 1];
      var active = c.id === currentConvId ? " is-active" : "";
      return '<button type="button" class="conv-item' + active + '" data-conv="' + e(c.id) + '" role="tab" aria-selected="' + (c.id === currentConvId) + '">' +
          '<span class="avatar conv-item__avatar" aria-hidden="true">' + e(convInitials(c.nom)) + '</span>' +
          '<span class="conv-item__body">' +
            '<span class="conv-item__name">' + e(c.nom) + '</span>' +
            '<span class="conv-item__preview">' + e(last ? last.texte : "") + '</span>' +
          '</span>' +
        '</button>';
    }).join("");
  }

  function renderThread() {
    var threadBox = document.getElementById("messaging-thread");
    if (!threadBox) { return; }
    var e = SS.escapeHtml;
    var conv = conversations.filter(function (c) { return c.id === currentConvId; })[0];
    if (!conv) { threadBox.innerHTML = ""; return; }

    var bubbles = conv.messages.map(function (m) {
      var who = m.from === "me" ? "conv-msg--me" : "conv-msg--them";
      return '<div class="conv-msg ' + who + '">' +
          '<p class="conv-msg__text">' + e(m.texte) + '</p>' +
          '<span class="conv-msg__date">' + e(SS.formatDate(m.date)) + '</span>' +
        '</div>';
    }).join("");

    threadBox.innerHTML =
      '<div class="conv-head">' +
        '<button type="button" class="btn btn-ghost btn-sm conv-back" data-conv-back>← Conversations</button>' +
        '<div class="conv-head__id">' +
          '<span class="avatar" aria-hidden="true">' + e(convInitials(conv.nom)) + '</span>' +
          '<span><h3 class="conv-head__name">' + e(conv.nom) + '</h3>' +
            '<span class="text-muted">' + e(conv.poste) + ' · ' + e(conv.offre) + '</span></span>' +
        '</div>' +
      '</div>' +
      '<div class="conv-body">' + bubbles + '</div>' +
      '<form class="conv-reply" id="conv-reply">' +
        '<label class="visually-hidden" for="conv-reply-text">Votre réponse à ' + e(conv.nom) + '</label>' +
        '<textarea id="conv-reply-text" rows="2" placeholder="Écrivez votre réponse…"></textarea>' +
        '<button type="submit" class="btn btn-primary btn-sm">Envoyer</button>' +
      '</form>';

    var back = threadBox.querySelector("[data-conv-back]");
    if (back) {
      back.addEventListener("click", function () {
        var wrap = document.getElementById("messaging");
        if (wrap) { wrap.classList.remove("is-thread-open"); }
      });
    }

    var form = threadBox.querySelector("#conv-reply");
    if (form) {
      form.addEventListener("submit", function (ev) {
        ev.preventDefault();
        var field = form.querySelector("#conv-reply-text");
        var txt = (field.value || "").trim();
        if (!txt) { return; }
        conv.messages.push({ from: "me", texte: txt, date: new Date().toISOString().slice(0, 10) });
        renderConvList();
        renderThread();
        SS.toast("Message envoyé (démonstration).");
      });
    }
  }

  /* ============================================================
     Contenus entreprise (marque employeur)
     ============================================================ */
  function seedContents() {
    var box = document.getElementById("contents-list");
    if (!box) { return; }
    var e = SS.escapeHtml;

    var items = [
      { titre: "Une journée avec notre conducteur de travaux", type: "Reportage", jours: 5 },
      { titre: "Découvrez notre atelier et nos équipements", type: "Visite", jours: 18 },
      { titre: "Comment travaille notre équipe support", type: "Coulisses", jours: 40 }
    ];

    if (!items.length) {
      box.innerHTML = '<div class="empty-state"><h3>Aucun contenu publié</h3>' +
        '<p>Partagez les coulisses de votre entreprise pour attirer les bons profils.</p>' +
        '<p><a class="btn btn-accent" href="publier-savoir-faire.html">Publier un contenu</a></p></div>';
      return;
    }

    box.innerHTML = items.map(function (it) {
      return '<article class="card dash-card content-card">' +
          '<div class="content-card__body">' +
            '<span class="badge badge--neutral">' + e(it.type) + '</span>' +
            '<h3 class="content-card__title">' + e(it.titre) + '</h3>' +
            '<p class="text-muted">Publié ' + e(SS.relativeDate(dateFromToday(-it.jours))) + '</p>' +
          '</div>' +
          '<div class="row-actions">' +
            '<a class="btn btn-outline btn-sm" href="savoir-faire.html">Voir</a>' +
            '<button type="button" class="btn btn-ghost btn-sm" data-toast="Modification du contenu (démonstration).">Modifier</button>' +
          '</div>' +
        '</article>';
    }).join("");
  }

  /* ============================================================
     Profil entreprise — affichage + mode Modifier
     ============================================================ */
  function initProfilEdit() {
    var view = document.getElementById("profil-desc-view");
    var form = document.getElementById("profil-desc-form");
    var field = document.getElementById("profil-desc");
    var editBtn = document.getElementById("profil-edit-btn");
    var saveBtn = document.getElementById("profil-desc-save");
    var cancelBtn = document.getElementById("profil-desc-cancel");
    if (!view || !form || !field || !editBtn || !saveBtn || !cancelBtn) { return; }

    /* Réhydrate la présentation éventuellement éditée précédemment. */
    var stored = SS.store.get(PROFILE_KEY, null);
    if (stored && typeof stored.description === "string" && stored.description.trim()) {
      view.textContent = stored.description;
      field.value = stored.description;
    }

    function openEdit() {
      field.value = view.textContent;
      form.hidden = false;
      editBtn.hidden = true;
      field.focus();
    }
    function closeEdit() {
      form.hidden = true;
      editBtn.hidden = false;
      editBtn.focus();
    }

    editBtn.addEventListener("click", openEdit);
    cancelBtn.addEventListener("click", closeEdit);
    saveBtn.addEventListener("click", function () {
      var val = (field.value || "").trim();
      if (val) { view.textContent = val; }
      SS.store.set(PROFILE_KEY, { description: view.textContent });
      closeEdit();
      SS.toast("Présentation enregistrée (démonstration).");
    });
  }

  /* ---- Historique de facturation (données de démonstration) ---- */
  function seedBilling() {
    var tbody = document.getElementById("billing-history");
    if (!tbody) { return; }
    var e = SS.escapeHtml;

    var rows = [
      { date: dateFromToday(-12), offre: "Assistant(e) commercial(e) — CDI" },
      { date: dateFromToday(-48), offre: "Gestionnaire de paie — CDI" },
      { date: dateFromToday(-95), offre: "Conducteur de travaux — CDI" }
    ];

    tbody.innerHTML = rows.map(function (r) {
      return "<tr>" +
        '<td data-label="Date">' + e(SS.formatDate(r.date)) + "</td>" +
        '<td data-label="Offre">' + e(r.offre) + "</td>" +
        '<td data-label="Montant">10&nbsp;€</td>' +
        '<td data-label="Statut"><span class="badge badge--remote">Payé</span></td>' +
        '<td data-label="Facture"><button type="button" class="btn btn-ghost btn-sm" data-toast="Téléchargement de la facture (démonstration).">Facture</button></td>' +
      "</tr>";
    }).join("");
  }

  /* ---- Navigation latérale : surlignage de la section visible ---- */
  function initNav() {
    var links = Array.prototype.slice.call(document.querySelectorAll(".dash-nav a[data-nav]"));
    if (!links.length) { return; }

    var byId = {};
    links.forEach(function (a) { byId[a.getAttribute("data-nav")] = a; });

    function activate(id) {
      links.forEach(function (a) { a.classList.remove("is-active"); });
      if (byId[id]) { byId[id].classList.add("is-active"); }
    }

    links.forEach(function (a) {
      a.addEventListener("click", function () { activate(a.getAttribute("data-nav")); });
    });

    if ("IntersectionObserver" in window) {
      var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) { activate(entry.target.id); }
        });
      }, { rootMargin: "-45% 0px -45% 0px", threshold: 0 });

      links.forEach(function (a) {
        var section = document.getElementById(a.getAttribute("data-nav"));
        if (section) { observer.observe(section); }
      });
    }
  }

  /* ---- Déconnexion ---- */
  function initLogout() {
    var btn = document.getElementById("logout-btn");
    if (btn) {
      btn.addEventListener("click", function () { SS.auth.logout(); });
    }
  }

  /* ---- Boutons fictifs (data-toast) : profil, facturation, contenus… ---- */
  function initToasts() {
    document.addEventListener("click", function (ev) {
      var btn = ev.target.closest ? ev.target.closest("[data-toast]") : null;
      if (btn) { SS.toast(btn.getAttribute("data-toast")); }
    });
  }

  /* ---- Modale de refus de candidature (accessible, focus piégé) ----
     LA MODALE DE REFUS COURTOIS EST INCHANGÉE ET VALIDÉE : motif interne
     jamais transmis, message courtois pré-rempli et éditable, toast, statut
     « non retenue ». Réutilisée depuis les cartes du pipeline. */
  function initRefusModal() {
    var overlay = document.getElementById("refus-modal");
    if (!overlay) { return; }
    var dialog = overlay.querySelector(".modal");
    var titleEl = document.getElementById("refus-modal-title");
    var form = document.getElementById("refus-form");
    var messageEl = document.getElementById("refus-message");
    var courtoisEl = document.getElementById("refus-courtois");
    var confirmBtn = document.getElementById("refus-confirm");
    if (!dialog || !titleEl || !form || !messageEl || !courtoisEl || !confirmBtn) { return; }

    var state = { trigger: null, card: null, nom: "", offre: "" };

    function selectedMotif() {
      var r = form.querySelector('input[name="refus-motif"]:checked');
      return r ? r.value : "";
    }

    /* Case cochée + motif choisi → pré-remplit le message courtois. */
    function fillCourtois() {
      if (!courtoisEl.checked) { return; }
      var m = selectedMotif();
      if (m && COURTOIS[m]) { messageEl.value = COURTOIS[m]; }
    }

    form.querySelectorAll('input[name="refus-motif"]').forEach(function (r) {
      r.addEventListener("change", fillCourtois);
    });
    courtoisEl.addEventListener("change", fillCourtois);

    function focusables() {
      var sel = 'button:not([disabled]), [href], input:not([disabled]), textarea:not([disabled]), select:not([disabled])';
      return Array.prototype.slice.call(dialog.querySelectorAll(sel)).filter(function (el) {
        return el.offsetParent !== null;
      });
    }

    function onKeydown(ev) {
      if (ev.key === "Escape") { ev.preventDefault(); close(); return; }
      if (ev.key !== "Tab") { return; }
      var f = focusables();
      if (!f.length) { return; }
      var first = f[0], last = f[f.length - 1];
      if (ev.shiftKey && document.activeElement === first) {
        ev.preventDefault(); last.focus();
      } else if (!ev.shiftKey && document.activeElement === last) {
        ev.preventDefault(); first.focus();
      }
    }

    function open(trigger) {
      state.trigger = trigger;
      state.card = trigger.closest(".appli-card");
      state.nom = trigger.getAttribute("data-nom") || "";
      state.offre = trigger.getAttribute("data-offre") || "";
      titleEl.textContent = "Refuser la candidature de " + state.nom;

      form.querySelectorAll('input[name="refus-motif"]').forEach(function (r) { r.checked = false; });
      courtoisEl.checked = true;
      messageEl.value = "";

      overlay.hidden = false;
      document.addEventListener("keydown", onKeydown);
      var f = focusables();
      if (f.length) { f[0].focus(); }
    }

    function close() {
      overlay.hidden = true;
      document.removeEventListener("keydown", onKeydown);
      /* Rend le focus au déclencheur ; si celui-ci est désormais désactivé
         (candidature refusée), on le rend à la carte concernée. */
      var t = state.trigger;
      if (t && !t.disabled && t.offsetParent !== null) {
        t.focus();
      } else if (state.card) {
        state.card.setAttribute("tabindex", "-1");
        state.card.focus();
      }
    }

    /* Clic sur l'overlay (hors modale) ferme. */
    overlay.addEventListener("click", function (ev) {
      if (ev.target === overlay) { close(); }
    });
    overlay.querySelectorAll("[data-refus-close]").forEach(function (b) {
      b.addEventListener("click", close);
    });

    confirmBtn.addEventListener("click", function () {
      var m = selectedMotif();
      /* Le message transmis est toujours courtois : le contenu de la zone de
         texte (prédéfini ou édité) ou, à défaut, un message générique. Jamais
         le libellé brut du motif. */
      var finalMsg = messageEl.value.trim();
      if (!finalMsg) { finalMsg = COURTOIS[m] || COURTOIS.autre; }
      var today = new Date().toISOString().slice(0, 10);

      if (state.card) {
        var badge = state.card.querySelector(".status-badge");
        if (badge) {
          badge.className = "status-badge status-non-retenue";
          badge.textContent = "Candidature non retenue";
        }
        var dateLine = state.card.querySelector("[data-refus-date]");
        if (dateLine) {
          dateLine.hidden = false;
          dateLine.textContent = "Candidat informé le " + SS.formatDate(today);
        }
        var refBtn = state.card.querySelector("[data-refus]");
        if (refBtn) { refBtn.disabled = true; refBtn.textContent = "Candidature refusée"; }
      }

      /* Persiste le message courtois final (exploité côté candidat). */
      var stored = SS.store.get(REFUS_KEY, {});
      stored[state.nom] = { nom: state.nom, offre: state.offre, message: finalMsg, date: today };
      SS.store.set(REFUS_KEY, stored);

      close();
      SS.toast("Le candidat a été informé avec un message courtois.");
    });

    openRefusModal = open;
  }

  /* ---- Utilitaires ---- */
  function setText(id, value) {
    var el = document.getElementById(id);
    if (el) { el.textContent = String(value); }
  }

  /* Initiales d'une raison sociale : deux premières lettres significatives
     (ex. « Fiduciaire Bellecour » → « FB »). Ignore les petits mots liants. */
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
})();

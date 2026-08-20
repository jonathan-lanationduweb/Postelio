/**
 * Espace recruteur — Candidatures (espace-entreprise-candidatures.html).
 *
 * Kanban type Trello (glisser-déposer natif) avec workflows métier au drop,
 * vue liste alternative (desktop) et cartes verticales + bottom-sheet (mobile).
 * Aucune librairie externe, aucun rechargement de page.
 *
 * Clés de stockage local (conservées / étendues) :
 *   ss_pipeline_v1     — { v, status:{id:statut}, order:{statut:[ids]} }
 *                        étape courante + ordre des cartes dans chaque colonne.
 *   ss_refus_demo      — message courtois final d'un refus (lu côté candidat).
 *   ss_cand_notes_v1   — notes recruteur privées, par candidat (jamais transmises).
 *
 * Le motif interne de refus n'est JAMAIS transmis : seul un message courtois
 * (prédéfini ou édité) est envoyé au candidat.
 */
(function () {
  "use strict";

  var REFUS_KEY = "ss_refus_demo";
  var PIPELINE_KEY = "ss_pipeline_v1";
  var NOTES_KEY = "ss_cand_notes_v1";
  var PIPELINE_SEED_VERSION = 2; /* bump : nouvelle structure (order) + statuts */

  /* Messages courtois prédéfinis, indexés par motif interne. */
  var COURTOIS = {
    profil: "Bonjour, nous vous remercions pour votre candidature et l'intérêt porté à notre entreprise. Après étude attentive de votre profil, nous avons décidé de ne pas y donner suite pour ce poste. Nous vous souhaitons une pleine réussite dans vos recherches.",
    experience: "Bonjour, merci beaucoup pour votre candidature. Nous avons retenu d'autres profils dont le parcours correspondait davantage aux attentes de ce poste. Nous conservons votre candidature et vous souhaitons une belle continuation.",
    pourvu: "Bonjour, nous vous remercions pour votre candidature. Le poste vient malheureusement d'être pourvu. Nous ne manquerons pas de revenir vers vous si une opportunité similaire se présente. Bonne continuation à vous.",
    dispo: "Bonjour, merci pour votre candidature et pour le temps consacré à notre échange. Les disponibilités ne correspondent pas au besoin actuel de l'équipe. Nous vous souhaitons pleine réussite dans la suite de vos démarches.",
    autre: "Bonjour, nous vous remercions sincèrement pour votre candidature. Nous ne sommes pas en mesure d'y donner une suite favorable pour le moment. Nous vous souhaitons une excellente continuation."
  };

  /* Colonnes du kanban. La colonne « Décision » contient DEUX zones de dépôt
     étiquetées, chacune un statut distinct (retenu / refuse). */
  var COLUMNS = [
    { key: "nouveau", label: "Nouveau" },
    { key: "examiner", label: "À examiner" },
    { key: "preselection", label: "Présélectionné" },
    { key: "entretien", label: "Entretien" },
    { key: "decision", label: "Décision", zones: [
      { key: "retenu", label: "Retenu" },
      { key: "refuse", label: "Refusé" }
    ] }
  ];

  var STATUS = {
    nouveau: { label: "Nouveau" },
    examiner: { label: "À examiner" },
    preselection: { label: "Présélectionné" },
    entretien: { label: "Entretien" },
    retenu: { label: "Retenu" },
    refuse: { label: "Refusé" }
  };
  var STATUS_ORDER = ["nouveau", "examiner", "preselection", "entretien", "retenu", "refuse"];

  /* Modales (initialisées au chargement). */
  var refusModal, entretienModal, retenuModal, detailModal, sheetModal;
  var openRefusModal = null;

  /* Données + index. */
  var CANDS = [];
  var byId = {};
  var seedIndex = {};

  /* État UI (vue courante + filtres). */
  var state = { view: "kanban", filters: { q: "", offre: "", date: "", exp: "" } };
  var lastDragEnd = 0;
  var toastTimer = null;

  document.addEventListener("DOMContentLoaded", function () {
    if (!window.EMP || !EMP.ready) { return; }
    if (!document.getElementById("pipeline-board")) { return; }

    CANDS = pipelineSeed();
    CANDS.forEach(function (c, i) { byId[c.id] = c; seedIndex[c.id] = i; });

    initRefusModal();
    initEntretienModal();
    initRetenuModal();
    initDetailModal();
    initStatusSheet();
    initToolbar();
    renderAll();
    initHorizontalScroll();
  });

  /* ============================================================
     Défilement horizontal du Kanban : molette → horizontal + barre de
     défilement dupliquée EN HAUT du pipeline (synchronisée), pour scroller
     sans devoir descendre jusqu'en bas de la zone.
     ============================================================ */
  function initHorizontalScroll() {
    var wrap = document.getElementById("pipeline-wrap");
    if (!wrap) { return; }

    /* 1. Molette verticale → défilement horizontal (quand il y a débordement). */
    wrap.addEventListener("wheel", function (ev) {
      if (wrap.scrollWidth <= wrap.clientWidth) { return; }
      if (Math.abs(ev.deltaY) <= Math.abs(ev.deltaX)) { return; }
      wrap.scrollLeft += ev.deltaY;
      ev.preventDefault();
    }, { passive: false });

    /* 2. Barre de défilement en haut : un proxy synchronisé avec le pipeline. */
    var top = document.createElement("div");
    top.className = "pipeline-scrolltop";
    top.setAttribute("aria-hidden", "true");
    var inner = document.createElement("div");
    top.appendChild(inner);
    wrap.parentNode.insertBefore(top, wrap);

    function sync() {
      inner.style.width = wrap.scrollWidth + "px";
      top.style.display = wrap.scrollWidth > wrap.clientWidth ? "block" : "none";
    }
    var lock = false;
    top.addEventListener("scroll", function () {
      if (lock) { return; } lock = true; wrap.scrollLeft = top.scrollLeft; lock = false;
    });
    wrap.addEventListener("scroll", function () {
      if (lock) { return; } lock = true; top.scrollLeft = wrap.scrollLeft; lock = false;
    });
    sync();
    window.addEventListener("resize", sync);
    /* Recalcule après un rendu du pipeline (filtres, changement de vue). */
    document.addEventListener("pipeline:rendered", sync);
  }

  /* ============================================================
     Données de démonstration (seed enrichi : 10 candidats variés)
     ============================================================ */
  function pipelineSeed() {
    return [
      { id: "p1", nom: "Julie Martin", poste: "Assistante commerciale", offre: "Assistant(e) commercial(e) — CDI", ville: "Lyon", exp: 4, skills: ["Relation client", "CRM", "Anglais"], savoirFaire: ["Sens du service", "Organisation"], dispo: "Immédiate", cv: "CV_Julie_Martin.pdf", jours: 1, statut: "nouveau",
        experience: "Quatre ans en administration des ventes, dont deux en gestion d'un portefeuille grands comptes.",
        historique: ["Candidature reçue", "CV consulté par le recruteur"] },
      { id: "p2", nom: "Karim Haddad", poste: "Développeur web", offre: "Développeur web full-stack — CDI", ville: "Villeurbanne", exp: 6, skills: ["JavaScript", "PHP", "React"], savoirFaire: ["Autonomie", "Esprit d'équipe"], dispo: "Préavis 1 mois", cv: "CV_Karim_Haddad.pdf", jours: 1, statut: "nouveau",
        experience: "Six ans en agence puis en éditeur logiciel, sur des applications métier full-stack.",
        historique: ["Candidature reçue"] },
      { id: "p3", nom: "Camille Reynaud", poste: "Comptable", offre: "Collaborateur comptable — CDI", ville: "Lyon", exp: 3, skills: ["Sage", "Bilan", "TVA"], savoirFaire: ["Rigueur", "Confidentialité"], dispo: "Préavis 2 mois", cv: "CV_Camille_Reynaud.pdf", jours: 2, statut: "examiner",
        experience: "Trois ans en cabinet d'expertise comptable, gestion d'un portefeuille de TPE.",
        historique: ["Candidature reçue", "Passée à l'étape À examiner"] },
      { id: "p4", nom: "Léa Dubois", poste: "Conductrice de travaux", offre: "Conducteur de travaux — CDI", ville: "Saint-Étienne", exp: 8, skills: ["Chantier", "Gros œuvre", "Sécurité"], savoirFaire: ["Leadership", "Gestion des priorités"], dispo: "Immédiate", cv: "CV_Lea_Dubois.pdf", jours: 3, statut: "examiner",
        experience: "Huit ans en pilotage de chantiers de logements collectifs et bâtiments tertiaires.",
        historique: ["Candidature reçue", "Passée à l'étape À examiner"] },
      { id: "p5", nom: "Malik Benhaddou", poste: "Gestionnaire de paie", offre: "Gestionnaire de paie — CDI", ville: "Lyon", exp: 5, skills: ["Paie", "Silae", "Social"], savoirFaire: ["Fiabilité", "Discrétion"], dispo: "Préavis 1 mois", cv: "CV_Malik_Benhaddou.pdf", jours: 3, statut: "preselection",
        experience: "Cinq ans en gestion de paie multi-conventions (jusqu'à 300 bulletins/mois).",
        historique: ["Candidature reçue", "Présélectionné"] },
      { id: "p6", nom: "Awa Diallo", poste: "Aide-soignante", offre: "Aide-soignant(e) — CDI", ville: "Bron", exp: 7, skills: ["Soins", "Gériatrie", "Écoute"], savoirFaire: ["Empathie", "Travail en équipe"], dispo: "Immédiate", cv: "CV_Awa_Diallo.pdf", jours: 4, statut: "preselection",
        experience: "Sept ans en EHPAD et service de gériatrie, accompagnement des personnes dépendantes.",
        historique: ["Candidature reçue", "Présélectionnée"] },
      { id: "p7", nom: "Thomas Ravel", poste: "Préparateur de commandes", offre: "Préparateur de commandes — CDD", ville: "Corbas", exp: 2, skills: ["CACES 1", "Logistique", "Rigueur"], savoirFaire: ["Ponctualité", "Endurance"], dispo: "Immédiate", cv: "CV_Thomas_Ravel.pdf", jours: 5, statut: "entretien",
        experience: "Deux ans en entrepôt logistique, préparation et contrôle des commandes.",
        historique: ["Candidature reçue", "Présélectionné", "Entretien proposé"] },
      { id: "p8", nom: "Inès Fabre", poste: "Chargée de communication", offre: "Chargé(e) de communication — CDI", ville: "Lyon", exp: 4, skills: ["Réseaux sociaux", "Rédaction", "PAO"], savoirFaire: ["Créativité", "Sens du détail"], dispo: "Préavis 1 mois", cv: "CV_Ines_Fabre.pdf", jours: 6, statut: "entretien",
        experience: "Quatre ans en communication interne et digitale, pilotage de la ligne éditoriale.",
        historique: ["Candidature reçue", "Présélectionnée", "Entretien proposé"] },
      { id: "p9", nom: "Yannick Perrot", poste: "Technicien de maintenance", offre: "Technicien de maintenance — CDI", ville: "Vénissieux", exp: 9, skills: ["Électromécanique", "Dépannage", "GMAO"], savoirFaire: ["Réactivité", "Méthode"], dispo: "Préavis 3 mois", cv: "CV_Yannick_Perrot.pdf", jours: 8, statut: "retenu",
        experience: "Neuf ans en maintenance industrielle, interventions préventives et curatives.",
        historique: ["Candidature reçue", "Présélectionné", "Entretien réalisé", "Retenu"] },
      { id: "p10", nom: "Sophie Lemaire", poste: "Secrétaire administrative", offre: "Secrétaire administrative — CDD", ville: "Lyon", exp: 3, skills: ["Word", "Accueil", "Planning"], savoirFaire: ["Polyvalence", "Amabilité"], dispo: "Immédiate", cv: "CV_Sophie_Lemaire.pdf", jours: 10, statut: "nouveau",
        experience: "Trois ans en secrétariat administratif et accueil au sein de PME.",
        historique: ["Candidature reçue"] }
    ];
  }

  /* ============================================================
     Stockage
     ============================================================ */
  function pipelineStore() {
    var s = SS.store.get(PIPELINE_KEY, null);
    if (!s || s.v !== PIPELINE_SEED_VERSION || !s.status) {
      return { v: PIPELINE_SEED_VERSION, status: {}, order: {} };
    }
    if (!s.order) { s.order = {}; }
    return s;
  }

  function savePipeline(s) { SS.store.set(PIPELINE_KEY, s); }

  function setPipelineStatus(id, statut) {
    var s = pipelineStore();
    s.status[id] = statut;
    savePipeline(s);
  }

  function saveOrder(statusKey, ids) {
    var s = pipelineStore();
    s.order[statusKey] = ids;
    savePipeline(s);
  }

  function clearRefus(nom) {
    var r = SS.store.get(REFUS_KEY, {});
    if (r[nom]) { delete r[nom]; SS.store.set(REFUS_KEY, r); }
  }

  function getNotes() { return SS.store.get(NOTES_KEY, {}); }
  function setNote(id, text) { var n = getNotes(); n[id] = text; SS.store.set(NOTES_KEY, n); }

  function effectiveStatus(cand, store, refus) {
    var s = store.status[cand.id];
    if (s && STATUS[s]) { return s; }
    if (refus[cand.nom]) { return "refuse"; }
    return cand.statut;
  }

  function statusBadgeClass(k) {
    return k === "retenu" ? "status-recue"
      : k === "refuse" ? "status-refusee"
      : k === "entretien" ? "status-entretien"
      : k === "preselection" ? "status-preselection"
      : k === "examiner" ? "status-vue"
      : "status-envoyee";
  }

  /* Regroupe les candidats par statut, dans l'ordre persisté. */
  function groupByStatus(store, refus) {
    var by = {};
    STATUS_ORDER.forEach(function (k) { by[k] = []; });
    CANDS.forEach(function (c) {
      var st = effectiveStatus(c, store, refus);
      if (!by[st]) { st = "nouveau"; }
      by[st].push(c);
    });
    STATUS_ORDER.forEach(function (k) { by[k] = orderedCards(k, by[k], store); });
    return by;
  }

  function orderedCards(statusKey, cards, store) {
    var ord = (store.order && store.order[statusKey]) || [];
    var pos = {};
    ord.forEach(function (id, i) { pos[id] = i; });
    return cards.slice().sort(function (a, b) {
      var pa = pos[a.id] == null ? 1e6 + seedIndex[a.id] : pos[a.id];
      var pb = pos[b.id] == null ? 1e6 + seedIndex[b.id] : pos[b.id];
      return pa - pb;
    });
  }

  /* ============================================================
     Rendu
     ============================================================ */
  function renderAll() {
    renderPipeline();
    renderMobile();
    renderList();
    applyFilters();
    updateView();
    document.dispatchEvent(new CustomEvent("pipeline:rendered"));
  }

  function cardHtml(c, status, draggable) {
    var e = SS.escapeHtml;
    var skills = c.skills.slice(0, 3).map(function (s) {
      return '<span class="skill-tag">' + e(s) + "</span>";
    }).join("");
    var drag = draggable === false ? "" : ' draggable="true"';
    var badge = '<span class="status-badge ' + statusBadgeClass(status) + '" data-badge>' + e(STATUS[status].label) + "</span>";
    var aria = c.nom + ", " + c.poste + ", étape " + STATUS[status].label + ". Entrée pour ouvrir le détail.";

    return '<article class="appli-card pipeline-card"' + drag + ' tabindex="0" role="button"' +
        ' data-cand="' + e(c.id) + '" data-status="' + e(status) + '"' +
        ' aria-label="' + e(aria) + '">' +
        '<div class="pipeline-card__top">' +
          '<div class="pipeline-card__id"><strong>' + e(c.nom) + "</strong>" +
            '<span class="pipeline-card__poste">' + e(c.poste) + "</span></div>" +
          badge +
        "</div>" +
        '<p class="pipeline-card__meta">' + e(c.ville) + " · " + c.exp + " ans d'expérience</p>" +
        '<div class="pipeline-card__skills">' + skills + "</div>" +
        '<p class="pipeline-card__offer">' + e(c.offre) + "</p>" +
        '<p class="pipeline-card__date">Dernière activité : ' + e(SS.relativeDate(EMP.dateFromToday(-c.jours))) + "</p>" +
        '<div class="row-actions">' +
          '<button type="button" class="btn btn-outline btn-sm" data-status-menu data-id="' + e(c.id) + '">Changer le statut</button>' +
          '<a class="btn btn-ghost btn-sm" href="espace-entreprise-messages.html?to=' + encodeURIComponent(c.nom) + '&poste=' + encodeURIComponent(c.poste) + '&offre=' + encodeURIComponent(c.offre) + '" data-msg>Message</a>' +
        "</div>" +
      "</article>";
  }

  function listBody(cards, statusKey) {
    if (!cards.length) {
      return '<p class="pipeline-empty">Déposez un candidat ici.</p>';
    }
    return cards.map(function (c) { return cardHtml(c, statusKey, true); }).join("");
  }

  function renderPipeline() {
    var board = document.getElementById("pipeline-board");
    if (!board) { return; }
    var e = SS.escapeHtml;
    var store = pipelineStore();
    var refus = SS.store.get(REFUS_KEY, {});
    var by = groupByStatus(store, refus);

    board.innerHTML = COLUMNS.map(function (col) {
      if (col.zones) {
        var zones = col.zones.map(function (z) {
          var cards = by[z.key] || [];
          return '<div class="pipeline-zone" data-zone="' + z.key + '">' +
              '<div class="pipeline-zone__head"><span class="zone-dot zone-dot--' + z.key + '" aria-hidden="true"></span>' +
                e(z.label) + '<span class="pipeline-col__count" data-count>' + cards.length + "</span></div>" +
              '<div class="pipeline-col__list pipeline-zone__list" data-drop="' + z.key + '">' + listBody(cards, z.key) + "</div>" +
            "</div>";
        }).join("");
        return '<div class="pipeline-col pipeline-col--decision" data-col="' + col.key + '">' +
            '<div class="pipeline-col__head">' + e(col.label) + "</div>" +
            '<div class="pipeline-col__zones">' + zones + "</div>" +
          "</div>";
      }
      var cards = by[col.key] || [];
      return '<div class="pipeline-col" data-col="' + col.key + '">' +
          '<div class="pipeline-col__head">' + e(col.label) +
            '<span class="pipeline-col__count" data-count>' + cards.length + "</span></div>" +
          '<div class="pipeline-col__list" data-drop="' + col.key + '">' + listBody(cards, col.key) + "</div>" +
        "</div>";
    }).join("");

    wireCommon(board);
    wireDrag(board);
  }

  function renderMobile() {
    var host = document.getElementById("pipeline-mobile");
    if (!host) { return; }
    var store = pipelineStore();
    var refus = SS.store.get(REFUS_KEY, {});
    var by = groupByStatus(store, refus);
    var html = STATUS_ORDER.map(function (k) {
      return by[k].map(function (c) { return cardHtml(c, k, false); }).join("");
    }).join("");
    host.innerHTML = html || '<div class="empty-state"><p>Aucun candidat.</p></div>';
    wireCommon(host);
  }

  function renderList() {
    var host = document.getElementById("pipeline-list");
    if (!host) { return; }
    var e = SS.escapeHtml;
    var store = pipelineStore();
    var refus = SS.store.get(REFUS_KEY, {});

    var rows = CANDS.map(function (c) {
      var st = effectiveStatus(c, store, refus);
      var rel = e(SS.relativeDate(EMP.dateFromToday(-c.jours)));
      return '<tr class="pipeline-list__row" data-cand="' + e(c.id) + '">' +
          '<td data-label="Candidat"><strong>' + e(c.nom) + "</strong>" +
            '<span class="pl-sub">' + e(c.poste) + "</span></td>" +
          '<td data-label="Offre">' + e(c.offre) + "</td>" +
          '<td data-label="Reçue">' + rel + "</td>" +
          '<td data-label="Statut"><span class="status-badge ' + statusBadgeClass(st) + '">' + e(STATUS[st].label) + "</span></td>" +
          '<td data-label="Dernière activité">' + rel + "</td>" +
          '<td data-label="Actions" class="pl-actions">' +
            '<button type="button" class="btn btn-outline btn-sm" data-status-menu data-id="' + e(c.id) + '">Changer le statut</button>' +
            '<button type="button" class="btn btn-ghost btn-sm" data-detail data-id="' + e(c.id) + '">Détail</button>' +
          "</td></tr>";
    }).join("");

    host.innerHTML = '<div class="pl-table-wrap"><table class="pl-table">' +
      "<thead><tr><th>Candidat</th><th>Offre</th><th>Reçue</th><th>Statut</th><th>Dernière activité</th><th>Actions</th></tr></thead>" +
      "<tbody>" + rows + "</tbody></table></div>";
    wireCommon(host);
  }

  /* ============================================================
     Câblage : clic / clavier (commun aux 3 vues) + glisser-déposer
     ============================================================ */
  function wireCommon(container) {
    container.addEventListener("click", function (ev) {
      var menuBtn = ev.target.closest("[data-status-menu]");
      if (menuBtn) { ev.stopPropagation(); openStatusSheet(byId[menuBtn.getAttribute("data-id")], menuBtn); return; }
      var detBtn = ev.target.closest("[data-detail]");
      if (detBtn) { ev.stopPropagation(); openDetail(byId[detBtn.getAttribute("data-id")], detBtn); return; }
      if (ev.target.closest("[data-msg]")) { return; }
      var card = ev.target.closest(".pipeline-card");
      if (card) {
        if (Date.now() - lastDragEnd < 250) { return; } /* clic parasite après un drag */
        openDetail(byId[card.getAttribute("data-cand")], card);
      }
    });

    container.addEventListener("keydown", function (ev) {
      if (ev.key !== "Enter" && ev.key !== " ") { return; }
      var card = ev.target.closest ? ev.target.closest(".pipeline-card") : null;
      if (card && ev.target === card) {
        ev.preventDefault();
        openDetail(byId[card.getAttribute("data-cand")], card);
      }
    });
  }

  function wireDrag(board) {
    board.querySelectorAll(".pipeline-card").forEach(function (card) {
      card.addEventListener("dragstart", function (ev) {
        ev.dataTransfer.setData("text/plain", card.getAttribute("data-cand"));
        ev.dataTransfer.effectAllowed = "move";
        card.classList.add("is-dragging");
        document.body.classList.add("kanban-dragging");
      });
      card.addEventListener("dragend", function () {
        card.classList.remove("is-dragging");
        document.body.classList.remove("kanban-dragging");
        clearDropTargets();
        lastDragEnd = Date.now();
      });
    });

    board.querySelectorAll("[data-drop]").forEach(function (list) {
      list.addEventListener("dragover", function (ev) {
        ev.preventDefault();
        ev.dataTransfer.dropEffect = "move";
        list.classList.add("is-drop-target");
      });
      list.addEventListener("dragleave", function (ev) {
        if (!list.contains(ev.relatedTarget)) { list.classList.remove("is-drop-target"); }
      });
      list.addEventListener("drop", function (ev) { onDrop(list, ev); });
    });
  }

  function clearDropTargets() {
    document.querySelectorAll(".is-drop-target").forEach(function (el) {
      el.classList.remove("is-drop-target");
    });
  }

  function getDragAfter(list, y) {
    var els = Array.prototype.slice.call(list.querySelectorAll(".pipeline-card:not(.is-dragging)"));
    var closest = { offset: -Infinity, el: null };
    els.forEach(function (el) {
      var box = el.getBoundingClientRect();
      var off = y - box.top - box.height / 2;
      if (off < 0 && off > closest.offset) { closest = { offset: off, el: el }; }
    });
    return closest.el;
  }

  function onDrop(list, ev) {
    ev.preventDefault();
    clearDropTargets();
    var id = ev.dataTransfer.getData("text/plain");
    if (!id) { return; }
    var board = document.getElementById("pipeline-board");
    var card = board.querySelector('.pipeline-card[data-cand="' + id + '"]');
    var cand = byId[id];
    if (!card || !cand) { return; }

    var target = list.getAttribute("data-drop");
    var store = pipelineStore();
    var refus = SS.store.get(REFUS_KEY, {});
    var prev = effectiveStatus(cand, store, refus);

    /* Déplacement visuel immédiat (réordonnable). */
    var after = getDragAfter(list, ev.clientY);
    var emptyMsg = list.querySelector(".pipeline-empty");
    if (emptyMsg) { emptyMsg.remove(); }
    if (after == null) { list.appendChild(card); } else { list.insertBefore(card, after); }
    var ids = Array.prototype.slice.call(list.querySelectorAll(".pipeline-card")).map(function (el) {
      return el.getAttribute("data-cand");
    });

    applyStatusChange(cand, target, prev, ids);
  }

  /* ============================================================
     Dispatcher des changements de statut + workflows métier
     ids : ordre visuel cible (drag). null pour le menu/bottom-sheet.
     ============================================================ */
  function applyStatusChange(cand, target, prev, ids) {
    function commit() {
      if (ids) { saveOrder(target, ids); }
      setPipelineStatus(cand.id, target);
      if (target !== "refuse") { clearRefus(cand.nom); }
      renderAll();
      focusCard(cand.id);
    }
    function cancel() { renderAll(); focusCard(cand.id); }

    if (target === prev) { /* simple réordonnancement dans la même colonne */
      if (ids) { saveOrder(target, ids); }
      renderAll();
      focusCard(cand.id);
      return;
    }

    if (target === "entretien") {
      openEntretien(cand, function () { commit(); moveToast(cand, target, prev); }, cancel);
    } else if (target === "retenu") {
      openRetenu(cand, function () { commit(); moveToast(cand, target, prev); }, cancel);
    } else if (target === "refuse") {
      if (openRefusModal) { openRefusModal(cand, function () { commit(); }, cancel); }
      else { cancel(); }
    } else {
      commit();
      moveToast(cand, target, prev);
    }
  }

  function focusCard(id) {
    var els = document.querySelectorAll('.pipeline-card[data-cand="' + id + '"]');
    for (var i = 0; i < els.length; i++) {
      if (els[i].offsetParent !== null) { els[i].focus(); return; }
    }
  }

  /* ============================================================
     Toast de déplacement avec bouton [Annuler]
     ============================================================ */
  function moveToast(cand, target, prev) {
    undoToast(cand.nom + " est passé(e) en " + STATUS[target].label + ".", function () {
      setPipelineStatus(cand.id, prev);
      if (prev !== "refuse") { clearRefus(cand.nom); }
      renderAll();
      focusCard(cand.id);
      SS.toast("Déplacement annulé.");
    });
  }

  function undoToast(message, onUndo) {
    var el = document.getElementById("kanban-toast");
    if (!el) {
      el = document.createElement("div");
      el.id = "kanban-toast";
      el.className = "kanban-toast";
      el.setAttribute("role", "status");
      document.body.appendChild(el);
    }
    el.innerHTML = '<span class="kanban-toast__msg"></span>' +
      '<button type="button" class="kanban-toast__undo">Annuler</button>';
    el.querySelector(".kanban-toast__msg").textContent = message;
    el.classList.add("is-visible");
    clearTimeout(toastTimer);

    function hide() { el.classList.remove("is-visible"); }
    el.querySelector(".kanban-toast__undo").onclick = function () {
      clearTimeout(toastTimer);
      hide();
      if (onUndo) { onUndo(); }
    };
    toastTimer = setTimeout(hide, 6000);
  }

  /* ============================================================
     Fabrique de modale accessible (focus piégé, Échap / overlay)
     onClose(cancelled) : cancelled = fermeture sans validation.
     ============================================================ */
  function createModal(overlay) {
    var dialog = overlay.querySelector(".modal");
    var returnTo = null, closeCb = null;

    function focusables() {
      var sel = 'button:not([disabled]), [href], input:not([disabled]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';
      return Array.prototype.slice.call(dialog.querySelectorAll(sel)).filter(function (el) {
        return el.offsetParent !== null;
      });
    }

    function onKey(ev) {
      if (ev.key === "Escape") { ev.preventDefault(); close(true); return; }
      if (ev.key !== "Tab") { return; }
      var f = focusables();
      if (!f.length) { return; }
      var first = f[0], last = f[f.length - 1];
      if (ev.shiftKey && document.activeElement === first) { ev.preventDefault(); last.focus(); }
      else if (!ev.shiftKey && document.activeElement === last) { ev.preventDefault(); first.focus(); }
    }

    function open(opts) {
      opts = opts || {};
      returnTo = opts.returnTo || document.activeElement;
      closeCb = opts.onClose || null;
      overlay.hidden = false;
      document.addEventListener("keydown", onKey);
      var f = focusables();
      if (opts.focus && opts.focus.focus) { opts.focus.focus(); }
      else if (f.length) { f[0].focus(); }
    }

    function close(cancelled) {
      if (overlay.hidden) { return; }
      overlay.hidden = true;
      document.removeEventListener("keydown", onKey);
      var rt = returnTo;
      if (rt && rt.focus && document.body.contains(rt) && rt.offsetParent !== null) { rt.focus(); }
      var cb = closeCb;
      closeCb = null;
      if (cb) { cb(!!cancelled); }
    }

    overlay.addEventListener("click", function (ev) { if (ev.target === overlay) { close(true); } });
    overlay.querySelectorAll("[data-close]").forEach(function (b) {
      b.addEventListener("click", function () { close(true); });
    });

    return { open: open, close: close, dialog: dialog };
  }

  /* ============================================================
     Modale « Proposer un entretien »
     ============================================================ */
  function initEntretienModal() {
    var overlay = document.getElementById("entretien-modal");
    if (!overlay) { return; }
    entretienModal = createModal(overlay);
    var confirm = document.getElementById("ent-confirm");
    if (confirm) {
      confirm.addEventListener("click", function () {
        var d = document.getElementById("ent-date");
        if (d && !d.value) { d.focus(); return; }
        entretienModal.close(false);
      });
    }
  }

  function openEntretien(cand, onConfirm, onCancel) {
    if (!entretienModal) { onCancel(); return; }
    var t = document.getElementById("entretien-modal-title");
    if (t) { t.textContent = "Proposer un entretien à " + cand.nom; }
    var d = document.getElementById("ent-date");
    if (d) { d.value = EMP.dateFromToday(1); }
    entretienModal.open({ onClose: function (cancelled) { cancelled ? onCancel() : onConfirm(); } });
  }

  /* ============================================================
     Modale de confirmation « Retenu »
     ============================================================ */
  function initRetenuModal() {
    var overlay = document.getElementById("retenu-modal");
    if (!overlay) { return; }
    retenuModal = createModal(overlay);
    var confirm = document.getElementById("retenu-confirm");
    if (confirm) { confirm.addEventListener("click", function () { retenuModal.close(false); }); }
  }

  function openRetenu(cand, onConfirm, onCancel) {
    if (!retenuModal) { onCancel(); return; }
    var txt = document.getElementById("retenu-modal-text");
    if (txt) { txt.textContent = "Confirmer que « " + cand.nom + " » est retenu(e) pour l'offre : " + cand.offre + " ?"; }
    retenuModal.open({ onClose: function (cancelled) { cancelled ? onCancel() : onConfirm(); } });
  }

  /* ============================================================
     Modale « Détail candidat » (profil, CV, notes privées, historique)
     ============================================================ */
  function initDetailModal() {
    var overlay = document.getElementById("cand-modal");
    if (overlay) { detailModal = createModal(overlay); }
  }

  function openDetail(cand, trigger) {
    if (!cand || !detailModal) { return; }
    var e = SS.escapeHtml;
    var store = pipelineStore();
    var refus = SS.store.get(REFUS_KEY, {});
    var st = effectiveStatus(cand, store, refus);
    var notes = getNotes()[cand.id] || "";

    var title = document.getElementById("cand-modal-title");
    if (title) { title.textContent = cand.nom; }

    var skills = cand.skills.map(function (s) { return '<span class="skill-tag">' + e(s) + "</span>"; }).join("");
    var sf = (cand.savoirFaire || []).map(function (s) { return '<span class="skill-tag skill-tag--sf">' + e(s) + "</span>"; }).join("");
    if (!sf) { sf = '<span class="text-muted">—</span>'; }
    var histo = (cand.historique || []).map(function (h) { return "<li>" + e(h) + "</li>"; }).join("");
    if (!histo) { histo = "<li>Candidature reçue " + e(SS.relativeDate(EMP.dateFromToday(-cand.jours))) + "</li>"; }

    var body = document.getElementById("cand-modal-body");
    body.innerHTML =
      '<div class="cand-detail">' +
        '<p class="cand-detail__status"><span class="status-badge ' + statusBadgeClass(st) + '">' + e(STATUS[st].label) + "</span></p>" +
        '<dl class="cand-detail__grid">' +
          "<div><dt>Métier</dt><dd>" + e(cand.poste) + "</dd></div>" +
          "<div><dt>Ville</dt><dd>" + e(cand.ville) + "</dd></div>" +
          "<div><dt>Expérience</dt><dd>" + cand.exp + " ans</dd></div>" +
          "<div><dt>Disponibilité</dt><dd>" + e(cand.dispo || "—") + "</dd></div>" +
          '<div class="cand-detail__wide"><dt>Offre concernée</dt><dd>' + e(cand.offre) + "</dd></div>" +
          '<div class="cand-detail__wide"><dt>CV</dt><dd><button type="button" class="link-more" data-toast="Ouverture du CV (démonstration).">' + e(cand.cv || "CV.pdf") + "</button></dd></div>" +
        "</dl>" +
        '<section class="cand-detail__sec"><h3>Expérience</h3><p>' + e(cand.experience || (cand.exp + " ans d'expérience en tant que " + cand.poste + ".")) + "</p></section>" +
        '<section class="cand-detail__sec"><h3>Compétences</h3><div class="pipeline-card__skills">' + skills + "</div></section>" +
        '<section class="cand-detail__sec"><h3>Savoir-faire Postelio</h3><div class="pipeline-card__skills">' + sf + "</div></section>" +
        '<section class="cand-detail__sec"><h3>Notes recruteur <span class="cand-detail__priv">Privé — jamais visible du candidat</span></h3>' +
          '<textarea id="cand-notes" rows="3" placeholder="Vos notes internes sur ce candidat…">' + e(notes) + "</textarea></section>" +
        '<section class="cand-detail__sec"><h3>Historique de candidature</h3><ul class="cand-detail__histo">' + histo + "</ul></section>" +
      "</div>";

    var ta = document.getElementById("cand-notes");
    if (ta) { ta.addEventListener("input", function () { setNote(cand.id, ta.value); }); }

    detailModal.open({ returnTo: trigger || document.activeElement });
  }

  /* ============================================================
     Bottom-sheet / menu « Changer le statut » (clavier + mobile)
     ============================================================ */
  function initStatusSheet() {
    var overlay = document.getElementById("status-sheet");
    if (overlay) { sheetModal = createModal(overlay); }
  }

  function openStatusSheet(cand, trigger) {
    if (!cand || !sheetModal) { return; }
    var e = SS.escapeHtml;
    var store = pipelineStore();
    var refus = SS.store.get(REFUS_KEY, {});
    var cur = effectiveStatus(cand, store, refus);

    var title = document.getElementById("status-sheet-title");
    if (title) { title.textContent = "Changer le statut — " + cand.nom; }

    var body = document.getElementById("status-sheet-body");
    body.innerHTML = '<ul class="status-sheet__list">' + STATUS_ORDER.map(function (k) {
      var isCur = k === cur;
      return "<li><button type=\"button\" class=\"status-choice" + (isCur ? " is-current" : "") + "\"" +
        " data-target=\"" + k + "\"" + (isCur ? ' aria-current="true"' : "") + ">" +
        '<span class="status-badge ' + statusBadgeClass(k) + '">' + e(STATUS[k].label) + "</span>" +
        (isCur ? '<span class="status-choice__cur">Étape actuelle</span>' : "") +
        "</button></li>";
    }).join("") + "</ul>";

    body.querySelectorAll(".status-choice").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var target = btn.getAttribute("data-target");
        sheetModal.close();
        if (target === cur) { return; }
        applyStatusChange(cand, target, cur, null);
      });
    });

    sheetModal.open({ returnTo: trigger || document.activeElement });
  }

  /* ============================================================
     Filtres + bascule de vue
     ============================================================ */
  function initToolbar() {
    var sel = document.getElementById("flt-offre");
    if (sel) {
      var offers = [];
      CANDS.forEach(function (c) { if (offers.indexOf(c.offre) < 0) { offers.push(c.offre); } });
      offers.sort();
      offers.forEach(function (o) {
        var op = document.createElement("option");
        op.value = o; op.textContent = o;
        sel.appendChild(op);
      });
      sel.addEventListener("change", function (ev) { state.filters.offre = ev.target.value; applyFilters(); });
    }
    var search = document.getElementById("flt-search");
    if (search) { search.addEventListener("input", function (ev) { state.filters.q = ev.target.value.trim(); applyFilters(); }); }
    var fltDate = document.getElementById("flt-date");
    if (fltDate) { fltDate.addEventListener("change", function (ev) { state.filters.date = ev.target.value; applyFilters(); }); }
    var fltExp = document.getElementById("flt-exp");
    if (fltExp) { fltExp.addEventListener("change", function (ev) { state.filters.exp = ev.target.value; applyFilters(); }); }

    document.querySelectorAll(".view-btn").forEach(function (b) {
      b.addEventListener("click", function () { setView(b.getAttribute("data-view")); });
    });
  }

  function setView(v) {
    state.view = v;
    document.querySelectorAll(".view-btn").forEach(function (b) {
      var on = b.getAttribute("data-view") === v;
      b.classList.toggle("is-active", on);
      b.setAttribute("aria-pressed", on ? "true" : "false");
    });
    updateView();
  }

  function updateView() {
    var wrap = document.getElementById("pipeline-wrap");
    var list = document.getElementById("pipeline-list");
    if (!wrap || !list) { return; }
    if (state.view === "liste") { wrap.hidden = true; list.hidden = false; }
    else { wrap.hidden = false; list.hidden = true; }
  }

  function candMatches(c) {
    var f = state.filters;
    if (f.q) {
      var q = f.q.toLowerCase();
      var hay = (c.nom + " " + c.poste + " " + c.ville + " " + c.skills.join(" ")).toLowerCase();
      if (hay.indexOf(q) < 0) { return false; }
    }
    if (f.offre && c.offre !== f.offre) { return false; }
    if (f.exp) {
      if (f.exp === "0-2" && c.exp > 2) { return false; }
      if (f.exp === "3-5" && (c.exp < 3 || c.exp > 5)) { return false; }
      if (f.exp === "6" && c.exp < 6) { return false; }
    }
    if (f.date) {
      var max = parseInt(f.date, 10);
      if (!isNaN(max) && c.jours > max) { return false; }
    }
    return true;
  }

  function applyFilters() {
    var nodes = document.querySelectorAll("#candidatures [data-cand]");
    nodes.forEach(function (n) {
      var c = byId[n.getAttribute("data-cand")];
      var ok = c ? candMatches(c) : true;
      n.classList.toggle("is-hidden", !ok);
    });

    document.querySelectorAll("#pipeline-board [data-drop]").forEach(function (list) {
      var vis = list.querySelectorAll(".pipeline-card:not(.is-hidden)").length;
      var head = list.parentElement.querySelector("[data-count]");
      if (head) { head.textContent = vis; }
      var old = list.querySelector("[data-empty-filter]");
      if (old) { old.remove(); }
      var total = list.querySelectorAll(".pipeline-card").length;
      if (total > 0 && vis === 0) {
        var p = document.createElement("p");
        p.className = "pipeline-empty";
        p.setAttribute("data-empty-filter", "");
        p.textContent = "Aucun résultat.";
        list.appendChild(p);
      }
    });

    var mob = document.getElementById("pipeline-mobile");
    if (mob) {
      var mvis = mob.querySelectorAll(".pipeline-card:not(.is-hidden)").length;
      var mEmpty = mob.querySelector("[data-mobile-empty]");
      if (mvis === 0 && mob.querySelectorAll(".pipeline-card").length > 0) {
        if (!mEmpty) {
          mEmpty = document.createElement("div");
          mEmpty.className = "empty-state";
          mEmpty.setAttribute("data-mobile-empty", "");
          mEmpty.innerHTML = "<p>Aucun candidat ne correspond à votre recherche.</p>";
          mob.appendChild(mEmpty);
        }
      } else if (mEmpty) {
        mEmpty.remove();
      }
    }
  }

  /* ============================================================
     Modale de refus (accessible, focus piégé) — RÉUTILISÉE.
     Motif interne jamais transmis ; message courtois pré-rempli et éditable.
     Adaptée pour être pilotée par callbacks (onConfirm / onCancel), de sorte
     qu'un refus non confirmé replace la carte dans sa colonne précédente.
     ============================================================ */
  function initRefusModal() {
    var overlay = document.getElementById("refus-modal");
    if (!overlay) { return; }
    var titleEl = document.getElementById("refus-modal-title");
    var form = document.getElementById("refus-form");
    var messageEl = document.getElementById("refus-message");
    var courtoisEl = document.getElementById("refus-courtois");
    var confirmBtn = document.getElementById("refus-confirm");
    if (!titleEl || !form || !messageEl || !courtoisEl || !confirmBtn) { return; }

    refusModal = createModal(overlay);
    var pending = { nom: "", offre: "", confirmed: false, onConfirm: null, onCancel: null };

    function selectedMotif() {
      var r = form.querySelector('input[name="refus-motif"]:checked');
      return r ? r.value : "";
    }
    function fillCourtois() {
      if (!courtoisEl.checked) { return; }
      var m = selectedMotif();
      if (m && COURTOIS[m]) { messageEl.value = COURTOIS[m]; }
    }
    form.querySelectorAll('input[name="refus-motif"]').forEach(function (r) {
      r.addEventListener("change", fillCourtois);
    });
    courtoisEl.addEventListener("change", fillCourtois);

    confirmBtn.addEventListener("click", function () {
      var m = selectedMotif();
      var finalMsg = messageEl.value.trim();
      if (!finalMsg) { finalMsg = COURTOIS[m] || COURTOIS.autre; }
      var today = new Date().toISOString().slice(0, 10);

      var stored = SS.store.get(REFUS_KEY, {});
      stored[pending.nom] = { nom: pending.nom, offre: pending.offre, message: finalMsg, date: today };
      SS.store.set(REFUS_KEY, stored);

      pending.confirmed = true;
      SS.toast("Le candidat a été informé avec un message courtois.");
      refusModal.close(false);
    });

    openRefusModal = function (cand, onConfirm, onCancel) {
      pending = { nom: cand.nom, offre: cand.offre, confirmed: false, onConfirm: onConfirm, onCancel: onCancel };
      titleEl.textContent = "Refuser la candidature de " + cand.nom;
      form.querySelectorAll('input[name="refus-motif"]').forEach(function (r) { r.checked = false; });
      courtoisEl.checked = true;
      messageEl.value = "";
      refusModal.open({ onClose: function () {
        if (pending.confirmed) { if (pending.onConfirm) { pending.onConfirm(); } }
        else { if (pending.onCancel) { pending.onCancel(); } }
      } });
    };
  }
})();

/**
 * Espace recruteur — Candidatures (espace-entreprise-candidatures.html).
 *
 * Pipeline de recrutement (kanban léger, persistant) + modale de refus
 * courtois. Réutilise à l'identique les clés de stockage et la logique
 * existantes :
 *   ss_pipeline_v1 — étape courante de chaque candidat (avancement).
 *   ss_refus_demo  — message courtois final d'un refus (lu côté candidat).
 * Le motif interne de refus n'est JAMAIS transmis : seul un message courtois
 * (prédéfini ou édité) est envoyé au candidat.
 */
(function () {
  "use strict";

  var REFUS_KEY = "ss_refus_demo";
  var PIPELINE_KEY = "ss_pipeline_v1";
  var PIPELINE_SEED_VERSION = 1;

  /* Messages courtois prédéfinis, indexés par motif interne. */
  var COURTOIS = {
    profil: "Bonjour, nous vous remercions pour votre candidature et l'intérêt porté à notre entreprise. Après étude attentive de votre profil, nous avons décidé de ne pas y donner suite pour ce poste. Nous vous souhaitons une pleine réussite dans vos recherches.",
    experience: "Bonjour, merci beaucoup pour votre candidature. Nous avons retenu d'autres profils dont le parcours correspondait davantage aux attentes de ce poste. Nous conservons votre candidature et vous souhaitons une belle continuation.",
    pourvu: "Bonjour, nous vous remercions pour votre candidature. Le poste vient malheureusement d'être pourvu. Nous ne manquerons pas de revenir vers vous si une opportunité similaire se présente. Bonne continuation à vous.",
    dispo: "Bonjour, merci pour votre candidature et pour le temps consacré à notre échange. Les disponibilités ne correspondent pas au besoin actuel de l'équipe. Nous vous souhaitons pleine réussite dans la suite de vos démarches.",
    autre: "Bonjour, nous vous remercions sincèrement pour votre candidature. Nous ne sommes pas en mesure d'y donner une suite favorable pour le moment. Nous vous souhaitons une excellente continuation."
  };

  var openRefusModal = null;
  var pipelineObservers = [];

  var PIPELINE_COLUMNS = [
    { key: "nouveau", label: "Nouveau" },
    { key: "a-examiner", label: "À examiner" },
    { key: "preselection", label: "Présélectionné" },
    { key: "entretien", label: "Entretien" },
    { key: "retenu", label: "Retenu" },
    { key: "refuse", label: "Refusé" }
  ];

  document.addEventListener("DOMContentLoaded", function () {
    if (!window.EMP || !EMP.ready) { return; }
    if (!document.getElementById("pipeline-board")) { return; }
    initRefusModal();
    renderPipeline();
  });

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

  function effectiveStatus(cand, store, refus) {
    if (refus[cand.nom]) { return "refuse"; }
    return store.status[cand.id] || cand.statut;
  }

  function renderPipeline() {
    var board = document.getElementById("pipeline-board");
    if (!board) { return; }
    var e = SS.escapeHtml;

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
                  '<a class="btn btn-ghost btn-sm" href="espace-entreprise-messages.html">Message</a>';
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
        '<p class="pipeline-card__date">Candidature reçue ' + e(SS.relativeDate(EMP.dateFromToday(-c.jours))) + '</p>' +
        refusedNote +
        '<div class="row-actions">' + actions + '</div>' +
      '</article>';
  }

  function wirePipeline(board) {
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

  /* ---- Modale de refus de candidature (accessible, focus piégé) ----
     INCHANGÉE ET VALIDÉE : motif interne jamais transmis, message courtois
     pré-rempli et éditable, toast, statut « non retenue ». */
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
      var t = state.trigger;
      if (t && !t.disabled && t.offsetParent !== null) {
        t.focus();
      } else if (state.card) {
        state.card.setAttribute("tabindex", "-1");
        state.card.focus();
      }
    }

    overlay.addEventListener("click", function (ev) {
      if (ev.target === overlay) { close(); }
    });
    overlay.querySelectorAll("[data-refus-close]").forEach(function (b) {
      b.addEventListener("click", close);
    });

    confirmBtn.addEventListener("click", function () {
      var m = selectedMotif();
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

      var stored = SS.store.get(REFUS_KEY, {});
      stored[state.nom] = { nom: state.nom, offre: state.offre, message: finalMsg, date: today };
      SS.store.set(REFUS_KEY, stored);

      close();
      SS.toast("Le candidat a été informé avec un message courtois.");
    });

    openRefusModal = open;
  }
})();

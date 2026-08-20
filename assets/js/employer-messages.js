/**
 * Espace recruteur — Messages (espace-entreprise-messages.html).
 *
 * Messagerie complète (démonstration, sans backend — §31) :
 *   §10 recherche instantanée ; §11 filtres (Tous · Non lus · Entretiens ·
 *   À répondre · Archivés) ; §12 tri (Plus récent · Non lus d'abord) ;
 *   §13 conversation active très visible ; §14 contexte de candidature en tête
 *   de fil + badges non lus ; §15 « Nouveau message » (sélecteur candidat) ;
 *   §16 modèles de messages ; §17 variables automatiques ({{prenom}}…) ;
 *   §18 bloc « entretien lié » + enregistrement de la proposition.
 *
 * Clés localStorage :
 *   ss_msg_state     — état par conversation { archived, read } (nouvelle clé).
 *   ss_interviews_v1 — propositions d'entretien (clé partagée, lecture/écriture).
 *
 * Helpers partagés : window.SS (escapeHtml, toast, store, formatDate, param,
 * auth) et window.EMP (dateFromToday).
 */
(function () {
  "use strict";

  var MSG_STATE_KEY = "ss_msg_state";
  var INTERVIEWS_KEY = "ss_interviews_v1";

  var conversations = null;
  var currentConvId = null;
  var searchQuery = "";
  var activeFilter = "tous";
  var sortMode = "recent";

  /* §11 — filtres disponibles (chips). */
  var FILTERS = [
    { id: "tous",       label: "Tous" },
    { id: "non-lus",    label: "Non lus" },
    { id: "entretiens", label: "Entretiens" },
    { id: "a-repondre", label: "À répondre" },
    { id: "archives",   label: "Archivés" }
  ];

  /* §15 — candidats sélectionnables (mêmes noms que le pipeline). */
  var SEED_CANDIDATES = [
    { nom: "Camille Reynaud",  poste: "Comptable",                  offre: "Collaborateur comptable — CDI" },
    { nom: "Karim Haddad",     poste: "Développeur web",            offre: "Développeur web full-stack — CDI" },
    { nom: "Sophie Lemaire",   poste: "Secrétaire administrative",  offre: "Secrétaire administrative — CDD" },
    { nom: "Léa Dubois",       poste: "Conductrice de travaux",     offre: "Conducteur de travaux — CDI" },
    { nom: "Malik Benhaddou",  poste: "Gestionnaire de paie",       offre: "Gestionnaire de paie — CDI" },
    { nom: "Awa Diallo",       poste: "Aide-soignante",             offre: "Aide-soignant(e) — CDI" },
    { nom: "Julie Martin",     poste: "Assistante commerciale",     offre: "Assistant(e) commercial(e) — CDI" }
  ];

  /* §16/§17 — modèles de messages, éditables avant envoi, à variables {{…}}. */
  var TEMPLATES = [
    { id: "dispo", label: "Demande de disponibilité",
      texte: "Bonjour {{prenom}},\n\nMerci pour votre candidature au poste de {{poste}}. Pourriez-vous m'indiquer vos disponibilités pour un premier échange dans les prochains jours ?\n\nBien cordialement,\n{{recruteur}} — {{entreprise}}" },
    { id: "entretien", label: "Proposition d'entretien", interview: true,
      texte: "Bonjour {{prenom}},\n\nVotre candidature au poste de {{poste}} a retenu notre attention. Nous serions ravis de vous rencontrer pour un entretien le {{date_entretien}} à {{heure_entretien}}.\n\nMerci de nous confirmer votre disponibilité.\n\nBien cordialement,\n{{recruteur}} — {{entreprise}}" },
    { id: "relance", label: "Relance",
      texte: "Bonjour {{prenom}},\n\nJe me permets de revenir vers vous concernant votre candidature au poste de {{poste}}. Seriez-vous toujours intéressé(e) ? N'hésitez pas à me recontacter.\n\nBien cordialement,\n{{recruteur}} — {{entreprise}}" },
    { id: "infos", label: "Demande d'informations",
      texte: "Bonjour {{prenom}},\n\nAfin de compléter votre dossier pour le poste de {{poste}}, pourriez-vous me transmettre votre CV à jour ainsi que vos éventuelles références ?\n\nMerci d'avance,\n{{recruteur}} — {{entreprise}}" },
    { id: "retenue", label: "Candidature retenue",
      texte: "Bonjour {{prenom}},\n\nNous avons le plaisir de vous informer que votre candidature au poste de {{poste}} a été retenue. Nous reviendrons vers vous très rapidement pour la suite du processus.\n\nFélicitations et à très bientôt,\n{{recruteur}} — {{entreprise}}" },
    { id: "refus", label: "Candidature non retenue",
      texte: "Bonjour {{prenom}},\n\nNous vous remercions de l'intérêt porté au poste de {{poste}} au sein de {{entreprise}}. Après une étude attentive, nous ne donnerons pas suite à votre candidature. Nous vous souhaitons une pleine réussite dans vos recherches.\n\nBien cordialement,\n{{recruteur}} — {{entreprise}}" },
    { id: "libre", label: "Message libre", texte: "" }
  ];

  document.addEventListener("DOMContentLoaded", function () {
    if (!window.EMP || !EMP.ready) { return; }
    if (!document.getElementById("messaging")) { return; }
    initMessages();
  });

  /* ============================================================
     Utilitaires
     ============================================================ */
  function future(offset) {
    if (window.EMP && EMP.dateFromToday) { return EMP.dateFromToday(offset); }
    var d = new Date(); d.setDate(d.getDate() + offset); return d.toISOString().slice(0, 10);
  }
  function isoDay(offset) { return future(offset); }

  function companyName() {
    var s = (SS.auth && SS.auth.get()) || {};
    return s.company || "Fiduciaire Bellecour";
  }
  function recruiterName() {
    var n = SS.auth && SS.auth.displayName ? SS.auth.displayName() : "";
    return n || "Claire Martin";
  }

  function splitName(nom) {
    var parts = String(nom || "").trim().split(/\s+/);
    return { prenom: parts[0] || "", nom: parts.slice(1).join(" ") };
  }
  function convInitials(nom) {
    var parts = String(nom).trim().split(/\s+/);
    return ((parts[0] ? parts[0][0] : "") + (parts[1] ? parts[1][0] : "")).toUpperCase();
  }
  function lastMsg(c) { return c.messages[c.messages.length - 1] || null; }
  function lastFromCandidate(c) { var m = lastMsg(c); return !!(m && m.from === "them"); }

  /* Entretien lié : proposition attachée à la conversation, ou entretien du
     même candidat déjà enregistré dans ss_interviews_v1. */
  function linkedInterview(c) {
    if (c.interview) { return c.interview; }
    var list = SS.store.get(INTERVIEWS_KEY, []);
    if (!list || !list.length) { return null; }
    var name = String(c.nom || "").toLowerCase();
    for (var i = 0; i < list.length; i++) {
      if (String(list[i].nom || "").toLowerCase() === name) { return list[i]; }
    }
    return null;
  }
  function hasInterview(c) { return !!linkedInterview(c); }

  /* ---- État persistant (archivage / lecture) ---- */
  function loadState() { return SS.store.get(MSG_STATE_KEY, {}) || {}; }
  function saveState(st) { SS.store.set(MSG_STATE_KEY, st); }
  function applyState() {
    var st = loadState();
    conversations.forEach(function (c) {
      var s = st[c.id];
      if (!s) { return; }
      if (s.read) { c.unread = 0; }
      if (typeof s.archived === "boolean") { c.archived = s.archived; }
    });
  }
  function markRead(c) {
    if (c.unread) { c.unread = 0; }
    var st = loadState();
    st[c.id] = st[c.id] || {};
    st[c.id].read = true;
    saveState(st);
  }
  function setArchived(c, val) {
    c.archived = val;
    var st = loadState();
    st[c.id] = st[c.id] || {};
    st[c.id].archived = val;
    saveState(st);
  }

  /* ============================================================
     Données de démonstration
     ============================================================ */
  function seedConversations() {
    return [
      { id: "m1", nom: "Julie Martin", poste: "Assistante commerciale", offre: "Assistant(e) commercial(e) — CDI",
        statut: "Entretien à planifier", unread: 2,
        interview: { date: isoDay(3), heure: "14:30", format: "Visioconférence" },
        messages: [
          { from: "me",   texte: "Bonjour Julie, votre profil correspond bien au poste. Seriez-vous disponible pour un entretien la semaine prochaine ?", date: isoDay(-3) },
          { from: "them", texte: "Bonjour, merci beaucoup ! Oui, je suis disponible la semaine prochaine.", date: isoDay(-2) },
          { from: "them", texte: "Faut-il prévoir quelque chose de particulier pour l'entretien ?", date: isoDay(-1) }
        ] },
      { id: "m2", nom: "Karim Haddad", poste: "Développeur web", offre: "Développeur web full-stack — CDI",
        statut: "Nouvelle candidature", unread: 1,
        messages: [
          { from: "them", texte: "Bonjour, ma candidature au poste de développeur est-elle toujours à l'étude ?", date: isoDay(-1) }
        ] },
      { id: "m3", nom: "Awa Diallo", poste: "Aide-soignante", offre: "Aide-soignant(e) — CDI",
        statut: "Présélection", unread: 0,
        messages: [
          { from: "me",   texte: "Bonjour Awa, votre profil nous intéresse. Pourrions-nous échanger par téléphone cette semaine ?", date: isoDay(-4) },
          { from: "them", texte: "Bonjour, merci beaucoup. Je suis joignable tous les après-midi. Bien à vous.", date: isoDay(-3) }
        ] },
      { id: "m4", nom: "Camille Reynaud", poste: "Comptable", offre: "Collaborateur comptable — CDI",
        statut: "En cours d'examen", unread: 0,
        messages: [
          { from: "them", texte: "Bonjour, je vous confirme mon intérêt pour le poste de comptable.", date: isoDay(-5) },
          { from: "me",   texte: "Bonjour Camille, merci. Nous étudions votre dossier et revenons vers vous rapidement.", date: isoDay(-4) }
        ] },
      { id: "m5", nom: "Malik Benhaddou", poste: "Gestionnaire de paie", offre: "Gestionnaire de paie — CDI",
        statut: "Candidature clôturée", unread: 0, archived: true,
        messages: [
          { from: "them", texte: "Bonjour, je reste disponible si un poste se libère à l'avenir. Merci.", date: isoDay(-12) },
          { from: "me",   texte: "Merci Malik, nous conservons votre candidature avec attention. Bonne continuation.", date: isoDay(-11) }
        ] }
    ];
  }

  /* ============================================================
     Initialisation
     ============================================================ */
  function initMessages() {
    var wrap = document.getElementById("messaging");
    var listBox = document.getElementById("messaging-list");
    var threadBox = document.getElementById("messaging-thread");
    var panelBox = document.getElementById("messaging-panel");
    if (!wrap || !listBox || !threadBox || !panelBox) { return; }

    conversations = seedConversations();
    applyState();

    /* §15/existant — ouverture directe via ?to=<nom>&poste=&offre= */
    var to = SS.param ? SS.param("to") : null;
    if (to) {
      openOrCreate({ nom: to, poste: SS.param("poste") || "Candidat", offre: SS.param("offre") || "" }, true);
    }

    if (!conversations.length) {
      wrap.innerHTML = '<div class="empty-state"><h3>Aucune conversation pour le moment.</h3>' +
        '<p>Vos échanges avec les candidats apparaîtront ici.</p></div>';
      return;
    }

    if (!currentConvId) { currentConvId = firstVisibleId() || conversations[0].id; }

    renderPanel();
    renderList();
    renderThread();
    wirePanel();

    listBox.addEventListener("click", function (ev) {
      var btn = ev.target.closest("[data-conv]");
      if (!btn) { return; }
      selectConv(btn.getAttribute("data-conv"), true);
    });
  }

  function selectConv(id, focusThread) {
    currentConvId = id;
    var conv = getConv(id);
    if (conv) { markRead(conv); }
    renderList();
    renderThread();
    document.getElementById("messaging").classList.add("is-thread-open"); /* mobile */
    if (focusThread) {
      var t = document.getElementById("messaging-thread").querySelector(".conv-head__name");
      if (t) { t.setAttribute("tabindex", "-1"); t.focus(); }
    }
  }

  function getConv(id) {
    for (var i = 0; i < conversations.length; i++) { if (conversations[i].id === id) { return conversations[i]; } }
    return null;
  }

  /* Crée (ou ouvre) une conversation vers un candidat donné (§15). */
  function openOrCreate(cand, silent) {
    var existing = conversations.filter(function (c) {
      return c.nom.toLowerCase() === String(cand.nom).toLowerCase();
    })[0];
    if (!existing) {
      existing = {
        id: "new-" + String(cand.nom).toLowerCase().replace(/[^a-z0-9]+/g, "-"),
        nom: cand.nom,
        poste: cand.poste || "Candidat",
        offre: cand.offre || "",
        statut: "Nouvelle conversation",
        unread: 0,
        messages: []
      };
      conversations.unshift(existing);
    } else if (existing.archived) {
      setArchived(existing, false); /* réactive une conversation archivée */
    }
    currentConvId = existing.id;
    markRead(existing);
    if (!silent) { selectConv(existing.id, true); }
    return existing;
  }

  /* ============================================================
     §10-12 — recherche / filtres / tri → conversations visibles
     ============================================================ */
  function visibleConversations() {
    var q = searchQuery.trim().toLowerCase();
    var list = conversations.filter(function (c) {
      /* archivage : le filtre « Archivés » les montre, sinon on les masque */
      if (activeFilter === "archives") { if (!c.archived) { return false; } }
      else if (c.archived) { return false; }

      if (activeFilter === "non-lus" && !(c.unread > 0)) { return false; }
      if (activeFilter === "entretiens" && !hasInterview(c)) { return false; }
      if (activeFilter === "a-repondre" && !lastFromCandidate(c)) { return false; }

      if (q) {
        var m = lastMsg(c);
        var hay = (c.nom + " " + c.poste + " " + c.offre + " " + (m ? m.texte : "")).toLowerCase();
        if (hay.indexOf(q) === -1) { return false; }
      }
      return true;
    });

    list.sort(function (a, b) {
      if (sortMode === "non-lus") {
        var ua = a.unread > 0 ? 1 : 0, ub = b.unread > 0 ? 1 : 0;
        if (ua !== ub) { return ub - ua; }
      }
      return dateOf(b) - dateOf(a);
    });
    return list;
  }
  function dateOf(c) { var m = lastMsg(c); return m ? new Date(m.date).getTime() : 0; }
  function firstVisibleId() { var v = visibleConversations(); return v.length ? v[0].id : null; }

  /* ============================================================
     §10-12/§15 — rendu du panneau de tête + câblage
     ============================================================ */
  function renderPanel() {
    var panel = document.getElementById("messaging-panel");
    if (!panel) { return; }
    var e = SS.escapeHtml;

    var chips = FILTERS.map(function (f) {
      var on = f.id === activeFilter;
      return '<button type="button" class="chip" data-filter="' + f.id + '" aria-pressed="' + on + '">' + e(f.label) + "</button>";
    }).join("");

    panel.innerHTML =
      '<button type="button" class="btn btn-accent btn-sm msg-panel__new" id="msg-new-btn" aria-haspopup="true" aria-expanded="false" aria-controls="msg-new-pop">+ Nouveau message</button>' +
      '<div class="msg-newpop" id="msg-new-pop" hidden>' +
        '<label class="visually-hidden" for="msg-new-search">Rechercher un candidat ou une offre</label>' +
        '<input type="search" id="msg-new-search" class="msg-search__input" placeholder="Rechercher un candidat ou une offre…" autocomplete="off">' +
        '<ul class="msg-newpop__list" id="msg-new-list" aria-label="Candidats"></ul>' +
      '</div>' +
      '<div class="msg-search">' +
        '<label class="visually-hidden" for="msg-search">Rechercher un candidat ou une offre</label>' +
        '<input type="search" id="msg-search" class="msg-search__input" placeholder="Rechercher un candidat ou une offre…" autocomplete="off">' +
      '</div>' +
      '<div class="msg-filters" role="group" aria-label="Filtrer les conversations">' + chips + '</div>' +
      '<div class="msg-sort">' +
        '<label class="msg-sort__label" for="msg-sort-select">Trier :</label>' +
        '<select id="msg-sort-select" class="conv-reply__tpl">' +
          '<option value="recent">Plus récent</option>' +
          '<option value="non-lus">Non lus d\'abord</option>' +
        '</select>' +
      '</div>';
  }

  function wirePanel() {
    var panel = document.getElementById("messaging-panel");
    if (!panel) { return; }

    var search = panel.querySelector("#msg-search");
    if (search) {
      search.value = searchQuery;
      search.addEventListener("input", function () {
        searchQuery = search.value || "";
        renderList();
      });
    }

    panel.querySelectorAll("[data-filter]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        activeFilter = btn.getAttribute("data-filter");
        panel.querySelectorAll("[data-filter]").forEach(function (b) {
          b.setAttribute("aria-pressed", b === btn ? "true" : "false");
        });
        renderList();
      });
    });

    var sort = panel.querySelector("#msg-sort-select");
    if (sort) {
      sort.value = sortMode;
      sort.addEventListener("change", function () { sortMode = sort.value; renderList(); });
    }

    /* §15 — popover « Nouveau message » */
    var newBtn = panel.querySelector("#msg-new-btn");
    var pop = panel.querySelector("#msg-new-pop");
    var popSearch = panel.querySelector("#msg-new-search");
    var popList = panel.querySelector("#msg-new-list");

    function renderNewList(q) {
      var e = SS.escapeHtml;
      var needle = (q || "").trim().toLowerCase();
      var items = SEED_CANDIDATES.filter(function (c) {
        if (!needle) { return true; }
        return (c.nom + " " + c.poste + " " + c.offre).toLowerCase().indexOf(needle) !== -1;
      });
      if (!items.length) {
        popList.innerHTML = '<li class="msg-newpop__empty">Aucun candidat trouvé.</li>';
        return;
      }
      popList.innerHTML = items.map(function (c) {
        return '<li><button type="button" class="msg-newpop__item" data-cand="' + e(c.nom) + '">' +
          '<strong>' + e(c.nom) + '</strong><span>' + e(c.poste) + ' · ' + e(c.offre) + '</span></button></li>';
      }).join("");
    }

    function openPop(open) {
      if (!pop || !newBtn) { return; }
      pop.hidden = !open;
      newBtn.setAttribute("aria-expanded", open ? "true" : "false");
      if (open) { renderNewList(""); if (popSearch) { popSearch.value = ""; popSearch.focus(); } }
    }

    if (newBtn) {
      newBtn.addEventListener("click", function (ev) {
        ev.stopPropagation();
        openPop(pop.hidden);
      });
    }
    if (popSearch) {
      popSearch.addEventListener("input", function () { renderNewList(popSearch.value); });
    }
    if (popList) {
      popList.addEventListener("click", function (ev) {
        var btn = ev.target.closest("[data-cand]");
        if (!btn) { return; }
        var name = btn.getAttribute("data-cand");
        var cand = SEED_CANDIDATES.filter(function (c) { return c.nom === name; })[0] || { nom: name };
        openPop(false);
        openOrCreate(cand, false);
      });
    }
    /* Fermer le popover en cliquant à l'extérieur / touche Échap */
    document.addEventListener("click", function (ev) {
      if (!pop || pop.hidden) { return; }
      if (pop.contains(ev.target) || (newBtn && newBtn.contains(ev.target))) { return; }
      openPop(false);
    });
    document.addEventListener("keydown", function (ev) {
      if (ev.key === "Escape" && pop && !pop.hidden) { openPop(false); }
    });
  }

  /* ============================================================
     §13/§14 — rendu de la liste des conversations
     ============================================================ */
  function renderList() {
    var listBox = document.getElementById("messaging-list");
    if (!listBox) { return; }
    var e = SS.escapeHtml;
    var visible = visibleConversations();

    if (!visible.length) {
      listBox.innerHTML = '<p class="msg-empty">' +
        (searchQuery || activeFilter !== "tous"
          ? "Aucune conversation ne correspond à votre recherche ou à ce filtre."
          : "Aucune conversation.") + '</p>';
      return;
    }

    listBox.innerHTML = visible.map(function (c) {
      var last = lastMsg(c);
      var active = c.id === currentConvId;
      var badge = c.unread > 0
        ? '<span class="conv-item__badge" aria-label="' + c.unread + ' message' + (c.unread > 1 ? "s" : "") + ' non lu' + (c.unread > 1 ? "s" : "") + '">' + c.unread + "</span>"
        : "";
      return '<button type="button" class="conv-item' + (active ? " is-active" : "") + (c.unread > 0 ? " is-unread" : "") +
          '" data-conv="' + e(c.id) + '" role="tab" aria-selected="' + active + '">' +
          '<span class="avatar conv-item__avatar" aria-hidden="true">' + e(convInitials(c.nom)) + '</span>' +
          '<span class="conv-item__body">' +
            '<span class="conv-item__head">' +
              '<span class="conv-item__name">' + e(c.nom) + '</span>' + badge +
            '</span>' +
            '<span class="conv-item__meta">' + e(c.poste) + (last ? " · " + e(SS.formatDate(last.date)) : "") + '</span>' +
            '<span class="conv-item__preview">' + e(last ? last.texte : "Nouvelle conversation") + '</span>' +
          '</span>' +
        '</button>';
    }).join("");
  }

  /* ============================================================
     §14/§16/§17/§18 — rendu du fil
     ============================================================ */
  function renderThread() {
    var threadBox = document.getElementById("messaging-thread");
    if (!threadBox) { return; }
    var e = SS.escapeHtml;
    var conv = getConv(currentConvId);

    if (!conv || conv.archived && activeFilter !== "archives" && !isVisible(conv)) {
      /* la conversation courante n'est plus visible : proposer une sélection */
      var v = visibleConversations();
      if (v.length) { conv = v[0]; currentConvId = conv.id; }
    }
    if (!conv) {
      threadBox.innerHTML = '<div class="thread-empty"><p>Sélectionnez une conversation pour afficher les messages.</p></div>';
      return;
    }

    var bubbles = conv.messages.map(function (m) {
      var who = m.from === "me" ? "conv-msg--me" : "conv-msg--them";
      return '<div class="conv-msg ' + who + '">' +
          '<p class="conv-msg__text">' + e(m.texte) + '</p>' +
          '<span class="conv-msg__date">' + e(SS.formatDate(m.date)) + '</span>' +
        '</div>';
    }).join("");

    var iv = linkedInterview(conv);
    var ivBlock = iv
      ? '<div class="conv-interview" role="note">' +
          '<p class="conv-interview__label">── Entretien proposé ──</p>' +
          '<p class="conv-interview__when">' + e(SS.formatDate(iv.date)) + (iv.heure ? " — " + e(iv.heure) : "") + '</p>' +
          (iv.format ? '<p class="conv-interview__format">' + e(iv.format) + '</p>' : "") +
        '</div>'
      : "";

    var tplOptions = '<option value="">Utiliser un modèle de message…</option>' +
      TEMPLATES.map(function (t) { return '<option value="' + t.id + '">' + e(t.label) + "</option>"; }).join("");

    var archiveLabel = conv.archived ? "Désarchiver" : "Archiver";

    threadBox.innerHTML =
      '<div class="conv-head">' +
        '<div class="conv-head__top">' +
          '<button type="button" class="btn btn-ghost btn-sm conv-back" data-conv-back>← Conversations</button>' +
          '<div class="conv-head__id">' +
            '<span class="avatar" aria-hidden="true">' + e(convInitials(conv.nom)) + '</span>' +
            '<div><h3 class="conv-head__name">' + e(conv.nom) + '</h3>' +
              '<span class="text-muted">' + e(conv.poste) + '</span></div>' +
          '</div>' +
        '</div>' +
        '<div class="conv-head__ctx">' +
          '<p class="conv-head__ctx-row"><span class="conv-head__ctx-k">Candidature :</span> ' + e(conv.offre || "—") + '</p>' +
          '<p class="conv-head__ctx-row"><span class="conv-head__ctx-k">Statut :</span> ' + e(conv.statut || "—") + '</p>' +
        '</div>' +
        '<div class="conv-head__actions">' +
          '<a class="btn btn-outline btn-sm" href="espace-entreprise-candidatures.html">Voir la candidature</a>' +
          '<button type="button" class="btn btn-ghost btn-sm" data-propose>Proposer un entretien</button>' +
          '<button type="button" class="btn btn-ghost btn-sm" data-archive>' + archiveLabel + '</button>' +
        '</div>' +
      '</div>' +
      '<div class="conv-body">' + bubbles + ivBlock + '</div>' +
      '<form class="conv-reply" id="conv-reply">' +
        '<div class="conv-reply__tools">' +
          '<label class="visually-hidden" for="conv-reply-tpl">Utiliser un modèle de message</label>' +
          '<select id="conv-reply-tpl" class="conv-reply__tpl" aria-label="Utiliser un modèle de message">' + tplOptions + '</select>' +
        '</div>' +
        '<label class="visually-hidden" for="conv-reply-text">Votre réponse à ' + e(conv.nom) + '</label>' +
        '<textarea id="conv-reply-text" rows="3" placeholder="Écrivez votre réponse…"></textarea>' +
        '<button type="submit" class="btn btn-primary btn-sm conv-reply__send">Envoyer</button>' +
      '</form>';

    wireThread(conv);
  }

  function isVisible(conv) {
    return visibleConversations().some(function (c) { return c.id === conv.id; });
  }

  function wireThread(conv) {
    var threadBox = document.getElementById("messaging-thread");

    var back = threadBox.querySelector("[data-conv-back]");
    if (back) {
      back.addEventListener("click", function () {
        document.getElementById("messaging").classList.remove("is-thread-open");
      });
    }

    /* §16/§17 — modèle → insertion (variables remplacées), éditable ensuite. */
    var tpl = threadBox.querySelector("#conv-reply-tpl");
    var field = threadBox.querySelector("#conv-reply-text");
    if (tpl && field) {
      tpl.addEventListener("change", function () {
        var t = getTemplate(tpl.value);
        tpl.value = "";
        if (!t) { return; }
        var filled = applyVariables(t.texte, conv);
        /* §18 — la proposition d'entretien crée le bloc lié + re-rend le fil ;
           on récupère alors le nouveau textarea avant d'y insérer le texte. */
        if (t.interview) {
          proposeInterview(conv, true);
          filled = applyVariables(t.texte, conv); /* variables date/heure à jour */
          field = document.getElementById("conv-reply-text");
        }
        if (field) { field.value = filled; field.focus(); }
      });
    }

    var propose = threadBox.querySelector("[data-propose]");
    if (propose) {
      propose.addEventListener("click", function () { proposeInterview(conv, false); });
    }

    var archive = threadBox.querySelector("[data-archive]");
    if (archive) {
      archive.addEventListener("click", function () {
        setArchived(conv, !conv.archived);
        SS.toast(conv.archived ? "Conversation archivée." : "Conversation désarchivée.");
        renderList();
        renderThread();
      });
    }

    var form = threadBox.querySelector("#conv-reply");
    if (form) {
      form.addEventListener("submit", function (ev) {
        ev.preventDefault();
        var txt = (field.value || "").trim();
        if (!txt) { return; }
        conv.messages.push({ from: "me", texte: txt, date: new Date().toISOString().slice(0, 10) });
        markRead(conv);
        renderList();
        renderThread();
        SS.toast("Message envoyé (démonstration).");
      });
    }
  }

  function getTemplate(id) {
    for (var i = 0; i < TEMPLATES.length; i++) { if (TEMPLATES[i].id === id) { return TEMPLATES[i]; } }
    return null;
  }

  /* §17 — remplacement des variables automatiques. */
  function applyVariables(text, conv) {
    var name = splitName(conv.nom);
    var iv = linkedInterview(conv) || {};
    var map = {
      prenom: name.prenom,
      nom: name.nom,
      poste: conv.poste || "",
      entreprise: companyName(),
      recruteur: recruiterName(),
      date_entretien: iv.date ? SS.formatDate(iv.date) : "à définir",
      heure_entretien: iv.heure ? iv.heure : "à définir"
    };
    return String(text).replace(/\{\{\s*(\w+)\s*\}\}/g, function (all, key) {
      return Object.prototype.hasOwnProperty.call(map, key) ? map[key] : all;
    });
  }

  /* §18 — enregistre une proposition d'entretien (ss_interviews_v1 + conv),
     affiche le bloc lié et notifie. */
  function proposeInterview(conv, silent) {
    var existing = linkedInterview(conv);
    if (!existing) {
      var iv = { date: isoDay(3), heure: "14:30", format: "Visioconférence" };
      conv.interview = iv;
      persistInterview(conv, iv);
      existing = iv;
    } else {
      conv.interview = { date: existing.date, heure: existing.heure, format: existing.format || "Visioconférence" };
    }
    renderList();
    renderThread();
    SS.toast("Le candidat recevra une notification pour confirmer.");
    return existing;
  }

  /* Écrit la proposition dans ss_interviews_v1 (partagé avec la page Entretiens). */
  function persistInterview(conv, iv) {
    var list = SS.store.get(INTERVIEWS_KEY, []);
    if (!Array.isArray(list)) { list = []; }
    var name = String(conv.nom || "").toLowerCase();
    var found = false;
    for (var i = 0; i < list.length; i++) {
      if (String(list[i].nom || "").toLowerCase() === name) {
        list[i].date = iv.date; list[i].heure = iv.heure; list[i].format = iv.format;
        found = true; break;
      }
    }
    if (!found) {
      list.push({
        id: "ivm-" + name.replace(/[^a-z0-9]+/g, "-"),
        nom: conv.nom, poste: conv.poste || "", offre: conv.offre || "",
        date: iv.date, heure: iv.heure, duree: "45", format: iv.format,
        instructions: "Proposition envoyée depuis la messagerie.",
        statut: "attente"
      });
    }
    SS.store.set(INTERVIEWS_KEY, list);
  }
})();

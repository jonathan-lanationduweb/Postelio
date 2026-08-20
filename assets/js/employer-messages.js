/**
 * Espace recruteur — Messages (espace-entreprise-messages.html).
 * Messagerie simple : liste de conversations + fil, réponse simulée.
 */
(function () {
  "use strict";

  var conversations = null;
  var currentConvId = null;

  /* Réponses types pré-écrites, éditables avant envoi ({prenom} personnalisé). */
  var TEMPLATES = [
    { label: "Proposer un entretien", texte: "Bonjour {prenom},\n\nVotre profil a retenu notre attention. Seriez-vous disponible pour un entretien la semaine prochaine ? Indiquez-moi vos créneaux préférés et le format qui vous convient (visioconférence, téléphone ou dans nos locaux).\n\nBien cordialement,\nClaire Martin — Fiduciaire Bellecour" },
    { label: "Demander des disponibilités", texte: "Bonjour {prenom},\n\nMerci pour votre candidature. Pourriez-vous m'indiquer vos disponibilités pour un premier échange dans les prochains jours ?\n\nBien cordialement,\nClaire Martin — Fiduciaire Bellecour" },
    { label: "Accuser réception", texte: "Bonjour {prenom},\n\nJe vous confirme la bonne réception de votre candidature. Nous l'étudions et revenons vers vous très rapidement.\n\nBien cordialement,\nClaire Martin — Fiduciaire Bellecour" },
    { label: "Demander une pièce", texte: "Bonjour {prenom},\n\nAfin de compléter votre dossier, pourriez-vous me transmettre votre CV à jour (et, le cas échéant, vos références) ?\n\nMerci d'avance,\nClaire Martin — Fiduciaire Bellecour" }
  ];

  document.addEventListener("DOMContentLoaded", function () {
    if (!window.EMP || !EMP.ready) { return; }
    if (!document.getElementById("messaging")) { return; }
    initMessages();
  });

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

    /* Démarrer une conversation vers un candidat précis via ?to=<nom>
       (bouton « Message » des candidatures). Si aucune conversation n'existe
       pour ce candidat, on en crée une vide et on l'ouvre. */
    var to = SS.param ? SS.param("to") : null;
    if (to) {
      var existing = conversations.filter(function (c) {
        return c.nom.toLowerCase() === to.toLowerCase();
      })[0];
      if (!existing) {
        existing = {
          id: "new-" + to.toLowerCase().replace(/[^a-z0-9]+/g, "-"),
          nom: to,
          poste: SS.param("poste") || "Candidat",
          offre: SS.param("offre") || "",
          messages: []
        };
        conversations.unshift(existing);
      }
      currentConvId = existing.id;
    }

    if (!conversations.length) {
      wrap.innerHTML = '<div class="empty-state"><h3>Aucune conversation pour le moment.</h3>' +
        '<p>Vos échanges avec les candidats apparaîtront ici.</p></div>';
      return;
    }

    if (!currentConvId) { currentConvId = conversations[0].id; }
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
        '<div class="conv-reply__tools">' +
          '<label class="visually-hidden" for="conv-reply-tpl">Réponse type</label>' +
          '<select id="conv-reply-tpl" class="conv-reply__tpl" aria-label="Insérer une réponse type">' +
            '<option value="">Réponse type…</option>' +
            TEMPLATES.map(function (t, i) { return '<option value="' + i + '">' + e(t.label) + "</option>"; }).join("") +
          "</select>" +
        "</div>" +
        '<label class="visually-hidden" for="conv-reply-text">Votre réponse à ' + e(conv.nom) + '</label>' +
        '<textarea id="conv-reply-text" rows="3" placeholder="Écrivez votre réponse…"></textarea>' +
        '<button type="submit" class="btn btn-primary btn-sm">Envoyer</button>' +
      '</form>';

    var back = threadBox.querySelector("[data-conv-back]");
    if (back) {
      back.addEventListener("click", function () {
        var wrap = document.getElementById("messaging");
        if (wrap) { wrap.classList.remove("is-thread-open"); }
      });
    }

    /* Réponse type → pré-remplit le textarea (éditable avant envoi). */
    var tpl = threadBox.querySelector("#conv-reply-tpl");
    var replyField = threadBox.querySelector("#conv-reply-text");
    if (tpl && replyField) {
      tpl.addEventListener("change", function () {
        var t = TEMPLATES[parseInt(tpl.value, 10)];
        if (!t) { return; }
        var prenom = String(conv.nom).trim().split(/\s+/)[0] || "";
        replyField.value = t.texte.replace(/\{prenom\}/g, prenom);
        replyField.focus();
        tpl.value = "";
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
})();

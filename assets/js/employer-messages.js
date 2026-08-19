/**
 * Espace recruteur — Messages (espace-entreprise-messages.html).
 * Messagerie simple : liste de conversations + fil, réponse simulée.
 */
(function () {
  "use strict";

  var conversations = null;
  var currentConvId = null;

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
})();

/**
 * Chatbot « Clémence » v2 — présent sur toutes les pages.
 * Un assistant vivant : indicateur de frappe animé, messages qui
 * apparaissent en douceur, invitation discrète après quelques secondes,
 * et mini-quiz de la recherche guidée monté directement dans la
 * conversation (via SS.guidedSearchMount).
 *
 * Les réponses restent prédéfinies (SCENARIOS) : l'interface est séparée
 * de la source des réponses pour brancher plus tard une API d'IA
 * (APP_CONFIG.api.endpoints.chatbot) sans toucher au composant.
 */
(function () {
  "use strict";

  var SEEN_KEY = "ss_chat_vu";

  /* Réponses prédéfinies. `answer` accepte du HTML simple (liens internes). */
  var SCENARIOS = [
    {
      id: "emploi",
      label: "Je recherche un emploi",
      answer: "Très bien ! Vous pouvez chercher parmi toutes nos offres " +
        'depuis la page <a href="offres.html">Offres d\'emploi</a> : mot-clé, ville, ' +
        "type de contrat, télétravail… Aucune inscription n'est nécessaire pour " +
        "consulter les offres et candidater. Et pensez au marque-page ★ pour " +
        "retrouver vos offres préférées !"
    },
    {
      id: "publier",
      label: "Je souhaite publier une offre",
      answer: "Avec plaisir ! Rendez-vous sur la page " +
        '<a href="publier-offre.html">Publier une offre</a> : un formulaire en ' +
        "6 étapes simples vous guide, avec un aperçu avant mise en ligne et " +
        "une sauvegarde en brouillon. Votre offre apparaît ensuite dans votre " +
        '<a href="espace-entreprise.html">espace entreprise</a>.'
    },
    {
      id: "renouveler",
      label: "Comment renouveler une offre ?",
      answer: "Lorsqu'une offre arrive à expiration, un bouton " +
        "« Renouveler pour 10 € » apparaît dans votre " +
        '<a href="espace-entreprise.html">espace entreprise</a>. Le renouvellement ' +
        "prolonge la publication de 60 jours. Dans ce prototype, le paiement est " +
        "entièrement simulé : aucune somme n'est réellement débitée."
    },
    {
      id: "contact",
      label: "Contacter l'équipe",
      answer: "Notre équipe vous répond du lundi au vendredi, de 9h à 18h. " +
        'Le plus simple est de passer par le <a href="contact.html">formulaire de ' +
        "contact</a>. Nous répondons généralement sous un jour ouvré."
    },
    {
      id: "conseils",
      label: "Des conseils pour ma candidature",
      answer: "Bonne idée ! Consultez nos <a href=\"blog.html\">conseils et articles</a> : " +
        "CV, préparation d'entretien, télétravail, fiches métiers… " +
        "De quoi mettre toutes les chances de votre côté."
    }
  ];

  var WELCOME = "Bonjour, je suis Clémence 👋 Posez-moi une question ci-dessous — " +
    "ou faites le mini-quiz : je vous oriente en quelques réponses.";

  var FOLLOW_UP = "Puis-je vous aider sur un autre sujet ?";

  var messages, shortcuts, panel, toggle, input;
  var opened = false;
  var busy = false;

  /* ==========================================================================
     Analyse d'intention de la saisie libre.
     Principe : tout est mis en minuscules et débarrassé des accents, puis on
     teste des tables de mots-clés. L'ordre des tests compte (les intentions
     spécifiques passent avant « emploi », qui est la plus générique).
     ========================================================================== */

  function normalize(str) {
    return (str == null ? "" : String(str))
      .toLowerCase()
      .normalize("NFD")
      .replace(/[̀-ͯ]/g, "");
  }

  /* Mots trop génériques pour servir de filtre sur les offres. */
  var STOPWORDS = {
    "emploi": 1, "emplois": 1, "poste": 1, "postes": 1, "offre": 1, "offres": 1,
    "job": 1, "jobs": 1, "travail": 1, "metier": 1, "metiers": 1, "cherche": 1,
    "recherche": 1, "recherches": 1, "trouver": 1, "voudrais": 1, "veux": 1,
    "aimerais": 1, "suis": 1, "dans": 1, "pour": 1, "avec": 1, "sur": 1, "une": 1,
    "des": 1, "les": 1, "mon": 1, "mes": 1, "ma": 1, "que": 1, "quel": 1,
    "quelle": 1, "comme": 1, "est": 1, "chez": 1, "vers": 1, "region": 1,
    "secteur": 1, "domaine": 1, "candidat": 1, "recherchez": 1, "bonjour": 1
  };

  var JOB_WORDS = ["secretaire", "assistant", "assistante", "assistanat",
    "comptable", "comptabilite", "gestionnaire", "gestion", "accueil",
    "standardiste", "office manager", "administratif", "administrative",
    "administration", "juridique", "medical", "medicale", "paie", "facturation",
    "direction", "commercial", "commerciale", "saisie", "reception",
    "receptionniste", "hotesse", "ressources humaines", "assistant rh"];

  /* Chaque table = liste de sous-chaînes recherchées dans le texte normalisé. */
  var KW = {
    suivi: ["ou en est ma candidature", "ou en sont mes candidature",
      "suivre ma candidature", "suivre mes candidature", "suivi de ma candidature",
      "suivi de mes candidature", "suivi de candidature", "ma candidature",
      "mes candidatures", "statut de ma candidature", "reponse a ma candidature",
      "j'ai postule", "j ai postule"],
    renouveler: ["renouvel", "prolonger mon offre", "prolonger l'offre",
      "prolonger une offre", "payer mon offre", "payer l'offre", "remettre en ligne"],
    recruter: ["recrut", "publier une offre", "publier une annonce",
      "deposer une offre", "poster une offre", "poster une annonce", "embaucher",
      "je recrute", "diffuser une offre", "publier une offre d'emploi"],
    contact: ["contact", "parler a quelqu", "parler a un", "parler a une personne",
      "un humain", "une humaine", "un vrai", "joindre l'equipe", "vous joindre",
      "conseiller", "assistance humaine", "appeler"],
    guide: ["je ne sais pas quel metier", "sais pas quel metier", "pas quel metier",
      "aucune idee", "sais pas quoi faire", "je ne sais pas quoi", "sais pas quoi",
      "je suis perdu", "je suis perdue", "m'orienter", "besoin d'orientation",
      "je ne sais pas ce que"],
    emploi: ["emploi", "poste", "offre", "je cherche", "cherche un", "cherche du",
      "recherche un", "recherche d'emploi", "travail", "job", "metier", "carriere",
      "cdi", "cdd", "temps partiel", "temps plein", "teletravail", "alternance"]
  };

  function matchesAny(text, list) {
    for (var i = 0; i < list.length; i++) {
      if (text.indexOf(list[i]) !== -1) { return true; }
    }
    return false;
  }

  function detectIntent(text) {
    var t = normalize(text);
    if (matchesAny(t, KW.suivi)) { return { type: "suivi" }; }
    if (matchesAny(t, KW.renouveler) || /(^|\D)10(\D|$)/.test(t)) { return { type: "renouveler" }; }
    if (matchesAny(t, KW.recruter)) { return { type: "recruter" }; }
    if (matchesAny(t, KW.contact)) { return { type: "contact" }; }
    if (matchesAny(t, KW.guide)) { return { type: "guide" }; }
    if (matchesAny(t, KW.emploi) || matchesAny(t, JOB_WORDS)) { return { type: "emploi" }; }
    return { type: "default" };
  }

  /* Mots « utiles » du message (hors mots vides) pour filtrer les offres. */
  function significantTokens(text) {
    return normalize(text)
      .replace(/[^a-z0-9\s]/g, " ")
      .split(/\s+/)
      .filter(function (w) { return w.length >= 3 && !STOPWORDS[w]; });
  }

  /* Sélectionne jusqu'à 3 offres dont le titre/catégorie/ville contient un des
     mots. Sans mot utile, renvoie simplement quelques offres récentes. */
  function matchOffers(offers, tokens) {
    if (!tokens.length) { return offers.slice(0, 3); }
    var scored = [];
    offers.forEach(function (o) {
      var hay = normalize([o.titre, o.categorieLabel, o.categorie, o.ville, o.contrat].join(" "));
      var score = 0;
      tokens.forEach(function (tk) { if (hay.indexOf(tk) !== -1) { score++; } });
      if (score > 0) { scored.push({ offer: o, score: score }); }
    });
    scored.sort(function (a, b) { return b.score - a.score; });
    return scored.slice(0, 3).map(function (x) { return x.offer; });
  }

  /* ---- Réponses aux intentions (chaînent botSay puis la relance FOLLOW_UP) ---- */

  function thenFollowUp(promise) {
    return promise.then(function () { return botSay(FOLLOW_UP); });
  }

  function respondSuivi() {
    var isCandidate = window.SS && SS.auth && SS.auth.isCandidate();
    if (isCandidate) {
      return thenFollowUp(botSay(
        "Retrouvez l'état de chacune de vos candidatures dans votre " +
        '<a href="espace-candidat.html#candidatures">espace candidat</a> : ' +
        "statut, date d'envoi et réponses des recruteurs y sont récapitulés.", true));
    }
    return thenFollowUp(botSay(
      "Pour suivre une candidature, connectez-vous à votre " +
      '<a href="connexion.html">compte candidat</a>. Vous n\'en avez pas encore ? ' +
      'La <a href="inscription.html">création d\'un compte candidat</a> est gratuite ' +
      "et vous permet de suivre vos candidatures et vos offres enregistrées.", true));
  }

  function respondRenouveler() {
    var r = (window.APP_CONFIG && APP_CONFIG.payment && APP_CONFIG.payment.renewal) || {};
    var price = r.price != null ? r.price : 10;
    var days = r.durationDays != null ? r.durationDays : 60;
    return thenFollowUp(botSay(
      "Lorsqu'une de vos offres arrive à expiration, un bouton " +
      "« Renouveler pour " + price + " € » apparaît : il remet l'annonce en ligne " +
      "pour " + days + " jours. Tout se passe dans la rubrique Facturation de votre " +
      '<a href="espace-entreprise.html#facturation">espace entreprise</a>. ' +
      "Dans ce prototype, le paiement est entièrement simulé.", true));
  }

  function respondRecruter() {
    return thenFollowUp(botSay(
      "Pour recruter, créez un " +
      '<a href="inscription.html">compte entreprise</a> (ou ' +
      '<a href="connexion.html">connectez-vous</a>), puis publiez votre annonce ' +
      'depuis la page <a href="publier-offre.html">Publier une offre</a>. Vous ' +
      'suivez ensuite vos offres et candidatures dans votre ' +
      '<a href="espace-entreprise.html">espace entreprise</a>.', true));
  }

  function respondContact() {
    return thenFollowUp(botSay(
      "Notre équipe vous répond du lundi au vendredi, de 9h à 18h. " +
      'Le plus simple est le <a href="contact.html">formulaire de contact</a> : ' +
      "nous répondons généralement sous un jour ouvré.", true));
  }

  function respondGuide() {
    return thenFollowUp(botSay(
      "Pas d'inquiétude, c'est fait pour ça ! Notre " +
      '<a href="recherche-guidee.html">recherche guidée</a> vous pose quelques ' +
      "questions simples et vous oriente vers les métiers et offres qui vous " +
      "correspondent. Vous pouvez aussi lancer le mini-quiz ci-dessous.", true));
  }

  function respondDefault() {
    return thenFollowUp(botSay(
      "Je ne suis pas certaine d'avoir bien compris. Je peux vous aider à " +
      "trouver une offre, suivre une candidature, publier une annonce ou " +
      "contacter l'équipe — reformulez votre question, ou utilisez les " +
      "raccourcis ci-dessous.", true));
  }

  function respondEmploi(text) {
    var tokens = significantTokens(text);
    if (!(window.SS && typeof SS.getActiveOffers === "function")) {
      return thenFollowUp(botSay(
        'Vous pouvez consulter toutes nos <a href="offres.html">offres d\'emploi</a> ' +
        "et filtrer par mot-clé, ville et type de contrat.", true));
    }
    busy = true; /* verrouille la saisie le temps de charger les offres */
    return SS.getActiveOffers().then(function (offers) {
      busy = false;
      var results = matchOffers(offers || [], tokens);
      var allUrl = "offres.html" + (tokens.length ? "?q=" + encodeURIComponent(tokens.join(" ")) : "");
      var html;
      if (results.length) {
        var items = results.map(function (o) {
          var label = SS.escapeHtml(o.titre) + (o.ville ? " — " + SS.escapeHtml(o.ville) : "");
          return '<li><a href="offre-detail.html?id=' + encodeURIComponent(o.id) + '">' + label + "</a></li>";
        }).join("");
        html = "Voici des offres qui pourraient vous intéresser :" +
          '<ul class="chatbot-offer-list">' + items + "</ul>" +
          '<a href="' + allUrl + '">Voir toutes les offres →</a>';
      } else {
        html = "Je n'ai pas trouvé d'offre correspondant précisément à votre " +
          'recherche. Explorez toutes nos <a href="' + allUrl + '">offres d\'emploi</a>, ' +
          'ou laissez-vous guider par la <a href="recherche-guidee.html">recherche guidée</a>.';
      }
      return thenFollowUp(botSay(html, true));
    }).catch(function () {
      busy = false;
      return thenFollowUp(botSay(
        "Je n'ai pas réussi à récupérer les offres à l'instant. Vous pouvez les " +
        'consulter directement sur la page <a href="offres.html">Offres d\'emploi</a>.', true));
    });
  }

  function handleUserText(raw) {
    var text = (raw == null ? "" : String(raw)).trim();
    if (!text || busy) { return; }
    addMessage(text, "user");
    switch (detectIntent(text).type) {
      case "suivi": respondSuivi(); break;
      case "renouveler": respondRenouveler(); break;
      case "recruter": respondRecruter(); break;
      case "contact": respondContact(); break;
      case "guide": respondGuide(); break;
      case "emploi": respondEmploi(text); break;
      default: respondDefault();
    }
  }

  document.addEventListener("DOMContentLoaded", function () {
    injectWidget();

    toggle = document.getElementById("chatbot-toggle");
    panel = document.getElementById("chatbot-panel");
    var closeBtn = document.getElementById("chatbot-close");
    messages = document.getElementById("chatbot-messages");
    shortcuts = document.getElementById("chatbot-shortcuts");
    input = document.getElementById("chatbot-text");
    var inputForm = document.getElementById("chatbot-input");

    function setOpen(open) {
      panel.classList.toggle("is-open", open);
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
      panel.setAttribute("aria-hidden", open ? "false" : "true");
      if (open) {
        clearNudge();
        try { sessionStorage.setItem(SEEN_KEY, "1"); } catch (e) { /* ignoré */ }
        if (!opened) {
          opened = true;
          botSay(WELCOME);
        }
        /* Focus sur la saisie libre : le clavier est prêt dès l'ouverture. */
        if (input) { input.focus(); }
      }
    }

    toggle.addEventListener("click", function () {
      setOpen(!panel.classList.contains("is-open"));
    });
    closeBtn.addEventListener("click", function () {
      setOpen(false);
      toggle.focus();
    });
    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && panel.classList.contains("is-open")) {
        setOpen(false);
        toggle.focus();
      }
    });

    /* Invitation discrète : petit rebond + point après quelques secondes,
       une seule fois par session. */
    var alreadySeen = false;
    try { alreadySeen = sessionStorage.getItem(SEEN_KEY) === "1"; } catch (e) { /* ignoré */ }
    if (!alreadySeen) {
      setTimeout(function () {
        if (!panel.classList.contains("is-open")) {
          toggle.classList.add("is-nudge");
          var dot = toggle.querySelector(".chatbot-dot");
          if (dot) { dot.hidden = false; }
        }
      }, 6000);
    }

    function clearNudge() {
      toggle.classList.remove("is-nudge");
      var dot = toggle.querySelector(".chatbot-dot");
      if (dot) { dot.hidden = true; }
    }

    /* Raccourcis : questions prédéfinies + mini-quiz. */
    shortcuts.addEventListener("click", function (event) {
      if (busy) { return; }
      var quizBtn = event.target.closest("[data-chatbot-quiz]");
      if (quizBtn) { startQuizInChat(); return; }

      var btn = event.target.closest("button[data-scenario]");
      if (!btn) { return; }
      var scenario = SCENARIOS.find(function (s) { return s.id === btn.getAttribute("data-scenario"); });
      if (!scenario) { return; }
      addMessage(scenario.label, "user");
      /* Point de branchement futur : remplacer getAnswer par un appel API. */
      getAnswer(scenario).then(function (answer) {
        return botSay(answer, true);
      }).then(function () {
        return botSay(FOLLOW_UP);
      });
    });

    /* Source des réponses — actuellement locale, plus tard : API d'IA. */
    function getAnswer(scenario) {
      return Promise.resolve(scenario.answer);
    }

    /* ---- Saisie libre ---- */
    var MAX_INPUT_HEIGHT = 96; /* ~4 lignes */

    function autoResize() {
      input.style.height = "auto";
      input.style.height = Math.min(input.scrollHeight, MAX_INPUT_HEIGHT) + "px";
    }

    function submitInput() {
      /* Ne rien effacer tant que le bot répond : le message serait perdu. */
      if (busy || !input.value.trim()) { return; }
      var text = input.value;
      input.value = "";
      autoResize();
      handleUserText(text);
    }

    if (inputForm && input) {
      inputForm.addEventListener("submit", function (event) {
        event.preventDefault();
        submitInput();
      });
      input.addEventListener("input", autoResize);
      /* Entrée envoie ; Shift+Entrée insère une nouvelle ligne. */
      input.addEventListener("keydown", function (event) {
        if (event.key === "Enter" && !event.shiftKey) {
          event.preventDefault();
          submitInput();
        }
      });
    }

    /* ---- Mini-quiz dans la conversation ---- */
    function startQuizInChat() {
      addMessage("Je veux être guidé(e) 🎯", "user");
      if (typeof SS.guidedSearchMount !== "function") {
        botSay('Bien sûr ! Rendez-vous sur la <a href="recherche-guidee.html">recherche guidée</a> : quelques questions et je vous oriente.', true);
        return;
      }
      botSay("Avec plaisir ! Répondez à quelques questions — une à la fois, et vous pouvez revenir en arrière à tout moment.").then(function () {
        shortcuts.hidden = true;
        var quizBox = document.createElement("div");
        quizBox.className = "chatbot-quiz";
        quizBox.id = "chatbot-quiz";
        panel.appendChild(quizBox);
        SS.guidedSearchMount(quizBox, {
          onQuit: function () {
            quizBox.remove();
            shortcuts.hidden = false;
            botSay("J'espère que ces suggestions vous aident ! " + FOLLOW_UP);
          }
        });
        quizBox.scrollIntoView({ block: "nearest" });
      });
    }
  });

  /* Bulle « Clémence écrit… » puis message : c'est ce qui rend le bot vivant. */
  function botSay(content, isHtml) {
    busy = true;
    var typing = document.createElement("div");
    typing.className = "chatbot-msg chatbot-msg--bot chatbot-typing";
    typing.setAttribute("aria-label", "Clémence est en train d'écrire");
    typing.innerHTML = "<span></span><span></span><span></span>";
    messages.appendChild(typing);
    messages.scrollTop = messages.scrollHeight;

    var reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    var delay = reduced ? 80 : 500 + Math.min(600, content.length * 4);

    return new Promise(function (resolve) {
      setTimeout(function () {
        typing.remove();
        addMessage(content, "bot", isHtml);
        busy = false;
        resolve();
      }, delay);
    });
  }

  function addMessage(content, from, isHtml) {
    var msg = document.createElement("div");
    msg.className = "chatbot-msg chatbot-msg--" + (from === "bot" ? "bot" : "user");
    if (isHtml) { msg.innerHTML = content; } else { msg.textContent = content; }
    messages.appendChild(msg);
    messages.scrollTop = messages.scrollHeight;
  }

  /* Construit le widget une seule fois, injecté sur chaque page. */
  function injectWidget() {
    var wrapper = document.createElement("div");
    wrapper.innerHTML =
      '<button type="button" id="chatbot-toggle" class="chatbot-toggle" ' +
        'aria-expanded="false" aria-controls="chatbot-panel" aria-label="Ouvrir l\'assistance en ligne">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
          'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
          '<path d="M21 11.5a8.4 8.4 0 0 1-8.5 8.3 8.9 8.9 0 0 1-3.2-.6L3 21l1.9-5.5a8 8 0 0 1-1.4-4A8.4 8.4 0 0 1 12 3.2a8.4 8.4 0 0 1 9 8.3z"/>' +
        "</svg>" +
        '<span class="chatbot-toggle__label">Besoin d\'aide&nbsp;?</span>' +
        '<span class="chatbot-dot" hidden aria-hidden="true"></span>' +
      "</button>" +
      '<section id="chatbot-panel" class="chatbot-panel" aria-hidden="true" aria-label="Assistant SuperSecrétaire">' +
        '<div class="chatbot-header">' +
          '<span class="avatar" aria-hidden="true">C</span>' +
          "<div><h2>Clémence</h2>" +
          "<p>Assistante SuperSecrétaire — réponses automatiques</p></div>" +
          '<button type="button" id="chatbot-close" class="chatbot-close" aria-label="Fermer l\'assistance">×</button>' +
        "</div>" +
        '<div id="chatbot-messages" class="chatbot-messages" aria-live="polite"></div>' +
        '<div id="chatbot-shortcuts" class="chatbot-shortcuts">' +
          '<button type="button" class="chatbot-quiz-btn" data-chatbot-quiz>🎯 Trouver ce qu\'il me faut — mini-quiz</button>' +
          SCENARIOS.map(function (s) {
            return '<button type="button" data-scenario="' + s.id + '">' + s.label + "</button>";
          }).join("") +
        "</div>" +
        '<form id="chatbot-input" class="chatbot-input">' +
          '<label class="sr-only" for="chatbot-text">Écrivez votre message</label>' +
          '<textarea id="chatbot-text" class="chatbot-input__field" rows="1" ' +
            'placeholder="Écrivez votre message…" autocomplete="off" ' +
            'aria-describedby="chatbot-input-hint"></textarea>' +
          '<span id="chatbot-input-hint" class="sr-only">Appuyez sur Entrée pour envoyer, Maj+Entrée pour une nouvelle ligne.</span>' +
          '<button type="submit" class="chatbot-input__send" aria-label="Envoyer">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
              'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
              '<path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4 20-7z"/>' +
            "</svg>" +
          "</button>" +
        "</form>" +
      "</section>";
    while (wrapper.firstChild) {
      document.body.appendChild(wrapper.firstChild);
    }
  }
})();

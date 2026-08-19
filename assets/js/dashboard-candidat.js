/**
 * Espace candidat (démonstration) : tableau de bord d'un chercheur d'emploi.
 * Session simulée via SS.auth ; toutes les données (candidatures, favoris,
 * alertes, profil) sont conservées dans le stockage local du navigateur.
 * Aucune donnée réelle n'est envoyée ni enregistrée côté serveur.
 */
(function () {
  "use strict";

  var S = APP_CONFIG.storage;

  document.addEventListener("DOMContentLoaded", function () {
    /* 1. Garde d'accès : réservé aux candidats connectés. */
    if (!SS.auth.require("candidate")) { return; }

    /* 2. Identité (avatar, nom, salutation). */
    fillIdentity();

    /* 3-7. Données de démonstration + rendu des sections. */
    seedIfEmpty();
    renderApplications();
    renderStatusNotification();
    initToasts();
    renderRecommendations();
    renderFavorites();
    renderAlerts();
    bindAlertForm();
    fillProfile();

    /* 8. Navigation latérale + déconnexion. */
    setupNav();
    var logout = document.getElementById("logout-btn");
    if (logout) {
      logout.addEventListener("click", function () { SS.auth.logout(); });
    }

    /* 9. Indicateurs cohérents avec les données. */
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
     candidatures change (ex. ajout du message d'entreprise). Les navigateurs
     ayant un ancien seed en cache sont ainsi régénérés, sinon la nouvelle
     fonctionnalité (message reçu, statut « non retenue ») resterait invisible. */
  var SEED_VERSION = "2026-08-19-refus";
  var SEED_KEY = "ss_seed_version";

  function seedIfEmpty() {
    var staleSeed = SS.store.get(SEED_KEY, null) !== SEED_VERSION;
    if (staleSeed || !SS.store.get(S.applications, null)) {
      SS.store.set(S.applications, defaultApplications());
      SS.store.set(SEED_KEY, SEED_VERSION);
    }
    if (!SS.store.get(S.favorites, null)) {
      SS.store.set(S.favorites, [
        "dev-web-junior-pixel-lille",
        "chef-projet-digital-pixel-bordeaux",
        "office-manager-technexis-lille"
      ]);
    }
    if (!SS.store.get(S.alerts, null)) {
      SS.store.set(S.alerts, [
        { metier: "Développeur web", lieu: "Lille", rayon: "30", contrat: "CDI", teletravail: true }
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

  /* ============================================================
     Candidatures
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

  function getApplications() { return SS.store.get(S.applications, []); }

  function renderApplications() {
    var box = document.getElementById("applications-list");
    if (!box) { return; }
    var apps = getApplications();

    if (!apps.length) {
      box.innerHTML =
        '<div class="empty-state"><h3>Aucune candidature pour l\'instant</h3>' +
        '<p><a href="offres.html">Parcourez les offres</a> et postulez pour suivre l\'avancement ici.</p></div>';
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
     Recommandations (offres actives priorisées par métier)
     ============================================================ */
  function normalize(str) {
    return (str || "").toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "");
  }

  function renderRecommendations() {
    var box = document.getElementById("reco-list");
    if (!box) { return; }

    SS.getActiveOffers().then(function (offers) {
      var session = SS.auth.get() || {};
      var tokens = normalize(session.metier).split(/[^a-z0-9]+/).filter(function (t) { return t.length >= 4; });

      var matches = offers.filter(function (o) {
        if (!tokens.length) { return false; }
        var hay = normalize(o.titre + " " + (o.categorieLabel || ""));
        return tokens.some(function (t) { return hay.indexOf(t) !== -1; });
      });

      /* À défaut de correspondance métier : les offres les plus récentes. */
      var pool = matches.length ? matches : offers.slice();
      pool.sort(function (a, b) { return new Date(b.datePublication) - new Date(a.datePublication); });

      box.dataset.recoCount = String(matches.length || pool.length);
      updateMetrics();

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
        return '<article class="card" style="padding: var(--sp-5); display:flex; flex-direction:column; gap: var(--sp-2);">' +
          '<div style="display:flex; justify-content:space-between; gap: var(--sp-2); align-items:start;">' +
            "<strong>" + e(o.titre) + "</strong>" +
            '<button type="button" class="btn btn-ghost btn-sm" data-fav="' + e(o.id) + '" aria-pressed="' + isFav + '" title="Enregistrer en favori">' +
              (isFav ? "♥" : "♡") + "</button>" +
          "</div>" +
          '<span class="text-muted">' + e(o.entrepriseNom) + " · " + e(o.ville) + "</span>" +
          '<div style="display:flex; gap: 6px; flex-wrap:wrap;">' +
            '<span class="badge badge--accent">' + e(o.contrat) + "</span>" +
            (remote ? '<span class="badge badge--remote">' + e(remote) + "</span>" : "") +
          "</div>" +
          "<span>" + e(o.salaire || "") + "</span>" +
          '<a class="btn btn-outline btn-sm" style="margin-top:auto;" href="offre-detail.html?id=' + encodeURIComponent(o.id) + '">Voir l\'offre</a>' +
        "</article>";
      }).join("");

      box.querySelectorAll("button[data-fav]").forEach(function (btn) {
        btn.addEventListener("click", function () { toggleFavorite(btn.getAttribute("data-fav")); });
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
      box.innerHTML = '<div class="empty-state"><h3>Aucun favori pour l\'instant</h3>' +
        '<p>Cliquez sur le cœur d\'une offre pour la retrouver ici.</p></div>';
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
     Alertes emploi
     ============================================================ */
  function renderAlerts() {
    var box = document.getElementById("alerts-list");
    if (!box) { return; }
    var alerts = SS.store.get(S.alerts, []);

    if (!alerts.length) {
      box.innerHTML = '<div class="empty-state"><p>Aucune alerte enregistrée. Créez-en une ci-dessus.</p></div>';
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
      return '<article class="card" style="padding: var(--sp-4); display:flex; justify-content:space-between; gap: var(--sp-3); align-items:center; flex-wrap:wrap;">' +
        "<div><strong>" + (parts.join(" — ") || "Alerte") + "</strong><br>" +
        '<span class="badge badge--accent">' + fresh + " nouvelle" + (fresh > 1 ? "s" : "") + " offre" + (fresh > 1 ? "s" : "") + " depuis votre dernière visite</span></div>" +
        '<button type="button" class="btn btn-ghost btn-sm" data-del-alert="' + i + '">Supprimer</button>' +
      "</article>";
    }).join("");

    box.querySelectorAll("[data-del-alert]").forEach(function (btn) {
      btn.style.marginTop = "0";
      btn.addEventListener("click", function () {
        var idx = parseInt(btn.getAttribute("data-del-alert"), 10);
        var list = SS.store.get(S.alerts, []);
        list.splice(idx, 1);
        SS.store.set(S.alerts, list);
        renderAlerts();
        SS.toast("Alerte supprimée.");
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
        teletravail: document.getElementById("alert-teletravail").checked
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
     Profil (complétion fixe 75 %)
     ============================================================ */
  function fillProfile() {
    var s = SS.auth.get() || {};
    setValue("prof-nom", SS.auth.displayName());
    setValue("prof-ville", s.city || "");
    setValue("prof-metier", s.metier || "");
    setValue("prof-contrats", "CDI, CDD");
    setValue("set-email", s.email || "");
  }

  /* ============================================================
     Indicateurs
     ============================================================ */
  function updateMetrics() {
    setText("metric-applications", getApplications().length);
    setText("metric-views", 2);
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

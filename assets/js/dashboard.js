/**
 * Espace entreprise (démonstration) — tableau de bord recruteur.
 *
 * Garde de session (SS.auth) : réservé au rôle "employer".
 * Réutilise la logique de fond existante : les offres de l'entreprise
 * sont récupérées via SS.getOffers() filtré sur l'identifiant de la
 * société connectée (companyId), les statuts modifiés (désactivation /
 * renouvellement) transitent par le stockage local (ss_offer_overrides),
 * et le renouvellement renvoie vers paiement.html?offre=<id>.
 *
 * Note prototype : le compte de démonstration « Fiduciaire Bellecour »
 * ne possède aucune offre dans les données JSON. Lorsque le filtre par
 * société ne renvoie rien, on adopte un sous-ensemble d'offres de
 * démonstration (déterministe) afin que l'espace reste illustratif.
 */
(function () {
  "use strict";

  /* Clé de stockage du refus (message courtois final), lue côté candidat. */
  var REFUS_KEY = "ss_refus_demo";

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

  document.addEventListener("DOMContentLoaded", function () {
    if (!window.SS || !SS.auth) { return; }
    /* Garde : visiteur → connexion ; candidat → son espace. */
    if (!SS.auth.require("employer")) { return; }

    var layout = document.querySelector(".dash-layout");
    if (!layout) { return; }

    fillIdentity();
    renderDashboard();
    initRefusModal();
    seedApplications();
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

    var line = [company, s.secteur, s.city].filter(Boolean).join(" · ");
    setText("company-line", line || company);

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
      return mine.length ? mine : buildDemoOffers(offers);
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

    var expOffsets = [-6, 6, 22, 45];   /* jours avant/après aujourd'hui */
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

  /* ---- Rendu principal : indicateurs + tableau + alerte 10 € ---- */
  function renderDashboard() {
    getCompanyOffers().then(function (offers) {
      var active = offers.filter(function (o) { return o.statut === "active"; });
      var totalApplications = offers.reduce(function (sum, o) {
        return sum + SS.fakeApplicationCount(o.id);
      }, 0);
      /* Nouvelles candidatures : sous-ensemble stable des candidatures reçues. */
      var newApplications = offers.reduce(function (sum, o) {
        return sum + (SS.fakeApplicationCount(o.id) % 3);
      }, 0);
      var toRenew = offers.filter(needsRenewal);

      /* Indicateurs de démonstration : valeurs fixes et cohérentes affichées
         immédiatement (jamais de tiret « — » au chargement). En production,
         ces chiffres viendraient de l'API recruteur. */
      setText("metric-active", 3);
      setText("metric-applications", 18);
      setText("metric-new", 5);
      setText("metric-renew", 1);

      renderTable(offers);
      renderRenewalAlert(toRenew);
    }).catch(function () {
      SS.dataError(document.getElementById("dashboard-table-wrap"));
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
      return '<span class="badge badge--remote">Active</span>';
    }
    if (o.statut === "desactivee") {
      return '<span class="badge badge--neutral">Désactivée</span>';
    }
    return '<span class="badge badge--expired">Expirée</span>';
  }

  function renderTable(offers) {
    var tbody = document.getElementById("dashboard-offers");
    if (!tbody) { return; }
    var e = SS.escapeHtml;

    if (!offers.length) {
      tbody.innerHTML = '<tr><td colspan="7">' +
        '<div class="empty-state"><h3>Aucune offre pour le moment</h3>' +
        '<p><a href="publier-offre.html">Publiez votre première offre</a> pour recevoir des candidatures.</p></div>' +
        "</td></tr>";
      return;
    }

    tbody.innerHTML = offers.map(function (o) {
      var renewable = needsRenewal(o);
      var actions =
        '<a class="btn btn-outline btn-sm" href="publier-offre.html?modifier=' + encodeURIComponent(o.id) + '">Modifier</a>' +
        '<a class="btn btn-ghost btn-sm" href="#candidatures">Voir les candidatures</a>';
      if (o.statut === "active") {
        actions += '<button type="button" class="btn btn-danger btn-sm" data-action="disable" data-id="' + e(o.id) + '">Désactiver</button>';
      } else if (o.statut === "desactivee") {
        actions += '<button type="button" class="btn btn-primary btn-sm" data-action="enable" data-id="' + e(o.id) + '">Réactiver</button>';
      }
      if (renewable) {
        actions += '<a class="btn btn-accent btn-sm" href="paiement.html?offre=' + encodeURIComponent(o.id) + '">Renouveler</a>';
      }

      return "<tr>" +
        '<td data-label="Titre"><strong>' + e(o.titre) + "</strong><br><span class='text-muted'>" + e(o.ville) + " — " + e(o.contrat) + "</span></td>" +
        '<td data-label="Publiée le">' + e(SS.formatDate(o.datePublication)) + "</td>" +
        '<td data-label="Vues">' + stableViews(o.id) + "</td>" +
        '<td data-label="Candidatures">' + SS.fakeApplicationCount(o.id) + "</td>" +
        '<td data-label="Statut">' + statusBadge(o) + "</td>" +
        '<td data-label="Expire le">' + e(SS.formatDate(o.dateExpiration)) + "</td>" +
        '<td><div class="row-actions">' + actions + "</div></td>" +
      "</tr>";
    }).join("");

    tbody.querySelectorAll("button[data-action]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var id = btn.getAttribute("data-id");
        var action = btn.getAttribute("data-action");
        var overrides = SS.store.get(APP_CONFIG.storage.offerOverrides, {});
        overrides[id] = overrides[id] || {};
        overrides[id].statut = action === "disable" ? "desactivee" : "active";
        if (action === "enable") {
          var next = new Date();
          next.setDate(next.getDate() + APP_CONFIG.payment.renewal.durationDays);
          overrides[id].dateExpiration = next.toISOString().slice(0, 10);
        }
        SS.store.set(APP_CONFIG.storage.offerOverrides, overrides);
        SS.toast(action === "disable" ? "Offre désactivée." : "Offre réactivée.");
        renderDashboard();
      });
    });
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

  /* ---- Candidatures reçues (données de démonstration) ---- */
  function seedApplications() {
    var box = document.getElementById("applications-received");
    if (!box) { return; }
    var e = SS.escapeHtml;

    var people = [
      { nom: "Camille Reynaud", offre: "Assistant(e) comptable — CDI", jours: 1, statut: "recue", label: "Nouvelle" },
      { nom: "Malik Benhaddou", offre: "Gestionnaire de paie — CDI", jours: 2, statut: "preselection", label: "Présélection" },
      { nom: "Sophie Lemaire", offre: "Secrétaire administrative — CDD", jours: 3, statut: "vue", label: "Vue" },
      { nom: "Yannick Perrot", offre: "Assistant(e) comptable — CDI", jours: 4, statut: "entretien", label: "Entretien" },
      { nom: "Inès Fabre", offre: "Gestionnaire de paie — CDI", jours: 5, statut: "recue", label: "Nouvelle" },
      { nom: "Thomas Ravel", offre: "Collaborateur comptable — CDI", jours: 6, statut: "vue", label: "Vue" },
      { nom: "Awa Diallo", offre: "Secrétaire administrative — CDD", jours: 8, statut: "preselection", label: "Présélection" }
    ];

    box.innerHTML = people.map(function (p, i) {
      var d = dateFromToday(-p.jours);
      return '<div class="appli-card" data-app="cand-' + i + '">' +
          '<div class="appli-card__top">' +
            "<div><strong>" + e(p.nom) + "</strong><br>" +
              '<span class="text-muted">' + e(p.offre) + " · reçue " + e(SS.relativeDate(d)) + "</span></div>" +
            '<span class="status-badge status-' + p.statut + '">' + e(p.label) + "</span>" +
          "</div>" +
          '<p class="text-muted" data-refus-date hidden style="margin-top:var(--sp-2);"></p>' +
          '<div class="form-actions" style="margin-top:var(--sp-3);">' +
            '<button type="button" class="btn btn-outline btn-sm" data-toast="Ouverture du profil candidat (démonstration).">Voir le profil</button>' +
            '<button type="button" class="btn btn-primary btn-sm" data-toast="Candidat présélectionné (démonstration).">Présélectionner</button>' +
            '<a class="btn btn-ghost btn-sm" href="#messages">Message</a>' +
            '<button type="button" class="btn btn-danger btn-sm" data-refus data-nom="' + e(p.nom) + '" data-offre="' + e(p.offre) + '">Refuser la candidature</button>' +
          "</div>" +
        "</div>";
    }).join("");

    box.querySelectorAll("[data-refus]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        if (openRefusModal) { openRefusModal(btn); }
      });
    });
  }

  /* ---- Historique de facturation (données de démonstration) ---- */
  function seedBilling() {
    var tbody = document.getElementById("billing-history");
    if (!tbody) { return; }
    var e = SS.escapeHtml;

    var rows = [
      { date: dateFromToday(-12), offre: "Assistant(e) comptable — CDI" },
      { date: dateFromToday(-48), offre: "Gestionnaire de paie — CDI" },
      { date: dateFromToday(-95), offre: "Secrétaire administrative — CDD" }
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

  /* ---- Boutons fictifs (data-toast) : profil, messages, facturation… ---- */
  function initToasts() {
    document.addEventListener("click", function (ev) {
      var btn = ev.target.closest ? ev.target.closest("[data-toast]") : null;
      if (btn) { SS.toast(btn.getAttribute("data-toast")); }
    });
  }

  /* ---- Modale de refus de candidature (accessible, focus piégé) ---- */
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

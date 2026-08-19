/**
 * Utilitaires partagés + comportements globaux (révélation au défilement,
 * année du pied de page, gestion générique des modales).
 * Exposé sous l'espace de noms global `SS`.
 */
(function () {
  "use strict";

  var cache = {};

  var MONTHS = ["janvier", "février", "mars", "avril", "mai", "juin",
    "juillet", "août", "septembre", "octobre", "novembre", "décembre"];

  window.SS = {

    /* ---- Chargement des données JSON (avec cache mémoire) ---- */
    loadJSON: function (path) {
      if (cache[path]) { return cache[path]; }
      cache[path] = fetch(path).then(function (res) {
        if (!res.ok) { throw new Error("HTTP " + res.status); }
        return res.json();
      });
      return cache[path];
    },

    getCompanies: function () {
      return SS.loadJSON(APP_CONFIG.data.companies);
    },

    getArticles: function () {
      return SS.loadJSON(APP_CONFIG.data.articles);
    },

    /* Offres = fichier JSON + offres publiées via le formulaire
       + statuts modifiés (désactivation, renouvellement) en localStorage. */
    getOffers: function () {
      return SS.loadJSON(APP_CONFIG.data.offers).then(function (offers) {
        var custom = SS.store.get(APP_CONFIG.storage.customOffers, []);
        var overrides = SS.store.get(APP_CONFIG.storage.offerOverrides, {});
        var all = offers.concat(custom);
        return all.map(function (offer) {
          var o = Object.assign({}, offer, overrides[offer.id] || {});
          /* Une offre dont la date d'expiration est passée est expirée. */
          if (o.statut === "active" && o.dateExpiration &&
              new Date(o.dateExpiration) < new Date()) {
            o.statut = "expiree";
          }
          return o;
        });
      });
    },

    /* Offres visibles côté candidats (actives uniquement). */
    getActiveOffers: function () {
      return SS.getOffers().then(function (offers) {
        return offers.filter(function (o) { return o.statut === "active"; });
      });
    },

    /* ---- Stockage local ---- */
    store: {
      get: function (key, fallback) {
        try {
          var raw = localStorage.getItem(key);
          return raw ? JSON.parse(raw) : fallback;
        } catch (e) {
          return fallback;
        }
      },
      set: function (key, value) {
        try {
          localStorage.setItem(key, JSON.stringify(value));
        } catch (e) { /* stockage indisponible : la démo continue sans persistance */ }
      },
      remove: function (key) {
        try { localStorage.removeItem(key); } catch (e) { /* ignoré */ }
      }
    },

    /* ---- Session simulée (prototype biface candidat / recruteur) ----
       AUCUN mot de passe n'est stocké ni vérifié. La « session » est un
       simple objet en stockage local : { loggedIn, role, firstName, ... }. */
    auth: {
      get: function () {
        var s = window.SS.store.get(APP_CONFIG.storage.session, null);
        return (s && s.loggedIn) ? s : null;
      },
      set: function (session) { window.SS.store.set(APP_CONFIG.storage.session, session); },
      clear: function () { window.SS.store.remove(APP_CONFIG.storage.session); },
      isLogged: function () { return !!this.get(); },
      isCandidate: function () { var s = this.get(); return !!(s && s.role === "candidate"); },
      isEmployer: function () { var s = this.get(); return !!(s && s.role === "employer"); },
      displayName: function () {
        var s = this.get() || {};
        return ((s.firstName || "") + " " + (s.lastName || "")).trim() || (s.company || "");
      },
      initials: function () {
        var s = this.get() || {};
        var a = (s.firstName || "").trim(), b = (s.lastName || "").trim();
        if (a && b) { return (a[0] + b[0]).toUpperCase(); }
        if (a) { return a[0].toUpperCase(); }
        if (s.company) { return s.company.trim()[0].toUpperCase(); }
        return "?";
      },
      logout: function (redirect) {
        this.clear();
        window.location.href = redirect || "index.html";
      },
      /* Garde d'accès d'un espace : renvoie false et redirige si le rôle
         ne correspond pas (visiteur → connexion ; mauvais rôle → son espace). */
      require: function (role) {
        var s = this.get();
        if (!s) { window.location.href = "connexion.html"; return false; }
        if (role && s.role !== role) {
          window.location.href = s.role === "employer" ? "espace-entreprise.html" : "espace-candidat.html";
          return false;
        }
        return true;
      }
    },

    /* ---- Aides diverses ---- */
    escapeHtml: function (value) {
      var div = document.createElement("div");
      div.textContent = value == null ? "" : String(value);
      return div.innerHTML;
    },

    param: function (name) {
      return new URLSearchParams(window.location.search).get(name);
    },

    formatDate: function (iso) {
      if (!iso) { return ""; }
      var d = new Date(iso);
      if (isNaN(d)) { return iso; }
      return d.getDate() + " " + MONTHS[d.getMonth()] + " " + d.getFullYear();
    },

    relativeDate: function (iso) {
      var diff = Math.floor((Date.now() - new Date(iso).getTime()) / 86400000);
      if (diff <= 0) { return "aujourd'hui"; }
      if (diff === 1) { return "hier"; }
      if (diff < 7) { return "il y a " + diff + " jours"; }
      if (diff < 30) { return "il y a " + Math.floor(diff / 7) + " semaine" + (diff >= 14 ? "s" : ""); }
      return "le " + SS.formatDate(iso);
    },

    /* Nombre de candidatures fictif mais stable pour une offre donnée. */
    fakeApplicationCount: function (id) {
      var h = 0;
      for (var i = 0; i < id.length; i++) { h = (h * 31 + id.charCodeAt(i)) % 997; }
      return (h % 14) + 1;
    },

    teletravailLabel: function (value) {
      if (value === "complet") { return "Télétravail complet"; }
      if (value === "partiel") { return "Télétravail partiel"; }
      return null;
    },

    /* Message affiché quand les données ne peuvent pas être chargées
       (typiquement : page ouverte sans serveur local, voir README). */
    dataError: function (container) {
      if (!container) { return; }
      container.innerHTML =
        '<div class="empty-state"><h3>Données indisponibles</h3>' +
        "<p>Impossible de charger les données de démonstration. " +
        "Lancez le site avec un petit serveur local (voir le fichier README.md) " +
        "plutôt qu'en ouvrant directement le fichier HTML.</p></div>";
    },

    /* ---- Message éphémère (toast) ---- */
    toast: function (message) {
      var el = document.getElementById("ss-toast");
      if (!el) {
        el = document.createElement("p");
        el.id = "ss-toast";
        el.className = "toast";
        el.setAttribute("role", "status");
        document.body.appendChild(el);
      }
      el.textContent = message;
      el.classList.add("is-visible");
      clearTimeout(el._timer);
      el._timer = setTimeout(function () {
        el.classList.remove("is-visible");
      }, 2600);
    },

    /* ---- Modales <dialog> ---- */
    openModal: function (dialog) {
      if (!dialog) { return; }
      if (typeof dialog.showModal === "function") {
        dialog.showModal();
      } else {
        dialog.setAttribute("open", "");
      }
    },

    closeModal: function (dialog) {
      if (!dialog) { return; }
      if (typeof dialog.close === "function") {
        dialog.close();
      } else {
        dialog.removeAttribute("open");
      }
    }
  };

  /* ---- Comportements globaux ---- */
  document.addEventListener("DOMContentLoaded", function () {

    /* Année dynamique du pied de page. */
    var yearEl = document.querySelector("[data-year]");
    if (yearEl) { yearEl.textContent = String(new Date().getFullYear()); }

    /* Newsletter du pied de page — simulée, comme le reste du prototype. */
    var newsletter = document.querySelector("[data-newsletter]");
    if (newsletter) {
      newsletter.addEventListener("submit", function (event) {
        event.preventDefault();
        newsletter.reset();
        SS.toast("Inscription enregistrée — prototype : aucun e-mail ne sera envoyé.");
      });
    }

    /* Fermeture générique des modales via [data-close-modal]. */
    document.addEventListener("click", function (event) {
      var closer = event.target.closest("[data-close-modal]");
      if (closer) { SS.closeModal(closer.closest("dialog")); }
    });

    /* Chaque grande section éditoriale apparaît en douceur. */
    document.querySelectorAll("main > .section > .container").forEach(function (el) {
      el.classList.add("reveal");
    });

    /* Révélation douce des sections marquées .reveal. */
    var reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    var revealables = document.querySelectorAll(".reveal");
    if (reduceMotion || !("IntersectionObserver" in window)) {
      revealables.forEach(function (el) { el.classList.add("is-visible"); });
    } else {
      /* rootMargin étendu vers le haut : une section dépassée d'un coup
         (ancre, restauration de défilement) est considérée comme vue. */
      var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add("is-visible");
            observer.unobserve(entry.target);
          }
        });
      }, { threshold: 0.12, rootMargin: "4000px 0px 0px 0px" });
      revealables.forEach(function (el) { observer.observe(el); });
    }
  });
})();


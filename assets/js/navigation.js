/**
 * Navigation : menu mobile et mise en évidence de la page courante.
 */
(function () {
  "use strict";

  document.addEventListener("DOMContentLoaded", function () {
    var toggle = document.querySelector(".nav-toggle");
    var nav = document.getElementById("main-nav");
    if (toggle && nav) {
      /* Ouverture / fermeture : aria-expanded + défilement bloqué. */
      var setOpen = function (open) {
        nav.classList.toggle("is-open", open);
        toggle.setAttribute("aria-expanded", open ? "true" : "false");
        document.body.classList.toggle("menu-open", open);
      };

      toggle.addEventListener("click", function () {
        setOpen(!nav.classList.contains("is-open"));
      });

      /* Fermer le menu mobile après un clic sur un lien. */
      nav.addEventListener("click", function (event) {
        if (event.target.closest("a")) { setOpen(false); }
      });

      /* Fermer avec la touche Échap. */
      document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && nav.classList.contains("is-open")) {
          setOpen(false);
          toggle.focus();
        }
      });
    }

    /* En-tête « vivant » : ombre douce dès que la page défile un peu. */
    var lightHeader = document.querySelector(".site-header--light");
    if (lightHeader) {
      var onScroll = function () {
        lightHeader.classList.toggle("is-scrolled", window.scrollY > 8);
      };
      window.addEventListener("scroll", onScroll, { passive: true });
      onScroll();
    }

    /* Marquer le lien de la page courante (aria-current). */
    var current = window.location.pathname.split("/").pop() || "index.html";
    document.querySelectorAll(".nav-links a").forEach(function (link) {
      var href = link.getAttribute("href");
      if (href === current ||
          (href === "offres.html" && current === "offre-detail.html") ||
          (href === "entreprises.html" && current === "entreprise-detail.html") ||
          (href === "savoir-faire.html" &&
            (current === "savoir-faire-detail.html" || current === "publier-savoir-faire.html")) ||
          (href === "blog.html" && current === "article.html")) {
        link.setAttribute("aria-current", "page");
      }
    });

    /* ---- Zone compte : avatar + menu déroulant selon la session ----
       Visiteur : on garde « Se connecter / Créer un compte ».
       Connecté : on remplace par l'avatar à initiales + un menu par rôle. */
    (function accountNav() {
      var host = document.getElementById("account-nav");
      if (!host || !window.SS || !SS.auth) { return; }
      var session = SS.auth.get();
      if (!session) { return; }

      var e = SS.escapeHtml;
      var isEmp = session.role === "employer";
      var initials = SS.auth.initials();
      var name = SS.auth.displayName();
      var sub = isEmp ? (session.company || "Espace recruteur") : "Espace candidat";

      /* Dropdown compte volontairement COURT : la navigation interne de
         l'espace (sidebar / menu) couvre déjà toutes les sections. */
      var menu = isEmp ? [
        ["Mon espace", "espace-entreprise.html"],
        ["Profil entreprise", "espace-entreprise.html#profil"],
        ["Paramètres", "espace-entreprise.html#parametres"]
      ] : [
        ["Mon espace", "espace-candidat.html"],
        ["Mon profil", "espace-candidat.html#profil"],
        ["Paramètres", "espace-candidat.html#parametres"]
      ];
      var items = menu.map(function (m) {
        return '<a role="menuitem" href="' + m[1] + '">' + e(m[0]) + "</a>";
      }).join("");

      /* ---- Centre de notifications (démo, persistant en localStorage) ---- */
      var notifKey = "ss_notifs_" + (isEmp ? "employer" : "candidate");
      var seed = isEmp ? [
        ["candidature", "Nouvelle candidature — Assistant commercial", "il y a 1 h", "espace-entreprise.html#candidatures", false],
        ["reponse", "1 candidat attend une réponse depuis 4 jours", "aujourd'hui", "espace-entreprise.html#candidatures", false],
        ["offre", "Votre offre « Aide-soignant » expire dans 3 jours", "aujourd'hui", "espace-entreprise.html#offres", false],
        ["message", "Nouveau message de Camille Reynaud", "hier", "espace-entreprise.html#messages", true],
        ["entretien", "Entretien avec Julie Martin mardi 14:30", "il y a 2 j", "espace-entreprise.html#entretiens", true]
      ] : [
        ["statut", "Votre candidature chez Pixel & Co a été mise à jour", "il y a 2 h", "espace-candidat.html#candidatures", false],
        ["message", "Nouveau message de TechNexis", "il y a 5 h", "espace-candidat.html#messages", false],
        ["entretien", "Entretien proposé — Développeur web junior", "hier", "espace-candidat.html#entretiens", false],
        ["offre", "3 nouvelles offres correspondent à votre recherche", "hier", "offres.html", true],
        ["vue", "Votre profil a été consulté par Groupe Horizon BTP", "il y a 2 j", "espace-candidat.html#candidatures", true]
      ];
      var notifs = SS.store.get(notifKey, null);
      if (!notifs) {
        notifs = seed.map(function (n) { return { type: n[0], text: n[1], time: n[2], href: n[3], read: n[4] }; });
        SS.store.set(notifKey, notifs);
      }
      function unread() { return notifs.filter(function (n) { return !n.read; }).length; }
      /* Pastille de couleur sobre selon l'urgence/type (pas d'emoji). */
      function dotClass(t) {
        if (t === "reponse" || t === "offre") { return "notif-dot--warn"; }
        if (t === "candidature" || t === "entretien") { return "notif-dot--info"; }
        if (t === "statut" || t === "vue") { return "notif-dot--ok"; }
        return "notif-dot--neutral";
      }
      function notifItems() {
        return notifs.map(function (n, i) {
          return '<li><a role="menuitem" class="notif-item' + (n.read ? "" : " is-unread") + '" href="' + n.href + '" data-notif-i="' + i + '">' +
            '<span class="notif-dot ' + dotClass(n.type) + '" aria-hidden="true"></span>' +
            '<span class="notif-item__body"><span class="notif-item__text">' + e(n.text) + "</span>" +
            '<span class="notif-item__time">' + e(n.time) + "</span></span></a></li>";
        }).join("");
      }
      var bellSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>';

      host.innerHTML =
        '<div class="account-cluster">' +
          '<div class="notif-menu" data-notif-menu>' +
            '<button type="button" class="notif-btn" id="notif-toggle" aria-haspopup="true" aria-expanded="false" aria-controls="notif-dropdown" aria-label="Notifications">' +
              bellSvg +
              '<span class="notif-badge" data-notif-count' + (unread() ? "" : " hidden") + ">" + unread() + "</span>" +
            "</button>" +
            '<div class="notif-dropdown" id="notif-dropdown" role="menu" hidden>' +
              '<div class="notif-dropdown__head"><strong>Notifications</strong>' +
                '<button type="button" class="notif-readall" data-notif-readall>Tout marquer comme lu</button></div>' +
              '<ul class="notif-list" data-notif-list>' + notifItems() + "</ul>" +
              '<p class="notif-empty" data-notif-empty' + (notifs.length ? " hidden" : "") + ">Vous êtes à jour ✓</p>" +
            "</div>" +
          "</div>" +
          '<div class="account-menu" data-account-menu>' +
            '<button type="button" class="avatar-btn" id="avatar-toggle" aria-haspopup="true" aria-expanded="false" aria-controls="account-dropdown" aria-label="Menu du compte de ' + e(name) + '">' +
              '<span class="avatar" aria-hidden="true">' + e(initials) + "</span>" +
              '<span class="avatar-caret" aria-hidden="true">▾</span>' +
            "</button>" +
            '<div class="account-dropdown" id="account-dropdown" role="menu" hidden>' +
              '<div class="account-dropdown__head"><strong>' + e(name) + "</strong><span>" + e(sub) + "</span></div>" +
              '<div class="account-dropdown__links">' + items + "</div>" +
              '<button type="button" class="account-dropdown__logout" role="menuitem" data-logout>Se déconnecter</button>' +
            "</div>" +
          "</div>" +
        "</div>";

      /* Générique : un déclencheur + son panneau (avatar OU notifications). */
      function bindMenu(wrapSel, btnId, ddId) {
        var wrap = host.querySelector(wrapSel);
        var btn = host.querySelector("#" + btnId);
        var dd = host.querySelector("#" + ddId);
        function open(o) {
          btn.setAttribute("aria-expanded", o ? "true" : "false");
          dd.hidden = !o;
          wrap.classList.toggle("is-open", o);
          if (o) {
            /* fermer l'autre panneau ouvert */
            host.querySelectorAll(".is-open").forEach(function (w) { if (w !== wrap) { w.classList.remove("is-open"); var d = w.querySelector("[role=menu]"); var b = w.querySelector("[aria-haspopup]"); if (d) { d.hidden = true; } if (b) { b.setAttribute("aria-expanded", "false"); } } });
            var f = dd.querySelector("[role=menuitem]"); if (f) { f.focus(); }
          }
        }
        btn.addEventListener("click", function (ev) { ev.stopPropagation(); open(dd.hidden); });
        dd.addEventListener("keydown", function (ev) { if (ev.key === "Escape") { open(false); btn.focus(); } });
        return { open: open, wrap: wrap, btn: btn, dd: dd };
      }
      var acc = bindMenu("[data-account-menu]", "avatar-toggle", "account-dropdown");
      var notif = bindMenu("[data-notif-menu]", "notif-toggle", "notif-dropdown");
      document.addEventListener("click", function (ev) {
        if (!acc.wrap.contains(ev.target)) { acc.open(false); }
        if (!notif.wrap.contains(ev.target)) { notif.open(false); }
      });
      host.querySelector("[data-logout]").addEventListener("click", function () { SS.auth.logout("index.html"); });

      /* Notifications : marquer lu au clic / tout lire. */
      function refreshBadge() {
        var badge = host.querySelector("[data-notif-count]");
        var n = unread();
        badge.textContent = n;
        badge.hidden = n === 0;
        SS.store.set(notifKey, notifs);
      }
      host.querySelectorAll("[data-notif-i]").forEach(function (a) {
        a.addEventListener("click", function () {
          var i = parseInt(a.getAttribute("data-notif-i"), 10);
          if (notifs[i]) { notifs[i].read = true; a.classList.remove("is-unread"); refreshBadge(); }
        });
      });
      host.querySelector("[data-notif-readall]").addEventListener("click", function () {
        notifs.forEach(function (n) { n.read = true; });
        host.querySelectorAll(".notif-item.is-unread").forEach(function (el) { el.classList.remove("is-unread"); });
        refreshBadge();
      });
    })();
  });
})();

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

      var isEmp = session.role === "employer";
      var initials = SS.auth.initials();
      var name = SS.auth.displayName();
      var sub = isEmp ? (session.company || "Espace recruteur") : "Espace candidat";

      var menu = isEmp ? [
        ["Mon espace entreprise", "espace-entreprise.html"],
        ["Profil entreprise", "espace-entreprise.html#profil"],
        ["Mes offres", "espace-entreprise.html#offres"],
        ["Candidatures reçues", "espace-entreprise.html#candidatures"],
        ["Facturation", "espace-entreprise.html#facturation"],
        ["Paramètres", "espace-entreprise.html#parametres"]
      ] : [
        ["Mon espace", "espace-candidat.html"],
        ["Mon profil", "espace-candidat.html#profil"],
        ["Mes candidatures", "espace-candidat.html#candidatures"],
        ["Mes favoris", "espace-candidat.html#favoris"],
        ["Mes alertes", "espace-candidat.html#alertes"],
        ["Paramètres", "espace-candidat.html#parametres"]
      ];
      var items = menu.map(function (m) {
        return '<a role="menuitem" href="' + m[1] + '">' + SS.escapeHtml(m[0]) + "</a>";
      }).join("");

      host.innerHTML =
        '<div class="account-menu" data-account-menu>' +
          '<button type="button" class="avatar-btn" id="avatar-toggle" aria-haspopup="true" aria-expanded="false" aria-controls="account-dropdown" aria-label="Menu du compte de ' + SS.escapeHtml(name) + '">' +
            '<span class="avatar" aria-hidden="true">' + SS.escapeHtml(initials) + '</span>' +
            '<span class="avatar-caret" aria-hidden="true">▾</span>' +
          '</button>' +
          '<div class="account-dropdown" id="account-dropdown" role="menu" hidden>' +
            '<div class="account-dropdown__head"><strong>' + SS.escapeHtml(name) + '</strong><span>' + SS.escapeHtml(sub) + '</span></div>' +
            '<div class="account-dropdown__links">' + items + '</div>' +
            '<button type="button" class="account-dropdown__logout" role="menuitem" data-logout>Se déconnecter</button>' +
          '</div>' +
        '</div>';

      var wrap = host.querySelector("[data-account-menu]");
      var btn = host.querySelector("#avatar-toggle");
      var dd = host.querySelector("#account-dropdown");
      function open(o) {
        btn.setAttribute("aria-expanded", o ? "true" : "false");
        dd.hidden = !o;
        wrap.classList.toggle("is-open", o);
        if (o) { var f = dd.querySelector("[role=menuitem]"); if (f) { f.focus(); } }
      }
      btn.addEventListener("click", function (e) { e.stopPropagation(); open(dd.hidden); });
      document.addEventListener("click", function (e) { if (!wrap.contains(e.target)) { open(false); } });
      document.addEventListener("keydown", function (e) {
        if (e.key === "Escape" && !dd.hidden) { open(false); btn.focus(); }
      });
      host.querySelector("[data-logout]").addEventListener("click", function () { SS.auth.logout("index.html"); });
    })();
  });
})();

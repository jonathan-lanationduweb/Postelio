/**
 * Coquille commune des espaces (candidat + recruteur) : navigation « une
 * rubrique à la fois » + tiroir (drawer) mobile.
 *
 * Le tableau de bord n'affiche plus TOUTES les sections empilées : chaque
 * entrée de la barre latérale (#apercu, #candidatures, #profil…) affiche sa
 * seule section — comme une page dédiée — sans casser les URLs (le routage
 * se fait par ancre `#id`). Sur mobile (<900px), la barre latérale devient un
 * tiroir déroulant ouvert par un bouton « Menu de mon espace ».
 *
 * Chargé APRÈS dashboard.js / dashboard-candidat.js : le contenu est déjà
 * rendu quand ce module masque/affiche les sections.
 */
(function () {
  "use strict";

  document.addEventListener("DOMContentLoaded", function () {
    var layout = document.querySelector(".dash-layout");
    if (!layout) { return; }
    var main = layout.querySelector(".dash-main");
    var sidebar = layout.querySelector(".dash-sidebar");
    if (!main || !sidebar) { return; }

    var sections = Array.prototype.slice.call(main.children).filter(function (el) {
      return el.classList && el.classList.contains("dash-block") && el.id;
    });
    var navLinks = Array.prototype.slice.call(sidebar.querySelectorAll(".dash-nav a"));
    /* Seuls les liens internes (#ancre) sont routés ; les liens externes
       (ex. « Publier une offre » → publier-offre.html) naviguent normalement. */
    var routed = navLinks.filter(function (l) {
      var h = l.getAttribute("href") || "";
      return h.charAt(0) === "#" && document.getElementById(h.slice(1));
    });
    if (!sections.length || !routed.length) { return; }

    var ids = routed.map(function (l) { return l.getAttribute("href").slice(1); });

    /* ---- Tiroir mobile : bouton injecté en tête de .dash-main ---- */
    var drawerBtn = document.createElement("button");
    drawerBtn.type = "button";
    drawerBtn.className = "dash-drawer-toggle";
    drawerBtn.setAttribute("aria-expanded", "false");
    drawerBtn.setAttribute("aria-controls", sidebar.id || "dash-sidebar");
    if (!sidebar.id) { sidebar.id = "dash-sidebar"; }
    drawerBtn.innerHTML = '<span class="dash-drawer-toggle__label">Menu de mon espace</span>' +
      '<span class="dash-drawer-toggle__caret" aria-hidden="true">▾</span>';
    main.insertBefore(drawerBtn, main.firstChild);
    var drawerLabel = drawerBtn.querySelector(".dash-drawer-toggle__label");

    function openDrawer(o) {
      layout.classList.toggle("dash-drawer-open", o);
      drawerBtn.setAttribute("aria-expanded", o ? "true" : "false");
    }
    drawerBtn.addEventListener("click", function () {
      openDrawer(!layout.classList.contains("dash-drawer-open"));
    });

    function labelFor(id) {
      var l = routed.filter(function (x) { return x.getAttribute("href").slice(1) === id; })[0];
      return l ? l.textContent.trim() : "Menu de mon espace";
    }

    function show(id) {
      if (ids.indexOf(id) === -1) { id = ids[0]; }
      sections.forEach(function (s) { s.hidden = (s.id !== id); });
      navLinks.forEach(function (l) {
        var on = l.getAttribute("href") === "#" + id;
        l.classList.toggle("is-active", on);
        if (on) { l.setAttribute("aria-current", "page"); } else { l.removeAttribute("aria-current"); }
      });
      if (drawerLabel) { drawerLabel.textContent = labelFor(id); }
      openDrawer(false);
      window.scrollTo(0, 0);
      /* Focus sur le titre de la section pour les lecteurs d'écran. */
      var target = document.getElementById(id);
      var heading = target && target.querySelector("h1, h2");
      if (heading) { heading.setAttribute("tabindex", "-1"); heading.focus({ preventScroll: true }); }
    }

    window.addEventListener("hashchange", function () {
      show((location.hash || "").replace(/^#/, ""));
    });

    var initial = (location.hash || "").replace(/^#/, "");
    show(ids.indexOf(initial) !== -1 ? initial : ids[0]);

    /* Fermer le tiroir en cliquant hors de la barre latérale (mobile). */
    document.addEventListener("click", function (ev) {
      if (!layout.classList.contains("dash-drawer-open")) { return; }
      if (sidebar.contains(ev.target) || drawerBtn.contains(ev.target)) { return; }
      openDrawer(false);
    });
  });
})();

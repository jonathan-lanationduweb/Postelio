/**
 * Espace recruteur — Contenus entreprise (espace-entreprise-contenus.html).
 *
 * « Votre marque employeur » : cartes de contenu avec miniature 16:9
 * (dégradé local, aucun appel réseau), filtres par catégorie (filtrage live),
 * et actions par carte (Voir + menu Modifier / Dépublier / Supprimer).
 *
 * Persistance des changements d'état dans ss_company_content (nouvelle clé) :
 *   { <id>: { status: "brouillon"|"publie", deleted: true } }
 * Les autres clés de stockage restent inchangées.
 */
(function () {
  "use strict";

  var CONTENT_KEY = "ss_company_content";
  var CAT_LABELS = {
    metiers: "Métiers", coulisses: "Coulisses", equipe: "Équipe",
    locaux: "Locaux", conseils: "Conseils"
  };

  var SEED = [
    { id: "c-conducteur", titre: "Une journée avec notre conducteur de travaux", cat: "metiers", type: "Reportage", jours: 5 },
    { id: "c-atelier", titre: "Découvrez notre atelier et nos équipements", cat: "locaux", type: "Visite", jours: 18 },
    { id: "c-support", titre: "Comment travaille notre équipe support", cat: "coulisses", type: "Coulisses", jours: 40 },
    { id: "c-alternants", titre: "Rencontre avec nos alternants et leurs tuteurs", cat: "equipe", type: "Portrait", jours: 62 }
  ];

  var openMenu = null;      /* menu […] actuellement ouvert */
  var deleteModal = null;
  var pendingDelete = null; /* id du contenu à supprimer après confirmation */

  document.addEventListener("DOMContentLoaded", function () {
    if (!window.EMP || !EMP.ready) { return; }
    var box = document.getElementById("contents-list");
    if (!box) { return; }

    deleteModal = initDeleteModal();
    initFilters();
    render("tous");

    /* Fermer tout menu ouvert au clic extérieur ou à Échap. */
    document.addEventListener("click", function (ev) {
      if (openMenu && !openMenu.wrap.contains(ev.target)) { closeMenu(); }
    });
    document.addEventListener("keydown", function (ev) {
      if (ev.key === "Escape" && openMenu) { var b = openMenu.btn; closeMenu(); if (b) { b.focus(); } }
    });
  });

  /* ---- État courant des contenus (seed + overrides) ---- */
  function currentItems() {
    var ov = SS.store.get(CONTENT_KEY, {}) || {};
    return SEED
      .filter(function (it) { return !(ov[it.id] && ov[it.id].deleted); })
      .map(function (it) {
        var o = Object.assign({}, it);
        o.status = (ov[it.id] && ov[it.id].status) || "publie";
        return o;
      });
  }

  function patch(id, changes) {
    var ov = SS.store.get(CONTENT_KEY, {}) || {};
    ov[id] = Object.assign({}, ov[id], changes);
    SS.store.set(CONTENT_KEY, ov);
  }

  /* ---- Filtres (chips) : filtrage live ---- */
  function initFilters() {
    var group = document.getElementById("content-filters");
    if (!group) { return; }
    group.addEventListener("click", function (ev) {
      var chip = ev.target.closest(".chip");
      if (!chip) { return; }
      group.querySelectorAll(".chip").forEach(function (c) {
        c.setAttribute("aria-pressed", c === chip ? "true" : "false");
      });
      render(chip.getAttribute("data-filter") || "tous");
    });
  }

  /* ---- Rendu de la liste selon le filtre ---- */
  function render(filter) {
    var box = document.getElementById("contents-list");
    if (!box) { return; }
    closeMenu();
    var e = SS.escapeHtml;
    var items = currentItems();

    /* Aucun contenu du tout : empty state incitatif. */
    if (!items.length) {
      box.innerHTML = '<div class="empty-state"><h3>Aucun contenu publié</h3>' +
        '<p>Partagez les coulisses de votre entreprise pour attirer les bons profils.</p>' +
        '<p><a class="btn btn-accent" href="publier-savoir-faire.html?type=entreprise">+ Créer un contenu</a></p></div>';
      return;
    }

    var shown = filter === "tous" ? items : items.filter(function (it) { return it.cat === filter; });

    /* Catégorie vide : message doux (les autres contenus existent). */
    if (!shown.length) {
      box.innerHTML = '<div class="empty-state"><h3>Aucun contenu dans « ' + e(CAT_LABELS[filter] || filter) + ' »</h3>' +
        '<p>Créez un contenu dans cette catégorie pour enrichir votre marque employeur.</p></div>';
      return;
    }

    box.innerHTML = '<div class="content-grid">' + shown.map(cardHtml).join("") + "</div>";
    wireCards(box);
  }

  function cardHtml(it) {
    var e = SS.escapeHtml;
    var views = EMP.stableViews(it.id);
    var reactions = hashCount(it.id) + 3;
    var isDraft = it.status === "brouillon";
    return '<article class="content-card2" data-id="' + e(it.id) + '">' +
        '<div class="content-thumb content-thumb--' + e(it.cat) + '">' +
          '<span class="content-thumb__type">' + e(it.type.toUpperCase()) + "</span>" +
          (isDraft ? '<span class="content-thumb__draft">Brouillon</span>' : "") +
        "</div>" +
        '<div class="content-card2__body">' +
          '<h3 class="content-card2__title">' + e(it.titre) + "</h3>" +
          '<p class="content-card2__date">Publié ' + e(SS.relativeDate(EMP.dateFromToday(-it.jours))) + "</p>" +
          '<p class="content-card2__stats">' + views + " vues · " + reactions + " réactions</p>" +
        "</div>" +
        '<div class="content-card2__foot">' +
          '<a class="btn btn-outline btn-sm" href="savoir-faire.html">Voir</a>' +
          '<div class="content-menu">' +
            '<button type="button" class="btn btn-ghost btn-sm content-menu__btn" aria-haspopup="menu" aria-expanded="false" aria-label="Actions sur « ' + e(it.titre) + ' »">⋯</button>' +
            '<div class="content-menu__list" role="menu" hidden>' +
              '<button type="button" role="menuitem" data-action="edit">Modifier</button>' +
              '<button type="button" role="menuitem" data-action="toggle">' + (isDraft ? "Republier" : "Dépublier") + "</button>" +
              '<button type="button" role="menuitem" class="content-menu__danger" data-action="delete">Supprimer</button>' +
            "</div>" +
          "</div>" +
        "</div>" +
      "</article>";
  }

  /* ---- Câblage des actions de chaque carte ---- */
  function wireCards(box) {
    box.querySelectorAll(".content-card2").forEach(function (card) {
      var id = card.getAttribute("data-id");
      var menuBtn = card.querySelector(".content-menu__btn");
      var menuList = card.querySelector(".content-menu__list");
      var wrap = card.querySelector(".content-menu");

      menuBtn.addEventListener("click", function (ev) {
        ev.stopPropagation();
        if (openMenu && openMenu.list === menuList) { closeMenu(); return; }
        closeMenu();
        menuList.hidden = false;
        menuBtn.setAttribute("aria-expanded", "true");
        openMenu = { wrap: wrap, list: menuList, btn: menuBtn };
        var first = menuList.querySelector('[role="menuitem"]');
        if (first) { first.focus(); }
      });

      menuList.querySelectorAll("[data-action]").forEach(function (mi) {
        mi.addEventListener("click", function () {
          var action = mi.getAttribute("data-action");
          closeMenu();
          if (action === "edit") {
            SS.toast("Modification du contenu (démonstration).");
          } else if (action === "toggle") {
            var items = currentItems();
            var cur = items.filter(function (x) { return x.id === id; })[0];
            var draft = cur && cur.status === "brouillon";
            patch(id, { status: draft ? "publie" : "brouillon" });
            SS.toast(draft ? "Contenu republié (démonstration)." : "Contenu dépublié (démonstration).");
            reRender();
          } else if (action === "delete") {
            askDelete(id, menuBtn);
          }
        });
      });
    });
  }

  function closeMenu() {
    if (!openMenu) { return; }
    openMenu.list.hidden = true;
    if (openMenu.btn) { openMenu.btn.setAttribute("aria-expanded", "false"); }
    openMenu = null;
  }

  /* ---- Conserver le filtre actif lors d'un nouveau rendu ---- */
  function reRender() {
    var active = document.querySelector('#content-filters .chip[aria-pressed="true"]');
    render(active ? active.getAttribute("data-filter") : "tous");
  }

  /* ---- Suppression : confirmation puis retrait ---- */
  function askDelete(id, returnTo) {
    pendingDelete = id;
    if (deleteModal) { deleteModal.open({ returnTo: returnTo }); }
  }

  function initDeleteModal() {
    var overlay = document.getElementById("content-delete-modal");
    var confirmBtn = document.getElementById("content-delete-confirm");
    if (!overlay || !confirmBtn) { return null; }
    var modal = createModal(overlay);
    confirmBtn.addEventListener("click", function () {
      modal.close();
      if (pendingDelete) {
        patch(pendingDelete, { deleted: true });
        pendingDelete = null;
        SS.toast("Contenu supprimé (démonstration).");
        reRender();
      }
    });
    return modal;
  }

  /* ============================================================
     Fabrique de modale accessible (focus piégé, Échap / overlay).
     ============================================================ */
  function createModal(overlay) {
    var dialog = overlay.querySelector(".modal");
    var returnTo = null;
    function focusables() {
      var sel = 'button:not([disabled]), [href], input:not([disabled]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';
      return Array.prototype.slice.call(dialog.querySelectorAll(sel)).filter(function (el) {
        return el.offsetParent !== null;
      });
    }
    function onKey(ev) {
      if (ev.key === "Escape") { ev.preventDefault(); close(); return; }
      if (ev.key !== "Tab") { return; }
      var f = focusables();
      if (!f.length) { return; }
      var first = f[0], last = f[f.length - 1];
      if (ev.shiftKey && document.activeElement === first) { ev.preventDefault(); last.focus(); }
      else if (!ev.shiftKey && document.activeElement === last) { ev.preventDefault(); first.focus(); }
    }
    function open(opts) {
      opts = opts || {};
      returnTo = opts.returnTo || document.activeElement;
      overlay.hidden = false;
      document.addEventListener("keydown", onKey);
      var f = focusables();
      if (f.length) { f[0].focus(); }
    }
    function close() {
      if (overlay.hidden) { return; }
      overlay.hidden = true;
      document.removeEventListener("keydown", onKey);
      if (returnTo && returnTo.focus && document.body.contains(returnTo) && returnTo.offsetParent !== null) { returnTo.focus(); }
    }
    overlay.addEventListener("click", function (ev) { if (ev.target === overlay) { close(); } });
    overlay.querySelectorAll("[data-close]").forEach(function (b) {
      b.addEventListener("click", function () { close(); });
    });
    return { open: open, close: close };
  }

  /* Petit compteur fictif mais stable, dérivé de l'identifiant. */
  function hashCount(id) {
    var h = 0;
    for (var i = 0; i < id.length; i++) { h = (h * 17 + id.charCodeAt(i)) % 89; }
    return h % 40;
  }
})();

/**
 * Page « offres.html » : filtres, tri, compteur de résultats et pagination,
 * appliqués côté client sur les données JSON locales.
 */
(function () {
  "use strict";

  var PAGE_SIZE = 6;
  var state = { offers: [], filtered: [], page: 1, savedOnly: false };

  function savedIds() {
    return SS.store.get((window.APP_CONFIG && APP_CONFIG.storage && APP_CONFIG.storage.favorites) || "ss_candidate_favorites", []);
  }

  function updateSavedCount() {
    var el = document.getElementById("saved-count");
    if (el) { el.textContent = "(" + savedIds().length + ")"; }
  }

  document.addEventListener("DOMContentLoaded", function () {
    var list = document.getElementById("offers-list");
    if (!list) { return; }

    SS.getActiveOffers()
      .then(SS.decorateOffers)
      .then(function (offers) {
        state.offers = offers;
        prefillFromUrl();
        bindControls();
        applyFilters();
      })
      .catch(function () { SS.dataError(list); });
  });

  /* Reprend les critères passés par le moteur de recherche de l'accueil. */
  function prefillFromUrl() {
    var q = SS.param("q");
    var lieu = SS.param("lieu");
    var categorie = SS.param("categorie");
    if (q) { document.getElementById("filter-keyword").value = q; }
    if (lieu) { document.getElementById("filter-location").value = lieu; }
    if (categorie) {
      var select = document.getElementById("filter-category");
      if (select.querySelector('[value="' + categorie + '"]')) {
        select.value = categorie;
      }
    }
  }

  function bindControls() {
    var form = document.getElementById("filters-form");
    var searchBand = document.getElementById("offers-search-band");

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      applyFilters();
    });
    if (searchBand) {
      searchBand.addEventListener("submit", function (event) {
        event.preventDefault();
        applyFilters();
      });
    }

    /* Filtrage immédiat sur les listes déroulantes et cases à cocher. */
    form.querySelectorAll("select, input[type='checkbox']").forEach(function (input) {
      input.addEventListener("change", applyFilters);
    });

    /* Filtrage au fil de la saisie (léger délai anti-rebond) — les champs
       texte vivent dans la bande de recherche au-dessus de la liste. */
    var timer;
    ["filter-keyword", "filter-location"].forEach(function (id) {
      var input = document.getElementById(id);
      if (!input) { return; }
      input.addEventListener("input", function () {
        clearTimeout(timer);
        timer = setTimeout(applyFilters, 250);
      });
    });

    document.getElementById("filters-reset").addEventListener("click", function () {
      form.reset();
      document.getElementById("filter-keyword").value = "";
      document.getElementById("filter-location").value = "";
      applyFilters();
    });

    document.getElementById("sort-select").addEventListener("change", applyFilters);

    /* Filtre « offres enregistrées » : bascule + compteur vivant. */
    var savedBtn = document.getElementById("saved-filter");
    if (savedBtn) {
      updateSavedCount();
      savedBtn.addEventListener("click", function () {
        state.savedOnly = !state.savedOnly;
        savedBtn.setAttribute("aria-pressed", state.savedOnly ? "true" : "false");
        applyFilters();
      });
      document.addEventListener("ss:saved-changed", function () {
        updateSavedCount();
        if (state.savedOnly) { applyFilters(); }
      });
    }

    /* Repli des filtres sur mobile. */
    var toggle = document.getElementById("filters-toggle");
    var panel = document.getElementById("filters-panel");
    if (toggle && panel) {
      toggle.addEventListener("click", function () {
        var collapsed = panel.classList.toggle("is-collapsed");
        toggle.setAttribute("aria-expanded", collapsed ? "false" : "true");
      });
    }
  }

  function normalize(text) {
    return (text || "").toString().toLowerCase()
      .normalize("NFD").replace(/[\u0300-\u036f]/g, "");
  }

  function applyFilters() {
    var keyword = normalize(document.getElementById("filter-keyword").value);
    var location = normalize(document.getElementById("filter-location").value);
    var category = document.getElementById("filter-category").value;
    var contract = document.getElementById("filter-contract").value;
    var remoteOnly = document.getElementById("filter-remote").checked;
    var salary = document.getElementById("filter-salary").value;
    var recency = document.getElementById("filter-date").value;
    var niveau = document.getElementById("filter-niveau").value;
    var experience = document.getElementById("filter-experience").value;

    var saved = state.savedOnly ? savedIds() : null;

    state.filtered = state.offers.filter(function (o) {
      if (saved && saved.indexOf(o.id) === -1) { return false; }
      if (keyword) {
        var haystack = normalize(o.titre + " " + o.entrepriseNom + " " +
          o.categorieLabel + " " + (o.competences || []).join(" "));
        if (haystack.indexOf(keyword) === -1) { return false; }
      }
      if (location) {
        var place = normalize(o.ville + " " + o.departement);
        if (place.indexOf(location) === -1) { return false; }
      }
      if (category && o.categorie !== category) { return false; }
      if (contract && o.contrat !== contract) { return false; }
      /* Niveau d'étude : une offre « indifférent » convient à tout niveau demandé. */
      if (niveau && o.niveauEtude !== niveau && o.niveauEtude !== "indifferent") { return false; }
      if (experience && o.experience !== experience) { return false; }
      if (remoteOnly && o.teletravail === "non") { return false; }
      if (salary) {
        var min = Number(salary);
        if (!o.salaireAnnuel || o.salaireAnnuel < min) { return false; }
      }
      if (recency) {
        var days = Number(recency);
        var age = (Date.now() - new Date(o.datePublication).getTime()) / 86400000;
        if (age > days) { return false; }
      }
      return true;
    });

    var sort = document.getElementById("sort-select").value;
    if (sort === "date") {
      state.filtered.sort(function (a, b) {
        return new Date(b.datePublication) - new Date(a.datePublication);
      });
    } else if (sort === "salaire") {
      state.filtered.sort(function (a, b) {
        return (b.salaireAnnuel || 0) - (a.salaireAnnuel || 0);
      });
    }
    /* « Pertinence » = ordre du fichier de données, offres à mots-clés en tête. */

    state.page = 1;
    render();
  }

  function render() {
    var list = document.getElementById("offers-list");
    var count = document.getElementById("results-count");
    var total = state.filtered.length;

    count.textContent = total === 0 ? "Aucune offre trouvée"
      : total + (total > 1 ? " offres trouvées" : " offre trouvée");

    if (total === 0) {
      list.innerHTML = state.savedOnly
        ? '<div class="empty-state"><h3>Aucune offre enregistrée</h3>' +
          "<p>Repérez une offre qui vous plaît et cliquez sur le marque-page " +
          "pour la retrouver ici à votre prochaine visite.</p></div>"
        : '<div class="empty-state"><h3>Aucun résultat</h3>' +
          "<p>Essayez d'élargir vos critères : moins de filtres, une ville voisine " +
          "ou un mot-clé plus général.</p></div>";
      renderPagination(0);
      return;
    }

    var start = (state.page - 1) * PAGE_SIZE;
    var pageItems = state.filtered.slice(start, start + PAGE_SIZE);
    list.innerHTML = pageItems.map(SS.offerCard).join("");
    renderPagination(Math.ceil(total / PAGE_SIZE));
  }

  function renderPagination(pages) {
    var nav = document.getElementById("pagination");
    if (pages <= 1) { nav.innerHTML = ""; return; }

    var html = '<button type="button" data-page="prev" ' +
      (state.page === 1 ? "disabled" : "") + ' aria-label="Page précédente">‹</button>';
    for (var i = 1; i <= pages; i++) {
      html += '<button type="button" data-page="' + i + '"' +
        (i === state.page ? ' aria-current="page"' : "") + ">" + i + "</button>";
    }
    html += '<button type="button" data-page="next" ' +
      (state.page === pages ? "disabled" : "") + ' aria-label="Page suivante">›</button>';
    nav.innerHTML = html;

    nav.querySelectorAll("button").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var target = btn.getAttribute("data-page");
        if (target === "prev") { state.page -= 1; }
        else if (target === "next") { state.page += 1; }
        else { state.page = Number(target); }
        render();
        document.getElementById("results-count")
          .scrollIntoView({ behavior: "smooth", block: "start" });
      });
    });
  }
})();




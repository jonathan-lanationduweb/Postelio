/**
 * Page « offres.html » — recherche RÉELLE côté serveur (I2).
 *
 * Les filtres, la pagination et le tri sont désormais délégués au backend
 * (`GET /jobs`, via PostelioDirectory). Aucune donnée JSON locale, aucun repli.
 * Seuls les filtres réellement supportés par le backend sont envoyés ; les contrôles sans
 * équivalent V1 (récence, télétravail-uniquement, tri, « enregistrées ») sont masqués proprement
 * (cf. docs/frontend/jobs-companies-integration.md). Un sélecteur de PROVENANCE est ajouté
 * (Toutes / Postelio / Partenaires).
 */
(function () {
  "use strict";

  var PER_PAGE = 12;
  var state = { page: 1, seq: 0, lastResult: null };

  document.addEventListener("DOMContentLoaded", function () {
    var list = document.getElementById("offers-list");
    if (!list) { return; }
    hideUnsupportedControls();
    ensureSourceSelect();
    bindControls();
    readUrlToInputs();
    runSearch(pageFromUrl());
  });

  /* ---- Filtres non supportés par le backend V1 : masqués (jamais simulés) ---- */
  function hideUnsupportedControls() {
    ["filter-date", "filter-remote", "sort-select"].forEach(function (id) {
      var el = document.getElementById(id);
      var field = el ? (el.closest(".field") || el.closest(".results-sort") || el) : null;
      if (field) { field.hidden = true; }
    });
    var saved = document.getElementById("saved-filter");
    if (saved) { saved.hidden = true; }
    var savedCount = document.getElementById("saved-count");
    if (savedCount) { savedCount.hidden = true; }
  }

  /* ---- Sélecteur de provenance (ajouté) ---- */
  function ensureSourceSelect() {
    if (document.getElementById("filter-source")) { return; }
    var form = document.getElementById("filters-form");
    if (!form) { return; }
    var wrap = document.createElement("div");
    wrap.className = "field";
    wrap.innerHTML =
      '<label for="filter-source">Provenance</label>' +
      '<select id="filter-source">' +
      '<option value="all">Toutes les sources</option>' +
      '<option value="postelio">Offres Postelio</option>' +
      '<option value="partners">Offres partenaires</option>' +
      "</select>";
    form.insertBefore(wrap, form.firstChild);
  }

  function bindControls() {
    var form = document.getElementById("filters-form");
    var searchBand = document.getElementById("offers-search-band");

    function submitHandler(e) { e.preventDefault(); runSearch(1); }
    if (form) { form.addEventListener("submit", submitHandler); }
    if (searchBand) { searchBand.addEventListener("submit", submitHandler); }

    if (form) {
      form.querySelectorAll("select, input[type='checkbox']").forEach(function (input) {
        input.addEventListener("change", function () { runSearch(1); });
      });
    }

    var timer;
    ["filter-keyword", "filter-location"].forEach(function (id) {
      var input = document.getElementById(id);
      if (!input) { return; }
      input.addEventListener("input", function () {
        clearTimeout(timer);
        timer = setTimeout(function () { runSearch(1); }, 300);
      });
    });

    var reset = document.getElementById("filters-reset");
    if (reset) {
      reset.addEventListener("click", function () {
        if (form) { form.reset(); }
        setVal("filter-keyword", ""); setVal("filter-location", "");
        var src = document.getElementById("filter-source"); if (src) { src.value = "all"; }
        runSearch(1);
      });
    }

    var toggle = document.getElementById("filters-toggle");
    var panel = document.getElementById("filters-panel");
    if (toggle && panel) {
      toggle.addEventListener("click", function () {
        var collapsed = panel.classList.toggle("is-collapsed");
        toggle.setAttribute("aria-expanded", collapsed ? "false" : "true");
      });
    }
  }

  function val(id) { var el = document.getElementById(id); return el ? String(el.value || "").trim() : ""; }
  function setVal(id, v) { var el = document.getElementById(id); if (el) { el.value = v; } }

  /* ---- Filtres → paramètres API (uniquement ceux réellement supportés) ---- */
  function currentFilters() {
    var f = {};
    var q = val("filter-keyword"); if (q) { f.q = q; }
    var ville = val("filter-location"); if (ville) { f.ville = ville; }
    var contrat = val("filter-contract"); if (contrat) { f.contrat = contrat; }
    var categorie = val("filter-category"); if (categorie) { f.categorie = categorie; }
    var niveau = val("filter-niveau"); if (niveau) { f.niveau_etude = niveau; }
    var exp = val("filter-experience"); if (exp) { f.experience = exp; }
    var sal = val("filter-salary"); if (sal) { f.salaire_min = sal; }
    var source = val("filter-source"); if (source && source !== "all") { f.source = source; }
    return f;
  }

  /* ---- URL partageable ---- */
  function syncUrl(filters, page) {
    var params = new URLSearchParams();
    Object.keys(filters).forEach(function (k) {
      /* Conserver les noms d'URL historiques pour la recherche d'accueil. */
      if (k === "q") { params.set("q", filters.q); }
      else if (k === "ville") { params.set("lieu", filters.ville); }
      else if (k === "categorie") { params.set("categorie", filters.categorie); }
      else { params.set(k, filters[k]); }
    });
    if (page > 1) { params.set("page", String(page)); }
    var qs = params.toString();
    try { history.replaceState(null, "", qs ? ("?" + qs) : location.pathname); } catch (e) { /* */ }
  }

  function pageFromUrl() {
    var p = parseInt(new URLSearchParams(location.search).get("page"), 10);
    return p > 0 ? p : 1;
  }

  function readUrlToInputs() {
    var p = new URLSearchParams(location.search);
    if (p.get("q")) { setVal("filter-keyword", p.get("q")); }
    if (p.get("lieu")) { setVal("filter-location", p.get("lieu")); }
    setIfOption("filter-category", p.get("categorie"));
    setIfOption("filter-contract", p.get("contrat"));
    setIfOption("filter-niveau", p.get("niveau_etude"));
    setIfOption("filter-experience", p.get("experience"));
    setIfOption("filter-salary", p.get("salaire_min"));
    setIfOption("filter-source", p.get("source"));
  }
  function setIfOption(id, value) {
    if (!value) { return; }
    var el = document.getElementById(id);
    if (el && el.querySelector('[value="' + (window.CSS && CSS.escape ? CSS.escape(value) : value) + '"]')) { el.value = value; }
  }

  /* ---- Recherche serveur ---- */
  function runSearch(page) {
    state.page = page || 1;
    var filters = currentFilters();
    syncUrl(filters, state.page);
    var seq = ++state.seq;
    showLoading();
    window.PostelioDirectory.jobs.search(filters, state.page, PER_PAGE).then(function (res) {
      if (seq !== state.seq) { return; } // réponse périmée ignorée (recherche plus récente en cours)
      state.lastResult = res;
      render(res);
    }, function (err) {
      if (seq !== state.seq) { return; }
      showError(err);
    });
  }

  function showLoading() {
    var list = document.getElementById("offers-list");
    var count = document.getElementById("results-count");
    if (count) { count.textContent = "Chargement des offres…"; }
    if (!list) { return; }
    list.setAttribute("aria-busy", "true");
    var sk = "";
    for (var i = 0; i < 4; i++) {
      sk += '<article class="offer-row" aria-hidden="true" style="opacity:.55">' +
        '<span class="logo-bubble" style="background:#e7ecf2"></span>' +
        '<div style="flex:1"><h3 class="offer-row__title" style="background:#eef2f6;height:1em;width:52%;border-radius:4px">&nbsp;</h3>' +
        '<p class="offer-row__company" style="background:#f1f4f8;height:.8em;width:38%;border-radius:4px;margin-top:.5rem">&nbsp;</p></div>' +
        "</article>";
    }
    list.innerHTML = sk;
  }

  function render(res) {
    var list = document.getElementById("offers-list");
    var count = document.getElementById("results-count");
    list.removeAttribute("aria-busy");

    if (count) {
      if (res.total === 0) { count.textContent = "Aucune offre trouvée"; }
      else if (res.totalIsExact) { count.textContent = res.total + (res.total > 1 ? " offres trouvées" : " offre trouvée"); }
      else { count.textContent = "Plus de " + res.total + " offres"; } // §15 : total approximatif
    }

    if (res.total === 0 || !res.items.length) {
      list.innerHTML =
        '<div class="empty-state"><h3>Aucun résultat</h3>' +
        "<p>Essayez d'élargir vos critères : moins de filtres, une ville voisine ou un mot-clé plus général.</p>" +
        '<p><button type="button" class="btn btn-outline btn-sm" id="offers-reset-empty">Réinitialiser les filtres</button></p></div>';
      var rb = document.getElementById("offers-reset-empty");
      if (rb) { rb.addEventListener("click", function () { var r = document.getElementById("filters-reset"); if (r) { r.click(); } }); }
      renderPagination(res);
      return;
    }

    list.innerHTML = res.items.map(SS.offerCard).join("");
    renderPagination(res);
  }

  function showError(err) {
    var list = document.getElementById("offers-list");
    var count = document.getElementById("results-count");
    if (count) { count.textContent = "Erreur de chargement"; }
    if (!list) { return; }
    list.removeAttribute("aria-busy");
    var msg = (err && err.userMessage) ? err.userMessage() : "Impossible de charger les offres.";
    list.innerHTML =
      '<div class="empty-state" role="alert"><h3>Impossible de charger les offres</h3>' +
      "<p>" + SS.escapeHtml(msg) + "</p>" +
      '<p><button type="button" class="btn btn-primary btn-sm" id="offers-retry">Réessayer</button></p></div>';
    var btn = document.getElementById("offers-retry");
    if (btn) { btn.addEventListener("click", function () { runSearch(state.page); }); }
    var nav = document.getElementById("pagination"); if (nav) { nav.innerHTML = ""; }
  }

  function renderPagination(res) {
    var nav = document.getElementById("pagination");
    if (!nav) { return; }
    var pages = res.totalPages || 1;
    if (pages <= 1) { nav.innerHTML = ""; return; }

    var cur = res.page || state.page;
    var html = '<button type="button" data-page="' + (cur - 1) + '" ' + (cur <= 1 ? "disabled" : "") + ' aria-label="Page précédente">‹</button>';
    /* Fenêtre de pages autour de la page courante pour ne pas exploser l'affichage. */
    var from = Math.max(1, cur - 2), to = Math.min(pages, cur + 2);
    if (from > 1) { html += pageBtn(1, cur) + (from > 2 ? '<span class="pagination__gap">…</span>' : ""); }
    for (var i = from; i <= to; i++) { html += pageBtn(i, cur); }
    if (to < pages) { html += (to < pages - 1 ? '<span class="pagination__gap">…</span>' : "") + pageBtn(pages, cur); }
    html += '<button type="button" data-page="' + (cur + 1) + '" ' + (cur >= pages ? "disabled" : "") + ' aria-label="Page suivante">›</button>';
    nav.innerHTML = html;

    nav.querySelectorAll("button[data-page]").forEach(function (btn) {
      if (btn.disabled) { return; }
      btn.addEventListener("click", function () {
        var target = parseInt(btn.getAttribute("data-page"), 10);
        if (target >= 1) {
          runSearch(target);
          var rc = document.getElementById("results-count");
          if (rc) { rc.scrollIntoView({ behavior: "smooth", block: "start" }); }
        }
      });
    });
  }
  function pageBtn(n, cur) {
    return '<button type="button" data-page="' + n + '"' + (n === cur ? ' aria-current="page"' : "") + ">" + n + "</button>";
  }
})();

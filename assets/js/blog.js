/**
 * Blog : liste avec catégories et recherche, articles récents (accueil),
 * article détaillé, articles associés et inscription newsletter (simulée).
 */
(function () {
  "use strict";

  /* Famille d'icônes SVG « line » (voir .icon dans components.css). */
  function svgIcon(paths) {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" ' +
      'stroke-linecap="round" stroke-linejoin="round">' + paths + "</svg>";
  }
  var ICONS = {
    link: svgIcon('<path d="M9.5 14.5 14.5 9.5"/><path d="M10.5 6.5 12 5a4 4 0 0 1 6 6l-1.5 1.5"/><path d="M13.5 17.5 12 19a4 4 0 0 1-6-6l1.5-1.5"/>'),
    mail: svgIcon('<rect x="2.5" y="4.5" width="19" height="15" rx="2"/><path d="M3 6.5l9 6 9-6"/>'),
    printer: svgIcon('<path d="M6.5 9V3.5h11V9"/><rect x="3.5" y="9" width="17" height="8" rx="2"/><path d="M7 15h10v5.5H7z"/>')
  };
  function iconEl(name) {
    return '<span class="icon" aria-hidden="true">' + (ICONS[name] || "") + "</span>";
  }

  document.addEventListener("DOMContentLoaded", function () {
    renderRecentArticles();
    initBlogList();
    renderArticle();
    initNewsletter();
  });

  function articleCard(article) {
    var e = SS.escapeHtml;
    return '<article class="card article-card">' +
      '<img src="' + e(article.image) + '" alt="' + e(article.imageAlt) + '" loading="lazy">' +
      '<div class="article-card__body">' +
        '<span class="badge badge--accent">' + e(article.categorie) + "</span>" +
        '<h3><a href="article.html?id=' + encodeURIComponent(article.id) + '">' + e(article.titre) + "</a></h3>" +
        "<p>" + e(article.resume) + "</p>" +
        '<div class="article-card__meta">' +
          "<span>" + e(SS.formatDate(article.date)) + "</span>" +
          "<span>" + e(article.tempsLecture) + " de lecture</span>" +
        "</div>" +
      "</div>" +
    "</article>";
  }

  /* Article mis en avant (image + texte côte à côte). */
  function articleFeatured(article) {
    var e = SS.escapeHtml;
    return '<article class="article-featured">' +
      '<img src="' + e(article.image) + '" alt="' + e(article.imageAlt) + '" loading="lazy">' +
      '<div class="article-featured__body">' +
        '<p class="article-meta-line"><span class="cat">' + e(article.categorie) + "</span>" +
          "<span>" + e(SS.formatDate(article.date)) + "</span><span>" + e(article.tempsLecture) + " de lecture</span></p>" +
        '<h3><a href="article.html?id=' + encodeURIComponent(article.id) + '">' + e(article.titre) + "</a></h3>" +
        "<p>" + e(article.resume) + "</p>" +
      "</div>" +
    "</article>";
  }

  /* Article compact (vignette + titre). */
  function articleCompact(article) {
    var e = SS.escapeHtml;
    return '<article class="article-compact">' +
      '<img src="' + e(article.image) + '" alt="' + e(article.imageAlt) + '" loading="lazy">' +
      "<div>" +
        '<p class="article-meta-line"><span class="cat">' + e(article.categorie) + "</span><span>" + e(article.tempsLecture) + "</span></p>" +
        '<h3><a href="article.html?id=' + encodeURIComponent(article.id) + '">' + e(article.titre) + "</a></h3>" +
      "</div>" +
    "</article>";
  }
  SS.articleFeatured = articleFeatured;
  SS.articleCompact = articleCompact;

  /* ---- Accueil : un article vedette + deux compacts ---- */
  function renderRecentArticles() {
    var featuredBox = document.getElementById("home-article-featured");
    var sideBox = document.getElementById("home-articles-side");
    var legacy = document.getElementById("recent-articles");
    if (!featuredBox && !legacy) { return; }
    SS.getArticles().then(function (articles) {
      var recent = articles.slice()
        .sort(function (a, b) { return new Date(b.date) - new Date(a.date); });
      if (featuredBox && sideBox) {
        featuredBox.innerHTML = articleFeatured(recent[0]);
        sideBox.innerHTML = recent.slice(1, 3).map(articleCompact).join("");
      } else if (legacy) {
        legacy.innerHTML = recent.slice(0, 3).map(articleCard).join("");
      }
    }).catch(function () { SS.dataError(featuredBox || legacy); });
  }

  /* ---- Liste du blog ---- */
  /* Badge éditorial déduit du contenu / de la fraîcheur (démo, guide le regard). */
  function articleBadge(a, index, total) {
    var t = (a.titre + " " + a.categorie).toLowerCase();
    if (index === 0) { return { label: "À la une", cls: "badge--accent" }; }
    if (index <= 2) { return { label: "Nouveau", cls: "badge--remote" }; }
    if (/guide|comment|checklist|étapes|etapes/.test(t)) { return { label: "Guide", cls: "" }; }
    return null;
  }

  /* Carte éditoriale premium (réutilise le composant .related-card). */
  function blogCard(a, badge) {
    var e = SS.escapeHtml;
    var url = "article.html?id=" + encodeURIComponent(a.id);
    return '<article class="related-card">' +
      '<span class="related-card__media">' +
        '<img src="' + e(a.image) + '" alt="' + e(a.imageAlt || a.titre) + '" loading="lazy">' +
        (badge ? '<span class="related-card__badge badge ' + badge.cls + '">' + e(badge.label) + "</span>" : "") +
      "</span>" +
      '<div class="related-card__content">' +
        '<span class="related-card__cat">' + e(a.categorie) + "</span>" +
        '<h3 class="related-card__title"><a href="' + url + '">' + e(a.titre) + "</a></h3>" +
        '<p class="related-card__excerpt">' + e(a.resume) + "</p>" +
        '<div class="related-card__footer">' +
          '<p class="related-card__meta">' + e(a.auteur) + " · " + e(a.tempsLecture) + " · " + e(SS.formatDate(a.date)) + "</p>" +
          '<span class="related-card__cta">Lire l\'article <span class="related-card__arrow" aria-hidden="true">→</span></span>' +
        "</div>" +
      "</div>" +
    "</article>";
  }

  /* Article phare : image plein cadre + dégradé + contenu superposé. */
  function blogHero(a) {
    var e = SS.escapeHtml;
    var url = "article.html?id=" + encodeURIComponent(a.id);
    return '<article class="blog-hero__card">' +
      '<span class="blog-hero__media"><img src="' + e(a.image) + '" alt="' + e(a.imageAlt || a.titre) + '"></span>' +
      '<span class="blog-hero__scrim" aria-hidden="true"></span>' +
      '<div class="blog-hero__body">' +
        '<div class="blog-hero__badges"><span class="badge badge--accent">À la une</span><span class="badge blog-hero__cat">' + e(a.categorie) + "</span></div>" +
        '<h2 class="blog-hero__title"><a href="' + url + '">' + e(a.titre) + "</a></h2>" +
        '<p class="blog-hero__resume">' + e(a.resume) + "</p>" +
        '<div class="blog-hero__foot">' +
          '<span class="blog-hero__meta">' + e(a.auteur) + " · " + e(a.tempsLecture) + " · " + e(SS.formatDate(a.date)) + "</span>" +
          '<a class="btn btn-accent" href="' + url + '">Lire l\'article</a>' +
        "</div>" +
      "</div>" +
    "</article>";
  }

  function initBlogList() {
    var grid = document.getElementById("articles-grid");
    if (!grid) { return; }

    SS.getArticles().then(function (articles) {
      var state = { category: "", query: "" };
      var byDate = articles.slice().sort(function (a, b) { return new Date(b.date) - new Date(a.date); });
      var featured = byDate[0];
      var catContainer = document.getElementById("blog-categories");

      /* Article phare (toujours le plus récent). */
      var heroBox = document.getElementById("blog-hero");
      if (heroBox && featured) { heroBox.innerHTML = blogHero(featured); }

      /* Statistiques éditoriales (démonstration). */
      var statsBox = document.getElementById("blog-stats");
      if (statsBox) {
        statsBox.innerHTML = [
          ["+320", "articles & guides"],
          ["+150", "professionnels"],
          ["4,8/5", "note moyenne"],
          ["+25 000", "lecteurs / mois"]
        ].map(function (s) {
          return '<div class="blog-stat"><strong>' + s[0] + "</strong><span>" + s[1] + "</span></div>";
        }).join("");
      }

      /* Filtres pilule à partir des catégories réelles. */
      var categories = [];
      articles.forEach(function (a) {
        if (categories.indexOf(a.categorie) === -1) { categories.push(a.categorie); }
      });
      catContainer.innerHTML =
        '<button type="button" class="blog-filter is-active" data-cat="" aria-pressed="true">Tous les articles</button>' +
        categories.map(function (c) {
          return '<button type="button" class="blog-filter" data-cat="' + SS.escapeHtml(c) + '" aria-pressed="false">' + SS.escapeHtml(c) + "</button>";
        }).join("");

      catContainer.addEventListener("click", function (event) {
        var btn = event.target.closest("button[data-cat]");
        if (!btn) { return; }
        state.category = btn.getAttribute("data-cat");
        catContainer.querySelectorAll("button").forEach(function (b) {
          var on = b === btn;
          b.setAttribute("aria-pressed", on ? "true" : "false");
          b.classList.toggle("is-active", on);
        });
        render();
      });

      /* Recherche premium : saisie + bouton effacer. */
      var searchInput = document.getElementById("blog-search-input");
      var clearBtn = document.getElementById("blog-search-clear");
      var onSearch = function () {
        state.query = searchInput.value.trim().toLowerCase();
        if (clearBtn) { clearBtn.hidden = !searchInput.value; }
        render();
      };
      searchInput.addEventListener("input", onSearch);
      document.getElementById("blog-search-form").addEventListener("submit", function (event) {
        event.preventDefault();
        render();
      });
      if (clearBtn) {
        clearBtn.addEventListener("click", function () {
          searchInput.value = "";
          searchInput.focus();
          onSearch();
        });
      }

      /* Sidebar : les plus consultés + thèmes. */
      var mostRead = document.getElementById("blog-most-read");
      if (mostRead) {
        mostRead.innerHTML = byDate.slice(0, 5).map(function (a) {
          return '<li><a href="article.html?id=' + encodeURIComponent(a.id) + '">' +
            '<span class="blog-side-ranked__cat">' + SS.escapeHtml(a.categorie) + "</span>" +
            SS.escapeHtml(a.titre) + "</a></li>";
        }).join("");
      }
      var sideCats = document.getElementById("blog-side-cats");
      if (sideCats) {
        sideCats.innerHTML = categories.map(function (c) {
          return '<button type="button" class="blog-side-cat" data-jump-cat="' + SS.escapeHtml(c) + '">' +
            SS.escapeHtml(c) + "</button>";
        }).join("");
        sideCats.addEventListener("click", function (event) {
          var b = event.target.closest("[data-jump-cat]");
          if (!b) { return; }
          var target = catContainer.querySelector('[data-cat="' + b.getAttribute("data-jump-cat").replace(/"/g, '\\"') + '"]');
          if (target) { target.click(); }
          document.querySelector(".blog-toolbar").scrollIntoView({ behavior: "smooth", block: "start" });
        });
      }

      function render() {
        var filtered = byDate.filter(function (a) {
          if (state.category && a.categorie !== state.category) { return false; }
          if (state.query) {
            var haystack = (a.titre + " " + a.resume + " " + a.categorie).toLowerCase();
            if (haystack.indexOf(state.query) === -1) { return false; }
          }
          return true;
        });

        /* Sans filtre actif, l'article phare (déjà en hero) n'est pas répété. */
        var noFilter = !state.category && !state.query;
        var list = noFilter ? filtered.filter(function (a) { return a.id !== featured.id; }) : filtered;

        if (!list.length) {
          grid.innerHTML = '<div class="empty-state"><h3>Aucun article trouvé</h3><p>Essayez un autre mot-clé ou une autre catégorie.</p></div>';
          return;
        }

        grid.innerHTML = list.map(function (a, i) {
          return blogCard(a, articleBadge(a, byDate.indexOf(a), byDate.length));
        }).join("");
      }

      render();
    }).catch(function () { SS.dataError(grid); });
  }

  /* ---- Article détaillé ---- */
  function renderArticle() {
    var body = document.getElementById("article-body");
    if (!body) { return; }
    var id = SS.param("id");

    SS.getArticles().then(function (articles) {
      var article = articles.find(function (a) { return a.id === id; }) || articles[0];
      var e = SS.escapeHtml;

      document.title = article.titre + " | Postelio";

      document.getElementById("article-category").textContent = article.categorie;
      document.getElementById("article-title").textContent = article.titre;

      var dek = document.getElementById("article-dek");
      if (dek) { dek.textContent = article.resume || ""; }

      /* Signature : avatar + auteur + date + temps de lecture. */
      var authorInitials = (article.auteur || "SS").replace(/^L'équipe\s+/i, "")
        .split(/\s+/).map(function (w) { return w.charAt(0); }).slice(0, 2).join("").toUpperCase();
      document.getElementById("article-meta").innerHTML =
        '<span class="article-byline__avatar" aria-hidden="true">' + e(authorInitials) + "</span>" +
        '<span class="article-byline__who">' + e(article.auteur) + "</span>" +
        '<span class="article-byline__sep" aria-hidden="true">·</span>' +
        "<span>" + e(SS.formatDate(article.date)) + "</span>" +
        '<span class="article-byline__sep" aria-hidden="true">·</span>' +
        "<span>" + e(article.tempsLecture) + " de lecture</span>";

      /* Image de couverture dans le hero. */
      var cover = document.getElementById("article-cover-img");
      if (cover) { cover.src = article.image; cover.alt = article.imageAlt || ""; }

      /* Corps : contenu HTML issu de notre propre fichier de données. */
      body.innerHTML = article.contenu;

      buildTableOfContents(body);
      setupReadingProgress(body);

      /* Articles associés : même catégorie d'abord, puis les plus récents. */
      var related = articles.filter(function (a) {
        return a.id !== article.id && a.categorie === article.categorie;
      });
      articles.forEach(function (a) {
        if (a.id !== article.id && related.indexOf(a) === -1) { related.push(a); }
      });

      var relatedBox = document.getElementById("related-articles");
      if (relatedBox) {
        relatedBox.innerHTML = related.slice(0, 3).map(relatedCard).join("");
      }

      /* Liste latérale « à lire aussi ». */
      var asideList = document.getElementById("aside-articles");
      if (asideList) {
        asideList.innerHTML = related.slice(0, 4).map(function (a) {
          return '<li><a href="article.html?id=' + encodeURIComponent(a.id) + '">' +
            e(a.titre) + "<span>" + e(a.categorie) + " — " + e(a.tempsLecture) + "</span></a></li>";
        }).join("");
      }

      renderShareBar(article);
      renderArticleAuthor(article, articles);
      injectArticleSchema(article);
    }).catch(function () { SS.dataError(body); });
  }

  /* Slugifie un titre pour en faire une ancre. */
  function slugify(text) {
    return (text || "").toString().toLowerCase()
      .normalize("NFD").replace(/[̀-ͯ]/g, "")
      .replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "").slice(0, 50);
  }

  /* Sommaire auto depuis les <h2> + surlignage au défilement. */
  function buildTableOfContents(body) {
    var card = document.getElementById("article-toc-card");
    var list = document.getElementById("article-toc");
    if (!card || !list) { return; }
    var headings = Array.prototype.slice.call(body.querySelectorAll("h2"));
    if (headings.length < 2) { return; }

    headings.forEach(function (h, i) {
      if (!h.id) { h.id = "sec-" + (slugify(h.textContent) || i); }
    });
    list.innerHTML = headings.map(function (h) {
      return '<li><a href="#' + h.id + '">' + SS.escapeHtml(h.textContent) + "</a></li>";
    }).join("");
    card.hidden = false;

    var links = Array.prototype.slice.call(list.querySelectorAll("a"));
    var spy = function () {
      var mid = window.innerHeight * 0.3;
      var active = 0;
      headings.forEach(function (h, i) {
        if (h.getBoundingClientRect().top <= mid) { active = i; }
      });
      links.forEach(function (a, i) { a.classList.toggle("is-active", i === active); });
    };
    window.addEventListener("scroll", spy, { passive: true });
    spy();
  }

  /* Barre de progression de lecture (proportion de l'article lue). */
  function setupReadingProgress(body) {
    var fill = document.getElementById("reading-progress-fill");
    if (!fill) { return; }
    var update = function () {
      var rect = body.getBoundingClientRect();
      var vh = window.innerHeight;
      var total = rect.height - vh;
      var done = Math.min(Math.max(-rect.top, 0), Math.max(total, 0));
      var pct = total > 0 ? (done / total) * 100 : 0;
      fill.style.width = Math.min(100, Math.max(0, pct)) + "%";
    };
    window.addEventListener("scroll", update, { passive: true });
    window.addEventListener("resize", update);
    update();
  }

  /* Boutons de partage. */
  function renderShareBar(article) {
    var box = document.getElementById("article-share");
    if (!box) { return; }
    var url = window.location.href;
    var enc = encodeURIComponent(url);
    var encTitle = encodeURIComponent(article.titre);
    box.innerHTML =
      '<button type="button" class="article-share__btn" data-copy>' + iconEl("link") + " Copier le lien</button>" +
      '<a class="article-share__btn" target="_blank" rel="noopener" href="https://www.linkedin.com/sharing/share-offsite/?url=' + enc + '"><span aria-hidden="true">in</span> LinkedIn</a>' +
      '<a class="article-share__btn" target="_blank" rel="noopener" href="https://www.facebook.com/sharer/sharer.php?u=' + enc + '"><span aria-hidden="true">f</span> Facebook</a>' +
      '<a class="article-share__btn" href="mailto:?subject=' + encTitle + "&body=" + enc + '">' + iconEl("mail") + " E-mail</a>" +
      '<button type="button" class="article-share__btn" data-print>' + iconEl("printer") + " Imprimer</button>";

    var copyBtn = box.querySelector("[data-copy]");
    if (copyBtn && navigator.clipboard) {
      copyBtn.addEventListener("click", function () {
        navigator.clipboard.writeText(url).then(function () {
          copyBtn.textContent = "✓ Lien copié";
          SS.toast("Lien de l'article copié.");
          setTimeout(function () { copyBtn.innerHTML = iconEl("link") + " Copier le lien"; }, 2500);
        });
      });
    } else if (copyBtn) { copyBtn.hidden = true; }
    var printBtn = box.querySelector("[data-print]");
    if (printBtn) { printBtn.addEventListener("click", function () { window.print(); }); }
  }

  /* Carte auteur + nombre d'articles publiés. */
  function renderArticleAuthor(article, articles) {
    var box = document.getElementById("article-author");
    if (!box) { return; }
    var e = SS.escapeHtml;
    var initials = (article.auteur || "SS").replace(/^L'équipe\s+/i, "")
      .split(/\s+/).map(function (w) { return w.charAt(0); }).slice(0, 2).join("").toUpperCase();
    var count = articles.filter(function (a) { return a.auteur === article.auteur; }).length;
    box.innerHTML =
      '<span class="article-author-card__avatar" aria-hidden="true">' + e(initials) + "</span>" +
      '<p class="article-author-card__name"><strong>' + e(article.auteur) + "</strong></p>" +
      '<p class="article-author-card__role">Rédaction éditoriale · Postelio</p>' +
      '<p class="article-author-card__bio">Des conseils pratiques pour les métiers du secrétariat, de l\'assistanat et de l\'administratif, relus par notre équipe.</p>' +
      '<span class="article-author-card__count">' + count + " article" + (count > 1 ? "s" : "") + " publié" + (count > 1 ? "s" : "") + "</span>";
  }

  /* Carte éditoriale « à lire ensuite » : image plein cadre, catégorie,
     titre, résumé, méta et CTA aligné en bas. Toute la carte est cliquable
     (lien étiré) ; le CTA reste un repère visuel. */
  function relatedCard(a) {
    var e = SS.escapeHtml;
    var url = "article.html?id=" + encodeURIComponent(a.id);
    return '<article class="related-card">' +
      '<span class="related-card__media">' +
        '<img src="' + e(a.image) + '" alt="' + e(a.imageAlt || a.titre) + '" loading="lazy">' +
      "</span>" +
      '<div class="related-card__content">' +
        '<span class="related-card__cat">' + e(a.categorie) + "</span>" +
        '<h3 class="related-card__title"><a href="' + url + '">' + e(a.titre) + "</a></h3>" +
        '<p class="related-card__excerpt">' + e(a.resume) + "</p>" +
        '<div class="related-card__footer">' +
          '<p class="related-card__meta">' + e(a.auteur) + " · " + e(a.tempsLecture) + " · " + e(SS.formatDate(a.date)) + "</p>" +
          '<span class="related-card__cta">Lire l\'article <span class="related-card__arrow" aria-hidden="true">→</span></span>' +
        "</div>" +
      "</div>" +
    "</article>";
  }

  /* Données structurées Article (SEO). */
  function injectArticleSchema(article) {
    var schema = {
      "@context": "https://schema.org",
      "@type": "Article",
      "headline": article.titre,
      "datePublished": article.date,
      "author": { "@type": "Organization", "name": article.auteur },
      "publisher": { "@type": "Organization", "name": "Postelio" },
      "description": article.resume
    };
    var script = document.createElement("script");
    script.type = "application/ld+json";
    script.textContent = JSON.stringify(schema);
    document.head.appendChild(script);
  }

  /* ---- Newsletter (simulée) ---- */
  function initNewsletter() {
    document.querySelectorAll("[data-newsletter-form]").forEach(function (form) {
      form.addEventListener("submit", function (event) {
        event.preventDefault();
        var input = form.querySelector("input[type='email']");
        if (!input.value.trim() || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(input.value.trim())) {
          input.focus();
          return;
        }
        /* Démo : en production, appel à APP_CONFIG.api.endpoints.newsletter. */
        form.innerHTML = '<p class="notice notice--success" role="status">' +
          "Merci ! Votre inscription est bien prise en compte (démonstration : aucun e-mail ne sera envoyé).</p>";
      });
    });
  }
})();

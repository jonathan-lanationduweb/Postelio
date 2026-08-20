/**
 * Espace « Savoir-faire & Avis » : liste avec filtres et tris, fiche
 * détaillée, notation multi-critères, commentaires et signalement.
 * Les notes et commentaires ajoutés sont conservés dans le navigateur
 * (démonstration) — la version WordPress s'appuiera sur de vrais comptes.
 */
(function () {
  "use strict";

  /* Famille d'icônes SVG « line » (voir .icon dans components.css). */
  function svgIcon(paths) {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" ' +
      'stroke-linecap="round" stroke-linejoin="round">' + paths + "</svg>";
  }
  var ICONS = {
    clock: svgIcon('<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>'),
    barChart: svgIcon('<path d="M5 20V11M12 20V4M19 20v-6"/>'),
    eye: svgIcon('<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>'),
    lightbulb: svgIcon('<path d="M9 18h6"/><path d="M10 21h4"/><path d="M12 3a6 6 0 0 0-3.5 10.9c.6.5 1 1.3 1 2.1h5c0-.8.4-1.6 1-2.1A6 6 0 0 0 12 3z"/>'),
    alert: svgIcon('<path d="M12 4 2.5 20h19L12 4z"/><path d="M12 10v4"/><path d="M12 17.4h.01"/>'),
    thumbsUp: svgIcon('<path d="M7 10v10H4V10z"/><path d="M7 10l4.2-7a2 2 0 0 1 2.7 2.6L12.6 9H19a2 2 0 0 1 2 2.3l-1.2 6A2 2 0 0 1 17.8 20H7"/>'),
    check: svgIcon('<circle cx="12" cy="12" r="9"/><path d="M8 12.3l2.7 2.7L16 9.5"/>'),
    bookmark: svgIcon('<path d="M6.5 4h11v16l-5.5-3.7L6.5 20z"/>'),
    bookmarkFilled: '<svg viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M6.5 4h11v16l-5.5-3.7L6.5 20z"/></svg>'
  };
  function iconEl(name, cls) {
    return '<span class="icon' + (cls ? " " + cls : "") + '" aria-hidden="true">' + (ICONS[name] || "") + "</span>";
  }

  var CRITERIA = [
    { key: "clarte", label: "Clarté des explications" },
    { key: "conseils", label: "Utilité des conseils" },
    { key: "facilite", label: "Facilité à suivre les étapes" },
    { key: "resultat", label: "Qualité du résultat" },
    { key: "generale", label: "Note générale" }
  ];

  /* ---- Accès aux données (fichier JSON + publications locales) ---- */
  SS.getKnowhow = function () {
    return SS.loadJSON(APP_CONFIG.data.savoirFaire).then(function (items) {
      var custom = SS.store.get(APP_CONFIG.storage.customKnowhow, []);
      return items.concat(custom);
    });
  };

  /* Notes combinées : moyennes du fichier + votes locaux du navigateur. */
  function combinedRatings(pub) {
    var local = (SS.store.get(APP_CONFIG.storage.knowhowRatings, {})[pub.id] || {});
    var votes = local.votes || [];
    var base = pub.notes || { total: 0, criteres: {}, repartition: { "5": 0, "4": 0, "3": 0, "2": 0, "1": 0 } };
    var total = base.total + votes.length;
    var criteres = {};
    CRITERIA.forEach(function (c) {
      var seedAvg = base.criteres[c.key] || 0;
      var sum = seedAvg * base.total;
      votes.forEach(function (v) { sum += v[c.key] || 0; });
      criteres[c.key] = total ? Math.round((sum / total) * 10) / 10 : 0;
    });
    var repartition = {};
    ["1", "2", "3", "4", "5"].forEach(function (n) {
      repartition[n] = (base.repartition[n] || 0) + votes.filter(function (v) {
        return String(v.generale) === n;
      }).length;
    });
    return { total: total, criteres: criteres, repartition: repartition };
  }

  /* Vues combinées : compteur du fichier + consultations locales. */
  function combinedViews(pub) {
    var local = SS.store.get(APP_CONFIG.storage.knowhowViews, {});
    return (pub.vues || 0) + (local[pub.id] || 0);
  }

  function starsHTML(value) {
    var pct = Math.max(0, Math.min(5, value)) / 5 * 100;
    return '<span class="stars" role="img" aria-label="' + value + ' sur 5">' +
      '<span class="stars-fill" style="width:' + pct + '%"></span></span>';
  }

  function difficultyBadge(pub) {
    return '<span class="badge badge--' + SS.escapeHtml(pub.difficulte) + '">' +
      SS.escapeHtml(pub.difficulteLabel) + "</span>";
  }

  /* Note sobre : un seul caractère étoile, note numérique, nombre d'avis. */
  function ratingNote(pub) {
    var r = combinedRatings(pub);
    if (!r.total) { return '<span class="rating-note">Pas encore d\'avis</span>'; }
    return '<span class="rating-note"><span class="rating-note__star" aria-hidden="true">★</span>' +
      "<strong>" + String(r.criteres.generale || "–").replace(".", ",") + "</strong>" +
      '<span class="rating-note__count">· ' + r.total + " avis</span></span>";
  }
  SS.knowhowRatingNote = ratingNote;

  /* Carte de publication (mosaïque) — aussi utilisée par la fiche entreprise
     et les publications similaires. */
  SS.knowhowCard = function (pub) {
    var e = SS.escapeHtml;
    var pending = pub.statut && pub.statut !== "publie";
    return '<article class="card knowhow-card">' +
      '<img src="' + e(pub.image) + '" alt="' + e(pub.imageAlt || pub.titre) + '" loading="lazy">' +
      '<div class="knowhow-card__body">' +
        '<div class="knowhow-card__badges">' +
          '<span class="badge badge--accent">' + e(pub.metier) + "</span>" +
          (pending ? '<span class="badge badge--moderation">En attente de validation</span>' : "") +
        "</div>" +
        '<h3><a href="savoir-faire-detail.html?id=' + encodeURIComponent(pub.id) + '">' + e(pub.titre) + "</a></h3>" +
        '<p class="knowhow-card__author"><strong>' + e(pub.auteur.nom) + "</strong> · " + e(pub.auteur.metier) + " · " + e(pub.auteur.ville) + "</p>" +
        '<div class="knowhow-card__meta">' +
          "<span>" + e(pub.difficulteLabel) + "</span><span>" + e(pub.duree) + "</span>" +
        "</div>" +
        '<div class="knowhow-card__foot">' +
          ratingNote(pub) +
          '<span class="link-more" aria-hidden="true">Voir les étapes</span>' +
        "</div>" +
      "</div>" +
    "</article>";
  };

  /* Publication vedette (grande image + signature du professionnel). */
  SS.knowhowFeatured = function (pub) {
    var e = SS.escapeHtml;
    var initials = (pub.auteur.nom || "?").split(/\s+/).map(function (w) { return w.charAt(0); }).slice(0, 2).join("").toUpperCase();
    return '<article class="kh-featured">' +
      '<img src="' + e(pub.image) + '" alt="' + e(pub.imageAlt || pub.titre) + '" loading="lazy">' +
      '<div class="kh-featured__body">' +
        '<p class="kh-featured__label">À la une · ' + e(pub.categorie) + "</p>" +
        '<h3><a href="savoir-faire-detail.html?id=' + encodeURIComponent(pub.id) + '">' + e(pub.titre) + "</a></h3>" +
        "<p>" + e(pub.resume) + "</p>" +
        '<div class="kh-featured__signature">' +
          '<span class="avatar" aria-hidden="true">' + e(initials) + "</span>" +
          "<div><strong>" + e(pub.auteur.nom) + "</strong><span>" + e(pub.auteur.metier) +
            (pub.auteur.entreprise ? " · " + e(pub.auteur.entreprise) : "") + " · " + e(pub.auteur.ville) + "</span></div>" +
        "</div>" +
        '<div class="kh-featured__meta">' +
          "<span>" + e(pub.difficulteLabel) + "</span><span>" + e(pub.duree) + "</span>" + ratingNote(pub) +
        "</div>" +
      "</div>" +
    "</article>";
  };

  /* Rangée compacte (suite de la liste). */
  SS.knowhowRow = function (pub) {
    var e = SS.escapeHtml;
    var pending = pub.statut && pub.statut !== "publie";
    return '<article class="kh-row">' +
      "<div>" +
        '<h3><a href="savoir-faire-detail.html?id=' + encodeURIComponent(pub.id) + '">' + e(pub.titre) + "</a>" +
        (pending ? ' <span class="badge badge--moderation">En attente de validation</span>' : "") + "</h3>" +
        '<p class="kh-row__meta">' + e(pub.auteur.nom) + " · " + e(pub.metier) + " · " + e(pub.difficulteLabel) + " · " + e(pub.duree) + "</p>" +
      "</div>" +
      '<div class="kh-row__side">' + ratingNote(pub) + "</div>" +
    "</article>";
  };

  document.addEventListener("DOMContentLoaded", function () {
    initList();
    initDetail();
  });

  /* ================= LISTE ================= */
  function initList() {
    var grid = document.getElementById("knowhow-grid");
    if (!grid) { return; }

    SS.getKnowhow().then(function (items) {
      var form = document.getElementById("knowhow-filters");
      var metierSelect = document.getElementById("kh-metier");
      var catSelect = document.getElementById("kh-categorie");

      /* Alimente les filtres métier et catégorie depuis les données. */
      var metiers = [], categories = [];
      items.forEach(function (p) {
        if (p.metier && metiers.indexOf(p.metier) === -1) { metiers.push(p.metier); }
        if (p.categorie && categories.indexOf(p.categorie) === -1) { categories.push(p.categorie); }
      });
      metiers.sort().forEach(function (m) { addOption(metierSelect, m); });
      categories.sort().forEach(function (c) { addOption(catSelect, c); });

      function apply() {
        var q = normalize(document.getElementById("kh-search").value);
        var metier = metierSelect.value;
        var cat = catSelect.value;
        var diff = document.getElementById("kh-difficulte").value;
        var minNote = Number(document.getElementById("kh-note").value || 0);
        var sort = document.getElementById("kh-sort").value;

        var filtered = items.filter(function (p) {
          var r = combinedRatings(p);
          if (q) {
            var hay = normalize(p.titre + " " + p.resume + " " + p.metier + " " +
              p.auteur.nom + " " + (p.auteur.entreprise || "") + " " + p.auteur.ville);
            if (hay.indexOf(q) === -1) { return false; }
          }
          if (metier && p.metier !== metier) { return false; }
          if (cat && p.categorie !== cat) { return false; }
          if (diff && p.difficulte !== diff) { return false; }
          if (minNote && (r.criteres.generale || 0) < minNote) { return false; }
          return true;
        });

        if (sort === "note") {
          filtered.sort(function (a, b) {
            return (combinedRatings(b).criteres.generale || 0) - (combinedRatings(a).criteres.generale || 0);
          });
        } else if (sort === "vues") {
          filtered.sort(function (a, b) { return combinedViews(b) - combinedViews(a); });
        } else {
          filtered.sort(function (a, b) {
            return new Date(b.datePublication) - new Date(a.datePublication);
          });
        }

        var count = document.getElementById("kh-count");
        count.textContent = filtered.length === 0 ? "Aucune publication trouvée"
          : filtered.length + (filtered.length > 1 ? " publications" : " publication");

        if (!filtered.length) {
          grid.innerHTML = '<div class="empty-state"><h3>Aucun résultat</h3><p>Élargissez vos critères ou effacez les filtres.</p></div>';
          return;
        }

        /* Composition magazine : une vedette, trois cartes, le reste en liste. */
        var featured = filtered[0];
        var cards = filtered.slice(1, 4);
        var rest = filtered.slice(4);
        grid.innerHTML = SS.knowhowFeatured(featured) +
          (cards.length ? '<div class="knowhow-mosaic">' + cards.map(SS.knowhowCard).join("") + "</div>" : "") +
          (rest.length ? '<div class="knowhow-rows">' + rest.map(SS.knowhowRow).join("") + "</div>" : "");
      }

      form.addEventListener("submit", function (event) { event.preventDefault(); apply(); });
      form.querySelectorAll("select").forEach(function (s) { s.addEventListener("change", apply); });
      document.getElementById("kh-sort").addEventListener("change", apply);
      var timer;
      document.getElementById("kh-search").addEventListener("input", function () {
        clearTimeout(timer);
        timer = setTimeout(apply, 250);
      });
      document.getElementById("kh-reset").addEventListener("click", function () {
        form.reset();
        apply();
      });

      apply();
    }).catch(function () { SS.dataError(grid); });
  }

  function addOption(select, value) {
    var option = document.createElement("option");
    option.value = value;
    option.textContent = value;
    select.appendChild(option);
  }

  function normalize(text) {
    return (text || "").toString().toLowerCase()
      .normalize("NFD").replace(/[\u0300-\u036f]/g, "");
  }

  /* ================= FICHE DÉTAILLÉE ================= */
  function initDetail() {
    var root = document.getElementById("knowhow-detail");
    if (!root) { return; }
    var id = SS.param("id");

    SS.getKnowhow().then(function (items) {
      var pub = items.find(function (p) { return p.id === id; }) || items[0];
      trackView(pub);
      renderDetail(pub);
      renderRatings(pub);
      setupRatingForm(pub);
      renderComments(pub);
      setupCommentForm(pub);
      setupShare(pub);
      setupReport(pub);
      setupEngagement(pub);
      renderSimilar(pub, items);
      setupProgress();
      setupCarousel();
    }).catch(function () { SS.dataError(root.querySelector(".container") || root); });
  }

  /* ---- Engagement : « J'ai testé » (compteur) + Sauvegarder (favori) ---- */
  function setupEngagement(pub) {
    var testedBtn = document.getElementById("kh-tested");
    var countEl = document.getElementById("kh-tested-count");
    if (testedBtn && countEl) {
      var TESTED_KEY = "ss_kh_tested";
      var base = Math.max(3, Math.round(combinedViews(pub) * 0.4));
      var store = SS.store.get(TESTED_KEY, {});
      var showCount = function () {
        var mine = store[pub.id] ? 1 : 0;
        countEl.innerHTML = "<strong>" + (base + mine) + "</strong> personnes ont testé cette méthode";
      };
      var lock = function () {
        testedBtn.setAttribute("aria-pressed", "true");
        testedBtn.classList.add("is-active");
        testedBtn.innerHTML = iconEl("check") + " Vous avez testé cette méthode";
        testedBtn.disabled = true;
      };
      showCount();
      if (store[pub.id]) { lock(); }
      testedBtn.addEventListener("click", function () {
        store = SS.store.get(TESTED_KEY, {});
        store[pub.id] = true;
        SS.store.set(TESTED_KEY, store);
        showCount();
        lock();
        SS.toast("Merci d'avoir partagé que vous avez testé cette méthode !");
      });
    }

    var saveBtn = document.getElementById("kh-save");
    if (saveBtn) {
      var SAVE_KEY = "ss_kh_saved";
      var label = saveBtn.querySelector(".kh-save__label");
      var icon = saveBtn.querySelector(".kh-save__icon");
      var sync = function () {
        var list = SS.store.get(SAVE_KEY, []);
        var on = list.indexOf(pub.id) !== -1;
        saveBtn.classList.toggle("is-on", on);
        saveBtn.setAttribute("aria-pressed", on ? "true" : "false");
        if (label) { label.textContent = on ? "Enregistré" : "Sauvegarder"; }
        if (icon) { icon.innerHTML = on ? ICONS.bookmarkFilled : ICONS.bookmark; }
      };
      sync();
      saveBtn.addEventListener("click", function () {
        var list = SS.store.get(SAVE_KEY, []);
        var i = list.indexOf(pub.id);
        if (i === -1) { list.push(pub.id); } else { list.splice(i, 1); }
        SS.store.set(SAVE_KEY, list);
        sync();
        SS.toast(i === -1 ? "Tutoriel sauvegardé." : "Tutoriel retiré de vos favoris.");
      });
    }
  }

  /* ---- Barre de progression sticky + surlignage de section ---- */
  function setupProgress() {
    var nav = document.getElementById("kh-progress");
    var fill = document.getElementById("kh-progress-fill");
    if (!nav) { return; }
    var links = Array.prototype.slice.call(nav.querySelectorAll("[data-progress-link]"));
    var targets = links.map(function (a) {
      return document.querySelector(a.getAttribute("href"));
    });

    var update = function () {
      var doc = document.documentElement;
      var scrollable = doc.scrollHeight - doc.clientHeight;
      var pct = scrollable > 0 ? Math.min(100, Math.max(0, doc.scrollTop / scrollable * 100)) : 0;
      if (fill) { fill.style.width = pct + "%"; }

      /* Section active : la dernière dont le haut a dépassé le milieu de l'écran. */
      var mid = window.innerHeight * 0.35;
      var activeIndex = 0;
      targets.forEach(function (t, i) {
        if (t && t.getBoundingClientRect().top <= mid) { activeIndex = i; }
      });
      links.forEach(function (a, i) {
        a.classList.toggle("is-active", i === activeIndex);
      });
    };

    window.addEventListener("scroll", update, { passive: true });
    window.addEventListener("resize", update);
    update();
  }

  /* ---- Carrousel des publications similaires ---- */
  function setupCarousel() {
    var track = document.getElementById("kh-similar");
    if (!track) { return; }
    var prev = document.querySelector("[data-carousel-prev]");
    var next = document.querySelector("[data-carousel-next]");
    var step = function () {
      var first = track.firstElementChild;
      return first ? first.getBoundingClientRect().width + 24 : 320;
    };
    if (prev) { prev.addEventListener("click", function () { track.scrollBy({ left: -step(), behavior: "smooth" }); }); }
    if (next) { next.addEventListener("click", function () { track.scrollBy({ left: step(), behavior: "smooth" }); }); }
  }

  function trackView(pub) {
    var views = SS.store.get(APP_CONFIG.storage.knowhowViews, {});
    views[pub.id] = (views[pub.id] || 0) + 1;
    SS.store.set(APP_CONFIG.storage.knowhowViews, views);
  }

  function difficultyLevel(pub) {
    var d = (pub.difficulte || "").toLowerCase();
    if (/debut|facile/.test(d)) { return 1; }
    if (/confirm|avance|expert/.test(d)) { return 3; }
    return 2;
  }

  function renderDetail(pub) {
    var e = SS.escapeHtml;
    var r = combinedRatings(pub);
    var views = combinedViews(pub);
    document.title = pub.titre + " | Savoir-faire Postelio";

    setText("kh-title", pub.titre);
    setText("kh-hero-resume", pub.resume);
    setText("kh-intro", pub.intro);
    setText("kh-result-text", pub.resultat);

    /* Image du hero. */
    var heroImg = document.getElementById("kh-hero-img");
    if (heroImg) { heroImg.src = pub.image; heroImg.alt = ""; }

    var badges = document.getElementById("kh-badges");
    badges.innerHTML = '<span class="badge badge--accent">' + e(pub.categorie) + "</span>" +
      '<span class="badge">' + e(pub.metier) + "</span>" + difficultyBadge(pub) +
      (pub.statut && pub.statut !== "publie" ? '<span class="badge badge--moderation">En attente de validation</span>' : "");

    /* Faits clés du hero. */
    var facts = document.getElementById("kh-hero-facts");
    if (facts) {
      var noteHtml = r.total
        ? '<li><span class="kh-fact__star" aria-hidden="true">★</span>' + String(r.criteres.generale).replace(".", ",") + " · " + r.total + " avis</li>"
        : "<li>Pas encore d'avis</li>";
      facts.innerHTML =
        "<li>" + iconEl("clock") + e(pub.duree) + "</li>" +
        "<li>" + iconEl("barChart") + e(pub.difficulteLabel) + "</li>" +
        noteHtml +
        "<li>" + iconEl("eye") + views + " vues</li>";
    }

    /* Auteur (dans le hero). */
    var initials = (pub.auteur.nom || "?").split(/\s+/).map(function (w) { return w.charAt(0); }).slice(0, 2).join("").toUpperCase();
    var authorBox = document.getElementById("kh-author");
    var companyPart = pub.auteur.entreprise
      ? (pub.auteur.entrepriseId
        ? '<a href="entreprise-detail.html?id=' + encodeURIComponent(pub.auteur.entrepriseId) + '">' + e(pub.auteur.entreprise) + "</a>"
        : e(pub.auteur.entreprise))
      : "";
    authorBox.innerHTML =
      '<span class="logo-bubble" aria-hidden="true">' + e(initials) + "</span>" +
      "<p><strong>" + e(pub.auteur.nom) + "</strong>" +
      e(pub.auteur.metier) + (companyPart ? " · " + companyPart : "") + " · " + e(pub.auteur.ville) + "</p>";

    /* Statistiques (démo : quelques indicateurs dérivés du nombre de vues). */
    var stats = document.getElementById("kh-stats");
    if (stats) {
      stats.innerHTML = [
        khStat(views, "vues"),
        khStat(Math.round(views * 0.22), "sauvegardes"),
        khStat(Math.round(views * 0.06), "partages"),
        khStat(r.total, "ont terminé"),
        khStat(pub.duree, "temps moyen")
      ].join("");
    }

    /* Informations pratiques : durée en mini-timeline + jauge de difficulté. */
    var info = document.getElementById("kh-infos");
    if (info) {
      var lvl = difficultyLevel(pub);
      var segs = [1, 2, 3].map(function (n) {
        return '<span class="kh-gauge__seg' + (n <= lvl ? " is-on" : "") + '"></span>';
      }).join("");
      var duree =
        '<div class="kh-duree">' +
          (pub.dureeDetail || []).map(function (d) {
            return '<div class="kh-duree__row"><span>' + e(d.label) + "</span><strong>" + e(d.valeur) + "</strong></div>";
          }).join("") +
          '<div class="kh-duree__total"><span>Total</span><span>' + e(pub.duree) + "</span></div>" +
        "</div>";
      info.innerHTML =
        "<div><dt>Difficulté</dt><dd><span class=\"kh-gauge__label\">" + e(pub.difficulteLabel) + "</span>" +
          '<span class="kh-gauge" aria-hidden="true">' + segs + "</span></dd></div>" +
        "<div><dt>Temps détaillé</dt><dd>" + duree + "</dd></div>";
    }

    fillList("kh-materials", pub.materiel);

    /* Conseils et erreurs en blocs callout. */
    renderCallouts("kh-tips", pub.conseils, ICONS.lightbulb);
    renderCallouts("kh-mistakes", pub.erreurs, ICONS.alert);

    /* Étapes en timeline. */
    var steps = document.getElementById("kh-steps");
    steps.innerHTML = (pub.etapes || []).map(function (step) {
      return '<li class="kh-timeline__item"><div class="kh-timeline__card">' +
        "<h3>" + e(step.titre) + "</h3>" +
        "<p>" + e(step.texte) + "</p>" +
        (step.image && /\.(webp|jpg|jpeg|png)$/i.test(step.image)
          ? '<img src="' + e(step.image) + '" alt="Illustration : ' + e(step.titre) + '" loading="lazy">' : "") +
        (step.conseil
          ? '<p class="kh-step-tip"><span class="kh-step-tip__icon icon" aria-hidden="true">' + ICONS.lightbulb + "</span><span><strong>Astuce du pro : </strong>" + e(step.conseil) + "</span></p>"
          : "") +
      "</div></li>";
    }).join("");

    /* Galerie cliquable (lightbox). */
    var gallery = document.getElementById("kh-gallery");
    var galItems = (pub.galerie || []).filter(function (g) {
      return /\.(webp|jpg|jpeg|png)$/i.test(g.image);
    });
    if (galItems.length) {
      gallery.innerHTML = galItems.map(function (g) {
        return '<button type="button" data-lightbox="' + e(g.image) + '" data-caption="' + e(g.alt) + '">' +
          '<img src="' + e(g.image) + '" alt="' + e(g.alt) + '" loading="lazy"></button>';
      }).join("");
      setupLightbox(gallery);
    } else {
      gallery.closest("section").hidden = true;
    }

    /* Carte professionnel enrichie (colonne latérale). */
    renderProCard(pub, initials, r);
  }

  function khStat(value, label) {
    return '<div class="kh-stat"><strong>' + SS.escapeHtml(String(value)) + "</strong><span>" + SS.escapeHtml(label) + "</span></div>";
  }

  function renderCallouts(id, items, iconSvg) {
    var el = document.getElementById(id);
    if (!el) { return; }
    if (!items || !items.length) { el.closest("section").hidden = true; return; }
    el.innerHTML = items.map(function (it) {
      return '<div class="kh-callout"><span class="kh-callout__icon icon" aria-hidden="true">' + iconSvg +
        "</span><span>" + SS.escapeHtml(it) + "</span></div>";
    }).join("");
  }

  function renderProCard(pub, initials, r) {
    var e = SS.escapeHtml;
    var box = document.getElementById("kh-pro-card");
    if (!box) { return; }
    /* Stats professionnel dérivées (démo). */
    var tutos = 1 + (pub.id ? pub.id.length % 4 : 1);
    box.innerHTML =
      '<span class="kh-pro__avatar" aria-hidden="true">' + e(initials) + "</span>" +
      '<p class="kh-pro__name"><strong>' + e(pub.auteur.nom) + "</strong></p>" +
      '<p class="kh-pro__role">' + e(pub.auteur.metier) +
        (pub.auteur.entreprise ? " · " + e(pub.auteur.entreprise) : "") + "<br>" + e(pub.auteur.ville) + "</p>" +
      '<span class="kh-pro__verified">✓ Expert vérifié</span>' +
      '<div class="kh-pro__stats">' +
        "<div><strong>" + tutos + "</strong><span>tutoriels</span></div>" +
        "<div><strong>" + r.total + "</strong><span>avis</span></div>" +
        "<div><strong>" + (r.criteres.generale ? String(r.criteres.generale).replace(".", ",") : "–") + "</strong><span>note</span></div>" +
      "</div>" +
      (pub.auteur.entrepriseId
        ? '<a class="btn btn-outline btn-sm btn-block" href="entreprise-detail.html?id=' +
          encodeURIComponent(pub.auteur.entrepriseId) + '">Voir son profil</a>'
        : '<p class="text-muted">Profil non référencé dans l\'annuaire (démonstration).</p>');
  }

  /* ---- Lightbox galerie ---- */
  function setupLightbox(container) {
    var dialog = document.getElementById("kh-lightbox");
    if (!dialog) { return; }
    var img = document.getElementById("kh-lightbox-img");
    var cap = document.getElementById("kh-lightbox-caption");
    container.addEventListener("click", function (event) {
      var btn = event.target.closest("[data-lightbox]");
      if (!btn) { return; }
      img.src = btn.getAttribute("data-lightbox");
      img.alt = btn.getAttribute("data-caption") || "";
      if (cap) { cap.textContent = btn.getAttribute("data-caption") || ""; }
      SS.openModal(dialog);
    });
  }

  function setText(id, value) {
    var el = document.getElementById(id);
    if (el) { el.textContent = value; }
  }

  function fillList(id, items) {
    var el = document.getElementById(id);
    if (el && items) {
      el.innerHTML = items.map(function (item) {
        return "<li>" + SS.escapeHtml(item) + "</li>";
      }).join("");
    }
  }

  /* ---- Synthèse des notes ---- */
  function renderRatings(pub) {
    var r = combinedRatings(pub);
    var e = SS.escapeHtml;

    document.getElementById("rating-average").innerHTML =
      '<span class="big">' + (r.criteres.generale || "–") + "</span>" +
      "<div>" + starsHTML(r.criteres.generale || 0) +
      '<p class="text-muted">' + r.total + " évaluation" + (r.total > 1 ? "s" : "") + "</p></div>";

    document.getElementById("rating-criteria").innerHTML = CRITERIA
      .filter(function (c) { return c.key !== "generale"; })
      .map(function (c) {
        var value = r.criteres[c.key] || 0;
        return '<div class="crit-row"><span>' + e(c.label) + "</span>" +
          starsHTML(value) + "<strong>" + value + "</strong></div>";
      }).join("");

    var max = Math.max.apply(null, ["1", "2", "3", "4", "5"].map(function (n) { return r.repartition[n]; }).concat([1]));
    document.getElementById("rating-distribution").innerHTML = ["5", "4", "3", "2", "1"].map(function (n) {
      var count = r.repartition[n];
      var pct = Math.round(count / max * 100);
      return '<div class="dist-row"><span>' + n + " étoile" + (n !== "1" ? "s" : "") + "</span>" +
        '<div class="dist-bar"><span style="width:' + pct + '%"></span></div>' +
        "<span>" + count + "</span></div>";
    }).join("");

    /* En-tête de page : note moyenne dans le hero. */
    var heroRating = document.getElementById("kh-hero-rating");
    if (heroRating) {
      heroRating.innerHTML = starsHTML(r.criteres.generale || 0) +
        "<strong>" + (r.criteres.generale || "–") + "</strong> <span>(" + r.total + " avis)</span>";
    }
  }

  /* ---- Formulaire de notation avec anti double-clic ---- */
  function setupRatingForm(pub) {
    var form = document.getElementById("rating-form");
    if (!form) { return; }

    var ratings = SS.store.get(APP_CONFIG.storage.knowhowRatings, {});
    var mine = ratings[pub.id];

    if (mine && mine.hasVoted) {
      lockRatingForm(form, "Vous avez déjà noté cette publication depuis ce navigateur. Merci pour votre contribution !");
      return;
    }

    var submitting = false;
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      if (submitting) { return; } /* anti double-clic */
      var vote = {};
      var missing = false;
      CRITERIA.forEach(function (c) {
        var checked = form.querySelector('input[name="' + c.key + '"]:checked');
        if (!checked) { missing = true; } else { vote[c.key] = Number(checked.value); }
      });
      var errorBox = document.getElementById("rating-error");
      if (missing) {
        errorBox.hidden = false;
        return;
      }
      errorBox.hidden = true;
      submitting = true;
      var btn = form.querySelector("button[type=submit]");
      btn.disabled = true;
      btn.textContent = "Enregistrement…";

      /* Petit délai simulant l'appel serveur + verrou anti-spam. */
      setTimeout(function () {
        var store = SS.store.get(APP_CONFIG.storage.knowhowRatings, {});
        store[pub.id] = store[pub.id] || { votes: [] };
        store[pub.id].votes.push(vote);
        store[pub.id].hasVoted = true;
        store[pub.id].date = new Date().toISOString();
        SS.store.set(APP_CONFIG.storage.knowhowRatings, store);
        renderRatings(pub);
        lockRatingForm(form, "Merci ! Votre note a bien été enregistrée (démonstration : elle n'est conservée que dans votre navigateur).");
      }, 600);
    });
  }

  function lockRatingForm(form, message) {
    form.innerHTML = '<p class="notice notice--success" role="status">' + SS.escapeHtml(message) + "</p>" +
      '<p class="text-muted">Dans la version WordPress, un compte utilisateur sera nécessaire pour noter — une seule note par personne.</p>';
  }

  /* ---- Commentaires ---- */
  function commentHTML(c) {
    var e = SS.escapeHtml;
    var initials = (c.auteur || "?").split(/\s+/).map(function (w) { return w.charAt(0); }).slice(0, 2).join("").toUpperCase();
    /* Nombre « utile » stable par commentaire (démo). */
    var useful = 1 + ((c.texte || "").length % 12);
    return '<article class="card comment-card">' +
      '<div class="comment-card__head">' +
        '<div class="comment-card__id">' +
          '<span class="comment-avatar" aria-hidden="true">' + e(initials) + "</span>" +
          '<div><span class="who">' + e(c.auteur) + "</span> " +
          (c.teste ? '<span class="badge badge--remote">Méthode testée</span>' : "") +
          '<br><span class="when">' + e(SS.formatDate(c.date)) + "</span></div>" +
        "</div>" +
        '<div class="rating-inline">' + starsHTML(c.note) + "<strong>" + c.note + "/5</strong></div>" +
      "</div>" +
      (c.titre ? "<h3>" + e(c.titre) + "</h3>" : "") +
      "<p>" + e(c.texte) + "</p>" +
      (c.resultat ? '<p class="comment-result">Résultat obtenu : ' + e(c.resultat) + "</p>" : "") +
      (c.reponsePro ? '<div class="pro-reply"><strong>Réponse du professionnel :</strong> ' + e(c.reponsePro) + "</div>" : "") +
      '<button type="button" class="comment-useful" data-useful>' + iconEl("thumbsUp") + ' Utile · <span class="comment-useful__n">' + useful + "</span></button>" +
    "</article>";
  }

  function renderComments(pub) {
    var list = document.getElementById("comments-list");
    if (!list) { return; }
    var local = (SS.store.get(APP_CONFIG.storage.knowhowComments, {})[pub.id] || []);
    var all = (pub.commentaires || []).concat(local);
    document.getElementById("comments-count").textContent =
      all.length + " retour" + (all.length > 1 ? "s" : "") + " d'expérience";
    list.innerHTML = all.length
      ? all.map(commentHTML).join("")
      : '<div class="empty-state"><h3>Aucun retour pour le moment</h3><p>Soyez la première personne à partager votre expérience.</p></div>';

    /* Bouton « Utile » : bascule visuelle (démo). */
    list.querySelectorAll("[data-useful]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var n = btn.querySelector(".comment-useful__n");
        var on = btn.classList.toggle("is-on");
        if (n) { n.textContent = Number(n.textContent) + (on ? 1 : -1); }
      });
    });
  }

  function setupCommentForm(pub) {
    var form = document.getElementById("comment-form");
    if (!form) { return; }
    var submitting = false;

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      if (submitting) { return; }
      if (!SS.validateForm(form)) { return; }
      var noteInput = form.querySelector('input[name="comment-note"]:checked');
      var noteError = document.getElementById("comment-note-error");
      if (!noteInput) {
        noteError.hidden = false;
        return;
      }
      noteError.hidden = true;
      submitting = true;

      var comment = {
        auteur: document.getElementById("comment-name").value.trim(),
        date: new Date().toISOString().slice(0, 10),
        note: Number(noteInput.value),
        titre: document.getElementById("comment-title").value.trim(),
        texte: document.getElementById("comment-text").value.trim(),
        resultat: document.getElementById("comment-outcome").value.trim() || null,
        teste: document.getElementById("comment-tested").checked,
        reponsePro: null
      };

      setTimeout(function () {
        var store = SS.store.get(APP_CONFIG.storage.knowhowComments, {});
        store[pub.id] = store[pub.id] || [];
        store[pub.id].push(comment);
        SS.store.set(APP_CONFIG.storage.knowhowComments, store);
        renderComments(pub);
        form.hidden = true;
        document.getElementById("comment-success").hidden = false;
      }, 400);
    });
  }

  /* ---- Partage ---- */
  function setupShare(pub) {
    var btn = document.getElementById("kh-share");
    if (!btn) { return; }
    btn.addEventListener("click", function () {
      var payload = {
        title: pub.titre + " — Savoir-faire Postelio",
        text: pub.resume,
        url: window.location.href
      };
      if (navigator.share) {
        navigator.share(payload).catch(function () { /* partage annulé */ });
      } else if (navigator.clipboard) {
        navigator.clipboard.writeText(window.location.href).then(function () {
          btn.textContent = "Lien copié !";
          setTimeout(function () { btn.textContent = "Partager"; }, 2500);
        });
      }
    });
  }

  /* ---- Signalement (modale) ---- */
  function setupReport(pub) {
    var dialog = document.getElementById("report-modal");
    var openBtn = document.getElementById("kh-report");
    if (!dialog || !openBtn) { return; }

    openBtn.addEventListener("click", function () { SS.openModal(dialog); });

    var form = dialog.querySelector("form");
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      var reason = form.querySelector('input[name="report-reason"]:checked');
      var error = dialog.querySelector(".field-error");
      if (!reason) {
        error.hidden = false;
        return;
      }
      error.hidden = true;
      var reports = SS.store.get(APP_CONFIG.storage.knowhowReports, []);
      reports.push({ publication: pub.id, motif: reason.value, date: new Date().toISOString() });
      SS.store.set(APP_CONFIG.storage.knowhowReports, reports);
      form.hidden = true;
      dialog.querySelector(".report-success").hidden = false;
    });
  }

  /* ---- Publications similaires ---- */
  function renderSimilar(pub, items) {
    var container = document.getElementById("kh-similar");
    if (!container) { return; }
    var similar = items.filter(function (p) {
      return p.id !== pub.id &&
        (p.categorie === pub.categorie || p.metier === pub.metier);
    });
    items.forEach(function (p) {
      if (p.id !== pub.id && similar.indexOf(p) === -1) { similar.push(p); }
    });
    container.innerHTML = similar.slice(0, 3).map(SS.knowhowCard).join("");
  }
})();

/**
 * CTA « Partager mon savoir-faire » de la page publique : destination adaptée
 * à la session (visiteur → connexion ; candidat → savoir-faire candidat ;
 * recruteur → contenu entreprise). Reste disponible sans être ambigu.
 */
(function () {
  "use strict";
  document.addEventListener("DOMContentLoaded", function () {
    var cta = document.getElementById("share-savoirfaire-cta");
    if (!cta || !window.SS || !SS.auth) { return; }
    /* La publication ne se lance PLUS depuis la page publique : le CTA mène à
       l'espace de l'utilisateur (Mes savoir-faire / Contenus entreprise). */
    var s = SS.auth.get();
    if (!s) {
      cta.href = "connexion.html";
    } else if (SS.auth.isEmployer()) {
      cta.href = "espace-entreprise-contenus.html";
    } else {
      cta.href = "espace-candidat.html#savoir-faire";
    }
  });
})();


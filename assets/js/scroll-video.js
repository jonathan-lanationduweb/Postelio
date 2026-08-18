/**
 * Séquence cinématique pilotée par le scroll (intro Postelio).
 *
 * Principe : la vidéo n'est jamais lue en autoplay. Le scroll dans la
 * section .cine calcule une progression 0→1, convertie en temps cible
 * sur la vidéo. Un rAF rapproche en douceur le temps affiché du temps
 * cible (scrubbing fluide, sans dérive après l'arrêt du scroll).
 *
 * Les textes (scènes) sont en HTML ; leur opacité/translation est pilotée
 * par une progression locale calculée à partir de data-from / data-to.
 */
(function () {
  "use strict";

  var DEBUG = false; /* passer à true en dev pour l'overlay de réglage */

  document.addEventListener("DOMContentLoaded", function () {
    var section = document.getElementById("cine");
    var video = document.getElementById("cine-video");
    if (!section || !video) { return; }

    var prefersReduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    if (prefersReduced) {
      /* Expérience alternative : le CSS empile poster + titre + CTA.
         On tente une image figée sans lecture ni scrubbing. */
      video.removeAttribute("preload");
      return;
    }

    var sticky = section.querySelector(".cine__sticky");
    var overlay = document.getElementById("cine-overlay");
    var nav = document.getElementById("cine-nav");
    var scenes = Array.prototype.slice.call(section.querySelectorAll(".cine__scene"));
    var progressItems = Array.prototype.slice.call(section.querySelectorAll(".cine__progress li"));

    var duration = 0;
    var targetTime = 0;
    var displayedTime = 0;
    var rafId = null;
    var ready = false;

    /* Dégradés directionnels selon la position du texte de la scène active. */
    var GRAD = {
      left: "linear-gradient(90deg, rgba(9,33,27,0.78) 0%, rgba(9,33,27,0.32) 46%, rgba(9,33,27,0) 82%)",
      right: "linear-gradient(270deg, rgba(9,33,27,0.78) 0%, rgba(9,33,27,0.32) 46%, rgba(9,33,27,0) 82%)",
      center: "linear-gradient(0deg, rgba(9,33,27,0.7) 0%, rgba(9,33,27,0.35) 50%, rgba(9,33,27,0.55) 100%)"
    };

    /* ---- Progression du scroll dans la section (0 → 1) ---- */
    function getScrollProgress() {
      var rect = section.getBoundingClientRect();
      var scrollable = section.offsetHeight - window.innerHeight;
      if (scrollable <= 0) { return 0; }
      var scrolled = -rect.top;
      return clamp(scrolled / scrollable, 0, 1);
    }

    function clamp(v, min, max) { return Math.max(min, Math.min(max, v)); }

    /* ---- Scènes : opacité/translation via progression locale ---- */
    function updateScenes(p) {
      var activeStep = 0;
      scenes.forEach(function (scene) {
        var from = parseFloat(scene.getAttribute("data-from"));
        var to = parseFloat(scene.getAttribute("data-to"));
        var span = to - from;
        var fade = Math.min(0.28 * span, 0.06); /* zone de fondu entrée/sortie */
        var local = 0;
        if (p >= from && p <= to) {
          /* montée puis plateau puis descente ; la 1re scène (from=0) est
             pleine dès le sommet, la dernière (to=1) reste pleine tout en bas */
          if (from > 0 && p < from + fade) { local = (p - from) / fade; }
          else if (to < 1 && p > to - fade) { local = (to - p) / fade; }
          else { local = 1; }
          scene.classList.add("is-live");
          activeStep = parseInt(scene.getAttribute("data-scene"), 10);
        } else {
          local = 0;
          scene.classList.remove("is-live");
        }
        local = clamp(local, 0, 1);
        scene.style.setProperty("--p", local.toFixed(3));

        /* Dégradé calé sur la scène dominante. */
        if (local > 0.5) {
          var pos = scene.classList.contains("cine__scene--right") ? "right"
            : scene.classList.contains("cine__scene--center") ? "center" : "left";
          section.style.setProperty("--cine-grad", GRAD[pos]);
        }
      });

      /* Indicateur de progression. */
      progressItems.forEach(function (li) {
        li.classList.toggle("is-active", parseInt(li.getAttribute("data-step"), 10) === activeStep);
      });

      /* Invite à défiler : seulement tout au début. */
      section.classList.toggle("is-past-intro", p > 0.06);

      /* Voile de sortie sur les tout derniers %, pour fondre vers la homepage. */
      var exit = p > 0.94 ? (p - 0.94) / 0.06 : 0;
      section.style.setProperty("--cine-exit", clamp(exit, 0, 1).toFixed(3));
    }

    /* ---- Navbar : translucide/fixe dès qu'on entre dans la séquence ---- */
    function updateNav(p) {
      if (!nav) { return; }
      nav.classList.toggle("is-solid", p > 0.02 && p < 0.99);
    }

    /* ---- Boucle d'interpolation (scrubbing fluide) ---- */
    function tick() {
      /* rapprochement progressif : réactif mais sans dérive prolongée */
      var diff = targetTime - displayedTime;
      if (Math.abs(diff) < 0.005) {
        displayedTime = targetTime;
      } else {
        displayedTime += diff * 0.16;
      }
      if (ready && isFinite(displayedTime)) {
        try { video.currentTime = displayedTime; } catch (e) { /* seek non prêt */ }
      }
      if (DEBUG) { updateDebug(); }
      if (Math.abs(targetTime - displayedTime) > 0.005) {
        rafId = requestAnimationFrame(tick);
      } else {
        rafId = null;
      }
    }

    function requestTick() {
      if (rafId === null) { rafId = requestAnimationFrame(tick); }
    }

    /* ---- Réaction au scroll ---- */
    function onScroll() {
      var p = getScrollProgress();
      targetTime = p * duration;
      updateScenes(p);
      updateNav(p);
      requestTick();
    }

    window.addEventListener("scroll", onScroll, { passive: true });
    window.addEventListener("resize", onScroll);

    /* ---- Initialisation quand la vidéo est prête ---- */
    function activate() {
      if (ready) { return; }
      duration = video.duration;
      if (!duration || !isFinite(duration)) { return; }
      ready = true;
      section.classList.add("is-ready");
      onScroll();
    }

    if (video.readyState >= 1 && video.duration) {
      activate();
    } else {
      video.addEventListener("loadedmetadata", activate);
    }
    /* Filet de sécurité si l'événement a été manqué. */
    video.addEventListener("canplay", activate);

    /* ---- Overlay de debug (désactivé par défaut) ---- */
    var dbg;
    function updateDebug() {
      if (!dbg) { return; }
      dbg.textContent =
        "scroll " + getScrollProgress().toFixed(3) +
        " | target " + targetTime.toFixed(2) +
        " | shown " + displayedTime.toFixed(2) +
        " / " + (duration || 0).toFixed(2) + "s";
    }
    if (DEBUG) {
      dbg = document.createElement("div");
      dbg.style.cssText = "position:fixed;left:8px;bottom:8px;z-index:9999;background:rgba(0,0,0,.8);" +
        "color:#0f0;font:12px/1.4 monospace;padding:6px 10px;border-radius:6px;pointer-events:none;";
      document.body.appendChild(dbg);
    }
  });
})();

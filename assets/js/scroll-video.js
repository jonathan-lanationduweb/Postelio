/**
 * Intro cinématique pilotée par le scroll (page d'accueil).
 *
 * La VIDÉO est l'expérience : GSAP ScrollTrigger épingle l'étage et « scrubbe »
 * la progression du scroll (avec une légère inertie). Cette progression pilote
 * DIRECTEMENT video.currentTime ainsi que l'opacité / le translate / le flou de
 * quatre moments narratifs — le tout entièrement réversible (aucun événement
 * one-shot, aucune classe « revealed » persistante). Des respirations sans
 * texte laissent la caméra raconter seule.
 */
(function () {
  "use strict";

  var DEBUG = false; /* true en dev : overlay Progress / Video / Moment */
  var SCROLL_DISTANCE = "+=300%"; /* distance de scroll du parcours (calibrée) */
  var SCRUB = 0.3;                /* inertie cinématique (≈ 0.25–0.35) */

  document.addEventListener("DOMContentLoaded", function () {
    var section = document.getElementById("cine");
    var video = document.getElementById("cine-video");
    if (!section || !video) { return; }

    var prefersReduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    var hasGsap = window.gsap && window.ScrollTrigger;

    /* Repli statique : mouvement réduit OU GSAP indisponible. */
    if (prefersReduced || !hasGsap) {
      video.removeAttribute("preload");
      section.classList.add("is-static");
      return;
    }

    gsap.registerPlugin(ScrollTrigger);

    var stage = section.querySelector(".cine__sticky");
    var overlay = document.getElementById("cine-overlay");
    var nav = document.getElementById("cine-nav");
    var hint = document.getElementById("cine-hint");
    var scenes = Array.prototype.slice.call(section.querySelectorAll(".cine__scene"));

    /* Moments narratifs : plages de progression, position, composition.
       Des « respirations » séparent les plages (0–8, 25–32, 50–57, 73–84 %). */
    var MOMENTS = [
      { el: scenes[0], start: 0.08, end: 0.25, pos: "left" },
      { el: scenes[1], start: 0.32, end: 0.50, pos: "right" },
      { el: scenes[2], start: 0.57, end: 0.73, pos: "left" },
      { el: scenes[3], start: 0.84, end: 0.92, pos: "center", hold: true }
    ];

    var GRAD = {
      left:   "linear-gradient(90deg, rgba(9,33,27,0.58) 0%, rgba(9,33,27,0.14) 42%, rgba(9,33,27,0) 72%)",
      right:  "linear-gradient(270deg, rgba(9,33,27,0.58) 0%, rgba(9,33,27,0.14) 42%, rgba(9,33,27,0) 72%)",
      center: "linear-gradient(0deg, rgba(9,33,27,0.5) 0%, rgba(9,33,27,0.08) 46%, rgba(9,33,27,0.16) 100%)"
    };

    var duration = 0;
    var dbg = null;
    var lastGrad = "";

    /* Progression locale bornée [0,1]. */
    function rangeProgress(p, a, b) {
      return Math.min(1, Math.max(0, (p - a) / (b - a)));
    }

    /* Enveloppe entrée(20 %) / maintien(60 %) / sortie(20 %).
       Entrée : opacity 0→1, translateY 24→0, blur 8→0.
       Sortie : opacity 1→0, translateY 0→-15, blur 0→5. */
    function envelope(local) {
      if (local <= 0) { return { o: 0, ty: 24, blur: 8 }; }
      if (local >= 1) { return { o: 0, ty: -15, blur: 5 }; }
      if (local < 0.2) { var t = local / 0.2; return { o: t, ty: (1 - t) * 24, blur: (1 - t) * 8 }; }
      if (local > 0.8) { var u = (local - 0.8) / 0.2; return { o: 1 - u, ty: -u * 15, blur: u * 5 }; }
      return { o: 1, ty: 0, blur: 0 };
    }

    /* Dernier moment : entrée (25 %) puis maintien jusqu'à la fin (pas de sortie). */
    function envelopeHold(local) {
      if (local <= 0) { return { o: 0, ty: 24, blur: 8 }; }
      if (local < 0.25) { var t = local / 0.25; return { o: t, ty: (1 - t) * 24, blur: (1 - t) * 8 }; }
      return { o: 1, ty: 0, blur: 0 };
    }

    function render(p) {
      var maxVis = 0, activePos = "left", activeMoment = 0;

      for (var i = 0; i < MOMENTS.length; i++) {
        var m = MOMENTS[i];
        var local = rangeProgress(p, m.start, m.end);
        var e = m.hold ? envelopeHold(local) : envelope(local);
        var el = m.el;
        el.style.opacity = e.o.toFixed(3);
        el.style.transform = "translateY(" + e.ty.toFixed(1) + "px)";
        el.style.filter = e.blur > 0.05 ? "blur(" + e.blur.toFixed(2) + "px)" : "none";
        el.style.visibility = e.o > 0.004 ? "visible" : "hidden";
        el.style.pointerEvents = (m.pos === "center" && e.o > 0.6) ? "auto" : "none";
        if (e.o > maxVis) { maxVis = e.o; activePos = m.pos; activeMoment = i + 1; }
      }

      /* Overlay : direction du moment dominant, intensité = présence de texte
         (≈ 0 pendant les respirations → vidéo seule). */
      if (activePos !== lastGrad) {
        section.style.setProperty("--cine-grad", GRAD[activePos]);
        lastGrad = activePos;
      }
      overlay.style.opacity = (maxVis * 0.9).toFixed(3);

      /* Invite à défiler : pleine avant 6 %, disparue à 12 %. */
      if (hint) {
        var hv = p < 0.06 ? 1 : (p > 0.12 ? 0 : (0.12 - p) / 0.06);
        hint.style.setProperty("--hint", Math.max(0, Math.min(1, hv)).toFixed(3));
      }

      /* Navbar à peine translucide après le tout début. */
      if (nav) { nav.classList.toggle("is-solid", p > 0.04); }

      /* Sortie : le beige de la section suivante monte du bas (90 → 100 %). */
      section.style.setProperty("--cine-exit", rangeProgress(p, 0.90, 1.0).toFixed(3));

      if (DEBUG && dbg) {
        dbg.textContent = "Progress: " + p.toFixed(3) +
          "\nVideo: " + (video.currentTime || 0).toFixed(2) + " / " + duration.toFixed(2) +
          "\nMoment: " + (activeMoment || "—");
      }
    }

    /* Applique la progression : currentTime + rendu des moments. */
    var proxy = { p: 0 };
    function apply() {
      var t = proxy.p * duration;
      if (t > duration) { t = duration; }
      if (video.readyState >= 1 && isFinite(t)) {
        try { video.currentTime = t; } catch (e) { /* seek non prêt */ }
      }
      render(proxy.p);
    }

    var built = false;
    function build() {
      if (built) { return; }
      duration = video.duration;
      if (!duration || !isFinite(duration)) { return; }
      built = true;
      section.classList.add("is-ready");

      /* Timeline « scrubbée » : la tête de lecture suit le scroll avec inertie.
         proxy.p (0→1) pilote currentTime + textes via apply(). */
      gsap.timeline({
        scrollTrigger: {
          trigger: section,
          start: "top top",
          end: SCROLL_DISTANCE,
          pin: stage,
          pinSpacing: true,
          scrub: SCRUB,
          anticipatePin: 1
        }
      }).to(proxy, { p: 1, ease: "none", onUpdate: apply });

      render(0);
      ScrollTrigger.refresh();
    }

    if (DEBUG) {
      dbg = document.createElement("div");
      dbg.className = "cine__debug";
      document.body.appendChild(dbg);
    }

    if (video.readyState >= 1 && video.duration) { build(); }
    else { video.addEventListener("loadedmetadata", build); }
    video.addEventListener("canplay", build);
    window.addEventListener("load", function () { if (built) { ScrollTrigger.refresh(); } });
  });
})();

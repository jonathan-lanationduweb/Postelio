/**
 * Intro cinématique pilotée par le scroll (page d'accueil).
 *
 * La VIDÉO est l'expérience. GSAP ScrollTrigger épingle l'étage (sticky) et
 * expose la progression du scroll ; le scroll ne fait que fixer une CIBLE.
 * Une boucle requestAnimationFrame interpole (lerp) la valeur affichée vers
 * cette cible et ne déplace video.currentTime que si l'écart dépasse un seuil
 * — d'où un travelling continu et fluide, sans palier ni saut de frame. La
 * même valeur lissée pilote l'opacité / le translate / le flou des moments
 * narratifs (réversible, aucun one-shot). Des respirations sans texte laissent
 * la caméra raconter seule.
 */
(function () {
  "use strict";

  var DEBUG = false; /* true en dev : overlay Progress / Video / Moment */
  var SCROLL_DISTANCE = "+=400%"; /* longue section sticky (~400vh) */
  var LERP = 0.14;                /* lissage du seek (0.10–0.18) : supprime les
                                     micro-saccades sans ralentir la réponse */

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
    var header = document.querySelector(".site-header--cine");
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

    /* Dégradés LOCAUX et légers : n'assombrissent que le côté du texte,
       le reste de l'image reste net et lumineux. */
    var GRAD = {
      left:   "linear-gradient(90deg, rgba(9,33,27,0.46) 0%, rgba(9,33,27,0.1) 40%, rgba(9,33,27,0) 66%)",
      right:  "linear-gradient(270deg, rgba(9,33,27,0.46) 0%, rgba(9,33,27,0.1) 40%, rgba(9,33,27,0) 66%)",
      center: "linear-gradient(0deg, rgba(9,33,27,0.42) 0%, rgba(9,33,27,0.05) 46%, rgba(9,33,27,0.12) 100%)"
    };

    var duration = 0;
    var dbg = null;
    var lastGrad = "";
    var lastSolid = null;

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
      overlay.style.opacity = (maxVis * 0.7).toFixed(3);

      /* Invite à défiler : pleine avant 6 %, disparue à 12 %. */
      if (hint) {
        var hv = p < 0.06 ? 1 : (p > 0.12 ? 0 : (0.12 - p) / 0.06);
        hint.style.setProperty("--hint", Math.max(0, Math.min(1, hv)).toFixed(3));
      }

      /* Navbar UNIQUE : bascule cinématique → normale à la fin de l'intro
         (même élément DOM, l'état suit le scroll ; réversible). */
      if (header) { header.classList.toggle("is-solid", p > 0.9); }

      if (DEBUG && dbg) {
        dbg.textContent = "Progress: " + p.toFixed(3) +
          "\nVideo: " + (video.currentTime || 0).toFixed(2) + " / " + duration.toFixed(2) +
          "\nMoment: " + (activeMoment || "—");
      }
    }

    /* ---- Moteur : scroll → cible, rAF → interpolation (lerp) ----
       Le handler de scroll ne fait QUE fixer targetP (aucun calcul lourd).
       La boucle rejoint targetP en douceur et ne touche video.currentTime que
       si l'écart dépasse un seuil — évite les seeks redondants (fluidité).
       Progression continue de 0 à 1, sans palier ni saut. */
    var targetP = 0; /* progression brute du scroll (cible) */
    var dispP = 0;   /* valeur affichée, lissée */
    var rafId = null;

    function loop() {
      var diff = targetP - dispP;
      if (Math.abs(diff) < 0.00015) { dispP = targetP; }
      else { dispP += diff * LERP; }

      if (video.readyState >= 1) {
        var t = dispP * duration;
        if (isFinite(t) && Math.abs(video.currentTime - t) > 0.01) {
          try { video.currentTime = t; } catch (e) { /* seek non prêt */ }
        }
      }
      render(dispP);

      if (Math.abs(targetP - dispP) >= 0.00015) { rafId = requestAnimationFrame(loop); }
      else { rafId = null; }
    }
    function requestLoop() { if (rafId === null) { rafId = requestAnimationFrame(loop); } }

    var built = false;
    function build() {
      if (built) { return; }
      duration = video.duration;
      if (!duration || !isFinite(duration)) { return; }
      built = true;
      section.classList.add("is-ready");

      /* Pin sticky géré par ScrollTrigger ; PAS de scrub (qui pousserait
         currentTime à chaque frame). onUpdate ne fait que fixer la cible. */
      ScrollTrigger.create({
        trigger: section,
        start: "top top",
        end: SCROLL_DISTANCE,
        pin: stage,
        pinSpacing: true,
        anticipatePin: 1,
        onUpdate: function (self) { targetP = self.progress; requestLoop(); },
        onRefresh: function (self) { targetP = dispP = self.progress; render(dispP); }
      });

      /* Continuité de sortie : la 1re section du site remonte doucement à son
         entrée (mouvement vertical naturel, réversible) — le vrai site prend
         le relais sans trou ni flash. */
      var firstSection = document.querySelector("#recent-title");
      firstSection = firstSection ? firstSection.closest("section") : null;
      if (firstSection) {
        gsap.from(firstSection, {
          y: 48,
          ease: "none",
          scrollTrigger: {
            trigger: firstSection,
            start: "top bottom",
            end: "top 64%",
            scrub: true
          }
        });
      }

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

/**
 * Socle Auth Postelio (I1) — amorçage + pont de compatibilité `SS.auth`.
 *
 * Chargé APRÈS main.js (qui définit `window.SS`) : remplace l'objet `SS.auth` SIMULÉ par un pont
 * délégant à la session RÉELLE (`PostelioAuth.session`). Les autres helpers `SS.*` (loadJSON,
 * store, toast, escapeHtml…) et les données MÉTIER en localStorage sont conservés (mode hybride).
 *
 * Séquence : (1) retirer l'ancien `ss_session` ; (2) rendu optimiste via l'instantané caché ;
 * (3) `session.load()` (valide le jeton via GET /me) ; (4) ré-application des gardes après vérité.
 */
(function () {
  "use strict";

  var Auth = window.PostelioAuth;
  if (!Auth) { throw new Error("PostelioAuth requis avant postelio-boot."); }
  var session = Auth.session;
  var guards = Auth.guards;

  /* 1. L'ancienne session simulée n'est plus une autorité : on la retire. */
  try { localStorage.removeItem("ss_session"); } catch (e) { /* */ }

  /* 2. Pont de compatibilité : SS.auth délègue à la session réelle. */
  function installBridge() {
    if (!window.SS) { window.SS = {}; }
    window.SS.auth = {
      /* Objet session compatible avec l'ancien contrat lu par les pages. */
      get: function () {
        var u = session.snapshot();
        if (!u) { return null; }
        return {
          loggedIn: true,
          role: u.front_role,          // "candidate" | "employer"
          email: u.email,
          display_name: u.display_name,
          email_verified: u.email_verified,
          status: u.status,
          id: u.id
        };
      },
      set: function () { /* déprécié : la session n'est plus créée côté front */ },
      clear: function () { try { Auth.tokens.clear(); } catch (e) { /* */ } },
      isLogged: function () { return session.isAuthenticated(); },
      isCandidate: function () { return session.isCandidate(); },
      isEmployer: function () { return session.isEmployer(); },
      displayName: function () { return session.displayName(); },
      initials: function () { return session.initials(); },
      logout: function (redirect) {
        session.logout().then(function () {
          window.location.href = redirect || "index.html";
        });
      },
      /* require(role front) : garde optimiste (instantané) ; la vérité /me corrige au boot. */
      require: function (role) {
        if (role === "employer") { return guards.requireRecruiter(); }
        if (role === "candidate") { return guards.requireCandidate(); }
        return guards.requireAuth();
      }
    };
  }
  installBridge();

  /* 3+4. Charger la session réelle puis appliquer la garde déclarée par la page. */
  function enforcePageGuard() {
    var body = document.body;
    var guard = body ? (body.getAttribute("data-guard") || "") : "";
    if (!guard) { return; }
    if (guard === "candidate") { guards.requireCandidate(); }
    else if (guard === "employer") { guards.requireRecruiter(); }
    else if (guard === "auth") { guards.requireAuth(); }
  }

  /* Bandeau « e-mail non vérifié » (le backend expose le renvoi). Non intrusif, refermable. */
  function maybeVerifyBanner() {
    var u = session.snapshot();
    if (!u || u.email_verified) { return; }
    if (document.getElementById("pst-verify-banner")) { return; }
    var bar = document.createElement("div");
    bar.id = "pst-verify-banner";
    bar.setAttribute("role", "status");
    bar.style.cssText = "background:#fff4e5;color:#663c00;padding:.6rem 1rem;font-size:.9rem;text-align:center;border-bottom:1px solid #f0d9b5;";
    var msg = document.createElement("span");
    msg.textContent = "Votre adresse e-mail n'est pas encore vérifiée. ";
    var btn = document.createElement("button");
    btn.type = "button";
    btn.textContent = "Renvoyer l'e-mail de vérification";
    btn.style.cssText = "background:none;border:none;color:#17324d;text-decoration:underline;cursor:pointer;font-size:inherit;";
    btn.addEventListener("click", function () {
      btn.disabled = true; btn.textContent = "Envoi…";
      session.resendVerification().then(function () {
        btn.textContent = "E-mail envoyé ✓";
      }, function () {
        btn.disabled = false; btn.textContent = "Réessayer";
      });
    });
    bar.appendChild(msg); bar.appendChild(btn);
    if (document.body) { document.body.insertBefore(bar, document.body.firstChild); }
  }

  function boot() {
    /* Garde optimiste immédiate (évite d'afficher un espace privé à un anonyme). */
    enforcePageGuard();
    /* Validation réelle : GET /me. Corrige l'affichage + ré-applique la garde. */
    session.load().then(function () {
      installBridge(); // rafraîchit l'instantané exposé
      enforcePageGuard();
      maybeVerifyBanner();
      try { window.dispatchEvent(new CustomEvent("ss:auth-ready", { detail: session.snapshot() })); } catch (e) { /* */ }
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
})();

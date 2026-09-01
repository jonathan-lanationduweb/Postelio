/**
 * Connexion / inscription / mot de passe oublié — RÉELS (Lot d'intégration I1).
 *
 * S'appuie sur le socle : PostelioAuth.session (jeton Bearer + GET /me) et PostelioAuth.guards.
 * Aucune session simulée, aucun compte de démonstration. Le rôle est déterminé par le SERVEUR ;
 * le champ « rôle » de connexion n'est qu'une aide visuelle (la redirection suit le rôle réel).
 *
 * Champs d'inscription non liés au compte (ville, métier, entreprise, secteur) : ils relèvent du
 * PROFIL (lot I3 / Lot 03) et ne sont pas transmis à /auth/register en I1.
 */
(function () {
  "use strict";

  var Auth = window.PostelioAuth;
  if (!Auth) { return; }
  var session = Auth.session, guards = Auth.guards;

  function val(id) { var el = document.getElementById(id); return el ? String(el.value || "").trim() : ""; }
  function checked(id) { var el = document.getElementById(id); return !!(el && el.checked); }

  /* Zone d'erreur de formulaire (aria-live), créée si absente. */
  function errorBox(form) {
    var box = form.querySelector(".form-error");
    if (!box) {
      box = document.createElement("p");
      box.className = "form-error";
      box.setAttribute("role", "alert");
      box.setAttribute("aria-live", "polite");
      box.hidden = true;
      form.insertBefore(box, form.firstChild);
    }
    return box;
  }
  function showError(form, message) {
    var box = errorBox(form);
    box.textContent = message;   // textContent : jamais d'innerHTML avec des données serveur
    box.hidden = false;
  }
  function clearError(form) {
    var box = form.querySelector(".form-error");
    if (box) { box.hidden = true; box.textContent = ""; }
  }
  function busy(btn, on, label) {
    if (!btn) { return; }
    if (on) {
      btn.dataset.label = btn.dataset.label || btn.textContent;
      btn.disabled = true;
      btn.setAttribute("aria-busy", "true");
      btn.textContent = label || (btn.dataset.label + "…");
    } else {
      btn.disabled = false;
      btn.removeAttribute("aria-busy");
      if (btn.dataset.label) { btn.textContent = btn.dataset.label; }
    }
  }

  /* Redirection après succès : ?next interne validé, sinon espace du rôle réel. */
  function redirectAfterAuth() {
    var next = guards.internalNext();
    window.location.href = next || session.homePath();
  }

  /* Message d'erreur selon le statut/contexte. */
  function authErrorMessage(err, context) {
    if (!err || err.status === 0) { return "Impossible de contacter Postelio. Vérifiez votre connexion."; }
    if (err.status === 401) { return "Adresse e-mail ou mot de passe incorrect."; }
    if (err.status === 403) { return "Ce compte n'est pas disponible. Contactez le support si besoin."; }
    if (err.status === 409) { return "Un compte existe déjà pour cette adresse e-mail."; }
    if (err.status === 429) { return "Trop de tentatives. Réessayez dans quelques minutes."; }
    if (err.status === 422) {
      var f = err.firstFieldError && err.firstFieldError();
      if (f) { return String(f.reason || "Certaines informations sont invalides."); }
      return "Certaines informations sont invalides.";
    }
    return err.userMessage ? err.userMessage() : "Une erreur est survenue.";
  }

  document.addEventListener("DOMContentLoaded", function () {

    /* ================= CONNEXION ================= */
    var loginForm = document.getElementById("login-form");
    if (loginForm) {
      var loginBtn = loginForm.querySelector('button[type="submit"]');
      loginForm.addEventListener("submit", function (e) {
        e.preventDefault();
        clearError(loginForm);
        var email = val("login-email"), pass = val("login-password");
        if (!email || !pass) { showError(loginForm, "Renseignez votre e-mail et votre mot de passe."); return; }
        busy(loginBtn, true, "Connexion…");
        session.login(email, pass).then(function () {
          redirectAfterAuth();
        }, function (err) {
          busy(loginBtn, false);
          showError(loginForm, authErrorMessage(err, "login"));
          var emailEl = document.getElementById("login-email");
          if (emailEl) { emailEl.focus(); }
        });
      });
    }

    /* Mot de passe oublié : demande de lien (anti-énumération → toujours succès). */
    var forgot = document.getElementById("forgot-password-link");
    if (forgot) {
      forgot.addEventListener("click", function (e) {
        e.preventDefault();
        var email = val("login-email");
        if (!email) {
          if (loginForm) { showError(loginForm, "Saisissez d'abord votre adresse e-mail, puis cliquez à nouveau."); }
          var el = document.getElementById("login-email"); if (el) { el.focus(); }
          return;
        }
        forgot.setAttribute("aria-busy", "true");
        session.lostPassword(email).then(function () {
          forgot.removeAttribute("aria-busy");
          if (window.SS && SS.toast) { SS.toast("Si un compte existe, un e-mail de réinitialisation vient d'être envoyé."); }
          else if (loginForm) { showError(loginForm, "Si un compte existe, un e-mail de réinitialisation vient d'être envoyé."); }
        }, function () {
          forgot.removeAttribute("aria-busy");
          if (loginForm) { showError(loginForm, "Impossible d'envoyer l'e-mail pour le moment."); }
        });
      });
    }

    /* ================= INSCRIPTION ================= */
    var chooser = document.getElementById("role-chooser");
    if (chooser) {
      var wrapCand = document.getElementById("form-candidate");
      var wrapEmp = document.getElementById("form-employer");

      function showRole(role) {
        chooser.hidden = true;
        wrapCand.hidden = role !== "candidate";
        wrapEmp.hidden = role !== "employer";
        var target = role === "candidate" ? wrapCand : wrapEmp;
        var first = target.querySelector("input");
        if (first) { first.focus(); }
        target.scrollIntoView({ behavior: "smooth", block: "start" });
      }
      chooser.querySelectorAll("[data-choose-role]").forEach(function (btn) {
        btn.addEventListener("click", function () { showRole(btn.getAttribute("data-choose-role")); });
      });
      document.querySelectorAll("[data-back-roles]").forEach(function (l) {
        l.addEventListener("click", function (e) {
          e.preventDefault();
          chooser.hidden = false; wrapCand.hidden = true; wrapEmp.hidden = true;
          chooser.scrollIntoView({ behavior: "smooth", block: "center" });
        });
      });

      function displayName(first, last) { return (first + " " + last).trim(); }

      function submitRegister(form, frontRole, fields, redirect) {
        var btn = form.querySelector('button[type="submit"]');
        clearError(form);
        if (!fields.email || !fields.password) { showError(form, "Renseignez votre e-mail et un mot de passe."); return; }
        if (fields.password.length < 8) { showError(form, "Le mot de passe doit contenir au moins 8 caractères."); return; }
        if (fields.consentId && !checked(fields.consentId)) { showError(form, "Veuillez accepter les conditions d'utilisation."); return; }
        busy(btn, true, "Création…");
        session.register({
          email: fields.email,
          password: fields.password,
          frontRole: frontRole,
          displayName: displayName(fields.first, fields.last)
        }).then(function () {
          window.location.href = redirect;
        }, function (err) {
          busy(btn, false);
          showError(form, authErrorMessage(err, "register"));
        });
      }

      var candForm = wrapCand ? wrapCand.querySelector("form") : null;
      if (candForm) {
        candForm.addEventListener("submit", function (e) {
          e.preventDefault();
          submitRegister(candForm, "candidate", {
            email: val("cand-email"), password: val("cand-password"),
            first: val("cand-firstname"), last: val("cand-lastname"), consentId: "cand-consent"
          }, "espace-candidat.html?onboarding=1");
        });
      }

      var empForm = wrapEmp ? wrapEmp.querySelector("form") : null;
      if (empForm) {
        empForm.addEventListener("submit", function (e) {
          e.preventDefault();
          submitRegister(empForm, "employer", {
            email: val("emp-email"), password: val("emp-password"),
            first: val("emp-firstname"), last: val("emp-lastname"), consentId: "emp-consent"
          }, "espace-entreprise.html");
        });
      }
    }
  });
})();

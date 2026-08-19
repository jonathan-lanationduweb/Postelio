/**
 * Connexion / inscription simulées (prototype biface candidat / recruteur).
 * Aucun vrai mot de passe n'est vérifié ni stocké : la « session » est un
 * simple objet en stockage local ({ loggedIn, role, firstName, ... }) géré
 * par SS.auth (voir main.js).
 */
(function () {
  "use strict";

  function val(id) { var el = document.getElementById(id); return el ? el.value.trim() : ""; }
  function ok(form) { return !window.SS.validateForm || window.SS.validateForm(form); }
  function go(role) { window.location.href = role === "employer" ? "espace-entreprise.html" : "espace-candidat.html"; }

  document.addEventListener("DOMContentLoaded", function () {
    var A = window.SS.auth, D = window.APP_CONFIG.demoAccounts;

    /* ---- Page CONNEXION ---- */
    var loginForm = document.getElementById("login-form");
    if (loginForm) {
      loginForm.addEventListener("submit", function (e) {
        e.preventDefault();
        if (!ok(loginForm)) { return; }
        var roleEl = loginForm.querySelector('input[name="login-role"]:checked');
        var role = roleEl ? roleEl.value : "candidate";
        var session = Object.assign({}, role === "employer" ? D.employer : D.candidate);
        var email = val("login-email");
        if (email) { session.email = email; }
        A.set(session);
        go(role);
      });
      /* Connexion démo en un clic (deux comptes de test). */
      document.querySelectorAll("[data-demo-login]").forEach(function (b) {
        b.addEventListener("click", function () {
          var role = b.getAttribute("data-demo-login");
          A.set(Object.assign({}, role === "employer" ? D.employer : D.candidate));
          go(role);
        });
      });
    }

    /* ---- Page INSCRIPTION : choix de rôle puis formulaire adapté ---- */
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

      var candForm = wrapCand.querySelector("form");
      candForm.addEventListener("submit", function (e) {
        e.preventDefault();
        if (!ok(candForm)) { return; }
        A.set({
          loggedIn: true, role: "candidate",
          firstName: val("cand-firstname"), lastName: val("cand-lastname"),
          email: val("cand-email"), city: val("cand-city"), metier: val("cand-metier")
        });
        window.location.href = "espace-candidat.html";
      });

      var empForm = wrapEmp.querySelector("form");
      empForm.addEventListener("submit", function (e) {
        e.preventDefault();
        if (!ok(empForm)) { return; }
        A.set({
          loggedIn: true, role: "employer",
          firstName: val("emp-firstname"), lastName: val("emp-lastname"),
          email: val("emp-email"), city: val("emp-city"),
          company: val("emp-company"), secteur: val("emp-secteur")
        });
        window.location.href = "espace-entreprise.html";
      });
    }
  });
})();

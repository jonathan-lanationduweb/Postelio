/**
 * Espace recruteur — Paramètres (espace-entreprise-parametres.html).
 *
 * Sections structurées : Notifications, Compte, Préférences,
 * Confidentialité / Session (déconnexion + zone dangereuse).
 *
 * Persistance des préférences dans ss_employer_settings (nouvelle clé, les
 * clés existantes restent inchangées). AUCUN mot de passe n'est stocké :
 * le champ est purement fictif (§paramètres). La déconnexion conserve
 * #logout-btn et la logique existante (SS.auth.logout).
 */
(function () {
  "use strict";

  var SETTINGS_KEY = "ss_employer_settings";

  document.addEventListener("DOMContentLoaded", function () {
    if (!window.EMP || !EMP.ready) { return; }
    if (!document.getElementById("parametres")) { return; }
    var settings = SS.store.get(SETTINGS_KEY, {}) || {};
    initNotifications(settings);
    initPreferences(settings);
    initAccount(settings);
    initLogout();
    initDeleteAccount();
  });

  /* ---- Notifications : cases à cocher + enregistrement ---- */
  function initNotifications(settings) {
    var form = document.getElementById("notif-form");
    if (!form) { return; }
    var saved = settings.notifications;
    if (saved) {
      Array.prototype.forEach.call(form.elements, function (el) {
        if (el.type === "checkbox" && Object.prototype.hasOwnProperty.call(saved, el.name)) {
          el.checked = !!saved[el.name];
        }
      });
    }
    form.addEventListener("submit", function (ev) {
      ev.preventDefault();
      var next = {};
      Array.prototype.forEach.call(form.elements, function (el) {
        if (el.type === "checkbox") { next[el.name] = el.checked; }
      });
      patch("notifications", next);
      SS.toast("Notifications enregistrées (démonstration).");
    });
  }

  /* ---- Préférences : langue + fuseau horaire ---- */
  function initPreferences(settings) {
    var form = document.getElementById("prefs-form");
    if (!form) { return; }
    var saved = settings.preferences || {};
    setSelect("pref-lang", saved.lang);
    setSelect("pref-tz", saved.tz);
    form.addEventListener("submit", function (ev) {
      ev.preventDefault();
      patch("preferences", {
        lang: value("pref-lang"),
        tz: value("pref-tz")
      });
      SS.toast("Préférences enregistrées (démonstration).");
    });
  }

  /* ---- Compte : lecture puis édition à la demande (sans stocker de mot de passe) ---- */
  function initAccount(settings) {
    var view = document.getElementById("account-view");
    var viewActions = document.getElementById("account-view-actions");
    var form = document.getElementById("account-form");
    var editBtn = document.getElementById("account-edit-btn");
    var cancelBtn = document.getElementById("account-cancel-btn");
    var nameVal = document.getElementById("account-name-val");
    var emailVal = document.getElementById("account-email-val");
    var nameInput = document.getElementById("account-name");
    var emailInput = document.getElementById("account-email");
    var pwdInput = document.getElementById("account-password");
    if (!view || !form || !editBtn || !cancelBtn) { return; }

    /* Valeurs par défaut : session, puis compte enregistré. */
    var s = SS.auth.get() || {};
    var acc = settings.account || {};
    var name = acc.name || SS.auth.displayName() || "";
    var email = acc.email || s.email || "";
    if (name) { nameVal.textContent = name; }
    if (email) { emailVal.textContent = email; }

    function openEdit() {
      nameInput.value = nameVal.textContent;
      emailInput.value = emailVal.textContent;
      pwdInput.value = "";
      view.hidden = true;
      viewActions.hidden = true;
      form.hidden = false;
      nameInput.focus();
    }
    function closeEdit() {
      form.hidden = true;
      view.hidden = false;
      viewActions.hidden = false;
      editBtn.focus();
    }

    editBtn.addEventListener("click", openEdit);
    cancelBtn.addEventListener("click", closeEdit);
    form.addEventListener("submit", function (ev) {
      ev.preventDefault();
      var newName = (nameInput.value || "").trim();
      var newEmail = (emailInput.value || "").trim();
      if (newName) { nameVal.textContent = newName; }
      if (newEmail) { emailVal.textContent = newEmail; }
      /* On enregistre le nom et l'e-mail, JAMAIS le mot de passe. */
      patch("account", { name: nameVal.textContent, email: emailVal.textContent });
      pwdInput.value = "";
      closeEdit();
      SS.toast("Compte mis à jour (démonstration).");
    });
  }

  /* ---- Déconnexion (comportement existant conservé) ---- */
  function initLogout() {
    var btn = document.getElementById("logout-btn");
    if (btn) {
      btn.addEventListener("click", function () { SS.auth.logout(); });
    }
  }

  /* ---- Zone dangereuse : suppression de compte avec confirmation ---- */
  function initDeleteAccount() {
    var openBtn = document.getElementById("delete-account-btn");
    var overlay = document.getElementById("delete-account-modal");
    var confirmBtn = document.getElementById("delete-account-confirm");
    if (!openBtn || !overlay || !confirmBtn) { return; }
    var modal = createModal(overlay);
    openBtn.addEventListener("click", function () { modal.open({ returnTo: openBtn }); });
    confirmBtn.addEventListener("click", function () {
      modal.close(false);
      /* Démonstration : rien n'est réellement supprimé. */
      SS.toast("Suppression simulée (démonstration).");
    });
  }

  /* ============================================================
     Fabrique de modale accessible (focus piégé, Échap / overlay)
     — même contrat que employer-candidates.js.
     ============================================================ */
  function createModal(overlay) {
    var dialog = overlay.querySelector(".modal");
    var returnTo = null;

    function focusables() {
      var sel = 'button:not([disabled]), [href], input:not([disabled]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';
      return Array.prototype.slice.call(dialog.querySelectorAll(sel)).filter(function (el) {
        return el.offsetParent !== null;
      });
    }
    function onKey(ev) {
      if (ev.key === "Escape") { ev.preventDefault(); close(); return; }
      if (ev.key !== "Tab") { return; }
      var f = focusables();
      if (!f.length) { return; }
      var first = f[0], last = f[f.length - 1];
      if (ev.shiftKey && document.activeElement === first) { ev.preventDefault(); last.focus(); }
      else if (!ev.shiftKey && document.activeElement === last) { ev.preventDefault(); first.focus(); }
    }
    function open(opts) {
      opts = opts || {};
      returnTo = opts.returnTo || document.activeElement;
      overlay.hidden = false;
      document.addEventListener("keydown", onKey);
      var f = focusables();
      if (f.length) { f[0].focus(); }
    }
    function close() {
      if (overlay.hidden) { return; }
      overlay.hidden = true;
      document.removeEventListener("keydown", onKey);
      if (returnTo && returnTo.focus && document.body.contains(returnTo)) { returnTo.focus(); }
    }
    overlay.addEventListener("click", function (ev) { if (ev.target === overlay) { close(); } });
    overlay.querySelectorAll("[data-close]").forEach(function (b) {
      b.addEventListener("click", function () { close(); });
    });
    return { open: open, close: function () { close(); } };
  }

  /* ---- Aides ---- */
  function patch(key, value) {
    var all = SS.store.get(SETTINGS_KEY, {}) || {};
    all[key] = value;
    SS.store.set(SETTINGS_KEY, all);
  }
  function setSelect(id, val) {
    var el = document.getElementById(id);
    if (el && val) { el.value = val; }
  }
  function value(id) {
    var el = document.getElementById(id);
    return el ? el.value : "";
  }
})();

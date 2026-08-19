/**
 * Espace recruteur — Profil entreprise (espace-entreprise-profil.html).
 *
 * Affiche l'identité de l'entreprise (logo à initiales, nom, secteur, ville)
 * et gère l'édition de la présentation (mode Modifier), persistée dans
 * ss_company_profile (clé inchangée).
 */
(function () {
  "use strict";

  var PROFILE_KEY = "ss_company_profile";

  document.addEventListener("DOMContentLoaded", function () {
    if (!window.EMP || !EMP.ready) { return; }
    if (!document.getElementById("profil-desc-form")) { return; }
    fillIdentity();
    initProfilEdit();
  });

  /* Le logo du profil représente l'ENTREPRISE : initiales de la société
     (ex. « FB »), et non celles de la personne connectée (« CM »). */
  function fillIdentity() {
    var s = SS.auth.get() || {};
    var company = s.company || APP_CONFIG.demoCompany.nom;
    setText("profil-logo", EMP.companyInitials(company));
    setText("profil-name", company);
    if (s.secteur) { setText("profil-sector", s.secteur); }
    if (s.city) { setText("profil-city", s.city); }
  }

  function initProfilEdit() {
    var view = document.getElementById("profil-desc-view");
    var form = document.getElementById("profil-desc-form");
    var field = document.getElementById("profil-desc");
    var editBtn = document.getElementById("profil-edit-btn");
    var saveBtn = document.getElementById("profil-desc-save");
    var cancelBtn = document.getElementById("profil-desc-cancel");
    if (!view || !form || !field || !editBtn || !saveBtn || !cancelBtn) { return; }

    var stored = SS.store.get(PROFILE_KEY, null);
    if (stored && typeof stored.description === "string" && stored.description.trim()) {
      view.textContent = stored.description;
      field.value = stored.description;
    }

    function openEdit() {
      field.value = view.textContent;
      form.hidden = false;
      editBtn.hidden = true;
      field.focus();
    }
    function closeEdit() {
      form.hidden = true;
      editBtn.hidden = false;
      editBtn.focus();
    }

    editBtn.addEventListener("click", openEdit);
    cancelBtn.addEventListener("click", closeEdit);
    saveBtn.addEventListener("click", function () {
      var val = (field.value || "").trim();
      if (val) { view.textContent = val; }
      SS.store.set(PROFILE_KEY, { description: view.textContent });
      closeEdit();
      SS.toast("Présentation enregistrée (démonstration).");
    });
  }

  function setText(id, value) {
    var el = document.getElementById(id);
    if (el) { el.textContent = String(value); }
  }
})();

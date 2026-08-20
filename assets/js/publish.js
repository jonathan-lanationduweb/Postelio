/**
 * Publication d'une offre : formulaire en 6 étapes avec barre de
 * progression, validation, aperçu et enregistrement local (démonstration).
 */
(function () {
  "use strict";

  var STEPS = 6;
  var current = 1;

  document.addEventListener("DOMContentLoaded", function () {
    var wizard = document.getElementById("publish-wizard");
    if (!wizard) { return; }

    /* La publication appartient à l'espace recruteur : réservé aux comptes
       employeur connectés (visiteur → connexion ; candidat → son espace). */
    if (window.SS && SS.auth && !SS.auth.require("employer")) { return; }

    var form = document.getElementById("publish-form");
    var prevBtn = document.getElementById("wizard-prev");
    var nextBtn = document.getElementById("wizard-next");
    var submitBtn = document.getElementById("wizard-submit");

    /* Pré-remplissage en mode « modifier » (depuis l'espace entreprise). */
    var editId = SS.param("modifier");
    if (editId) {
      SS.getOffers().then(function (offers) {
        var offer = offers.find(function (o) { return o.id === editId; });
        if (!offer) { return; }
        setValue("pub-company", offer.entrepriseNom);
        setValue("pub-title", offer.titre);
        setValue("pub-category", offer.categorie);
        setValue("pub-city", offer.ville);
        setValue("pub-contract", offer.contrat);
        setValue("pub-duration", offer.duree || "");
        setValue("pub-time", offer.tempsTravail);
        setValue("pub-salary", offer.salaire);
        setValue("pub-remote", offer.teletravail);
        setValue("pub-description", offer.description);
        setValue("pub-missions", (offer.missions || []).join("\n"));
        setValue("pub-profile", (offer.profil || []).join("\n"));
        setValue("pub-skills", (offer.competences || []).join(", "));
        setValue("pub-email", offer.email);
        setValue("pub-expiry", offer.dateExpiration);
      }).catch(function () { /* mode création par défaut */ });
    } else if (window.SS && SS.auth && SS.auth.isEmployer && SS.auth.isEmployer()) {
      /* Nouvelle offre : préremplir les infos de l'entreprise du recruteur
         connecté (modifiables). Il ne ressaisit que le poste. (§2-3) */
      var s = SS.auth.get() || {};
      setValue("pub-company", s.company || "");
      if (s.city) { setValue("pub-city", s.city); }
      if (s.email) { setValue("pub-email", s.email); }
      SS.getCompanies().then(function (cs) {
        var c = cs.find(function (x) { return x.id === s.companyId; });
        if (!c) { return; }
        setValue("pub-city", c.ville);
        setValue("pub-email", c.email || s.email || "");
        setValue("pub-category", "finance-comptabilite");
      }).catch(function () { /* infos entreprise indisponibles */ });
    }

    /* Date d'expiration proposée par défaut : dans 60 jours. */
    var expiry = document.getElementById("pub-expiry");
    if (expiry && !expiry.value) {
      var d = new Date();
      d.setDate(d.getDate() + APP_CONFIG.payment.renewal.durationDays);
      expiry.value = d.toISOString().slice(0, 10);
    }

    prevBtn.addEventListener("click", function () { goTo(current - 1); });
    nextBtn.addEventListener("click", function () {
      if (!validateStep(current)) { return; }
      if (current + 1 === 5) { buildPreview(); }
      goTo(current + 1);
    });

    /* ---- Brouillon fictif (stockage local) ---- */
    var DRAFT_KEY = "ss_offre_brouillon";
    var FIELD_IDS = ["pub-company", "pub-city", "pub-email", "pub-title", "pub-category",
      "pub-duration", "pub-time", "pub-salary", "pub-remote", "pub-contract",
      "pub-description", "pub-missions", "pub-profile", "pub-skills", "pub-expiry"];

    var draftBtn = document.getElementById("wizard-draft");
    if (draftBtn) {
      draftBtn.addEventListener("click", function () {
        var draft = {};
        FIELD_IDS.forEach(function (id) {
          var el = document.getElementById(id);
          if (el) { draft[id] = el.value; }
        });
        SS.store.set(DRAFT_KEY, draft);
        draftBtn.textContent = "Brouillon enregistré ✓";
        setTimeout(function () { draftBtn.textContent = "Enregistrer le brouillon"; }, 2500);
      });

      /* Restauration du brouillon à l'ouverture (hors mode « modifier »). */
      var draft = SS.store.get(DRAFT_KEY, null);
      if (draft && !editId) {
        FIELD_IDS.forEach(function (id) {
          var el = document.getElementById(id);
          if (el && draft[id]) { el.value = draft[id]; }
        });
        var restoreNote = document.createElement("p");
        restoreNote.className = "notice";
        restoreNote.setAttribute("role", "status");
        restoreNote.textContent = "Votre brouillon a été restauré : reprenez là où vous en étiez.";
        form.insertBefore(restoreNote, form.firstChild);
      }
    }

    /* Récapitulatif permanent sous le formulaire. */
    function updateRecap() {
      var recap = document.getElementById("wizard-recap");
      if (!recap) { return; }
      var parts = [];
      var title = document.getElementById("pub-title").value.trim();
      var company = document.getElementById("pub-company").value.trim();
      var city = document.getElementById("pub-city").value.trim();
      var contract = document.getElementById("pub-contract").value;
      if (title) { parts.push(title); }
      if (company) { parts.push(company); }
      if (city) { parts.push(city); }
      if (contract) { parts.push(contract); }
      recap.hidden = parts.length === 0;
      recap.textContent = parts.length ? "Votre annonce : " + parts.join(" · ") : "";
    }

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      if (!validateStep(current)) { return; }
      saveOffer();
      goTo(STEPS); /* étape 6 : confirmation */
    });

    function goTo(step) {
      current = Math.max(1, Math.min(STEPS, step));
      document.querySelectorAll(".wizard-step").forEach(function (el, index) {
        el.classList.toggle("is-active", index + 1 === current);
      });
      document.querySelectorAll(".progress-step").forEach(function (el, index) {
        el.classList.toggle("is-active", index + 1 === current);
        el.classList.toggle("is-done", index + 1 < current);
      });
      prevBtn.hidden = current === 1 || current === STEPS;
      nextBtn.hidden = current >= 5;
      submitBtn.hidden = current !== 5;
      var draftButton = document.getElementById("wizard-draft");
      if (draftButton) { draftButton.hidden = current === STEPS; }
      updateRecap();
      wizard.scrollIntoView({ behavior: "smooth", block: "start" });
      var heading = document.querySelector(".wizard-step.is-active h2");
      if (heading) { heading.setAttribute("tabindex", "-1"); heading.focus({ preventScroll: true }); }
    }

    /* Valide uniquement les champs requis de l'étape affichée. */
    function validateStep(step) {
      var panel = document.querySelectorAll(".wizard-step")[step - 1];
      var valid = true;
      panel.querySelectorAll("[required]").forEach(function (input) {
        var field = input.closest(".field");
        var error = field ? field.querySelector(".field-error") : null;
        var ok = input.type === "checkbox" ? input.checked : input.value.trim() !== "";
        if (ok && input.type === "email") {
          ok = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(input.value.trim());
        }
        if (field) { field.classList.toggle("has-error", !ok); }
        if (error) { error.hidden = ok; }
        if (!ok) { valid = false; }
      });
      return valid;
    }

    /* Aperçu (étape 5) construit à partir des valeurs saisies. */
    function buildPreview() {
      var e = SS.escapeHtml;
      var get = function (id) { return (document.getElementById(id).value || "").trim(); };
      document.getElementById("offer-preview").innerHTML =
        "<h3>" + e(get("pub-title")) + "</h3>" +
        '<p class="text-muted">' + e(get("pub-company")) + " — " + e(get("pub-city")) + "</p>" +
        "<dl>" +
          "<div><dt>Contrat</dt><dd>" + e(get("pub-contract")) + (get("pub-duration") ? " — " + e(get("pub-duration")) : "") + "</dd></div>" +
          "<div><dt>Temps de travail</dt><dd>" + e(get("pub-time")) + "</dd></div>" +
          "<div><dt>Salaire</dt><dd>" + e(get("pub-salary") || "Selon profil") + "</dd></div>" +
          "<div><dt>Télétravail</dt><dd>" + e(remoteLabel(get("pub-remote"))) + "</dd></div>" +
          "<div><dt>Candidatures envoyées à</dt><dd>" + e(get("pub-email")) + "</dd></div>" +
          "<div><dt>Expire le</dt><dd>" + e(SS.formatDate(get("pub-expiry"))) + "</dd></div>" +
        "</dl>" +
        '<p class="preview-text">' + e(get("pub-description")) + "</p>";
    }

    function remoteLabel(value) {
      if (value === "complet") { return "Télétravail complet"; }
      if (value === "partiel") { return "Télétravail partiel"; }
      return "Sur site";
    }

    /* Enregistrement de l'offre dans le stockage local (démonstration).
       En production : envoi vers l'API WordPress (APP_CONFIG.api). */
    function saveOffer() {
      var get = function (id) { return (document.getElementById(id).value || "").trim(); };
      var lines = function (value) {
        return value.split("\n").map(function (l) { return l.trim(); }).filter(Boolean);
      };
      var categorySelect = document.getElementById("pub-category");
      var offer = {
        id: "offre-" + Date.now(),
        titre: get("pub-title"),
        entrepriseId: APP_CONFIG.demoCompany.id,
        entrepriseNom: get("pub-company"),
        ville: get("pub-city"),
        departement: "",
        contrat: get("pub-contract"),
        duree: get("pub-duration") || null,
        tempsTravail: get("pub-time"),
        salaire: get("pub-salary") || "Salaire selon profil",
        salaireAnnuel: null,
        teletravail: get("pub-remote") || "non",
        categorie: categorySelect.value,
        categorieLabel: categorySelect.options[categorySelect.selectedIndex].textContent,
        datePublication: new Date().toISOString().slice(0, 10),
        dateExpiration: get("pub-expiry"),
        statut: "active",
        resume: get("pub-description").slice(0, 180),
        description: get("pub-description"),
        missions: lines(get("pub-missions")),
        profil: lines(get("pub-profile")),
        competences: get("pub-skills").split(",").map(function (s) { return s.trim(); }).filter(Boolean),
        avantages: [],
        email: get("pub-email")
      };
      var custom = SS.store.get(APP_CONFIG.storage.customOffers, []);
      custom.push(offer);
      SS.store.set(APP_CONFIG.storage.customOffers, custom);
      SS.store.remove("ss_offre_brouillon");

      var link = document.getElementById("published-offer-link");
      if (link) { link.href = "offre-detail.html?id=" + encodeURIComponent(offer.id); }
    }

    function setValue(id, value) {
      var el = document.getElementById(id);
      if (el) { el.value = value; }
    }
  });
})();

/**
 * Publication d'un savoir-faire : formulaire en 6 étapes avec ajout et
 * suppression dynamiques des étapes de la méthode. Enregistrement local
 * (démonstration) avec le statut « en attente de validation » pour
 * illustrer le futur circuit de modération WordPress.
 */
(function () {
  "use strict";

  var PANELS = 6;
  var current = 1;
  var stepIndex = 0;

  document.addEventListener("DOMContentLoaded", function () {
    var wizard = document.getElementById("kh-publish-wizard");
    if (!wizard) { return; }

    var form = document.getElementById("kh-publish-form");
    var prevBtn = document.getElementById("khw-prev");
    var nextBtn = document.getElementById("khw-next");
    var submitBtn = document.getElementById("khw-submit");

    /* Deux étapes de méthode proposées au départ. */
    addStep();
    addStep();

    document.getElementById("add-step").addEventListener("click", function () {
      addStep();
    });

    prevBtn.addEventListener("click", function () { goTo(current - 1); });
    nextBtn.addEventListener("click", function () {
      if (!validatePanel(current)) { return; }
      if (current + 1 === 6) { buildPreview(); }
      goTo(current + 1);
    });

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      if (!validatePanel(current)) { return; }
      savePublication();
      document.getElementById("khw-confirmation").hidden = false;
      document.getElementById("khw-panels").hidden = true;
      document.querySelector(".wizard-nav").hidden = true;
      document.getElementById("khw-confirmation").focus();
    });

    /* ---- Navigation entre les panneaux ---- */
    function goTo(panel) {
      current = Math.max(1, Math.min(PANELS, panel));
      document.querySelectorAll(".wizard-step").forEach(function (el, index) {
        el.classList.toggle("is-active", index + 1 === current);
      });
      document.querySelectorAll(".progress-step").forEach(function (el, index) {
        el.classList.toggle("is-active", index + 1 === current);
        el.classList.toggle("is-done", index + 1 < current);
      });
      prevBtn.hidden = current === 1;
      nextBtn.hidden = current >= PANELS;
      submitBtn.hidden = current !== PANELS;
      wizard.scrollIntoView({ behavior: "smooth", block: "start" });
    }

    function validatePanel(panel) {
      var el = document.querySelectorAll(".wizard-step")[panel - 1];
      var valid = true;
      el.querySelectorAll("[required]").forEach(function (input) {
        var field = input.closest(".field");
        var error = field ? field.querySelector(".field-error") : null;
        var ok = input.type === "checkbox" ? input.checked : input.value.trim() !== "";
        if (field) { field.classList.toggle("has-error", !ok); }
        if (error) { error.hidden = ok; }
        if (!ok) { valid = false; }
      });
      return valid;
    }

    /* ---- Étapes dynamiques (ajout / suppression) ---- */
    function addStep() {
      stepIndex += 1;
      var container = document.getElementById("dynamic-steps");
      var block = document.createElement("div");
      block.className = "dyn-step";
      block.innerHTML =
        '<div class="dyn-step__head">' +
          "<h3>Étape</h3>" +
          '<button type="button" class="btn btn-danger btn-sm" data-remove-step>Supprimer</button>' +
        "</div>" +
        '<div class="field">' +
          '<label for="step-title-' + stepIndex + '">Titre de l\'étape *</label>' +
          '<input type="text" id="step-title-' + stepIndex + '" data-step-title required>' +
          '<p class="field-error" hidden>Veuillez donner un titre à cette étape.</p>' +
        "</div>" +
        '<div class="field">' +
          '<label for="step-text-' + stepIndex + '">Explication *</label>' +
          '<textarea id="step-text-' + stepIndex + '" data-step-text required></textarea>' +
          '<p class="field-error" hidden>Veuillez expliquer cette étape.</p>' +
        "</div>" +
        '<div class="field">' +
          '<label for="step-tip-' + stepIndex + '">Conseil complémentaire (facultatif)</label>' +
          '<input type="text" id="step-tip-' + stepIndex + '" data-step-tip>' +
        "</div>" +
        '<div class="field">' +
          '<span class="hint">Image de l\'étape (simulée dans ce prototype)</span>' +
          '<label class="file-drop" for="step-img-' + stepIndex + '">Joindre une image' +
            '<input type="file" id="step-img-' + stepIndex + '" accept="image/*">' +
            '<span class="file-name"></span>' +
          "</label>" +
        "</div>";
      container.appendChild(block);
      renumberSteps();

      block.querySelector("[data-remove-step]").addEventListener("click", function () {
        if (container.querySelectorAll(".dyn-step").length <= 1) { return; }
        block.remove();
        renumberSteps();
      });

      var fileInput = block.querySelector("input[type=file]");
      fileInput.addEventListener("change", function () {
        block.querySelector(".file-name").textContent =
          fileInput.files.length ? fileInput.files[0].name : "";
      });
    }

    function renumberSteps() {
      var blocks = document.querySelectorAll("#dynamic-steps .dyn-step");
      blocks.forEach(function (b, i) {
        b.querySelector("h3").textContent = "Étape " + (i + 1);
        b.querySelector("[data-remove-step]").disabled = blocks.length <= 1;
      });
    }

    /* ---- Aperçu ---- */
    function buildPreview() {
      var e = SS.escapeHtml;
      var get = function (id) { return (document.getElementById(id).value || "").trim(); };
      var steps = collectSteps();
      var diffSelect = document.getElementById("khp-difficulte");
      document.getElementById("khp-preview").innerHTML =
        "<h3>" + e(get("khp-titre")) + "</h3>" +
        '<p class="text-muted">' + e(get("khp-metier")) + " · " +
          e(diffSelect.options[diffSelect.selectedIndex].textContent) + " · " + e(get("khp-duree")) + "</p>" +
        "<p>" + e(get("khp-resume")) + "</p>" +
        "<dl>" +
          "<div><dt>Catégorie</dt><dd>" + e(document.getElementById("khp-categorie").value) + "</dd></div>" +
          "<div><dt>Étapes</dt><dd>" + steps.length + "</dd></div>" +
          "<div><dt>Entreprise associée</dt><dd>" + (e(get("khp-entreprise")) || "Aucune") + "</dd></div>" +
        "</dl>" +
        "<ol>" + steps.map(function (s) { return "<li>" + e(s.titre) + "</li>"; }).join("") + "</ol>";
    }

    function collectSteps() {
      return Array.prototype.map.call(
        document.querySelectorAll("#dynamic-steps .dyn-step"),
        function (block) {
          return {
            titre: block.querySelector("[data-step-title]").value.trim(),
            texte: block.querySelector("[data-step-text]").value.trim(),
            conseil: block.querySelector("[data-step-tip]").value.trim() || null,
            image: null
          };
        }
      ).filter(function (s) { return s.titre || s.texte; });
    }

    /* ---- Enregistrement local avec statut de modération ---- */
    function savePublication() {
      var get = function (id) { return (document.getElementById(id).value || "").trim(); };
      var lines = function (v) {
        return v.split("\n").map(function (l) { return l.trim(); }).filter(Boolean);
      };
      var diffSelect = document.getElementById("khp-difficulte");
      var pub = {
        id: "sf-" + Date.now(),
        titre: get("khp-titre"),
        resume: get("khp-resume"),
        metier: get("khp-metier"),
        categorie: document.getElementById("khp-categorie").value,
        auteur: {
          nom: get("khp-nom") || "Professionnel anonyme",
          metier: get("khp-metier"),
          entreprise: get("khp-entreprise") || null,
          entrepriseId: null,
          ville: get("khp-ville") || ""
        },
        difficulte: diffSelect.value,
        difficulteLabel: diffSelect.options[diffSelect.selectedIndex].textContent,
        duree: get("khp-duree"),
        dureeDetail: [],
        materiel: lines(get("khp-materiel")),
        intro: get("khp-resume"),
        etapes: collectSteps(),
        conseils: lines(get("khp-conseils")),
        erreurs: lines(get("khp-erreurs")),
        resultat: get("khp-resultat"),
        image: "assets/images/sf-resultat.svg",
        imageAlt: "Illustration générique de la publication",
        galerie: [],
        datePublication: new Date().toISOString().slice(0, 10),
        vues: 0,
        statut: "attente",
        notes: { total: 0, criteres: {}, repartition: { "5": 0, "4": 0, "3": 0, "2": 0, "1": 0 } },
        commentaires: []
      };
      var custom = SS.store.get(APP_CONFIG.storage.customKnowhow, []);
      custom.push(pub);
      SS.store.set(APP_CONFIG.storage.customKnowhow, custom);

      var link = document.getElementById("khw-published-link");
      if (link) { link.href = "savoir-faire-detail.html?id=" + encodeURIComponent(pub.id); }
    }
  });
})();

/**
 * Contexte de publication selon ?type : un candidat partage un savoir-faire
 * (preuve de compétence) ; un recruteur publie un contenu entreprise (marque
 * employeur). Adapte le titre/intro sans changer le formulaire.
 */
(function () {
  "use strict";
  document.addEventListener("DOMContentLoaded", function () {
    if (!window.SS || !SS.param) { return; }
    var type = SS.param("type");
    var s = SS.auth && SS.auth.get ? SS.auth.get() : null;
    if (!type && s) { type = s.role === "employer" ? "entreprise" : "candidat"; }
    if (type !== "entreprise") { return; }
    var title = document.getElementById("kh-publish-title");
    var intro = document.getElementById("kh-publish-intro");
    var crumb = document.getElementById("kh-publish-crumb");
    if (title) { title.textContent = "Publier un contenu entreprise"; }
    if (crumb) { crumb.textContent = "Contenu entreprise"; }
    if (intro) {
      intro.textContent = "Présentez un métier, une journée type, vos coulisses ou vos méthodes de travail : " +
        "ces contenus renforcent votre marque employeur. Six étapes guidées, un aperçu avant envoi, " +
        "puis une relecture par notre équipe avant la mise en ligne.";
    }
  });
})();

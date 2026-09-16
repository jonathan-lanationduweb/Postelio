/**
 * Parcours de candidature RÉEL (guest ou connecté) sur la fiche offre.
 *
 * S'active quand l'offre est identifiée par un UUID réel (?id=UUID) : récupère l'offre via
 * GET /jobs/{uuid} (questions de présélection publiques incluses) et pilote la fenêtre
 * #apply-modal existante — sans redesign. Guest : prénom, nom, e-mail, CV (POST
 * /guest/files/cv), présélection, message, consentement → POST /jobs/{uuid}/guest-applications
 * (202 + « Confirmez votre e-mail »). Connecté (candidat) : identité préremplie depuis /me,
 * CV du profil, → POST /jobs/{uuid}/applications.
 *
 * Le backend reste l'autorité (présélection, consentement, format CV) : le front affiche
 * proprement les erreurs 422/409/429/5xx/réseau.
 */
(function () {
  "use strict";

  var UUID_RE = /^[0-9a-fA-F-]{36}$/;

  function e(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
  function api() { return window.PostelioAPI; }
  function bearer() {
    try { return (window.PostelioAuth && PostelioAuth.tokens && PostelioAuth.tokens.get()) || null; } catch (x) { return null; }
  }
  function loggedCandidate() {
    try { return !!(window.PostelioAuth && PostelioAuth.session && PostelioAuth.session.isCandidate && PostelioAuth.session.isCandidate()); } catch (x) { return false; }
  }

  document.addEventListener("DOMContentLoaded", function () {
    var dialog = document.getElementById("apply-modal");
    var openBtn = document.getElementById("apply-button");
    var form = document.getElementById("apply-form");
    if (!dialog || !openBtn || !form || !api()) { return; }

    var id = new URLSearchParams(window.location.search).get("id") || "";
    if (!UUID_RE.test(id)) { return; } // offre non réelle (démo) : laissé au parcours existant

    api().client.get("/jobs/" + id).then(function (res) {
      init(res.data || {});
    }, function () { /* offre introuvable : bouton laissé inactif proprement */ });

    function init(offer) {
      var titleEl = document.getElementById("apply-offer-title");
      var companyEl = document.getElementById("apply-offer-company");
      if (titleEl) { titleEl.textContent = offer.titre || "Cette offre"; }
      if (companyEl) { companyEl.textContent = (offer.company && offer.company.nom) || ""; }

      var guest = !loggedCandidate();
      var guestCvRef = null; // référence CV guest après upload

      // Remplace le contenu du formulaire par le parcours réel (guest ou connecté).
      form.innerHTML = buildFormHtml(offer, guest);
      wireCv(guest, function (ref) { guestCvRef = ref; });

      openBtn.removeAttribute("disabled");
      var clone = openBtn.cloneNode(true); // retire d'éventuels écouteurs hérités
      openBtn.parentNode.replaceChild(clone, openBtn);
      openBtn = clone;
      openBtn.addEventListener("click", function () {
        if (loggedCandidate()) { prefillIdentity(); }
        if (window.SS && SS.openModal) { SS.openModal(dialog); } else { dialog.showModal && dialog.showModal(); }
      });

      form.addEventListener("submit", function (ev) {
        ev.preventDefault();
        submit(offer, guest, function () { return guestCvRef; });
      });
    }

    function buildFormHtml(offer, guest) {
      var h = '<div class="apply-alert" id="apply-alert" role="alert" hidden></div>';
      if (guest) {
        h += '<div class="field"><label for="ga-first">Prénom *</label><input type="text" id="ga-first" autocomplete="given-name" required></div>' +
          '<div class="field"><label for="ga-last">Nom *</label><input type="text" id="ga-last" autocomplete="family-name" required></div>' +
          '<div class="field"><label for="ga-email">E-mail *</label><input type="email" id="ga-email" autocomplete="email" required></div>';
      } else {
        h += '<dl class="apply-identity" id="apply-identity"></dl>';
      }
      // CV
      h += '<div class="field"><label for="ga-cv">CV (PDF) ' + (guest ? '' : '') + '</label>' +
        '<input type="file" id="ga-cv" accept="application/pdf,.pdf">' +
        '<p class="hint" id="ga-cv-status">Format PDF, 10 Mo maximum.</p></div>';
      // Présélection
      var qs = Array.isArray(offer.questions_preselection) ? offer.questions_preselection : [];
      if (qs.length) {
        h += '<fieldset class="apply-screening"><legend>Questions du recruteur</legend>' + qs.map(questionField).join("") + "</fieldset>";
      }
      // Message
      h += '<div class="field"><label for="ga-message">Message au recruteur (facultatif)</label><textarea id="ga-message" rows="4"></textarea></div>';
      // Consentement (décoché par défaut)
      h += '<div class="field field--checkbox"><label><input type="checkbox" id="ga-consent"> ' +
        "J'accepte que ces informations soient transmises au recruteur pour traiter ma candidature et conservées selon la " +
        '<a href="confidentialite.html" target="_blank" rel="noopener">politique de confidentialité</a>. *</label></div>';
      h += '<div class="form-actions"><button type="submit" class="btn btn-accent btn-block" id="ga-submit">Envoyer ma candidature</button></div>';
      return h;
    }

    function questionField(q) {
      var id = "ga-q-" + e(q.id);
      var req = q.required ? " *" : "";
      var lbl = '<label for="' + id + '">' + e(q.label || q.id) + req + "</label>";
      if (q.type === "oui_non") {
        return '<div class="field">' + lbl + '<select id="' + id + '" data-qid="' + e(q.id) + '"><option value="">—</option><option value="oui">Oui</option><option value="non">Non</option></select></div>';
      }
      if (q.type === "choix" && Array.isArray(q.options)) {
        return '<div class="field">' + lbl + '<select id="' + id + '" data-qid="' + e(q.id) + '"><option value="">—</option>' +
          q.options.map(function (o) { return '<option value="' + e(o) + '">' + e(o) + "</option>"; }).join("") + "</select></div>";
      }
      if (q.type === "nombre") {
        return '<div class="field">' + lbl + '<input type="number" id="' + id + '" data-qid="' + e(q.id) + '"></div>';
      }
      return '<div class="field">' + lbl + '<input type="text" id="' + id + '" data-qid="' + e(q.id) + '"></div>';
    }

    function collectScreening() {
      var out = {};
      form.querySelectorAll("[data-qid]").forEach(function (el) {
        var v = el.value;
        if (v !== "" && v != null) { out[el.getAttribute("data-qid")] = v; }
      });
      return out;
    }

    function prefillIdentity() {
      var box = document.getElementById("apply-identity");
      if (!box) { return; }
      var u = (window.PostelioAuth && PostelioAuth.session && PostelioAuth.session.snapshot && PostelioAuth.session.snapshot()) || {};
      box.innerHTML =
        "<div><dt>Prénom / Nom</dt><dd>" + e(u.display_name || u.name || "Votre profil") + "</dd></div>" +
        "<div><dt>E-mail</dt><dd>" + e(u.email || "") + "</dd></div>";
    }

    // Upload CV guest dès sélection (multipart → raw fetch ; le client JSON ne gère pas FormData).
    function wireCv(guest, setRef) {
      var input = document.getElementById("ga-cv");
      var status = document.getElementById("ga-cv-status");
      if (!input) { return; }
      input.addEventListener("change", function () {
        var file = input.files && input.files[0];
        if (!file) { return; }
        if (file.type && file.type !== "application/pdf") { status.textContent = "Seuls les fichiers PDF sont acceptés."; input.value = ""; return; }
        if (file.size > 10 * 1024 * 1024) { status.textContent = "Fichier trop volumineux (10 Mo maximum)."; input.value = ""; return; }
        if (!guest) { status.textContent = "CV sélectionné : " + file.name; return; }
        status.textContent = "Envoi du CV…";
        var fd = new FormData(); fd.append("file", file, file.name);
        fetch(api().config.apiBaseUrl + "/guest/files/cv", { method: "POST", body: fd, credentials: "omit" })
          .then(function (r) { return r.text().then(function (t) { return { ok: r.ok, status: r.status, body: t }; }); })
          .then(function (r) {
            var p = null; try { p = JSON.parse(r.body); } catch (x) { p = null; }
            if (r.ok && p && p.data && p.data.cv_reference) {
              setRef(p.data.cv_reference);
              status.textContent = "CV joint : " + e(file.name);
            } else if (r.status === 429) {
              status.textContent = "Trop d'envois. Réessayez dans quelques minutes.";
            } else if (r.status === 415 || r.status === 422) {
              status.textContent = (p && p.error && p.error.message) ? p.error.message : "Fichier invalide.";
            } else {
              status.textContent = "Envoi du CV impossible. Réessayez.";
            }
          }, function () { status.textContent = "Erreur réseau lors de l'envoi du CV."; });
      });
    }

    function alertBox(msg) {
      var a = document.getElementById("apply-alert");
      if (a) { a.textContent = msg; a.hidden = false; }
    }
    function fieldErrors(details) {
      if (!details) { return ""; }
      var first = null;
      for (var k in details) { if (Object.prototype.hasOwnProperty.call(details, k)) { first = details[k]; break; } }
      return first ? String(first) : "";
    }

    function submit(offer, guest, getCvRef) {
      var a = document.getElementById("apply-alert"); if (a) { a.hidden = true; }
      var btn = document.getElementById("ga-submit");
      if (!document.getElementById("ga-consent") || !document.getElementById("ga-consent").checked) {
        alertBox("Vous devez accepter la transmission de votre candidature pour continuer."); return;
      }
      var payload = { screening_answers: collectScreening(), message: (document.getElementById("ga-message") || {}).value || "" };
      var path, options;
      if (guest) {
        payload.first_name = (document.getElementById("ga-first") || {}).value || "";
        payload.last_name = (document.getElementById("ga-last") || {}).value || "";
        payload.email = (document.getElementById("ga-email") || {}).value || "";
        payload.consent = true;
        payload.cv_reference = getCvRef() || "";
        if (!payload.first_name || !payload.last_name || !payload.email) { alertBox("Prénom, nom et e-mail sont requis."); return; }
        path = "/jobs/" + offer.uuid + "/guest-applications";
        options = { body: payload };
      } else {
        path = "/jobs/" + offer.uuid + "/applications";
        options = { body: payload, bearer: bearer() };
      }
      if (btn) { btn.disabled = true; btn.setAttribute("aria-busy", "true"); }

      api().client.post(path, options).then(function () {
        if (guest) {
          form.innerHTML = '<div class="apply-success"><h3>Confirmez votre candidature</h3>' +
            "<p>Nous venons de vous envoyer un e-mail à <strong>" + e(payload.email) + "</strong>. " +
            "Cliquez sur le lien reçu pour finaliser l'envoi de votre candidature au recruteur.</p></div>";
        } else {
          form.innerHTML = '<div class="apply-success"><h3>Candidature envoyée</h3>' +
            '<p>Votre candidature a bien été transmise. Suivez-la depuis <a href="espace-candidat.html">votre espace</a>.</p></div>';
        }
      }, function (err) {
        if (btn) { btn.disabled = false; btn.removeAttribute("aria-busy"); }
        var msg;
        if (err.status === 422) { msg = fieldErrors(err.details) || "Certaines informations sont invalides (présélection ?)."; }
        else if (err.status === 409) { msg = err.message ? String(err.message) : "Vous avez déjà postulé à cette offre."; }
        else if (err.status === 429) { var w = err.details && err.details.retry_after ? Math.ceil(err.details.retry_after / 60) : 0; msg = "Trop de tentatives." + (w ? " Réessayez dans environ " + w + " minute(s)." : " Réessayez plus tard."); }
        else if (err.status === 401 || err.status === 403) { msg = "Vous devez être connecté(e) en tant que candidat pour postuler ainsi."; }
        else if (err.status === 404) { msg = "Cette offre n'est plus disponible."; }
        else if (err.status === 0) { msg = "Impossible de contacter Postelio. Vérifiez votre connexion."; }
        else { msg = err.userMessage ? err.userMessage() : "Une erreur est survenue. Réessayez plus tard."; }
        alertBox(msg);
      });
    }
  });
})();

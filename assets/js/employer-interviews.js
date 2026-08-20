/**
 * Espace recruteur — Entretiens (espace-entreprise-entretiens.html).
 *
 * Liste des entretiens + planification (modale dynamique). Le formulaire adapte
 * ses champs au format choisi (visio / téléphone / dans nos locaux), propose un
 * aperçu avant envoi, puis enregistre l'entretien. Le candidat reçoit ensuite
 * (côté espace candidat, simulé) une notification pour CONFIRMER le rendez-vous.
 *
 * Aucun backend (§31) : toute la logique est en localStorage.
 *   ss_interviews_v1   — liste des entretiens (clé inchangée).
 *   ss_company_profile — lu (lecture seule) pour préremplir l'adresse.
 */
(function () {
  "use strict";

  var KEY = "ss_interviews_v1";
  var PROFILE_KEY = "ss_company_profile";

  /* Adresse de repli si le profil entreprise n'en contient pas encore. */
  var DEMO_ADDRESS = { adresse: "12 rue de la République", complement: "", cp: "69002", ville: "Lyon", contact: "Claire Martin", contactTel: "04 72 00 00 00" };

  /* Libellés + classes de badge par statut (§8, très différenciés). */
  var STATUTS = {
    confirme:        { label: "Confirmé",                cls: "status-badge--confirme" },
    attente:         { label: "En attente",             cls: "status-badge--attente" },
    nouveau_creneau: { label: "Nouveau créneau proposé", cls: "status-badge--creneau" },
    annule:          { label: "Annulé",                 cls: "status-badge--annule" },
    realise:         { label: "Réalisé",                cls: "status-badge--realise" }
  };

  var editingId = null; /* id de l'entretien en cours d'édition, ou null (création) */

  document.addEventListener("DOMContentLoaded", function () {
    if (!window.EMP || !EMP.ready) { return; }
    if (!document.getElementById("interviews-list")) { return; }
    seedIfEmpty();
    buildHeader();
    buildModal();
    render();
  });

  var SEED_VERSION = "2026-08-20-iv-dynamic";
  var SEED_KEY = "ss_interviews_seed";

  function seedIfEmpty() {
    /* Re-seed si la structure du jeu de démo a changé (nouveaux formats/statuts),
       sinon un ancien cache masquerait les nouveautés. */
    var stale = SS.store.get(SEED_KEY, null) !== SEED_VERSION;
    if (SS.store.get(KEY, null) && !stale) { return; }
    SS.store.set(SEED_KEY, SEED_VERSION);
    SS.store.set(KEY, [
      { id: "iv1", nom: "Julie Martin", poste: "Assistante commerciale", offre: "Assistant(e) commercial(e) — CDI",
        date: EMP.dateFromToday(3), heure: "14:30", duree: "45", format: "Visioconférence",
        lien: "https://meet.postelio.exemple/julie-martin", instructions: "Merci de vous connecter 5 minutes avant.",
        statut: "confirme" },
      { id: "iv2", nom: "Thomas Ravel", poste: "Préparateur de commandes", offre: "Préparateur de commandes — CDI",
        date: EMP.dateFromToday(4), heure: "10:00", duree: "30", format: "Dans nos locaux",
        adresse: "12 rue de la République", complement: "Bâtiment B, 2e étage", cp: "69002", ville: "Lyon",
        contact: "Claire Martin", contactTel: "04 72 00 00 00",
        instructions: "Présentez-vous à l'accueil et demandez Claire Martin.",
        statut: "attente" },
      { id: "iv3", nom: "Inès Fabre", poste: "Chargée de communication", offre: "Chargé(e) de communication — CDD",
        date: EMP.dateFromToday(6), heure: "16:15", duree: "45", format: "Téléphone",
        tel: "06 12 34 56 78", instructions: "Nous vous appellerons sur le numéro renseigné dans votre profil.",
        statut: "nouveau_creneau" }
    ]);
  }

  function getAll() { return SS.store.get(KEY, []); }
  function setAll(list) { SS.store.set(KEY, list); }

  /* ============================================================
     A. En-tête : titre → phrase descriptive → bouton → espace
     ============================================================ */
  function buildHeader() {
    var list = document.getElementById("interviews-list");
    var head = document.createElement("div");
    head.className = "iv-head";
    head.innerHTML =
      '<p class="iv-head__hint">Planifiez vos rendez-vous et suivez leur statut. Après envoi, le candidat reçoit une notification pour confirmer le créneau.</p>' +
      '<button type="button" class="btn btn-accent" id="iv-plan-btn">+ Planifier un entretien</button>';
    list.parentNode.insertBefore(head, list);
  }

  /* ============================================================
     D. Rendu de la liste (statuts très différenciés)
     ============================================================ */
  function render() {
    var box = document.getElementById("interviews-list");
    if (!box) { return; }
    var e = SS.escapeHtml;
    var items = getAll();

    if (!items.length) {
      box.innerHTML = '<div class="empty-state"><h3>Aucun entretien planifié</h3>' +
        '<p>Cliquez sur « Planifier un entretien » ci-dessus, ou proposez-en un depuis le pipeline de candidatures.</p>' +
        '<p><button type="button" class="btn btn-accent" id="iv-plan-empty">+ Planifier un entretien</button></p></div>';
      var emptyBtn = document.getElementById("iv-plan-empty");
      if (emptyBtn) { emptyBtn.addEventListener("click", openCreate); }
      return;
    }

    box.innerHTML = items.map(function (it) {
      var st = STATUTS[it.statut] || STATUTS.attente;
      var badge = '<span class="status-badge status-badge--dot ' + st.cls + '">' + e(st.label) + '</span>';
      var when = e(SS.formatDate(it.date)) + " · " + e(it.heure) + (it.duree ? " · " + e(it.duree) + " min" : "");
      var mode = e(it.format || "") + (formatLieu(it) ? " — " + e(formatLieu(it)) : "");
      var isClosed = it.statut === "annule" || it.statut === "realise";

      var menu =
        '<details class="row-menu">' +
          '<summary class="btn btn-ghost btn-sm" aria-label="Autres actions">⋯</summary>' +
          '<div class="row-menu__pop" role="menu">' +
            '<button type="button" role="menuitem" data-iv-edit="' + e(it.id) + '">Modifier</button>' +
            (isClosed ? "" :
              '<button type="button" role="menuitem" data-iv-done="' + e(it.id) + '">Marquer comme réalisé</button>' +
              '<button type="button" role="menuitem" class="row-menu__danger" data-iv-cancel="' + e(it.id) + '">Annuler l\'entretien</button>') +
          '</div>' +
        '</details>';

      return '<article class="appli-card interview-card" data-statut="' + e(it.statut) + '">' +
          '<div class="appli-card__top">' +
            '<div class="interview-card__who"><strong>' + e(it.nom) + '</strong><br><span class="text-muted">' + e(it.poste) + '</span></div>' +
            badge +
          '</div>' +
          '<p class="interview-card__when">' + when + '</p>' +
          '<p class="interview-card__mode text-muted">' + mode + '</p>' +
          (it.offre ? '<p class="interview-card__offer text-muted">Offre : ' + e(it.offre) + '</p>' : "") +
          '<div class="row-actions">' +
            '<button type="button" class="btn btn-outline btn-sm" data-toast="Ouverture du profil candidat (démonstration).">Voir le profil</button>' +
            '<a class="btn btn-ghost btn-sm" href="espace-entreprise-messages.html?to=' + encodeURIComponent(it.nom) + '">Message</a>' +
            menu +
          '</div>' +
        '</article>';
    }).join("");

    box.querySelectorAll("[data-iv-cancel]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        if (!window.confirm("Annuler cet entretien ? Le candidat en sera informé.")) { return; }
        updateStatut(btn.getAttribute("data-iv-cancel"), "annule");
        SS.toast("Entretien annulé.");
      });
    });
    box.querySelectorAll("[data-iv-done]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        updateStatut(btn.getAttribute("data-iv-done"), "realise");
        SS.toast("Entretien marqué comme réalisé.");
      });
    });
    box.querySelectorAll("[data-iv-edit]").forEach(function (btn) {
      btn.addEventListener("click", function () { openEdit(btn.getAttribute("data-iv-edit")); });
    });
  }

  function updateStatut(id, statut) {
    setAll(getAll().map(function (x) { return x.id === id ? Object.assign({}, x, { statut: statut }) : x; }));
    render();
  }

  /* Ligne « lieu / lien » calculée selon le format. */
  function formatLieu(iv) {
    if (iv.format === "Visioconférence") { return iv.lien || ""; }
    if (iv.format === "Téléphone") { return iv.tel || ""; }
    if (iv.format === "Dans nos locaux") {
      return [iv.adresse, iv.complement, [iv.cp, iv.ville].filter(Boolean).join(" ")]
        .filter(Boolean).join(", ");
    }
    return iv.lieu || "";
  }

  /* ============================================================
     B/C. Modale « Planifier un entretien » (form dynamique + aperçu)
     ============================================================ */
  var overlay, lastFocus;

  function buildModal() {
    var e = SS.escapeHtml;
    var candidates = ["Julie Martin", "Thomas Ravel", "Inès Fabre", "Camille Reynaud", "Karim Haddad",
      "Sophie Lemaire", "Léa Dubois", "Malik Benhaddou", "Awa Diallo", "Autre candidat"];

    overlay = document.createElement("div");
    overlay.className = "modal-overlay";
    overlay.id = "iv-modal";
    overlay.hidden = true;
    overlay.innerHTML =
      '<div class="modal modal--wide" role="dialog" aria-modal="true" aria-labelledby="iv-modal-title">' +
        '<div class="modal__head">' +
          '<h2 class="modal__title" id="iv-modal-title">Planifier un entretien</h2>' +
          '<button type="button" class="modal-close" data-iv-close aria-label="Fermer">✕</button>' +
        '</div>' +
        '<div class="modal__body">' +

          /* ---- Vue formulaire ---- */
          '<form id="iv-form">' +
            '<div class="field"><label for="iv-nom">Candidat</label>' +
              '<select id="iv-nom">' + candidates.map(function (c) { return '<option>' + e(c) + "</option>"; }).join("") + "</select></div>" +
            '<div class="field"><label for="iv-offre">Offre concernée</label>' +
              '<input type="text" id="iv-offre" placeholder="Ex. : Assistant(e) commercial(e) — CDI"></div>' +
            '<div class="form-row">' +
              '<div class="field"><label for="iv-date">Date</label><input type="date" id="iv-date"></div>' +
              '<div class="field"><label for="iv-heure">Heure</label><input type="time" id="iv-heure" value="14:30"></div>' +
            '</div>' +
            '<div class="form-row">' +
              '<div class="field"><label for="iv-duree">Durée</label>' +
                '<select id="iv-duree"><option value="30">30 minutes</option><option value="45">45 minutes</option><option value="60">60 minutes</option></select></div>' +
              '<div class="field"><label for="iv-format">Format</label>' +
                '<select id="iv-format"><option>Visioconférence</option><option>Téléphone</option><option>Dans nos locaux</option></select></div>' +
            '</div>' +

            /* Bloc Visioconférence */
            '<div class="iv-fmt" id="iv-block-visio" data-fmt="Visioconférence">' +
              '<div class="field"><label for="iv-lien">Lien de visioconférence</label>' +
                '<input type="url" id="iv-lien" placeholder="https://meet…"></div>' +
              '<div class="field"><label for="iv-visio-instr">Instructions complémentaires</label>' +
                '<textarea id="iv-visio-instr" rows="2">Merci de vous connecter 5 minutes avant.</textarea></div>' +
            '</div>' +

            /* Bloc Téléphone */
            '<div class="iv-fmt" id="iv-block-tel" data-fmt="Téléphone" hidden>' +
              '<div class="field"><label for="iv-tel">Numéro utilisé pour l\'appel</label>' +
                '<input type="tel" id="iv-tel" placeholder="Ex. : 04 72 00 00 00"></div>' +
              '<div class="field"><label for="iv-tel-instr">Instructions</label>' +
                '<textarea id="iv-tel-instr" rows="2">Nous vous appellerons sur le numéro renseigné dans votre profil.</textarea></div>' +
            '</div>' +

            /* Bloc Dans nos locaux */
            '<div class="iv-fmt" id="iv-block-locaux" data-fmt="Dans nos locaux" hidden>' +
              '<div class="field field--checkbox">' +
                '<input type="checkbox" id="iv-use-company" checked>' +
                '<label for="iv-use-company">Utiliser l\'adresse de l\'entreprise</label>' +
              '</div>' +
              '<div class="field"><label for="iv-adresse">Adresse</label><input type="text" id="iv-adresse" placeholder="N° et rue"></div>' +
              '<div class="field"><label for="iv-complement">Complément d\'adresse</label><input type="text" id="iv-complement" placeholder="Bâtiment, étage, digicode…"></div>' +
              '<div class="form-row">' +
                '<div class="field"><label for="iv-cp">Code postal</label><input type="text" id="iv-cp" inputmode="numeric" placeholder="69002"></div>' +
                '<div class="field"><label for="iv-ville">Ville</label><input type="text" id="iv-ville" placeholder="Lyon"></div>' +
              '</div>' +
              '<div class="form-row">' +
                '<div class="field"><label for="iv-contact">Nom du contact sur place</label><input type="text" id="iv-contact" placeholder="Ex. : Claire Martin"></div>' +
                '<div class="field"><label for="iv-contact-tel">Téléphone du contact</label><input type="tel" id="iv-contact-tel" placeholder="04 72 00 00 00"></div>' +
              '</div>' +
              '<div class="field"><label for="iv-acces">Instructions d\'accès</label>' +
                '<textarea id="iv-acces" rows="2">Présentez-vous à l\'accueil et demandez le contact indiqué.</textarea></div>' +
            '</div>' +
          '</form>' +

          /* ---- Vue aperçu (§7) ---- */
          '<div id="iv-preview" hidden>' +
            '<p class="iv-preview__lead">Vérifiez la proposition avant de l\'envoyer au candidat.</p>' +
            '<dl class="iv-preview__grid" id="iv-preview-grid"></dl>' +
          '</div>' +

        '</div>' +

        /* ---- Actions ---- */
        '<div class="modal__actions" id="iv-actions-form">' +
          '<button type="button" class="btn btn-outline" data-iv-close>Annuler</button>' +
          '<button type="button" class="btn btn-primary" id="iv-to-preview">Aperçu avant envoi</button>' +
        '</div>' +
        '<div class="modal__actions" id="iv-actions-preview" hidden>' +
          '<button type="button" class="btn btn-outline" id="iv-back">Modifier</button>' +
          '<button type="button" class="btn btn-primary" id="iv-send">Envoyer la proposition</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(overlay);

    /* Dynamique du format (§3-6) */
    var formatSel = overlay.querySelector("#iv-format");
    formatSel.addEventListener("change", applyFormat);

    /* Case « Utiliser l'adresse de l'entreprise » */
    var useCompany = overlay.querySelector("#iv-use-company");
    useCompany.addEventListener("change", function () {
      if (useCompany.checked) { fillCompanyAddress(); }
    });

    /* Ouverture / fermeture */
    var planBtn = document.getElementById("iv-plan-btn");
    if (planBtn) { planBtn.addEventListener("click", openCreate); }
    overlay.querySelectorAll("[data-iv-close]").forEach(function (b) { b.addEventListener("click", close); });
    overlay.addEventListener("click", function (ev) { if (ev.target === overlay) { close(); } });

    /* Échap + focus piégé */
    overlay.addEventListener("keydown", function (ev) {
      if (ev.key === "Escape") { ev.preventDefault(); close(); return; }
      if (ev.key === "Tab") { trapFocus(ev); }
    });

    /* Navigation entre les vues */
    overlay.querySelector("#iv-to-preview").addEventListener("click", showPreview);
    overlay.querySelector("#iv-back").addEventListener("click", showForm);
    overlay.querySelector("#iv-send").addEventListener("click", submit);

    /* Fermer les menus « … » ouverts au clic extérieur */
    document.addEventListener("click", function (ev) {
      document.querySelectorAll(".row-menu[open]").forEach(function (d) {
        if (!d.contains(ev.target)) { d.open = false; }
      });
    });
  }

  /* ---- Champs dynamiques selon le format ---- */
  function applyFormat() {
    var current = overlay.querySelector("#iv-format").value;
    overlay.querySelectorAll(".iv-fmt").forEach(function (block) {
      block.hidden = block.getAttribute("data-fmt") !== current;
    });
  }

  /* ---- Préremplissage de l'adverse depuis le profil entreprise ---- */
  function fillCompanyAddress() {
    var addr = companyAddress();
    setVal("iv-adresse", addr.adresse);
    setVal("iv-complement", addr.complement);
    setVal("iv-cp", addr.cp);
    setVal("iv-ville", addr.ville);
    setVal("iv-contact", addr.contact);
    setVal("iv-contact-tel", addr.contactTel);
  }

  /* Lit ss_company_profile ; à défaut d'adresse, renvoie l'adresse de démo. */
  function companyAddress() {
    var p = SS.store.get(PROFILE_KEY, {}) || {};
    if (!p.adresse) {
      return Object.assign({}, DEMO_ADDRESS);
    }
    var parsed = parseAddress(p.adresse);
    return {
      adresse: parsed.rue || p.adresse,
      complement: "",
      cp: parsed.cp || "",
      ville: parsed.ville || p.ville || "",
      contact: DEMO_ADDRESS.contact,           /* le profil ne stocke pas de contact d'accueil */
      contactTel: p.telephone || DEMO_ADDRESS.contactTel
    };
  }

  /* Décompose « 12 quai … , 69006 Lyon » en { rue, cp, ville }. */
  function parseAddress(str) {
    var out = { rue: "", cp: "", ville: "" };
    var m = String(str || "").match(/^(.*?)[,\s]*\b(\d{5})\b\s*(.*)$/);
    if (m) {
      out.rue = m[1].replace(/[,\s]+$/, "").trim();
      out.cp = m[2];
      out.ville = m[3].trim();
    } else {
      out.rue = String(str || "").trim();
    }
    return out;
  }

  /* ---- Ouverture (création / édition) ---- */
  function openCreate() {
    editingId = null;
    overlay.querySelector("#iv-modal-title").textContent = "Planifier un entretien";
    resetForm();
    open();
  }

  function openEdit(id) {
    var iv = getAll().filter(function (x) { return x.id === id; })[0];
    if (!iv) { return; }
    editingId = id;
    overlay.querySelector("#iv-modal-title").textContent = "Modifier l'entretien";
    resetForm();
    setVal("iv-nom", iv.nom);
    setVal("iv-offre", iv.offre);
    setVal("iv-date", iv.date);
    setVal("iv-heure", iv.heure);
    setVal("iv-duree", iv.duree || "45");
    setVal("iv-format", iv.format || "Visioconférence");
    applyFormat();
    if (iv.format === "Visioconférence") {
      setVal("iv-lien", iv.lien); setVal("iv-visio-instr", iv.instructions);
    } else if (iv.format === "Téléphone") {
      setVal("iv-tel", iv.tel); setVal("iv-tel-instr", iv.instructions);
    } else if (iv.format === "Dans nos locaux") {
      overlay.querySelector("#iv-use-company").checked = false;
      setVal("iv-adresse", iv.adresse); setVal("iv-complement", iv.complement);
      setVal("iv-cp", iv.cp); setVal("iv-ville", iv.ville);
      setVal("iv-contact", iv.contact); setVal("iv-contact-tel", iv.contactTel);
      setVal("iv-acces", iv.instructions);
    }
    open();
  }

  function resetForm() {
    setVal("iv-nom", "");
    setVal("iv-offre", "");
    setVal("iv-date", EMP.dateFromToday(3));
    setVal("iv-heure", "14:30");
    setVal("iv-duree", "45");
    setVal("iv-format", "Visioconférence");
    setVal("iv-lien", "");
    setVal("iv-visio-instr", "Merci de vous connecter 5 minutes avant.");
    setVal("iv-tel", "");
    setVal("iv-tel-instr", "Nous vous appellerons sur le numéro renseigné dans votre profil.");
    setVal("iv-complement", "");
    setVal("iv-acces", "Présentez-vous à l'accueil et demandez le contact indiqué.");
    overlay.querySelector("#iv-use-company").checked = true;
    fillCompanyAddress();
    applyFormat();
    showForm();
  }

  function open() {
    lastFocus = document.activeElement;
    overlay.hidden = false;
    var f = overlay.querySelector("#iv-form select, #iv-form input");
    if (f) { f.focus(); }
  }

  function close() {
    overlay.hidden = true;
    if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
  }

  /* ---- Bascule des vues formulaire / aperçu ---- */
  function showForm() {
    overlay.querySelector("#iv-form").hidden = false;
    overlay.querySelector("#iv-preview").hidden = true;
    overlay.querySelector("#iv-actions-form").hidden = false;
    overlay.querySelector("#iv-actions-preview").hidden = true;
  }

  function showPreview() {
    var iv = collect();
    var e = SS.escapeHtml;
    var rows = [
      ["Candidat", iv.nom + (iv.offre ? " — " + iv.offre : "")],
      ["Date et heure", SS.formatDate(iv.date) + " à " + iv.heure],
      ["Durée", iv.duree + " minutes"],
      ["Format", iv.format]
    ];
    if (iv.format === "Visioconférence") {
      if (iv.lien) { rows.push(["Lien", iv.lien]); }
      if (iv.instructions) { rows.push(["Instructions", iv.instructions]); }
    } else if (iv.format === "Téléphone") {
      if (iv.tel) { rows.push(["Numéro d'appel", iv.tel]); }
      if (iv.instructions) { rows.push(["Instructions", iv.instructions]); }
    } else if (iv.format === "Dans nos locaux") {
      var lieu = [iv.adresse, iv.complement, [iv.cp, iv.ville].filter(Boolean).join(" ")].filter(Boolean).join(", ");
      if (lieu) { rows.push(["Adresse", lieu]); }
      if (iv.contact) { rows.push(["Contact sur place", iv.contact + (iv.contactTel ? " · " + iv.contactTel : "")]); }
      if (iv.instructions) { rows.push(["Accès", iv.instructions]); }
    }
    overlay.querySelector("#iv-preview-grid").innerHTML = rows.map(function (r) {
      return "<div><dt>" + e(r[0]) + "</dt><dd>" + e(r[1]) + "</dd></div>";
    }).join("");

    overlay.querySelector("#iv-form").hidden = true;
    overlay.querySelector("#iv-preview").hidden = false;
    overlay.querySelector("#iv-actions-form").hidden = true;
    overlay.querySelector("#iv-actions-preview").hidden = false;
    overlay.querySelector("#iv-send").focus();
  }

  /* ---- Collecte des champs → objet entretien ---- */
  function collect() {
    var format = getVal("iv-format");
    var iv = {
      nom: getVal("iv-nom") || "Candidat",
      poste: "Candidat",
      offre: getVal("iv-offre"),
      date: getVal("iv-date") || EMP.dateFromToday(3),
      heure: getVal("iv-heure") || "14:30",
      duree: getVal("iv-duree") || "45",
      format: format
    };
    if (format === "Visioconférence") {
      iv.lien = getVal("iv-lien");
      iv.instructions = getVal("iv-visio-instr");
    } else if (format === "Téléphone") {
      iv.tel = getVal("iv-tel");
      iv.instructions = getVal("iv-tel-instr");
    } else if (format === "Dans nos locaux") {
      iv.adresse = getVal("iv-adresse");
      iv.complement = getVal("iv-complement");
      iv.cp = getVal("iv-cp");
      iv.ville = getVal("iv-ville");
      iv.contact = getVal("iv-contact");
      iv.contactTel = getVal("iv-contact-tel");
      iv.instructions = getVal("iv-acces");
    }
    return iv;
  }

  /* ---- Enregistrement (§7) ---- */
  function submit() {
    var iv = collect();
    var all = getAll();
    if (editingId) {
      setAll(all.map(function (x) {
        return x.id === editingId ? Object.assign({}, x, iv, { id: editingId }) : x;
      }));
      close();
      render();
      SS.toast("Entretien mis à jour.");
    } else {
      iv.id = "iv" + Date.now();
      iv.statut = "attente";
      all.push(iv);
      setAll(all);
      close();
      render();
      SS.toast("Proposition envoyée — le candidat recevra une notification pour confirmer.");
    }
  }

  /* ---- Focus piégé dans la modale ---- */
  function trapFocus(ev) {
    var focusables = Array.prototype.filter.call(
      overlay.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'),
      function (el) { return el.offsetParent !== null; } /* visibles uniquement */
    );
    if (!focusables.length) { return; }
    var first = focusables[0];
    var last = focusables[focusables.length - 1];
    if (ev.shiftKey && document.activeElement === first) {
      ev.preventDefault(); last.focus();
    } else if (!ev.shiftKey && document.activeElement === last) {
      ev.preventDefault(); first.focus();
    }
  }

  /* ---- Petits utilitaires DOM ---- */
  function getVal(id) { var el = overlay.querySelector("#" + id); return el ? el.value : ""; }
  function setVal(id, v) { var el = overlay.querySelector("#" + id); if (el) { el.value = v == null ? "" : v; } }
})();

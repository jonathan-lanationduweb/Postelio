/**
 * Publication d'une offre — tunnel court en 3 ÉTAPES (démonstration).
 *
 *   1. LE POSTE       — modèle de poste, intitulé, lieu, contrat, conditions,
 *                       niveau d'étude et expérience (choix rapides).
 *   2. LE CONTENU     — description assistée, missions/compétences proposées
 *                       (cases à cocher), avantages hérités du profil entreprise.
 *   3. APERÇU         — vraie fiche telle que le candidat la verra, puis publication.
 *
 * Principes : on ne redemande JAMAIS les infos entreprise (elles viennent du
 * profil, §20) ; tout est prérempli, l'utilisateur ne fait qu'ajuster.
 * Prise en charge : brouillon + reprise automatique, duplication (?dupliquer=),
 * modèle direct (?modele=), édition (?modifier=). Aucun backend : enregistrement
 * dans le stockage local (ss_custom_offers), prêt pour l'API WordPress.
 */
(function () {
  "use strict";

  var DRAFT_KEY = "ss_offre_brouillon";

  /* ---- Choix rapides ---- */
  var CONTRATS = ["CDI", "CDD", "Intérim", "Alternance"];
  var TELETRAVAIL = [{ v: "non", l: "Aucun" }, { v: "partiel", l: "Partiel" }, { v: "complet", l: "Complet" }];
  var NIVEAUX = ["Sans diplôme", "CAP / BEP", "Bac", "Bac+2", "Bac+3", "Bac+5 et plus"];
  var EXPERIENCES = ["Débutant accepté", "1 à 2 ans", "3 à 5 ans", "Plus de 5 ans"];

  var CATEGORIES = [
    ["administration-assistanat", "Administration & Assistanat"],
    ["commerce-vente", "Commerce & Vente"],
    ["informatique-digital", "Informatique & Digital"],
    ["sante-social", "Santé & Social"],
    ["btp-construction", "BTP & Construction"],
    ["logistique-transport", "Logistique & Transport"],
    ["finance-comptabilite", "Finance & Comptabilité"],
    ["ressources-humaines", "Ressources humaines"],
    ["immobilier", "Immobilier"],
    ["hotellerie-restauration", "Hôtellerie & Restauration"],
    ["artisanat", "Artisanat"],
    ["services", "Services"]
  ];

  /* ---- Modèles de poste (fictifs, §16/§25) ---- */
  var TEMPLATES = [
    { key: "comptable", label: "Comptable", categorie: "finance-comptabilite",
      contrat: "CDI", niveau: "Bac+2", experience: "1 à 2 ans", tempsTravail: "Temps plein — 39h", salaire: "30 000 à 36 000 € brut / an",
      missions: ["Tenue de la comptabilité de plusieurs dossiers clients", "Établissement des déclarations de TVA", "Révision des comptes et préparation du bilan", "Relation et conseil auprès des clients"],
      competences: ["Comptabilité générale", "Fiscalité", "Sage / Cegid", "Excel", "Rigueur"] },
    { key: "gestionnaire-paie", label: "Gestionnaire de paie", categorie: "finance-comptabilite",
      contrat: "CDI", niveau: "Bac+2", experience: "3 à 5 ans", tempsTravail: "Temps plein — 39h", salaire: "30 000 à 36 000 € brut / an",
      missions: ["Gestion des bulletins de paie", "Déclarations sociales (DSN)", "Administration du personnel", "Conseil client en droit social"],
      competences: ["Paie", "Droit social", "DSN", "Silae", "Excel"] },
    { key: "assistant-administratif", label: "Assistant administratif", categorie: "administration-assistanat",
      contrat: "CDI", niveau: "Bac", experience: "Débutant accepté", tempsTravail: "Temps plein — 35h", salaire: "24 000 à 28 000 € brut / an",
      missions: ["Accueil physique et téléphonique", "Gestion du courrier et des agendas", "Classement et archivage des dossiers", "Suivi administratif courant"],
      competences: ["Accueil", "Pack Office", "Organisation", "Orthographe", "Sens du service"] },
    { key: "commercial", label: "Commercial", categorie: "commerce-vente",
      contrat: "CDI", niveau: "Bac+2", experience: "1 à 2 ans", tempsTravail: "Temps plein — 39h", salaire: "28 000 € + variable",
      missions: ["Prospection de nouveaux clients", "Négociation et suivi commercial", "Fidélisation du portefeuille", "Reporting de l'activité"],
      competences: ["Négociation", "Prospection", "CRM", "Relation client", "Autonomie"] },
    { key: "developpeur", label: "Développeur web", categorie: "informatique-digital",
      contrat: "CDI", niveau: "Bac+3", experience: "1 à 2 ans", tempsTravail: "Temps plein — 35h", salaire: "34 000 à 42 000 € brut / an",
      missions: ["Développement de fonctionnalités web", "Intégration et maintenance applicative", "Participation aux revues de code", "Collaboration avec l'équipe produit"],
      competences: ["JavaScript", "HTML/CSS", "Git", "React", "Travail en équipe"] },
    { key: "preparateur-commandes", label: "Préparateur de commandes", categorie: "logistique-transport",
      contrat: "CDD", niveau: "Sans diplôme", experience: "Débutant accepté", tempsTravail: "Temps plein — 35h", salaire: "SMIC + primes",
      missions: ["Préparation des commandes clients", "Contrôle qualité et emballage", "Gestion des stocks", "Respect des consignes de sécurité"],
      competences: ["Rigueur", "CACES", "Travail en équipe", "Rapidité", "Organisation"] },
    { key: "conducteur-travaux", label: "Conducteur de travaux", categorie: "btp-construction",
      contrat: "CDI", niveau: "Bac+3", experience: "3 à 5 ans", tempsTravail: "Temps plein — 39h", salaire: "38 000 à 46 000 € brut / an",
      missions: ["Pilotage de chantiers", "Encadrement des équipes", "Suivi budgétaire et planning", "Relation avec les clients et sous-traitants"],
      competences: ["Gestion de chantier", "Lecture de plans", "Encadrement", "Sécurité", "Autonomie"] },
    { key: "conseiller-vente", label: "Conseiller de vente", categorie: "commerce-vente",
      contrat: "CDI", niveau: "CAP / BEP", experience: "Débutant accepté", tempsTravail: "Temps plein — 35h", salaire: "SMIC + primes",
      missions: ["Accueil et conseil des clients", "Mise en rayon et merchandising", "Encaissement", "Fidélisation de la clientèle"],
      competences: ["Relation client", "Vente", "Sens du service", "Dynamisme", "Travail en équipe"] }
  ];

  /* ---- État du formulaire ---- */
  var s = null;       /* state courant */
  var company = null; /* entreprise connectée (companies.json) */
  var profile = null; /* ss_company_profile (avantages, coordonnées) */
  var step = 1;
  var editId = null;
  var app = null;

  function blankState() {
    return {
      template: "", titre: "", categorie: "finance-comptabilite", categorieLabel: "Finance & Comptabilité",
      ville: "", contrat: "CDI", teletravail: "non", niveau: "Bac+2", experience: "1 à 2 ans",
      tempsTravail: "Temps plein — 39h", salaire: "", duree: "",
      description: "", missions: [], profil: [], competences: [], avantages: [],
      email: "", consent: false
    };
  }

  document.addEventListener("DOMContentLoaded", function () {
    app = document.getElementById("publish-app");
    if (!app) { return; }
    if (window.SS && SS.auth && !SS.auth.require("employer")) { return; }

    SS.getCompanies().then(function (companies) {
      var sess = SS.auth.get() || {};
      company = (companies || []).find(function (c) { return c.id === (sess.companyId || APP_CONFIG.demoCompany.id); }) || {};
      profile = SS.store.get("ss_company_profile", {}) || {};

      /* Garde-fou : profil entreprise trop incomplet → on invite à le compléter (§6). */
      if (essentialsMissing()) { renderGate(); return; }

      s = blankState();
      prefillFromCompany();

      editId = SS.param("modifier");
      var dupId = SS.param("dupliquer");
      var modele = SS.param("modele");

      if (editId || dupId) {
        SS.getOffers().then(function (offers) {
          var src = offers.find(function (o) { return o.id === (editId || dupId); });
          if (src) { loadFromOffer(src, !!dupId); }
          bootstrapDraft(modele);
          render();
        }).catch(function () { bootstrapDraft(modele); render(); });
      } else {
        bootstrapDraft(modele);
        render();
      }
    }).catch(function () {
      company = {}; profile = {}; s = blankState(); render();
    });
  });

  function essentialsMissing() {
    var p = profile || {};
    var hasLegal = (p.raisonSociale || company.nom) && (p.siren || true);
    var hasAddress = p.adresse || company.adresse;
    var hasContact = (p.contactEmail || p.email || company.email);
    return !(hasLegal && hasAddress && hasContact);
  }

  function prefillFromCompany() {
    s.ville = profile.ville || company.ville || "Lyon";
    s.email = profile.email || company.email || "";
    /* Avantages hérités du profil (§18) : cochés par défaut, désactivables. */
    s.avantages = (profile.avantagesList || company.avantages || []).slice();
  }

  function loadFromOffer(o, isDup) {
    s.template = "";
    s.titre = isDup ? (o.titre + " (copie)") : o.titre;
    s.categorie = o.categorie || s.categorie;
    s.categorieLabel = o.categorieLabel || labelForCategory(s.categorie);
    s.ville = o.ville || s.ville;
    s.contrat = o.contrat || s.contrat;
    s.teletravail = o.teletravail || s.teletravail;
    s.niveau = o.niveau || s.niveau;
    s.experience = o.experience || s.experience;
    s.tempsTravail = o.tempsTravail || s.tempsTravail;
    s.salaire = o.salaire || s.salaire;
    s.duree = o.duree || "";
    s.description = o.description || "";
    s.missions = (o.missions || []).slice();
    s.profil = (o.profil || []).slice();
    s.competences = (o.competences || []).slice();
    if (o.avantages && o.avantages.length) { s.avantages = o.avantages.slice(); }
    s.email = o.email || s.email;
  }

  /* Reprise automatique d'un brouillon (§23) ou application d'un modèle direct. */
  function bootstrapDraft(modele) {
    if (modele) { var t = TEMPLATES.find(function (x) { return x.key === modele; }); if (t) { applyTemplate(t); } return; }
    if (editId) { return; }
    var draft = SS.store.get(DRAFT_KEY, null);
    if (draft && draft.state) { s._hasDraft = true; s._draft = draft.state; }
  }

  function labelForCategory(key) {
    var c = CATEGORIES.find(function (x) { return x[0] === key; });
    return c ? c[1] : "";
  }

  /* window.EMP n'est chargé que dans l'espace recruteur ; cette page utilise
     l'en-tête marketing. On fournit donc des replis autonomes. */
  function initialsOf(name) {
    if (window.EMP) { return window.EMP.companyInitials(name); }
    var words = String(name || "?").trim().split(/\s+/).slice(0, 2);
    return words.map(function (w) { return w.charAt(0); }).join("").toUpperCase() || "?";
  }
  function expiryDate() {
    if (window.EMP) { return window.EMP.dateFromToday(APP_CONFIG.payment.renewal.durationDays); }
    var d = new Date();
    d.setDate(d.getDate() + APP_CONFIG.payment.renewal.durationDays);
    return d.toISOString().slice(0, 10);
  }

  /* ============================================================
     Rendu principal
     ============================================================ */
  function render() {
    var e = SS.escapeHtml;
    /* Bannière de reprise de brouillon. */
    var draftBanner = "";
    if (s._hasDraft) {
      draftBanner = '<div class="pub-draft-banner notice" role="status">' +
        "<span>Votre brouillon a été enregistré. Reprendre là où vous en étiez&nbsp;?</span>" +
        '<span class="pub-draft-banner__actions">' +
          '<button type="button" class="btn btn-primary btn-sm" data-draft-resume>Continuer mon offre</button>' +
          '<button type="button" class="btn btn-ghost btn-sm" data-draft-discard>Repartir de zéro</button>' +
        "</span></div>";
    }

    app.innerHTML =
      draftBanner +
      '<div class="pub-steps" aria-hidden="true">' +
        stepDot(1, "Le poste") + stepDot(2, "Le contenu") + stepDot(3, "Aperçu & publication") +
      "</div>" +
      '<div class="pub-body card dash-card"></div>' +
      '<div class="pub-foot">' +
        '<button type="button" class="btn btn-ghost" data-back' + (step === 1 ? " hidden" : "") + ">← Étape précédente</button>" +
        '<button type="button" class="btn btn-ghost" data-draft>Enregistrer le brouillon</button>' +
        (step < 3
          ? '<button type="button" class="btn btn-primary" data-next>Continuer →</button>'
          : '<button type="button" class="btn btn-accent" data-publish>Publier l\'offre</button>') +
      "</div>";

    wireDraftBanner();
    var body = app.querySelector(".pub-body");
    if (step === 1) { renderStep1(body); }
    else if (step === 2) { renderStep2(body); }
    else { renderStep3(body); }

    var back = app.querySelector("[data-back]");
    if (back) { back.addEventListener("click", function () { go(step - 1); }); }
    var next = app.querySelector("[data-next]");
    if (next) { next.addEventListener("click", function () { if (validateStep()) { go(step + 1); } }); }
    var pub = app.querySelector("[data-publish]");
    if (pub) { pub.addEventListener("click", publish); }
    app.querySelector("[data-draft]").addEventListener("click", saveDraft);
  }

  function stepDot(n, label) {
    var cls = "pub-step" + (n === step ? " is-active" : "") + (n < step ? " is-done" : "");
    return '<div class="' + cls + '"><span class="pub-step__n">' + n + "</span>" + SS.escapeHtml(label) + "</div>";
  }

  function go(n) {
    collectStep();
    step = Math.max(1, Math.min(3, n));
    window.scrollTo({ top: app.offsetTop - 80, behavior: "smooth" });
    render();
  }

  function wireDraftBanner() {
    var resume = app.querySelector("[data-draft-resume]");
    if (resume) {
      resume.addEventListener("click", function () {
        Object.assign(s, s._draft); s._hasDraft = false; s._draft = null; render();
      });
    }
    var discard = app.querySelector("[data-draft-discard]");
    if (discard) {
      discard.addEventListener("click", function () {
        SS.store.remove(DRAFT_KEY); s._hasDraft = false; render();
      });
    }
  }

  /* ============================================================
     Étape 1 — Le poste
     ============================================================ */
  function renderStep1(body) {
    var e = SS.escapeHtml;
    body.innerHTML =
      "<h2>Le poste</h2>" +
      '<p class="form-hint">Partez d\'un modèle pour tout préremplir, puis ajustez.</p>' +
      '<div class="pub-templates" role="group" aria-label="Modèles de poste">' +
        TEMPLATES.map(function (t) {
          return '<button type="button" class="chip" aria-pressed="' + (s.template === t.key) + '" data-tpl="' + t.key + '">' + e(t.label) + "</button>";
        }).join("") +
      "</div>" +
      field("Intitulé du poste *", '<input type="text" id="f-titre" value="' + e(s.titre) + '" placeholder="Ex. : Collaborateur comptable">') +
      '<div class="form-row">' +
        field("Lieu *", '<input type="text" id="f-ville" value="' + e(s.ville) + '">') +
        field("Famille de métier", categorySelect()) +
      "</div>" +
      buttonGroup("Type de contrat", "contrat", CONTRATS.map(mapVal)) +
      '<div class="form-row">' +
        field("Durée (si CDD / intérim / alternance)", '<input type="text" id="f-duree" value="' + e(s.duree) + '" placeholder="Ex. : 6 mois">') +
        field("Temps de travail", '<input type="text" id="f-temps" value="' + e(s.tempsTravail) + '">') +
      "</div>" +
      field("Salaire proposé", '<input type="text" id="f-salaire" value="' + e(s.salaire) + '" placeholder="Ex. : 32 000 à 36 000 € brut / an">') +
      buttonGroup("Télétravail", "teletravail", TELETRAVAIL) +
      buttonGroup("Niveau d'étude", "niveau", NIVEAUX.map(mapVal)) +
      buttonGroup("Expérience", "experience", EXPERIENCES.map(mapVal));

    /* Modèles */
    body.querySelectorAll("[data-tpl]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var t = TEMPLATES.find(function (x) { return x.key === btn.getAttribute("data-tpl"); });
        if (t) { collectStep(); applyTemplate(t); render(); }
      });
    });
    wireButtonGroups(body);
  }

  function mapVal(v) { return { v: v, l: v }; }

  function categorySelect() {
    var e = SS.escapeHtml;
    return '<select id="f-categorie">' + CATEGORIES.map(function (c) {
      return '<option value="' + c[0] + '"' + (c[0] === s.categorie ? " selected" : "") + ">" + e(c[1]) + "</option>";
    }).join("") + "</select>";
  }

  function field(label, control) {
    return '<div class="field"><label>' + SS.escapeHtml(label) + "</label>" + control + '<p class="field-error" hidden>Champ requis.</p></div>';
  }

  function buttonGroup(label, key, opts) {
    var e = SS.escapeHtml;
    return '<div class="field"><label>' + e(label) + '</label><div class="profil-radios" data-group="' + key + '">' +
      opts.map(function (o) {
        return '<button type="button" class="chip" aria-pressed="' + (s[key] === o.v) + '" data-val="' + e(o.v) + '">' + e(o.l) + "</button>";
      }).join("") + "</div></div>";
  }

  function wireButtonGroups(body) {
    body.querySelectorAll("[data-group]").forEach(function (grp) {
      var key = grp.getAttribute("data-group");
      grp.querySelectorAll("[data-val]").forEach(function (btn) {
        btn.addEventListener("click", function () {
          grp.querySelectorAll("[data-val]").forEach(function (b) { b.setAttribute("aria-pressed", "false"); });
          btn.setAttribute("aria-pressed", "true");
          s[key] = btn.getAttribute("data-val");
        });
      });
    });
  }

  function applyTemplate(t) {
    s.template = t.key;
    if (!s.titre) { s.titre = t.label; }
    s.categorie = t.categorie; s.categorieLabel = labelForCategory(t.categorie);
    s.contrat = t.contrat; s.niveau = t.niveau; s.experience = t.experience;
    s.tempsTravail = t.tempsTravail; if (!s.salaire) { s.salaire = t.salaire; }
    s.missions = t.missions.slice();
    s.competences = t.competences.slice();
    s._tplMissions = t.missions.slice();
    s._tplCompetences = t.competences.slice();
  }

  /* ============================================================
     Étape 2 — Le contenu (assisté)
     ============================================================ */
  function renderStep2(body) {
    var e = SS.escapeHtml;
    var missionPool = unionList(s._tplMissions || [], s.missions);
    var skillPool = unionList(s._tplCompetences || [], s.competences);
    var avantagesPool = unionList(profile.avantagesList || company.avantages || [], s.avantages);

    body.innerHTML =
      "<h2>Le contenu de l'offre</h2>" +
      '<div class="field"><label for="f-desc">Description du poste</label>' +
        '<div class="pub-desc-tools"><button type="button" class="btn btn-outline btn-sm" data-gen>✦ Générer une base de description</button></div>' +
        '<textarea id="f-desc" rows="5" placeholder="Contexte, équipe, environnement…">' + e(s.description) + "</textarea></div>" +
      checkGroup("Missions principales", "missions", missionPool, "Ajouter une mission") +
      checkGroup("Compétences attendues", "competences", skillPool, "Ajouter une compétence") +
      '<div class="field"><label for="f-profil">Profil recherché (une ligne par critère)</label>' +
        '<textarea id="f-profil" rows="3" placeholder="Ex. : 2 ans d\'expérience sur un poste similaire">' + e((s.profil || []).join("\n")) + "</textarea></div>" +
      avantagesGroup(avantagesPool);

    body.querySelector("[data-gen]").addEventListener("click", function () {
      collectStep();
      s.description = generateDescription();
      renderStep2(body);
    });
    wireCheckGroups(body);
  }

  function unionList(a, b) {
    var out = (a || []).slice();
    (b || []).forEach(function (x) { if (out.indexOf(x) === -1) { out.push(x); } });
    return out;
  }

  function checkGroup(label, key, pool, addLabel) {
    var e = SS.escapeHtml;
    var selected = s[key] || [];
    return '<div class="field"><label>' + e(label) + "</label>" +
      '<div class="pub-checks" data-checks="' + key + '">' +
        pool.map(function (v) {
          var on = selected.indexOf(v) !== -1;
          return '<label class="pub-check' + (on ? " is-on" : "") + '"><input type="checkbox" ' + (on ? "checked" : "") + ' data-v="' + e(v) + '"> ' + e(v) + "</label>";
        }).join("") +
      "</div>" +
      '<form class="profil-chips__add" data-add="' + key + '"><input type="text" placeholder="' + e(addLabel) + '"><button type="submit" class="btn btn-outline btn-sm">+ Ajouter</button></form>' +
    "</div>";
  }

  function avantagesGroup(pool) {
    var e = SS.escapeHtml;
    if (!pool.length) { return ""; }
    return '<div class="field"><label>Avantages de l\'offre</label>' +
      '<p class="form-hint">Repris de votre profil entreprise — décochez ceux qui ne s\'appliquent pas à cette offre.</p>' +
      '<div class="pub-checks" data-checks="avantages">' +
        pool.map(function (v) {
          var on = (s.avantages || []).indexOf(v) !== -1;
          return '<label class="pub-check' + (on ? " is-on" : "") + '"><input type="checkbox" ' + (on ? "checked" : "") + ' data-v="' + e(v) + '"> ' + e(v) + "</label>";
        }).join("") +
      "</div>" +
      '<form class="profil-chips__add" data-add="avantages"><input type="text" placeholder="Ajouter un avantage"><button type="submit" class="btn btn-outline btn-sm">+ Ajouter</button></form>' +
    "</div>";
  }

  function wireCheckGroups(body) {
    body.querySelectorAll("[data-checks]").forEach(function (grp) {
      var key = grp.getAttribute("data-checks");
      grp.querySelectorAll("input[type=checkbox]").forEach(function (cb) {
        cb.addEventListener("change", function () {
          var v = cb.getAttribute("data-v");
          var list = (s[key] || []).slice();
          var i = list.indexOf(v);
          if (cb.checked && i === -1) { list.push(v); }
          if (!cb.checked && i !== -1) { list.splice(i, 1); }
          s[key] = list;
          cb.closest(".pub-check").classList.toggle("is-on", cb.checked);
        });
      });
    });
    body.querySelectorAll("[data-add]").forEach(function (form) {
      form.addEventListener("submit", function (ev) {
        ev.preventDefault();
        var key = form.getAttribute("data-add");
        var input = form.querySelector("input");
        var val = (input.value || "").trim();
        if (!val) { return; }
        var list = (s[key] || []).slice();
        if (list.indexOf(val) === -1) { list.push(val); }
        s[key] = list;
        if (key === "missions") { s._tplMissions = unionList(s._tplMissions || [], [val]); }
        if (key === "competences") { s._tplCompetences = unionList(s._tplCompetences || [], [val]); }
        input.value = "";
        renderStep2(app.querySelector(".pub-body"));
      });
    });
  }

  /* Génération d'une base de description à partir des choix (§17, sans API). */
  function generateDescription() {
    var nom = profile.nom || company.nom || "Notre entreprise";
    var poste = s.titre || "ce poste";
    var contratTxt = { "CDI": "en CDI", "CDD": "en CDD", "Intérim": "en intérim", "Alternance": "en alternance" }[s.contrat] || "";
    var intro = nom + " recrute un(e) " + poste + " " + contratTxt + " à " + (s.ville || "") + ".";
    if (company.description) { intro += " " + company.description.split(".")[0] + "."; }
    var missionsTxt = (s.missions || []).length
      ? " Vos principales missions : " + s.missions.slice(0, 4).join(", ").toLowerCase() + "."
      : "";
    var tele = s.teletravail === "partiel" ? " Télétravail partiel possible." : (s.teletravail === "complet" ? " Poste en télétravail complet." : "");
    return intro + missionsTxt + tele + " Poste ouvert aux profils " + (s.experience || "").toLowerCase() + ".";
  }

  /* ============================================================
     Étape 3 — Aperçu & publication
     ============================================================ */
  function renderStep3(body) {
    var e = SS.escapeHtml;
    var o = buildOffer();
    body.innerHTML =
      "<h2>Aperçu de votre offre</h2>" +
      '<p class="form-hint">Voici la fiche telle que les candidats la verront.</p>' +
      '<article class="pub-preview">' +
        '<div class="pub-preview__head">' +
          '<span class="avatar" aria-hidden="true">' + e(initialsOf(o.entrepriseNom)) + "</span>" +
          "<div><h3>" + e(o.titre || "Intitulé du poste") + "</h3>" +
          '<p class="pub-preview__meta">' + e(o.entrepriseNom) + " · " + e(o.ville) + "</p></div>" +
        "</div>" +
        '<ul class="pub-preview__tags">' +
          tag(o.contrat) + tag(o.duree) + tag(o.tempsTravail) + tag(remoteLabel(o.teletravail)) +
          tag(o.niveau) + tag(o.experience) +
        "</ul>" +
        '<p class="pub-preview__salary">' + e(o.salaire || "Salaire selon profil") + "</p>" +
        (o.description ? '<p class="pub-preview__desc">' + e(o.description) + "</p>" : "") +
        listBlock("Missions", o.missions) +
        listBlock("Profil recherché", o.profil) +
        (o.competences.length ? '<p class="pub-preview__skills">' + o.competences.map(function (c) { return '<span class="badge">' + e(c) + "</span>"; }).join("") + "</p>" : "") +
        (o.avantages.length ? listBlock("Avantages", o.avantages) : "") +
      "</article>" +
      '<div class="field field--checkbox wizard-consent"><input type="checkbox" id="f-consent"' + (s.consent ? " checked" : "") + ">" +
        '<label for="f-consent">Je certifie que cette annonce est conforme à la réglementation (non-discrimination, offre réelle) et j\'accepte les conditions de publication.</label>' +
        '<p class="field-error" hidden>Veuillez accepter les conditions de publication.</p></div>' +
      '<p class="form-hint">Candidatures reçues à&nbsp;: ' + e(o.email || "—") + " · Publication valable 30 jours (renouvelable 10 €).</p>";

    body.querySelector("#f-consent").addEventListener("change", function (ev) { s.consent = ev.target.checked; });
  }

  function tag(v) { return v ? '<li>' + SS.escapeHtml(v) + "</li>" : ""; }
  function listBlock(title, arr) {
    if (!arr || !arr.length) { return ""; }
    var e = SS.escapeHtml;
    return '<div class="pub-preview__list"><h4>' + e(title) + "</h4><ul>" +
      arr.map(function (x) { return "<li>" + e(x) + "</li>"; }).join("") + "</ul></div>";
  }
  function remoteLabel(v) { return v === "complet" ? "Télétravail complet" : (v === "partiel" ? "Télétravail partiel" : "Sur site"); }

  /* ============================================================
     Collecte, validation, construction et publication
     ============================================================ */
  function collectStep() {
    if (!app) { return; }
    var body = app.querySelector(".pub-body");
    if (!body) { return; }
    if (step === 1) {
      val("f-titre", "titre"); val("f-ville", "ville"); val("f-duree", "duree");
      val("f-temps", "tempsTravail"); val("f-salaire", "salaire");
      var cat = body.querySelector("#f-categorie");
      if (cat) { s.categorie = cat.value; s.categorieLabel = labelForCategory(cat.value); }
    } else if (step === 2) {
      var desc = body.querySelector("#f-desc"); if (desc) { s.description = desc.value.trim(); }
      var prof = body.querySelector("#f-profil");
      if (prof) { s.profil = prof.value.split("\n").map(function (l) { return l.trim(); }).filter(Boolean); }
    }
    function val(id, key) { var el = body.querySelector("#" + id); if (el) { s[key] = el.value.trim(); } }
  }

  function validateStep() {
    collectStep();
    var body = app.querySelector(".pub-body");
    var ok = true;
    if (step === 1) {
      if (!s.titre) { markError(body.querySelector("#f-titre")); ok = false; }
      if (!s.ville) { markError(body.querySelector("#f-ville")); ok = false; }
      if (!ok) { SS.toast("Renseignez l'intitulé et le lieu du poste."); }
    } else if (step === 2) {
      if (!s.missions.length) { SS.toast("Ajoutez au moins une mission."); ok = false; }
    }
    return ok;
  }

  function markError(input) {
    if (!input) { return; }
    var field = input.closest(".field");
    if (field) {
      field.classList.add("has-error");
      var err = field.querySelector(".field-error");
      if (err) { err.hidden = false; }
    }
    input.focus();
  }

  function buildOffer() {
    var sess = SS.auth.get() || {};
    return {
      id: editId || ("offre-" + (s._id || (s._id = "u" + (s.titre || "").length + "-" + (company.id || "demo")))),
      titre: s.titre,
      entrepriseId: sess.companyId || APP_CONFIG.demoCompany.id,
      entrepriseNom: profile.nom || company.nom || APP_CONFIG.demoCompany.nom,
      ville: s.ville, departement: company.departement || "",
      contrat: s.contrat, duree: s.duree || null, tempsTravail: s.tempsTravail,
      salaire: s.salaire || "Salaire selon profil", salaireAnnuel: null,
      teletravail: s.teletravail, categorie: s.categorie, categorieLabel: s.categorieLabel,
      niveau: s.niveau, experience: s.experience,
      description: s.description, resume: (s.description || "").slice(0, 180),
      missions: s.missions.slice(), profil: s.profil.slice(), competences: s.competences.slice(),
      avantages: s.avantages.slice(), email: s.email,
      datePublication: new Date().toISOString().slice(0, 10),
      dateExpiration: expiryDate(),
      statut: "active"
    };
  }

  function saveDraft() {
    collectStep();
    SS.store.set(DRAFT_KEY, { state: s, at: new Date().toISOString() });
    SS.toast("Brouillon enregistré. Vous pourrez le reprendre plus tard.");
  }

  function publish() {
    if (!s.consent) {
      var cb = app.querySelector("#f-consent");
      markError(cb);
      SS.toast("Veuillez accepter les conditions de publication.");
      return;
    }
    var offer = buildOffer();
    if (editId) {
      /* Mise à jour d'une offre personnalisée existante. */
      var custom = SS.store.get(APP_CONFIG.storage.customOffers, []);
      var idx = custom.findIndex(function (o) { return o.id === editId; });
      if (idx !== -1) { custom[idx] = offer; } else { custom.push(offer); }
      SS.store.set(APP_CONFIG.storage.customOffers, custom);
    } else {
      offer.id = "offre-" + Date.now();
      var list = SS.store.get(APP_CONFIG.storage.customOffers, []);
      list.push(offer);
      SS.store.set(APP_CONFIG.storage.customOffers, list);
    }
    SS.store.remove(DRAFT_KEY);
    renderSuccess(offer);
  }

  function renderSuccess(offer) {
    var e = SS.escapeHtml;
    app.innerHTML =
      '<div class="pub-body card dash-card pub-success">' +
        '<span class="pub-success__mark" aria-hidden="true">✓</span>' +
        "<h2>Votre offre est en ligne&nbsp;!</h2>" +
        '<p class="notice notice--success" role="status">« ' + e(offer.titre) + ' » est publiée (démonstration : enregistrée dans votre navigateur). Valable 30 jours, renouvelable pour 10&nbsp;€.</p>' +
        '<div class="form-actions">' +
          '<a class="btn btn-primary" href="offre-detail.html?id=' + encodeURIComponent(offer.id) + '">Voir mon annonce</a>' +
          '<a class="btn btn-outline" href="espace-entreprise-offres.html">Mes offres</a>' +
        "</div>" +
      "</div>";
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  /* Profil trop incomplet : on bloque et on invite à le compléter (§6). */
  function renderGate() {
    app.innerHTML =
      '<div class="pub-body card dash-card">' +
        "<h2>Complétez votre profil entreprise</h2>" +
        '<p>Avant de publier votre première offre, renseignez les informations essentielles de votre entreprise (identité légale, adresse, contact).</p>' +
        '<div class="form-actions"><a class="btn btn-primary" href="espace-entreprise-profil.html">Compléter mon profil</a></div>' +
      "</div>";
  }
})();

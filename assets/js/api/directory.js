/**
 * Annuaire public Offres + Entreprises (I2) — accès données RÉELLES via PostelioAPI.
 *
 * Expose `window.PostelioDirectory` : recherche/lecture d'offres et d'entreprises + adaptateurs
 * qui projettent la vue publique du backend (`postelio/v1`) vers la forme d'objet historique
 * attendue par les gabarits de rendu existants (SS.offerCard, fiche, cartes entreprises). Ainsi
 * la présentation ne change pas : seule la SOURCE de données devient l'API réelle (plus de JSON).
 *
 * Aucune donnée n'est mise en cache localement (le backend fait foi) ; aucun repli JSON.
 */
(function () {
  "use strict";

  var API = window.PostelioAPI;
  if (!API) { throw new Error("PostelioAPI requis avant PostelioDirectory."); }

  /* Palette de bulles déterministe (les entreprises n'ont pas de couleur côté API). */
  var PALETTE = ["#17324D", "#2A6F7F", "#3B5BA9", "#8A5A44", "#5B7C4B", "#7A4E7E", "#B4632F", "#2E6B5E", "#6A5ACD", "#94566B"];
  function hashCode(s) { var h = 0, i; s = String(s || ""); for (i = 0; i < s.length; i++) { h = (h * 31 + s.charCodeAt(i)) >>> 0; } return h; }
  function initialsOf(name) {
    var parts = String(name || "??").trim().split(/\s+/).filter(Boolean);
    if (!parts.length) { return "??"; }
    return (parts[0].charAt(0) + (parts[1] ? parts[1].charAt(0) : "")).toUpperCase();
  }
  function bubble(name) { return { couleur: PALETTE[hashCode(name) % PALETTE.length], initiales: initialsOf(name) }; }

  function formatSalaire(annuel) {
    if (!annuel) { return ""; }
    var n = Math.round(Number(annuel) / 1000) * 1000;
    return n.toLocaleString("fr-FR") + " € brut / an";
  }

  /* ---- Adaptateur OFFRE (vue publique native OU externe → forme historique) ---- */
  function adaptJob(j) {
    j = j || {};
    var company = j.company || {};
    var src = j.source || {};
    var app = j.application || {};
    var b = bubble(company.nom || j.titre);
    return {
      id: j.uuid,
      titre: j.titre || "",
      entrepriseId: company.uuid || null,
      entrepriseNom: company.nom || "",
      ville: j.ville || "",
      departement: j.departement || "",
      contrat: j.contrat || "",
      duree: j.duree || "",
      tempsTravail: j.temps_travail || "",
      experience: j.experience || "",
      experienceLabel: j.experience || "",
      niveauEtude: j.niveau_etude || "",
      niveauEtudeLabel: j.niveau_etude || "",
      teletravail: j.teletravail || null,
      salaire: j.salaire || formatSalaire(j.salaire_annuel),
      salaireAnnuel: j.salaire_annuel || 0,
      datePublication: j.date_publication || null,
      dateExpiration: j.date_expiration || null,
      statut: "active",
      categorie: j.categorie || "",
      categorieLabel: j.categorie_label || "",
      competences: Array.isArray(j.competences) ? j.competences : [],
      missions: Array.isArray(j.missions) ? j.missions : [],
      profil: Array.isArray(j.profil) ? j.profil : [],
      avantages: Array.isArray(j.avantages) ? j.avantages : [],
      description: j.description || "",
      resume: j.resume || "",
      couleur: b.couleur,
      initiales: b.initiales,
      logoUrl: company.logo_url || null,
      verifie: !!company.verified,
      /* Provenance / candidature */
      external: !!src.external,
      sourceType: src.type || "native",
      sourceKey: src.key || "postelio",
      sourceLabel: src.label || (src.external ? "Partenaire" : "Postelio"),
      attribution: src.attribution || null,
      applicationMode: app.mode || "postelio",
      applyRedirect: app.redirect || null,
      seo: j.seo || null
    };
  }

  /* ---- Adaptateur ENTREPRISE (vue publique → forme historique) ----
     Clés éditoriales RÉELLES du contrat (cf. CompanyService) : secteur, activite, ville, effectif,
     adresse, telephone, email, site, avantages, valeurs, logo_url. L'API n'expose ni `departement`,
     ni `taille`, ni `site_web` : on lit donc `effectif` et `site`, en tolérant les anciens noms au
     cas où le backend viendrait à les exposer. */
  function adaptCompany(c) {
    c = c || {};
    var ed = c.editorial || {};
    var b = bubble(c.nom);
    return {
      id: c.uuid,
      nom: c.nom || "",
      description: c.description || "",
      ville: ed.ville || "",
      departement: "",
      secteur: ed.secteur || "",
      activite: ed.activite || ed.secteur || "",
      taille: ed.effectif || ed.taille || "",
      adresse: ed.adresse || "",
      telephone: ed.telephone || "",
      email: ed.email || "",
      siteWeb: ed.site || ed.site_web || "",
      logoUrl: ed.logo_url || null,
      couleur: b.couleur,
      initiales: b.initiales,
      verifie: !!c.verified,
      verifieLabel: c.verified ? "Entreprise vérifiée" : "",
      valeurs: Array.isArray(ed.valeurs) ? ed.valeurs : [],
      avantages: Array.isArray(ed.avantages) ? ed.avantages : [],
      legal: c.legal || {}
    };
  }

  /* ---- Requêtes ---- */
  var jobs = {
    /* filters : objet de paramètres publics déjà normalisés (q, ville, contrat, categorie,
       niveau_etude, experience, salaire_min, source…). */
    search: function (filters, page, perPage) {
      var query = {};
      Object.keys(filters || {}).forEach(function (k) { query[k] = filters[k]; });
      query.page = page || 1;
      query.per_page = perPage || 12;
      return API.client.get("/jobs", { query: query }).then(function (res) {
        var meta = (res.meta && res.meta.pagination) || {};
        return {
          items: (res.data || []).map(adaptJob),
          total: meta.total || 0,
          totalPages: meta.total_pages || 1,
          page: meta.page || query.page,
          perPage: meta.per_page || query.per_page,
          totalIsExact: meta.total_is_exact !== false
        };
      });
    },
    get: function (uuid) {
      return API.client.get("/jobs/" + encodeURIComponent(uuid)).then(function (res) { return adaptJob(res.data); });
    },
    applyRedirectUrl: function (uuid) {
      return API.config.apiBaseUrl + "/jobs/" + encodeURIComponent(uuid) + "/apply-redirect";
    }
  };

  var companies = {
    list: function (page, perPage) {
      return API.client.get("/companies", { query: { page: page || 1, per_page: perPage || 24 } }).then(function (res) {
        var meta = (res.meta && res.meta.pagination) || {};
        return {
          items: (res.data || []).map(adaptCompany),
          total: meta.total || 0,
          totalPages: meta.total_pages || 1,
          page: meta.page || (page || 1),
          perPage: meta.per_page || (perPage || 24)
        };
      });
    },
    get: function (uuid) {
      return API.client.get("/companies/" + encodeURIComponent(uuid)).then(function (res) { return adaptCompany(res.data); });
    }
  };

  window.PostelioDirectory = {
    jobs: jobs,
    companies: companies,
    adaptJob: adaptJob,
    adaptCompany: adaptCompany,
    bubble: bubble
  };
})();

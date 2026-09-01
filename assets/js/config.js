/**
 * Configuration centrale de Postelio.
 * Toutes les futures URL d'API sont regroupées ici : lors du passage
 * à WordPress (ou à une passerelle de paiement réelle), il suffira de
 * remplacer les valeurs `null` par les vraies adresses.
 * Aucune clé d'API réelle ne doit jamais être stockée dans ce fichier.
 */
window.APP_CONFIG = {
  siteName: "Postelio",

  /* Sources de données de démonstration (fichiers JSON locaux). */
  data: {
    offers: "data/offers.json",
    companies: "data/companies.json",
    articles: "data/articles.json",
    savoirFaire: "data/savoir-faire.json",
    guidedSearch: "data/guided-search.json"
  },

  /* ⚠️ DÉPRÉCIÉ (Lot d'intégration I1).
     La configuration et l'appel des API réelles Postelio sont désormais assurés par le SOCLE :
       - assets/js/api/postelio-api.js  → window.PostelioAPI.config (base URL résolue par
         environnement, surchargeable via window.POSTELIO_CONFIG) + client HTTP unique ;
       - assets/js/auth/postelio-auth.js → session réelle (jeton Bearer + GET /me).
     Les chemins ci-dessous NE sont PLUS l'API cible (les vraies routes sont sous
     `/wp-json/postelio/v1`, cf. docs/backend/api-contract.md). Ne PAS réintroduire de
     `fetch('/wp/v2/...')`. Seul `geocoding` (service public sans clé) reste réellement utilisé,
     par assets/js/autocomplete.js. Ce bloc sera retiré au fil des lots d'intégration. */
  api: {
    baseUrl: null, /* déprécié — voir PostelioAPI.config.apiBaseUrl */
    endpoints: {
      /* geocoding : SEUL endpoint encore actif (API Adresse — service public, sans clé). */
      geocoding: "https://api-adresse.data.gouv.fr/search/",
      /* --- ci-dessous : placeholders DÉPRÉCIÉS, non appelés (branchement lot par lot) --- */
      _deprecated: true,
      offers: "/wp/v2/offre",
      companies: "/wp/v2/entreprise",
      articles: "/wp/v2/posts",
      applications: "/postelio/v1/candidatures",
      contact: "/postelio/v1/contact",
      newsletter: "/postelio/v1/newsletter",
      chatbot: "/postelio/v1/chatbot",
      savoirFaire: "/wp/v2/savoir-faire",
      knowhowRatings: "/postelio/v1/savoir-faire/notes",
      knowhowComments: "/postelio/v1/savoir-faire/commentaires",
      knowhowReports: "/postelio/v1/savoir-faire/signalements",
      guidedSearch: "/postelio/v1/recherche-guidee",
      recommendations: "/postelio/v1/recommandations",
      companiesDirectory: null
    }
  },

  /* Paiement simulé — structure prête pour Stripe / WooCommerce. */
  payment: {
    provider: "demo",          /* remplacer par "stripe", "woocommerce"… */
    publicKey: null,           /* jamais de clé réelle dans cette version front */
    renewal: {
      price: 10,
      currency: "EUR",
      durationDays: 30,
      label: "Renouvellement d'une offre d'emploi (30 jours)"
    }
  },

  /* Clés du stockage local du navigateur. */
  storage: {
    session: "ss_session",
    customOffers: "ss_custom_offers",
    offerOverrides: "ss_offer_overrides",
    payments: "ss_payments",
    customKnowhow: "ss_sf_publications",
    knowhowRatings: "ss_sf_notes",
    knowhowComments: "ss_sf_commentaires",
    knowhowViews: "ss_sf_vues",
    knowhowReports: "ss_sf_signalements",
    /* Espace candidat (version front) */
    applications: "ss_candidate_applications",
    favorites: "ss_candidate_favorites",
    alerts: "ss_candidate_alerts",
    candidateProfile: "ss_candidate_profile"
  },

  /* Compte entreprise de démonstration pour l'espace entreprise. */
  demoCompany: {
    id: "fiduciaire-bellecour",
    nom: "Fiduciaire Bellecour",
    contact: "Claire Martin"
  },

  /* Comptes de démonstration — plateforme à deux faces (version front).
     AUCUN mot de passe réel n'est stocké ni vérifié. */
  demoAccounts: {
    candidate: {
      loggedIn: true,
      role: "candidate",
      firstName: "Jonathan",
      lastName: "Davy",
      email: "jonathan.davy@exemple.fr",
      city: "Lyon",
      metier: "Développeur web"
    },
    employer: {
      loggedIn: true,
      role: "employer",
      firstName: "Claire",
      lastName: "Martin",
      email: "claire.martin@fiduciaire-bellecour.exemple.fr",
      city: "Lyon",
      company: "Fiduciaire Bellecour",
      companyId: "fiduciaire-bellecour",
      secteur: "Finance & Comptabilité"
    }
  }
};

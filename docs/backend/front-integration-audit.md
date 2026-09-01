# Postelio — Audit d'intégration Front ↔ Backend (Phase 1 : cartographie)

> Carte vivante du branchement front ↔ backend. La **Phase 1** était une cartographie (aucun code).
> Le branchement se fait ensuite **lot par lot** (I1, I2, …) en préservant les données de démo
> jusqu'au nettoyage final. Statut de chaque lot en fin de document. Référence backend gelée à la
> cartographie : develop = `601e648`, main = `027cbe1`.

## 0. Constat central

Le front public (racine du dépôt, servi à `http://postelio.local/`) est une **démo 100 % statique /
localStorage**. `APP_CONFIG.api.baseUrl = null` (`assets/js/config.js`) : **aucune page n'appelle
jamais une vraie API**. Tout passe par `window.SS` (`assets/js/main.js`) :
- `SS.loadJSON()` → `fetch()` de fichiers **locaux** `data/*.json` (cache mémoire) ;
- `SS.store` → wrapper `localStorage` ;
- `SS.auth` → **session simulée** (`ss_session`, aucun mot de passe stocké/vérifié), garde
  client-side `SS.auth.require(role)`.

Seuls vrais appels réseau du front : les JSON locaux + `assets/js/autocomplete.js` →
`api-adresse.data.gouv.fr` (géocodage public, sans clé). Le backend réel (`postelio/v1/*`, Lots
01–14) est **prêt et testé** mais **entièrement débranché** du front. Les `api.endpoints` de
`config.js` sont des **placeholders périmés** qui ne correspondent même pas aux routes réelles
(ex. `offers: "/wp/v2/offre"` alors que le backend expose `/postelio/v1/jobs`).

**Conséquence** : le branchement est un chantier quasi « greenfield » côté front, à mener domaine
par domaine contre le contrat `postelio/v1` réel, en préservant les données de démo jusqu'au bout.

## 1. Cartographie des pages (29 HTML)

| Page | Fichier JS | Rôle | Source actuelle | Backend prêt | API cible (postelio/v1) | Statut | Prio |
|---|---|---|---|---|---|---|---|
| Accueil | main/offers/companies/hero-video/scroll-video | public | JSON + hardcodé | oui (jobs/companies/site) | GET /jobs, /companies, /site/config | statique + mock | P0/P1 |
| Offres (liste) | offers.js, filters.js | public | offers.json + overrides + favorites LS | **oui** | GET /jobs (+filtres) | mock/JSON | **P0** |
| Offre détail | offers.js | public | offers.json + companies.json | **oui** | GET /jobs/{uuid} (+externe) | mock/JSON | **P0** |
| Recherche guidée | guided-search.js | public | guided-search.json + JSON | partiel | (mapper sur GET /jobs) | JSON | P2 |
| Entreprises | companies.js | public | companies.json + ss_candidate_followed | oui | GET /companies | JSON | P1 |
| Entreprise détail | companies.js | public | companies.json | oui | GET /companies/{uuid} | JSON | P1 |
| Savoir-faire (liste) | knowhow.js | public | savoir-faire.json + ss_sf_* | oui (skills) | GET /skills | JSON+LS | P2 |
| Savoir-faire détail | knowhow.js | public | savoir-faire.json + ss_sf_* (notes/vues/comm.) | partiel | GET /skills/{uuid} (+comments) ; notes/vues = **gap** | JSON+LS mock | P2 |
| Blog / Article | blog.js | public | articles.json (HTML inline) | (WP natif) | WP `/wp/v2/posts` ou statique | JSON | P3 |
| Contact | contact.js | public | **simulé** (aucun envoi) | **NON** | (gap) | mock | P2 |
| Connexion | auth.js | public | demoAccounts → ss_session | **oui** | POST /auth | mock | **P0** |
| Inscription | auth.js | public | ss_session | **oui** | POST /auth/register | mock | **P0** |
| Espace candidat | dashboard-candidat.js | candidat | ~18 clés ss_* | oui | /me, /me/applications, /me/favorites/jobs, /me/saved-searches, /me/files/cv, /me/interviews, /me/notifications | mock/LS | **P0** |
| Espace entreprise (dashboard) | employer-dashboard.js | recruteur | ss_* + seeds | oui | /companies/me, /companies/me/applications | mock/LS | P1 |
| — Offres | employer-offers.js, publish.js | recruteur | ss_custom_offers, ss_offer_overrides, ss_offre_brouillon | oui | GET /jobs/me, POST/PUT /jobs, lifecycle | mock/LS | P1 |
| — Candidatures (kanban) | employer-candidates.js | recruteur | pipelineSeed + ss_pipeline_v1 + ss_applications_sent | oui | GET /companies/me/applications, PUT statut | mock/LS | P1 |
| — Messagerie | employer-messages.js | recruteur | seed + ss_msg_state (msgs non persistés) | oui | /me/conversations, /companies/me/applications/{uuid}/conversation | mock/LS | P1 |
| — Entretiens | employer-interviews.js | recruteur | ss_interviews_v1 | oui | /companies/me/interviews | mock/LS | P1 |
| — Facturation | employer-billing.js | recruteur | **hardcodé** (ignore ss_payments) | oui | GET /billing/orders | mock | P1 |
| — Profil | employer-profile.js | recruteur | ss_company_profile (SIREN fictif) | oui | /recruiters/me/profile, /companies/me, /companies/me/verification | mock/LS | P1 |
| — Paramètres | employer-settings.js | recruteur | ss_employer_settings | oui | /me/settings, /me/notification-preferences | mock/LS | P2 |
| — Contenus | employer-content.js | recruteur | SEED + ss_company_content | oui (skills) | /me/skills (as_company) | mock/LS | P2 |
| Publier offre | publish.js | recruteur | ss_custom_offers + templates | oui | POST /jobs (+ publish) | mock/LS | P1 |
| Publier savoir-faire | knowhow-publish.js | mixte | ss_sf_publications (statut "attente") | oui | POST /me/skills + /publish | mock/LS | P2 |
| Paiement | payment.js | recruteur | demo → ss_payments + ss_offer_overrides | oui | POST /billing/checkout (webhook = vérité) | mock | P1 |
| Chatbot (widget) | chatbot.js | public | scénarios locaux | **NON** | (gap / hors V1) | mock | P3 |
| À propos / Mentions / Confidentialité / 404 | — | public | statique | n/a | — | statique | — |

## 2. Inventaire mocks / localStorage (≈35 clés)

> `config.js` ne déclare que ~13 clés ; les modules JS en définissent bien d'autres en dur.

**Session / auth** : `ss_session` (écrit auth.js/main.js ; lu partout) → remplacer par jeton Bearer + `GET /me`.

**Candidat** : `ss_candidate_applications`, `ss_applications_sent` (**PARTAGÉE** : écrite par le flux
« postuler » offre-detail, lue par le kanban recruteur ET fusionnée au dashboard candidat),
`ss_candidate_favorites` (cœur offre = dashboard), `ss_candidate_alerts`, `ss_candidate_profile`,
`ss_candidate_cv` + `ss_candidate_cvs` (**nom + date seulement, jamais le fichier**),
`ss_candidate_knowhow`, `ss_candidate_followed`, `ss_seed_version`, `ss_pipeline_v1`,
`ss_cand_relances`, `ss_cand_interviews_confirmed`, `ss_cand_iv_prep`, `ss_cand_iv_debrief`,
`ss_refus_demo` (partagée), `ss_candidate_settings`, `ss_notifs_candidate`.

**Recruteur** : `ss_custom_offers`, `ss_offer_overrides`, `ss_offre_brouillon`, `ss_company_profile`,
`ss_employer_settings`, `ss_company_content`, `ss_pipeline_v1`, `ss_refus_demo`, `ss_cand_notes_v1`,
`ss_emails_sent`, `ss_interviews_v1` (+ `ss_interviews_seed`), `ss_msg_state`, `ss_payments`,
`ss_notifs_employer`.

**Savoir-faire / public** : `ss_sf_publications` (customKnowhow), `ss_sf_notes` (notes = **mock, pas
de backend V1**), `ss_sf_vues` (vues = **mock**), `ss_sf_commentaires`, `ss_sf_signalements`,
`ss_kh_tested`, `ss_kh_saved`, `ss_chat_vu` (sessionStorage).

Chaque clé sera remplacée par l'API du domaine correspondant (§3 mapping). Clés **sans backend** :
`ss_sf_notes`, `ss_sf_vues`, `ss_kh_tested`, `ss_emails_sent`, `ss_refus_demo` (motif interne jamais
transmis — OK), `ss_*_iv_prep/debrief/relances` (aides perso candidat — à décider : garder local ou
créer un backend « bloc-notes »).

## 3. Matrice front → API réelle (par domaine)

| Domaine front | Endpoint réel | Écart / travail |
|---|---|---|
| Login / register / logout | `POST /auth`, `/auth/register`, `/auth/logout(-all)`, `/auth/refresh` | session simulée → Bearer ; garde `require()` → `GET /me` |
| Vérif e-mail / mot de passe oublié | `/auth/verify-email(/resend)`, `/auth/lost-password`, `/auth/reset-password` | **UI absente côté front** (backend prêt) |
| Profil candidat | `GET/PUT /candidates/me/profile`, `GET /me` ; public `GET /candidates/{uuid}` | ss_candidate_profile → API |
| Offres (liste/filtres) | `GET /jobs` (q, ville, contrat, categorie, teletravail, niveau_etude, experience, salaire_min, alternance, stage, debutant, **source**) | front n'a **pas** le filtre source/partenaires ni les offres externes |
| Offre détail + externe | `GET /jobs/{uuid}` ; apply-redirect (job-sources) | gérer 404/410 (retirée), redirection partenaire |
| Favoris | `GET/POST/DELETE /me/favorites/jobs[/{ref}]` (Lot 14) | ss_candidate_favorites → API (idempotent) |
| Alertes | `CRUD /me/saved-searches` (+ preview/run-now, Lot 14) | champs front (metier→q, lieu→ville, **rayon = non supporté**, salaireMin→salaire_min, datePub→published_after interne) ; fréquence disabled/daily/weekly |
| Candidature (postuler) | `POST /jobs/{uuid}/applications` | **réponses de présélection obligatoires** (422 sinon) — non collectées côté front ; CV réel requis |
| Suivi candidatures | `GET /me/applications[/{uuid}]`, retrait | ss_applications_sent → API |
| Kanban recruteur | `GET /companies/me/applications`, changement de statut, `/companies/me/applications/{uuid}/conversation` | **mapper les colonnes front** (nouveau/examiner/preselection/entretien/retenu/refuse) sur la machine à états backend V1 (moins granulaire) |
| CV & fichiers | `POST/GET /me/files/cv[/…]` | fichiers **privés** : jamais d'URL publique ; upload réel (le front ne stocke que le nom) |
| Messagerie | `GET /me/conversations[/{uuid}]`, envoi, `conversation.read` | contextualisée **par candidature** : créer la conversation via `/companies/me/applications/{uuid}/conversation` ; messages front non persistés |
| Entretiens | `/me/interviews`, `/companies/me/interviews` | types visio/onsite/phone OK ; coordonnées **capability-gated** |
| Notifications | `GET /me/notifications`, `/unread-count`, `/read-all`, `/me/notification-preferences` | cloche **décorative** (seed local) → API réelle |
| Savoir-faire | `GET /skills[/{uuid}]`, `POST /me/skills` (+publish), commentaires `/skills/…`, signalement `POST /me/moderation/reports` | **notes/vues = pas de backend V1** (futur) |
| Facturation | `POST /billing/checkout` (webhook = vérité), `GET /billing/orders` | success_url ≠ preuve ; le front active l'offre **immédiatement** (à corriger : attendre le fulfillment) ; historique hardcodé |
| Modération (front) | `POST /me/moderation/reports` ; gérer `moderation_blocked` (422) à la publication | signalements LS → API |
| Site Builder public | `GET /site/config[/{page}]` (public) | **le front normal ne consomme PAS la config** (bridge inerte hors preview) → tout est en dur |
| SEO | contrats skills/jobs/site + `sitemap.xml` (statique) | HTML **générique statique**, **aucun canonical**, JS ne change que `document.title` |

## 4. Backend readiness (§29)

| Domaine | Prêt | Justification |
|---|---|---|
| Users / Auth | **OUI** | Lot 02 : /auth/* complet (register, verify, lost/reset, refresh, logout), Bearer, garde caps. |
| Companies | **OUI** | Lot 03 : CRUD, vérification, annuaire, fiche publique, membres. |
| Jobs | **OUI** | Lot 04 : recherche publique + détail + cycle de vie + UUID. |
| External jobs | **OUI** | Lot 10 : recherche composite, apply-redirect, états source, SEO. |
| Applications | **OUI** | Lot 05 : postuler (présélection obligatoire), suivi, kanban, statuts. |
| Files (CV) | **OUI** | Lot 06 : fichiers **privés**, snapshot CV. |
| Messaging | **OUI** | Lot 07 : conversations **contextualisées par candidature**, unread/read/close. |
| Interviews | **OUI** | Lot 08 : proposer/confirmer/refuser/replanifier/annuler/terminer, 3 types. |
| Notifications | **OUI** | Lot 09 : in-app + e-mail + préférences par catégorie. |
| Moderation | **OUI** | Lot 11 : signalements + passerelle préventive. |
| Billing | **OUI** | Lot 12 : Stripe Checkout + **webhook = vérité** (exactly-once). |
| Skills | **OUI** (contenus/commentaires/modération) ; **NON** (notation/vues) | Lot 13 : skill + commentaires + modération V1 ; notation multi-critères/réactions = futur. |
| Favorites / Alerts | **OUI** | Lot 14 : favoris, recherches sauvegardées, alertes. |
| Site Builder | **PARTIEL** | Config REST publique prête, mais **le front public ne la consomme pas** (preview only) → à câbler. |

## 5. Gaps backend (§30) — NE PAS coder maintenant

| Fonction | Écran | Importance | Solution recommandée | Lot |
|---|---|---|---|---|
| Formulaire contact (envoi) | contact.html | moyenne | endpoint `/contact` (rate-limit + anti-spam + e-mail via Notifications) OU form WP | futur |
| Newsletter | footer, blog | faible | endpoint `/newsletter` OU service tiers | futur |
| Recherche guidée (moteur) | recherche-guidee.html | faible | mapper sur `GET /jobs` (pas de nouvel endpoint) | I (offres) |
| Recommandations d'offres | accueil/candidat | moyenne | endpoint reco (ou réutiliser filtres profil) ; `offers_reco` n'est qu'une **catégorie de notif** | futur |
| Notation / vues savoir-faire | savoir-faire détail | faible | **hors V1** (Lot 13 l'exclut) → futur | futur |
| Chatbot | widget | faible | hors périmètre V1 | futur |
| Aides perso candidat (prépa/débrief/relances entretien) | espace candidat | faible | soit garder local, soit petit backend « notes candidat » | à décider |
| Consommation Site Builder par le front public | tout le site | **haute** | fetch `GET /site/config` au chargement normal + application (ou SSR) | I (Site Builder) |
| SEO réellement appliqué (title/desc/canonical/OG par ressource) | offres/skills/entreprises | **haute** | rendu dynamique/SSR + `link canonical` (absent partout) | I (SEO) |

## 6. Plan d'intégration ordonné (proposé, à valider)

Petits lots testables, dépendances croissantes. **Ne pas tout brancher d'un coup.**

| Lot | Contenu | Backend requis | Dépend de | Prio | Risque |
|---|---|---|---|---|---|
| **I1 — Socle API + Auth** | Client API unique (baseUrl, Bearer, parseur d'erreurs, composants loading/empty/error/retry, mapping 401/403/404/409/410/422/429) ; connexion/inscription/logout réels ; garde `/me` ; verify-email + reset UI | Users/Auth | — | **P0** | auth, CORS, Tauri, perte session démo |
| **I2 — Offres & entreprises publiques** | GET /jobs (filtres réels + **source/partenaires** + externes + pagination approx.), /jobs/{uuid} (404/410/redirect), /companies[/{uuid}] | Jobs, Job-sources, Companies | I1 | **P0** | pagination approximative, XSS contenu externe |
| **I3 — Profil candidat + CV** | /candidates/me/profile, upload CV **privé** (/me/files/cv) | Users, Files | I1 | **P0/P1** | upload, privacy, taille/type |
| **I4 — Candidatures candidat** | postuler (présélection + CV), /me/applications, retrait | Applications | I2, I3 | **P0** | présélection obligatoire, états optimistes |
| **I5 — Recruteur offres + kanban** | /jobs/me, POST/PUT/lifecycle, /companies/me/applications + statuts | Jobs, Applications | I1 | P1 | mapping colonnes→états, drag optimiste |
| **I6 — Messagerie** | conversations contextualisées candidature | Messaging | I4, I5 | P1 | contexte par candidature, XSS messages |
| **I7 — Entretiens** | candidat + recruteur, 3 types | Interviews | I5 | P1 | coordonnées capability-gated |
| **I8 — Notifications** | cloche réelle + préférences | Notifications | I1 | P1 | remplacement du seed décoratif |
| **I9 — Favoris & Alertes** | /me/favorites/jobs, /me/saved-searches | Alerts (Lot 14) | I2 | P1 | mapping champs alerte, migration LS |
| **I10 — Savoir-faire** | /skills, publish, commentaires, signalement, `moderation_blocked` | Skills, Moderation | I1 | P2 | notation/vues = gap (futur) |
| **I11 — Facturation** | checkout Stripe, **webhook = vérité**, /billing/orders | Billing | I5 | P1 | success≠preuve, historique |
| **I12 — Site Builder public + SEO** | fetch /site/config au chargement + application ; SEO par ressource + canonical | Site, Jobs, Skills | I2 | P1/P2 | SSR vs client, crawlers |
| **I13 — Gaps** | contact/newsletter/guided/reco (build vs report) | (nouveaux) | — | P2/P3 | périmètre |
| **I14 — Nettoyage mocks** | suppression localStorage/JSON de démo, migration éventuelle, retrait demoAccounts | — | tous | P2 | perte de données, régressions |

## 7. Priorisation

- **P0 (indispensable pour que Postelio fonctionne réellement)** : I1 (auth/socle), I2 (offres/entreprises), I3 (profil/CV), I4 (candidatures candidat).
- **P1 (lancement)** : I5 (recruteur), I6 (messagerie), I7 (entretiens), I8 (notifications), I9 (favoris/alertes), I11 (billing), I12 (Site Builder public + SEO).
- **P2 (amélioration)** : I10 (savoir-faire), I13 (gaps), I14 (nettoyage).
- **P3 (futur)** : chatbot, notation/vues savoir-faire, recommandations, blog WP natif.

## 8. Risques transverses (§31)

1. **Perte de données localStorage** au passage API (favoris/alertes/candidatures/brouillons) → prévoir une migration one-shot optionnelle ou assumer la perte (démo).
2. **Auth** : session simulée → Bearer ; garde client-side → serveur ; **Tauri** (Bearer vs cookie, URL API, CORS).
3. **CORS / origine** : front `postelio.local/` vs `postelio.local/wordpress/wp-json` (même origine en local, à valider en préprod/prod).
4. **Présélection obligatoire** : le flux « postuler » doit collecter les réponses (422 sinon).
5. **Upload CV** : fichiers **privés** (jamais d'URL publique), taille/type, aperçu.
6. **États optimistes** (kanban drag, favoris) : réconcilier avec la réponse serveur (rollback sur erreur).
7. **Pagination approximative** (Lot 10, `total_is_exact=false`) : UI honnête au-delà de la fenêtre.
8. **Billing** : `success_url` ≠ preuve → n'activer l'offre que sur **webhook/fulfillment**.
9. **XSS** : le front injecte du HTML issu des JSON (skills `contenu`, articles) via `innerHTML` → échapper/sanitiser le contenu API (surtout skills HTML, messages, offres externes).
10. **Privacy** : CV privé, notes recruteur, coordonnées d'entretien (capability-gated) — ne jamais exposer au mauvais rôle.
11. **SEO** : titres posés uniquement par JS (invisibles aux crawlers), **aucun canonical** → nécessitera un rendu dynamique/SSR.
12. **Mobile / Tauri** : liens externes, apply-redirect, checkout Stripe (ouverture navigateur système).

## 9. Couche client API (§22) — cible unique (à NE PAS implémenter maintenant)

Aujourd'hui : une seule couche `SS` (loadJSON/store/auth) orientée démo, **sans** baseUrl ni Bearer.
Cible : un client `api.js` unique (baseUrl depuis `APP_CONFIG`, en-tête `Authorization: Bearer`,
parseur d'erreurs `{error:{code,message,details}}`, gestion 401→refresh/redirect, helpers GET/POST/
PUT/DELETE, composants d'état réseau). Conserver `SS.store` uniquement pour des préférences locales
non sensibles. **Aucun secret dans le JS public.**

## 10. Lots suivants recommandés (immédiats)

1. **I1 — Socle API + Auth** (P0) : la brique bloquante ; tout en dépend.
2. **I2 — Offres & entreprises publiques** (P0) : valeur immédiate, risque faible, public.
3. **I3 + I4 — Profil/CV + Candidatures candidat** (P0) : cœur du produit côté candidat.

Chaque lot : une branche `feature/front-Ixx`, données de démo conservées jusqu'à I14, tests
manuels + (si possible) e2e navigateur, et un critère de sortie « le flux marche contre le vrai
backend sans casser les pages non encore branchées ».

---

## I1 — Socle API + Authentification — ✅ LIVRÉ (branche `feature/front-api-auth`)

**Fait** : client HTTP UNIQUE (`assets/js/api/postelio-api.js` → `PostelioAPI`), erreurs
structurées (`ApiError` + messages utilisateur), session RÉELLE (`assets/js/auth/postelio-auth.js`
→ `PostelioAuth.session`, jeton Bearer + `GET /me`, mapping rôle backend `recruiter`↔front
`employer`), gardes (`requireAuth/Candidate/Recruiter`), amorçage + pont `SS.auth` réel
(`assets/js/auth/postelio-boot.js`). Connexion / inscription candidat / inscription recruteur /
déconnexion / mot de passe oublié (demande) BRANCHÉS (`assets/js/auth.js`). Bandeau « e-mail non
vérifié » + renvoi. Socle inclus sur les 29 pages ; `data-guard` sur les 12 pages privées.
`ss_session` simulé RETIRÉ (le reste des mocks métier est conservé — mode hybride).

**Transport** : **Bearer uniquement** (`credentials: omit`). Motif : envoyer le cookie WordPress
force WP à exiger un nonce REST (absent d'un front statique) → 401 malgré un Bearer valide. Choix
cohérent web + future app Tauri (fournisseur de jeton abstrait `PostelioAuth.tokens.use`).

**Gaps backend/front restants (I1)** :
- Page de **réinitialisation** de mot de passe (le lien e-mail pointe vers `/reinitialiser-mot-de-passe`) : page front à créer (backend `/auth/reset-password` prêt) → lot ultérieur.
- Page d'**atterrissage de vérification e-mail** (lien `uid`+`token`) : page front à créer (backend `/auth/verify-email` prêt). En local, l'inscription auto-vérifie l'e-mail.
- Inscription recruteur : crée le **compte** recruteur ; la création + vérification de l'**entreprise** (Lot 03) reste à brancher (lot I5).

**Détails** : voir `docs/frontend/api-client.md` et `docs/frontend/auth-integration.md`.

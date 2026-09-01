# Postelio Front — Intégration Offres & Entreprises publiques (I2)

Consultation publique branchée sur l'API réelle `postelio/v1`. Aucune donnée JSON locale pour ces
pages, **aucun repli JSON** (API réelle ou état d'erreur). Les données MÉTIER privées restent en
localStorage jusqu'aux lots suivants (mode hybride).

## Fichiers
- `assets/js/api/directory.js` → `window.PostelioDirectory` : `jobs.search/get/applyRedirectUrl`,
  `companies.list/get`, `adaptJob/adaptCompany/bubble`. Passe par `PostelioAPI.client` (client unique).
- `assets/js/filters.js` (offres liste), `assets/js/offers.js` (carte partagée, accueil, fiche,
  similaires), `assets/js/companies.js` (annuaire + fiche). Cache-buster `?v=i2` sur ces includes.

## Routes backend utilisées
`GET /jobs` (filtres + pagination + `meta.pagination.total_is_exact`) · `GET /jobs/{uuid}` (404
inconnue/masquée/source désactivée, 410 retirée) · `GET /jobs/{uuid}/apply-redirect` (302 → partenaire)
· `GET /companies` (paginé) · `GET /companies/{uuid}` (404).

## Adaptateurs (API → forme historique)
Les gabarits de rendu existants (SS.offerCard, fiche, cartes entreprises) sont **conservés** : seule
la source change. `adaptJob` projette la vue publique (native OU externe) vers `{ id(uuid), titre,
entrepriseNom, ville, contrat, salaire, datePublication, external, sourceLabel, attribution,
applicationMode, applyRedirect, couleur, initiales, verifie, … }`. `adaptCompany` idem pour les
entreprises. `bubble(nom)` génère une couleur déterministe + initiales (l'API ne fournit pas de
couleur d'entreprise).

## Offres — liste
Recherche + pagination **côté serveur** (`PostelioDirectory.jobs.search(filters, page, 12)`), tri par
date de publication décroissante (défaut backend). Filtres envoyés : `q, ville, contrat, categorie,
niveau_etude, experience, salaire_min, source`. Provenance ajoutée (sélecteur Toutes/Postelio/
Partenaires → `source=all|postelio|partners`). URL partageable (`?q&lieu&categorie&contrat&…&page`),
anti-réponse-périmée (jeton de séquence), debounce 300 ms sur les champs texte. États :
squelette de chargement, vide (avec réinitialisation), erreur (`ApiError.userMessage()` + Réessayer).
`total_is_exact=false` → « Plus de N offres » (jamais un total exact trompeur).

**Contrôles masqués (pas de backend V1, jamais simulés)** : récence (`#filter-date`), télétravail-
uniquement (`#filter-remote`), tri (`#sort-select`), « ★ Enregistrées » (`#saved-filter`, revient en
I9). Le bouton d'enregistrement par carte reste (localStorage, branché réellement en I9).

## Offre — fiche
- **Native** : titre/description/résumé/lieu/contrat/salaire/dates/missions/profil/compétences/
  avantages + encart entreprise (badge vérifié, lien fiche) + schéma **JobPosting** (indexable).
  Bouton « Postuler » = modale transitoire (localStorage `ss_applications_sent`) **inchangée jusqu'à
  I4** (candidature réelle).
- **Externe (partenaire)** : mêmes champs + **bloc d'attribution** (source, « Offre proposée par … »,
  licence, date de mise à jour) ; bouton « Postuler sur le site partenaire » →
  `PostelioDirectory.jobs.applyRedirectUrl(uuid)` (navigation top-level, le backend renvoie 302).
  Pas de schéma JobPosting (offre `noindex`). Jamais de candidature Postelio.
- **404/410** : la zone fiche est remplacée par un message clair + CTA « Voir les autres offres ».
- **Similaires** : 1 requête (`categorie`, sinon `ville`), l'offre courante exclue (pas de N+1).

## Entreprises
- **Liste** : `PostelioDirectory.companies.list` (paginé). Le backend N'A PAS de recherche
  d'entreprises → la liste réelle est chargée (bornée à 10 pages) puis filtrée **côté client**
  (nom/secteur/ville). Cartes robustes aux champs absents (données réelles souvent partielles).
- **Fiche** : `companies.get(uuid)` — nom, description, éditorial (ville/secteur/taille/coordonnées si
  présents), **badge vérifié (serveur)**, identité légale publique (raison sociale, **SIREN** —
  public une fois vérifié). Jamais d'e-mail privé, de motif de vérification, ni d'ID interne.
  Le badge n'est PAS recalculé côté front (flag `verified` du backend).
- **Offres de l'entreprise** : pas d'endpoint public dédié (gap) → renvoi vers la page Offres.
- « Suivre l'entreprise » : conservé en localStorage (`ss_candidate_followed`), branchement réel
  ultérieur.

## Sécurité
Client unique (I1), Bearer inutile ici (pages publiques). Rendu : `SS.escapeHtml`/`textContent` pour
toute donnée serveur (cartes, fiche, attribution, messages). Liens externes `rel="nofollow noopener"`.

## Limites V1 / gaps (récapitulatif)
Filtre `company` sur `/jobs` absent (compteurs/offres par entreprise) · recherche serveur
d'entreprises absente (filtrage client) · tri, récence, télétravail-oui/non absents côté public ·
taxonomie catégorie/expérience non garantie · apply-redirect 302 nécessite une source partenaire
**configurée** (sinon 404, comportement correct). Cache-busting front : `?v=i2` (à généraliser).

## Tests
- `node tests/front-jobs-companies.test.mjs` (24 assertions : adaptateurs native/externe/entreprise,
  bulle, filtres+pagination+total_is_exact, apply-redirect).
- Vérification navigateur réelle sur `postelio.local` : liste (recherche serveur, provenance, URL,
  états), fiche native (JobPosting), fiche externe (attribution + 302 via provider de test),
  404/410, annuaire (données réelles + filtre nom), fiche entreprise (badge/SIREN, pas de fuite).

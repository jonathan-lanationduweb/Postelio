# Postelio — prototype

**Postelio** est une plateforme française dédiée à l'emploi des métiers du **secrétariat, de l'assistanat et de l'administratif**. Elle mélange plusieurs concepts :

- un moteur de recherche d'offres d'emploi (avec filtres, tri et pagination) ;
- une approche humaine proche d'une agence de recrutement ;
- un annuaire d'entreprises inspiré du principe d'un annuaire professionnel ;
- un espace entreprise pour publier, gérer et renouveler des offres (renouvellement à 10 €) ;
- un blog de conseils emploi ;
- un espace communautaire **« Savoir-faire & Avis »** où les professionnels partagent leurs méthodes, recettes et techniques, notées par les visiteurs ;
- une **« Recherche guidée »** : un assistant en une question par écran qui oriente le visiteur (emploi, entreprise, professionnel, publication, découverte de métier, savoir-faire) vers des suggestions personnalisées ;
- un chatbot d'accompagnement présent sur toutes les pages.

> ⚠️ **Prototype de démonstration** : toutes les offres, entreprises, coordonnées et personnes sont fictives. Aucun paiement réel, aucune donnée bancaire, aucune clé d'API.

## Direction artistique (v2 — refonte)

- Palette « **vert sapin & miel** » conservée mais redistribuée : blanc pour les sections de contenu, crème en respiration, une section sapin forte par page, orange réservé aux actions clés.
- **Hero éditorial** : typographie serif géante sur deux lignes (seconde en italique), accroche en colonne droite, et **vidéo locale pleine largeur** arrondie (Pexels, HD/SD selon l'écran, lecture automatique silencieuse, bouton Lire/Suspendre, `prefers-reduced-motion` respecté). Maquette Figma d'origine : fichier « Postelio — Hero éditorial vidéo ».
- **Photographies locales** (Pexels, format WebP, crédits dans `assets/images/photos/credits.md`) pour les articles, l'à-propos et les savoir-faire ; les schémas SVG restent pour les étapes.
- Typographie : la **serif** (Georgia) est réservée aux grands titres éditoriaux ; les titres de cartes et l'interface sont en sans-serif.
- **3 rayons de bordure seulement** (4 px, 8 px, cercle pour les avatars), boutons rectangulaires, étiquettes typographiques à la place des pilules, ombres quasi absentes, filets fins.
- Compositions variées : offre vedette + liste à filets, colonnes asymétriques, blocs numérotés, cartes à bordure partielle, listes éditoriales.
- Micro-interactions légères, `prefers-reduced-motion` respecté.

## Lancer le prototype

Les pages chargent leurs données via `fetch()` sur les fichiers JSON : il faut donc **un petit serveur local** (l'ouverture directe d'un fichier HTML par double-clic bloque le chargement des données dans la plupart des navigateurs).

Depuis le dossier `supersecretaire` :

```bash
# Avec Python (souvent déjà installé)
python -m http.server 8000

# Ou avec Node.js
npx serve .
```

Puis ouvrez `http://localhost:8000` dans votre navigateur.

## Organisation du projet

```text
supersecretaire/
├── index.html               Accueil (hero + recherche, offres, catégories, entreprises, articles)
├── offres.html              Recherche d'offres : filtres, tri, pagination
├── offre-detail.html        Fiche offre + candidature (modale) + offres similaires
├── entreprises.html         Annuaire des entreprises (recherche, filtres)
├── entreprise-detail.html   Fiche entreprise + ses offres
├── publier-offre.html       Formulaire de publication en 6 étapes
├── espace-entreprise.html   Tableau de bord entreprise (démo)
├── paiement.html            Paiement simulé du renouvellement (10 €)
├── recherche-guidee.html    Recherche guidée en pleine page (le quiz s'ouvre aussi en panneau
│                            depuis l'accueil, la page des offres, celle des entreprises
│                            et le menu mobile)
├── savoir-faire.html        Espace « Savoir-faire & Avis » : recherche, filtres, tris
├── savoir-faire-detail.html Fiche savoir-faire : étapes, notation 5 étoiles, commentaires, signalement
├── publier-savoir-faire.html Publication d'un savoir-faire en 6 étapes (étapes dynamiques)
├── blog.html                Liste des articles (catégories + recherche)
├── article.html             Article détaillé + articles associés
├── a-propos.html            Présentation de la plateforme
├── contact.html             Formulaire de contact (simulé)
├── connexion.html           Connexion / création de compte (simulées)
│
├── assets/
│   ├── css/                 Feuilles séparées : reset, variables (jetons), global,
│   │                        components, home, offers, companies, dashboard, blog, responsive
│   ├── js/                  Modules par fonctionnalité (voir ci-dessous)
│   ├── images/              Illustrations SVG légères des articles
│   └── icons/               Favicon SVG
│
└── data/
    ├── offers.json          15 offres fictives réalistes
    ├── companies.json       10 entreprises fictives
    ├── articles.json        7 articles de blog rédigés en français
    ├── savoir-faire.json    8 savoir-faire de métiers variés (boulanger, secrétaire,
    │                        parqueteur, mécanicien, RH, peintre, assistant, paysagiste)
    └── guided-search.json   Scénario de la recherche guidée (questions, réponses,
                             embranchements par parcours)
```

### Rôle des fichiers JavaScript

| Fichier | Rôle |
| --- | --- |
| `config.js` | **Configuration centrale** : chemins des données, futures URL d'API (WordPress, paiement, chatbot…), tarif du renouvellement, clés du stockage local. C'est le seul fichier à modifier pour brancher de vraies API. |
| `main.js` | Utilitaires partagés (`SS.*`) : chargement JSON avec cache, échappement HTML, dates en français, stockage local, modales, animation d'apparition. |
| `navigation.js` | Menu mobile + lien actif dans la navigation. |
| `search.js` | Moteur de recherche du hero (redirige vers `offres.html`). |
| `offers.js` | Gabarit de carte d'offre, offres récentes, fiche offre, candidature, partage, offres similaires, JSON-LD `JobPosting`. |
| `filters.js` | Filtres, tri, compteur et pagination de la page offres. |
| `companies.js` | Annuaire, entreprises mises en avant, fiche entreprise. |
| `blog.js` | Liste du blog, catégories, recherche, article, articles associés, newsletter simulée, JSON-LD `Article`. |
| `dashboard.js` | Tableau de bord : statistiques, liste des offres, désactivation / réactivation. |
| `publish.js` | Formulaire de publication en 6 étapes (validation, aperçu, enregistrement local). |
| `payment.js` | Paiement **simulé** du renouvellement : met à jour le statut de l'offre en stockage local. |
| `auth.js` | Connexion / inscription simulées (session fictive en stockage local). |
| `contact.js` | Formulaire de contact simulé. |
| `chatbot.js` | Chatbot « Clémence » : widget injecté sur toutes les pages, réponses prédéfinies, architecture prête pour une API d'IA. |
| `knowhow.js` | Savoir-faire & Avis : liste (filtres métier/catégorie/difficulté/note, tris récent/note/vues), fiche détaillée, notation 5 étoiles multi-critères avec anti double-vote, commentaires avec badge « Méthode testée », signalement, compteur de vues. |
| `knowhow-publish.js` | Publication d'un savoir-faire en 6 étapes avec ajout/suppression dynamiques des étapes ; enregistrement local au statut « en attente de validation ». |
| `guided-search.js` | Recherche guidée : moteur du quiz (une question par écran, barre de progression, précédent / passer / quitter), panneau réutilisable injecté sur les pages d'entrée, calcul local des recommandations par correspondance avec les JSON (offres, entreprises, savoir-faire, articles). La fonction `computeResults()` est le point de branchement prévu pour une future API de recommandation ou d'IA. Réponses en sessionStorage uniquement. |

## Fonctionnalités déjà simulées (fonctionnelles dans le navigateur)

- Recherche et filtres d'offres (mot-clé, lieu, métier, contrat, salaire, date, télétravail), tri et pagination ;
- fiche offre complète avec formulaire de candidature accessible (modale `<dialog>`) ;
- annuaire d'entreprises avec recherche et fiche détaillée ;
- publication d'offre en 6 étapes avec aperçu — l'offre publiée apparaît réellement dans la liste et le tableau de bord (stockage local) ;
- tableau de bord : statistiques, désactivation / réactivation d'offres ;
- renouvellement à 10 € avec paiement simulé qui réactive l'offre pour 60 jours ;
- blog complet, chatbot, formulaires de contact et de connexion ;
- espace « Savoir-faire & Avis » : notation multi-critères (moyennes, répartition 1–5 étoiles), commentaires et retours d'expérience, réponses du professionnel, signalement, publication en 6 étapes, section « Ses conseils et savoir-faire » sur les fiches entreprises. Trois types d'avis clairement séparés : note de la publication, avis sur le professionnel (via sa fiche), commentaires.

## Ce qui nécessitera WordPress ou une API

| Sujet | Piste d'intégration |
| --- | --- |
| Offres, entreprises, articles | Custom Post Types (`offre`, `entreprise`) + articles natifs, exposés via l'API REST (`APP_CONFIG.api`) |
| Candidatures | Endpoint custom `supersecretaire/v1/candidatures` + notification e-mail |
| Paiement du renouvellement | Stripe (Payment Intent) ou WooCommerce — la page `paiement.html` en reprend déjà la structure |
| Comptes entreprises | Utilisateurs WordPress avec rôle dédié `entreprise` |
| Chatbot | API d'IA branchée dans `chatbot.js` (fonction `getAnswer`) |
| Savoir-faire | CPT `savoir-faire` + taxonomies `metier`, `categorie`, `difficulte` ; champs personnalisés répéteurs pour les étapes (titre, texte, image, conseil) ; notation via table dédiée ou plugin ; commentaires WordPress personnalisés (note, « méthode testée », réponse du pro) ; statuts de modération : brouillon / en attente / publié / refusé / signalé / archivé |
| Adresses / villes | API Adresse (adresse.data.gouv.fr) pour l'autocomplétion |
| E-mails | Service transactionnel (Brevo, Mailjet…) |

## Recommandations pour la conversion en thème WordPress

- **Header** : composant UNIQUE, deux variantes visuelles.
  - Source de vérité : `partials/header.html` (ne jamais éditer les `<header>` des pages à la main).
  - Synchronisation : `node tools/sync-header.mjs` réécrit le header de toutes les pages,
    choisit la variante (`site-header--overlay` sur l'accueil, posée sur la vidéo du hero ;
    `site-header--light` partout ailleurs) et pose `aria-current="page"` sur le lien actif.
  - En WordPress : ce fragment devient `header.php` ; la variante se choisit via `body_class()`
    (ex. `is_front_page() ? 'site-header--overlay' : 'site-header--light'`), le lien actif via le menu WP.
  - Exception assumée : `404.html` (page GitHub Pages servie sur des chemins arbitraires,
    liens absolus uniquement) n'embarque pas la navbar.
- **Footer** : identique sur toutes les pages → `footer.php` direct.
- `index.html` → `front-page.php` ; chaque section (hero, offres récentes, catégories…) devient un bloc ou un template part réutilisable.
- `offres.html` → `archive-offre.php` (CPT `offre`), `offre-detail.html` → `single-offre.php` ;
  `entreprises.html` → `archive-entreprise.php`, `entreprise-detail.html` → `single-entreprise.php` ;
  `blog.html` → `home.php`, `article.html` → `single.php`.
- Les champs des fichiers JSON (`offers.json`, `companies.json`) correspondent aux **champs personnalisés** à créer (ACF ou meta natives) : contrat, salaire, télétravail, date d'expiration, etc.
- Les gabarits de cartes JavaScript (`SS.offerCard`, cartes entreprise/article) se traduisent en template parts PHP (`template-parts/card-offre.php`…).
- Les catégories de métiers et secteurs deviennent des **taxonomies**.
- Le statut des offres (active / expirée / désactivée) devient un statut de publication ou une meta, géré par le back-office.
- `assets/css` et `assets/js` s'enfilent tels quels via `wp_enqueue_style` / `wp_enqueue_script` ; seuls les appels `fetch()` de données locales sont à remplacer par l'API REST ou par du rendu PHP.

## Limites connues du prototype

- Les données modifiées (offres publiées, renouvellements, session) ne sont conservées que dans le navigateur (localStorage).
- Le nombre de candidatures est illustratif (calculé de façon stable à partir de l'identifiant de l'offre).
- Le paiement, l'envoi d'e-mails, la connexion et le dépôt de CV sont simulés.
- La pagination des offres est purement visuelle côté client (adaptée à un volume de démonstration).

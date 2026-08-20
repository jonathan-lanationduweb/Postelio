# Postelio — prototype

**Postelio** est une plateforme française de l'emploi **multi-secteurs** (commerce, informatique, BTP, santé, logistique, comptabilité, hôtellerie, artisanat… et une part d'administratif). Slogan : _« L'emploi qui vous correspond. »_ Le prototype réunit :

- un moteur de recherche d'offres (filtres, tri, pagination) ;
- un annuaire d'entreprises avec fiches détaillées ;
- une plateforme **biface candidat / recruteur** : chacun son compte, son tableau de bord et son suivi ;
- un blog de conseils emploi ;
- un espace communautaire **« Savoir-faire & Avis »** (méthodes de métier notées par les visiteurs) ;
- une **« Recherche guidée »** (assistant une-question-par-écran) ;
- un **chatbot** présent sur toutes les pages, avec saisie libre et analyse d'intention ;
- une **intro cinématique** pilotée par le scroll sur l'accueil.

> ⚠️ **Prototype de démonstration** : toutes les offres, entreprises, coordonnées et personnes sont fictives. Aucun paiement réel, aucune donnée bancaire, aucune clé d'API. Les sessions et données sont conservées dans le navigateur (localStorage), sans mot de passe stocké.

## Les deux faces de la plateforme

Sans compte, on peut **chercher et lire** librement toutes les offres et fiches. Pour **postuler et suivre** ses candidatures, on crée un **compte candidat gratuit**. Les recruteurs disposent d'un espace pour publier et gérer leurs offres.

| | Candidat (gratuit) | Recruteur |
| --- | --- | --- |
| Espace | `espace-candidat.html` | `espace-entreprise.html` |
| Contenu | candidatures + statuts + timeline, recommandations, favoris, alertes, profil, messages | tableau de bord, offres, candidatures reçues, profil entreprise, messages, facturation |
| Modèle éco. | 100 % gratuit | renouvellement d'offre **10 € / 30 jours** (pas d'abonnement) |

**Comptes de démonstration** (bouton « connexion démo » sur `connexion.html`, définis dans `config.js > demoAccounts`) :
- Candidat — **Jonathan Davy** (JD)
- Recruteur — **Claire Martin**, Fiduciaire Bellecour (CM)

**Suivi de candidature** — vocabulaire de statuts unifié : Candidature envoyée → Vue par l'entreprise → Présélection → Entretien proposé → Entretien réalisé → Offre reçue → Candidature non retenue → Candidature retirée. Lorsqu'un recruteur refuse une candidature, il choisit un **motif interne** (jamais transmis) et un **message courtois** (prédéfini ou édité) est envoyé au candidat, qui le retrouve dans son espace derrière « Voir le message », avec une notification.

## Direction artistique

- Palette « **vert sapin & miel** » : vert profond `#1e4f46` / vert foncé `#14372f`, crème `#faf7f1`, beige `#f3efe5`, orange-doré `#e8a33d`.
- **Intro cinématique** (accueil) : vidéo plein écran dont la lecture est pilotée par le scroll (GSAP ScrollTrigger, `assets/js/scroll-video.js`), moments narratifs, navbar unique qui passe de « transparente » à « pleine » ; `prefers-reduced-motion` respecté.
- Typographie : **serif** (Georgia) réservée aux grands titres ; interface en sans-serif.
- Rayons de bordure sobres, boutons rectangulaires, étiquettes typographiques plutôt que pilules, filets fins, ombres discrètes.
- **Icônes** : une seule famille SVG « line » (`stroke=currentColor`, `viewBox 0 0 24 24`), classe `.icon` / `.icon--lg` dans `components.css` — pas d'emoji utilisés comme icônes.
- Photographies locales (Pexels, WebP, crédits dans `assets/images/photos/credits.md`).
- Micro-interactions légères.

## Lancer le prototype

Les pages chargent leurs données via `fetch()` sur les fichiers JSON : il faut donc **un petit serveur local** (l'ouverture directe d'un fichier HTML par double-clic bloque le chargement des données).

Depuis le dossier `postelio` :

```bash
# Avec Node.js
npx http-server -p 8123 -c-1

# Ou avec Python
python -m http.server 8123
```

Puis ouvrez `http://localhost:8123` dans votre navigateur.

## Organisation du projet

```text
postelio/
├── index.html               Accueil : intro cinématique + recherche, offres, catégories, entreprises, articles
├── offres.html              Recherche d'offres : filtres, tri, pagination
├── offre-detail.html        Fiche offre + candidature (modale) + offres similaires
├── entreprises.html         Annuaire des entreprises (recherche, filtres)
├── entreprise-detail.html   Fiche entreprise + ses offres
├── connexion.html           Connexion (rôle candidat/recruteur en boutons segmentés) + comptes démo
├── inscription.html         Création de compte : choix du rôle puis formulaire candidat ou recruteur
├── espace-candidat.html     Espace candidat (monopage : rubriques par ancre, tiroir mobile)
├── espace-entreprise.html   Espace recruteur — tableau de bord (vue d'ensemble)
├── espace-entreprise-*.html Espace recruteur — pages dédiées : offres, candidatures,
│                            entretiens, messages, profil, contenus, facturation, parametres
├── publier-offre.html       Formulaire de publication en 6 étapes (réservé aux recruteurs connectés)
├── paiement.html            Paiement simulé du renouvellement (10 € / 30 jours)
├── recherche-guidee.html    Recherche guidée en pleine page (le quiz s'ouvre aussi en panneau ailleurs)
├── savoir-faire.html        Espace « Savoir-faire & Avis » : recherche, filtres, tris
├── savoir-faire-detail.html Fiche savoir-faire : étapes, notation 5 étoiles, commentaires, signalement
├── publier-savoir-faire.html Publication d'un savoir-faire en 6 étapes (étapes dynamiques)
├── blog.html                Liste des articles (catégories + recherche)
├── article.html             Article détaillé + articles associés
├── a-propos.html            Présentation de la plateforme
├── contact.html             Formulaire de contact (simulé)
├── mentions-legales.html · confidentialite.html   Pages légales
│
├── partials/
│   └── header.html          Source de vérité de la navbar (synchronisée par tools/sync-header.mjs)
├── tools/
│   └── sync-header.mjs       Réécrit la navbar sur toutes les pages (variante cine/light + lien actif)
│
├── assets/
│   ├── css/                 reset, variables, global, components, home, cine, offers,
│   │                        companies, knowhow, blog, dashboard, guided-search, responsive
│   ├── js/                  Modules par fonctionnalité (voir ci-dessous)
│   ├── images/              Photos (photos/) + poster de l'intro + illustrations
│   ├── videos/              Vidéo de l'intro cinématique
│   └── icons/               Favicon SVG + icônes PWA
│
└── data/
    ├── offers.json          26 offres fictives sur 11 familles de métiers
    ├── companies.json       12 entreprises fictives
    ├── articles.json        13 articles de blog en français
    ├── savoir-faire.json    Savoir-faire de métiers variés
    └── guided-search.json   Scénario de la recherche guidée
```

### Rôle des fichiers JavaScript

| Fichier | Rôle |
| --- | --- |
| `config.js` | **Configuration centrale** : chemins des données, futures URL d'API, tarif du renouvellement (10 € / 30 j), clés de stockage local, comptes de démonstration. Seul fichier à modifier pour brancher de vraies API. |
| `main.js` | Utilitaires partagés (`SS.*`) : chargement JSON avec cache, échappement HTML, dates FR, stockage local, modales, toasts, animation d'apparition, et **`SS.auth`** (session : `get/set/clear`, `isCandidate/isEmployer`, `initials()`, `displayName()`, `logout()`, garde d'accès `require(role)`). |
| `navigation.js` | Menu mobile, lien actif, et **zone compte** : visiteur → « Se connecter / Créer un compte » ; connecté → avatar à initiales + menu déroulant par rôle (accessible). |
| `scroll-video.js` | Intro cinématique : lecture de la vidéo pilotée par le scroll (GSAP ScrollTrigger, interpolation rAF). |
| `search.js` | Moteur de recherche de l'accueil (redirige vers `offres.html`). |
| `offers.js` | Gabarit de carte d'offre, offres récentes, fiche offre, candidature, partage, offres similaires, JSON-LD `JobPosting`. |
| `filters.js` | Filtres, tri, compteur et pagination de la page offres. |
| `companies.js` | Annuaire, entreprises mises en avant, fiche entreprise. |
| `blog.js` | Blog : liste, catégories, recherche, article, articles associés, newsletter simulée, JSON-LD `Article`. |
| `dash-shell.js` | Coquille de l'espace **candidat** (monopage) : routage par ancre (une rubrique visible à la fois), tiroir mobile, footer compact. |
| `dashboard-candidat.js` | Espace **candidat** : candidatures + statuts + timeline, message reçu (« Voir le message »), notification, recommandations, favoris, alertes, profil professionnel + savoir-faire. Garde `require("candidate")`. Seed de démo versionné. |
| `employer-shell.js` | Coquille de l'espace **recruteur** (multi-pages) : garde `require("employer")`, barre latérale groupée + état actif par page, tiroir mobile, footer compact, redirections des anciennes URLs `espace-entreprise.html#xxx`, helpers partagés `window.EMP`. |
| `employer-*.js` | Modules par page recruteur : `-dashboard` (indicateurs + à-faire), `-offers`, `-candidates` (pipeline + **modale de refus** courtoise), `-interviews`, `-messages`, `-profile`, `-content`, `-billing`, `-settings`. |
| `publish.js` | Publication d'offre en 6 étapes (validation, aperçu, enregistrement local). Réservé aux recruteurs. |
| `payment.js` | Paiement **simulé** du renouvellement : met à jour le statut de l'offre. |
| `auth.js` | Connexion / inscription : choix du rôle, comptes démo, création de session `SS.auth`, redirection vers l'espace. |
| `contact.js` | Formulaire de contact simulé. |
| `chatbot.js` | Chatbot « Clémence » injecté sur toutes les pages : raccourcis rapides **et saisie libre** avec analyse d'intention (emploi, suivi de candidature, renouvellement, recrutement, recherche guidée, contact). Prêt pour une API d'IA. |
| `knowhow.js` · `knowhow-publish.js` | Savoir-faire & Avis : liste/filtres/tris, fiche, notation multi-critères, commentaires, signalement ; publication en 6 étapes. |
| `guided-search.js` | Recherche guidée : moteur du quiz + panneau réutilisable ; `computeResults()` = point de branchement d'une future API de reco. |

## Fonctionnalités simulées (fonctionnelles dans le navigateur)

- Recherche, filtres, tri et pagination d'offres ; fiche offre + candidature (modale `<dialog>`) ;
- annuaire d'entreprises + fiches détaillées ;
- **comptes candidat & recruteur** avec sessions persistantes, avatar et menu par rôle sur toutes les pages ;
- **espace candidat** : suivi des candidatures (statuts + timeline), réception d'un message courtois après refus, notifications, favoris, alertes, profil ;
- **espace recruteur** : indicateurs, gestion des offres, **modale de refus** (motif interne → message courtois), facturation, renouvellement 10 € / 30 jours (paiement simulé) ;
- publication d'offre en 6 étapes (l'offre apparaît réellement dans la liste et le tableau de bord) ;
- blog, chatbot à saisie libre, formulaires de contact et de connexion ;
- espace « Savoir-faire & Avis » (notation multi-critères, commentaires, signalement, publication).

## Ce qui nécessitera WordPress ou une API

| Sujet | Piste d'intégration |
| --- | --- |
| Offres, entreprises, articles | Custom Post Types (`offre`, `entreprise`) + articles natifs, via l'API REST (`APP_CONFIG.api`) |
| Comptes candidat / recruteur | Utilisateurs WordPress avec rôles dédiés ; profils et préférences en meta |
| Candidatures & suivi | Endpoint custom + table de statuts ; notifications e-mail ; messagerie |
| Paiement du renouvellement | Stripe (Payment Intent) ou WooCommerce — `paiement.html` en reprend la structure |
| Chatbot | API d'IA branchée dans `chatbot.js` (détection d'intention → réponses) |
| Savoir-faire | CPT `savoir-faire` + taxonomies `metier`/`categorie`/`difficulte` ; notation ; modération |
| Adresses / villes | API Adresse (adresse.data.gouv.fr) pour l'autocomplétion |
| E-mails | Service transactionnel (Brevo, Mailjet…) |

## Recommandations pour la conversion en thème WordPress

- **Header** : composant UNIQUE, deux variantes (`site-header--cine` sur l'accueil, `site-header--light` ailleurs).
  - Source de vérité : `partials/header.html` (ne jamais éditer les `<header>` à la main).
  - Synchronisation : `node tools/sync-header.mjs` réécrit le header de toutes les pages et pose `aria-current`.
  - En WordPress : ce fragment devient `header.php` ; la variante via `body_class()`, le lien actif via le menu WP.
  - Exception : `404.html` (page GitHub Pages, liens absolus) n'embarque pas la navbar.
- **Footer** : identique partout → `footer.php`.
- `index.html` → `front-page.php` ; sections en blocs / template parts.
- `offres.html` → `archive-offre.php`, `offre-detail.html` → `single-offre.php` ; idem entreprises ; `blog.html` → `home.php`, `article.html` → `single.php`.
- Les champs des JSON (`offers.json`, `companies.json`) correspondent aux **champs personnalisés** (ACF ou meta).
- Les gabarits de cartes JS (`SS.offerCard`…) se traduisent en template parts PHP.
- Familles de métiers et secteurs → **taxonomies** ; statut des offres → statut de publication ou meta.
- `assets/css` et `assets/js` s'enfilent tels quels (`wp_enqueue_*`) ; seuls les `fetch()` locaux passent à l'API REST ou au rendu PHP.

## Limites connues du prototype

- Les données (offres publiées, candidatures, renouvellements, session) ne sont conservées que dans le navigateur (localStorage) ; aucun mot de passe n'y est stocké.
- Le nombre de candidatures reçues et certains indicateurs sont illustratifs (valeurs de démonstration).
- Le paiement, l'envoi d'e-mails, la connexion, la messagerie et le dépôt de CV sont simulés.
- La pagination des offres est côté client (volume de démonstration).

## Déploiement

Dépôt : `https://github.com/jonathan-lanationduweb/Postelio` — publié sur GitHub Pages : `https://jonathan-lanationduweb.github.io/Postelio/` (branche `main`). Après un `git push`, le build Pages se termine en ~1 min, puis le cache CDN se propage sur quelques minutes.

# Postelio — Back-office unifié (`postelio-backoffice`)

Plugin **propriétaire de TOUTE l'expérience wp-admin Postelio** : menu, design system, écrans,
actions, assets, aperçus. Les plugins métier restent propriétaires de leurs données et règles ;
`postelio-site` reste propriétaire du schéma, de la config, de la validation, du stockage, du REST,
de l'identité (logo / favicon) et de la configuration du front. Le back-office **ne duplique rien** :
il lit et écrit via les contrats publics et le REST.

**Phase 1 (validée)** : squelette, architecture UI, menu, design system, Tableau de bord, Mon site /
Vue d'ensemble, Mon site / Accueil (éditeur avec vrai front en aperçu), mécanisme de compatibilité
avec `postelio-admin` (legacy). **Phase 2 (livrée, §7)** : toute la zone Mon site. Les écrans métier
et système restent rendus par le legacy.

---

## 1. Audit du legacy (`postelio-admin` + `postelio-site`)

| Existant | Verdict | Détail |
|---|---|---|
| `Menu.php` (menu unique, groupes CSS `::before`, écrans masqués routables, `simplify_wp_menu`) | **À REFAIRE** (refait) | Logique reprise dans `Backoffice\Menu` : mêmes slugs, mêmes groupes, routage migré/legacy. Le legacy ne construit plus le menu quand le back-office est actif. |
| `Pages\Page` (garde capability, `.pst-admin`, flash) | **À REFAIRE** (refait) | `Screens\Screen` : même contrat, enveloppe `.pst-bo`, mêmes codes de notice (compat actions legacy). |
| `Support\Ui` (helpers HTML) | **À REFAIRE** (refait) | `Ui\Ui` : composants `bo-*` (page_header, tabs, card, stat, row, badge, alert, empty, table, kv, entity, button, action_button, details). Zéro style inline. |
| `Support\Metrics`, `Support\Health`, `Support\Contracts` | **À RÉUTILISER** (transitoire) | Agrégateurs de données éprouvés (contrats/REST/WP natif, aucun SQL cross-domaine). Réutilisés derrière la façade `Support\Data` avec `class_exists` ; à rapatrier quand le legacy sera retiré. |
| `Actions\Actions` (admin-post, nonce + capability + délégation) | **À RÉUTILISER** | Restent enregistrées par le legacy : les écrans legacy ET les futurs écrans migrés les appellent (`Ui::action_button` produit le même formulaire). Migration vers le back-office prévue avec les écrans métier. |
| `DashboardPage` | **À REFAIRE** (refait) | Fonctionnalité conservée (KPI réels, « À traiter » réel, raccourcis, santé compacte), markup/CSS refaits (`DashboardScreen`). |
| `Site\PagesHubPage` (cartes) | **À REFAIRE** (refait) | `SiteHubScreen` : lignes de pilotage (nom, résumé, sections, SEO, Modifier/SEO/Voir), Structure, Identité. Plus de grosses cartes décoratives. |
| `Site\SiteEditorPage` + `site-editor.js` + `site-editor.css` | **À REFAIRE** (refait pour Accueil) | Coque `SiteEditorScreen` + moteur `site-builder.js` (mêmes mécaniques : schéma, iframe `?postelio_preview=1`, postMessage, preview-ready, non-enregistré, Desktop/Tablette/Mobile ou appareil imposé, `target`, média, save/reload, `show_if`, `identity_hint`). Le moteur est générique : migrer Navigation/Footer/Apparence/SEO/pages = ajouter le slug à `Menu::MIGRATED`. |
| `Site\SiteMenu`, `Site\SiteNav` | **À REFAIRE** (refait) | `Menu::SITE_PAGES / site_slug / site_page_for_slug` + `Screens\Site\SiteNav` (onglets `bo-tabs`). |
| `assets/admin.css`, `site-editor.css` (cascade de correctifs) | **À SUPPRIMER PLUS TARD** | Remplacés par `backoffice.css` (base unique) + `site-builder.css` (éditeur uniquement). Restent chargés sur les écrans legacy non migrés. |
| `assets/js/site-preview-bridge.js` (front) | **À RÉUTILISER (inchangé)** | Le bridge du front est le contrat d'aperçu ; le nouveau moteur envoie exactement les mêmes messages. |
| Pages métier (Users, Companies, Jobs, Applications, Messaging, Interviews, Skills, Moderation, Billing, Sources, Settings, Notifications, Files, Health, Alerts) | **À MIGRER (Phase 2+)** | Rendues par le legacy via `Legacy::render()` en attendant. |
| `postelio-site` (schéma, service, repo, REST, identité, favicon) | **À RÉUTILISER (inchangé)** | Source de vérité ; aucune modification. |

## 2. Architecture

```
plugins/postelio-backoffice/
  postelio-backoffice.php      bootstrap, constantes, autoloader core, filtres de compat (à l'inclusion)
  src/Plugin.php               boot (admin uniquement) : Menu + Assets + Legacy
  src/Menu.php                 menu unique, slugs conservés, MIGRATED, routage migré → Screen / sinon Legacy
  src/Legacy.php               pont vers postelio-admin : rendu des écrans non migrés, état « en migration »
  src/Assets.php               CSS/JS uniquement sur les écrans migrés ; PST_BO_SITE ; body class
  src/Ui/Ui.php                design system serveur (composants bo-*)
  src/Screens/Screen.php       base (capability, enveloppe, flash)
  src/Screens/DashboardScreen.php
  src/Screens/Site/SiteNav.php, SiteHubScreen.php, SiteEditorScreen.php
  src/Support/Data.php         façade données (contrats métier ; legacy Metrics/Health réutilisés, transitoire)
  assets/css/backoffice.css    base unique (tokens, page, header, tabs, cards, stats, rows, badges…)
  assets/css/site-builder.css  éditeur (cartes de section, champs, média, repeater, aperçu, save bar)
  assets/js/backoffice.js      confirmations
  assets/js/site-builder.js    moteur d'édition (schéma → UI, aperçu vrai front, save)
```

- **Aucune table, aucune migration, aucune écriture directe** dans une table d'un autre plugin.
- **Assets** : chargés seulement si `$_GET['page']` ∈ `Menu::MIGRATED` ; version = `POSTELIO_BACKOFFICE_VERSION`
  (cache-buster unique, propagé à l'iframe d'aperçu via `&v=`).
- **Menu** : Tableau de bord · Mon site · ACTIVITÉ (Utilisateurs, Entreprises, Offres, Candidatures,
  Messagerie, Entretiens, Savoir-faire) · GESTION (Modération, Facturation, Sources d'offres) ·
  RÉGLAGES (Réglages). Notifications / Fichiers / Santé / Alertes / éditeurs de pages : routables,
  masqués du menu (accès via Réglages, Mon site et URL directe).

## 3. Stratégie legacy (aucun fichier supprimé)

- `postelio-backoffice.php` déclare **à l'inclusion** : `postelio/admin/legacy_menu` → false et
  `postelio/admin/legacy_assets` → false pour les slugs migrés. `postelio-admin` (boot à
  `plugins_loaded:60`) lit ces filtres : il **ne construit plus le menu** mais garde ses **actions
  admin-post** et son **enqueue** pour les écrans non migrés.
- `Menu::route()` : slug migré → `Screen` ; sinon `Legacy::render()` (instancie la page legacy) ;
  si le legacy est désactivé → écran « en cours de migration » (jamais de fatal).
- Un seul menu « Postelio » (vérifié : 1 entrée de premier niveau, 27 sous-menus, 0 doublon de slug).
- Désactiver `postelio-backoffice` = retour instantané au legacy complet (menu + écrans).

## 4. Design system

Tokens (`.pst-bo`) : bleu nuit `#17324D` (structure, actions principales), corail `#FF6B6B`
(accent : surtitre, indicateurs « à traiter », bouton d'enregistrement de la save bar), fond chaud
`#F6F4EF`, surfaces blanches, texte `#1B2A3A`, bordures `#E4E7EC`. L'essentiel reste clair.
Interdit : `style=""` dans les écrans (vérifié : 0 sur les trois écrans migrés).

## 5. Écrans Phase 1

- **Tableau de bord** : en-tête (titre, santé en badge, Modifier le site / Voir le site) ; 6
  indicateurs ; « À traiter » (lignes réelles : entreprises à vérifier, modération, paiements en échec,
  e-mails en échec, offres qui expirent — chacune sous capability) ; raccourcis ; ligne santé.
- **Mon site / Vue d'ensemble** : onglets Vue d'ensemble · Accueil · Navigation · Footer · Apparence ·
  SEO ; lignes de pages (nom, résumé, sections actives, état SEO, Modifier / SEO / Voir) ; Structure
  (Navigation, Footer) ; Identité (nom, logo, favicon → Apparence).
- **Mon site / Accueil** : gauche = sections (repliées : titre + résumé compact ; ouvertes : champs sur
  grille 2 colonnes, repeaters en lignes compactes → édition inline, médias vignette + nom + poids +
  Remplacer / Retirer / Défaut) ; droite = VRAI FRONT en iframe sticky, Desktop / Tablette / Mobile,
  vidéo hero administrable, save bar. Compatible avec `preview_target` / `preview_device` (Footer),
  identité globale, `show_if`, `brand_text` fallback (cfc5e98).

## 6. Pages restant à migrer (Phase 2+)

Navigation, Footer, Apparence, SEO, éditeurs Offres / Entreprises / Savoir-faire / Conseils / Contact
(moteur prêt : ajouter les slugs à `Menu::MIGRATED`), puis Utilisateurs, Entreprises, Offres,
Candidatures, Messagerie, Entretiens, Savoir-faire, Modération, Facturation, Sources, Réglages,
Notifications, CV & fichiers, Santé, Favoris & Alertes, et le rapatriement des Actions / Metrics /
Health / Contracts. Ensuite seulement : retrait de `postelio-admin`.


---

## 7. Phase 2 — zone « Mon site » complète (septembre 2026)

`Menu::MIGRATED` couvre désormais toute la zone Mon site : Vue d'ensemble, Accueil, Navigation,
Footer, Apparence, SEO, Offres, Entreprises, Savoir-faire, Conseils, Contact. Ces écrans passent
tous par `SiteEditorScreen` + `site-builder.js` (UN moteur, pas dix implémentations) ; leurs assets
legacy (`admin.css`, `site-editor.css`, `site-editor.js`) ne sont plus chargés (filtre
`postelio/admin/legacy_assets`). Slugs inchangés (favoris intacts) ; back-office désactivé = retour
au legacy.

- **Navigation** : Marque (logo global / override conditionnel / nom de marque override, rappel
  d'identité), Liens (repeater compact : « Offres · /offres · Tout le monde », clic → édition inline
  libellé / URL / visibilité), Boutons (toggle + libellé + URL). Schéma : `preview_target = header`
  → le bridge cale l'aperçu sur l'en-tête réel ; Desktop / Tablette / Mobile libres.
- **Footer** : exactement le comportement de cfc5e98 (`preview_target = footer`,
  `preview_device = mobile`, sections Marque / Colonnes / Réseaux / Mentions / Réglages, logo
  global avec override conditionnel, `brand_text` override vide → nom global, description propre).
- **Apparence** : Identité (nom, logo, logo clair, favicon 16/32 px + nom + Choisir / Remplacer /
  Retirer / Défaut, image sociale), Couleurs (picker + hex, palette par défaut #17324D / #FF6B6B /
  #FAF7F1 / #17324D), Typographie (selects du schéma), Boutons (arrondi, style). L'aperçu est le vrai
  front : le bridge applique couleurs, typographie (feuille d'aperçu injectée à partir de valeurs
  fermées) et boutons. Pas de « brand board ». Upload SVG non activé (Safe SVG requis).
- **SEO** : Global (nom du site, template de titre, meta description, image sociale) + une carte par
  page (titre SEO, meta description, titre / description sociaux, image, noindex), une seule carte
  ouverte à la fois, compteurs indicatifs 60 / 155, aperçu SERP + Open Graph = composant éditorial
  (« ne reflète ni la position ni l'indexation réelle »), sélecteur d'appareil masqué.
- **Offres / Entreprises / Savoir-faire / Conseils / Contact** : sections du `SiteSchema` (rien
  d'inventé), aperçu = vraie page correspondante (`offres.html`, `entreprises.html`,
  `savoir-faire.html`, `blog.html`, `contact.html` + `?postelio_preview=1`), collections auto /
  manuel via `/site/admin/search` et `/site/admin/resolve` (« Contenu indisponible » si la référence
  a disparu), note « Posts WordPress » (Conseils) et note « aucun backend d'envoi » (Contact)
  affichées.
- **Composants mutualisés** : un seul champ média (vignette / nom / poids / warning > 15 Mo /
  actions), un seul repeater, un seul sélecteur de contenu, une seule save bar, un seul état d'erreur
  d'aperçu (« Chargement… » / « Impossible de charger l'aperçu » + Réessayer).

Reste pour la Phase 3 : les écrans métier et système (Utilisateurs, Entreprises, Offres,
Candidatures, Messagerie, Entretiens, Savoir-faire, Modération, Facturation, Sources, Réglages,
Notifications, CV & fichiers, Santé, Favoris & Alertes), le rapatriement des Actions / Metrics /
Health / Contracts, puis le retrait de `postelio-admin` et de ses assets.

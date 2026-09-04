# Postelio — Back-office unifié (`postelio-backoffice`)

Plugin **propriétaire de TOUTE l'expérience wp-admin Postelio** : menu, design system, écrans,
actions, assets, aperçus. Les plugins métier restent propriétaires de leurs données et règles ;
`postelio-site` reste propriétaire du schéma, de la config, de la validation, du stockage, du REST,
de l'identité (logo / favicon) et de la configuration du front. Le back-office **ne duplique rien** :
il lit et écrit via les contrats publics et le REST.

**État : migration terminée.** Phase 1 (§1-6) fondation, tableau de bord et « Mon site » ; Phase 2
(§7) toute la zone Mon site ; **Phase 3 (§8) écrans métier et système, puis retrait du plugin
historique `postelio-admin`, supprimé du dépôt**. Les sections 1 à 3 décrivent l'audit et la
stratégie de transition, conservés comme historique : le mécanisme de compatibilité (`Legacy.php`,
filtres `postelio/admin/legacy_*`) n'existe plus.

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

Structure finale (après Phase 3) :

```
plugins/postelio-backoffice/
  postelio-backoffice.php      bootstrap, constantes, autoloader du core
  src/Plugin.php               boot : Actions (toujours) + Menu et Assets (écrans d'admin)
  src/Menu.php                 menu unique, slugs conservés, table SCREENS = routage de tous les écrans
  src/Assets.php               CSS/JS uniquement sur les écrans Postelio ; PST_BO_SITE ; body class
  src/Actions/Actions.php      admin-post : nonce → capacité → validation → délégation au domaine
  src/Ui/Ui.php                design system serveur (composants bo-*)
  src/Screens/Screen.php       base (capacité, enveloppe, notices flash, pagination)
  src/Screens/ListScreen.php   squelette commun des écrans de liste (onglets, pagination, détail)
  src/Screens/*.php            Dashboard, Users, Companies, Jobs, Applications, Interviews,
                               Messaging, Skills, Moderation, Billing, Sources, Settings,
                               Notifications, Files, Health, Alerts
  src/Screens/Site/*.php       SiteNav, SiteHubScreen, SiteEditorScreen
  src/Support/Rest.php         appel REST interne (rest_do_request) au nom de l'utilisateur courant
  src/Support/Health.php       agrégation santé socle + /health des modules
  src/Support/Data.php         façade de lecture (compteurs, modules, santé) via les *AdminDirectory
  src/Support/Fmt.php          formatage (dates UTC → site, tailles, montants, références, extraits)
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

## 3. Stratégie legacy (historique — dispositif retiré en Phase 3)

> Ce mécanisme de transition n'existe plus : `postelio-admin` est supprimé et les filtres
> `postelio/admin/legacy_*` ont disparu du bootstrap. Conservé ici pour mémoire.

- `postelio-backoffice.php` déclarait **à l'inclusion** : `postelio/admin/legacy_menu` → false et
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


---

## 8. Phase 3 — migration métier + système, retrait du legacy (septembre 2026)

Tous les écrans wp-admin de Postelio sont désormais rendus par `postelio-backoffice`. Le plugin
historique `postelio-admin` a été **supprimé** du dépôt : plus aucune dépendance runtime.

### 8.1 Matrice de migration (14 écrans)

| Écran | Données lues (façade / API) | Actions | Capacité | Données sensibles |
|---|---|---|---|---|
| Utilisateurs | `WP_User_Query` + `Users\Api\UserDirectory`, `Users\Users\AccountService`, `CandidateProfileRepository`, `CompanyDirectory`, `InterviewDirectory`, `NotificationDirectory`, `SkillDirectory` | suspendre / réactiver (`UserModeration`) | `pst_manage_platform`, `pst_suspend_account` | e-mail affiché à l'admin uniquement |
| Entreprises | `Companies\Api\CompanyAdminDirectory` | vérifier / rejeter (`VerificationService`), suspendre / réactiver (`CompanyModeration`) | `pst_manage_platform`, `pst_verify_company`, `pst_suspend_company` | motif interne réservé à `pst_verify_company` |
| Offres | `Jobs\Api\JobAdminDirectory`, `Jobs\Api\JobDirectory::external` | suspendre / réactiver (`JobModeration`), masquer / restaurer externe (`JobSourcesModeration`) | `pst_manage_all_jobs`, `pst_moderate_content` | — |
| Candidatures | `Applications\Api\ApplicationAdminDirectory` | aucune (workflow = entreprise) | `pst_manage_platform` | **notes recruteur jamais exposées**, CV = référence seule |
| Entretiens | `Interviews\Api\InterviewAdminDirectory` | aucune | `pst_manage_platform` | **coordonnées demandées au contrat seulement si capacité**, jamais en liste |
| Messagerie | `Messaging\Api\MessagingAdminDirectory` | aucune | `pst_manage_platform` | **corps des messages jamais affiché** |
| Savoir-faire | `Skills\Api\SkillAdminDirectory` | masquer / restaurer (`SkillModeration`) | `pst_moderate_content` | — |
| Modération | REST `/moderation/cases` | assigner, traiter, classer, escalader, avertir, masquer, fermer, suspendre, note | `pst_view_moderation_queue`, `pst_decide_report`, `pst_moderate_content` | — |
| Facturation | REST `/billing/health`, `/billing/admin/orders` | relancer le traitement | `pst_manage_billing` | **aucun secret Stripe** |
| Sources d'offres | REST `/job-sources/health` | aucune (aucun contrat de synchronisation manuelle) | `pst_manage_platform` | **aucune clé ni variable d'environnement** |
| Réglages | options WP, Registry, REST `/health` | aucune | `pst_manage_platform` | **aucun secret** |
| Service e-mail | `Notifications\Api\NotificationDirectory` | e-mail de test | `pst_manage_platform` | **destinataires masqués**, aucun contenu |
| CV & fichiers | `Files\Api\FileAdminDirectory` + `ApplicationAdminDirectory::referenced_cv` | aucune | `pst_manage_platform` | **ni chemin, ni clé de stockage, ni contenu, ni téléchargement** |
| Santé | `Core\Health\Status` + REST `/health` des modules | aucune | `pst_manage_platform` | — |

### 8.2 Couche support autonome (fin de la dépendance transitoire)

`Support\Metrics` / `Health` / `Contracts` du plugin historique ne sont plus utilisés. Le back-office
embarque désormais :

- `Support\Rest` — appel REST interne (`rest_do_request`) au nom de l'utilisateur courant : les
  `permission_callback` et présenteurs des domaines s'appliquent. Seul moyen de lire les domaines
  sans façade PHP (modération, facturation, sources).
- `Support\Health` — agrégation du snapshot du socle + des `/health` de modules, avec cache de
  requête ; libellés et variantes d'affichage.
- `Support\Data` — façade de lecture (compteurs, santé, disponibilité des modules) appelant les
  `*AdminDirectory` derrière `class_exists` ; `facade()` neutralise toute absence de module.
- `Support\Fmt` — dates UTC → fuseau du site, tailles, montants, références courtes, extraits.

Les façades `*AdminDirectory` **restent dans les plugins métier** : c'est volontaire, le back-office
les consomme sans les centraliser.

### 8.3 Actions rapatriées

`Backoffice\Actions\Actions` reprend les **mêmes slugs** `pst_admin_*` (26 actions). Chaque
gestionnaire : nonce → capacité → validation du paramètre (`uuid` borné à `[A-Za-z0-9-]{1,64}`) →
**délégation** au service propriétaire ou à l'endpoint du domaine → notice flash → redirection.
Aucune logique métier, aucune écriture directe.

### 8.4 Structure commune des écrans

`Screens\ListScreen` impose le même squelette à toutes les listes : en-tête → indicateurs éventuels
→ onglets de statut → filtres compacts → table → pagination → état vide. Cellule d'entité homogène
(avatar rond pour les personnes, carré pour entreprises / offres / contenus, initiales en repli) ;
un identifiant technique n'est jamais l'information principale (replié dans « Détails techniques »).

### 8.5 Menu final

Tableau de bord · Mon site · **Activité** (Utilisateurs, Entreprises, Offres, Candidatures,
Messagerie, Entretiens, Savoir-faire) · **Gestion** (Modération avec pastille de dossiers ouverts,
Facturation, Sources d'offres) · **Réglages**. Service e-mail, CV & fichiers, Santé et Favoris &
Alertes restent **routables** (accès depuis Réglages → Système et par URL directe) mais masqués du
menu par CSS — jamais par `remove_submenu_page()`, qui casserait la vérification de capacité.

### 8.6 Retrait de `postelio-admin`

Vérifications avant suppression : aucune référence à `Postelio\Admin\` hors du plugin lui-même ;
aucun écran ne charge `admin.css`, `admin.js`, `site-editor.css` ou `site-editor.js` ; les
gestionnaires `admin_post_*` proviennent bien de `Backoffice\Actions`. Le plugin a été désactivé,
les 27 écrans revérifiés, puis le dossier supprimé (dépôt + jonction locale + entrée
`active_plugins`). Les filtres de compatibilité `postelio/admin/legacy_*`, devenus sans objet, ont
été retirés du bootstrap.

### 8.7 Tests

- 15 suites unitaires vertes ; 14 suites smoke exécutées.
- **Non-régression prouvée** sur les deux suites non vertes : `postelio-job-sources` présente
  exactement les **mêmes 6 échecs** avec et sans le plugin historique (vérifié en restaurant
  `postelio-admin` depuis `develop` dans un worktree) — ils sont **préexistants** et sans rapport
  avec le back-office. `postelio-files` passe (42 vérifications) : son échec initial venait du
  harnais de test local, qui ne chargeait pas `wp-admin/includes/file.php`.
- 27 écrans rendus sans fatal, avec et sans le plugin historique, et avec les modules billing /
  skills / notifications / job-sources désactivés (états « Module indisponible » propres).
- Vues de détail sur ressource inexistante : « Introuvable » sur les 9 écrans concernés.
- Navigateur réel : menu unique (1 entrée, 27 sous-menus, 0 doublon), 9 onglets de Réglages, sweep
  des 11 écrans restants, aucun asset legacy, zéro style inline.
- Responsive 1440 / 1024 / 782 / 390 px : indicateurs 4 → 2 → 1 colonne, tables à défilement
  horizontal, aucun débordement de page.

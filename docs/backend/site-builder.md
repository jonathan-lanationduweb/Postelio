# Postelio — Éditeur visuel du site (« Site Builder »)

> Phase 1. Permet à une personne non technique de configurer le SITE public depuis WordPress, sans
> toucher au code. **Distinct** du back-office de gestion (`postelio-admin`) : deux menus séparés —
> **Postelio** (gestion métier) et **Postelio Site** (éditeur du site).

## 1. Architecture — séparation des responsabilités

| Plugin | Rôle |
|--------|------|
| `postelio-site` (nouveau) | **Source de vérité** de la configuration du site : schéma, stockage (options), REST public + admin, capacité `pst_manage_site`, audit. Aucune logique métier, aucune table. |
| `postelio-admin` (étendu) | **UI d'édition** : menu « Postelio Site », écran éditeur (coque), assets (CSS/JS). Orchestration pure — lit/écrit via les contrats de `postelio-site`. |

Pas de deuxième source de vérité : la config vit dans `postelio-site` (options), l'admin n'est qu'une
interface. Le front public la consommera plus tard via l'API REST publique (non branché en Phase 1).

## 2. Modèle de configuration

- **Schéma** (`Config\SiteSchema`, données pures/testables) : `pages → sections → champs` + valeurs
  par défaut + `VERSION` (= 1, pour migrations futures).
  - Type `sections` (ex. Accueil) : sections activables (`_enabled`) et réordonnables (`_order`).
  - Type `single` (Navigation, Footer, Apparence…) : un groupe de champs.
- **Types de champ** : `text · textarea · toggle · media · color · select · number · repeater`.
- **Stockage** (`Config\SiteConfigRepository`) : **une option par page** (`postelio_site_<page>`) —
  jamais un JSON global monolithique. Les valeurs lues sont fusionnées sur les défauts du schéma
  (tolérant aux ajouts de champs). `postelio_site_config_version` trace la version.
- **Écriture** (`Config\SiteConfigService`) : assainit chaque valeur **selon son type** (jamais de
  HTML arbitraire : `sanitize_text_field` / `sanitize_textarea_field` / `esc_url_raw` /
  `sanitize_hex_color` / select validé / repeater borné à 50), rejette pages/champs inconnus, filtre
  l'ordre des sections, puis persiste et **émet un événement d'audit** `site.<page>.updated`
  (auto-journalisé par l'audit du core, charge utile minimale).

## 3. Pages du builder

| Page | État Phase 1 |
|------|--------------|
| Accueil | **Complète** — Hero, Recherche, Catégories, Offres, Entreprises, Savoir-faire, Arguments, Articles, CTA (activation + réordonnancement). |
| Navigation | **Complète** — logo, marque, liens (repeater + visibilité), boutons Connexion/Inscription. |
| Footer | **Complète** — logo, description, colonnes de liens, réseaux, liens légaux, copyright. |
| Apparence | **Complète** — couleurs (primaire/accent/fond/texte), typographie, style des boutons. |
| Offres, Entreprises, Savoir-faire, Conseils, Contact, SEO | **Préparées** (structure + enregistrement) — badge « Page préparée », complétées ultérieurement. |

## 4. Éditeur (UI)

- **Menu** de premier niveau `postelio-site` (`Site\SiteMenu`), séparé du menu gestion. Capacité
  `pst_manage_site`.
- **Écran** `Site\SiteEditorPage` : coque (en-tête *Voir le site* / *Enregistrer*, éditeur à gauche,
  aperçu à droite, save bar). Le schéma + les valeurs + les endpoints sont injectés via
  `window.PST_SITE` (`wp_localize_script`).
- **Assets** (`assets/site-editor.{css,js}`, chargés uniquement sur les écrans Site) : moteur
  **piloté par le schéma** — cartes de section repliables, switches ON/OFF, champs (texte, zone,
  média via la médiathèque WP, couleur, select, nombre), **repeaters** (ajout/suppression/ordre),
  **réordonnancement** des sections (↑/↓), **sélecteur d'appareil** Desktop/Tablette/Mobile,
  **aperçu fidèle** thémé par l'apparence, **save bar** avec état « non enregistré » / « ✓ enregistré ».
- **Sécurité UI** : les chaînes utilisateur sont insérées dans l'aperçu via `textContent`
  (jamais `innerHTML`) → pas d'injection.

## 5. Aperçu

Aperçu **recréé fidèlement** avec les composants/templates Postelio (thémé par la config d'apparence,
donc les couleurs/typo se reflètent en direct). Il lit l'**état local non enregistré** (pas besoin de
brouillon serveur en Phase 1). Lors du branchement front (chantier séparé), l'aperçu pourra pointer
sur le vrai rendu.

## 6. API REST

- **Publique** (`Http\SiteConfigController`, sans auth — présentation uniquement) :
  - `GET /postelio/v1/site/config` → `{ version, pages }`
  - `GET /postelio/v1/site/config/{page}` → `{ page, values }`
- **Admin** (`Http\SiteAdminController`, `permission_callback = pst_manage_site`, cookie + nonce
  `X-WP-Nonce`) :
  - `GET  /postelio/v1/site/admin/{page}` → `{ schema, values, version }`
  - `POST /postelio/v1/site/admin/{page}` (body `{ values }`) → `{ values }` (validé + audité)

## 7. Permissions

- Capacité **`pst_manage_site`** — accordée dynamiquement (filtre `user_has_cap`) à tout utilisateur
  ayant `pst_manage_platform` (admin Postelio) ou `manage_options` (admin WP). **Réversible**, sans
  mutation des rôles stockés. Modérateur / Support : **pas d'accès**.

## 8. Enregistrer vs Publier (recommandation)

Phase 1 : **enregistrement live** (chaque *Enregistrer* écrit l'option immédiatement) — suffisant
pour une petite équipe et simple. Un cycle **brouillon → publier** pourra être ajouté plus tard
(l'aperçu lisant déjà l'état local non enregistré, la bascule serait peu invasive).

## 9. Audit

Chaque enregistrement émet `site.<page>.updated` (payload minimal : page + auteur) — journalisé par
l'audit du core. Aucun dump du JSON de configuration.

## 10. Hors périmètre (Phase 1)

Front public **non branché** (reste intact), pages Offres/Entreprises/Savoir-faire/Conseils/Contact/
SEO **préparées** mais non finalisées, backend métier et Lot 14 **non touchés**.

---

## 11. Phase 2 — pages publiques, SEO, navigation consolidée

### 11.1 Navigation : un seul menu
Le second menu de premier niveau « Postelio Site » est **supprimé**. Tout vit dans l'unique menu
**Postelio**, ordonné en groupes matérialisés par des libellés CSS (`::before` ancrés par slug —
robustes, sans JS, dégradant proprement) : **Mon site** (Accueil, Navigation, Footer, Pages &
contenus, Apparence, SEO) · **Activité** (Utilisateurs, Entreprises, Offres, Candidatures,
Savoir-faire, Messagerie, Entretiens) · **Contrôle** (Modération, Facturation, Sources,
Notifications, CV & fichiers) · **Système** (Réglages, Santé, Favoris & Alertes). Les éditeurs des
pages de contenu (Offres/Entreprises/Savoir-faire/Conseils/Contact) sont **enregistrés** (routables +
capability intacte) mais **masqués du menu par CSS** — on n'utilise PAS `remove_submenu_page()` (qui
casserait la vérification de capability de wp-admin). Accès via le hub.

### 11.2 Pages & contenus (hub)
Écran de cartes (`Site\PagesHubPage`) : chaque page publique (Accueil, Offres, Entreprises,
Savoir-faire, Conseils, Contact) avec nom, description, état (sections actives), statut SEO, et
boutons **Modifier** / **SEO** / **Voir** (si `front_path`). L'utilisateur n'a jamais à connaître les
CPT WordPress.

### 11.3 Éditeurs de pages publiques
Offres, Entreprises, Savoir-faire, Conseils, Contact deviennent des pages `type=sections` (mêmes
briques que l'Accueil : Hero, Recherche, Filtres, Résultats/Flux, Mise en avant, Arguments, CTA…),
donc réutilisent intégralement le moteur (cartes, switches, réordonnancement, aperçu). Les **filtres**
sont déclarés par l'admin (libellé + visible) : on n'invente aucun filtre côté moteur métier.

### 11.4 Sélecteur de contenu (`collection`)
Nouveau type de champ : l'admin **recherche et ajoute** du contenu (offres / entreprises /
savoir-faire / articles) sans jamais taper d'UUID. REST admin `GET /site/admin/search?type=&q=` et
`GET /site/admin/resolve?type=&ids=` — lecture via les **façades propriétaires**
(`JobAdminDirectory`, `CompanyAdminDirectory`, `SkillAdminDirectory`) et les **Posts WordPress** pour
les articles (aucune requête SQL cross-domaine, aucune duplication de données métier). Le stockage
reste une **liste de références stables** (UUID / ID). Une référence supprimée s'affiche « **Contenu
indisponible** » et reste retirable (l'éditeur ne casse pas). Réordonnancement + suppression par
élément.

### 11.5 SEO
Écran structuré (`type=sections`, `no_toggle`) : **Global** (nom du site, template de titre, meta
description par défaut, image sociale) + une carte **par page** (Accueil, Offres, Entreprises,
Savoir-faire, Conseils, Contact) avec titre SEO, meta description, titre/description sociaux, image,
`noindex`. **Compteurs** indicatifs (~60 / ~155, non bloquants). Aperçu **SERP Google** + **Open
Graph** en direct pour la page ouverte (aperçu éditorial : ne simule ni position ni indexation
réelle). L'indexation globale relève de WordPress (Réglages → Lecture) — non dupliquée ici.

### 11.6 Apparence / Identité
Écran regroupé en **Identité** (logo principal, logo clair, favicon, image sociale — ressources
globales que Navigation/Footer réutilisent via `use_identity_logo`, avec override possible),
**Couleurs**, **Typographie**, **Boutons**. Bouton **« Réinitialiser aux valeurs Postelio »** (avec
confirmation) pour ne pas casser la cohérence de marque.

### 11.7 Schéma & migration
`SiteSchema::VERSION = 2`. Les pages Phase 1 (home/navigation/footer/appearance) restent
**rétro-compatibles** (ajouts avec défauts, fusion `stored` sur `defaults` → aucune perte : testé
avec une ancienne option home Phase 1). Les pages « préparées » de la Phase 1
(jobs/companies/skills/blog/contact), jamais finalisées, **changent de forme** (single → sections),
d'où la montée de version. `blog` renommé `advice` (Conseils).

### 11.8 Décisions documentées
- **Articles / Conseils** : source éditoriale = **Posts WordPress**, sélectionnés par ID via le
  `collection` (pas de second moteur d'articles, pas de copie de contenu).
- **Contact** : **aucun backend d'envoi** n'existe encore — l'écran configure UNIQUEMENT l'affichage
  (documenté dans l'éditeur). Aucun SMTP / secret.
- **Cache** : les options WordPress sont déjà mises en cache efficacement (options autoloaded /
  object cache). **Pas** de couche Redis/transient ajoutée à ce stade.
- **Yoast / RankMath** : **aucune dépendance** ni synchronisation. La config SEO du Site Builder est
  destinée au futur front headless/statique.

### 11.9 REST public — sûreté
`GET /site/config[/{page}]` ne renvoie que de la **présentation** (y compris les références de
sélection). Aucune donnée admin, audit, secret ou capability.

### 11.10 Sécurité
Sanitization stricte par type côté service (texte, zone, URL via `esc_url_raw` — jamais de
`javascript:`, couleur hex, select validé, `collection` = liste d'IDs `[A-Za-z0-9-]` bornée à 50).
Save bar inchangée (Annuler restaure, Enregistrer valide côté serveur) ; en cas d'échec, les
modifications locales sont **conservées**. Audit `site.<page>.updated` étendu à toutes les pages.

### 11.11 Hors périmètre (Phase 2)
Front public toujours **non branché**, backend métier et Lot 14 **non touchés**.


---

## 12. Corrections ciblées — Footer, Identité, Favicon (septembre 2026)

### 12.1 Footer : aperçu = le VRAI footer, en mobile
- Le schéma du Footer déclare `preview_target = footer` et `preview_device = mobile`
  (`SiteSchema::footer()`). L'éditeur lit ces indications : le sélecteur Desktop / Tablette / Mobile
  est **masqué** sur cet écran (libellé « Aperçu mobile » + « Ouvrir »), le canvas est forcé en
  largeur mobile, et **toutes les autres pages** conservent leurs trois modes. Reload = même état
  (source de vérité = schéma, pas de préférence locale).
- L'iframe charge toujours le vrai front (`/index.html?postelio_preview=1`). Chaque message
  `postelio-site-preview` porte désormais `target`. Le bridge (`assets/js/site-preview-bridge.js`)
  applique la config puis appelle `scrollIntoView` sur `footer.site-footer` (liste blanche
  `footer|header`, jamais de sélecteur venu du message), immédiatement puis après stabilisation du
  rendu (350 ms). Pas de `setTimeout` de découverte : le premier envoi part sur l'événement
  `postelio-preview-ready` existant. Après une modification live, le footer est **recalé**, jamais
  le hero.
- Panneau gauche réorganisé : **Marque** (logo global / override, nom de marque override,
  description) · **Colonnes de liens** · **Réseaux sociaux** · **Mentions / bas de page**
  (liens légaux, copyright) · **Réglages** (afficher newsletter, afficher réseaux). Les réglages
  agissent sur les blocs réels du footer (`.footer-news`, `.footer-social`) ; les liens légaux sont
  rendus dans `.footer-bottom` (liste `pst-footer-legal`, preview uniquement).

### 12.2 Identité globale = source de vérité (Apparence → Identité)
- Champs : **Nom de marque** (`brand_name`, défaut « Postelio »), **Logo principal**, **Logo
  clair**, **Favicon du site**, **Image sociale**. Logos : `svg|png|webp|jpg|jpeg` ; favicon :
  `svg|png|ico` (validés côté UI ET côté service, extension refusée → repli sur le défaut).
- Champ média : Choisir / **Remplacer** / **Retirer** / **Restaurer la valeur par défaut** +
  prévisualisation réelle (logo en `contain` sur damier ; favicon rendu **16 px et 32 px** comme dans
  un onglet). Les chemins racine (`/assets/...`) sont acceptés et résolus sur l'origine du front.
- **Navigation / Footer** : `use_identity_logo` (défaut ON) → logo global ; le champ « Logo
  (override) » est **masqué** tant que le toggle est actif (`show_if`) et réapparaît sinon. Un rappel
  « Identité globale : <nom> · <logo> — Modifier dans Apparence → Identité » est affiché dans la carte
  Marque. `brand_text` devient un **override vide par défaut** (repli sur `brand_name` global) ; la
  **description** reste propre au footer. Une valeur `brand_text` déjà stockée reste honorée
  (ajouts rétro-compatibles, `VERSION` inchangée = 2).
- Le bridge applique l'identité au vrai DOM : `.site-header .logo-mark` reçoit une `<img>` (ou
  retrouve sa pastille « P » si vide), une `<img class="pst-footer-logo">` est insérée dans
  `.footer-brand`, `.logo-text` (header + footer) = override ou nom global, `<link rel="icon">` =
  favicon configuré (défaut : favicon Postelio).
- `SiteConfigDirectory::identity()` renvoie l'identité résolue en URLs absolues
  (`brand_name`, `logo_url`, `logo_light_url`, `favicon_url`, `favicon_is_default`) ; exposée dans
  `GET /site/config` (clé `identity`) pour le futur front.

### 12.3 Favicon : UNE source de vérité, et ce qui est réellement branché
- **Référence validée** : `assets/icons/favicon.svg` (pastille corail `#FF6B6B`, « P » bleu nuit
  `#17324D`). Le fichier du front a été aligné sur cette version (il portait encore l'ancienne
  palette verte/miel). `SiteSchema::DEFAULT_FAVICON = /assets/icons/favicon.svg` : le champ
  Apparence → Favicon vaut ce fichier par défaut, « Restaurer la valeur par défaut » y revient.
- **Branché réellement** : (1) l'aperçu Site Builder (bridge) ; (2) **tout ce que WordPress rend
  lui-même** — wp-admin, écran de connexion, éventuel front WP — via le filtre `get_site_icon_url`
  (`Postelio\Site\Plugin::site_icon_url`) : WordPress émet ses balises `<link rel="icon">` avec le
  favicon Postelio configuré, sans réglage Customizer parallèle. Vérifié : `wp_site_icon()` sort
  `http://postelio.local/assets/icons/favicon.svg`.
- **NON branché (gap documenté, pas simulé)** : les **29 pages HTML statiques du front** portent en
  dur `<link rel="icon" href="assets/icons/favicon.svg">` (+ `manifest.webmanifest`, `icon-192/512`,
  et `404.html` qui pointe l'URL absolue GitHub Pages du même fichier). Elles affichent donc le
  favicon **par défaut** (= la référence validée), pas un favicon remplacé dans l'admin. Un favicon
  ou un logo personnalisé ne s'appliquera au front public que lorsque le chantier **I12 — Site
  Builder public** consommera `GET /site/config` (clé `identity`) au chargement, ou lorsque les HTML
  seront générés côté serveur. Ce chantier est distinct et n'a pas été démarré ici. Le manifest PWA
  (`theme_color` encore `#1e4f46`) relève du même chantier.
- Note : WordPress n'autorise pas l'upload de SVG par défaut (`upload_mimes`). Pour téléverser un
  logo SVG via la médiathèque, un plugin d'assainissement SVG (ex. Safe SVG) est nécessaire ; PNG /
  WebP / JPG fonctionnent sans rien. Postelio n'active pas le SVG lui-même (risque XSS).

### 12.4 Tests
`php plugins/postelio-site/tests/run-unit.php` (schéma : cible/appareil footer, groupes, identité,
défauts, formats). Vérifications WordPress (script de session) : sanitization (`javascript:` refusé
→ défaut, `.gif` refusé pour un logo), `identity()`, `get_site_icon_url()`, rendu des pages admin.
Navigateur : front `?postelio_preview=1` en 390 px → message avec `target:footer` → footer visible
immédiatement, logo header/footer, nom de marque, favicon, newsletter masquée, colonnes, liens
légaux ; aucune erreur console. wp-admin : écran Footer en « Aperçu mobile » sans sélecteur
d'appareil, iframe positionnée sur le footer, override logo masqué/affiché selon le toggle.

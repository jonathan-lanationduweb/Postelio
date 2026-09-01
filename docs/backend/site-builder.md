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

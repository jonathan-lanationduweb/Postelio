# Postelio Skills (Lot 13)

« Savoir-faire & Avis » : contenus éditoriaux **publics** publiés par un candidat (en son nom)
ou un recruteur (en son nom ou au nom de **son** entreprise), + **commentaires** (« Avis » V1).
Publication **modérée** en amont (gate préventive, comme les offres). **Aucun rendu WP public**
(exposition REST uniquement, SEO livré en contrat). Aucune dépendance externe, aucun e-mail
direct (événements → Notifications), aucune UI admin (la file de modération centrale suffit).

## Architecture
```
CPT postelio_skill (public=false)  +  taxonomies postelio_skill_category / _tag
   statut MÉTIER en meta : draft | published | archived   (post_status WP reste 'publish')
   masquage à CAUSE DISTINCTE : pst_mod_hidden (modération) · pst_susp_hidden (suspension)
   visibilité publique = published & !mod_hidden & !susp_hidden

POST /me/skills → SkillService (draft) → PUT → POST /publish
   publish/édition significative → ModerationGateway (postelio/moderation/evaluate)
     low → published · medium → published + case · high/critical → reste draft (fail-closed)
   auteur/entreprise TOUJOURS dérivés du serveur (anti-spoofing)

GET /skills, /skills/{uuid}                (public : published + visible)
GET/POST /skills/{uuid}/comments           (« Avis » : modérés pré-insert comme Messaging)

Contrats : SkillDirectory (profils/search/moderation) · SkillModeration (hide/unhide)
Cascade : SuspensionSync écoute user.suspended/unsuspended + company.suspended/verified
```

## Définition V1
Contenu éditorial **libre** (titre, résumé, contenu HTML restreint, catégorie, tags, image
optionnelle) + **blocs structurés OPTIONNELS** (`materiel/etapes/conseils/erreurs/resultat/
galerie/difficulte/duree/metier`) stockés dans `pst_details` — **jamais requis** pour publier.
Champs requis : `titre`, `contenu`, `catégorie`. Notation multi-critères, réactions/likes,
compteur de vues, avis employeur = **HORS V1** (le front garde ces parties en mock).

## Modèle de données
- **CPT `postelio_skill`** (`public=false, publicly_queryable=false`) — pas de table pour le
  contenu principal. Meta : `pst_uuid`(UNIQUE), `pst_status`, `pst_author_type`
  (candidate=personnel | company), `pst_company_id/uuid`, `pst_revision`, `pst_summary`,
  `pst_details`(JSON), `pst_mod_hidden`, `pst_susp_hidden`. Image = featured image (WP Media).
- **Taxonomies WP** : `postelio_skill_category` (hiérarchique) + `postelio_skill_tag` (libre).
- **Commentaires** : table dédiée `wp_postelio_skill_comments` (`public_uuid` UNIQUE, `skill_id`,
  `author_user_id`, `author_role`, `body`, `status` published|hidden|deleted, timestamps,
  `deleted_at`). Migration idempotente, désactivation non destructive.

## Workflow (SANS pending — aligné Jobs)
`draft → published → archived` (+ `published → draft` si édition bloquée). Le **masquage**
n'est pas un statut : deux drapeaux à cause distincte. Une **levée de suspension** ne
réexpose jamais un contenu masqué par la **modération** (drapeaux indépendants).

## Médias
**WordPress Media Library** (public, natif, SEO). **Jamais** `postelio-files` (stockage privé
CV). Featured image + galerie (IDs d'attachements → URLs par le presenter). Contenu HTML =
`wp_kses` liste blanche (p, listes, gras/italique, titres, liens https ; jamais script/iframe/
style/attributs on…/schémas javascript:/data:/file:).

## Modération
`ModerationGateway::evaluate` à la publication et à l'édition significative d'un contenu publié
(bloqué → redevient draft). Signalement : `POST /moderation/reports` (`resource_type` **skill**
ou **skill_comment** — ajout additif au catalogue de motifs). Hide/unhide modérateur →
`SkillModeration` (routé depuis `ModerationActions`). Commentaires : gateway **pré-insert**
(low→publié, medium→publié+flag, high/critical→aucune row). Aucun système parallèle.

## Suspension / suppression
- User suspendu / **supprimé** → ses savoir-faire personnels **masqués** (`pst_susp_hidden`),
  restaurés à la réactivation (jamais un contenu masqué par la modération). Nouvelle
  publication/commentaire refusés (`is_active`).
- Entreprise suspendue → contenus entreprise masqués ; réactivation (`company.verified`) → restaurés.
- Jamais de hard-delete ; auteur = archive, modérateur = hide, admin = suppression logique
  exceptionnelle. Anonymisation/conservation post-suppression = **futur** (À VALIDER RGPD/SEO).

## SEO (contrat prêt, non rendu V1)
Vue publique : `seo { slug, title, meta_description, canonical(null V1), author, date_published,
date_modified, noindex, in_sitemap }`. API indexée par **UUID** ; le slug (post_name) n'est
jamais une clé métier. Le front ne consomme pas encore ce contrat.

## API
Public : `GET /skills` (filtres `q, category, tag, author_type, company, sort, page/per_page` ;
published+visible only), `GET /skills/{uuid}`. Auteur : `GET/POST /me/skills`,
`GET/PUT /me/skills/{uuid}`, `POST /me/skills/{uuid}/publish|archive`. Avis :
`GET/POST /skills/{uuid}/comments`. UUID publics only, jamais d'ID SQL ni de donnée privée.

## Capabilities (réutilisées + minimum)
Candidat : `pst_publish_own_skill`, `pst_manage_own_skill`, `pst_comment_skill`. Recruteur :
idem + `pst_manage_company_content` (contenu entreprise via `as_company`). Admin : tout
(hide/unhide via Moderation `pst_moderate_content`). Support : aucun accès. `pst_email_verified`
exigé pour publier/commenter (cohérent avec les autres écritures sensibles).

## Événements
`skill.created`, `skill.updated`, `skill.published`, `skill.archived`, `skill.hidden`,
`skill.restored`, `skill.comment_created`. Notifications (Lot 09, additif) : commentaire reçu →
auteur du savoir-faire ; masquage modération via l'événement existant. Aucun e-mail direct.

## Contrats publics
- `SkillDirectory` : `get_context`, `belongs_to_user`, `belongs_to_company`, `public_view`,
  `published_for_user`, `published_for_company` (profils candidat/entreprise, search futur).
- `SkillModeration` : `hide`, `unhide`, `set_visibility`, `is_visible`.

## Contrats additifs (autres domaines, non destructifs)
- `postelio-core` : capability `pst_comment_skill` (+ caps skill sur le rôle recruteur).
- `postelio-users` : `UserDirectory::public_author` (byline auteur public, jamais email/tél).
- `postelio-moderation` : `ModerationActions` route `skill` hide/unhide → `SkillModeration` ;
  `ReasonCodes` gagne `skill_comment` ; visibilité skill/skill_comment via le filtre
  `postelio/moderation/resource_visible` (fourni par Skills).

## Sécurité
XSS (`wp_kses`), liens dangereux neutralisés, non-divulgation 404 (cross-user/company),
mass-assignment (whitelist ; auteur/entreprise serveur), UUID only, anti-spoofing, rate-limit
commentaires (`postelio/skills/comment_rate_per_hour`), spam via Moderation. Jamais d'e-mail/tél
privé dans la byline.

## Tests
- `tests/run-unit.php` : SkillStateMachine, SkillSanitizer (XSS/liens/tags/details), dérivations pures.
- `tests/smoke.php` : create/update/publish (low/medium/high)/archive · pas de pending ·
  ownership candidat A/B & entreprise A/B · as_company & anti-spoofing · liste/détail public ·
  draft/hidden/archived → 404 · SEO · révision · hide/unhide (SkillModeration) · report ·
  commentaires (low/medium/high, rate-limit) · suspension user/entreprise (masquage + cause
  distincte) · compte supprimé.

## Décisions FIGÉES (V1)
Contenu libre + blocs optionnels · CPT (pas de table contenu) · commentaires table dédiée ·
notation/likes FUTUR · avis employeur HORS lot · workflow SANS pending · WP Media (jamais
postelio-files) · taxonomies WP · suspension = masquage (cause distincte) · suppression =
masquage · SEO en contrat · front non modifié.

## Points À VALIDER
Notation au lancement · rétention RGPD post-suppression (masquer vs anonymiser) · liste
éditoriale des catégories · avis employeur (conception séparée future).

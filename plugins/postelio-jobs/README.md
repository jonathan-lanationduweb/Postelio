# postelio-jobs

Offres d'emploi (Lot 04). Dépend de **postelio-core**, **postelio-users** et
**postelio-companies** ; réutilise registry, REST `postelio/v1`, événements,
migrations/cron, erreurs, permissions et audit du core, ainsi que les **contrats
publics** de companies (`CompanyDirectory`, `CompanyVerification`). **Aucune
infrastructure dupliquée.**

> Hors périmètre : candidatures, messagerie, entretiens, **facturation réelle**
> (renouvellement payant), savoir-faire, notifications réelles, modération, et
> **favoris/alertes candidat**.

## Modèle de données
Offre = **CPT `postelio_job`** (data-model.md). `post_title` = intitulé,
`post_content` = description, `post_author` = recruteur. Meta : `pst_uuid`
(UUID public D2, immuable), `pst_company_id`/`_uuid`/`_name` (dénormalisé),
`pst_status` (cycle de vie), champs filtrables (`pst_ville`, `pst_contrat`,
`pst_categorie`, `pst_teletravail`, `pst_niveau_etude`, `pst_experience`,
`pst_salaire_annuel`, `pst_alternance/stage/debutant`), `pst_date_publication`,
`pst_date_expiration`, et `pst_detail` (JSON : missions, profil, compétences,
avantages, présélection, processus, libellés…). Pas de table dédiée.

## Cycle de vie (machine à états canonique V1 — 7 états)
`draft, published, expiring, expired, filled, archived, suspended`. **Aucun état
fantôme** : `pending` (modération) retiré → futur `postelio-moderation` ; `renewed`
**n'est pas un état** mais la transition `expiring|expired → published` + l'événement
`job.renewed`. **D1 :** brouillon créable sans entreprise vérifiée ; **publication
publique (`draft → published`) exige `verified`** via
`CompanyVerification::can_publish_jobs()`. Seul un **brouillon** est publiable en
libre-service (réactivation d'une suspendue = admin ; remise en ligne d'une expirée =
renouvellement billing). Expiration cron quotidien (dates **UTC**) :
`published → expiring` (J‑7, borne incluse), `published/expiring → expired`
(échéance incluse). **Visibles publiquement : `published`/`expiring` seulement.**

## Édition d'une offre publiée (V1)
Une offre `published`/`expiring` peut modifier ses champs **éditoriaux**. Protégés
(jamais modifiables via l'édition) : entreprise propriétaire, `uuid`, `statut`, dates
système. Chaque édition incrémente `revision` (version métier, base du snapshot de
candidature). `draft` éditable ; `archived`/`suspended` non éditables.

## Renouvellement (contrat billing)
`Postelio\Jobs\Api\JobLifecycle::can_renew()` / `renew_after_payment($id,$days,$meta)` :
seul point d'entrée du renouvellement. **Billing n'écrit jamais** `pst_status` /
`pst_date_expiration` — il appelle ce contrat après paiement, qui remet l'offre
`published`, prolonge l'échéance, incrémente `renewal_count` et émet `job.renewed`.
Aucun paiement/endpoint payant en V1.

## Entreprise dénormalisée
`pst_company_id` (relation), `pst_company_uuid` (référence publique) — **immuables** ;
`pst_company_name` = **cache/repli**. Le presenter lit toujours le nom **courant** via
`CompanyDirectory`, et le cache est rafraîchi sur `company.updated` (aucun affichage de
deux noms différents).

## Recherche / filtres
Filtres V1 en **postmeta** (`WP_Meta_Query`) — limite de scalabilité assumée. Les
endpoints passent par `JobSearchProvider` (filtre `postelio/jobs/search_provider`),
remplaçable par **postelio-search** sans casser l'API. Filtres validés/typés ;
`per_page` borné (max 100) ; valeur invalide → 0 résultat (jamais d'erreur).

## Présélection & snapshot candidature (contrats préparés)
`questions_preselection` normalisées : `{id, label, type(oui_non|texte|nombre|choix),
required, critere(indispensable|souhaite|null)}`. `postelio-applications` devra
snapshotter à la candidature : `job_uuid`, `job_revision`, `titre`, entreprise
(uuid+nom à T), questions utilisées (voir data-model.md).

## Endpoints (`postelio/v1`, UUID uniquement)
| Méthode | Route | Accès |
|---|---|---|
| GET | `/jobs` | public (liste + filtres q/ville/contrat/catégorie/télétravail/niveau/exp/salaire_min/alternance/stage/débutant) |
| GET | `/jobs/{uuid}` | public (published/expiring seulement) |
| GET | `/jobs/me` | `pst_edit_own_company_jobs` |
| POST | `/jobs` | `pst_edit_own_company_jobs` + `pst_email_verified` |
| PUT | `/jobs/{uuid}` | idem + membre de l'entreprise |
| POST | `/jobs/{uuid}/publish` | `pst_publish_job` + `pst_email_verified` + entreprise **verified** |
| POST | `/jobs/{uuid}/fill` | `pst_edit_own_company_jobs` |
| POST | `/jobs/{uuid}/archive` | `pst_edit_own_company_jobs` |
| POST | `/jobs/{uuid}/duplicate` | `pst_duplicate_job` + `pst_email_verified` |
| POST | `/jobs/{uuid}/status` | `pst_manage_all_jobs` (admin : suspend/published) |

Ownership : le recruteur doit être **membre** de l'entreprise de l'offre
(`CompanyDirectory::is_member`) ; l'admin (`pst_manage_all_jobs`) contourne.

## Événements
`job.created`, `job.updated`, `job.published`, `job.expiring`, `job.expired`,
`job.filled`, `job.archived`, `job.suspended` — audités par le core.

## Tests
```bash
php plugins/postelio-jobs/tests/run-unit.php                       # sans WP
wp eval-file plugins/postelio-jobs/tests/smoke.php --path=wordpress # WP vivant
```

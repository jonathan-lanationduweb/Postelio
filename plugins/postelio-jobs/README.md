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

## Cycle de vie (machine à états canonique)
`draft → published → expiring → expired → renewed → filled → archived`, `→ suspended`
(admin), `pending` (si modération, hors lot). **D1 :** un **brouillon** est créable
sans entreprise vérifiée ; la **publication publique exige `verified`** (vérifié via
`CompanyVerification::can_publish_jobs()`). Expiration automatique (cron quotidien) :
`published → expiring` (J‑7), `published/expiring → expired` (échéance). Renouvellement
payant (`expired → renewed → published`) = **postelio-billing** (hors lot).

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

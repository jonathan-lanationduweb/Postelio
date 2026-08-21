# postelio-applications

Candidatures candidat ↔ offre ↔ entreprise (Lot 05) — cœur du suivi de recrutement.
Dépend de **core, users, companies, jobs** ; réutilise leurs contrats publics
(`JobDirectory`, `CompanyDirectory`, `UserDirectory`) et l'infra du core. **Aucune
lecture directe des meta d'autres plugins ; aucune infrastructure dupliquée.**

> Hors périmètre : messagerie réelle, entretiens réels, notifications réelles,
> paiement, matching IA, modération générale.

## Tables (via le migrateur du core)
- `wp_postelio_applications` — candidature + **snapshot d'offre** + réponses de
  présélection (JSON). **UNIQUE (job_id, candidate_user_id)** = règle V1 « 1 candidat =
  1 candidature par offre » garantie **en base** (concurrence), y compris après retrait
  (la ligne subsiste → pas de re-candidature).
- `wp_postelio_application_history` — historique **append-only**.
- `wp_postelio_recruiter_notes` — notes internes **privées**.

## Modèle (application)
`id` interne + **`public_uuid`** (D2, seul exposé) ; `candidate_user_id`, `job_id`,
`job_uuid`, `company_id`, `company_uuid`, `status`, `cv_reference`, `job_revision`,
`job_snapshot` (JSON), `screening_answers` (JSON), `candidate_message`, `sort_order`
(Kanban), `created_at`, `updated_at`, `withdrawn_at`.

## Machine à états (V1)
`new → review → shortlisted → interview → selected|rejected`, et `actif → withdrawn`
(candidat). Retours arrière autorisés **entre états actifs** (Kanban) ; `new` jamais
cible ; `selected/rejected/withdrawn` **terminaux**. `interview` = étape de pipeline
(le détail des RDV = `postelio-interviews`). Le front ne peut pas écrire `status`
librement (transitions gardées + capabilities).

Statuts front (Kanban) → backend : `nouveau→new`, `examiner→review`,
`preselection→shortlisted`, `entretien→interview`, `retenu→selected`, `refuse→rejected`.

## Snapshot d'offre
Figé à la candidature (minimal) : `job_uuid`, `job_revision`, `titre`, `company_uuid`,
`company_name` (à T), `questions_preselection`. La candidature reste consultable même si
l'offre change (revision ≠), expire, est pourvue ou archivée.

## CV utilisé
`cv_reference` (référence opaque immuable). **Dépendance : `postelio-files`** fournira le
snapshot CV immuable réel ; en attendant, la référence est conservée telle quelle
(aucun stockage public). Si le candidat remplace son CV, la candidature garde sa
référence historique.

## Présélection
Réponses validées **contre le snapshot serveur** (jamais reconstruites depuis le body) :
par réponse `{question_id, question_label (snapshot), question_type, answer}` ; contrôle
obligatoire/type (oui_non/nombre/texte/choix).

## Endpoints (`postelio/v1`)
| Méthode | Route | Accès |
|---|---|---|
| POST | `/jobs/{job_uuid}/applications` | `pst_apply_job` + `pst_email_verified` |
| GET | `/me/applications` | `pst_view_own_applications` |
| GET | `/me/applications/{uuid}` | candidat propriétaire |
| POST | `/me/applications/{uuid}/withdraw` | `pst_withdraw_own_application` |
| GET | `/companies/me/applications` | `pst_view_company_applications` (filtres job/statut) |
| GET | `/companies/me/applications/{uuid}` | recruteur membre |
| POST | `/companies/me/applications/{uuid}/status` | `pst_change_application_status` + `pst_email_verified` |
| GET/POST | `/companies/me/applications/{uuid}/notes` | `pst_manage_recruiter_notes` |

**Non-divulgation** : toute ressource hors périmètre de l'acteur → **404** (connaître
l'UUID ne donne aucun accès). Le candidat ne voit jamais notes/motif interne/reviewer/
autres candidats ; le recruteur n'agit que dans le contexte de **son** entreprise.

## Événements (via core)
`application.created`, `application.status_changed`, `application.reviewed`,
`application.shortlisted`, `application.interview`, `application.selected`,
`application.rejected`, `application.withdrawn` — audités.

## Contrats sortants
`Postelio\Applications\Api\ApplicationDirectory` (`context`, `belongs_to_company`,
`move_to_interview`) pour **postelio-interviews**/**messaging** — ils n'écrivent jamais
la table applications.

## RGPD
Durées **À VALIDER**. Pas de suppression arbitraire ; anonymisation candidat + conservation
technique de l'historique préparées pour un lot ultérieur.

## Tests
```bash
php plugins/postelio-applications/tests/run-unit.php
wp eval-file plugins/postelio-applications/tests/smoke.php --path=wordpress
```

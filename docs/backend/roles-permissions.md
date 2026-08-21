# Postelio — Rôles & permissions

Rôles WordPress custom (créés par `postelio-core` à l'activation, capabilities mappées).
Le contrôle est **serveur** : chaque endpoint et chaque transition de statut vérifie une
capability. Le front n'accorde aucun droit.

## Rôles

| Rôle | Description |
|---|---|
| `visitor` (non authentifié) | Consultation publique : offres, entreprises, savoir-faire, articles. |
| `postelio_candidate` | Chercheur d'emploi. |
| `postelio_recruiter` | Membre d'une entreprise. |
| `postelio_admin` | Administration globale de la plateforme. |
| `postelio_moderator` (option) | Modération des contenus/signalements. |
| `postelio_support` (option) | Lecture pour assistance, sans écriture métier. |

## Capabilities (préfixe `pst_`)

### Visitor
- `read` public uniquement (offres publiées, fiches entreprise, savoir-faire publiés).
- Aucune capability d'écriture. Postuler / enregistrer / suivre → exige un compte.

### postelio_candidate
- `pst_edit_own_profile`, `pst_manage_own_cv`, `pst_upload_file`
- `pst_apply_job`, `pst_view_own_applications`, `pst_withdraw_own_application`
- `pst_manage_own_favorites`, `pst_manage_own_alerts`, `pst_follow_company`
- `pst_send_message` (dans ses conversations), `pst_report_content`
- `pst_confirm_interview`, `pst_reschedule_interview`, `pst_reject_interview`
- `pst_publish_own_skill`, `pst_manage_own_skill`
- `pst_export_own_data`, `pst_delete_own_account`

### postelio_recruiter
- `pst_manage_own_company` (si owner/membre), `pst_request_company_verification`
- `pst_publish_job`, `pst_edit_own_company_jobs`, `pst_duplicate_job`, `pst_renew_job`
- `pst_view_company_applications`, `pst_change_application_status`
- `pst_manage_recruiter_notes` (privées)
- `pst_propose_interview`, `pst_cancel_interview`
- `pst_send_message`, `pst_report_content`
- `pst_manage_company_content`, `pst_pay_renewal`

> Un recruteur n'agit que sur **son entreprise** : garde par `company_id` (le recruteur
> doit être `CompanyMember`). Vérifié en plus de la capability.

### postelio_admin
- Toutes les capabilities candidat/recruteur en lecture globale +
- `pst_moderate_content`, `pst_verify_company`, `pst_suspend_account`,
  `pst_suspend_company`, `pst_manage_all_jobs`, `pst_view_audit_log`,
  `pst_manage_billing`, `pst_manage_platform`

### postelio_moderator (option)
- `pst_moderate_content`, `pst_view_moderation_queue`, `pst_decide_report`. Pas de
  gestion facturation/plateforme.

### postelio_support (option)
- Lecture d'assistance (`pst_view_*` restreint), aucune écriture métier, pas d'accès
  aux CV ni aux notes recruteur.

## Matrice endpoints × rôles (extrait)

| Action | Candidate | Recruiter | Admin |
|---|---|---|---|
| Voir offres publiées | ✅ (public) | ✅ | ✅ |
| Publier/éditer une offre | ❌ | ✅ (sa company) | ✅ |
| Postuler | ✅ | ❌ | ❌ |
| Voir une candidature | ✅ (la sienne) | ✅ (reçue par sa company) | ✅ |
| Changer le statut d'une candidature | ❌ | ✅ | ✅ |
| Retirer sa candidature | ✅ | ❌ | ✅ |
| Notes recruteur | ❌ | ✅ | ✅ (audit) |
| Voir ses entretiens | ✅ (les siens) | ✅ (ceux de sa company) | ✅ |
| Proposer un entretien | ❌ | ✅ (candidature de sa company) | ✅ |
| Confirmer / refuser un entretien | ✅ (le sien) | ❌ | ✅ |
| Proposer un autre créneau | ✅ (le sien) | ❌ | ✅ |
| Annuler un entretien confirmé | ✅ (le sien) | ✅ (ceux de sa company) | ✅ |
| Modifier / accepter re-créneau / marquer réalisé | ❌ | ✅ (ceux de sa company) | ✅ |
| Vérifier une entreprise | ❌ | ❌ | ✅ |
| Modérer un contenu | ❌ | ❌ | ✅ / modo |
| Télécharger un CV | ✅ (le sien) | ✅ (candidature reçue) | ✅ |
| Lire une conversation | ✅ (participant) | ✅ (candidature de sa company) | ✅ (audit) |
| Envoyer un message | ✅ (participant, e-mail vérifié) | ✅ (e-mail vérifié) | ✅ |
| Ouvrir une conversation | ❌ | ✅ (via candidature de sa company) | ✅ |

## Règles transverses
- **Messagerie (Lot 07)** : capability `pst_send_message` requise pour **lire** ; l'**envoi**
  exige en plus `pst_email_verified`. Une conversation est toujours rattachée à une
  candidature (pas de contact arbitraire) ; accès hors périmètre → **404**. Une entreprise
  peut avoir plusieurs recruteurs participants (lecture + réponse) ; l'état lu/non-lu est
  **par utilisateur**. La **fermeture manuelle** d'une conversation est réservée au
  **propriétaire (`owner`) de l'entreprise** ou à un modérateur (`pst_moderate_content`)
  — pas à un recruteur membre lambda. Fermeture **automatique** si la candidature devient
  `rejected`/`withdrawn` (lecture seule) ; `selected` ne ferme pas.
- **Entretiens (Lot 08)** : nouvelles capabilities `pst_view_own_interviews` (candidat,
  lecture) et `pst_manage_company_interviews` (recruteur, lecture + gestion), en plus des
  capabilities d'action existantes (`pst_propose_interview`, `pst_confirm_interview`,
  `pst_reschedule_interview`, `pst_reject_interview` = decline, `pst_cancel_interview`).
  La **lecture** n'exige pas l'e-mail vérifié ; **proposer/confirmer/reprogrammer/modifier/
  annuler/réaliser** exigent `pst_email_verified`. Un entretien est toujours rattaché à une
  candidature (jamais arbitraire) ; accès hors périmètre → **404**. Décisions V1 :
  l'**annulation candidat** d'un entretien confirmé est autorisée (via `pst_reject_interview`
  + e-mail vérifié, ownership strict) ; **plusieurs entretiens successifs** par candidature
  (doublon actif identique refusé) ; une nouvelle proposition est refusée si l'offre est
  `filled`/`archived`/`suspended` ; `completed` reste **manuel** (aucun cron).
- **Accès aux CV** : le candidat (propriétaire) et **uniquement** les recruteurs d'une
  entreprise ayant reçu une candidature avec ce CV (via snapshot). Admin pour audit.
- **Coordonnées candidat** : soumises à `CandidateProfile.visibility` (email/tel) —
  toujours visibles du recruteur qui consulte le CV ([data-model.md](data-model.md#candidateprofile)).
- **Notes recruteur** : jamais renvoyées par un endpoint accessible au candidat.
- **Transitions de statut** : autorisées par rôle selon [workflows.md](workflows.md).

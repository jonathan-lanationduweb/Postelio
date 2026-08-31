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
- `pst_publish_own_skill`, `pst_manage_own_skill`, `pst_comment_skill` *(Lot 13)*
- `pst_export_own_data`, `pst_delete_own_account`

### postelio_recruiter
- `pst_manage_own_company` (si owner/membre), `pst_request_company_verification`
- `pst_publish_job`, `pst_edit_own_company_jobs`, `pst_duplicate_job`, `pst_renew_job`
- `pst_view_company_applications`, `pst_change_application_status`
- `pst_manage_recruiter_notes` (privées)
- `pst_propose_interview`, `pst_cancel_interview`
- `pst_send_message`, `pst_report_content`
- `pst_manage_company_content`, `pst_pay_renewal`
- `pst_publish_own_skill`, `pst_manage_own_skill`, `pst_comment_skill` *(gagnées au Lot 13 ;
  `pst_manage_company_content` couvre le savoir-faire au nom de l'entreprise via `as_company`)*

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
| Payer un renouvellement d'offre (Lot 12) | ❌ | ✅ (owner **ou** recruteur, sa company) | ✅ |
| Facturation admin / santé (Lot 12) | ❌ | ❌ | ✅ (`pst_manage_billing`) |
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
| Signaler un contenu | ✅ | ✅ | ✅ |
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
- **Notifications (Lot 09)** : tout utilisateur Postelio authentifié lit/gère **ses
  propres** notifications (`GET/POST /me/notifications*`, capability `read`) et ses
  préférences (`GET|PUT /me/notification-preferences`). Ownership strict (A ne voit jamais
  B). Certaines catégories sont **obligatoires** (entretien annulé, preuve de confirmation,
  entreprise suspendue) : l'e-mail part malgré une préférence OFF. Le compteur cloche
  (notifications) est distinct du compteur messagerie (messages non lus).
- **Modération (Lot 11)** : **aucune nouvelle capability** — les rôles sont **réutilisés**.
  Le **modérateur** (`pst_view_moderation_queue`, `pst_moderate_content`, `pst_decide_report`)
  accède à la file, assigne, revoit, note et exécute les actions `no_action`/`hide`/`unhide`/
  `close_conversation`/`warning`/`dismiss`/`escalate`. L'**admin** (toutes capabilities) peut
  en plus `suspend_user`/`unsuspend_user`, `suspend_company`/`unsuspend_company`,
  `suspend_job`/`unsuspend_job` — ces actions admin sont **re-vérifiées par capability dans
  l'exécuteur d'action**. Le **support n'a aucun accès modération**. Signaler un contenu =
  `pst_report_content` (candidat + recruteur). Les **notes internes** d'un cas ne sont
  visibles que dans `GET /moderation/cases/{uuid}` (file admin), jamais côté auteur du
  signalement. Non-divulgation systématique → **404**.
- **Facturation (Lot 12)** : **aucune nouvelle capability** — les rôles sont **réutilisés**.
  Le **checkout** (`POST /billing/checkout`) et la consultation des commandes exigent
  `pst_pay_renewal` (**owner ET recruteur** membres de l'entreprise peuvent payer) **+
  `pst_email_verified`** ; conditions supplémentaires : entreprise **`verified` & non
  suspendue**, `JobLifecycle::can_renew()`. Les endpoints **admin** (`/billing/admin/*`,
  `/billing/health`) exigent `pst_manage_billing` (**admin uniquement**). Le **candidat** et
  le **support** n'ont **aucun** accès facturation. Non-divulgation inter-entreprise → **404**.
- **Savoir-faire & Avis (Lot 13)** : **une seule** nouvelle capability, `pst_comment_skill`.
  Publier/gérer son savoir-faire = `pst_publish_own_skill` + `pst_manage_own_skill` (candidat
  **et** recruteur) ; contenu au nom de l'entreprise = `pst_manage_company_content` (recruteur,
  membre) via le flag `as_company`. **Publier** et **commenter** exigent en plus
  `pst_email_verified`. Lecture publique = visiteur (contenu `published` **et** visible). Le
  masquage/démasquage modérateur passe par la Modération (`pst_moderate_content`) et le contrat
  `SkillModeration` — jamais une capability skill dédiée. Le **support n'a aucun accès**.
  Ownership strict (auteur/entreprise dérivés serveur) ; hors périmètre → **404**.
- **Back-office (postelio-admin, Phase 1)** : **aucune nouvelle capability** — les rôles
  sont **réutilisés**. Menu, tableau de bord et pages de modération = `pst_view_moderation_queue`
  (admin + modérateur) ; utilisateurs, entreprises, offres, santé, réglages et emplacements en
  préparation = `pst_manage_platform` (admin) ; facturation = `pst_manage_billing`. Chaque page
  **re-vérifie la capability côté serveur** et chaque action passe par `admin-post` **avec nonce**.
  **Recruteur, candidat et support n'ont aucun accès** au back-office. Voir
  [admin-backoffice.md](admin-backoffice.md).
- **Accès aux CV** : le candidat (propriétaire) et **uniquement** les recruteurs d'une
  entreprise ayant reçu une candidature avec ce CV (via snapshot). Admin pour audit.
- **Coordonnées candidat** : soumises à `CandidateProfile.visibility` (email/tel) —
  toujours visibles du recruteur qui consulte le CV ([data-model.md](data-model.md#candidateprofile)).
- **Notes recruteur** : jamais renvoyées par un endpoint accessible au candidat.
- **Transitions de statut** : autorisées par rôle selon [workflows.md](workflows.md).

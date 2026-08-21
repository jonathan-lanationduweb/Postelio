# Postelio Interviews (Lot 08)

Backend de planification et de gestion des entretiens, lié à une **candidature**.
Formats : **visioconférence**, **sur place**, **téléphone**. Aucune intégration externe
(pas d'e-mail réel, ni Google/Teams/Meet/Zoom, ni SMS/push, ni facturation) : le plugin
**émet des événements** que Notifications/E-mails consommeront plus tard.

## Contexte obligatoire (candidature)
Un entretien est toujours rattaché à une candidature valide, via
`ApplicationDirectory::context()` + appartenance entreprise `CompanyDirectory::is_member()`.
Le recruteur ne peut proposer que sur une candidature **de son entreprise** et dans un
**état planifiable** (`ApplicationDirectory::is_schedulable()` = pipeline actif :
new/review/shortlisted/interview). Candidature terminale (selected/rejected/withdrawn)
⇒ proposition refusée `409 conflict`. Accès hors périmètre ⇒ **404** (non-divulgation).

Le plugin **n'écrit jamais** la table `applications` : à la première proposition valide,
il fait avancer le pipeline via `ApplicationDirectory::move_to_interview()` (best-effort —
n'invalide pas l'entretien si la transition n'est pas applicable). **Décision : la
transition pipeline a lieu à la _proposition_** (pas à la confirmation).

## Tables
- `wp_postelio_interviews` — créneau courant en **UTC** (`scheduled_at`) + `timezone`
  métier, `type`, `duration_minutes`, données spécifiques JSON (`video_data` /
  `location_data` / `phone_data`), `instructions`, statut, proposition de re-créneau en
  attente (`proposed_scheduled_at` / `proposed_by` / `proposed_message`),
  `candidate_response_at`, `cancelled_at`. `UNIQUE public_uuid` ; aucun ID interne exposé.
- `wp_postelio_interview_history` — append-only : une ligne par transition (`actor_role`,
  `action`, `from_status`, `to_status`, métadonnée **minimale** — jamais d'instructions
  ni de coordonnées privées).

## Machine à états
`proposed → confirmed | declined | reschedule_requested | cancelled`
`confirmed → reschedule_requested | cancelled | completed | proposed` *(modif substantielle)*
`reschedule_requested → confirmed | proposed | cancelled`
`declined | cancelled | completed` = **terminaux**.

`proposed` représente déjà « en attente du candidat » — pas d'état `pending_candidate`
redondant. Toutes les transitions sont contrôlées côté serveur.

## Types & données
- **video** : `video_data.meeting_url` (http/https validé strictement, jamais rendu en
  HTML) + `provider` optionnel. URL saisie manuellement (aucune API Meet/Teams/Zoom).
- **onsite** : `location_data` **structuré** (address, address_complement, postal_code,
  city, contact, access_instructions). La ville est **préremplie** depuis
  `CompanyDirectory::public_summary()` si absente. Adresse **ou** ville requise.
- **phone** : `phone_data.phone_number` + `who_calls` (`recruiter_calls|candidate_calls`).
  Le numéro n'est renvoyé qu'aux participants autorisés (non-divulgation).

## Dates / durée / validation
- Entrée **ISO 8601**, stockage **UTC** ; fuseau métier conservé à part. Une entrée sans
  offset est interprétée dans le `timezone` fourni. Testé hiver/été (DST).
- Créneau refusé si **passé** ou fuseau invalide. Durée bornée **15–240 min**.
- `instructions` : texte seul (`sanitize_textarea_field`, XSS inerte). URL/téléphone/
  adresse validés et nettoyés.

## Actions & permissions (capabilities cohérentes avec roles-permissions.md)
| Action | Route | Capability |
|---|---|---|
| Lister/voir (candidat) | `GET /me/interviews`, `GET /me/interviews/{uuid}` | `pst_view_own_interviews` |
| Confirmer | `POST /me/interviews/{uuid}/confirm` | `pst_confirm_interview` + `pst_email_verified` |
| Refuser | `POST /me/interviews/{uuid}/decline` | `pst_reject_interview` |
| Autre créneau | `POST /me/interviews/{uuid}/reschedule` | `pst_reschedule_interview` + `pst_email_verified` |
| Lister/voir (recruteur) | `GET /companies/me/interviews[/{uuid}]` | `pst_manage_company_interviews` |
| Proposer | `POST /companies/me/applications/{application_uuid}/interviews` | `pst_propose_interview` + `pst_email_verified` |
| Modifier | `PUT /companies/me/interviews/{uuid}` | `pst_manage_company_interviews` + `pst_email_verified` |
| Accepter le re-créneau candidat | `POST /companies/me/interviews/{uuid}/accept-reschedule` | `pst_manage_company_interviews` + `pst_email_verified` |
| Annuler | `POST /companies/me/interviews/{uuid}/cancel` | `pst_cancel_interview` + `pst_email_verified` |
| Marquer réalisé | `POST /companies/me/interviews/{uuid}/complete` | `pst_manage_company_interviews` + `pst_email_verified` |

La lecture n'exige pas l'e-mail vérifié ; les actions **sensibles** oui. UUID lu depuis les
**params d'URL** uniquement (jamais le body). Filtres de liste : `status`, `from`, `to`,
`application_uuid` ; pagination `page`/`per_page`.

## Reschedule / modification / reconfirmation
- **Candidat** propose un autre créneau (`reschedule`) : le créneau initial est **conservé**,
  la proposition est stockée dans `proposed_*` et le statut passe `reschedule_requested`.
- **Recruteur** peut **accepter** (`accept-reschedule` → le créneau proposé devient le
  créneau officiel, statut `confirmed`) ou **contre-proposer** via `PUT` (nouveau créneau,
  statut `proposed`) ou **annuler**.
- Une modification recruteur **substantielle** (date/heure, type, lieu, lien visio) d'un
  entretien **déjà confirmé** le renvoie en `proposed` ⇒ **nouvelle confirmation candidat
  requise**. Une modification mineure (instructions) reste dans le même statut.

## Concurrence / doublons
**Un seul entretien actif** (`proposed`/`confirmed`/`reschedule_requested`) par
candidature à la fois (protection anti-doublon). Un nouvel entretien n'est possible
qu'une fois le précédent terminal (completed/cancelled/declined) — modèle « entretiens
successifs ». *(À VALIDER : autoriser plusieurs entretiens actifs simultanés.)*

## Offre expirée / pourvue (§33)
L'entretien dépend de l'**état de la candidature**, pas de la visibilité publique de
l'offre : une offre expirée/pourvue n'empêche pas de planifier tant que la candidature est
active. Seule une candidature **terminale** bloque. *(À VALIDER : cas
filled/archived/suspended de l'offre si politique RH différente.)*

## Événements (via core, audit minimal sans données privées)
`interview.proposed`, `interview.confirmed`, `interview.declined`,
`interview.reschedule_requested`, `interview.rescheduled`, `interview.cancelled`,
`interview.completed`. Payload (pour Notifications/e-mail) : `interview_uuid`,
`application_uuid`, `candidate_user_id`, `company_id`, `job_uuid`, `scheduled_at`, `type`.
L'audit ne contient **jamais** l'URL visio, l'adresse, le téléphone ni les instructions.

## Lien messagerie (§22)
Aucune seconde messagerie : le front réutilise `MessagingDirectory` pour ouvrir/retrouver
la conversation de la candidature. Interviews **n'insère pas** de message système en V1 ;
il émet `interview.*` — Messaging/Notifications pourront y réagir plus tard.

## Contrat public
`Postelio\Interviews\Api\InterviewDirectory` : `get_context()` (données complètes pour
l'e-mail de preuve : entreprise, offre, date UTC, fuseau, durée, type + coordonnées),
`upcoming_count()`, `has_active_for_application()`, `history()`.

## Données préparées pour l'e-mail de preuve (§26)
`get_context()` expose tout le nécessaire au futur e-mail de confirmation de rendez-vous :
entreprise (nom/uuid), offre (`job_uuid`), `scheduled_at` (UTC) + `timezone` + durée, type
et coordonnées selon le type, plus la référence `interview_uuid`.

## Mapping front (front non modifié dans ce lot)
- **Recruteur** (« Mes entretiens ») : *Planifier* → `POST …/applications/{uuid}/interviews` ;
  *Modifier* → `PUT …/interviews/{uuid}` ; *Annuler* → `…/cancel` ; *Message* →
  `MessagingDirectory` ; *Voir profil* → `candidate.profile_uuid` ; badges
  Confirmé/À confirmer → `status`.
- **Candidat** : *Confirmer* → `…/confirm` ; *Proposer un autre créneau* → `…/reschedule` ;
  *Refuser* → `…/decline` ; *Voir l'offre* → `job_uuid` ; *Message* → `MessagingDirectory`.
- **Mobile/Tauri** : API identique (Bearer), aucune logique spécifique.

## Points À VALIDER
- Annulation par le **candidat** après confirmation (V1 : le candidat *refuse* avant
  confirmation ou *repropose* un créneau ; il ne peut pas hard-cancel). À arbitrer.
- Un seul entretien actif par candidature (vs plusieurs simultanés).
- Comportement sur offre `filled`/`archived`/`suspended`.
- Automatisation de `completed` (cron à l'heure passée) — V1 = marquage manuel recruteur.

## Tests
- `tests/run-unit.php` : machine à états + validation (types, fuseaux, DST, durée, URL).
- `tests/smoke.php` : scénario complet (proposition, confirmation, modif/reconfirmation,
  réalisé, autre créneau + acceptation, annulation, refus, doublon, candidature terminale,
  historique, listes/filtres, permissions, events/audit).

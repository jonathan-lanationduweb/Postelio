# Postelio — Modèle de données

Dérivé des objets réellement présents dans le front (clés `ss_*` en localStorage).
Pour chaque objet : champs, relations, propriétaire (plugin), statut, dates,
contraintes, index, données sensibles, conservation (RGPD → voir
[security.md](security.md#rgpd)). `À VALIDER` = décision produit/juridique non figée.

## Stockage : WordPress natif vs tables dédiées

| Objet | Stockage retenu | Justification |
|---|---|---|
| User | `wp_users` + `wp_usermeta` | Auth/rôles natifs WordPress, réutilise sessions/reset password. |
| CandidateProfile / RecruiterProfile | **table dédiée** (1-1 avec user) | Champs nombreux et structurés (JSON pour listes) ; requêtes/filtres ; éviter l'explosion de `usermeta`. |
| Company | **CPT** `postelio_company` | Contenu éditorial public (fiche, présentation, logo), profite des révisions/medias WP. |
| CompanyMember, Follow | **table dédiée** | Relations n-n simples, requêtes par `company_id`/`user_id`. |
| Job | **CPT** `postelio_job` + taxonomies | Contenu public listé/filtré/indexé ; taxonomies natives (secteur, contrat…). |
| JobAlert, Favorite | **table dédiée** | Données transactionnelles par utilisateur, volumineuses, non éditoriales. |
| Application, ApplicationHistory, PreselectionAnswer, RecruiterNote | **tables dédiées** | Transactionnel, relationnel, sensible, fort volume, index critiques. **Pas** de CPT. |
| CV, Document, CVSnapshot | **table dédiée** + fichier protégé | Fichiers sensibles hors webroot, accès contrôlé, versionnement. |
| Conversation, Message | **tables dédiées** | Fort volume, index par conversation/date, temps réel futur. **Pas** de CPT. |
| Interview | **table dédiée** | Transactionnel, statuts, dates, relations. |
| Notification | **table dédiée** | Fort volume, index `user_id`/`read_at`, purge périodique. |
| SkillContent, CompanyContent | **CPT** `postelio_skill` / `postelio_company_content` | Contenu éditorial public modéré (comme les articles). |
| Payment, Invoice, Renewal, WebhookEvent | **tables dédiées** | Financier, traçable, immuable, webhooks. |
| ModerationReport | **table dédiée** | File admin, requêtes par statut/type. |
| AuditLog | **table dédiée** (append-only) | Journal immuable, index `actor`/`resource`/`created_at`. |

> **Décision explicite : pas de « tout en CPT ».** Le transactionnel/sensible/volumineux
> (candidatures, messages, notifications, entretiens, paiements, historiques, logs) va
> en **tables dédiées** `wp_postelio_*`. Le contenu éditorial public (entreprises, offres,
> savoir-faire, contenus) va en **CPT + taxonomies**.

## Tables dédiées (convention `wp_postelio_*`)

```
wp_postelio_candidate_profiles     wp_postelio_recruiter_profiles
wp_postelio_company_members        wp_postelio_company_follows
wp_postelio_job_alerts             wp_postelio_favorites
wp_postelio_applications           wp_postelio_application_history
wp_postelio_preselection_answers   wp_postelio_recruiter_notes
wp_postelio_cvs                    wp_postelio_documents
wp_postelio_cv_snapshots           wp_postelio_conversations
wp_postelio_messages               wp_postelio_interviews
wp_postelio_notifications          wp_postelio_payments
wp_postelio_invoices               wp_postelio_renewals
wp_postelio_webhook_events         wp_postelio_moderation_reports
wp_postelio_audit_log
```

---

## User
- **Propriétaire :** users. **Stockage :** `wp_users`+meta.
- **Champs :** id, email (unique), password_hash (natif), display_name, role
  (candidate|recruiter|postelio_admin|moderator|support), status
  (active|suspended|deleted), email_verified_at, created_at, last_login_at.
- **Sensible :** email (privé par défaut), password. **Conservation :** tant que compte
  actif ; anonymisation à la suppression (voir RGPD).

## CandidateProfile
- **Propriétaire :** users. **Relation :** 1-1 `user_id`.
- **Champs :** metier (principal), metiers_alt (JSON ≤2), ville, rayon_km, contrat,
  temps_travail, teletravail, salaire_souhaite, niveau_etude, disponibilite,
  dispo_date, statut_recherche (active|ecoute|indispo), statut_visible (bool),
  profile_visibility (recruteurs|candidatees|masque), alternance (JSON),
  mobilite (JSON: permis_b, vehicule, national), a_propos (texte),
  experiences (JSON[]), formations (JSON[]), competences_principales (JSON[] {nom,niveau}),
  competences_complementaires (JSON[]), langues (JSON[]), certifications (JSON[]),
  realisations (JSON[]), liens (JSON), visibility (JSON: email, tel),
  telephone (sensible), photo (bool), blocked_companies (JSON[]), date_maj.
- **Sensible :** telephone, email (via visibility). **Index :** `user_id`, `ville`,
  `metier`, `statut_recherche`.
- **Note :** listes riches en JSON (front déjà structuré ainsi) ; extraction en tables
  filles seulement si la recherche l'exige (V2, voir [SearchProvider](implementation-plan.md#recherche)).

## RecruiterProfile
- **Propriétaire :** users. **Relation :** 1-1 `user_id`, appartient à une `company`.
- **Champs :** prenom, nom, fonction, email_pro, telephone_pro, company_id.
- **Sensible :** email_pro, telephone_pro.

## Company
- **Propriétaire :** companies. **Stockage :** CPT `postelio_company`.
- **Champs :** id, nom, slug, secteur (taxo), activite, ville, adresse, departement,
  site_web, email, telephone, taille, effectif_bucket, initiales, couleur, description,
  valeurs (JSON), avantages (JSON), organisation (JSON), reseaux (JSON),
  **identité légale** (raison_sociale, nom_commercial, forme_juridique, siren, siret,
  tva, adresse_siege, cp_siege, ville_siege, pays, date_creation),
  contact (JSON), verification_status, verified_label, logo (media), photos (media[]).
- **Statut :** verification_status (incomplete|pending|verified|manual_review|rejected|suspended).
- **Sensible :** siren/siret/tva (identité légale). **Index :** slug, secteur, ville,
  verification_status. **Conservation :** tant qu'active.

## CompanyMember
- **Propriétaire :** companies. **Table dédiée.**
- **Champs :** id, company_id, user_id, role_in_company (owner|recruiter), created_at.
- **Contrainte :** unique (company_id, user_id). **Index :** company_id, user_id.

## Follow
- **Propriétaire :** companies. **Table dédiée** (`company_follows`).
- **Champs :** id, user_id (candidat), company_id, created_at.
- **Contrainte :** unique (user_id, company_id). **Index :** user_id, company_id.

## Job
- **Propriétaire :** jobs. **Stockage :** CPT `postelio_job` + taxonomies.
- **Champs :** id, titre, company_id, ville, departement, contrat (taxo),
  duree, temps_travail, salaire, salaire_annuel, teletravail (taxo),
  categorie/secteur (taxo), niveau_etude (taxo), experience (taxo),
  description, resume, missions (JSON), profil (JSON), competences (JSON),
  avantages (JSON), email_reception, questions_preselection (JSON[]),
  processus_recrutement (JSON[]), date_publication, date_expiration, statut.
- **Statut :** draft|pending|published|expiring|expired|renewed|filled|archived|suspended.
- **Index :** company_id, statut, date_expiration, secteur, ville, contrat. Filtres :
  débutant accepté / alternance / stage (via taxo/champs).
- **Conservation :** offres expirées conservées `À VALIDER` (ex. 24 mois).

## Application
- **Propriétaire :** applications. **Table dédiée.**
- **Champs :** id, job_id, candidate_id, company_id, cv_snapshot_id, message,
  statut, date_envoi, derniere_activite, source.
- **Statut :** new|review|shortlisted|interview|selected|rejected|withdrawn
  (workflow → [workflows.md](workflows.md#candidature)).
- **Sensible :** message, tout le dossier. **Index :** job_id, candidate_id, company_id,
  statut, date_envoi. **Contrainte :** unique (job_id, candidate_id) recommandé.
- **Conservation :** `À VALIDER` (ex. 24 mois après clôture, puis anonymisation).

## ApplicationHistory
- **Propriétaire :** applications. **Table dédiée** (append-only).
- **Champs :** id, application_id, from_status, to_status, actor_id, actor_role,
  label, created_at. **Index :** application_id, created_at.

## PreselectionAnswer
- **Propriétaire :** applications. **Table dédiée.**
- **Champs :** id, application_id, question (snapshot), type (oui_non|texte|nombre),
  answer, created_at. **Index :** application_id.

## RecruiterNote (privée)
- **Propriétaire :** applications. **Table dédiée.**
- **Champs :** id, application_id, author_id, body, updated_at.
- **Sensible : NE JAMAIS exposer au candidat.** Lecture : recruteurs de la company.

## CV & CVSnapshot
- **Propriétaire :** files. **Table dédiée** + fichier protégé.
- **CV :** id, candidate_id, name, file_path (hors webroot), mime, size, principal (bool),
  created_at, updated_at. **Contrainte :** un seul `principal` par candidat.
- **CVSnapshot :** id, cv_id, application_id, name, file_path (copie immuable), created_at.
  → **Snapshot** : une candidature référence la version du CV **au moment de l'envoi**
  (voir [workflows.md](workflows.md#snapshot-cv)). **Index :** candidate_id ; application_id.
- **Sensible : fort.** Accès contrôlé, jamais d'URL publique ([security.md](security.md#fichiers)).
- **Conservation :** CV vivant tant que compte actif ; snapshot lié à la conservation
  de la candidature.

## Document
- **Propriétaire :** files. **Table dédiée.** id, candidate_id, type
  (lettre_motivation|portfolio), name, file_path, mime, size, created_at.

## Favorite
- **Propriétaire :** jobs. **Table dédiée.** id, user_id, job_id, created_at.
  **Contrainte :** unique (user_id, job_id). **Index :** user_id.
  > **Note d'audit (BUG-01 corrigé côté front) :** clé unifiée `ss_candidate_favorites`.
  > Le back-office matérialise cette unicité.

## JobAlert
- **Propriétaire :** jobs. **Table dédiée.** id, user_id, metier, ville, rayon,
  contrat, niveau_etude, experience, salaire_min, date_pub, teletravail (bool),
  frequence (immediate|quotidienne|hebdomadaire), active (bool), created_at, last_run_at.
  **Requis minimum :** metier + ville. **Index :** user_id, active.

## Interview
- **Propriétaire :** interviews. **Table dédiée.**
- **Champs :** id, application_id, job_id, candidate_id, company_id, date, heure, duree,
  format (visio|sur_place|telephone), lien, adresse, contact, instructions, telephone,
  statut, creneau_propose (JSON), created_at.
- **Statut :** proposed|pending_candidate|confirmed|reschedule_requested|rejected|
  cancelled|completed. **Index :** application_id, candidate_id, company_id, date.

## Conversation
- **Propriétaire :** messaging. **Table dédiée.**
- **Champs :** id, application_id (nullable), candidate_id, recruiter_id, company_id,
  poste, statut_context, created_at, last_message_at.
- **Index :** candidate_id, recruiter_id, application_id, last_message_at.

## Message
- **Propriétaire :** messaging. **Table dédiée.**
- **Champs :** id, conversation_id, sender_id, sender_role, type (user|system), body,
  read_at, moderation_state (allowed|review|blocked), reported (bool), created_at.
- **Sensible :** contenu. **Index :** conversation_id, created_at, read_at.

## Notification
- **Propriétaire :** notifications. **Table dédiée.**
- **Champs :** id, user_id, type (event key), title, body, href, read_at, channel
  (in_app|email), created_at. **Index :** user_id, read_at, created_at.
- **Conservation :** purge in-app `À VALIDER` (ex. 90 jours).

## SkillContent / CompanyContent
- **Propriétaire :** skills. **Stockage :** CPT.
- **SkillContent :** id, candidate_id, titre, resume, categorie, competences (JSON),
  etapes (JSON), note, avis, vues, date, moderation_state.
- **CompanyContent :** id, company_id, titre, resume, categorie, corps, date, moderation_state.
- **Statut :** moderation_state (allowed|review|blocked). **Index :** candidate_id/company_id,
  moderation_state, categorie.

## Payment / Invoice / Renewal / WebhookEvent
- **Propriétaire :** billing. **Tables dédiées.**
- **Payment :** id, user_id, company_id, amount (1000 = 10 €), currency (EUR),
  provider (stripe|demo), provider_ref, status (pending|succeeded|failed|refunded),
  purpose (job_renewal), job_id, created_at.
- **Invoice :** id, payment_id, number, amount, issued_at, pdf_path.
- **Renewal :** id, job_id, payment_id, days (30), new_expiration, applied_at.
- **WebhookEvent :** id, provider, provider_event_id (unique), type, payload (JSON),
  received_at, processed_at. **Sensible :** financier ; **immuable/traçable**.

## ModerationReport
- **Propriétaire :** moderation. **Table dédiée.**
- **Champs :** id, resource_type (message|job|profile|skill|company_content),
  resource_id, reporter_id, reason, status (open|allowed|blocked|review), decided_by,
  decided_at, created_at. **Index :** status, resource_type.

## AuditLog
- **Propriétaire :** core. **Table dédiée (append-only).**
- **Champs :** id, actor_id, actor_role, action, resource_type, resource_id, metadata
  (JSON minimal), ip (À VALIDER RGPD), created_at. **Index :** actor_id, resource_type,
  resource_id, created_at. **Détail :** [security.md](security.md#audit-log).

---

## Relations (résumé)
```
Company 1─n CompanyMember n─1 User(recruiter)
Company 1─n Job
Company 1─n CompanyContent
User(candidate) 1─1 CandidateProfile
User(candidate) 1─n CV 1─n CVSnapshot
User(candidate) 1─n Favorite ; 1─n JobAlert ; 1─n Follow ; 1─n SkillContent
Job 1─n Application n─1 User(candidate)
Application 1─1 CVSnapshot ; 1─n ApplicationHistory ; 1─n PreselectionAnswer ;
            1─n RecruiterNote ; 1─n Interview ; 0..1 Conversation
Conversation 1─n Message
User 1─n Notification
Payment 1─1 Invoice ; 1─1 Renewal ─1 Job
```
Détail des relations métier : [workflows.md](workflows.md) §7.

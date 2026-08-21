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

## Identifiants

**Décision V1 (D2) :** double identifiant.

- **ID interne numérique** — clé primaire `BIGINT UNSIGNED AUTO_INCREMENT` de chaque
  table dédiée (et `ID` natif WordPress pour users/CPT). Sert aux jointures et index ;
  **jamais** de logique de sécurité fondée sur la seule connaissance de l'ID.
- **UUID public** — colonne `public_uuid` (UUID v4, unique, indexée) sur les
  **ressources sensibles ou exposées via l'API** lorsque pertinent : candidatures,
  fichiers/CV & snapshots, messages, entretiens, paiements/factures. C'est **l'UUID**
  qui apparaît dans les URLs/API publiques (`/applications/{uuid}`), pas l'ID interne
  (évite l'énumération et la fuite de volumétrie).
- Ressources **éditoriales publiques** (offres, entreprises, savoir-faire en CPT) :
  l'ID/slug WordPress natif suffit (déjà public par nature) ; UUID non requis.

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
- **Propriétaire :** companies. **Stockage :** CPT `postelio_company` (implémenté Lot 03).
- **Identifiants :** `id` interne (post ID, jamais exposé) + **`public_uuid`** (UUID v4
  serveur, immuable, exposé via l'API — D2 ; unicité **applicative**, voir
  [#identifiants](#identifiants)).
- **Trois zones de champs** (implémentation Lot 03) :
  - **éditoriale** (`pst_editorial`, modifiable) : secteur, activite, ville, effectif,
    adresse, telephone, email, site, avantages (JSON), valeurs (JSON), org (JSON :
    teletravail/horaires/tags/precisions), reseaux (JSON), logo (image à la une), has_photo ;
    nom = `post_title`, présentation = `post_content`.
  - **légale déclarée** (`pst_legal_declared`, saisie recruteur avant vérification) :
    raison_sociale, nom_commercial, forme_juridique, siren, siret, tva, **naf_ape**
    (code NAF/APE), adresse_siege, cp_siege, ville_siege, pays, date_creation.
  - **légale vérifiée** (`pst_legal_verified`, **figée** par la vérification, non
    modifiable par le recruteur).
- **Statut :** `verification_status` ∈ `unverified|pending|manual_review|verified|rejected|suspended`
  (machine à états canonique, voir [workflows.md](workflows.md#entreprise-company--vérification)).
  Traçabilité dans `pst_verification` (provider, requested_at/verified_at, verified_legal_id,
  reviewer_id, motif interne). `conflict` **n'est pas** un statut (motif `duplicate_siren`
  de `manual_review`).
- **Sensible :** siren/siret/tva ; `reviewer_id`/`motif` = **admin uniquement** (jamais public).
- **Index :** `pst_uuid`, `pst_siren` (anti-doublon), `pst_verification_status`.

## CompanyMember
- **Propriétaire :** companies. **Table dédiée** `wp_postelio_company_members` (implémenté Lot 03).
- **Champs :** id, company_id, user_id, role_in_company (owner|recruiter), created_at.
- **Contrainte :** unique (company_id, user_id). **Index :** company_id, user_id.
- **Décision V1 :** un recruteur appartient à **une seule** entreprise active (création
  refusée s'il est déjà rattaché) ; une entreprise peut avoir **plusieurs** recruteurs.
  Le schéma n-n reste compatible avec une multi-appartenance future. Invitations /
  changements de rôle / retraits : **hors Lot 03**.

## Follow
- **Propriétaire :** companies. **Table dédiée** (`company_follows`).
- **Champs :** id, user_id (candidat), company_id, created_at.
- **Contrainte :** unique (user_id, company_id). **Index :** user_id, company_id.

## Job
- **Propriétaire :** jobs. **Stockage :** CPT `postelio_job` + meta (implémenté Lot 04 ;
  taxonomies déferrées — filtres en meta V1, voir limite ci-dessous).
- **Identifiants :** `id` interne (post ID, jamais exposé) + **`public_uuid`** (UUID v4
  serveur, immuable — D2).
- **Entreprise (dénormalisée) :** `company_id` (relation interne durable),
  `company_uuid` (référence publique), `company_name` (**cache/repli uniquement** — le
  presenter lit toujours le nom courant via `CompanyDirectory` ; le cache est
  rafraîchi sur `company.updated`). `company_id`/`company_uuid` ne changent jamais.
- **Champs :** titre (post_title), description (post_content), ville, departement,
  contrat, duree, temps_travail, salaire, salaire_annuel, teletravail, categorie(+label),
  niveau_etude(+label), experience(+label), missions (JSON), profil (JSON),
  competences (JSON), avantages (JSON), processus (JSON), email_reception (privé),
  **questions_preselection (JSON[], structure ci-dessous)**, date_publication,
  date_expiration (dates **UTC `Y-m-d`**), statut, **revision** (version métier,
  incrémentée à chaque édition), **renewal_count**, **renewed_at**.
- **Statut (V1, 7) :** `draft|published|expiring|expired|filled|archived|suspended`.
  `pending` (modération) et `renewed` (état) **retirés** — voir
  [workflows.md](workflows.md#offre-job). Visibles publiquement : `published`, `expiring`.
- **Index :** `pst_company_id`, `pst_status`, `pst_date_publication`, `pst_uuid`,
  + champs filtrables (`pst_ville/contrat/categorie/teletravail/niveau_etude/experience/
  salaire_annuel/alternance/stage/debutant`).
- **Limite de scalabilité (assumée V1) :** filtres/recherche en **postmeta**
  (`WP_Meta_Query`). Les endpoints publics passent par l'abstraction
  `JobSearchProvider` (filtre `postelio/jobs/search_provider`) pour permettre un
  remplacement futur par **postelio-search** (table/index dédié, Meilisearch/Typesense)
  **sans casser l'API**.
- **Conservation :** offres expirées conservées `À VALIDER` (ex. 24 mois).

### `questions_preselection` — structure stable (contrat pour postelio-applications)
Chaque question est un objet **normalisé à l'enregistrement** :
`{ id (slug stable), label, type (oui_non|texte|nombre|choix), required (bool),
critere (indispensable|souhaite|null) }`. `postelio-applications` enregistrera les
réponses candidat **contre ces `id`** — jamais contre une structure improvisée.

### Snapshot de candidature (préparé, à implémenter au Lot applications)
Lorsqu'un candidat postule, la candidature doit **figer** de quoi savoir à quelle
version de l'offre il a répondu, **indépendamment** des modifications ultérieures :
`job_uuid`, **`job_revision`** (version métier au moment de la candidature), `titre`,
entreprise (`company_uuid` + `company_name` au moment T), et les
`questions_preselection` utilisées. Les **révisions WordPress ne suffisent pas** (elles
ne capturent pas fiablement les meta) → on retient une **version métier**
(`pst_revision`) + un snapshot applicatif côté `postelio-applications`.

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

## Fichiers (CV & documents) — `wp_postelio_files` (implémenté Lot 06)
- **Propriétaire :** files. **Table unifiée** `wp_postelio_files` + stockage privé.
  **Décision de consolidation** : au lieu de tables séparées cvs/documents/cv_snapshots,
  une seule table avec `type` (cv|document) et **versions immuables**. Le « snapshot CV »
  d'une candidature = **référence immuable** à une ligne (l'UUID du fichier), jamais une
  copie physique : un nouvel upload crée une NOUVELLE ressource, l'ancienne n'est jamais
  remplacée. `postelio-applications` stocke `cv_reference = files.public_uuid`.
- **Champs :** id (interne), **public_uuid** (D2, seul exposé), owner_user_id, type,
  storage_provider, storage_key (interne), original_name (affichage), stored_name
  (aléatoire), mime_type, size_bytes, sha256, status
  (uploaded|ready|quarantined|archived|deleted), is_primary (un seul par owner/type),
  created_at, updated_at, deleted_at. **Index :** public_uuid (unique), (owner,type),
  status, sha256.
- **CV V1 :** PDF, 10 Mo (D3), MIME réel + signature `%PDF-` vérifiés.
- **Sensible : fort.** Stockage privé hors chemins publics (+ `.htaccess` deny), jamais
  d'URL disque ; accès uniquement via `GET /files/{uuid}/view|download` (proprio ou
  recruteur autorisé). Voir [security.md](security.md#5-fichiers-cv--documents).
- **Suppression :** logique — `deleted` si non référencé, **`archived`** (conservé) si
  encore référencé par une candidature.
- **Conservation :** CV actif tant que compte actif ; pièces référencées conservées.
  Durées `À VALIDER`.

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
- **Champs :** id, `public_uuid`, conversation_id, sender_id, sender_role, type
  (user|system), body, read_at, moderation_state (allowed|review|blocked), reported
  (bool), `deleted_at` (soft-delete), created_at.
- **Immuabilité (D6) :** le `body` est **immuable** une fois créé (aucune édition en V1).
  La disparition d'un message = **soft-delete** (`deleted_at`) ou modération ; la ligne
  est **conservée** (audit). Voir [workflows.md](workflows.md#message).
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

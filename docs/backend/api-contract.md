# Postelio — Contrat d'API REST

Base : `/wp-json/postelio/v1/`. Consommée par le **web** et la future app **Tauri**.
Ressources au pluriel. Contrats figés dans ce lot (pas d'implémentation).

## 1. Versionnement

- Namespace versionné dans l'URL : `postelio/v1`, puis `postelio/v2` pour les ruptures.
- `v1` maintenu tant que le web **et** Tauri en dépendent. Changements **non cassants**
  (ajout de champ optionnel) restent en `v1` ; toute **rupture** (suppression/renommage
  de champ, changement de sémantique) impose `v2`.
- En-tête recommandé : `Accept: application/json` ; le serveur peut renvoyer
  `X-Postelio-Api-Version`.

## 2. Format standard des réponses

Succès :
```json
{ "data": { }, "meta": { "pagination": { "page": 1, "per_page": 20, "total": 42 } } }
```
Erreur :
```json
{ "error": { "code": "forbidden", "message": "Action non autorisée.", "details": {} } }
```

### Codes d'erreur internes (stables)
| code | HTTP | Sens |
|---|---|---|
| `unauthenticated` | 401 | Pas de session/token valide. |
| `forbidden` | 403 | Authentifié mais capability/rôle insuffisant. |
| `not_found` | 404 | Ressource inexistante ou non visible. |
| `validation_error` | 422 | Champs invalides (`details` = liste champ→raison). |
| `invalid_transition` | 409 | Transition de statut non autorisée. |
| `conflict` | 409 | Doublon / contrainte d'unicité. |
| `rate_limited` | 429 | Trop de requêtes. |
| `payload_too_large` | 413 | Fichier trop volumineux. |
| `unsupported_media_type` | 415 | MIME non autorisé. |
| `payment_required` | 402 | Action nécessitant un paiement (renouvellement). |
| `moderation_blocked` | 422 | Contenu bloqué par la modération préventive (Lot 11 ; message **générique**, jamais la règle ni le `reason_code`). |
| `server_error` | 500 | Erreur interne (loggée). |

## 3. Authentification
- **Web** : cookies WordPress + **nonce** REST (`X-WP-Nonce`) pour les mutations.
- **Tauri/app** : **token applicatif** (Bearer, ex. JWT ou Application Passwords) émis
  par `/auth`. Voir [security.md](security.md#authentification).

## 4. Conventions communes
- Pagination : `?page`, `?per_page` (défaut 20, max 100). Tri : `?sort=-created_at`.
- Filtres : `?status=`, `?q=`, `?ville=`, etc. (validés serveur).
- Dates : ISO 8601 UTC.
- **Identifiants (D2) :** en base, IDs **numériques internes**. Dans les URLs/API, le
  `{id}` des **ressources sensibles/exposées** (candidatures, fichiers, messages,
  entretiens, paiements) est l'**UUID public** (`public_uuid`), pas l'ID interne. Les
  ressources éditoriales publiques (offres, entreprises, savoir-faire) utilisent
  l'ID/slug WordPress natif. Voir [data-model.md](data-model.md#identifiants).

## 5. Endpoints principaux

> Notation : `M` méthode · `R` rôle requis · `→` réponse · `err` erreurs typiques.

### Auth & moi — `postelio-users`
- `POST /auth` — login (email+mot de passe / provider). → `{ token, user }`. err: `unauthenticated`, `validation_error`.
- `POST /auth/refresh` · `POST /auth/logout`.
- `GET /me` — profil de l'utilisateur courant (identité + rôle). R: authentifié.
- `GET/PUT /me/settings` — préférences (notifications, langue, visibilité coordonnées).
- `GET /me/export` — export RGPD. `DELETE /me` — suppression/anonymisation.

### Candidats — `postelio-users`
- `GET /candidates/{id}` — **vue recruteur** (respecte visibilité ; jamais email/tel/notes si non autorisé). R: recruiter/admin.
- `GET/PUT /candidates/me/profile` — profil complet (self). R: candidate.

### Entreprises — `postelio-companies`
> Identification publique par **UUID** (D2) — jamais l'ID interne. Champs : identité
> découpée en `editorial` / `legal_declared` / `legal_verified` (figé).
- `GET /companies` (public, liste ; suspendues masquées) · `GET /companies/{uuid}` (public).
- `POST /companies` — crée SON entreprise (owner). R: recruiter **+ `pst_email_verified`**. err: `conflict` (déjà rattaché / SIREN en doublon), `validation_error`.
- `GET /companies/me` — entreprise du recruteur (vue propriétaire + complétion + statut). R: recruiter.
- `PUT /companies/me` — mise à jour. R: recruiter **+ `pst_email_verified`**. Le **légal est verrouillé** si `verified` (err: `forbidden`).
- `POST /companies/me/verification` — demande de vérification. R: recruiter (`pst_request_company_verification`) **+ `pst_email_verified`**. err: `invalid_transition`, `validation_error` (SIREN/SIRET).
- `GET /companies/me/verification` — statut de vérification. R: recruiter.
- `POST /companies/{uuid}/verification/decision` — décision `verified|rejected|manual_review|suspended` (+ `motif`). R: **admin** (`pst_verify_company`). err: `invalid_transition`, `not_found`. Le recruteur **ne peut jamais** se déclarer `verified`.
- `POST/DELETE /companies/{uuid}/follow` — R: candidate. *(prévu ; hors Lot 03.)*
- Contrat inter-plugins : `CompanyVerification::can_publish_jobs($company_id)` (+ filtres `postelio/company/*`) — utilisé par `postelio-jobs` sans lire l'implémentation interne.

### Offres — `postelio-jobs` (identification par **UUID**, D2)
- `GET /jobs` (public, filtres : q, ville, contrat, catégorie, télétravail, niveau,
  expérience, salaire_min, débutant/alternance/stage) · `GET /jobs/{uuid}` (public :
  `published`/`expiring` seulement).
- `GET /jobs/me` — offres de l'entreprise du recruteur (tous statuts). R: recruiter.
- `POST /jobs` — crée un **brouillon** (autorisé sans entreprise vérifiée — D1).
  R: recruiter (`pst_edit_own_company_jobs`) **+ `pst_email_verified`** + membre.
- `PUT /jobs/{uuid}` — édite. R: idem + membre.
- `POST /jobs/{uuid}/publish` — publication publique. R: `pst_publish_job` **+
  `pst_email_verified`** ; **exige l'entreprise `verified`** (via
  `CompanyVerification::can_publish_jobs()`). err: `forbidden`, `invalid_transition`.
- `POST /jobs/{uuid}/fill|archive` — R: `pst_edit_own_company_jobs`.
- `POST /jobs/{uuid}/duplicate` — nouveau brouillon. R: `pst_duplicate_job` + `pst_email_verified`.
- `POST /jobs/{uuid}/status` — admin (`pst_manage_all_jobs`) : `suspend|published`.
- États V1 : `draft|published|expiring|expired|filled|archived|suspended` (pas de
  `pending` ni d'état `renewed` — voir [workflows.md](workflows.md#offre-job)). Seuls
  `published`/`expiring` sont visibles publiquement.
- Expiration automatique (cron, dates **UTC**) : `published → expiring` (J‑7) → `expired`.
- **Renouvellement** (`expiring|expired → published`, événement `job.renewed`) : via le
  contrat **`Postelio\Jobs\Api\JobLifecycle::renew_after_payment()`** appelé par
  **postelio-billing** après paiement (**implémenté Lot 12** ; le checkout payant est exposé
  par billing — voir [#facturation--postelio-billing-implémenté-lot-12](#facturation--postelio-billing-implémenté-lot-12) —
  jamais un endpoint de `postelio-jobs`).
- Favoris / alertes candidat (`/me/favorites`, `/me/alerts`) : **hors Lot 04.**

### Candidatures — `postelio-applications` (identification par **UUID**, D2)
> Implémenté Lot 05. Snapshot d'offre figé (`job_revision`) ; réponses de présélection
> validées **contre le snapshot serveur** ; règle V1 **1 candidat = 1 candidature/offre**
> (contrainte unique en base). Non-divulgation : hors périmètre → **404**.
- `POST /jobs/{job_uuid}/applications` — postuler (message?, cv_reference?, screening_answers{id:val}).
  R: candidate **+ `pst_email_verified`**. err: `conflict` (déjà postulé), `validation_error`
  (présélection), `invalid_transition` (offre non candidateable). *(Le snapshot CV immuable
  réel viendra de `postelio-files` ; `cv_reference` opaque en attendant.)*
- `GET /me/applications` (filtre statut) · `GET /me/applications/{uuid}` (détail + timeline). R: candidate (propriétaire).
- `POST /me/applications/{uuid}/withdraw` — R: candidate. err: `invalid_transition`.
- `GET /companies/me/applications` (filtres `job`={uuid}, `status`) · `GET /companies/me/applications/{uuid}`. R: recruiter (membre).
- `POST /companies/me/applications/{uuid}/status` — transition (voir [workflows.md](workflows.md#candidature-application)). R: recruiter **+ `pst_email_verified`**. err: `invalid_transition`. Motif de refus **interne** (jamais exposé au candidat).
- `GET/POST /companies/me/applications/{uuid}/notes` — notes privées. R: `pst_manage_recruiter_notes`.
- Contrat sortant : `Postelio\Applications\Api\ApplicationDirectory` (`context`, `belongs_to_company`, `move_to_interview`) pour `postelio-interviews`/`messaging`.

### Fichiers / CV — `postelio-files` (identification par **UUID**, D2 ; implémenté Lot 06)
> Stockage **privé** (hors chemins publics) derrière `StorageProvider` ; CV V1 = PDF
> 10 Mo (D3), MIME+signature vérifiés ; versions immuables (snapshot par référence).
- `POST /me/files/cv` — upload (multipart `file`). R: `pst_manage_own_cv` **+ `pst_email_verified`**. err: `unsupported_media_type`, `payload_too_large`, `validation_error`.
- `GET /me/files/cv` · `GET /me/files/cv/{uuid}` — R: `pst_manage_own_cv` (propriétaire).
- `POST /me/files/cv/{uuid}/primary` — définir principal. R: `pst_manage_own_cv`.
- `DELETE /me/files/cv/{uuid}` — retrait logique (archivé si référencé). R: `pst_manage_own_cv`.
- `GET /files/{uuid}/view` (inline) · `GET /files/{uuid}/download` — **streaming sécurisé**
  (pas d'URL disque ; `application/pdf` + `nosniff` + CSP sandbox ; HTTP Range). Accès :
  **propriétaire** OU recruteur autorisé (candidature de son entreprise référençant ce CV,
  via `postelio/files/authorize_download`). Hors périmètre → **404**.
- Contrat pour applications : `\Postelio\Files\Api\FileCvContract::usable_for_application()`.

### Messagerie — `postelio-messaging` (identification par **UUID**, D2 ; implémenté Lot 07)
> Messagerie **contextualisée par une candidature** : le recruteur ne peut écrire qu'à un
> candidat via une candidature d'une offre de **son** entreprise (jamais de contact
> arbitraire). **1 conversation par candidature** (unique en base). Messages **immuables**
> (D6, texte seul, XSS neutralisé). Lecture autorisée même e-mail non vérifié ; **envoi**
> exige `pst_email_verified`. Hors périmètre → **404** (non-divulgation).
- `POST /companies/me/applications/{application_uuid}/conversation` — ouvrir/récupérer la
  conversation d'une candidature (idempotent). R: `pst_send_message`, recruteur membre. 404 sinon.
- `GET /me/conversations` — liste (candidat ↦ ses conversations ; recruteur ↦ celles de sa
  company), triée `last_message_at` DESC, `unread_count` par conversation. R: `pst_send_message`.
- `GET /me/conversations/{uuid}` — détail (interlocuteur, statut, `unread_count`). R: participant.
- `GET /me/conversations/{uuid}/messages?before={message_uuid}&limit=` — historique, ordre
  chronologique ASC déterministe `(created_at,id)`, **pagination curseur** (`before`=UUID
  message, `meta.has_more`). R: participant.
- `POST /me/conversations/{uuid}/messages` — envoyer (`{body}`). R: `pst_send_message` **+
  `pst_email_verified`**. err: `validation_error` (vide/trop long), `invalid_transition`
  (conversation fermée → 409), `rate_limited`.
- `POST /me/conversations/{uuid}/read` — marquer lu (curseur monotone `last_read_message_id`).
- `POST /me/conversations/{uuid}/close` — fermeture **manuelle**, réservée au
  **propriétaire (`owner`) de l'entreprise** ou modérateur (`pst_moderate_content`) ; un
  recruteur membre lambda ou le candidat → **403**. *(Fermeture **automatique** en plus si
  la candidature devient `rejected`/`withdrawn` ; `selected` ne ferme pas.)*
- Contrat sortant : `\Postelio\Messaging\Api\MessagingDirectory` (`unread_count`,
  `get_conversation_context`, `can_message`, `close_conversation`).

### Entretiens — `postelio-interviews` (identification par **UUID**, D2 ; implémenté Lot 08)
> Entretien lié à une **candidature** (jamais arbitraire) ; types `video|onsite|phone` ;
> dates ISO 8601 stockées **UTC** + fuseau métier ; instructions texte seul (XSS inerte) ;
> hors périmètre → **404**. Lecture sans e-mail vérifié ; actions sensibles → `pst_email_verified`.
- Candidat : `GET /me/interviews` (filtres `status`,`from`,`to`,`application_uuid`,
  pagination) · `GET /me/interviews/{uuid}` (avec historique) ·
  `POST /me/interviews/{uuid}/confirm` · `.../decline` · `.../reschedule` (propose un autre
  créneau `{scheduled_at, message?}`) · `.../cancel` (**annuler un entretien confirmé**,
  `{reason?}`). R candidat : `pst_view_own_interviews` (lecture),
  `pst_confirm_interview`/`pst_reschedule_interview` **+ `pst_email_verified`**,
  `pst_reject_interview` (decline **et** cancel ; cancel exige aussi `pst_email_verified`).
- Recruteur : `GET /companies/me/interviews[/{uuid}]` (R `pst_manage_company_interviews`) ;
  `POST /companies/me/applications/{application_uuid}/interviews` (proposer ;
  `pst_propose_interview` + vérifié ; `409` si candidature terminale, si offre
  `filled`/`archived`/`suspended`, ou si **doublon actif identique** — mais **plusieurs
  entretiens successifs autorisés**) ; `PUT /companies/me/interviews/{uuid}` (modifier) ;
  `POST .../{uuid}/accept-reschedule` ; `POST .../{uuid}/cancel` ;
  `POST .../{uuid}/complete` (**manuel** ; aucun passage automatique).
- Contrat sortant : `\Postelio\Interviews\Api\InterviewDirectory` (`get_context`,
  `upcoming_count`, `has_active_for_application`, `history`).

### Notifications — `postelio-notifications` (implémenté Lot 09)
> In-app + e-mails transactionnels pilotés par les événements. UUID uniquement ; ownership
> strict (un utilisateur ne voit que ses notifications). Action **structurée**
> (`action_type`+`resource_uuid`), jamais d'URL absolue. Cloche = notifications ≠ compteur
> messagerie.
- `GET /me/notifications` — paginé ; filtres `?unread=1&type=…`. R: authentifié.
- `GET /me/notifications/unread-count` → `{ count }` (compteur cloche, endpoint dédié).
- `POST /me/notifications/{uuid}/read` · `POST /me/notifications/read-all`.
- `GET /me/notification-preferences` · `PUT /me/notification-preferences` — catégories du
  rôle (transactionnel vs marketing) ; le serveur ignore les catégories hors rôle et les
  types obligatoires.
- Contrat sortant : `\Postelio\Notifications\Api\NotificationDirectory` (`unread_count`,
  `recent`). E-mails : jamais d'action sensible par GET, jamais de token en URL.

### Facturation — `postelio-billing` (implémenté Lot 12)
> Renouvellement **payant** d'offre (10 € TTC / 30 j) via **Stripe Checkout hosted**. Billing
> **paie puis délègue** l'effet à `JobLifecycle` (jamais d'écriture de statut d'offre). Le
> **webhook signé** est la **seule source de vérité** (le `success_url` ne confirme jamais).
> UUID uniquement (jamais d'ID SQL ni de secret provider) ; **non-divulgation** inter-entreprise
> → **404**. Montants en **cents entiers**.
- `POST /billing/checkout` — crée une commande + une session Checkout. Body **`{product_code,
  resource_type, resource_uuid}` UNIQUEMENT** (montant/devise/durée envoyés par le front sont
  **ignorés**). R: `pst_pay_renewal` **+ `pst_email_verified`** ; conditions : authentifié,
  actif, **membre** (owner OU recruteur) de l'entreprise propriétaire de l'offre, entreprise
  **`verified` & non suspendue**, `JobLifecycle::can_renew()`. → `{order_uuid, checkout_url,
  expires_at}`. err: `forbidden` (candidat → 403), `not_found` (offre inconnue / autre
  entreprise → 404), `payment_required`/`invalid_transition` (non renouvelable),
  `validation_error`.
- `GET /billing/orders` — historique paginé, **scope entreprise** (source du dashboard).
  R: `pst_pay_renewal` **+ `pst_email_verified`**.
- `GET /billing/orders/{uuid}` — statut synthétique (`status`/`payment_status`/
  `fulfillment_status`/`product`/`resource`/`amount`/`currency`/`receipt_available`) ;
  inter-entreprise → **404** ; jamais d'ID SQL ni de secret provider.
- `POST /billing/webhook/stripe` — **PUBLIC**, cryptographiquement **signé** (aucun
  nonce/session/Bearer) : corps **brut** + signature + tolérance de timestamp ; **idempotent**
  via le magasin d'événements. Signature invalide → **400** ; événement bien signé → **200**.
  V1 : `checkout.session.{completed,async_payment_succeeded,async_payment_failed,expired}`,
  `charge.refunded`, `charge.dispute.created` (les `payment_intent.*` / `invoice.*` sont ignorés).
- `GET /billing/admin/orders` · `GET /billing/admin/orders/{uuid}` ·
  `POST /billing/admin/orders/{uuid}/retry-fulfillment` · `GET /billing/health`. R: **admin**
  (`pst_manage_billing`). Health : `status/provider/mode/configured/webhook_configured/
  last_webhook_at/last_successful_payment_at/failed_fulfillment_count/seller_configured/
  invoice_legal_ready` — **aucun secret**.
- **Reçu vs facture :** V1 expose le **reçu Stripe** (`receipt_url`), ce **n'est pas** une
  facture ; facture légale numérotée = **phase ultérieure** (`invoice_legal_ready=false`).

### Savoir-faire & Avis — `postelio-skills` (implémenté Lot 13)
> **Contenu éditorial public** modéré **en amont** (comme les offres). Exposé **uniquement**
> via l'API (aucun rendu WordPress ; SEO livré comme **contrat**). Identification par **UUID**
> (`pst_uuid`) ; **jamais** d'ID SQL, le `slug` n'est pas une clé métier. Auteur/entreprise
> **dérivés serveur** (body `author_user_id`/`company_id` **ignorés** — anti-spoofing).
> Non-divulgation inter-utilisateur/entreprise → **404**. Image = Media Library WP.
- `GET /skills` — **public** (`published` **et** visible = `!mod_hidden && !susp_hidden`).
  Filtres : `q`, `category`, `tag`, `author_type`, `company`, `sort=recent|oldest`,
  `page`/`per_page`. → liste (byline publique + entreprise publique + catégorie/tags + image).
- `GET /skills/{uuid}` — **public** ; **404** si non public. → contenu + **byline auteur
  publique** + entreprise publique + catégorie/tags + image/galerie + dates + **bloc `seo`**.
  Jamais d'ID SQL ni de donnée privée.
- `GET /me/skills` — les savoir-faire de l'utilisateur (tous statuts). R: authentifié.
- `POST /me/skills` — crée un **brouillon** (requis : `title`, `content`, `category` ; `as_company`
  pour un recruteur au nom de son entreprise). R: `pst_publish_own_skill`/`pst_manage_own_skill`.
- `GET /me/skills/{uuid}` · `PUT /me/skills/{uuid}` — owner-aware ; hors périmètre → **404**.
- `POST /me/skills/{uuid}/publish` — passerelle `ModerationGateway::evaluate`. R: **+
  `pst_email_verified`**. err: `moderation_blocked` (**422**, générique), `invalid_transition`.
- `POST /me/skills/{uuid}/archive` — R: auteur (owner/entreprise).
- `GET /skills/{uuid}/comments` — **public** (commentaires visibles). ·
  `POST /skills/{uuid}/comments` — commenter (modéré **PRE-insert**). R: `pst_comment_skill`
  **+ `pst_email_verified`**. err: `moderation_blocked`, `rate_limited`, `validation_error`.
- **Bloc `seo`** (dans la vue publique, non rendu par WP en V1) : `{ slug, title,
  meta_description, canonical (null V1), author, date_published, date_modified, noindex,
  in_sitemap }` — `noindex=false` **uniquement** si `published` **et** visible.
- **Signalements** : via `POST /moderation/reports` (`resource_type` ∈ `skill`, `skill_comment`).
- Contrats sortants : `\Postelio\Skills\Api\SkillDirectory`, `\Postelio\Skills\Api\SkillModeration`.

### Offres externes — `postelio-job-sources` (implémenté Lot 10)
> Les offres externes sont **fusionnées** dans les endpoints `/jobs` existants (pas de route
> dédiée candidat). UUID Postelio uniquement ; `external_id` jamais exposé.
- `GET /jobs?source=all|postelio|partners` — recherche **unifiée** (natif ⊕ externe), défaut
  `all`. Chaque item porte `source{type,key,label,external,attribution}` et
  `application{mode}`. Offre externe : `application.mode=external_redirect`.
  **`meta.pagination.total_is_exact`** (bool) : la fusion V1 est exacte dans la fenêtre
  `merge_cap` ; au-delà, `total` reste la somme exacte mais l'ordre/pagination profonde
  peut être approximatif (`false`). Défaut `true` (moteur natif).
- `GET /jobs/{uuid}` — détail natif OU externe. **404** si la source est désactivée/non
  configurée ou l'offre masquée (indisponibilité réversible) ; **410 Gone** si l'offre est
  **removed** (retirée à la source + anonymisée). Externe : `seo{noindex,canonical,
  in_sitemap}` + `attribution{notice,licence_url,source_updated_at,logo_url}`.
- `GET /jobs/{uuid}/apply-redirect` — offre externe active+disponible → **302** vers l'URL
  officielle/partenaire (revalidée) ; native → 404 ; source désactivée/masquée → **404** ;
  removed → **410** ; URL invalide / mauvais mode → 404/400. Émet
  `external_job.apply_redirected` (jamais une candidature). **Aucune** candidature Postelio
  sur une offre externe (`POST /jobs/{uuid}/applications` → **409**).
- `GET /job-sources/health` — admin (`pst_manage_platform`) : état par provider (disponible,
  offres actives, dernière sync/succès, dernière erreur ; aucun secret).

### Modération — `postelio-moderation` (implémenté Lot 11)
> Modération centralisée : **signalements** (réactif → **cas** regroupés par ressource) +
> **passerelle préventive** (`postelio/moderation/evaluate`, appelée par messagerie/offres).
> UUID v4 uniquement (jamais d'ID SQL) ; **non-divulgation** systématique → **404** ;
> `reporter_user_id` reste interne ; message de blocage **générique** (n'expose jamais la
> règle ni les `reason_code`). Moteur de règles **local** (aucun provider externe en V1).
- `POST /moderation/reports` — signaler (`pst_report_content`) : `{resource_type,
  resource_uuid, reason_code, description?}`. Ressource inconnue/inaccessible → **404**.
  Réponse **générique** (statut) + flag `duplicate` (dédup). err: `validation_error`, `rate_limited`.
- `GET /me/moderation/reports` — **ses** signalements (`pst_report_content`) ; statuts
  génériques `received|under_review|resolved` ; jamais d'ID SQL, de note interne ni de tiers.
- `GET /moderation/cases` — file paginée + filtres `status|priority|resource_type`.
  R: `pst_view_moderation_queue`.
- `GET /moderation/cases/{uuid}` — détail + **historique** des événements. R:
  `pst_view_moderation_queue`. Notes internes visibles **ici uniquement** (file admin).
- `POST /moderation/cases/{uuid}/assign` — assigner. R: `pst_decide_report`.
- `POST /moderation/cases/{uuid}/decision` — décider (`pst_moderate_content`) : `{action,
  reason_codes?, note?, resolve?, target?}`. `target{type,uuid}` optionnel permet à un
  **admin** de suspendre l'auteur (par UUID public) depuis un cas de contenu. Les actions
  **admin** (`suspend_*`) sont **re-vérifiées par capability** dans l'exécuteur d'action.
  err: `forbidden`, `invalid_transition`.
- `POST /moderation/cases/{uuid}/note` — note interne. R: `pst_moderate_content`.
- `GET /moderation/health` — état (`provider: local_only`). R: `pst_view_moderation_queue`.
- **Actions déléguées** (via contrats propriétaires, jamais d'écriture directe) :
  `hide/unhide`→JobSources, `close_conversation`→Messaging, `suspend_job/unsuspend_job`→Jobs,
  `suspend_company/unsuspend_company`→Companies (+ `CompanySuspensionSync`),
  `suspend_user/unsuspend_user`→Users (réversible). Modo : `no_action/hide/unhide/
  close_conversation/warning/dismiss/escalate` ; suspensions = **admin**.

### Core
- `GET /health` · `GET /version` · `GET /config` (public non sensible).

## 6. Idempotence & sécurité
- Mutations exigent nonce (web) ou Bearer (app). Webhooks : signature + idempotence.
- Toute mutation de statut passe par le moteur de transitions ([workflows.md](workflows.md)).
- Détails sécurité : [security.md](security.md).

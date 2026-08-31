# Postelio — Plugins backend

Chaque plugin : **responsabilité**, **dépendances**, **données possédées**,
**endpoints exposés**, **événements émis**, **événements écoutés**, **interactions**.
Règle absolue : aucun *god plugin*, propriété unique de la donnée, communication
inter-plugins **uniquement** via le bus d'événements du core ou l'API REST.

Contrats détaillés : [api-contract.md](api-contract.md), [events.md](events.md),
[data-model.md](data-model.md).

---

## postelio-core

**Responsabilité (transversal uniquement).**
- Version globale de la plateforme + registry des plugins Postelio (déclaration,
  version, dépendances, ordre de chargement).
- Socle REST : enregistrement du namespace `postelio/v1`, enveloppe de réponse
  standard, gestion centralisée des erreurs, pagination, tri, filtres communs.
- Bus d'événements interne (abstraction au-dessus des hooks WordPress) :
  `Postelio\Core\Events::emit()` / `::on()`.
- Permissions génériques (mapping rôles→capabilities, helper `can()`), garde REST.
- Journalisation applicative + **audit log** (table `wp_postelio_audit_log`).
- Abstraction cron/queue (`Postelio\Core\Jobs`) pour les tâches asynchrones.
- Helpers communs (validation, sanitization, DTO, réponses, dates, IDs).
- Framework de **migrations** DB par plugin (schema version).

**Dépendances :** aucune (socle).
**Données possédées :** `wp_postelio_audit_log`, options `postelio_*` globales, registry.
**Endpoints :** `/health`, `/version`, `/me` (agrège l'identité via users — lecture),
`/config` (public, non sensible).
**Émet :** `plugin.registered`. **Écoute :** tous (pour l'audit log générique).
**NE contient PAS :** candidatures, messages, offres, entretiens, paiements, fichiers.

---

## postelio-users

**Responsabilité.** Comptes et identité applicative ; profils candidat et recruteur ;
authentification API (tokens app), consentements RGPD, suppression/anonymisation de compte.

**Dépendances :** core.
**Données possédées :** `wp_users`/`wp_usermeta` (natif), `CandidateProfile`,
`RecruiterProfile`, préférences de visibilité, `consents`. Voir
[data-model.md](data-model.md#user).
**Endpoints :** `/auth` (login/refresh/logout), `/me`, `/candidates/{id}`,
`/candidates/me/profile`, `/recruiters/me/profile`, `/me/settings`, `/me/export`
(RGPD), `DELETE /me` (suppression).
**Émet :** `user.created`, `user.updated`, `user.deleted`, `candidate.profile_updated`.
**Écoute :** `company.member_added` (lier un recruteur à une entreprise).
**Interactions :** fournit l'identité aux autres plugins via l'API `/me` ; applique
les règles de visibilité des coordonnées ([data-model.md](data-model.md#visibilité)).

---

## postelio-companies

**Responsabilité.** Entreprises (fiche publique, identité légale), membres recruteurs,
statut de vérification, « entreprises suivies » côté candidat.

**Dépendances :** core, users.
**Données possédées :** `Company` (CPT `postelio_company`), `CompanyMember` (relation
recruteur↔entreprise, table dédiée), `Follow` (candidat suit une entreprise, table dédiée).
**Endpoints :** `/companies`, `/companies/{id}`, `/companies/me` (recruteur),
`/companies/{id}/verification`, `/companies/{id}/follow` (candidat).
**Émet :** `company.created`, `company.updated`, `company.verification_requested`,
`company.verified`, `company.followed`.
**Écoute :** `user.created` (créer l'entreprise à l'inscription recruteur si fournie).
**Interactions :** `jobs` rattache les offres à une `company_id` ; `notifications`
prévient les suiveurs lors de `job.published`.

---

## postelio-jobs

**Responsabilité.** Offres d'emploi (CRUD, publication 3 étapes, modèles, brouillons,
duplication, statuts, expiration), **alertes emploi**, **favoris** (offres enregistrées).

**Dépendances :** core, companies (une offre appartient à une entreprise).
**Données possédées :** `Job` (CPT `postelio_job`), `JobAlert` (table dédiée),
`Favorite` (table dédiée). Taxonomies : secteur/famille de métier, type de contrat,
niveau d'étude, télétravail (voir [data-model.md](data-model.md#job)).
**Endpoints :** `/jobs`, `/jobs/{id}`, `/jobs/me` (recruteur), `/jobs/{id}/duplicate`,
`/jobs/{id}/renew`, `/me/favorites`, `/me/alerts`.
**Émet :** `job.created`, `job.published`, `job.expiring`, `job.expired`, `job.renewed`,
`job.filled`, `job.archived`, `alert.created`, `favorite.added`.
**Écoute :** `payment.succeeded` (activer un renouvellement), `application.selected`
(proposer `job.filled`), `company.suspended` (dépublier les offres).
**Interactions :** `billing` pour le renouvellement ; `applications` lit l'offre ciblée ;
cron `job.expiring`/`job.expired` (voir [implementation-plan.md](implementation-plan.md#cron)).

---

## postelio-applications

**Responsabilité.** Candidatures (création depuis une offre), pipeline de statuts,
historique, notes recruteur **privées**, réponses aux **questions de présélection**.

**Dépendances :** core, jobs, users, files (snapshot CV).
**Données possédées :** `Application`, `ApplicationHistory`, `PreselectionAnswer`
(tables dédiées) ; les **notes recruteur** (table dédiée, jamais exposées au candidat).
**Endpoints :** `/applications` (POST candidat), `/applications/me` (candidat),
`/companies/me/applications` (recruteur, pipeline), `/applications/{id}`,
`/applications/{id}/status`, `/applications/{id}/notes` (recruteur), `/applications/{id}/withdraw`.
**Émet :** `application.created`, `application.status_changed`, `application.rejected`,
`application.selected`, `application.withdrawn`.
**Écoute :** `interview.confirmed` (refléter dans l'historique), `job.filled`.
**Interactions :** demande un **snapshot CV** à `files` à la création ; ouvre une
`Conversation` via `messaging` ; déclenche `notifications`.

---

## postelio-files

**Responsabilité.** Stockage **sécurisé** des CV et documents (lettre de motivation),
accès contrôlé (jamais d'URL publique directe), **snapshot** du CV utilisé pour une
candidature.

**Dépendances :** core, users.
**Données possédées :** `CV` (table dédiée + fichier hors webroot ou protégé),
`Document` (lettre de motivation, portfolio), `CVSnapshot`.
**Endpoints :** `/me/cvs`, `/me/cvs/{id}` (principal, remplacer, supprimer),
`/files/{id}/download` (téléchargement signé/contrôlé), `/me/documents`.
**Émet :** `cv.uploaded`, `cv.replaced`, `cv.deleted`, `cv.snapshot_created`.
**Écoute :** `application.created` (créer le snapshot), `user.deleted` (purge fichiers).
**Interactions :** garantit qu'un recruteur autorisé (candidature reçue) peut lire le
snapshot ; voir [security.md](security.md#fichiers) et [data-model.md](data-model.md#cv--cvsnapshot).

---

## postelio-messaging

**Responsabilité.** Conversations candidat↔recruteur **contextualisées par une
candidature**, messages (texte, immuables), statut de lecture par participant, fermeture.
*(Messages système, signalement/modération : hooks prévus, hors périmètre V1.)*

**Dépendances :** core, users, companies, applications (**contexte candidature
obligatoire** — pas de contact arbitraire). Découplage via `ApplicationDirectory` +
`CompanyDirectory` + `UserDirectory` (aucune dépendance circulaire).
**Données possédées :** `Conversation`, `Participant`, `Message` (3 tables dédiées).
**Décisions V1 :** **1 conversation par candidature** (`UNIQUE(application_id)`, sûr en
concurrence) ; participants multiples possibles côté entreprise (plusieurs recruteurs) ;
messages **immuables** (D6) + suppression **logique** ; XSS neutralisé (texte seul) ;
non-lu via curseur monotone `last_read_message_id` (robuste à la même seconde, §33) ;
pagination **curseur** ; **contexte gelé** (offre pourvue/expirée, renommage entreprise,
candidature sélectionnée/rejetée n'altèrent pas l'historique) ; statut
`active|closed|archived` (envoi possible uniquement si `active`) ; rate-limiting ;
lecture même e-mail non vérifié, **envoi** exige `pst_email_verified`.
**Endpoints :** voir [api-contract.md](api-contract.md#messagerie--postelio-messaging).
**Émet :** `conversation.created`, `conversation.read`, `conversation.closed`,
`message.created` (audit **sans body**).
**Contrat sortant :** `\Postelio\Messaging\Api\MessagingDirectory`.
**Interactions :** ouverture liée à une candidature via `ApplicationDirectory::context`
+ appartenance entreprise via `CompanyDirectory::is_member`.

---

## postelio-interviews

**Responsabilité.** Entretiens liés à une **candidature** : proposition (recruteur),
confirmation / refus / autre créneau (candidat), modification / acceptation / annulation /
réalisé (recruteur), historique. Formats **visio / sur place / téléphone** avec données
structurées. *(Pas d'e-mail réel, ni calendrier externe, ni SMS/push : hooks via events.)*

**Dépendances :** core, users, companies, applications. Découplage via
`ApplicationDirectory` (contexte + `move_to_interview` + `is_schedulable`) et
`CompanyDirectory` (appartenance, préremplissage adresse). Aucune écriture directe de la
table applications.
**Données possédées :** `Interview` + `InterviewHistory` (2 tables dédiées).
**Décisions V1 :** contexte candidature obligatoire ; pipeline → `interview` **à la
proposition** ; états `proposed/confirmed/reschedule_requested/declined/cancelled/completed`
(pas de `pending_candidate` redondant) ; **un seul entretien actif par candidature** ;
dates **UTC** + fuseau métier (ISO 8601) ; durée 15–240 min ; URL visio validée (jamais
rendue en HTML) ; adresse structurée (ville préremplie) ; téléphone non divulgué ;
modification substantielle d'un confirmé ⇒ retour `proposed` (reconfirmation) ; candidature
terminale ⇒ `409` ; hors périmètre ⇒ `404` ; `completed` = marquage manuel (cron futur).
**Endpoints :** voir [api-contract.md](api-contract.md#entretiens--postelio-interviews).
**Émet :** `interview.proposed`, `interview.confirmed`, `interview.declined`,
`interview.reschedule_requested`, `interview.rescheduled`, `interview.cancelled`,
`interview.completed` (audit **sans coordonnées ni instructions**).
**Contrat sortant :** `\Postelio\Interviews\Api\InterviewDirectory` (contexte pour
Notifications/e-mail de preuve, compteur à venir, historique).
**Interactions :** émet des événements que `notifications`/e-mail consommeront ; le lien
messagerie passe par `MessagingDirectory` (pas de seconde messagerie).

---

## postelio-notifications (implémenté Lot 09)

**Responsabilité.** Notifications **in-app** (cloche/centre) + **e-mails transactionnels**,
pilotés par les événements. Plugin **réactif** : écoute, décide des canaux, n'appelle
**jamais** `wp_mail()` directement (Router → EmailDispatcher → file → EmailProvider).

**Dépendances :** core, users, companies, jobs, applications, messaging, interviews
(contrats publics uniquement). **load_order 70.**
**Données possédées :** `Notification` + `NotificationDelivery` (2 tables) ; préférences
en `user_meta` JSON (`pst_notification_prefs`).
**Décisions V1 :** matrice événement→destinataire→canal (voir README) ; idempotence par
`dedup_key` UNIQUE ; acteur≠destinataire ; multi-recruteurs = créateur d'offre + owner
(dédup) ; anti-spam messages (in-app immédiat, e-mail différé 5 min conditionnel, 1/conv/
30 min) ; rappels entretien 24 h/1 h via Scheduler ; obligatoires = entretien annulé,
preuve de confirmation, entreprise suspendue ; `completed` sans notif ; compteurs cloche
et messagerie **distincts** ; actions **structurées** (pas d'URL absolue) web+Tauri ;
temps réel = polling.
**Endpoints :** `GET /me/notifications`, `GET /me/notifications/unread-count`,
`POST /me/notifications/{uuid}/read`, `POST /me/notifications/read-all`,
`GET|PUT /me/notification-preferences`.
**Émet (interne) :** `notification.created`, `notification.read`, `email.queued`,
`email.sent`, `email.failed` — **jamais réécoutés** (anti-boucle).
**Écoute :** `application.created|selected|rejected|withdrawn`, `message.created`,
`conversation.read`, `interview.*` (sauf `completed`), `company.verified|rejected|
suspended`, `job.expiring|expired|suspended`. *(Ignore `application.status_changed|
reviewed|shortlisted|interview` — D2.)*
**Contrat sortant :** `\Postelio\Notifications\Api\NotificationDirectory`
(`unread_count`, `recent`).
**E-mail :** `EmailProvider` (interface) ; V1 dev = `WpMailProvider` ; provider prod
non choisi (filtre `postelio/notifications/email_provider`).

---

## postelio-moderation (implémenté Lot 11)

**Responsabilité.** **Modération centralisée** — signalements utilisateur (**réactif** →
**cas** regroupés par ressource) **et** passerelle **préventive** (filtre
`postelio/moderation/evaluate` appelé par la messagerie et les offres avant
insertion/publication). Revue humaine par modérateurs/admins. Le domaine **décide** puis
**délègue l'exécution** aux domaines propriétaires (jamais d'écriture directe d'une table
tierce). **Moteur de règles local uniquement** en V1 : aucun provider externe, aucune
dépendance Composer.

**Dépendances :** core, users, companies, jobs, job-sources, messaging (contrats publics
uniquement). **load_order 90.**
**Données possédées :** `ModerationReport` + `ModerationCase` + `ModerationCaseEvent`
(**3 tables** `wp_postelio_moderation_*`, migration idempotente, désactivation non
destructive). Voir [data-model.md](data-model.md#modération--wp_postelio_moderation_-implémenté-lot-11).
**Provider :** interface `ModerationProvider` (filtre `postelio/moderation/provider`)
**prête** pour un futur provider externe, **non branchée** en V1 ; `GET /moderation/health`
rapporte `provider: local_only`.
**Passerelle préventive :** `low`→autorisé ; `medium`→`review_required` (le contenu **passe**
+ cas ouvert/rattaché = « envoyer + signaler ») ; `high|critical`→**bloqué** (fail-closed,
erreur générique `moderation_blocked`/422). **Aucun** état `pending` (ni offres ni messages).
**Endpoints :** voir [api-contract.md](api-contract.md#modération--postelio-moderation-implémenté-lot-11).
**Émet :** `moderation.report_created`, `moderation.case_opened`,
`moderation.review_started`, `moderation.decision_made`, `moderation.content_hidden`,
`moderation.content_restored`, `moderation.user_warned`. Les **suspensions** notifient via
les événements **propriétaires** (`job.suspended`/`company.suspended`/`user.suspended`) —
jamais de doublon.
**Écoute :** appelé en **filtre** par messaging/jobs (passerelle préventive) ; le réactif
entre par `POST /moderation/reports` (pas d'abonnement à un flux d'événements pour décider).
**Actions déléguées** (contrats propriétaires, jamais d'`UPDATE` direct) :
`hide/unhide`→`JobSourcesModeration` ; `close_conversation`→`MessagingDirectory` ;
`suspend_job/unsuspend_job`→`JobModeration`→`JobService::admin_transition` ;
`suspend_company/unsuspend_company`→`CompanyModeration`→`VerificationService::decide`
(+ écouteur découplé `CompanySuspensionSync` qui suspend les offres **publiées**, notify:false) ;
`suspend_user/unsuspend_user`→`UserModeration` (statut suspendu + révocation des jetons
Bearer + destruction des sessions WP ; **réversible** ; jamais d'écriture directe dans
`wp_users`).
**Contrats additifs introduits ce lot** (tous non destructifs) : `UserModeration` +
`UserDirectory::{public_uuid,id_from_public_uuid,is_active}` (users) ; `JobModeration` +
`CompanySuspensionSync` + gate de pré-publication + gardes `is_active` sur `create()`/
`publish()` (jobs) ; `CompanyModeration` (companies) ; `JobSourcesModeration`
(hide/unhide/is_visible) (job-sources) ; `send()` appelle la passerelle + garde `is_active`
(messaging) ; garde `is_active` sur `propose()` (interviews) ; **nouveau** code d'erreur
`moderation_blocked`→422 (core, 11→12 codes).

---

## postelio-billing (implémenté Lot 12)

**Responsabilité.** **Renouvellement payant** d'une offre (10 € TTC / 30 j) via **Stripe
Checkout hosted**. Le plugin **paie puis délègue** l'effet métier à `postelio-jobs`
(contrat `JobLifecycle`) : il n'écrit **jamais** `pst_status`/`pst_date_expiration`. Le
**webhook signé** est la **seule source de vérité** (le retour navigateur / `success_url`
ne confirme jamais). **Aucune dépendance Composer** (client Stripe léger via `wp_remote_*`) ;
**aucune donnée carte** ne transite (PCI SAQ-A) ; Billing **n'envoie aucun e-mail**.

**Modèle économique V1 :** 1re publication **gratuite** (Billing ne touche pas
`JobService::publish`) ; produit `job_renewal` = **1000 cents** (10 € TTC), **EUR** seul,
**+30 j** ; renouvelable **uniquement si `JobLifecycle::can_renew()`** (statuts
`expiring|expired` — pas de contournement de modération). Aucun crédit / pack / abonnement /
prépaiement.

**Dépendances :** core, jobs (cible du renouvellement), companies (identité de facturation).
**Données possédées :** **3 tables** (pas de table facture en V1) `wp_postelio_billing_orders`,
`wp_postelio_billing_payments`, `wp_postelio_billing_events` — montants en **cents entiers**,
migrations idempotentes non destructives (désactivation = conservation comptable). Voir
[data-model.md](data-model.md#facturation--wp_postelio_billing_-implémenté-lot-12).
**Provider :** interface `PaymentProvider` ; `StripePaymentProvider` (`wp_remote_*`, signature
webhook **HMAC-SHA256** de `t.payload` + tolérance de timestamp) ; `FakePaymentProvider` (tests) ;
résolu au point d'usage via le filtre `postelio/billing/provider` (`ProviderRegistry`). Détection
test/live via `sk_test_`/`sk_live_` (config incohérente → santé `degraded`, pas de checkout live).
Client Stripe créé **paresseusement, un par ENTREPRISE**. Clé publiable **non requise** en V1.
**Endpoints :** voir [api-contract.md](api-contract.md#facturation--postelio-billing-implémenté-lot-12)
(`POST /billing/checkout`, `GET /billing/orders[/{uuid}]`, `POST /billing/webhook/stripe`
public signé, `GET|POST /billing/admin/*`, `GET /billing/health`).
**Émet :** `order.created`, `checkout.created`, `payment.succeeded`, `payment.failed`,
`payment.refunded`, `payment.disputed`, `renewal.applied`, `fulfillment.failed`,
`order.manual_review` (nommage par agrégat, **pas** de préfixe `billing.*`, **pas** de
`invoice.created` en V1).
**Écoute :** **aucun événement métier** — l'achat est **initié par l'utilisateur** ; la
confirmation vient **du webhook Stripe signé**, jamais du bus (Billing **n'écoute pas**
`job.expiring`).
**Exactly-once (mécanisme critique) :** renouvellement clé par `idempotency_key = order_uuid`,
passé à `JobLifecycle::renew_after_payment($job_id, $days, ['idempotency_key' => order_uuid])`
(extension **additive** de jobs). Jobs tient un **registre** (`pst_renewal_ledger`, cible figée)
et applique un **SET absolu** (jamais `++`/`+=`), écrit **avant** le SET → rejeu webhook, retry
de fulfillment et crash intermédiaire convergent vers **une seule** prolongation, **un seul**
`renewal_count++`, **un seul** `job.renewed`. Nouvelle échéance calculée **par Jobs** :
`max(échéance_courante, aujourd'hui) + 30`. Retry via récurrence Core Scheduler `postelio_15min`
(max 5 → `fulfillment_failed`/`manual_review`).
**Reçu vs facture :** V1 expose le **reçu Stripe** (`receipt_url`) comme justificatif — ce
**n'est pas** une « facture ». Facture légale française numérotée = **phase ultérieure** (gated
`SellerConfig` via `POSTELIO_SELLER_*`, TVA, numérotation) ; santé expose `seller_configured` /
`invoice_legal_ready=false`. **Aucun PDF** de facture en V1.
**Contrats additifs introduits ce lot :** `JobLifecycle` (idempotency_key) + `JobRepository`
(registre/SET absolu) + `JobDirectory::company_id_of` (jobs) ; `CompanyBilling::identity`
(companies) ; écouteur `job.renewed` + template e-mail `job_renewed` (notifications). **Core
inchangé** (`pst_manage_billing` et `payment_required`/402 préexistent).

---

## postelio-job-sources (implémenté Lot 10)

**Responsabilité.** Agrégation/synchronisation d'**offres externes** (France Travail V1)
dans une **table dédiée**, fusionnées à la recherche `/jobs` (aucune page séparée).
Candidature externe = **redirection** (jamais de candidature Postelio). Aucun scraping ;
Indeed/HelloWork/ATS = FUTUR/partenariat.

**Dépendances :** core, jobs (contrats/filtres publics uniquement). **load_order 80.**
**Données possédées :** `ExternalJob` (`wp_postelio_external_jobs`) + `JobSourceSyncRun`
(`wp_postelio_job_source_sync_runs`). **Ne touche pas** au CPT `postelio_job`.
**Providers :** `JobSourceProvider` (interface) ; `FranceTravailProvider` (OAuth2
client_credentials, token caché, RateLimiter, UrlGuard SSRF) ; `FakeJobSourceProvider`
(tests). Secrets en constantes/env (`POSTELIO_FT_CLIENT_ID`/`_SECRET`), jamais en base/Git.
**Recherche :** `CompositeJobSearchProvider` branché via `postelio/jobs/search_provider`
(natif CPT ⊕ externe table) ; filtre `source=all|postelio|partners`.
**Sync :** slices configurables + import progressif (caps) via **Core Scheduler**
(`job_sources_sync`, défaut horaire ; refresh complet ≥24h — licence FT). Disparition
confirmée (refresh complet réussi) ⇒ `removed` + **anonymisation** (licence Art. 7) ;
panne ⇒ aucun retrait.
**Endpoints :** `GET /jobs/{uuid}/apply-redirect` (302 externe), `GET /job-sources/health`
(admin). Contrat sortant : présentation/résolution via filtres jobs.
**Émet :** `job_source.sync_started|completed|failed`, `external_job.created|updated|
removed|apply_redirected` (jamais vers Notifications).
**Licence France Travail** respectée (attribution, contenu complet + logo, refresh 24h,
retrait/anonymisation, RGPD UE) — voir [integrations.md](integrations.md).

## postelio-skills (implémenté Lot 13)

**Responsabilité.** **« Savoir-faire & Avis »** — **contenu éditorial public** (savoir-faire)
publié par un **candidat** (en son nom) ou un **recruteur** (en son nom **ou** au nom de son
entreprise via `as_company`), plus des **commentaires** (« Avis »). Publication **modérée en
amont** (passerelle préventive, comme les offres). **Aucun rendu WordPress public** (exposé
**uniquement** via l'API REST ; SEO livré comme **contrat d'API**), **aucune** dépendance
externe, **aucun** e-mail direct (événements → Notifications), **aucune** UI d'admin (la file
de modération centrale suffit).

**Dépendances :** core, users, companies, moderation (contrats publics uniquement).
**Données possédées :** CPT **`postelio_skill`** (`public=false`, `publicly_queryable=false` —
comme les offres ; `post_status` reste `publish`, statut métier en meta) — **pas de table
dédiée** pour le contenu principal — + table dédiée **`wp_postelio_skill_comments`** pour les
commentaires (migration idempotente, désactivation non destructive). Meta : `pst_uuid` (UNIQUE,
exposé), `pst_status` (`draft|published|archived`), `pst_author_type` (`candidate`=personnel |
`company`), `pst_company_id`/`pst_company_uuid`, `pst_revision`, `pst_summary`, `pst_details`
(JSON), `pst_mod_hidden`, `pst_susp_hidden`. Image = **image à la une** (Media Library WP,
publique — **jamais** `postelio-files`). Taxonomies : `postelio_skill_category` (hiérarchique)
+ `postelio_skill_tag` (libre). Voir
[data-model.md](data-model.md#savoir-faire--avis--postelio_skill--implémenté-lot-13).
**Définition V1 :** contenu libre (titre, résumé, HTML restreint `wp_kses`, catégorie, tags,
image optionnelle) + blocs structurés **optionnels** (matériel/étapes/conseils/erreurs/résultat/
galerie/difficulté/durée/métier) en `pst_details` — **jamais requis**. **Requis : titre, contenu,
catégorie.** Notation multi-critères, réactions/likes, compteur de vues, avis employeurs = **hors
V1** (le front garde ces éléments en mock).
**Statuts & visibilité :** `draft → published → archived` (+ `published → draft` si une édition
est bloquée). **« hidden » n'est pas un statut** : c'est une suppression de visibilité **à cause
tracée** via deux flags **indépendants** `pst_mod_hidden` (modération) et `pst_susp_hidden`
(suspension utilisateur/entreprise). Visibilité publique = `published && !mod_hidden &&
!susp_hidden`. Lever une suspension **ne réexpose jamais** un contenu masqué par la modération.
Voir [workflows.md](workflows.md#savoir-faire--avis-skills--implémenté-lot-13).
**Modération :** `ModerationGateway::evaluate` (filtre `postelio/moderation/evaluate`) à la
publication et à l'édition significative d'un contenu publié (bloqué → retour `draft`) ;
`low`→publié, `medium`→publié + cas, `high|critical`→reste `draft` (fail-closed
`moderation_blocked`/422, message **générique**). **Commentaires** évalués **PRE-insert**
(`low`→publié, `medium`→publié + flag, `high|critical`→**aucune ligne**). Signalements via
`POST /moderation/reports` (types **`skill`** et **`skill_comment`** ajoutés au catalogue de
`reason_code`) ; masquage/démasquage modérateur routé depuis `ModerationActions` vers le
contrat `SkillModeration::set_visibility`.
**Endpoints :** voir [api-contract.md](api-contract.md#savoir-faire--avis--postelio-skills-implémenté-lot-13).
**Émet :** `skill.created`, `skill.updated`, `skill.published`, `skill.archived`,
`skill.hidden`, `skill.restored`, `skill.comment_created`. (`skill.published` **ne
s'auto-notifie pas** ; commentaire reçu → notification à l'auteur du savoir-faire.)
**Écoute :** `user.suspended`/`user.unsuspended`/`user.deleted`/`company.suspended`/
`company.verified` (écouteurs découplés `SuspensionSync` — masquage/restauration via
`pst_susp_hidden`).
**Contrats sortants :** `\Postelio\Skills\Api\SkillDirectory` (`get_context`, `belongs_to_user`,
`belongs_to_company`, `public_view`, `published_for_user`, `published_for_company`) et
`\Postelio\Skills\Api\SkillModeration` (`hide`/`unhide`/`set_visibility`/`is_visible`).
**Contrats additifs introduits ce lot :** capability `pst_comment_skill` + caps skill
(`pst_publish_own_skill`/`pst_manage_own_skill`) sur le rôle **recruteur** (core) ;
`UserDirectory::public_author` — byline publique, **jamais** e-mail/téléphone (users) ;
`ModerationActions` route skill/skill_comment vers `SkillModeration`, `ReasonCodes` gagne
`skill_comment`, visibilité répondue via le filtre `postelio/moderation/resource_visible`
**fourni par Skills** (moderation). **`pst_comment_skill` est la SEULE nouvelle capability.**
**Sécurité :** auteur/entreprise **dérivés côté serveur** (anti-spoofing : `author_user_id`/
`company_id` du body ignorés) ; non-divulgation inter-utilisateur/entreprise → **404** ; XSS
neutralisé (`wp_kses`, liens dangereux retirés) ; rate-limit des commentaires (filtre
`postelio/skills/comment_rate_per_hour`) ; pas de hard-delete.

---

## postelio-admin (implémenté — Phase 1 back-office)

**Responsabilité.** **Back-office wp-admin** (centre de contrôle) : une **couche
d'administration/orchestration pure**, **sans logique métier, sans table, sans SQL/meta
direct** dans les plugins propriétaires. Il **consomme des contrats** pour lire (façades
`Api\*Directory` + REST interne via `rest_do_request` **exécuté comme l'admin courant** —
capabilities et presenters s'appliquent) et **délègue les actions** aux services
propriétaires (`UserModeration`, `CompanyModeration`, `VerificationService`,
`JobModeration`, REST de modération). **Ne fatal jamais** si un module est absent
(détection `Registry::has` / `class_exists` → « Module indisponible »).

**Principe.** Jamais d'écriture directe d'une table tierce ; toute action passe par le
contrat public du domaine propriétaire. Détection défensive des modules désactivés.

**Dépendances :** core + **contrats des autres modules**, **tous optionnels et détectés**
à l'exécution (aucune dépendance dure ; un module manquant dégrade la page, pas le plugin).

**Menu unique « Postelio »** (sous-menus) : Tableau de bord, Utilisateurs, Entreprises,
Offres, Candidatures, CV & fichiers, Messagerie, Entretiens, Notifications, Sources
d'offres, Modération, Facturation, Savoir-faire, Favoris & Alertes (**préparé pour le
Lot 14**), Réglages, Santé du système.
**Pages complètes Phase 1 :** Tableau de bord, Utilisateurs, Entreprises, Offres,
Modération, Santé. Les autres = **emplacements de menu** (« en préparation »).

**Capabilities réutilisées (aucune nouvelle) :** menu + tableau de bord + modération =
`pst_view_moderation_queue` (admin + modérateur) ; utilisateurs / entreprises / offres /
santé / réglages / placeholders = `pst_manage_platform` (admin) ; facturation =
`pst_manage_billing`. Chaque page **re-vérifie la capability côté serveur** ; actions via
`admin-post` **avec nonce**.

**Contrats additifs (lecture seule) introduits côté plugins propriétaires, aucune nouvelle
capability :** `Postelio\Jobs\Api\JobAdminDirectory` (compteurs + liste) et
`Postelio\Companies\Api\CompanyAdminDirectory` (compteurs + liste).

**Design :** `assets/admin.css` scopé sous `.pst-admin`, palette Postelio (bleu nuit
`#17324D` + corail `#FF6B6B`), **aucun framework JS**. **Front public non modifié.**

Doc complète : [admin-backoffice.md](admin-backoffice.md).

---

## Anti-cycles

- Les plugins métier **ne s'appellent jamais directement** entre eux : ils émettent /
  écoutent des événements, ou consomment l'API. Ex. `applications` n'appelle pas
  `messaging` en dur : il émet `application.created`, et `messaging` réagit.
- Seules les dépendances **descendantes** vers `core` (et vers un domaine strictement
  « plus bas » : jobs→companies, applications→jobs) sont autorisées en appel de service
  en lecture. Graphe garanti acyclique — voir
  [implementation-plan.md](implementation-plan.md#dépendances).

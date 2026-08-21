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

**Responsabilité.** Entretiens : proposition, confirmation, contre-proposition de
créneau, refus, annulation, formats (visio/sur place/téléphone) et infos associées.

**Dépendances :** core, applications, users.
**Données possédées :** `Interview` (table dédiée).
**Endpoints :** `/interviews` (recruteur propose), `/interviews/me` (candidat),
`/interviews/{id}/confirm`, `/interviews/{id}/reschedule`, `/interviews/{id}/reject`,
`/interviews/{id}/cancel`.
**Émet :** `interview.proposed`, `interview.confirmed`, `interview.rescheduled`,
`interview.rejected`, `interview.cancelled`, `interview.completed`.
**Écoute :** `application.status_changed` (cohérence), `application.withdrawn` (annuler).
**Interactions :** insère des messages système via `messaging` ; alimente
`notifications` ; export `.ics` (déjà simulé côté front).

---

## postelio-notifications

**Responsabilité.** Notifications in-app (cloche) et e-mails transactionnels. Applique
la **matrice** événement→destinataire→canal ([events.md](events.md#matrice-de-notifications)).

**Dépendances :** core (+ écoute tous les domaines).
**Données possédées :** `Notification` (table dédiée), préférences de notification
(dans `users`/settings, en lecture).
**Endpoints :** `/me/notifications`, `/me/notifications/read`, `/me/notifications/read-all`.
**Émet :** `notification.created`.
**Écoute :** `application.*`, `interview.*`, `message.created`, `job.published`
(suiveurs), `company.verified`, `payment.*`.
**Interactions :** délègue l'envoi e-mail au provider transactionnel (intégration
primaire, non branchée dans ce lot).

---

## postelio-moderation

**Responsabilité.** File de modération et signalements pour messages, offres, profils,
savoir-faire, contenus entreprise. États : Allowed / Blocked / Review required.

**Dépendances :** core (+ écoute les événements de contenu).
**Données possédées :** `ModerationReport` (table dédiée), état de modération des objets
(champ possédé par chaque plugin, décision journalisée ici).
**Endpoints :** `/moderation/reports` (admin), `/moderation/reports/{id}/decide`,
`/moderation/queue`.
**Émet :** `content.reported`, `moderation.decided`.
**Écoute :** `message.reported`, `skill.reported`, `job.created`, `skill.submitted`,
`company_content.submitted` (mise en file si review requise).
**Interactions :** aucune API de modération externe branchée dans ce lot.

---

## postelio-billing

**Responsabilité.** Renouvellement d'offre (10 €/30 j), paiements, factures, webhooks
provider (Stripe prévu). Aucun code Stripe dans ce lot.

**Dépendances :** core, jobs (cible du renouvellement).
**Données possédées :** `Payment`, `Invoice`, `Renewal`, `WebhookEvent` (tables dédiées).
**Endpoints :** `/billing/renewals` (initier), `/billing/payments`, `/billing/invoices`,
`/billing/webhook` (public signé, provider).
**Émet :** `payment.succeeded`, `payment.failed`, `renewal.applied`, `invoice.created`.
**Écoute :** `job.expiring` (proposer le renouvellement).
**Interactions :** sur `payment.succeeded`, notifie `jobs` d'appliquer le renouvellement.

---

## postelio-skills

**Responsabilité.** Savoir-faire candidat (preuves de compétences) et contenus
entreprise (marque employeur) : publication, statuts de modération, notes/avis/vues,
compétences démontrées.

**Dépendances :** core, users, companies.
**Données possédées :** `SkillContent`, `CompanyContent` (CPT ou tables dédiées, voir
[data-model.md](data-model.md#skillcontent--companycontent)), notes/avis/vues.
**Endpoints :** `/skills`, `/skills/{id}`, `/me/skills`, `/companies/{id}/contents`.
**Émet :** `skill.submitted`, `skill.published`, `skill.reported`,
`company_content.submitted`.
**Écoute :** `moderation.decided` (publier/bloquer), `user.deleted`.
**Interactions :** `moderation` valide avant publication publique ; `users` affiche les
compétences démontrées dans le profil.

---

## Anti-cycles

- Les plugins métier **ne s'appellent jamais directement** entre eux : ils émettent /
  écoutent des événements, ou consomment l'API. Ex. `applications` n'appelle pas
  `messaging` en dur : il émet `application.created`, et `messaging` réagit.
- Seules les dépendances **descendantes** vers `core` (et vers un domaine strictement
  « plus bas » : jobs→companies, applications→jobs) sont autorisées en appel de service
  en lecture. Graphe garanti acyclique — voir
  [implementation-plan.md](implementation-plan.md#dépendances).

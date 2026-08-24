# Postelio — Architecture backend (Lot 00)

> **Statut : documentation d'architecture.** Aucun code métier, aucune table, aucun
> endpoint n'est implémenté dans ce lot. Objectif : figer les contrats (données,
> API, événements, permissions, conventions) avant le développement des plugins.

## 1. Principes directeurs

1. **API-first.** Toute donnée et toute action passent par une API REST versionnée
   (`/wp-json/postelio/v1/…`). Le thème WordPress (site web) et la future app
   **Tauri** consomment **la même API**. Aucune logique métier ne vit dans le thème.
2. **Modulaire, un plugin = un domaine.** Chaque domaine métier est un plugin WordPress
   autonome. Aucun *god plugin*. `postelio-core` ne contient **que** le transversal.
3. **Découplage par événements.** Les plugins communiquent par un bus d'événements
   interne (hooks WordPress encapsulés par le core), jamais par appels directs de
   classe d'un plugin métier à un autre. Cela évite les dépendances circulaires.
4. **Propriété unique de la donnée.** Chaque objet appartient à **un seul** plugin qui
   possède sa table/CPT et expose ses endpoints. Les autres plugins lisent via l'API
   ou via des services en lecture seule exposés par le core.
5. **Sécurité par défaut.** Permissions vérifiées côté serveur pour chaque endpoint et
   chaque transition de statut. Le front **ne décide jamais** d'un statut librement.
6. **Compatibilité évolutive.** Versionnement d'API (`v1`, `v2`), migrations DB par
   plugin, désactivation non destructive.

## 2. Vue d'ensemble

```
                         ┌──────────────────────────┐
   Web (thème WP)  ─────▶│      API REST postelio     │◀───── App Tauri (plus tard)
                         │      /wp-json/postelio/v1   │
                         └──────────────┬─────────────┘
                                        │
                              ┌─────────▼─────────┐
                              │   postelio-core    │  (transversal : registry,
                              │  bus d'événements  │   REST base, perms, logs,
                              │  helpers, cron, log│   erreurs, migrations)
                              └─────────┬─────────┘
        ┌───────────┬───────────┬───────┼────────┬───────────┬───────────┐
        ▼           ▼           ▼       ▼        ▼           ▼           ▼
     users     companies      jobs   files  applications messaging  interviews
        │           │           │       │        │           │           │
        └───────────┴─────┬─────┴───────┴────────┴─────┬─────┴───────────┘
                          ▼                            ▼
                   notifications                   moderation
                          ▼                            ▼
                        billing                      skills
```

Le graphe de dépendances complet (sans cycle) est décrit dans
[implementation-plan.md](implementation-plan.md#dépendances) et [plugins.md](plugins.md).

## 3. Découpage en plugins

| Plugin | Domaine | Doc détaillée |
|---|---|---|
| `postelio-core` | Transversal : registry, REST, événements, perms, logs, cron, erreurs, migrations | [plugins.md](plugins.md#postelio-core) |
| `postelio-users` | Comptes, profils candidat/recruteur, auth applicative | [plugins.md](plugins.md#postelio-users) |
| `postelio-companies` | Entreprises, membres recruteurs, vérification | [plugins.md](plugins.md#postelio-companies) |
| `postelio-jobs` | Offres d'emploi, alertes, favoris | [plugins.md](plugins.md#postelio-jobs) |
| `postelio-applications` | Candidatures, historique, questions de présélection | [plugins.md](plugins.md#postelio-applications) |
| `postelio-files` | CV et documents, accès contrôlé, snapshots | [plugins.md](plugins.md#postelio-files) |
| `postelio-messaging` | Conversations et messages | [plugins.md](plugins.md#postelio-messaging) |
| `postelio-interviews` | Entretiens, créneaux, formats | [plugins.md](plugins.md#postelio-interviews) |
| `postelio-notifications` | Notifications in-app + e-mails | [plugins.md](plugins.md#postelio-notifications) |
| `postelio-moderation` | File de modération, signalements | [plugins.md](plugins.md#postelio-moderation) |
| `postelio-billing` | Renouvellement payant d'offre (Stripe Checkout), paiements, reçus | [plugins.md](plugins.md#postelio-billing) |
| `postelio-skills` | Savoir-faire & Avis : contenu éditorial public (candidat/recruteur) + commentaires, modéré en amont | [plugins.md](plugins.md#postelio-skills) |

## 4. Périmètre fonctionnel (dérivé du front existant)

Le back-office ne fait que **persister et sécuriser** ce que le front simule déjà.
Objets métier réellement présents dans le front (localStorage `ss_*`), voir
[data-model.md](data-model.md) :

- Comptes candidat / recruteur, profil professionnel riche (recherche, à-propos,
  expériences, formations, compétences, langues, permis/mobilité, certifications,
  réalisations, liens, visibilité).
- Entreprises (annuaire, fiche, identité légale, vérification simulée, « suivre »).
- Offres (publication en 3 étapes, modèles, brouillons, duplication, statuts,
  renouvellement 10 €/30 j).
- Candidatures (parcours candidat→recruteur, pipeline Kanban, historique, notes
  recruteur privées, questions de présélection prévues).
- CV multiple + CV principal + lettre de motivation + **snapshot** à la candidature.
- Favoris, alertes emploi, entreprises suivies.
- Messagerie candidat↔recruteur (contexte candidature, messages système).
- Entretiens (3 formats, confirmation, créneaux, préparation, débrief).
- Notifications (cloche groupée, e-mails simulés).
- Savoir-faire candidat (preuves de compétences) + contenus entreprise.
- Facturation (renouvellement simulé, historique).
- Paramètres, visibilité des coordonnées, suppression de compte.

> **Règle :** on ne documente ici **aucune** fonctionnalité absente du front ou non
> explicitement prévue. Les extensions futures (parsing CV, agrégateurs d'offres…)
> sont listées comme intégrations **secondaires** dans [integrations.md](integrations.md).

## 5. Conventions (résumé)

Détails complets dans [api-contract.md](api-contract.md) et
[implementation-plan.md](implementation-plan.md#conventions-de-code).

- **Namespace PHP :** `Postelio\Core`, `Postelio\Users`, `Postelio\Jobs`, …
- **Tables dédiées :** préfixe `wp_postelio_…` (voir [data-model.md](data-model.md#tables-dédiées)).
- **Options :** préfixe `postelio_…`.
- **Hooks/événements :** `postelio/<domaine>.<action>` (ex. `postelio/application.created`).
- **REST :** base `postelio/v1`, ressources au pluriel, kebab/snake cohérent.
- **Réponses :** enveloppe `{ data, meta }` / `{ error: { code, message, details } }`.

## 6. Décisions V1 arrêtées

Décisions validées pour la V1. Elles orientent l'implémentation ; les détails vivent
dans les docs référencées. Les durées de conservation **RGPD restent `À VALIDER`**
(aucune valeur n'est inventée — voir [security.md](security.md#6-rgpd)).

| # | Décision V1 | Doc de référence |
|---|---|---|
| D1 | **Brouillons avant vérification :** une entreprise non vérifiée peut préparer/enregistrer des **brouillons** d'offres, mais **ne peut pas publier publiquement** ; la publication publique exige `verified`. | [workflows.md](workflows.md#offre-job), [security.md](security.md#2-autorisation) |
| D2 | **Identifiants :** **IDs numériques internes** (clés primaires en base) + **UUID publics** pour les ressources sensibles/exposées via l'API lorsque pertinent (candidatures, fichiers, messages…). | [data-model.md](data-model.md#identifiants) |
| D3 | **CV V1 :** format **PDF uniquement**, **10 Mo maximum**. | [security.md](security.md#5-fichiers-cv--documents) |
| D4 | **Stockage fichiers :** **local privé** (hors webroot) en développement, derrière une abstraction **`StorageProvider`** permettant un provider **S3-compatible** plus tard. | [security.md](security.md#5-fichiers-cv--documents), [integrations.md](integrations.md) |
| D5 | **Rate limiting :** mécanisme **configurable**, **seuils non figés** à ce stade. | [security.md](security.md#3-menaces-web) |
| D6 | **Messages :** **immuables en V1** une fois envoyés ; **suppression logique/modération** possible si nécessaire (pas d'édition libre). | [security.md](security.md#4-données-sensibles), [data-model.md](data-model.md) |
| D7 | **Adresse IP :** stockée **uniquement** pour les événements de **sécurité/audit** où c'est justifié. | [security.md](security.md#7-audit-log) |
| D8 | **2FA :** **prévue pour les comptes administrateurs** ; **non obligatoire** candidat/recruteur en V1. | [security.md](security.md#1-authentification) |
| D9 | **Paiement :** **Stripe** (Checkout hosted) — **implémenté Lot 12** (`postelio-billing`). | [integrations.md](integrations.md) |
| D10 | **Vérification entreprise :** **Sirene / RNE** comme providers cibles. | [integrations.md](integrations.md) |
| D11 | **Conservation RGPD :** durées **`À VALIDER`**, non inventées. | [security.md](security.md#6-rgpd) |
| D12 | **Vérification e-mail obligatoire pour les actions sensibles** (postuler, écrire à une entreprise, publier une offre, contacter un candidat…). Contrôle centralisé via la capability virtuelle **`pst_email_verified`**. Inscription/connexion/profil/public restent ouverts sans vérification. | [security.md](security.md#1-authentification) |
| D13 | **Jetons applicatifs Bearer opaques maison** (`uid.tid.secret`, hash-only, révocables) pour l'app ; JWT/Application Passwords écartés en V1. | [security.md](security.md#9-jetons-applicatifs-bearer) |

> Réutilisation D2 (UUID public) : appliquée au profil candidat au Lot 02
> (`/candidates/{uuid}`) et à l'entreprise au Lot 03 (`/companies/{uuid}`). Même
> principe à généraliser ensuite à jobs, applications, interviews, conversations.

## 8. Questions ouvertes — Lot 03 (à valider, non tranchées silencieusement)

Décisions prises par défaut pendant l'implémentation, à confirmer :

| # | Question | Proposition retenue (V1) | Impact | Statut |
|---|---|---|---|---|
| Q1 | Stockage de la vérification entreprise : table dédiée `wp_postelio_company_verifications` (historique) ou meta + audit log ? | **Meta `pst_verification` (état courant) + audit log (historique)** ; pas de table dédiée (non prévue dans data-model). | Historique lisible via audit log ; requêtes d'historique moins directes qu'une table. | **À VALIDER** |
| Q2 | Un recruteur peut-il appartenir à **plusieurs** entreprises en V1 ? | **Non en V1** (création refusée si déjà rattaché) ; le schéma `company_members` (n-n, rôles owner/recruiter) le **permet** pour plus tard. Documenté dans [data-model.md](data-model.md#companymember). | Invitation/multi-appartenance repoussées sans refonte de schéma. | **Décidé V1** |
| Q3 | Endpoints d'invitation / retrait / changement de rôle de collaborateur. | **Non exposés** (hors api-contract) ; seul le rattachement du créateur (owner) est implémenté. | À ajouter comme incrément (routes + capabilities) sans changer les tables. | **Hors scope Lot 03** |
| Q4 | Unicité de l'UUID entreprise : le CPT stocke l'UUID en **meta** (pas d'index unique SQL natif). | **Unicité applicative** assumée : UUID v4 serveur (122 bits), contrôle de collision à la création (régénération), lecture **déterministe** (ID interne le plus petit) + journalisation si corruption. Pas de refonte du CPT. | Documenté [data-model.md#identifiants](data-model.md#identifiants) comme applicatif (non DB). Si garantie SQL stricte requise un jour : colonne/table d'index dédiée. | **Décidé V1** |
| Q5 | Endpoint de **décision de vérification admin** (`POST /companies/{uuid}/verification/decision`). | Ajouté à [api-contract.md](api-contract.md#5-endpoints-principaux). | — | **Résolu** |
| Q6 | Champ **code NAF/APE** (`naf_ape`). | Ajouté à l'identité légale ; [data-model.md](data-model.md#company) aligné. | — | **Résolu** |

### Contrat de vérification pour les autres plugins

`postelio-jobs` (et futurs) **ne lisent jamais** `pst_verification_status`, les meta,
`legal_verified` ou le provider. Ils passent par les façades publiques de
`postelio-companies` :
- **`Postelio\Companies\Api\CompanyVerification`** — `is_verified()`,
  `can_publish_jobs()`, `get_verification_status()` (+ filtres
  `postelio/company/{is_verified,can_publish_jobs,verification_status}`) ;
- **`Postelio\Companies\Api\CompanyDirectory`** — appartenance recruteur ↔ entreprise
  (`company_of_user`, `is_member`, `role_of`), résolution d'identité (`id_from_uuid`,
  `uuid_of`, `name_of`) et `public_summary()` pour l'affichage.

Règle V1 (D1) : brouillon autorisé pour une entreprise non vérifiée, **publication
publique ⇒ `verified`**. Appliquée par `postelio-jobs` au Lot 04 (`POST /jobs/{uuid}/publish`).

`postelio-jobs` expose à son tour deux contrats publics :
- **`Postelio\Jobs\Api\JobLifecycle`** — `can_renew()`, `renew_after_payment()` : point
  d'entrée du **renouvellement** pour `postelio-billing`. Billing n'écrit jamais
  `pst_status`/`pst_date_expiration` : il appelle ce contrat après paiement, qui
  applique la transition `expiring|expired → published` et émet `job.renewed`.
- **`Postelio\Jobs\Search\JobSearchProvider`** (filtre `postelio/jobs/search_provider`)
  — abstraction du moteur de recherche : les endpoints publics n'en dépendent pas de
  `WP_Meta_Query` en dur ; `postelio-search` pourra le remplacer (table/index dédié,
  Meilisearch/Typesense) sans casser l'API. Défaut V1 : `MetaQuerySearchProvider`.

`postelio-applications` expose **`Postelio\Applications\Api\ApplicationDirectory`**
(`context`, `belongs_to_company`, `is_schedulable`, `move_to_interview`) pour
`postelio-interviews` / `postelio-messaging` ; `postelio-users` expose
**`Api\UserDirectory`**. `postelio-messaging` (Lot 07) expose
**`Api\MessagingDirectory`** ; `postelio-interviews` (Lot 08) expose
**`Api\InterviewDirectory`** (contexte d'entretien pour Notifications/e-mail de preuve,
compteur à venir, historique). Ces façades évitent toute dépendance circulaire.

`postelio-skills` (Lot 13) publie les **« Savoir-faire & Avis »** : du **contenu éditorial
public** (savoir-faire) rédigé par un **candidat** (en son nom) ou un **recruteur** (en son
nom **ou** au nom de son entreprise via `as_company`), plus des **commentaires** (« Avis »).
**Aucun rendu WordPress public** (exposition **uniquement** via l'API REST Postelio ; le SEO
est livré comme **contrat d'API**, non rendu par WP en V1), **aucune** dépendance externe,
**aucun** e-mail direct (événements → Notifications), **aucune** UI d'admin (la file de
modération centrale suffit). **CPT `postelio_skill`** (`public=false`, `publicly_queryable=false`
— comme les offres ; `post_status` reste `publish`, le statut métier vit en meta `pst_status`
∈ `draft|published|archived`) **sans table dédiée** pour le contenu principal ; les
**commentaires** vivent dans la table dédiée `wp_postelio_skill_comments`. Champs requis :
**titre, contenu, catégorie** ; blocs structurés (matériel/étapes/conseils/erreurs/résultat/
galerie/difficulté/durée/métier) **optionnels** en JSON `pst_details`, jamais requis (notation
multi-critères, réactions/likes, compteur de vues, avis employeurs = **hors V1**). Image =
**Media Library WordPress** (publique), **jamais** `postelio-files` (stockage CV privé) ;
contenu = **liste blanche `wp_kses`** (p, listes, strong/em, h2/h3, blockquote, liens https ;
script/iframe/style/on*/javascript:/data:/file: interdits). Taxonomies :
`postelio_skill_category` (hiérarchique) + `postelio_skill_tag` (libre). **Modération
préventive** (comme les offres, **pas d'état `pending`**) : `ModerationGateway::evaluate`
(filtre `postelio/moderation/evaluate`) à la publication et à l'édition significative d'un
contenu publié (bloqué → retour `draft`, fail-closed `moderation_blocked`/422, message
générique) ; les **commentaires** sont évalués **PRE-insert** (high/critical → aucune ligne) ;
signalements via `POST /moderation/reports` (types `skill` et `skill_comment` **ajoutés** au
catalogue) ; masquage/démasquage modérateur routé par `ModerationActions` → contrat
`SkillModeration`. **« hidden » n'est pas un statut** : c'est une **suppression de visibilité à
cause tracée** via deux **flags indépendants** `pst_mod_hidden` (modération) et `pst_susp_hidden`
(suspension utilisateur/entreprise) — visibilité publique = `published && !mod_hidden &&
!susp_hidden` ; **lever une suspension ne réexpose jamais** un contenu masqué par la modération.
Auteur/entreprise **toujours dérivés côté serveur** (anti-spoofing : `author_user_id`/
`company_id` du body ignorés) ; non-divulgation inter-utilisateur/entreprise → **404**. **Une
seule** nouvelle capability : `pst_comment_skill` (le candidat a déjà `pst_publish_own_skill` +
`pst_manage_own_skill` ; le recruteur les **gagne** + `pst_comment_skill`, en plus de
`pst_manage_company_content` pour le contenu d'entreprise) ; `pst_email_verified` requis pour
publier/commenter. Contrats **additifs** introduits ce lot : `pst_comment_skill` + caps skill
sur le rôle recruteur (core) ; `UserDirectory::public_author` — byline publique, **jamais**
e-mail/téléphone (users) ; `ModerationActions` route skill/skill_comment vers `SkillModeration`,
`ReasonCodes` gagne `skill_comment`, visibilité skill/skill_comment répondue via le filtre
`postelio/moderation/resource_visible` **fourni par Skills** (moderation). Contrats **publics**
du plugin : `SkillDirectory` (`get_context`, `belongs_to_user`, `belongs_to_company`,
`public_view`, `published_for_user`, `published_for_company`) et `SkillModeration`
(`hide`/`unhide`/`set_visibility`/`is_visible`). Rate-limit des commentaires via le filtre
`postelio/skills/comment_rate_per_hour` (pas d'anti-spam parallèle — la modération s'en charge).
Suspension/suppression : compte suspendu **ou** supprimé → savoir-faire personnels masqués
(`pst_susp_hidden`), restaurés au rétablissement (jamais ceux masqués par la modération) ;
entreprise suspendue → contenus d'entreprise masqués, restaurés sur `company.verified` ; écouteurs
découplés `SuspensionSync` (`user.suspended`/`user.unsuspended`/`user.deleted`/`company.suspended`/
`company.verified`). Pas de hard-delete (auteur = archive, modérateur = masque, admin = suppression
logique exceptionnelle) ; anonymisation post-suppression = **futur** (RGPD/SEO `À VALIDER`).

`postelio-billing` (Lot 12) gère le **renouvellement payant** d'une offre (10 € TTC / 30 j)
via **Stripe Checkout hosted**. Il **paie puis délègue** l'effet métier à `postelio-jobs`
via le contrat **`JobLifecycle`** : il n'écrit **jamais** `pst_status`/`pst_date_expiration`.
Le **webhook signé** est la **seule source de vérité** (le retour navigateur / `success_url`
ne confirme jamais un paiement). **Aucune dépendance Composer** (client HTTP Stripe léger via
`wp_remote_*`, comme France Travail au Lot 10) ; **aucune donnée carte** ne transite par
Postelio (PCI SAQ-A) ; Billing **n'envoie aucun e-mail** (émet des événements → Notifications).
**Exactly-once :** le renouvellement est clé par `idempotency_key = order_uuid`, passé à
`JobLifecycle::renew_after_payment($job_id, $days, ['idempotency_key' => order_uuid])`
(extension **additive** de `postelio-jobs`) ; Jobs tient un **registre** (post meta
`pst_renewal_ledger`) figeant la cible et applique un **SET absolu** (jamais `++`/`+=`), écrit
**avant** le SET — rejeu du webhook, retry de fulfillment et crash intermédiaire convergent
vers **une seule** prolongation, **un seul** `renewal_count++`, **un seul** `job.renewed`.
La nouvelle échéance est calculée **par Jobs** : `max(échéance_courante, aujourd'hui) + 30`
(Billing ne la recalcule jamais). Provider derrière l'interface `PaymentProvider`
(`StripePaymentProvider` via `wp_remote_*`, signature webhook HMAC-SHA256 ; `FakePaymentProvider`
pour les tests ; filtre `postelio/billing/provider`, `ProviderRegistry`). Client Stripe créé
**paresseusement, un par ENTREPRISE**. Contrats **additifs** introduits ce lot : `JobLifecycle`
(idempotency_key) + registre/SET absolu + `JobDirectory::company_id_of` (jobs) ;
`CompanyBilling::identity` (companies) ; écouteur `job.renewed` + template e-mail `job_renewed`
(notifications). **Core inchangé** (`pst_manage_billing` et `payment_required`/402 préexistent).
**3 tables** `wp_postelio_billing_{orders,payments,events}` (montants en **cents entiers**,
migrations idempotentes non destructives). Retry de fulfillment via la récurrence Core Scheduler
`postelio_15min` (max 5 → `fulfillment_failed`/`manual_review`). **Reçu Stripe** (`receipt_url`)
comme justificatif V1 ; **facture légale numérotée = phase ultérieure** (gated `SellerConfig` /
`POSTELIO_SELLER_*`). Billing **n'écoute pas** `job.expiring` : l'achat est **initié par
l'utilisateur**.

`postelio-moderation` (Lot 11) centralise la **modération** : **réactif** (signalements
utilisateur → **cas** regroupés par ressource) **et** **préventif** (passerelle
`postelio/moderation/evaluate`, appelée par la messagerie et les offres avant
insertion/publication). **Moteur de règles local uniquement** en V1 (aucun provider
externe, aucune dépendance Composer) ; l'interface `ModerationProvider` (filtre
`postelio/moderation/provider`) reste **branchable** pour un futur provider — `GET
/moderation/health` rapporte `provider: local_only`. Le domaine **décide** puis **délègue
l'exécution** aux domaines propriétaires via leurs **contrats publics** (jamais d'`UPDATE`
direct d'une table tierce) : `JobSourcesModeration` (hide/unhide), `MessagingDirectory`
(close_conversation), `JobModeration`→`JobService::admin_transition` (suspend offre),
`CompanyModeration`→`VerificationService::decide` (suspend entreprise ; un écouteur
découplé `CompanySuspensionSync` suspend alors les offres **publiées**), `UserModeration`
(suspension **réversible** : statut + révocation des jetons Bearer + destruction des
sessions WP, **jamais** d'écriture directe dans `wp_users`). Contrats additifs (tous non
destructifs) introduits ce lot : `UserModeration` +
`UserDirectory::{public_uuid,id_from_public_uuid,is_active}` (users), `JobModeration` +
`CompanySuspensionSync` + gate de pré-publication (jobs), `CompanyModeration` (companies),
`JobSourcesModeration` (job-sources), `send()` appelle la passerelle (messaging), garde
`is_active` (interviews). `postelio-core` gagne un **nouveau** code d'erreur stable
`moderation_blocked` → **422** (catalogue : 11 → 12 codes).

`postelio-job-sources` (Lot 10) agrège des offres **externes** (France Travail V1) dans une
**table dédiée** `wp_postelio_external_jobs` (jamais le CPT — volumétrie), et les fusionne à
la recherche `/jobs` via le filtre existant **`postelio/jobs/search_provider`**
(`CompositeJobSearchProvider` = natif CPT ⊕ externe). Détail/présentation/candidature externe
passent par les filtres `postelio/jobs/{present_external,resolve_external}` (aucune
dépendance circulaire). Candidature externe = **redirection** (`/jobs/{uuid}/apply-redirect`),
jamais de candidature Postelio. Providers derrière `JobSourceProvider` (FranceTravailProvider
réel ; Indeed/HelloWork/ATS = futur/partenariat). Sync par slices via **Core Scheduler**.

`postelio-notifications` (Lot 09) est **réactif** : il écoute les événements des autres
domaines et décide des canaux (in-app / e-mail) via une matrice + préférences. Il n'appelle
jamais `wp_mail()` directement (Router → EmailDispatcher → file → **`EmailProvider`**) et
réutilise le **`Core\Jobs\Scheduler`** (worker de file + rappels d'entretien). Il expose
**`Api\NotificationDirectory`** (compteur cloche, aperçu récent) — lecture seule ; les
autres plugins n'écrivent **jamais** de notification directement. Les contrats consommés
ont été étendus de façon additive (application_uuid/job_uuid sur `application.*`,
actor_user_id sur `interview.*`, `JobDirectory::created_by/uuid_of/title_of`,
`CompanyDirectory::owner_of`, `MessagingDirectory::has_unread_in_conversation`).

`postelio-files` (Lot 06) — service transversal de fichiers privés :
- **`StorageProvider`** (interface) + `LocalPrivateStorageProvider` (V1, filtre
  `postelio/files/storage_provider`) : stockage **hors chemins publics** (+ `.htaccess`
  deny), clés assainies, noms aléatoires ; `S3StorageProvider` futur sans changer les
  contrats. **`FileScanner`** (défaut `NullScanner`, filtre `postelio/files/scanner`) :
  point d'extension antivirus, non branché.
- **`Postelio\Files\Api\FileCvContract`** : `postelio-applications` valide/verrouille un
  CV (appartenance + `ready`) sans toucher au stockage. **Découplage sans cycle** :
  applications consomme le contrat files ; files interroge applications uniquement par
  filtres (`postelio/files/authorize_download`, `postelio/files/file_is_referenced`).
  Le CV étant immuable, référencer son UUID depuis une candidature garantit le snapshot.

## 7. Ce qui est HORS de ce lot

- CPT métier, tables, endpoints complets, migrations exécutées.
- Intégrations réelles : Stripe, e-mail transactionnel, Sirene/RNE, modération, anti-bot.
- Toute modification du front (HTML/CSS/JS).
- Le Lot 01 (Core) — **ne pas démarrer** avant validation de cette architecture.

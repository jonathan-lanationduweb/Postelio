# Postelio — Plan d'implémentation & conventions

Cross-cutting : ordre des lots, mapping Git, conventions, DB, index, migrations,
activation/désinstallation, tests, cron, recherche, Tauri, environnements.

## Dépendances (graphe acyclique)

```
postelio-core
  ├── postelio-users
  │     └── postelio-companies
  │            └── postelio-jobs
  │                   ├── postelio-applications ── (files, messaging, interviews)
  │                   └── postelio-billing
  ├── postelio-files
  ├── postelio-messaging
  ├── postelio-interviews
  ├── postelio-notifications   (écoute large, ne dépend que du core)
  ├── postelio-moderation      (écoute large, ne dépend que du core)
  └── postelio-skills
```
Règles : dépendances **descendantes** uniquement ; toute autre interaction passe par le
**bus d'événements** ([events.md](events.md)). Aucune dépendance circulaire.

## Ordre des lots

| Lot | Contenu | Branche Git |
|---|---|---|
| **00** | Architecture & documentation (ce lot) | `feature/core-architecture` |
| 01 | `postelio-core` (REST base, events, perms, audit, migrations, cron) | *(à créer)* `feature/core-plugin` |
| 02 | `postelio-users` (comptes, profils, auth API, RGPD) | `feature/comptes-authentification` |
| 03 | `postelio-companies` (entreprises, membres, vérification) | *(à créer)* `feature/entreprises-backend` |
| 04 | `postelio-files` (CV, documents, snapshot, download sécurisé) | `feature/profil-cv-backend` |
| 05 | `postelio-jobs` (offres, favoris, alertes, taxonomies) | `feature/offres-backend` |
| 06 | `postelio-applications` (candidatures, historique, présélection, notes) | `feature/candidatures-backend` |
| 07 | `postelio-messaging` (conversations, messages) | `feature/messagerie-backend` |
| 08 | `postelio-interviews` (entretiens) | `feature/entretiens-backend` |
| 09 | `postelio-notifications` (in-app + e-mails) | `feature/notifications-emails` |
| 10 | `postelio-moderation` (file, signalements) | *(à créer)* `feature/moderation-security` |
| 11 | `postelio-billing` (renouvellement, paiement, factures) | `feature/facturation-paiement` |
| 12 | `postelio-skills` (savoir-faire + contenus entreprise) | `feature/savoir-faire-backend` |
| 13 | Recherche & alertes (SearchProvider, exécution des alertes) | *(à créer)* `feature/search-alerts` |
| 14 | Outils admin (dashboards, exports, audit UI) | *(à créer)* `feature/admin-tools` |
| 15 | Intégration Tauri (auth app, push) — **plus tard** | *(à créer)* `feature/tauri-integration` |

> **Livré hors roadmap initiale — `postelio-job-sources`** (agrégation d'offres externes,
> France Travail V1) sur `feature/job-sources-backend`. Ajoute une table dédiée
> `wp_postelio_external_jobs` + un `CompositeJobSearchProvider` branché sur le seam
> `postelio/jobs/search_provider` (recherche unifiée), sans toucher au CPT natif. Extensions
> additives de `postelio-jobs` (présentation/résolution/filtre `source`) et
> `postelio-applications` (garde offre externe → 409). Indeed/HelloWork/ATS = futur/partenariat.

> **`postelio-billing` — Lot 12, implémenté** sur la branche **`feature/billing-backend`**.
> Renouvellement payant d'offre (10 € TTC / 30 j) via **Stripe Checkout hosted** (client
> `wp_remote_*`, **aucune dépendance Composer** ; PCI SAQ-A). **3 tables**
> `wp_postelio_billing_{orders,payments,events}` (cents entiers, migrations idempotentes). Billing
> **paie puis délègue** à `postelio-jobs` via `JobLifecycle::renew_after_payment()` (extension
> **additive** : idempotency_key + registre `pst_renewal_ledger`/SET absolu → **exactly-once**) ;
> il n'écrit jamais le statut d'offre et **n'écoute pas** `job.expiring` (achat initié par
> l'utilisateur, confirmé par le **webhook signé**). Contrats additifs : `CompanyBilling::identity`
> (companies), écouteur `job.renewed` + template `job_renewed` (notifications). Core inchangé.
> Reçu Stripe en V1 ; facture légale numérotée = phase ultérieure.

> **`postelio-skills` — Lot 13, implémenté** sur la branche **`feature/skills-backend`**
> (« Savoir-faire & Avis »). Contenu éditorial **public** publié par un candidat (en son nom)
> ou un recruteur (en son nom **ou** au nom de son entreprise via `as_company`) + commentaires,
> **modéré en amont** (passerelle préventive, **pas d'état `pending`**). **CPT `postelio_skill`**
> (`public=false`, statut métier en meta `pst_status` ∈ `draft|published|archived`) **sans table
> dédiée** pour le contenu + table `wp_postelio_skill_comments` (migrations idempotentes non
> destructives). **Aucun rendu WP public** (exposé via l'API ; SEO = contrat d'API), **aucune**
> dépendance externe, **aucun** e-mail direct (événements → Notifications), **aucune** UI admin.
> Visibilité = `published && !pst_mod_hidden && !pst_susp_hidden` (flags **indépendants** ; lever
> une suspension ne réexpose jamais un contenu masqué par la modération). Image = Media Library WP
> (jamais `postelio-files`) ; contenu = liste blanche `wp_kses`. Contrats **additifs** : capability
> `pst_comment_skill` (**seule** nouvelle) + caps skill sur le recruteur (core),
> `UserDirectory::public_author` (users), routage `ModerationActions`→`SkillModeration` +
> `ReasonCodes` (skill/skill_comment) + filtre `postelio/moderation/resource_visible` (moderation).
> Contrats publics : `SkillDirectory`, `SkillModeration`. *(Le Lot 13 « Recherche & alertes » du
> tableau de roadmap ci-dessus reste à faire sur `feature/search-alerts` — numérotation de la
> phase de conception, distincte du lot d'implémentation `postelio-skills`.)*

## Mapping avec les branches Git existantes

Déjà créées (issues de `develop`) :
`feature/api-config`, `feature/comptes-authentification`, `feature/offres-backend`,
`feature/candidatures-backend`, `feature/messagerie-backend`, `feature/entretiens-backend`,
`feature/profil-cv-backend`, `feature/facturation-paiement`, `feature/savoir-faire-backend`,
`feature/notifications-emails`, **et** `feature/core-architecture` (ce lot).

- `feature/api-config` → **fusionne dans le Lot 01** (`postelio-core`) : la config
  d'API (`config.js` front pointe déjà `/postelio/v1/` + `postelio.fr`).

## Branches manquantes à créer

- `feature/core-plugin` (Lot 01 — plugin core ; `feature/api-config` peut servir de base).
- `feature/entreprises-backend` (Lot 03).
- `feature/moderation-security` (Lot 10).
- `feature/search-alerts` (Lot 13).
- `feature/admin-tools` (Lot 14).
- `feature/tauri-integration` (Lot 15, plus tard).

> Ce lot **documente** ces manques ; il ne crée pas forcément les branches.

## Conventions de code

- **Namespaces PHP :** `Postelio\Core`, `Postelio\Users`, `Postelio\Companies`,
  `Postelio\Jobs`, `Postelio\Applications`, `Postelio\Files`, `Postelio\Messaging`,
  `Postelio\Interviews`, `Postelio\Notifications`, `Postelio\Moderation`,
  `Postelio\Billing`, `Postelio\Skills`.
- **Structure par plugin :** `Controllers/` (REST), `Services/` (logique métier),
  `Repositories/` (accès DB), `Models/` ou `DTO/`, `Migrations/`, `Events/`.
- **Préfixes :** tables `wp_postelio_*` · options `postelio_*` · hooks
  `postelio/<domaine>.<action>` · REST `postelio/v1` · capabilities `pst_*` · rôles
  `postelio_*`.
- **Nommage classes :** `JobsController`, `ApplicationService`, `ApplicationRepository`,
  `CvSnapshot` (DTO). PSR-4, PSR-12.

## Base de données

- Tables dédiées listées dans [data-model.md](data-model.md#tables-dédiées).
- `dbDelta()` pour la création ; **jamais** d'`ALTER` anarchique au runtime.
- Charset/collation : `utf8mb4_unicode_ci`. Clés étrangères logiques (WP n'impose pas les
  FK) + intégrité applicative.

## Index & performance (prioritaires dès la création)

Noms de tables complets conformes à [data-model.md](data-model.md#tables-dédiées-convention-wp_postelio_).

| Table | Index |
|---|---|
| `wp_postelio_applications` | `job_id`, `candidate_id`, `company_id`, `status`, `created_at`, unique(`job_id`,`candidate_id`) |
| `wp_postelio_application_history` | `application_id`, `created_at` |
| `wp_postelio_messages` | `conversation_id`, `created_at`, `read_at` |
| `wp_postelio_conversations` | `candidate_id`, `recruiter_id`, `application_id`, `last_message_at` |
| `wp_postelio_notifications` | `user_id`, `read_at`, `created_at` |
| `wp_postelio_interviews` | `application_id`, `candidate_id`, `company_id`, `date` |
| `wp_postelio_favorites` | unique(`user_id`,`job_id`), `user_id` |
| `wp_postelio_job_alerts` | `user_id`, `active` |
| `wp_postelio_audit_log` | `actor_id`, `resource_type`, `resource_id`, `created_at` |
| `wp_postelio_cvs` / `wp_postelio_cv_snapshots` | `candidate_id` ; `application_id` |

## Migrations

- Chaque plugin porte un **`schema_version`** (option `postelio_<plugin>_schema`).
- Migrations **incrémentales** ordonnées ; exécutées à l'activation/upgrade via un
  `Migrator` du core. Idempotentes.

## Activation / désactivation / désinstallation

- **Désactivation :** ne supprime **aucune** donnée (tables et contenus conservés).
- **Désinstallation :** suppression **uniquement** sur action explicite (option
  « supprimer les données » ou procédure documentée), jamais par défaut.
- Ordre d'activation respectant les dépendances (core d'abord). Le core refuse
  d'activer un plugin dont une dépendance manque.

## Cron / jobs asynchrones (à prévoir, non implémentés)

- Expiration des offres (`job.expiring` J-7, `job.expired` échéance).
- Rappels d'entretien (J-1).
- Exécution des **alertes emploi** (immediate/quotidienne/hebdomadaire).
- Envoi e-mails en file + retries.
- Nettoyage fichiers orphelins ; purge notifications anciennes.
- Relance profil/CV ancien.
- Marquage `interview.completed` après la date.
- **Abstraction :** `Postelio\Core\Jobs` au-dessus de WP-Cron (option : Action Scheduler
  pour la fiabilité en volume).

## Recherche

- Interface **`SearchProvider`**. **V1 :** MySQL + index WordPress bien pensés
  (filtres offres déjà définis). **Plus tard :** Meilisearch / Typesense / OpenSearch si
  le volume l'exige. Rien à installer maintenant.

## Tauri (compatibilité, pas de code)

- API **indépendante du thème** (déjà le cas, API-first).
- **Auth app** par token Bearer (`/auth`), mêmes comptes, mêmes données.
- **Versionnement** d'API respecté (`v1`).
- **Notifications** : prévoir un canal push futur (déclaré dans notifications).

## Environnements

| Env | Usage | Config |
|---|---|---|
| local | dev | providers en **mock/demo**, e-mails capturés (Mailhog), stockage local |
| staging | recette | clés de test (Stripe test, e-mail sandbox), données anonymisées |
| production | prod | clés réelles via variables d'env / `wp-config` **hors dépôt** |

- **Aucun secret commité.** Clés API, DSN e-mail, secrets Stripe, jetons Sirene → env.

## Tests (minimum par plugin)

| Type | Cible |
|---|---|
| Unitaires | services (workflows, calculs, snapshot), repositories mockés |
| Intégration | migrations DB, repositories réels (BD de test) |
| REST API | chaque endpoint : succès, 401/403, 422, 409 |
| Permissions | matrice rôles × endpoints ([roles-permissions.md](roles-permissions.md)) |
| Workflows | transitions autorisées/refusées ([workflows.md](workflows.md)) |
| End-to-end | parcours candidature complet (postuler→pipeline→entretien→décision) |

Minimums : `core` (perms, events, migrations), `applications` & `interviews`
(workflows exhaustifs), `files` (accès CV/snapshot), `billing` (webhooks idempotents),
`users` (auth + RGPD).

## Arrêt
Fin du Lot 00. **Ne pas démarrer le Lot 01** avant validation de cette architecture.

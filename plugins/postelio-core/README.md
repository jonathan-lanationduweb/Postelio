# postelio-core

Socle **transversal** de Postelio (Lot 01). Ce plugin ne contient **aucune logique
métier** (ni offre, ni candidature, ni profil, ni message, ni entretien, ni paiement,
ni vérification d'entreprise). Il fournit uniquement l'infrastructure commune décrite
dans [`/docs/backend/`](../../docs/backend/).

## Ce que fournit le core

| Brique | Classe(s) | Rôle |
|---|---|---|
| Amorçage | `Plugin` | Câblage à `plugins_loaded`, activation/désactivation, upgrade auto. |
| Autoloading | `Autoloader` | PSR-4 `Postelio\Core\` → `src/` (sans Composer). |
| Registry des modules | `Registry` | Déclaration, versions, dépendances, ordre de démarrage (tri topologique, refus des cycles/dépendances manquantes). |
| Socle REST | `Rest\Server`, `Rest\Controller` | Namespace `postelio/v1`, enveloppe `{data,meta}`, gestion d'erreurs. |
| Événements | `Events` | Bus au-dessus des hooks WP (`postelio/<domaine>.<action>` + hook générique `postelio/event`). |
| Erreurs | `Errors`, `ApiError` | Codes internes stables → statut HTTP + enveloppe `{error:{code,message,details}}`. |
| Permissions | `Permissions\Capabilities`, `Roles`, `Guard` | Rôles `postelio_*`, capabilities `pst_*`, `permission_callback` REST. |
| Audit | `Audit\AuditLog`, `AuditListener` | Table `wp_postelio_audit_log` (append-only) + écoute globale des événements. |
| Migrations | `Migrations\Migrator`, `Migration`, `CreateAuditLogTable` | Schéma versionné par module, incrémental, idempotent. |
| Cron / queue | `Jobs\Scheduler` | Abstraction au-dessus de WP-Cron (`enqueue`, `schedule`, `recurring`). |
| Journalisation | `Log\Logger` | Diagnostic technique (debug.log). |
| Santé | `Health\Status` | Instantané d'état interne (DB, table d'audit, dépendances, modules). |

## Endpoints transversaux (namespace `postelio/v1`)

| Méthode | Route | Accès | Description |
|---|---|---|---|
| GET | `/health` | public | État interne (`ok`/`degraded`, checks, modules). 200 ou 503. |
| GET | `/version` | public | Versions plateforme / API / core / PHP / WP + modules. |
| GET | `/config` | public | Config publique non sensible (filtre `postelio/public_config`). |
| GET | `/me` | authentifié | Identité transversale (id, rôles, capabilities `pst_*`) ; enrichie plus tard par `postelio-users` via le filtre `postelio/me`. |

En permaliens simples, utiliser `?rest_route=/postelio/v1/health` ; en permaliens
« jolis », `/wp-json/postelio/v1/health`.

## Événements émis par le core

`core.ready`, `plugin.registered` (via le registry), `rest.routes_registering`
(signale aux plugins métier qu'ils peuvent enregistrer leurs routes).
Le core **écoute tout** (`postelio/event`) pour l'audit log.

## Activation / désactivation

- **Activation :** crée/synchronise les rôles + capabilities, exécute les migrations
  (crée `wp_postelio_audit_log`), fixe `postelio_platform_version`.
- **Désactivation :** **non destructive** — conserve tables, rôles et données ; ne
  nettoie que les tâches cron Postelio.
- **Désinstallation :** non destructive par défaut. Pour tout supprimer, définir
  l'option `postelio_delete_data_on_uninstall` à `true` avant de supprimer le plugin.

## Tests

```bash
# Tests unitaires (sans WordPress ni PHPUnit)
php plugins/postelio-core/tests/run-unit.php

# Smoke test sur WordPress vivant (via WP-CLI)
wp eval-file plugins/postelio-core/tests/smoke.php --path=wordpress
```

## Conventions

PSR-4 / PSR-12, namespace `Postelio\Core`, tables `wp_postelio_*`, options
`postelio_*`, hooks `postelio/<domaine>.<action>`, capabilities `pst_*`, rôles
`postelio_*`. Voir [`docs/backend/implementation-plan.md`](../../docs/backend/implementation-plan.md).

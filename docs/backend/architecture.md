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
| `postelio-billing` | Renouvellement d'offre, paiements, factures | [plugins.md](plugins.md#postelio-billing) |
| `postelio-skills` | Savoir-faire candidat + contenus entreprise | [plugins.md](plugins.md#postelio-skills) |

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

## 6. Ce qui est HORS de ce lot

- CPT métier, tables, endpoints complets, migrations exécutées.
- Intégrations réelles : Stripe, e-mail transactionnel, Sirene/RNE, modération, anti-bot.
- Toute modification du front (HTML/CSS/JS).
- Le Lot 01 (Core) — **ne pas démarrer** avant validation de cette architecture.

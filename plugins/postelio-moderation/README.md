# Postelio Moderation (Lot 11)

Modération **centralisée** de la plateforme : **signalements** utilisateurs (réactif) + une
**passerelle préventive** (`postelio/moderation/evaluate`, messages/offres) adossée à un
**moteur de règles LOCAL**. Le domaine **décide** ; il **délègue l'exécution** aux domaines
propriétaires via leurs contrats publics (jamais d'`UPDATE` direct d'une autre table).
**Aucun provider externe en V1** (pas d'API tierce, pas d'OpenAI, pas de dépendance Composer).
Revue **humaine** par les modérateurs/admins.

## Architecture
```
Réactif  : POST /moderation/reports → ReportService → CaseService.open_or_attach
Préventif: domaine (messaging/jobs) → filtre postelio/moderation/evaluate → ModerationGateway
                                          ├─ LocalRuleEngine (V1, pur)
                                          └─ ModerationProvider (interface ; AUCUN branché en V1)

CaseService (machine à états) ─┬─ CaseRepository        → wp_postelio_moderation_cases
                               └─ CaseEventRepository   → wp_postelio_moderation_case_events (append-only)
ReportService ───────────────── ReportRepository       → wp_postelio_moderation_reports

Décision → ModerationActions (délégation, jamais d'écriture d'une autre table) :
   hide/unhide          → JobSourcesModeration        (offres externes/natives : visibilité)
   close_conversation   → MessagingDirectory
   suspend_job          → JobModeration               → JobService.admin_transition
   suspend_company      → CompanyModeration           → VerificationService.decide
   suspend_user         → UserModeration              (révoque jetons + sessions ; réversible)
```
Découplage strict : la modération dépend des domaines, jamais l'inverse. Les domaines
appellent la modération par **filtre** (aucune dépendance de classe → pas de cycle).

## Trois tables (EXACTEMENT, décision figée)
- `wp_postelio_moderation_reports` — signalements bruts (`reporter_user_id` **interne**).
- `wp_postelio_moderation_cases` — **1 case active par ressource** (regroupement).
- `wp_postelio_moderation_case_events` — historique **append-only** (décisions/actions/notes).

Pas de table `decisions`/`cache`/`preferences`/`appeal`. Migration **idempotente**
(`CREATE TABLE IF NOT EXISTS` + dédent). Index composite `dedup` en **préfixe** de colonne
(clé ≤ 1000 octets, contrainte MyISAM/InnoDB observée sur l'install). **Jamais** de
hard-delete de contenu métier ; désactivation = **non destructive**.

## Machine à états d'une case
`open → in_review | escalated` · `in_review → resolved | dismissed | escalated` ·
`escalated → in_review` · `resolved`/`dismissed` **terminaux**. Statuts **actifs**
(1 seul par ressource) : `open`, `in_review`, `escalated`. Après une case terminale, un
nouveau signalement de la même ressource **ouvre une nouvelle case**.

## Passerelle préventive (fail modes figés)
`LocalRuleEngine` → risque `low|medium|high|critical` :
- **low** → `allowed`.
- **medium** → `review_required` : le contenu **passe** + une case est **ouverte/rattachée**
  (send + flag). Ex. coordonnées de contact (`contact_bypass`) = medium **contextuel**.
- **high/critical** → `blocked` (**fail-closed**).

Application par domaine :
- **Messagerie** (`MessagingService::send`) : évaluation **pré-insert**. `blocked` ⇒ **aucune
  ligne**, **aucun** `message.created`, erreur générique `moderation_blocked` (422). `medium`
  ⇒ message envoyé + case (case rattachée à la **conversation**, pas de spam de cases).
- **Offres** (`JobService::publish`) : **gate de pré-publication**. `blocked` (high/critique)
  ⇒ l'offre **reste en brouillon**, `moderation_blocked`. `medium` ⇒ publication + flag.
  **Jamais** d'état `pending` (ni pour l'offre ni pour le message).

Le message d'erreur utilisateur est **générique** : il n'expose **jamais** les règles de
détection ni les reason codes.

## Provider (V1 : local uniquement)
Interface `ModerationProvider` (`name`/`is_available`/`moderate_text`) prête pour un futur
provider externe branchable via `postelio/moderation/provider` ; **aucun** n'est fourni en V1.
`FakeModerationProvider` sert uniquement aux tests. `GET /moderation/health` renvoie
`provider: local_only`.

## Rôles & permissions (réutilisés, aucun nouveau)
- **Modérateur** (`pst_view_moderation_queue`, `pst_moderate_content`, `pst_decide_report`) :
  file, assignation, revue, note, `no_action`/`hide`/`unhide`/`close_conversation`/`warning`/
  `dismiss`/`escalate`.
- **Admin** (toutes caps) : en plus `suspend_user`/`suspend_company`/`suspend_job` (+ inverses).
- **Support** : **aucun** accès modération (aucune capability ajoutée).

Les actions admin sont gardées par capability dans `ModerationActions` (double garde
défense-en-profondeur en plus du `permission_callback` de l'endpoint).

## API
- `POST /moderation/reports` (`pst_report_content`) — **générique** :
  `{resource_type, resource_uuid, reason_code, description?}`. Statuts renvoyés génériques ;
  `duplicate` en cas de dédoublonnage. Ressource inconnue/inaccessible → **404**
  (non-divulgation).
- `GET /me/moderation/reports` — ses propres signalements, statut **générique**
  (`received|under_review|resolved`), **jamais** d'ID SQL, de note interne ni de reporter tiers.
- `GET /moderation/cases` (`pst_view_moderation_queue`) — file paginée + filtres
  `status|priority|resource_type`.
- `GET /moderation/cases/{uuid}` — détail + historique (notes internes **visibles ici seulement**).
- `POST /moderation/cases/{uuid}/assign` (`pst_decide_report`).
- `POST /moderation/cases/{uuid}/decision` (`pst_moderate_content`) —
  `{action, reason_codes?, note?, resolve?, target?}`. `target {type,uuid}` permet p.ex. de
  suspendre l'**auteur** (par UUID public) depuis une case de contenu.
- `POST /moderation/cases/{uuid}/note` (`pst_moderate_content`).
- `GET /moderation/health` — compteurs + `provider`.

UUID publics (v4) uniquement, lus depuis les **params d'URL** ; les IDs SQL ne sont **jamais**
exposés.

## Contrats additifs (autres domaines, non destructifs)
- `postelio-users` : `UserModeration` (suspend/unsuspend/is_suspended — révoque jetons + sessions,
  respecte `AccountService::META_STATUS`, ne touche jamais `wp_users`) ; `UserDirectory`
  (`public_uuid`/`id_from_public_uuid`/`is_active`).
- `postelio-jobs` : `JobModeration` (suspend/unsuspend → `JobService::admin_transition`) ;
  `CompanySuspensionSync` (écoute `company.suspended` → suspend les offres **actives** de
  l'entreprise, `notify:false` pour éviter le doublon de notification) ; gardes `is_active`
  (create/publish) + gate de pré-publication.
- `postelio-companies` : `CompanyModeration` (suspend/unsuspend → `VerificationService::decide`).
- `postelio-job-sources` : `JobSourcesModeration` (hide/unhide/is_visible via `local_visibility`).
- `postelio-messaging` : `send()` appelle la passerelle + garde `is_active`.
- `postelio-interviews` : garde `is_active` sur `propose`.
- `postelio-core` : code d'erreur `moderation_blocked` (422) ajouté au catalogue stable.

## Événements
`moderation.report_created`, `moderation.case_opened`, `moderation.review_started`,
`moderation.decision_made`, `moderation.content_hidden|content_restored`,
`moderation.user_warned`. La suspension d'offre/entreprise/utilisateur notifie via les
événements **propriétaires** (`job.suspended`/`company.suspended`/`user.suspended`) — la
modération **ne duplique jamais** ces notifications.

## Sécurité / RGPD
- Non-divulgation systématique (404) : signaler une ressource inconnue ne révèle pas son existence.
- La modération **stocke le nécessaire** : reason codes, métadonnées, description libre du
  signalement — **jamais** le contenu complet d'un message/offre/CV.
- `reporter_user_id` reste interne (anonymat vis-à-vis du contenu signalé).
- Rate-limit + déduplication des signalements (filtres `report_rate_per_hour`, `report_dedup_window`).
- Suspension utilisateur **réversible** : statut + révocation jetons/sessions ; aucune donnée détruite.

## Tests
- `tests/run-unit.php` (55, sans WP) : `CaseStateMachine` (transitions/terminal/actif),
  `ReasonCodes` (catalogue + politique par ressource + priorités), `LocalRuleEngine`
  (classification low/medium/high/critical), `ModerationDecision` (message générique sans
  fuite de règle), `EvaluationRequest`.
- `tests/smoke.php` (65, WP vivant) : activation/3 tables ; signalements (validation,
  non-divulgation, dedup, rate-limit, grouping + priorité max, statut générique, ownership,
  non-exposition) ; file (rôles dont **support interdit**, assign, note append-only, décision/
  transitions, 409 sur case clôturée, nouvelle case après clôture, action admin interdite au
  modérateur) ; passerelle (publish fail-closed + `is_active`, message low/medium/critical) ;
  actions déléguées (suspend user/company + **cascade offres**, hide/unhide) ; santé ;
  événements sans doublon.

## Décisions FIGÉES (V1)
3 tables · pas d'état `pending` · contact = medium contextuel · endpoint report **générique** ·
`UserModeration` dans users · suspension entreprise → cascade offres (listener Jobs, découplé) ·
support sans accès · provider **local uniquement** · exécution **déléguée** (aucun `UPDATE`
d'une autre table) · pas d'exposition d'ID SQL / de note interne / de règle de détection ·
front **non modifié**, aucune UI de modération créée.

## Points À VALIDER (hors code)
- Réglage fin des listes `blocklist_critical` / `watchlist` selon usage réel.
- Cadence/priorisation de la file selon volume observé.
- Éventuel futur provider externe (interface prête) et politique d'appel.
- Accès fin « participant » pour le signalement de message (raffinement futur documenté).

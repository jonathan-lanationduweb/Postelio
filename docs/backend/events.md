# Postelio — Événements internes

Bus d'événements du core (`Postelio\Core\Events`). Convention : `postelio/<domaine>.<action>`.
Les plugins **émettent** et **écoutent** ; jamais d'appel direct inter-plugins métier.
`postelio-core` écoute **tout** pour l'audit log.

## Catalogue d'événements

| Événement | Émetteur | Consommateurs |
|---|---|---|
| `user.created` | users | companies (rattacher recruteur), notifications, core(audit) |
| `user.updated` | users | core(audit) |
| `user.deleted` | users | files (purge CV), applications (anonymiser), messaging, core(audit) |
| `candidate.profile_updated` | users | jobs (recalcul reco/alertes), core(audit) |
| `company.created` | companies | notifications, core(audit) |
| `company.updated` | companies | core(audit) |
| `company.verification_requested` | companies | moderation/admin, notifications, core(audit) |
| `company.verified` | companies | notifications (recruteur), jobs (badge), core(audit) |
| `company.suspended` | companies | jobs (dépublier), notifications, core(audit) |
| `company.followed` | companies | core(audit) |
| `job.created` | jobs | moderation (si review), core(audit) |
| `job.published` | jobs | notifications (suiveurs + alertes), core(audit) |
| `job.expiring` | jobs (cron) | notifications (recruteur) — *billing n'écoute PAS (achat initié par l'utilisateur)* |
| `job.expired` | jobs (cron) | notifications, core(audit) |
| `job.renewed` | jobs | notifications (recruteur, e-mail `job_renewed`), core(audit) — *émis après fulfillment billing (Lot 12), une seule fois (exactly-once)* |
| `job.filled` | jobs | applications (info), notifications, core(audit) |
| `alert.created` | jobs | core(audit) |
| `favorite.added` | jobs | core(audit) |
| `application.created` | applications | files (snapshot CV), messaging (créer conversation), notifications (recruteur), core(audit) |
| `application.status_changed` | applications | notifications (candidat), interviews (cohérence), core(audit) |
| `application.rejected` | applications | notifications (candidat, message courtois), core(audit) |
| `application.selected` | applications | notifications, jobs (proposer filled), core(audit) |
| `application.withdrawn` | applications | notifications (recruteur), interviews (annuler), core(audit) |
| `cv.uploaded` / `cv.replaced` / `cv.deleted` | files | core(audit) |
| `cv.snapshot_created` | files | applications, core(audit) |
| `conversation.created` | messaging | notifications (interlocuteur), core(audit) — *Lot 07* |
| `message.created` | messaging | notifications (destinataire), core(audit — **jamais le body**) — *Lot 07* |
| `conversation.read` | messaging | (métrique) — *Lot 07 ; remplace `message.read`* |
| `conversation.closed` | messaging | notifications, core(audit) — *Lot 07* |
| `message.reported` | messaging | moderation, core(audit) — *hook futur* |
| `interview.proposed` | interviews | notifications (candidat), core(audit) — *Lot 08* |
| `interview.confirmed` | interviews | notifications (recruteur), core(audit) — *Lot 08* |
| `interview.reschedule_requested` | interviews | notifications (recruteur), core(audit) — *Lot 08 ; candidat propose un autre créneau* |
| `interview.rescheduled` | interviews | notifications (deux côtés), core(audit) — *Lot 08 ; créneau changé/accepté* |
| `interview.declined` | interviews | notifications (recruteur), core(audit) — *Lot 08 ; remplace `interview.rejected`* |
| `interview.cancelled` | interviews | notifications (candidat), core(audit) — *Lot 08* |
| `interview.completed` | interviews | notifications, core(audit) — *Lot 08 ; marquage manuel (cron futur)* |
| `notification.created` | notifications | (interne — **jamais réécouté** par notifications) — *Lot 09* |
| `notification.read` | notifications | (métrique interne) — *Lot 09* |
| `email.queued` / `email.sent` / `email.failed` | notifications | (observabilité interne ; **jamais réécouté** → anti-boucle) — *Lot 09* |
| `order.created` | billing | core(audit) — *Lot 12* |
| `checkout.created` | billing | core(audit) — *Lot 12 ; session Checkout ouverte* |
| `payment.succeeded` | billing | core(audit) — *Lot 12 ; déclenche la délégation à `JobLifecycle` (exactly-once)* |
| `payment.failed` | billing | notifications (recruteur), core(audit) — *Lot 12* |
| `payment.refunded` | billing | core(audit) — *Lot 12 ; PAS de rollback des jours ajoutés* |
| `payment.disputed` | billing | core(audit) — *Lot 12 ; PAS de suspension automatique* |
| `renewal.applied` | billing | core(audit) — *Lot 12 ; effet métier réel via `job.renewed` (émis par jobs)* |
| `fulfillment.failed` | billing | core(audit) — *Lot 12 ; retry `postelio_15min`, max 5* |
| `order.manual_review` | billing | notifications (file admin, option), core(audit) — *Lot 12 ; doublon / attempts épuisés* |
| `skill.created` | skills | core(audit) — *Lot 13 ; brouillon créé* |
| `skill.updated` | skills | core(audit) — *Lot 13* |
| `skill.published` | skills | core(audit) — *Lot 13 ; **ne s'auto-notifie pas** l'auteur* |
| `skill.archived` | skills | core(audit) — *Lot 13* |
| `skill.hidden` | skills | core(audit) — *Lot 13 ; masquage modération (`pst_mod_hidden`)* |
| `skill.restored` | skills | core(audit) — *Lot 13 ; démasquage* |
| `skill.comment_created` | skills | notifications (auteur du savoir-faire), core(audit) — *Lot 13* |
| `moderation.report_created` | moderation | (interne : ouvre/rattache un **cas**), core(audit) — *Lot 11 ; remplace `content.reported`* |
| `job_source.sync_started` / `.sync_completed` / `.sync_failed` | job-sources | (admin/observabilité — **jamais** Notifications) — *Lot 10* |
| `external_job.created` / `.updated` / `.removed` | job-sources | (interne domaine offres) — *Lot 10* |
| `external_job.apply_redirected` | job-sources | (analytics — **jamais** une candidature) — *Lot 10* |
| `moderation.case_opened` | moderation | notifications (file admin), core(audit) — *Lot 11* |
| `moderation.review_started` | moderation | core(audit) — *Lot 11* |
| `moderation.decision_made` | moderation | domaines propriétaires (exécution **déléguée**), core(audit) — *Lot 11 ; remplace `moderation.decided`* |
| `moderation.content_hidden` | moderation | core(audit) — *Lot 11* |
| `moderation.content_restored` | moderation | core(audit) — *Lot 11* |
| `moderation.user_warned` | moderation | notifications (option), core(audit) — *Lot 11* |

> **Suspensions (Lot 11) :** les notifications de suspension passent par les événements
> **propriétaires** (`job.suspended` / `company.suspended` / `user.suspended`) — la
> modération ne **duplique jamais** ces notifications ; ces événements ne créent pas de
> doublon de Notification.

## Matrice de notifications

Canaux : **in-app** (cloche), **email** (transactionnel), *push Tauri* (plus tard).
`—` = pas de notification.

> **Implémentée au Lot 09** — la matrice V1 effective (avec obligatoires, différés,
> priorités, actions) est documentée dans `plugins/postelio-notifications/README.md`.
> Écarts figés vs le tableau ci-dessous (décisions D2/D4/D12) : `application.status_changed`
> / `reviewed` / `shortlisted` / `interview` ⇒ **aucune** notification (états internes) ;
> `application.created` côté candidat ⇒ **e-mail d'accusé seul** (pas d'in-app) ;
> `message.created` e-mail ⇒ **différé 5 min, conditionnel à la non-lecture, 1/conv/30 min** ;
> `interview.confirmed` ⇒ e-mail de **preuve** au candidat (obligatoire) + notif recruteur ;
> `company.verification_requested`, `interview.completed`, alertes offres/reco ⇒ **futur**.

| Événement | Candidat | Recruteur | Admin | Email ? | In-app ? |
|---|---|---|---|---|---|
| application.created | — | ✅ | — | ✅ recruteur | ✅ recruteur |
| application.status_changed | — | — | — | — | — *(interne, D2)* |
| application.rejected | ✅ (msg courtois) | — | — | ✅ | ✅ |
| application.selected | ✅ | — | — | ✅ | ✅ |
| application.withdrawn | — | ✅ | — | option | ✅ recruteur |
| message.created | ✅ (dest.) | ✅ (dest.) | — | option | ✅ |
| interview.proposed | ✅ | — | — | ✅ | ✅ |
| interview.confirmed | — | ✅ | — | ✅ | ✅ |
| interview.rescheduled | — | ✅ | — | ✅ | ✅ |
| interview.cancelled | ✅ | ✅ | — | ✅ | ✅ |
| interview (rappel J-1) | ✅ | ✅ | — | ✅ | ✅ |
| job.published (suiveurs/alertes) | ✅ | — | — | selon fréquence alerte | ✅ |
| job.expiring | — | ✅ | — | ✅ | ✅ |
| company.verified | — | ✅ | — | ✅ | ✅ |
| company.verification_requested | — | — | ✅ | option | ✅ file admin |
| job.renewed (Lot 12) | — | ✅ | — | ✅ (`job_renewed`) | ✅ recruteur |
| payment.succeeded (Lot 12) | — | — | — | — *(reçu = e-mail Stripe, pas Notifications)* | — *(interne/observabilité)* |
| payment.failed | — | ✅ | — | ✅ | ✅ |
| moderation.case_opened / decision_made | — | — | ✅ | option | ✅ file admin |
| skill.comment_created (Lot 13) | ✅ (si auteur) | ✅ (si auteur) | — | option | ✅ auteur du savoir-faire |

> **Modération (Lot 11) :** une **suspension** (utilisateur/entreprise/offre) notifie via
> l'événement **propriétaire** (`*.suspended`) ; la modération ne crée **aucune**
> notification en double.

> Les préférences de notification (candidat : nouvelles offres, changement de statut,
> nouveau message, proposition d'entretien, rappel, conseils) existent déjà côté front
> et pilotent l'activation par canal.

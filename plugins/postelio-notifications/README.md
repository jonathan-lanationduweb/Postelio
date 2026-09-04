# Postelio Notifications (Lot 09)

Notifications **in-app** (cloche/centre) et **e-mails transactionnels**, pilotés par les
**événements** des autres plugins. Le plugin est **réactif** : il écoute, décide des
canaux, et n'appelle **jamais** `wp_mail()` directement. Aucune modification du front dans
ce lot (voir mapping plus bas).

## Architecture
```
Applications / Messaging / Interviews / Jobs / Companies
        └── emit(event)                         (contrats publics + Events core)
                 ↓
 NotificationRouter  → applique la MATRICE + préférences + acteur≠destinataire + dedup
        ├── NotificationService → notification in-app (idempotente, group_key)
        └── EmailDispatcher     → file d'attente e-mail (idempotente, retry/backoff)
                 ↓  worker Core\Jobs\Scheduler (postelio_15min)
            EmailProvider (interface) ← WpMailProvider (V1 dev)
```
Le Router **n'écoute jamais** ses propres événements (`notification.*` / `email.*`) : pas
de boucle. Il passe par les contrats publics (`CompanyDirectory`, `JobDirectory`,
`MessagingDirectory`, `InterviewDirectory`, `UserDirectory`) — jamais les tables d'un
autre plugin.

## Tables
- **`wp_postelio_notifications`** : in-app. `dedup_key` **UNIQUE** (idempotence),
  `group_key` (regroupement/obsolescence), `read_at` / `resolved_at`, `action_type` +
  `resource_type` + `resource_uuid` + `action_payload` (action **structurée**, pas d'URL
  absolue), `priority` (`normal|important|critical`). UUID public uniquement.
- **`wp_postelio_notification_deliveries`** : file multi-canal (email V1, push futur).
  `dedup_key`+`channel` **UNIQUE**, `status` (`pending|processing|sent|failed|skipped`),
  `attempts`/`max_attempts`, `scheduled_at`, backoff, `provider_message_id`, `last_error`.
- **Préférences** : `user_meta` `pst_notification_prefs` (JSON versionné) — pas de table.

## Idempotence & retry
- `dedup_key = n|e:{type}:{resource_uuid}:{user_id}[:{variant|bucket}]`. Un événement
  rejoué ne crée **ni** 2 notifications **ni** 2 e-mails (contrainte UNIQUE).
- File : claim atomique (`pending`→`processing`), envoi, `sent` (jamais renvoyé) ou échec
  → `pending` avec **backoff** (2,4,8… min, plafonné 1 h) tant que `attempts < max`, puis
  `failed`. Un `processing` abandonné > 5 min est récupérable.

## Précision d'envoi (file e-mail)
Chaque `enqueue` planifie un **one-shot** `Core\Jobs\Scheduler` à l'**échéance exacte** de
la livraison (`scheduled_at`), en plus du worker récurrent `postelio_15min` (filet de
sécurité pour les ticks manqués et les retries/backoff). Une livraison prévue à T+5 min ne
dépend donc **pas** du seul cycle 15 min. **Précision réelle** : bornée par le déclenchement
WP-Cron — quasi immédiate avec un vrai cron système (recommandé en prod, ~1 min), sinon
liée au trafic. **Si WP-Cron est retardé** : la livraison reste `pending` et part au tick
suivant (jamais perdue) ; le worker récurrent la rattrape. La valeur 5 min reste filtrable
(`postelio/notifications/message_email_delay`).

## Anti-spam messages (D4)
`message.created` → in-app **immédiat** ; e-mail **différé 5 min** (filtre
`postelio/notifications/message_email_delay`), envoyé **uniquement si la conversation est
encore non lue** (`MessagingDirectory::has_unread_in_conversation`, pas de présence
temps réel), **1 e-mail max par conversation/destinataire / fenêtre 30 min** (filtre
`…/message_email_window`, via un *bucket* dans la `dedup_key`). `conversation.read` résout
les notifs in-app du fil et passe les e-mails en attente en `skipped`.

## Rappels d'entretien (D5)
À la confirmation : **J-24 h** (in-app + e-mail) et **H-1 h** (in-app) planifiés via
`Core\Jobs\Scheduler` (`iv_reminder_24h` / `iv_reminder_1h`, offsets filtrables). Annulés
sur annulation/reprogrammation/refus ; replanifiés seulement si l'entretien redevient
`confirmed`. Un rappel qui se déclenche vérifie que l'entretien est toujours `confirmed`.

## Préférences (D6)
Catalogue serveur autoritaire (rôle, marketing ?, défauts par canal in_app/email). Le
client ne peut pas modifier une catégorie hors rôle ; les types **obligatoires** (D8)
ignorent les préférences. Séparation **transactionnel** (nécessaire au service) vs
**marketing** (`offers_reco`, `news`, `newsletter` — e-mail OFF par défaut, opt-in,
désinscription à venir).

**Obligatoires V1 (non désactivables, e-mail toujours envoyé) :** `interview_cancelled`,
`interview_confirmed_proof` (preuve candidat), `company_suspended` (+ futurs événements de
sécurité). Le reste est ON par défaut mais configurable.

## Compteurs (D9)
**Sémantique du badge** : `unread-count` = notifications **non lues ET non résolues ET non
expirées**. Une notification « action requise » devenue caduque (ex. « Confirmez votre
entretien » après confirmation, ou les messages d'un fil une fois la conversation lue) est
marquée `resolved_at` → elle **ne gonfle plus le badge**, mais reste **consultable dans la
liste** (historique). `read_at` ≠ `resolved_at` : lu (par l'utilisateur) vs devenu caduc
(par le système). Le filtre `?unread=1` suit la même sémantique.

Cloche = `GET /me/notifications/unread-count` (notifications in-app non lues). Messagerie =
`MessagingDirectory::unread_count` (messages non lus). **Compteurs distincts** en base ;
un message crée une notification, mais les compteurs restent indépendants.

## Actions web + Tauri (D11)
Aucune URL absolue stockée : `action_type` (`open_interview|open_conversation|
open_application|manage_job|company_profile`) + `resource_type` + `resource_uuid`. Le web
et Tauri traduisent. L'e-mail embarque un lien de deep-link (`postelio/notifications/
web_base_url` filtrable) ; **aucune action sensible par GET**, jamais de token en URL.

## Événements source réellement écoutés
`application.created`, `application.selected`, `application.rejected`,
`application.withdrawn` · `message.created`, `conversation.read` · `interview.proposed`,
`interview.confirmed`, `interview.declined`, `interview.reschedule_requested`,
`interview.rescheduled`, `interview.cancelled` · `company.verified`, `company.rejected`,
`company.suspended` · `job.expiring`, `job.expired`, `job.suspended`.

**Ignorés volontairement (D2)** : `application.status_changed`, `application.reviewed`,
`application.shortlisted`, `application.interview` (états internes du pipeline),
`interview.completed`.

## Matrice V1 implémentée (extrait)
| Événement | Destinataire | In-app | Email | Oblig. | Diff. | Prio |
|---|---|---|---|---|---|---|
| application.created | recruteur (créateur+owner) | ✅ | pref | non | immédiat | normal |
| application.created | candidat | ❌ | ✅ (accusé) | non | immédiat | normal |
| application.selected | candidat | ✅ | pref | non | immédiat | important |
| application.rejected | candidat (sans motif) | ✅ | pref | non | immédiat | important |
| application.withdrawn | recruteur | ✅ | ❌ | non | immédiat | normal |
| message.created | destinataire | ✅ | différé/condition | non | 5 min | normal |
| interview.proposed | candidat | ✅ | pref | non | immédiat | important |
| interview.confirmed | recruteur | ✅ | pref | non | immédiat | normal |
| interview.confirmed | candidat (preuve) | ❌ | ✅ | **oui** | immédiat | important |
| interview.declined | recruteur | ✅ | pref | non | immédiat | normal |
| interview.reschedule_requested | recruteur | ✅ | pref | non | immédiat | important |
| interview.rescheduled | candidat | ✅ | pref | non | immédiat | important |
| interview.cancelled | l'autre partie | ✅ | ✅ | **oui** | immédiat | important |
| interview.reminder (24h/1h) | candidat + recruteur | ✅ | 24h oui / 1h non | non | planifié | important |
| company.verified | owner | ✅ | pref | non | immédiat | normal |
| company.rejected | owner (sans motif) | ✅ | pref | non | immédiat | important |
| company.suspended | owner | ✅ | ✅ | **oui** | immédiat | **critical** |
| job.expiring / expired / suspended | recruteur (créateur+owner) | ✅ | pref | non | immédiat | normal/important |

Multi-recruteurs (D3) : créateur de l'offre **+** owner, dédupliqués ; jamais tous les
membres. Acteur ≠ destinataire (D12) partout (exception : e-mail de preuve au candidat).

## Provider e-mail (D7)
`EmailProvider` (interface) ; V1 dev = `WpMailProvider` (`wp_mail`). Production =
provider transactionnel réel (non choisi), branché via `postelio/notifications/
email_provider`. Templates : `TemplateRegistry` (subject/preheader/body/CTA/variables,
texte V1 ; branding #17324D/#FF6B6B et HTML responsive à venir).

## API
`GET /me/notifications` (paginé ; `?unread=1&type=`) · `GET /me/notifications/unread-count`
· `POST /me/notifications/{uuid}/read` · `POST /me/notifications/read-all` ·
`GET|PUT /me/notification-preferences`. Ownership strict (un utilisateur ne voit que ses
notifications). Compte supprimé/inactif → aucune notification/livraison.

## Contrat public
`Postelio\Notifications\Api\NotificationDirectory` : `unread_count($user)`,
`recent($user, $limit)` — lecture seule (cloche/header). Les autres plugins ne créent
jamais de notification directement : ils émettent des événements.

## Sécurité / audit
Jamais dans une notif/e-mail/livraison : motif interne, note recruteur, reviewer, id SQL,
token, clé de stockage. Contenu dynamique neutralisé (`sanitize_text_field` /
`sanitize_textarea_field`). Le worker journalise statut/`provider_message_id`, jamais le
corps complet.

## Temps réel (D17)
V1 = **polling REST léger** (le front rafraîchit `unread-count`). Pas de WebSocket/SSE.
Architecture multi-canal prête pour un futur `PushProvider` (Tauri).

## Mapping front (à brancher plus tard — front NON modifié)
| Front actuel (simulé) | Remplacement API |
|---|---|
| `navigation.js` cloche `.notif-badge` (seed `ss_notifs_*`) | `GET /me/notifications/unread-count` |
| dropdown `.notif-list` (localStorage) | `GET /me/notifications` (+ groupes Aujourd'hui/Hier) |
| item `href` (page#ancre) | `action_type` + `resource_uuid` (résolu client) |
| « Tout marquer comme lu » | `POST /me/notifications/read-all` |
| clic item | `POST /me/notifications/{uuid}/read` + navigation |
| `#notif-form` (recruteur) / `#set-notif-*` (candidat) → `ss_employer_settings`/`ss_candidate_settings` | `GET|PUT /me/notification-preferences` |
| badge messagerie | `MessagingDirectory::unread_count` (distinct) |

## Dépendances additives introduites (autres plugins, non-destructives)
- `applications` : `application.*` porte `application_uuid` + `job_uuid` (D1).
- `interviews` : `interview.*` porte `actor_user_id` (exclusion de l'acteur).
- `jobs` : `JobDirectory::created_by()`, `uuid_of()`, `title_of()`.
- `companies` : `CompanyDirectory::owner_of()`.
- `messaging` : `MessagingDirectory::has_unread_in_conversation()` ; `conversation.read`
  porte `conversation_uuid`.
- `core` : capabilities inchangées (lecture notifs = `read`, présente sur tous les rôles).

## Points À VALIDER (restants)
- Provider e-mail de production (non choisi).
- Digest marketing (offres/reco) — conçu, non implémenté V1.
- Notifier plusieurs recruteurs au-delà de créateur+owner (assignation) — futur.
- Bounces/complaints provider — hors V1.

## Diagnostic du service e-mail (admin)
- `WpMailProvider` capture `wp_mail_failed` : `last_error` porte la cause PHPMailer
  (`wp_mail_failed: <message> (code N)`) au lieu d'un booléen ; `WpMailProvider::transport()`
  décrit le transport réellement actif (WP Mail SMTP, SMTP via `phpmailer_init`, PHP `mail()`
  sendmail ou SMTP php.ini) — jamais de secret.
- `EmailDispatcher::transport()`, `send_test($user_id)` (même provider, **hors file**, destinataire =
  adresse courante de l'utilisateur, résultat conservé dans l'option
  `postelio_notifications_email_test` sans contenu), `last_test()`, `mask_email()`.
- Contrat `NotificationDirectory` : `transport()`, `delivery_failures($limit)` (destinataires
  masqués), `send_test()`, `last_test()`. Consommé par l'écran Postelio → Notifications.
- **Pas de relance** d'une livraison `failed` : aucune opération sûre n'existe (gap documenté dans
  `docs/backend/admin-backoffice.md` §11) ; corriger le transport puis vérifier par un e-mail de test.
- `wp_mail() === true` = remise au transport, pas une preuve de livraison.

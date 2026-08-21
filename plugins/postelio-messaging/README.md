# postelio-messaging

Messagerie candidat ↔ recruteur (Lot 07), **contextualisée par une candidature**.
Dépend de **core, users, companies, applications** ; réutilise leurs contrats
(`ApplicationDirectory`, `CompanyDirectory`, `UserDirectory`) et l'infra du core.
**Aucune lecture directe des tables d'autres plugins ; aucune infra dupliquée.**

> Hors périmètre : entretiens, notifications réelles (e-mail), paiement, modération
> complète, pièces jointes (le CV reste géré par postelio-files).

## Principe central — pas de contact arbitraire
Un recruteur ne peut contacter un candidat **que** via une candidature d'une offre de
**son entreprise** (résolu par `ApplicationDirectory::context` + `CompanyDirectory::is_member`).
Connaître un UUID ne donne aucun accès → hors périmètre = **404** (non-divulgation).

## Tables
- `wp_postelio_conversations` — 1 par candidature (**UNIQUE(application_id)** ⇒
  concurrence gérée en base). Contexte figé : `application_uuid`, `job_uuid`,
  `company_uuid`, `company_name`, `subject` (titre offre au moment T), `candidate_user_id`,
  `status` (active|closed|archived), `last_message_at`.
- `wp_postelio_conversation_participants` — état de lecture **par utilisateur** via un
  curseur **monotone** `last_read_message_id` (+ `last_read_at` informatif) ⇒ lu/non-lu
  multi-participant (plusieurs recruteurs par entreprise). **UNIQUE(conversation_id, user_id)**.
- `wp_postelio_messages` — **immuables (D6)** : jamais d'UPDATE du `body`. `status`
  (sent|deleted), `deleted_at`. Ordre déterministe `(created_at, id)`.

## Participants / conversation partagée
La conversation appartient au **contexte entreprise + candidature**, pas au premier
recruteur. Tout recruteur **membre** de l'entreprise peut la reprendre (participant créé
paresseusement à l'accès, avec son propre curseur `last_read_message_id`). Le candidat
est toujours participant. *(Décision V1 documentée.)*

## Endpoints (`postelio/v1`, UUID) — API unifiée
| Méthode | Route | Accès |
|---|---|---|
| GET | `/me/conversations` | `pst_send_message` (candidat ou recruteur) |
| GET | `/me/conversations/{uuid}` | participant/membre |
| GET | `/me/conversations/{uuid}/messages` | idem — **curseur** `before`=uuid, `limit`≤100 |
| POST | `/me/conversations/{uuid}/messages` | `pst_send_message` **+ `pst_email_verified`** |
| POST | `/me/conversations/{uuid}/read` | participant/membre |
| POST | `/me/conversations/{uuid}/close` | recruteur/admin |
| POST | `/companies/me/applications/{application_uuid}/conversation` | recruteur ouvre (crée si absent) — `+ pst_email_verified` |

Lecture accessible même si l'e-mail n'est plus vérifié ; seul l'**envoi** exige
`pst_email_verified`. UUID lu depuis les **params d'URL** (jamais le body).

## unread / read
`unread_count` = messages dont l'`id` interne est **> `participant.last_read_message_id`**
**et** non émis par l'utilisateur. Le curseur par **id monotone** (et non par `DATETIME`)
lève l'ambiguïté des messages créés à la **même seconde** (§33). `POST /read` avance
**uniquement** le curseur du participant courant (jamais celui de l'autre) et ne le fait
**jamais reculer** (`max(courant, nouveau)`). `MessagingDirectory::unread_count($user)` =
total (futur compteur header) — **distinct des notifications générales** (à ne pas mélanger).

## Pagination des messages
**Curseur `before`** (UUID d'un message) + `limit` — stable quand de nouveaux messages
arrivent (contrairement à page/offset). Sans `before` → dernière page. `meta.has_more`
+ `meta.before` (UUID du plus ancien renvoyé) pour charger l'historique.

## Contenu / XSS
V1 **texte uniquement** : `sanitize_textarea_field` (tags retirés → XSS inerte), trim,
vide refusé, longueur max configurable (`postelio/messaging/max_length`, 5000), Unicode
conservé. L'API renvoie du **texte** (JSON) ; le front l'affiche comme texte.

## Immuabilité / suppression / modération
Messages immuables (pas d'édition). Suppression = **logique** (`status=deleted`,
`deleted_at`) : la ligne est conservée, le presenter renvoie `deleted:true` / `body:null`
(jamais l'original). Hook de modération futur (`postelio-moderation`) : signalement/masquage
via événements/contrats — non implémenté ici.

## Contexte figé / workflow
La conversation reste consultable si l'offre expire/est pourvue/archivée, si l'entreprise
change de nom (nom courant relu via `CompanyDirectory`), ou si la candidature passe
selected/rejected. **Retrait de candidature (V1)** : historique conservé, conversation
non auto-fermée (fermeture = action explicite) — blocage d'envoi sur retrait `À VALIDER`.

## Anti-spam
Rate limiting **configurable** (`postelio/messaging/rate_limit_per_min`, 20/min) via
transient ; message vide/énorme rejeté.

## Événements (via core, sans `body` dans l'audit)
`conversation.created`, `conversation.closed`, `conversation.read`, `message.created`.
Payload `message.created` (pour postelio-notifications) : destinataire, `conversation_uuid`,
`sender_role`, contexte offre/entreprise. **Messaging n'envoie aucun e-mail.**

## Contrat public
`Postelio\Messaging\Api\MessagingDirectory` : `unread_count`, `get_conversation_context`,
`can_message`, `close_conversation`.

## Mapping front (à consommer plus tard — front non modifié)
- **Candidat** (`dashboard-candidat`) : liste conv → `GET /me/conversations` ; ouvrir →
  `GET …/{uuid}/messages` ; envoyer → `POST …/messages` ; non-lus → `unread_count` ;
  marquer lu → `POST …/read`.
- **Recruteur** (`employer-messages`, bloqué historiquement sur « Julie ») : liste →
  `GET /me/conversations` ; **changer de conversation** → `GET …/{uuid}/messages` (UUID
  distinct par conversation, plus de blocage) ; ouvrir depuis une candidature →
  `POST /companies/me/applications/{uuid}/conversation` ; **envoyer** → `POST …/messages`
  (fonctionne réellement) ; contexte candidature = `subject`/`application_uuid` ; lu/non-lu
  par participant. Les **modèles** de messages restent front (variables `{{prenom}}`) ; un
  message envoyé depuis un modèle = message normal immuable.

## Tests
```bash
php plugins/postelio-messaging/tests/run-unit.php
wp eval-file plugins/postelio-messaging/tests/smoke.php --path=wordpress
```

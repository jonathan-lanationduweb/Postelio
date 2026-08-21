# Postelio — Workflows & transitions

Chaque objet à cycle de vie a une **machine à états** appliquée **côté serveur**. Les
transitions non listées sont refusées (`error.code = invalid_transition`). Le front ne
choisit jamais un statut arbitrairement.

## Offre (Job)

**Machine à états canonique V1 (7 états, aucun état fantôme)** — implémentée dans
`postelio-jobs` (`JobStateMachine`) :
`draft, published, expiring, expired, filled, archived, suspended`.

- **`pending`** (modération d'offre) : **retiré de la V1**. Sera réintroduit par
  `postelio-moderation` ; aucun code actuel ne peut y entrer.
- **`renewed`** : **n'est pas un état persistant**. Le renouvellement est la transition
  `expired → published` (+ nouvelle échéance) accompagnée de l'événement `job.renewed`,
  déclenchée **uniquement** par `postelio-billing` après paiement (contrat
  `Postelio\Jobs\Api\JobLifecycle` ; aucun paiement en V1).

| Transition | Autorisé par |
|---|---|
| (création/édition) draft | recruteur membre — **autorisé même si l'entreprise n'est pas vérifiée** (D1) |
| draft → published | recruteur (`POST /jobs/{uuid}/publish`) — **refusé si l'entreprise n'est pas `verified`** (D1). Seul un **brouillon** est publiable en libre-service. |
| published → expiring | système (cron, J‑7) |
| published/expiring → expired | système (cron, échéance) |
| expiring/expired → published | **renouvellement** via `JobLifecycle::renew_after_payment()` (billing) — émet `job.renewed` |
| published/expiring → filled | recruteur (poste pourvu) |
| draft/published/expiring/expired/filled → archived | recruteur |
| published/expiring → suspended | admin (`POST /jobs/{uuid}/status`) |
| suspended → published | admin (réactivation ; entreprise toujours `verified` requise) |

- **États visibles publiquement : `published` et `expiring` uniquement.** `draft`,
  `expired`, `filled`, `archived`, `suspended` → invisibles (fiche publique → 404,
  absents de la liste).
- **`archived`** est terminal (on recrée via **duplication**, qui produit un nouveau
  brouillon).
- **Édition d'une offre publiée (V1) :** une offre `published`/`expiring` peut modifier
  ses champs **éditoriaux** ; l'entreprise propriétaire, l'`uuid`, le `statut` et les
  **dates système** ne sont **jamais** modifiables via l'édition (protégés par le
  lifecycle). Chaque édition incrémente une **révision métier** (`pst_revision`). Une
  future modération pourra imposer une revue sur certains changements.
- **Dates :** stockées en **date UTC (`Y-m-d`)**. Sémantique : une offre est `expired`
  lorsque `today_UTC >= date_expiration` ; `expiring` lorsque
  `date_expiration <= today_UTC + 7 j` (bornes incluses : exactement J‑7 → `expiring`,
  exactement l'échéance → `expired`).

## Candidature (Application)

États : `new → review → shortlisted → interview → selected | rejected`, et
`* → withdrawn` (candidat).

| Transition | Autorisé par |
|---|---|
| new → review | recruteur |
| review → shortlisted | recruteur |
| shortlisted → interview | recruteur (crée/lie un Interview) |
| interview → selected | recruteur |
| interview → rejected | recruteur |
| review/shortlisted → rejected | recruteur |
| new/review/shortlisted/interview → withdrawn | **candidat uniquement** |

Règles :
- Le **motif interne** de refus n'est **jamais** transmis au candidat ; seul un message
  courtois l'est (déjà le cas côté front).
- `selected` peut proposer `job.filled` (non automatique).
- Chaque transition écrit une ligne `ApplicationHistory` + émet
  `application.status_changed` (+ `application.rejected`/`selected`).

## Entretien (Interview) — implémenté Lot 08

Machine canonique **V1** (pas de `pending_candidate` redondant : `proposed` = en attente
candidat). Toutes les transitions sont contrôlées serveur (`InterviewStateMachine`).

États : `proposed`, `confirmed`, `reschedule_requested`, `declined`, `cancelled`,
`completed` (les 3 derniers terminaux).

| Transition | Autorisé par | Note |
|---|---|---|
| (création) → proposed | recruteur | candidature de son entreprise, état planifiable, e-mail vérifié |
| proposed → confirmed | candidat | |
| proposed → declined | candidat | (remplace `rejected`) |
| proposed/confirmed → reschedule_requested | candidat | propose un autre créneau (le créneau initial est conservé) |
| reschedule_requested → confirmed | recruteur | **accepte** le créneau proposé (`accept-reschedule`) |
| reschedule_requested → proposed | recruteur | **contre-propose** (via `PUT` modifier) |
| confirmed → proposed | recruteur | **modification substantielle** (date/type/lieu/lien) ⇒ **reconfirmation** candidat |
| proposed/confirmed/reschedule_requested → cancelled | recruteur | (candidat : voir À VALIDER) |
| confirmed → completed | recruteur/admin | marquage manuel (cron futur) |

- **Contexte candidature obligatoire** (via `ApplicationDirectory`) ; pipeline candidature
  → `interview` **à la proposition** ; candidature terminale (selected/rejected/withdrawn)
  ⇒ proposition refusée `409`. **Un seul entretien actif par candidature** à la fois.
- **Types** ([data-model.md](data-model.md#interview)) : visio (URL validée), sur place
  (adresse structurée, ville préremplie), téléphone (numéro + qui appelle). Dates ISO 8601
  stockées **UTC** + fuseau métier. Hors périmètre ⇒ 404.
- **Offre expirée** : n'empêche pas un entretien tant que la candidature est active (§33).
- **À VALIDER :** annulation par le candidat après confirmation ; plusieurs entretiens
  actifs simultanés ; cas offre `filled`/`archived`/`suspended`.

## Entreprise (Company) — vérification

**Machine à états canonique (6 états)** — implémentée dans
`postelio-companies` (`VerificationStateMachine`). `conflict` **n'est pas un état** :
c'est un motif (`duplicate_siren`) de `manual_review`.

États : `unverified`, `pending`, `manual_review`, `verified`, `rejected`, `suspended`.

| Transition | Autorisé par | Note |
|---|---|---|
| unverified → pending | recruteur (demande) | SIREN/SIRET valides requis |
| unverified → manual_review | système | doublon SIREN (motif `duplicate_siren`) |
| pending → verified | provider auto **ou** admin | fige `legal_verified` |
| pending → rejected | provider auto **ou** admin | motif |
| pending → manual_review | provider/système/admin | doute |
| manual_review → verified / rejected | admin | |
| manual_review → pending | recruteur | nouvelle demande |
| rejected → pending | recruteur | re-soumission |
| verified → suspended | admin | modération |
| verified → manual_review | admin | **réouverture / re-vérification** (déverrouille le légal) |
| suspended → verified / rejected | admin | réactivation / clôture |

- **Acteurs :** le recruteur ne provoque que `… → pending` (demande) et **ne peut
  jamais se déclarer `verified`** ; le provider (pendant une demande) applique
  `pending → verified|rejected|manual_review` ; l'admin applique les décisions
  (`pst_verify_company`). Toute transition non listée est refusée (`invalid_transition`).
- **Réversibilité :** aucun état n'est réellement terminal — `rejected` est
  re-soumissible, `verified` peut être suspendu ou rouvert, `suspended` réactivé.
- **`suspended` :** l'entreprise est **masquée des endpoints publics** (`GET /companies`,
  `GET /companies/{uuid}` → 404) et **ne peut pas publier d'offre** (`can_publish_jobs`
  = false).
- **Publication d'offre (D1) :** `postelio-jobs` demandera la permission via le contrat
  public `CompanyVerification::can_publish_jobs()` (V1 : vrai ssi `verified`), **sans**
  lire l'implémentation interne de `postelio-companies`.

> Correction d'une identité légale déjà vérifiée : les données `legal_verified` ne sont
> jamais modifiables par le recruteur. Le mécanisme prévu est **nouvelle déclaration →
> re-vérification** : l'admin rouvre le dossier (`verified → manual_review`), ce qui
> **déverrouille** `legal_declared`, puis une nouvelle vérification refige
> `legal_verified`. (Workflow détaillé de re-déclaration : hors Lot 03.)

> **Blocage publication (décision V1 — D1) :** une entreprise **non vérifiée** peut
> **préparer et enregistrer des brouillons** d'offres, mais **ne peut pas publier
> publiquement**. La publication publique exige l'état **`verified`** (au-delà du seul
> « dossier complet »). Contrôle serveur, non contournable par le front.

## Conversation & Message (implémenté Lot 07)

**Contexte obligatoire.** Une conversation naît **d'une candidature** : le recruteur
l'ouvre via `POST /companies/me/applications/{application_uuid}/conversation` (candidature
d'une offre de **son** entreprise). Aucun contact arbitraire ; **1 conversation par
candidature** (unique en base, idempotent en concurrence). Le candidat n'ouvre pas de
conversation lui-même — il répond dès qu'elle existe.

**Statut conversation :** `active → closed`, `closed → active` (réouverture),
`active/closed → archived`. La lecture reste **toujours** possible (contexte gelé). Émet
`conversation.created|read|closed`.

**Fermeture manuelle (décision V1) :** réservée au **propriétaire (`owner`) de
l'entreprise** ou à un modérateur (`pst_moderate_content`) — pas à un recruteur membre
lambda ni au candidat (→ 403).

**Fermeture automatique (décision V1) :** une candidature qui devient **`rejected` ou
`withdrawn`** ferme automatiquement la conversation liée (lecture seule, envoi → 409,
historique conservé) — via écoute de `application.rejected`/`application.withdrawn`. Une
candidature **`selected` ne ferme PAS** la conversation.

**Envoi :** exige `pst_send_message` **+** `pst_email_verified` ; la **lecture** ne
requiert que `pst_send_message` (e-mail non vérifié autorisé). Rate-limité.

**Décision V1 (D6) :** un message envoyé est **immuable** — **pas d'édition** du
contenu. La disparition d'un message se fait par **suppression logique** (soft-delete :
`deleted_at` renseigné, contenu masqué mais ligne conservée pour l'audit) ou par
**modération** (hook futur), pas par suppression physique.

États : `sent → read`, `sent/read → reported → moderated (allowed|blocked)`,
`sent/read → deleted` (**soft-delete** logique).

| Transition | Autorisé par |
|---|---|
| (création) sent | candidat/recruteur (participant) |
| sent → read | destinataire (ouverture) |
| any → reported | participant |
| reported → moderated | admin/modo |
| sent/read → deleted (logique) | auteur (le sien) / admin-modo — **pas d'édition** (D6) |

## §7 Relations métier (vue synthèse)

```
Company → Recruiters (CompanyMembers) → Jobs → Applications
Candidate → CVs → Applications → CVSnapshot
Candidate → Favorites, Alerts, Follows, Skills
Application → Job, Candidate, CVSnapshot, StatusHistory, Interviews,
              Conversation(Messages), RecruiterNotes, PreselectionAnswers
```

## Snapshot CV

À la création d'une **Application**, `postelio-files` fige une **copie immuable**
(`CVSnapshot`) du CV principal (ou choisi). Si le candidat remplace/supprime son CV
ensuite, l'Application **conserve** le snapshot d'origine (ce que le recruteur a
réellement reçu). Le téléchargement recruteur pointe vers le **snapshot**, pas le CV
vivant. Voir [data-model.md](data-model.md#cv--cvsnapshot) et
[security.md](security.md#fichiers).

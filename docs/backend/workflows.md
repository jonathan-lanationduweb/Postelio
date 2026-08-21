# Postelio — Workflows & transitions

Chaque objet à cycle de vie a une **machine à états** appliquée **côté serveur**. Les
transitions non listées sont refusées (`error.code = invalid_transition`). Le front ne
choisit jamais un statut arbitrairement.

## Offre (Job)

États : `draft → pending(*) → published → expiring → expired → renewed → filled → archived → suspended`
(*) `pending` (revue) uniquement si la modération d'offre est activée.

| Transition | Autorisé par |
|---|---|
| draft → pending / published | recruteur (owner de la company) |
| pending → published / rejected | admin/modo |
| published → expiring | système (cron, J-7) |
| expiring → expired | système (cron, échéance) |
| expired → renewed | recruteur via **paiement** (`payment.succeeded`) |
| renewed → published | système (application du renouvellement) |
| published/expiring → filled | recruteur (poste pourvu) |
| any → archived | recruteur |
| any → suspended | admin |

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

## Entretien (Interview)

États : `proposed → pending_candidate → confirmed`, `proposed → reschedule_requested`,
`proposed → rejected`, `confirmed → completed`, `* → cancelled`.

| Transition | Autorisé par |
|---|---|
| (création) proposed | recruteur |
| proposed → confirmed | candidat |
| proposed → reschedule_requested | candidat (propose un créneau) |
| reschedule_requested → proposed | recruteur (nouvelle proposition) |
| proposed → rejected | candidat |
| proposed/confirmed → cancelled | recruteur (ou candidat côté sien) |
| confirmed → completed | système (après la date) / recruteur |

Infos par format ([data-model.md](data-model.md#interview)) : visio (lien+instructions),
sur place (adresse+contact+accès), téléphone (numéro/indication).

## Entreprise (Company) — vérification

États : `incomplete → pending_verification → verified`, `pending_verification →
manual_review → verified | rejected`, `verified → suspended`.

| Transition | Autorisé par |
|---|---|
| incomplete → pending_verification | recruteur (dossier complet : identité légale + adresse + contact) |
| pending_verification → verified | admin (ou provider Sirene/RNE plus tard) |
| pending_verification → manual_review | système/admin (doute) |
| manual_review → verified/rejected | admin |
| verified → suspended | admin |

> Blocage publication (déjà simulé front) : une offre ne peut être publiée que si le
> profil entreprise a le minimum (raison sociale, adresse, contact). `À VALIDER` :
> exiger `verified` pour publier, ou seulement « dossier complet ».

## Message

États : `sent → read`, `sent/read → reported → moderated (allowed|blocked)`,
`* → deleted` (si applicable, `À VALIDER`).

| Transition | Autorisé par |
|---|---|
| (création) sent | candidat/recruteur (participant) |
| sent → read | destinataire (ouverture) |
| any → reported | participant |
| reported → moderated | admin/modo |

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

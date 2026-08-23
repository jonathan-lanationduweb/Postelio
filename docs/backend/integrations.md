# Postelio — Intégrations externes

Aucune intégration n'est branchée dans ce lot. Pour chaque service : **objectif**,
**criticité**, **dépendance métier**, **données envoyées**, **données reçues**, **fallback**.

## Intégrations PRIMAIRES (nécessaires au fonctionnement cible)

### 1. Vérification d'entreprise — Sirene / RNE (INSEE) — **providers cibles V1 (D10)**
- **Objectif :** vérifier SIREN/SIRET, raison sociale, état administratif.
- **Criticité :** haute (badge « Entreprise vérifiée » ; conditionne la publication d'offre — D1).
- **Envoyé :** SIREN/SIRET saisis. **Reçu :** raison sociale, adresse, état, forme juridique.
- **Fallback :** revue manuelle admin (workflow `manual_review`), badge « en cours ».
- **Plugin :** companies. **Statut :** champs/statuts prévus, **pas d'API** dans ce lot.
- **Décision (D10) :** **Sirene / RNE** retenus comme providers cibles, derrière l'interface `VerificationProvider`.

### 2. E-mail transactionnel (ex. Brevo / Mailjet / Postmark)
- **Objectif :** e-mails de notification (candidature, entretien, refus, facture…).
- **Criticité :** haute. **Envoyé :** destinataire, contenu templaté. **Reçu :** statut d'envoi/webhooks.
- **Fallback :** `wp_mail` natif (SMTP) ; file d'attente + retries.
- **Plugin :** notifications.
- **État Lot 09 (implémenté) :** interface **`EmailProvider`** (filtre
  `postelio/notifications/email_provider`). **V1 dev = `WpMailProvider`** (`wp_mail`).
  La logique métier n'appelle jamais `wp_mail()` : Router → EmailDispatcher → **file**
  (`wp_postelio_notification_deliveries`) → provider, avec retry/backoff/idempotence via
  le `Core\Jobs\Scheduler`. **Provider transactionnel de production : non choisi**
  (`À VALIDER`). Bounces/complaints : colonnes/hooks prévus, hors V1.

### 3. Stockage de fichiers — abstraction `StorageProvider` (D4)
- **Objectif :** CV/documents sécurisés. **Criticité :** haute.
- **Décision (D4) :** interface **`StorageProvider`** ; **provider local privé** (hors
  webroot) par **défaut en développement/V1**, provider **S3-compatible** branchable plus
  tard sans changer les appelants.
- **CV V1 (D3) :** PDF, 10 Mo max (voir [security.md](security.md#5-fichiers-cv--documents)).
- **Envoyé :** fichier hors webroot. **Reçu :** URL signée temporaire (selon provider).
- **Plugin :** files.

### 4. Anti-bot (ex. hCaptcha / Turnstile)
- **Objectif :** protéger inscription, candidature, contact. **Criticité :** moyenne-haute.
- **Envoyé :** token challenge. **Reçu :** validation. **Fallback :** rate limiting + honeypot.
- **Plugin :** users/core.

### 5. Paiement — **Stripe, provider cible V1 (D9)**
- **Objectif :** renouvellement d'offre 10 €/30 j. **Criticité :** haute (monétisation).
- **Envoyé :** montant, référence, client. **Reçu :** statut paiement + **webhooks** (idempotents).
- **Fallback :** mode « demo » (déjà côté front) tant que non branché. **Plugin :** billing.

### 6. Modération (ex. service de scoring de contenu)
- **Objectif :** pré-filtrer messages/offres/savoir-faire. **Criticité :** moyenne.
- **Envoyé :** texte à évaluer. **Reçu :** score/décision. **Fallback :** file de revue manuelle admin.
- **Plugin :** moderation.

## Intégrations SECONDAIRES (extensions futures, non prévues au front)

| Service | Objectif | Criticité | Dépendance | Envoyé / Reçu | Fallback |
|---|---|---|---|---|---|
| Agenda (Google/Microsoft) | Ajouter l'entretien au calendrier | basse | interviews | événement / lien | `.ics` (déjà simulé) |
| Visioconférence (Meet/Zoom) | Générer le lien visio | basse | interviews | créneau / URL | lien saisi manuellement |
| SMS (ex. Twilio) | Rappels d'entretien SMS | basse | notifications | numéro+texte / statut | e-mail |
| France Travail / Indeed / HelloWork | Diffusion/agrégation d'offres | basse-moyenne | jobs | offre / IDs externes | publication interne seule |

### 4. Agrégation d'offres externes — `postelio-job-sources` (implémenté Lot 10)
- **France Travail** — API officielle « Offres d'emploi v2 », **provider réel V1**. OAuth2
  client_credentials (token `entreprise.francetravail.fr/connexion/oauth2/access_token?realm=
  /partenaire`, scope `api_offresdemploiv2 o2dsoffre`, ~25 min) ; endpoints
  `api.francetravail.io/partenaire/offresdemploi/v2/offres/search` + `/{id}` ; pagination
  `range` (≤150/appel, ~3150/req) ; quota **10 req/s** (429 → backoff+circuit+stale).
  **Secrets** : constantes `POSTELIO_FT_CLIENT_ID`/`POSTELIO_FT_CLIENT_SECRET` (ou env),
  **jamais** en base/Git. **Licence** : attribution (source+date+lien Licence), contenu
  complet + logo, refresh ≥24h + propagation, retrait/anonymisation, RGPD UE — respectée.
- **Indeed / HelloWork / ATS** — **FUTUR / PARTENARIAT** (pas d'API pull publique) ; aucun
  scraping. Abstraits derrière `JobSourceProvider` (placeholders, non implémentés).
- **Stockage** : table dédiée `wp_postelio_external_jobs` ; recherche unifiée via
  `CompositeJobSearchProvider` (filtre `postelio/jobs/search_provider`). Candidature externe
  = **redirection** (`/jobs/{uuid}/apply-redirect`), jamais de candidature Postelio.
| Parsing CV | Pré-remplir le profil depuis un CV | basse | files/users | fichier / champs extraits | saisie manuelle (front prévoit déjà le message « détection future ») |
| Géolocalisation avancée | Recherche par rayon précis | basse | jobs | adresse / coordonnées | API Adresse (data.gouv, déjà en config) |
| Push Tauri | Notifications app desktop | basse (futur) | notifications | token device / push | in-app + e-mail |

## Principes
- Chaque intégration est encapsulée derrière une **interface** (`VerificationProvider`,
  `MailProvider`, `StorageProvider`, `PaymentProvider`, `ModerationProvider`,
  `SearchProvider`) pour être remplaçable et testable (mock en dev).
- Aucune clé API commitée (voir [security.md](security.md#secrets--environnements)).

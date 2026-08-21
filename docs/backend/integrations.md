# Postelio — Intégrations externes

Aucune intégration n'est branchée dans ce lot. Pour chaque service : **objectif**,
**criticité**, **dépendance métier**, **données envoyées**, **données reçues**, **fallback**.

## Intégrations PRIMAIRES (nécessaires au fonctionnement cible)

### 1. Vérification d'entreprise — Sirene / RNE (INSEE)
- **Objectif :** vérifier SIREN/SIRET, raison sociale, état administratif.
- **Criticité :** haute (badge « Entreprise vérifiée »).
- **Envoyé :** SIREN/SIRET saisis. **Reçu :** raison sociale, adresse, état, forme juridique.
- **Fallback :** revue manuelle admin (workflow `manual_review`), badge « en cours ».
- **Plugin :** companies. **Statut :** champs/statuts prévus, **pas d'API** dans ce lot.

### 2. E-mail transactionnel (ex. Brevo / Mailjet / Postmark)
- **Objectif :** e-mails de notification (candidature, entretien, refus, facture…).
- **Criticité :** haute. **Envoyé :** destinataire, contenu templaté. **Reçu :** statut d'envoi/webhooks.
- **Fallback :** `wp_mail` natif (SMTP) ; file d'attente + retries.
- **Plugin :** notifications.

### 3. Stockage de fichiers (local protégé, puis S3-like optionnel)
- **Objectif :** CV/documents sécurisés. **Criticité :** haute.
- **Envoyé :** fichier chiffré/hors webroot. **Reçu :** URL signée temporaire.
- **Fallback :** stockage local protégé (défaut V1). **Plugin :** files.

### 4. Anti-bot (ex. hCaptcha / Turnstile)
- **Objectif :** protéger inscription, candidature, contact. **Criticité :** moyenne-haute.
- **Envoyé :** token challenge. **Reçu :** validation. **Fallback :** rate limiting + honeypot.
- **Plugin :** users/core.

### 5. Paiement (Stripe prévu)
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
| Parsing CV | Pré-remplir le profil depuis un CV | basse | files/users | fichier / champs extraits | saisie manuelle (front prévoit déjà le message « détection future ») |
| Géolocalisation avancée | Recherche par rayon précis | basse | jobs | adresse / coordonnées | API Adresse (data.gouv, déjà en config) |
| Push Tauri | Notifications app desktop | basse (futur) | notifications | token device / push | in-app + e-mail |

## Principes
- Chaque intégration est encapsulée derrière une **interface** (`VerificationProvider`,
  `MailProvider`, `FileStorage`, `PaymentProvider`, `ModerationProvider`,
  `SearchProvider`) pour être remplaçable et testable (mock en dev).
- Aucune clé API commitée (voir [security.md](security.md#secrets--environnements)).

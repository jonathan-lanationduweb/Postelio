# Postelio — Contrat d'API REST

Base : `/wp-json/postelio/v1/`. Consommée par le **web** et la future app **Tauri**.
Ressources au pluriel. Contrats figés dans ce lot (pas d'implémentation).

## 1. Versionnement

- Namespace versionné dans l'URL : `postelio/v1`, puis `postelio/v2` pour les ruptures.
- `v1` maintenu tant que le web **et** Tauri en dépendent. Changements **non cassants**
  (ajout de champ optionnel) restent en `v1` ; toute **rupture** (suppression/renommage
  de champ, changement de sémantique) impose `v2`.
- En-tête recommandé : `Accept: application/json` ; le serveur peut renvoyer
  `X-Postelio-Api-Version`.

## 2. Format standard des réponses

Succès :
```json
{ "data": { }, "meta": { "pagination": { "page": 1, "per_page": 20, "total": 42 } } }
```
Erreur :
```json
{ "error": { "code": "forbidden", "message": "Action non autorisée.", "details": {} } }
```

### Codes d'erreur internes (stables)
| code | HTTP | Sens |
|---|---|---|
| `unauthenticated` | 401 | Pas de session/token valide. |
| `forbidden` | 403 | Authentifié mais capability/rôle insuffisant. |
| `not_found` | 404 | Ressource inexistante ou non visible. |
| `validation_error` | 422 | Champs invalides (`details` = liste champ→raison). |
| `invalid_transition` | 409 | Transition de statut non autorisée. |
| `conflict` | 409 | Doublon / contrainte d'unicité. |
| `rate_limited` | 429 | Trop de requêtes. |
| `payload_too_large` | 413 | Fichier trop volumineux. |
| `unsupported_media_type` | 415 | MIME non autorisé. |
| `payment_required` | 402 | Action nécessitant un paiement (renouvellement). |
| `server_error` | 500 | Erreur interne (loggée). |

## 3. Authentification
- **Web** : cookies WordPress + **nonce** REST (`X-WP-Nonce`) pour les mutations.
- **Tauri/app** : **token applicatif** (Bearer, ex. JWT ou Application Passwords) émis
  par `/auth`. Voir [security.md](security.md#authentification).

## 4. Conventions communes
- Pagination : `?page`, `?per_page` (défaut 20, max 100). Tri : `?sort=-created_at`.
- Filtres : `?status=`, `?q=`, `?ville=`, etc. (validés serveur).
- Dates : ISO 8601 UTC. IDs : entiers ou UUID (`À VALIDER` — cohérent par plugin).

## 5. Endpoints principaux

> Notation : `M` méthode · `R` rôle requis · `→` réponse · `err` erreurs typiques.

### Auth & moi — `postelio-users`
- `POST /auth` — login (email+mot de passe / provider). → `{ token, user }`. err: `unauthenticated`, `validation_error`.
- `POST /auth/refresh` · `POST /auth/logout`.
- `GET /me` — profil de l'utilisateur courant (identité + rôle). R: authentifié.
- `GET/PUT /me/settings` — préférences (notifications, langue, visibilité coordonnées).
- `GET /me/export` — export RGPD. `DELETE /me` — suppression/anonymisation.

### Candidats — `postelio-users`
- `GET /candidates/{id}` — **vue recruteur** (respecte visibilité ; jamais email/tel/notes si non autorisé). R: recruiter/admin.
- `GET/PUT /candidates/me/profile` — profil complet (self). R: candidate.

### Entreprises — `postelio-companies`
- `GET /companies` (public, filtres) · `GET /companies/{id}` (public).
- `GET/PUT /companies/me` — R: recruiter.
- `POST /companies/{id}/verification` — demande de vérification. R: recruiter.
- `POST/DELETE /companies/{id}/follow` — R: candidate.

### Offres, favoris, alertes — `postelio-jobs`
- `GET /jobs` (public, filtres : q, ville, rayon, secteur, contrat, salaire,
  niveau, expérience, télétravail, date, débutant/alternance/stage) · `GET /jobs/{id}`.
- `POST /jobs`, `PUT /jobs/{id}` — R: recruiter (sa company). err: `payment_required` (si quota).
- `POST /jobs/{id}/duplicate` · `POST /jobs/{id}/renew` (→ billing).
- `GET/POST/DELETE /me/favorites` · `GET/POST/PUT/DELETE /me/alerts`. R: candidate.

### Candidatures — `postelio-applications`
- `POST /applications` — postuler (job_id, cv_id, message, réponses présélection). R: candidate.
  → crée l'Application + **snapshot CV** + conversation. err: `conflict` (déjà postulé).
- `GET /applications/me` — R: candidate. `GET /applications/{id}` — self ou recruteur concerné.
- `GET /companies/me/applications` — pipeline recruteur (filtres statut/offre). R: recruiter.
- `POST /applications/{id}/status` — transition (voir [workflows.md](workflows.md#candidature)). R: recruiter. err: `invalid_transition`.
- `POST /applications/{id}/withdraw` — R: candidate.
- `GET/PUT /applications/{id}/notes` — notes privées. R: recruiter.

### Fichiers / CV — `postelio-files`
- `GET/POST /me/cvs` · `PUT/DELETE /me/cvs/{id}` (principal, remplacer, supprimer). R: candidate.
- `POST /me/documents` (lettre de motivation). 
- `GET /files/{id}/download` — **téléchargement contrôlé/signé** (pas d'URL publique).
  R: propriétaire ou recruteur autorisé (candidature reçue). err: `forbidden`.

### Messagerie — `postelio-messaging`
- `GET /conversations` · `GET /conversations/{id}` · `GET/POST /conversations/{id}/messages`.
- `POST /messages/{id}/read` · `POST /messages/{id}/report`. R: participant.

### Entretiens — `postelio-interviews`
- `POST /interviews` (recruteur propose) · `GET /interviews/me`.
- `POST /interviews/{id}/confirm|reschedule|reject|cancel` (voir workflow). 

### Notifications — `postelio-notifications`
- `GET /me/notifications` · `POST /me/notifications/read` · `POST /me/notifications/read-all`.

### Facturation — `postelio-billing`
- `POST /billing/renewals` (initier un renouvellement d'offre). R: recruiter.
- `GET /billing/payments` · `GET /billing/invoices`. R: recruiter/admin.
- `POST /billing/webhook` — endpoint provider (public **signé**), idempotent via `WebhookEvent`.

### Savoir-faire & contenus — `postelio-skills`
- `GET /skills` (public) · `GET /skills/{id}` · `GET/POST/PUT/DELETE /me/skills`. R: candidate.
- `GET/POST /companies/{id}/contents`. R: recruiter (sa company).

### Modération — `postelio-moderation`
- `GET /moderation/queue` · `GET /moderation/reports` · `POST /moderation/reports/{id}/decide`. R: admin/modo.

### Core
- `GET /health` · `GET /version` · `GET /config` (public non sensible).

## 6. Idempotence & sécurité
- Mutations exigent nonce (web) ou Bearer (app). Webhooks : signature + idempotence.
- Toute mutation de statut passe par le moteur de transitions ([workflows.md](workflows.md)).
- Détails sécurité : [security.md](security.md).

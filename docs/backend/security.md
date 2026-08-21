# Postelio — Sécurité, données sensibles, RGPD, audit

Documentation des règles à appliquer dès le Lot 01. Rien n'est implémenté ici.

## 1. Authentification
- **Web (thème WP)** : session/cookies WordPress + **nonce REST** (`X-WP-Nonce`) sur
  toute mutation.
- **App Tauri / clients API** : **jeton applicatif Bearer** émis par `/auth`, expiration
  + refresh, révocable. Implémentation V1 : jetons opaques maison (voir §9). Jamais de
  mot de passe ni de secret de jeton stocké côté client en clair.
- **Vérification e-mail** (décision V1 — **D12**) : **obligatoire pour les actions
  sensibles**. Un compte non vérifié peut s'inscrire, se connecter, compléter son profil
  et consulter le public ; il **ne peut pas** postuler / écrire à une entreprise
  (candidat) ni publier une offre / contacter un candidat / mener les actions de
  recrutement (recruteur). Contrôle **centralisé** via la capability virtuelle
  **`pst_email_verified`** (accordée dynamiquement si e-mail vérifié + compte actif) :
  les plugins métier composent `Guard::require_all('<cap_métier>', 'pst_email_verified')`.
  L'envoi réel des e-mails relèvera de `postelio-notifications`. Les durées/rappels
  restent `À VALIDER`.
- **Reset password** via le flux WordPress natif (`get/check_password_reset_key`,
  `reset_password`). Jamais de jeton de réinitialisation ni de session dans une URL
  autre que le lien à usage unique envoyé par e-mail.
- **2FA** (décision V1 — D8) : **prévue pour les comptes administrateurs** (`postelio_admin`) ;
  **non obligatoire** pour candidat/recruteur en V1. Méthode (TOTP) `À VALIDER`.

## 2. Autorisation
- Toute route vérifie **capability** (voir [roles-permissions.md](roles-permissions.md))
  **+** propriété de la ressource (ex. recruteur ⇒ membre de la company ; candidat ⇒
  propriétaire). Double contrôle (capability + ownership).
- Les transitions de statut passent par le moteur de workflow ([workflows.md](workflows.md)),
  jamais un `update` libre depuis le front.
- **Publication d'offre** (décision V1 — D1) : une entreprise **non vérifiée** peut créer
  et enregistrer des **brouillons** d'offres, mais la transition vers **publiée
  (publique)** est **refusée** tant que l'entreprise n'est pas `verified`. Contrôle côté
  serveur, jamais contournable par le front.

## 3. Menaces web
- **CSRF** : nonce sur mutations (web) ; Bearer + `Origin`/`Referer` check (app).
- **XSS** : sanitization à l'entrée (`sanitize_*`), échappement à la sortie
  (`esc_html`/`esc_attr`/`wp_kses` pour le rich text) ; jamais d'HTML brut injecté.
- **SQL injection** : `$wpdb->prepare` systématique / requêtes paramétrées ; pas de
  concaténation de variables.
- **Validation/sanitization** : schéma par endpoint (types, longueurs, valeurs
  autorisées) ; rejet `validation_error` sinon.
- **Rate limiting** (décision V1 — D5) : mécanisme **configurable** par IP + par
  utilisateur sur `/auth`, `/applications`, `/messages`, `/files/*/download`. Les
  **seuils ne sont pas figés** à ce stade (configuration). Réponse `rate_limited` (429).
- **Brute force** : throttle + verrouillage temporaire sur `/auth` ; journalisation.
- **Uploads** : voir §5.

## 4. Données sensibles

| Donnée | Lecture | Modification | Suppression |
|---|---|---|---|
| CV / snapshot | candidat (proprio) + recruteur d'une candidature reçue + admin | candidat | candidat (CV vivant) ; snapshot lié à la candidature |
| E-mail perso | candidat ; recruteur **seulement si** visibilité=recruteurs ou via CV | candidat | anonymisé à la suppression compte |
| Téléphone | idem e-mail (visibilité) | candidat | idem |
| Messages | participants + admin (audit) | **immuables en V1** (pas d'édition — D6) | suppression **logique**/modération (D6) |
| Entretien (créneau/lien visio/adresse/téléphone) | candidat concerné + recruteurs de la company + admin ; hors périmètre → 404 | recruteur (modif substantielle ⇒ reconfirmation) ; candidat (confirme/refuse/propose créneau) | logique via statut (`cancelled`) ; historique append-only conservé |
| Notification in-app | destinataire (propriétaire) uniquement ; admin/support = **statut** de livraison sans le corps | destinataire (lu/lu-tout) | purge via `expires_at` (`À VALIDER`) |
| E-mail (livraison) | worker interne ; jamais de motif/note/token/ID SQL/corps complet dans l'audit | — (immuable) | conservé pour observabilité (statut, provider_message_id) |
| Notes recruteur | recruteurs de la company + admin | recruteur | recruteur |
| Adresse personnelle | candidat + recruteur autorisé | candidat | anonymisée |
| Historique candidature | candidat (le sien), recruteur concerné, admin | système (append-only) | anonymisé selon conservation |
| Identité légale entreprise | recruteur de la company + admin | recruteur | avec l'entreprise |
| Paiements / factures | recruteur de la company + admin | — (immuable) | conservation légale |

## 5. Fichiers (CV & documents)
- **Jamais d'URL publique directe.** Stockage **hors webroot** (ou dossier protégé
  `.htaccess`/nginx `deny`, nom de fichier non devinable).
- **Abstraction `StorageProvider`** (décision V1 — D4) : **stockage local privé** en
  développement (défaut V1), remplaçable par un provider **S3-compatible** plus tard
  sans changer les appelants. Toute écriture/lecture passe par cette interface.
- Téléchargement via endpoint **`GET /files/{id}/download`** : vérifie l'autorisation,
  puis stream le fichier ou renvoie une **URL signée à durée limitée** (selon le
  provider `StorageProvider` actif).
- **CV V1** (décision — D3) : **PDF uniquement** (`application/pdf`), **10 Mo maximum**.
  Validation MIME **réelle** (pas seulement l'extension). Les autres formats de document
  (`.doc`, `.docx`) restent `À VALIDER` pour une version ultérieure.
- **Implémenté Lot 06 (`postelio-files`)** : stockage **privé** hors chemins publics
  (`WP_CONTENT_DIR/postelio-private/files`, filtre `postelio/files/storage_dir`, +
  `.htaccess` deny — **inaccessibilité HTTP vérifiée** sur postelio.local) ; noms
  physiques aléatoires ; MIME `finfo` + signature `%PDF-` + taille + anti-traversée ;
  téléchargement/aperçu par **streaming** (`GET /files/{uuid}/download|view`,
  `application/pdf` + `nosniff` + CSP sandbox + HTTP Range). Accès : propriétaire OU
  recruteur autorisé via `postelio/files/authorize_download` ; sinon **404**. Versions
  immuables (snapshot par référence). `FileScanner` (défaut no-op) prévu pour un
  antivirus futur.
- **Snapshot CV** : copie immuable à la candidature (voir [workflows.md](workflows.md#snapshot-cv)).
- Suppression : purge du CV vivant à la suppression de compte ; snapshots gérés selon la
  conservation des candidatures.

## 6. RGPD

| Sujet | Règle | Statut |
|---|---|---|
| Consentements | À la création de compte (CGU + politique). Journalisés. | `À VALIDER` (textes) |
| Export des données | `GET /me/export` (profil, CV refs, candidatures, messages, alertes). | prévu |
| Suppression de compte | `DELETE /me` → suppression/anonymisation (déjà côté front en localStorage). | prévu |
| Anonymisation | Candidatures/messages conservés pour le recruteur mais **anonymisés** (nom → « Candidat retiré »). | `À VALIDER` |
| Conservation candidatures | ex. 24 mois après clôture puis anonymisation. | **À VALIDER** |
| Conservation CV | tant que compte actif ; snapshot selon candidature. | `À VALIDER` |
| Conservation messages | ex. durée de la relation + X mois. | **À VALIDER** |
| Conservation logs (audit) | ex. 12 mois. | **À VALIDER** |
| Conservation factures | obligation légale (ex. 10 ans en France). | **À VALIDER** |

> Aucune durée juridique n'est inventée : les valeurs ci-dessus marquées **À VALIDER**
> doivent être confirmées avant implémentation.

## 7. Audit log
Journaliser (table `wp_postelio_audit_log`, append-only) au minimum :
- admin suspend/rétablit un compte ou une entreprise ;
- entreprise vérifiée / rejetée ;
- recruteur change le statut d'une candidature ;
- offre publiée / renouvelée / archivée ;
- paiement reçu / échoué ;
- contenu modéré (allowed/blocked) ;
- suppression/anonymisation de compte.

Champs : `actor_id`, `actor_role`, `action`, `resource_type`, `resource_id`,
`metadata` (JSON minimal, **sans** donnée sensible superflue), `created_at`. Immuable,
lecture admin uniquement.

- **Adresse IP** (décision V1 — D7) : stockée **uniquement** pour les événements de
  **sécurité/audit où c'est justifié** (ex. `/auth`, verrouillage brute force,
  suspension de compte) — **pas** de journalisation d'IP générale. Durée de conservation
  `À VALIDER` (RGPD, §6).

## 8. Secrets & environnements
Aucune clé secrète commitée (voir [implementation-plan.md](implementation-plan.md#environnements)).
Clés provider (Stripe, e-mail, Sirene) via variables d'environnement / `wp-config`
hors dépôt.

## 9. Jetons applicatifs (Bearer)

Implémentés par `postelio-users` pour les clients non-cookie (future app Tauri). Le
web continue d'utiliser cookies WordPress + nonce.

**Format** : `"{uid}.{tid}.{secret}"` — `uid` = ID utilisateur (non secret), `tid` =
identifiant de jeton (64 bits), `secret` = 256 bits.

**Pourquoi ce choix (V1)** : zéro dépendance externe (pas de lib JWT), révocation
serveur immédiate et par-session (impossible avec un JWT stateless sans liste de
révocation), rotation simple, stockage minimal. Alternative écartée pour la V1 :
JWT signé (révocation plus complexe) et Application Passwords WP (pensées pour
l'utilisateur humain, pas pour un flux d'app).

**Garanties** :
- `tid`/`secret` générés via **CSPRNG** (`random_bytes`) — entropie 64/256 bits ;
- le **secret brut n'est jamais stocké** : seul son **hash SHA-256** l'est (usermeta),
  avec une **expiration** (14 j par défaut, filtre `postelio/auth_token_ttl`) ;
- le secret **n'apparaît jamais** dans les logs ni l'audit log, ni dans une URL
  (en-tête `Authorization` uniquement) ;
- validation en **temps constant** (`hash_equals`) ; **expiration vérifiée serveur** ;
- **révocation réelle** (suppression de l'entrée) ; `refresh` **invalide** l'ancien ;
- **`revoke_all`** = déconnexion globale (endpoint `/auth/logout-all`, suppression de
  compte) ;
- **intégrité** : un `uid`/`tid` falsifié ne donne accès à rien — le hash stocké est lié
  au triplet exact et le secret reste inconnu de l'attaquant ; jeton **malformé** rejeté.

**Limites (assumées V1)** : jetons stockés en `usermeta` (suffisant à l'échelle visée ;
une table dédiée pourra suivre si le volume l'exige) ; pas de rotation automatique de
secret ; révocation d'un jeton précis nécessite son `tid` (l'utilisateur peut toujours
tout révoquer via `/auth/logout-all`).

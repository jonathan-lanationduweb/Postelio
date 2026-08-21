# Postelio — Sécurité, données sensibles, RGPD, audit

Documentation des règles à appliquer dès le Lot 01. Rien n'est implémenté ici.

## 1. Authentification
- **Web (thème WP)** : session/cookies WordPress + **nonce REST** (`X-WP-Nonce`) sur
  toute mutation.
- **App Tauri / clients API** : **token applicatif** Bearer (JWT signé ou Application
  Passwords WP). Émission par `/auth`, expiration + refresh. Jamais de mot de passe stocké
  côté client.
- **Vérification e-mail** obligatoire avant certaines actions (postuler, publier) — `À VALIDER`.
- **Reset password** via le flux WordPress natif.
- **2FA admin** : recommandée pour `postelio_admin` (`À VALIDER` : TOTP).

## 2. Autorisation
- Toute route vérifie **capability** (voir [roles-permissions.md](roles-permissions.md))
  **+** propriété de la ressource (ex. recruteur ⇒ membre de la company ; candidat ⇒
  propriétaire). Double contrôle (capability + ownership).
- Les transitions de statut passent par le moteur de workflow ([workflows.md](workflows.md)),
  jamais un `update` libre depuis le front.

## 3. Menaces web
- **CSRF** : nonce sur mutations (web) ; Bearer + `Origin`/`Referer` check (app).
- **XSS** : sanitization à l'entrée (`sanitize_*`), échappement à la sortie
  (`esc_html`/`esc_attr`/`wp_kses` pour le rich text) ; jamais d'HTML brut injecté.
- **SQL injection** : `$wpdb->prepare` systématique / requêtes paramétrées ; pas de
  concaténation de variables.
- **Validation/sanitization** : schéma par endpoint (types, longueurs, valeurs
  autorisées) ; rejet `validation_error` sinon.
- **Rate limiting** : par IP + par utilisateur sur `/auth`, `/applications`,
  `/messages`, `/files/*/download` (`À VALIDER` : seuils). Réponse `rate_limited` (429).
- **Brute force** : throttle + verrouillage temporaire sur `/auth` ; journalisation.
- **Uploads** : voir §5.

## 4. Données sensibles

| Donnée | Lecture | Modification | Suppression |
|---|---|---|---|
| CV / snapshot | candidat (proprio) + recruteur d'une candidature reçue + admin | candidat | candidat (CV vivant) ; snapshot lié à la candidature |
| E-mail perso | candidat ; recruteur **seulement si** visibilité=recruteurs ou via CV | candidat | anonymisé à la suppression compte |
| Téléphone | idem e-mail (visibilité) | candidat | idem |
| Messages | participants + admin (audit) | auteur (édition `À VALIDER`) | modération/admin |
| Notes recruteur | recruteurs de la company + admin | recruteur | recruteur |
| Adresse personnelle | candidat + recruteur autorisé | candidat | anonymisée |
| Historique candidature | candidat (le sien), recruteur concerné, admin | système (append-only) | anonymisé selon conservation |
| Identité légale entreprise | recruteur de la company + admin | recruteur | avec l'entreprise |
| Paiements / factures | recruteur de la company + admin | — (immuable) | conservation légale |

## 5. Fichiers (CV & documents)
- **Jamais d'URL publique directe.** Stockage **hors webroot** (ou dossier protégé
  `.htaccess`/nginx `deny`, nom de fichier non devinable).
- Téléchargement via endpoint **`GET /files/{id}/download`** : vérifie l'autorisation,
  puis stream le fichier ou renvoie une **URL signée à durée limitée** (`À VALIDER` :
  stockage local vs S3-like).
- **MIME autorisés** : `application/pdf`, `.doc`, `.docx` (`À VALIDER`). **Taille max** :
  `À VALIDER` (ex. 5 Mo). Validation MIME **réelle** (pas seulement l'extension).
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
`metadata` (JSON minimal, **sans** donnée sensible superflue), `created_at`
(`ip` : `À VALIDER` RGPD). Immuable, lecture admin uniquement.

## 8. Secrets & environnements
Aucune clé secrète commitée (voir [implementation-plan.md](implementation-plan.md#environnements)).
Clés provider (Stripe, e-mail, Sirene) via variables d'environnement / `wp-config`
hors dépôt.

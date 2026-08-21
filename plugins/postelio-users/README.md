# postelio-users

Comptes & authentification (Lot 02). Dépend de **postelio-core** dont il réutilise
tout le socle (registry, REST `postelio/v1`, événements, erreurs, permissions,
migrations). **Aucune infrastructure dupliquée.**

Périmètre : comptes candidats/recruteurs, profils **de base**, inscription,
connexion, déconnexion, récupération de mot de passe, vérification e-mail
(optionnelle), `/me` enrichi, préférences, export/suppression RGPD (préparés),
auth **web (cookies+nonce)** + **applicative Bearer** (future app Tauri).

> Hors périmètre (lots suivants) : entreprises, offres, candidatures, CV métier
> complet, messages, entretiens, paiements, vérification Sirene/RNE.

## Données (tables, via le migrateur du core)

| Table | Contenu | Migration |
|---|---|---|
| `wp_postelio_candidate_profiles` | Profil candidat 1-1 (base + listes JSON réservées aux lots suivants) | users #1 |
| `wp_postelio_recruiter_profiles` | Profil recruteur 1-1 (`company_id` nullable → Lot 03) | users #2 |

Comptes : `wp_users`/`wp_usermeta` natifs. Meta : `postelio_status`,
`postelio_email_verified_at`, `postelio_created_at`, `postelio_last_login_at`,
`postelio_api_tokens`, `postelio_settings`.

## Endpoints (`postelio/v1`)

| Méthode | Route | Accès | Rôle |
|---|---|---|---|
| POST | `/auth/register` | public | crée candidat/recruteur |
| POST | `/auth` | public | connexion → `{user, token, expires_at}` |
| POST | `/auth/refresh` | Bearer | renouvelle le jeton |
| POST | `/auth/logout` | public | révoque jeton + session |
| POST | `/auth/lost-password` | public | e-mail de réinitialisation (anti-énumération) |
| POST | `/auth/reset-password` | public | applique le nouveau mot de passe |
| GET/POST | `/auth/verify-email` | public | valide l'e-mail via jeton |
| POST | `/auth/verify-email/resend` | authentifié | renvoie l'e-mail de vérification |
| GET/PUT | `/candidates/me/profile` | `pst_edit_own_profile` | profil candidat (self) |
| GET/PUT | `/recruiters/me/profile` | `pst_manage_own_company` | profil recruteur (self) |
| GET | `/candidates/{id}` | `pst_view_company_applications` | vue recruteur (respecte la visibilité) |
| GET/PUT | `/me/settings` | authentifié | préférences (notifications, langue) |
| GET | `/me/export` | `pst_export_own_data` | export RGPD |
| DELETE | `/me` | `pst_delete_own_account` | anonymisation/suppression (préparée) |

`/me` (déclaré par le core) est **enrichi** via le filtre `postelio/me` :
e-mail, rôle, statut, `email_verified`, préférences, profil.

## Authentification

- **Web** : cookies WordPress + nonce REST (`X-WP-Nonce`), posés à la connexion.
- **App/Tauri** : en-tête `Authorization: Bearer {uid}.{tid}.{secret}`. Jetons
  émis à l'inscription/connexion, renouvelables, révocables ; seul le hash SHA-256
  du secret est stocké (usermeta), avec expiration (14 j par défaut, filtre
  `postelio/auth_token_ttl`).
- **Vérification e-mail** : désactivée par défaut (`À VALIDER`), activable via le
  filtre `postelio/require_email_verification`.

## Événements émis

`user.created`, `user.updated`, `user.deleted`, `candidate.profile_updated`,
`plugin.registered` (audités par le core).

## Tests

```bash
php plugins/postelio-users/tests/run-unit.php                       # sans WP
wp eval-file plugins/postelio-users/tests/smoke.php --path=wordpress # WP vivant
```

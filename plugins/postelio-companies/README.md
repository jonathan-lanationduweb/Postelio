# postelio-companies

Entreprises (profil employeur), rattachement des recruteurs et cadre de
vérification (Lot 03). Dépend de **postelio-core** et **postelio-users** ; réutilise
leur registry, événements, migrations, erreurs REST, permissions et audit. **Aucune
infrastructure dupliquée.**

> Hors périmètre : offres, candidatures, messagerie, entretiens, facturation,
> savoir-faire, notifications réelles, modération générale, **API Sirene/RNE réelle**.

## Modèle de données

- **Entreprise** : CPT `postelio_company` (data-model.md). `post_title` = nom,
  `post_content` = présentation, `post_author` = recruteur créateur, image à la une
  = logo. Métadonnées :
  - `pst_uuid` (UUID v4 public, unique, immuable — D2) ;
  - `pst_editorial` (JSON, **modifiable** par le recruteur) ;
  - `pst_legal_declared` (JSON, saisi avant vérification) ;
  - `pst_legal_verified` (JSON, **figé** par la vérification, non modifiable) ;
  - `pst_verification` (JSON : statut, provider, dates, `verified_legal_id`, reviewer, motif) ;
  - `pst_siren` / `pst_verification_status` (indexés pour recherche/anti-doublon).
- **Membres** : table `wp_postelio_company_members` (n-n, rôle `owner|recruiter`,
  unique `(company_id,user_id)`).

**Séparation stricte légal / éditorial** : l'éditorial est toujours modifiable ; le
légal est modifiable tant que l'entreprise n'est pas `verified`, puis **verrouillé**.

## Endpoints (`postelio/v1`)

| Méthode | Route | Accès |
|---|---|---|
| GET | `/companies` | public (liste) |
| GET | `/companies/{uuid}` | public (masque les suspendues) |
| POST | `/companies` | `pst_manage_own_company` + `pst_email_verified` |
| GET | `/companies/me` | `pst_manage_own_company` |
| PUT | `/companies/me` | `pst_manage_own_company` + `pst_email_verified` |
| POST | `/companies/me/verification` | `pst_request_company_verification` + `pst_email_verified` |
| GET | `/companies/me/verification` | `pst_manage_own_company` |
| POST | `/companies/{uuid}/verification/decision` | `pst_verify_company` (admin) |

Identification publique par **UUID** uniquement — aucun ID interne dans les URLs/réponses.

## Vérification

États : `unverified → pending → (verified | rejected | manual_review)`,
`verified → suspended`. **Le recruteur ne peut jamais se déclarer `verified`.**
Provider abstrait `VerificationProvider` (filtre `postelio/verification_provider`) ;
défaut `ManualVerificationProvider` = revue manuelle admin (aucune API externe).
Fondations anti-fraude : validation SIREN/SIRET (Luhn), détection de doublon de SIREN
(→ `manual_review`), état de revue manuelle. `VerificationService::is_verified()` sera
réutilisé par postelio-jobs (publication conditionnée — D1).

## Rattachement recruteur

Le créateur devient **owner** ; le schéma autorise plusieurs recruteurs par
entreprise. `company.member_added` est écouté par postelio-users pour renseigner
`RecruiterProfile.company_id`. Invitations/retraits/changements de rôle : **schéma
préparé, endpoints non exposés dans ce lot** (hors périmètre documentaire).

## Événements

`company.created`, `company.updated`, `company.member_added`,
`company.verification_requested`, `company.verified`, `company.rejected`,
`company.suspended` — audités par le core (sans donnée sensible superflue).

## Tests

```bash
php plugins/postelio-companies/tests/run-unit.php                       # sans WP
wp eval-file plugins/postelio-companies/tests/smoke.php --path=wordpress # WP vivant
```

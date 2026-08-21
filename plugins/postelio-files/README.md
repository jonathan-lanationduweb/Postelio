# postelio-files

Infrastructure de fichiers privés (Lot 06) — service **transversal** de gestion des
CV et fichiers. Dépend de **core** et **users**. Les autres plugins ne construisent
jamais de chemin disque, n'exposent aucune URL uploads et ne lisent/suppriment jamais
un fichier directement : ils passent par les **contrats** de ce plugin.

> Hors périmètre : messagerie, entretiens, facturation, **S3 réel**, **antivirus réel**,
> parsing IA du CV.

## Stockage privé (StorageProvider)
Abstraction `StorageProvider` ; provider V1 = **`LocalPrivateStorageProvider`**.
- Base : `WP_CONTENT_DIR/postelio-private/files` (filtre `postelio/files/storage_dir` —
  **mettre hors webroot en production**).
- Protégée à la création : `.htaccess` **deny** + `index.php` de silence ; **testé
  inaccessible en HTTP** sur `postelio.local`.
- Clés assainies (aucune traversée) ; noms physiques **aléatoires** (UUID) ; portable
  (aucune logique Windows — fonctions de chemin PHP/WP).
- Provider futur `S3StorageProvider` branchable via `postelio/files/storage_provider`
  sans changer les contrats.

## Modèle (`wp_postelio_files`)
`id` interne + **`public_uuid`** (seul exposé), `owner_user_id`, `type` (cv|document),
`storage_provider`, `storage_key`, `original_name` (affichage), `stored_name`
(aléatoire), `mime_type`, `size_bytes`, `sha256`, `status`
(`uploaded|ready|quarantined|archived|deleted`), `is_primary`, dates, `deleted_at`.
Consolidation vs data-model (cvs/documents/cv_snapshots) : **une** table + versions
immuables ; le « snapshot » d'une candidature = référence immuable à une ligne.

## CV V1
**PDF uniquement, 10 Mo max** (D3, filtre `postelio/files/max_bytes`). Validation
**réelle** : extension finale `.pdf`, MIME `finfo` = `application/pdf`, **signature
`%PDF-`**, taille. Rejette faux PDF, double extension (`cv.pdf.php`), 0 octet,
traversal, MIME incorrect. **Versionnement** : chaque upload = nouvelle ressource
immuable (un CV référencé par une candidature n'est jamais remplacé physiquement).

## Endpoints (`postelio/v1`)
| Méthode | Route | Accès |
|---|---|---|
| POST | `/me/files/cv` (multipart `file`) | `pst_manage_own_cv` + `pst_email_verified` |
| GET | `/me/files/cv` · `/me/files/cv/{uuid}` | `pst_manage_own_cv` |
| POST | `/me/files/cv/{uuid}/primary` | `pst_manage_own_cv` |
| DELETE | `/me/files/cv/{uuid}` | `pst_manage_own_cv` (logique) |
| GET | `/files/{uuid}/view` (inline) · `/files/{uuid}/download` | authentifié + autorisation fine |

**Accès aux fichiers** : le **propriétaire** (candidat) ; OU un plugin tiers via le
filtre `postelio/files/authorize_download` (ex. `postelio-applications` : recruteur
d'une candidature de **son entreprise** référençant ce CV). Connaître l'UUID ne suffit
pas → sinon **404** (non-divulgation). Streaming sécurisé : `Content-Type application/pdf`,
`X-Content-Type-Options: nosniff`, CSP `sandbox`, `Content-Disposition` contrôlé,
support **HTTP Range** (206) pour les viewers PDF. Aucune URL disque.

## Suppression
Logique : `deleted` si non référencé (purgeable), **`archived`** si encore référencé
par une candidature (conservé, retiré du profil courant, jamais détruit). Files
interroge `postelio/files/file_is_referenced` (répondu par applications).

## Intégration applications (snapshot CV)
Le `cv_reference` opaque du Lot 05 est remplacé par le vrai contrat :
`\Postelio\Files\Api\FileCvContract::usable_for_application($cv_uuid, $candidate_id)`
(appartenance + type cv + statut `ready`). La candidature conserve l'UUID immuable →
snapshot garanti sans copie physique. **Pas de dépendance circulaire** : applications
consomme le contrat files ; files interroge applications uniquement par filtres.

## Sécurité fichiers (V1)
Statuts `uploaded/ready/quarantined/archived/deleted`. Validation MIME/extension/
taille/signature + stockage privé. Interface `FileScanner` (défaut `NullScanner`) —
point d'extension antivirus futur (`postelio/files/scanner`), **non branché**.

## Événements / audit
`cv.uploaded`, `cv.primary_changed`, `cv.deleted` (audités). Jamais de contenu, clé de
stockage ou URL dans l'audit.

## RGPD
Durées **À VALIDER**. Export candidat pourra lister ses CV (UUID). Suppression profil /
anonymisation / purge différée / conservation des pièces référencées : préparées.

## Tests
```bash
php plugins/postelio-files/tests/run-unit.php
wp eval-file plugins/postelio-files/tests/smoke.php --path=wordpress
```

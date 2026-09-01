# Postelio — Favoris, recherches sauvegardées & alertes (Lot 14)

Plugin **`postelio-alerts`**. Trois fonctions candidat : **favoris** d'offres, **recherches
sauvegardées**, **alertes emploi**. Aucune UI publique (REST uniquement), **aucun e-mail direct**
(événements → Notifications), **aucun accès SQL** aux offres (contrat de recherche Jobs, natif +
externe France Travail).

## 1. Architecture

```
Favoris ─────────── FavoriteService ─ FavoriteRepository ─ table job_favorites
Recherches/alertes ─ SavedSearchService ─ SavedSearchRepository ─ table saved_searches
Moteur d'alertes ── MatchingService ─┬─ JobSearchDirectory (contrat Jobs : natif + externe)
                                     └─ DeliveryRepository ─ table alert_deliveries (UNIQUE)
Planification ───── AlertScheduler / AlertDispatcher ─ Scheduler du core (07h30 Europe/Paris)
Notifications ───── NotificationsBridge (filtres) + event job_alert.matches_found
RGPD ────────────── AccountSync (user.deleted, filtre postelio/users/export)
Admin ───────────── AlertsAdminDirectory (compteurs agrégés) ← AlertsPage (postelio-admin)
```

Aucun plugin métier n'est appelé en dur : la recherche passe par `Jobs\Api\JobSearchDirectory`
(qui résout le provider via `postelio/jobs/search_provider` — moteur composite natif+externe quand
`postelio-job-sources` est actif). Notifications et Job Sources sont **optionnels**.

## 2. Tables

| Table | Clés / contraintes | Rôle |
|---|---|---|
| `postelio_job_favorites` | `UNIQUE(candidate_user_id, job_source, job_reference)`, `UNIQUE(public_uuid)` | 1 favori par (candidat, offre). Idempotence par contrainte. |
| `postelio_saved_searches` | `UNIQUE(candidate_user_id, filters_hash)`, `UNIQUE(public_uuid)`, `KEY(alert_frequency, next_run_at)` | Filtres validés (JSON) + fréquence + curseur/planification. |
| `postelio_alert_deliveries` | `UNIQUE(saved_search_id, job_source, job_reference)`, `KEY(reserved_at)` | Anti-doublon d'envoi. Rétention ≈13 mois. |

**Identité canonique d'offre** = `(job_source, job_reference)` où `job_source ∈ {native, external}`
et `job_reference` = `public_uuid` de l'offre. Un UUID natif et une référence externe
**n'appartiennent pas au même espace de noms** : la contrainte porte sur les deux colonnes.
Aucun snapshot d'offre : la carte publique est résolue à la lecture via `JobDirectory::public_card`.

## 3. Matching & `published_after`

1. Filtres validés (whitelist Jobs) + `published_after` = curseur (`cursor_ts`, sinon `created_at`
   au premier passage — on ne notifie jamais tout le back-catalogue).
2. Recherche via `JobSearchDirectory::search()` — **une seule logique** de matching (natif+externe).
3. Pagination jusqu'à **épuisement** OU **limite de sécurité** (`match_max_pages`, défaut 40).
   Limite atteinte → `Logger::warning` + événement `job_alert.run_failed` (`reason=result_cap`) :
   l'anomalie est **loguée/auditée**, jamais un avancement silencieux qui perdrait des offres.
4. Réservation de chaque delivery **avant** notification (`INSERT IGNORE` atomique). Seules les
   **nouvelles** réservations sont retenues (deux workers/retries concurrents ne peuvent pas
   notifier deux fois la même offre).
5. Digest → `job_alert.matches_found` → deliveries marquées `sent`.
6. `cron` : `next_run_at` recalculé ; `run_now` : planification inchangée (curseur/dédup respectés
   → run-now ne peut pas spammer).

**`published_after`** est un filtre **interne** ajouté au moteur Jobs (natif : meta date de
publication `pst_date_publication` au grain jour ; externe : `source_published_at`). Il n'est
**jamais** exposé comme filtre public (rejeté en validation stricte). Il **borne** la requête ;
la **garantie de non-doublon** reste la table `alert_deliveries` (curseur = simple optimisation,
robuste face aux ré-imports externes dont la date peut changer).

## 4. Planification (Scheduler du core uniquement)

Ancre quotidienne = événement unique **auto-replanifié** à **07h30 Europe/Paris** (précision +
DST garantis, contrairement à un intervalle « daily » ancré à l'activation). `ensure()` la ré-arme
si elle manque (auto-réparation à chaque boot). Le drain sélectionne les recherches échues
(`next_run_at <= now`) par **lots** (`dispatch_batch`, défaut 150) et se replanifie tant qu'il
reste des lots — jamais de scan global, jamais un cron géant. Le weekly (lundi 07h30) est capté par
l'ancre quotidienne via son `next_run_at`. Purge de rétention bornée à chaque ancre.

## 5. Notifications (découplé)

`postelio-alerts` **enregistre** la catégorie `job_alert` (candidat, in-app+email, non-marketing)
via `postelio/notifications/categories`, et le template `job_alert_digest` via
`postelio/notifications/email_templates`. `postelio-notifications` n'a **aucune dépendance en dur**
envers `postelio-alerts` : module absent ⇒ catégorie/template absents ⇒ digest jamais produit.
`NotificationRouter` consomme `job_alert.matches_found` (référence souple par nom d'événement,
comme tous les autres événements métier) et produit **UNE** notification in-app + **AU PLUS un**
e-mail digest par cycle (jamais un e-mail par offre). L'e-mail exige une **adresse vérifiée**
(§ sécurité) et reste soumis aux préférences de canal.

## 6. Sécurité / RGPD

- Candidat V1 uniquement. Ownership strict (non-propriétaire → 404). Caps `pst_manage_own_favorites`
  / `pst_manage_own_alerts` (réutilisées ; les recherches sauvegardées sont le véhicule des alertes,
  on ne multiplie pas les capabilities).
- **Favoris** : compte actif suffit (e-mail vérifié non requis). **Alertes** : compte actif ; canal
  **e-mail** ⇒ `email_verified` requis. **Suspendu** : aucune mutation, aucun run, aucune
  notification ; planification avancée pour ne pas re-sélectionner à chaque tick ; données
  conservées ; reprise à la réactivation.
- **Suppression de compte** (`user.deleted`) : purge favoris + recherches + deliveries. **Export**
  RGPD : favoris + recherches ajoutés via `postelio/users/export`.
- Noms de recherche **sanitizés** (`sanitize_text_field`, 190 car.). Filtres = whitelist stricte
  (clé inconnue → 422). Rate-limits (transient) sur create/update, preview, run-now.

## 7. Offres externes

Aucun accès à la table `external_jobs`. Résolution via `JobDirectory` (`resolve_source`,
`public_card`, `external`) et recherche via le moteur composite. États respectés : source
désactivée / masquée (`local_visibility`) / retirée (`sync_status=removed`) → carte
`available:false`, favori conservé. Une offre externe reste candidatée selon son
`application_mode` (redirection partenaire) — hors périmètre de ce lot.

## 8. API (namespace `postelio/v1`)

```
GET    /me/favorites/jobs
POST   /me/favorites/jobs/{job_reference}
DELETE /me/favorites/jobs/{job_reference}

GET    /me/saved-searches
POST   /me/saved-searches
GET    /me/saved-searches/{uuid}
PUT    /me/saved-searches/{uuid}
DELETE /me/saved-searches/{uuid}
POST   /me/saved-searches/{uuid}/preview
POST   /me/saved-searches/{uuid}/run-now
```

Toutes les ressources exposent `public_uuid` (jamais d'id SQL). Erreurs au format `ApiError`
(`{error:{code,message,details}}`) : 401 (anonyme), 403 (mauvais rôle / compte suspendu), 404
(inconnu / non-propriétaire), 409 (quota / doublon de filtres), 422 (filtre inconnu), 429 (rate).

## 9. Quotas & configuration (filtrables)

Favoris **500** · recherches **20** · alertes actives **10** · digest sample **5** · rétention
deliveries **≈396 j** · pagination sécurité **40 pages × 50**. Hooks : voir README du plugin.

## 10. Limites V1 & mapping front

- **Fréquences** : `disabled|daily|weekly`. Architecture prête pour `immediate` (l'événement
  `job.published` existe déjà côté Jobs) — **non implémenté**.
- **Rayon/géo** : non supporté (le backend utilise `ville`). Le champ « Rayon » du formulaire
  d'alerte front (`espace-candidat.html`) sera retiré/adapté au branchement.
- **Front** (branché dans un lot ultérieur) : cœur d'offre → `POST/DELETE /me/favorites/jobs/{ref}` ;
  « Mes favoris » → `GET /me/favorites/jobs` ; formulaire d'alerte → `POST /me/saved-searches`
  (+ fréquence) ; « Mes alertes » → `GET /me/saved-searches`. Les clés localStorage actuelles
  (`ss_candidate_favorites`, `ss_candidate_alerts`) seront supprimées à ce moment-là.
- **Admin** : supervision **agrégée** (compteurs + état planificateur), jamais le contenu des
  recherches ni les favoris individuels.

# Postelio Alerts (Lot 14)

**Favoris**, **recherches sauvegardées** et **alertes emploi** pour les candidats. Aucune UI
publique (exposition REST uniquement), **aucun e-mail direct** (événements → Notifications),
**aucun accès SQL** aux offres (tout passe par le contrat de recherche Jobs, natif + externe).

## Architecture
```
Favoris
  POST/DELETE /me/favorites/jobs/{job_reference}   (idempotent, contrainte UNIQUE)
  GET         /me/favorites/jobs                    (paginé, carte publique + available)
  identité canonique = (job_source, job_reference)  — natif et externe = espaces de noms distincts
  offre indisponible → favori conservé, available:false (via JobDirectory::public_card)

Recherches sauvegardées + alertes
  CRUD /me/saved-searches  + /preview + /run-now
  filtres = whitelist Jobs (FilterValidator, strict) ; clé inconnue → 422
  alert_frequency : disabled | daily | weekly   (modèle de vérité unique — pas de flag enabled)
  déduplication par filters_hash (§14) ; quotas favoris 500 / recherches 20 / alertes actives 10

Moteur d'alertes (MatchingService)
  Scheduler du core → ancre quotidienne 07h30 Europe/Paris (auto-replanifiée, DST-correct)
    → drain par lots (next_run_at atteint) → une recherche = une recherche BORNÉE via
      Jobs\Api\JobSearchDirectory (moteur natif+externe, published_after) → pagination jusqu'à
      épuisement ou limite de sécurité (anomalie loguée) → réservation deliveries (UNIQUE) →
      seules les NOUVELLES → digest → événement job_alert.matches_found
  garantie anti-doublon = table deliveries (UNIQUE saved_search_id, job_source, job_reference)
    le curseur cursor_ts n'est qu'une optimisation de fenêtre (published_after), pas la dédup

Notifications (découplé)
  postelio-alerts enregistre la catégorie job_alert + le template job_alert_digest via filtres
    (postelio-notifications n'a AUCUNE dépendance en dur ; module absent → catégorie absente)
  NotificationRouter consomme job_alert.matches_found → 1 digest in-app + 1 e-mail par cycle
    e-mail seulement si adresse vérifiée (§17), soumis aux préférences de canal

RGPD
  user.deleted → purge favoris + recherches + deliveries
  filtre postelio/users/export → favoris + recherches ajoutés à l'export
  suspension ≠ suppression : aucune mutation, aucun run, données conservées
```

## Tables
- `postelio_job_favorites` — `UNIQUE(candidate_user_id, job_source, job_reference)` (1 favori/offre).
- `postelio_saved_searches` — `UNIQUE(candidate_user_id, filters_hash)` (dédup), `KEY(alert_frequency, next_run_at)` (sélection due).
- `postelio_alert_deliveries` — `UNIQUE(saved_search_id, job_source, job_reference)` (anti-doublon), rétention ≈13 mois.

## Capabilities
- Favoris : `pst_manage_own_favorites`.
- Recherches + alertes : `pst_manage_own_alerts` (les recherches sauvegardées sont le véhicule des
  alertes ; §16 : ne pas multiplier les capabilities). Toutes deux déjà déclarées dans le core.

## Décisions V1
- Candidat uniquement. Fréquences `disabled|daily|weekly` (architecture prête pour `immediate`, non
  implémenté). Rayon/géo hors V1 (le backend utilise `ville`). `published_after` = filtre INTERNE
  (jamais public). Admin = supervision **agrégée** (privacy-first).

## Filtres/hooks configurables
`postelio/alerts/max_favorites` (500) · `max_saved_searches` (20) · `max_active_alerts` (10) ·
`match_per_page` (50) · `match_max_pages` (40) · `digest_sample` (5) · `dispatch_batch` (150) ·
`deliveries_retention_days` (396) · `rate_write_per_hour` (30) · `rate_preview_per_min` (20) ·
`rate_run_per_hour` (6).

## Tests
- `php plugins/postelio-alerts/tests/run-unit.php` — ParisSchedule (DST), FilterValidator, empreinte.
- `wp eval-file plugins/postelio-alerts/tests/smoke.php --path=wordpress` — bout en bout (favoris,
  recherches, matching natif+externe, deliveries UNIQUE, dédup, notifications, RGPD).

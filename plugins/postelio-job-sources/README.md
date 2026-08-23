# Postelio Job Sources (Lot 10)

Agrégation/synchronisation d'**offres externes** dans Postelio, fusionnées à la recherche
`/jobs` **sans page séparée**. Premier provider réel : **France Travail** (API officielle
« Offres d'emploi v2 »). Indeed / HelloWork / ATS = **FUTUR / partenariat** (non
implémentés). **Aucun scraping.** Candidature externe = **redirection** (jamais de
candidature Postelio). Aucun secret en base ni en Git.

## Architecture
```
Scheduler (core)  →  SyncOrchestrator
   ├─ JobSourceRegistry ── JobSourceProvider (FranceTravailProvider ; FakeJobSourceProvider en test)
   │        └─ OAuth2 (token caché) · RateLimiter (throttle + circuit) · UrlGuard (SSRF)
   ├─ normalize() → NormalizedExternalJob (DTO) · HtmlSanitizer (XSS)
   ├─ ExternalJobRepository  → wp_postelio_external_jobs   (upsert / removed+anonymise)
   └─ SyncRunRepository      → wp_postelio_job_source_sync_runs (observabilité)

postelio-jobs (filtres publics, aucune dépendance circulaire) :
   postelio/jobs/search_provider   → CompositeJobSearchProvider (natif CPT ⊕ externe table)
   postelio/jobs/present_external  → ExternalJobPresenter (JobPresenter délègue)
   postelio/jobs/resolve_external  → résolution UUID externe (détail /jobs/{uuid}, JobDirectory)
```

## France Travail (revalidé sur doc officielle, cette session)
- **API** : `GET {base}/v2/offres/search` + `/v2/offres/{id}` ; base
  `https://api.francetravail.io/partenaire/offresdemploi`.
- **Pagination** `range=p-d` (≤150/appel, plafond ~3150/requête) ; total via `Content-Range`.
- **Incrémental** `minCreationDate`/`maxCreationDate` (par date de **création** ; pas de
  filtre d'actualisation → refresh complet ≥24h pour propager updates/suppressions).
- **Auth** OAuth2 client_credentials, token `entreprise.francetravail.fr/connexion/oauth2/
  access_token?realm=/partenaire`, scope `api_offresdemploiv2 o2dsoffre`, token ~25 min.
- **Quota** 10 req/s ; **429** → backoff + circuit + cache stale.
- Secrets : constantes `POSTELIO_FT_CLIENT_ID` / `POSTELIO_FT_CLIENT_SECRET` (ou env),
  **jamais** en base ni Git. Sans secrets, la source est *indisponible* et ses offres sont
  **exclues de la recherche** (§désactivation).

## Licence France Travail (respectée)
Réf. `francetravail.io/produits-partages/documentation/conditions-dutilisation-api/licence-offres-emploi`.
- **Attribution** (Art. 4) : source « France Travail » + date de mise à jour + lien vers la
  Licence → fournis dans `source.attribution` (`notice`, `licence_url`, `source_updated_at`).
- **Contenu complet + logo** (Art. 5.3) : le DTO conserve les champs d'affichage
  (competences, formations, langues, permis, qualification…) dans `source_metadata` + logo.
- **Refresh ≥24h + propagation** (Art. 5.2) : sync récurrente ; refresh complet par slice.
- **Retrait/anonymisation** (Art. 7) : une offre disparue à la source (refresh complet
  confirmé) passe `removed` et est **anonymisée** (company/description/URL/commune vidées).
- RGPD (Art. 8) : stockage UE, finalité mise en relation.

## Table dédiée (décision validée)
`wp_postelio_external_jobs` (PAS le CPT — volumétrie 100k–500k). `UNIQUE public_uuid`,
`UNIQUE(source_key, external_id)`, index source/status/visibility/slice/commune/contrat/
rome/dates. Une offre resynchronisée N fois reste **une** ressource (UUID stable). Le natif
reste en CPT `postelio_job`. À grande échelle, brancher un moteur d'index via le **même**
`JobSearchProvider`.

## Identifiants & provenance
`public_uuid` (exposé) ; `external_id` **jamais** exposé par l'API. `source_type`
`native|external`, `source_key`, `application_mode` (`postelio` | `external_redirect` |
futur `external_api`).

## Synchronisation
Pipeline : authenticate → fetch_page (slice) → normalize → sanitize → upsert (content_hash)
→ observabilité. **Slices** configurables (`postelio/job_sources/slices` : département/ROME/
fenêtre de dates…) car la pagination FT plafonne (~3150/requête). Import **progressif** :
caps `per_page` (≤150), `max_pages_per_run` (5), `offers_cap_per_run` (500), reprise par
offset. **Cadence** : worker récurrent `job_sources_sync` (filtre `postelio/job_sources/
sync_recurrence`, défaut `hourly`) via **Core Scheduler** (pas de 2ᵉ cron) ; dimensionner
les slices pour un refresh complet ≥ quotidien (licence). Sans slices configurés / sans
secrets → **no-op** (sécurité).

## Disparition d'une offre (§17)
- **CAS A** (panne / 429 / timeout / run partiel) → **aucun retrait** ; run `failed`/`partial`.
- **CAS B** (refresh **complet** du slice réussi) → offres actives non revues = `removed` +
  **anonymisation**. États sync `active | stale | removed` (indépendants du workflow natif).
- Période de grâce implicite : tant qu'un refresh complet ne confirme pas l'absence, l'offre
  reste affichée.

## Masquage admin & désactivation
`local_visibility` `visible|hidden` — **préservé à la resync** (jamais réactivé
automatiquement). Source **désactivée** (secrets retirés ou filtre off) → offres **exclues
de la recherche** ; non hard-deletées.

## Candidature externe (§17/29/30)
`external` + `external_redirect` ⇒ **aucune Application Postelio** (garde dans
`ApplicationService::apply` → `409 conflict`). CTA front → `GET /jobs/{uuid}/apply-redirect`
→ **302** vers `external_apply_url` (repli `external_url`), après revalidation d'URL ;
émet `external_job.apply_redirected` (analytics, **jamais** une candidature). Offre
removed/hidden → **410**.

## Sécurité
- **SSRF** : le serveur n'appelle QUE les hôtes officiels FT (`UrlGuard::api_host_allowed`).
- **URL de redirection candidat** : `UrlGuard::safe_redirect_url` (https, pas de
  `javascript:`/`data:`/`file:`, pas de localhost/IP privée) — SANS restriction de domaine
  (partenaires FT légitimes), sans requête serveur dessus.
- **HTML** : `HtmlSanitizer` liste blanche stricte (pas de script/iframe/style/on*).
- Payload validé, timeouts, retry borné + backoff, circuit breaker.

## API
- `GET /jobs` (recherche **unifiée** ; filtre `source=all|postelio|partners`, défaut `all`).
- `GET /jobs/{uuid}` (détail natif OU externe ; externe removed/hidden → **410**).
- `GET /jobs/{uuid}/apply-redirect` (externe → 302 ; sinon 404 ; removed → 410).
- `GET /job-sources/health` (admin `pst_manage_platform`) : par provider — disponible,
  offres actives, dernière sync/succès, dernière erreur (jamais de secret).

## SEO (contrat, front non modifié)
Offres externes : `seo.noindex=true`, `seo.canonical` = URL source (si fiable),
`seo.in_sitemap=false`. Fiche externe removed → 410. Dates : `source_published_at` /
`source_updated_at` / `synced` distinctes (jamais une date Postelio trompeuse).

## Événements
`job_source.sync_started|completed|failed` (admin/observabilité), `external_job.created|
updated|removed` (interne), `external_job.apply_redirected` (analytics). **Jamais** vers les
notifications utilisateur.

## Contrats étendus (additifs, non destructifs)
- `postelio-jobs` : `JobPresenter` (branche externe + bloc `source`/`application` natif),
  `JobController::get_public` (repli externe + 410) et `sanitize_filters` (`source`),
  `JobDirectory::is_external/external/application_mode`, filtres `search_provider`/
  `present_external`/`resolve_external`.
- `postelio-applications` : garde « offre externe → 409, pas de candidature ».

## Mapping France Travail (extrait)
`id→external_id`, `intitule→title`, `description→(sanitizé)`, `entreprise.nom→company_name`
(nullable → « Entreprise confidentielle »), `entreprise.logo→company_logo_url`,
`lieuTravail.{libelle,commune,codePostal,latitude,longitude}`, `typeContrat→
contract_code_source`+`contract_normalized`, `natureContrat`, `romeCode/romeLibelle`,
`codeNAF/secteurActiviteLibelle`, `experienceExige/Libelle`, `salaire.libelle→salary_text`,
`dureeTravailLibelleConverti→working_time`, `alternance`, `dateCreation→source_published_at`,
`dateActualisation→source_updated_at`, `origineOffre.urlOrigine→external_url`,
`contact.urlPostulation|urlRecruteur→external_apply_url`. Complément d'affichage (licence)
→ `source_metadata`. **Pas** de stockage du JSON brut (seulement `content_hash`).

## Tests
- `tests/run-unit.php` (38) : UrlGuard, HtmlSanitizer, content_hash, mapping FT (fixtures :
  complète, salaire absent, entreprise confidentielle, alternance, URL partenaire, HTML
  malveillant, champs inconnus).
- `tests/smoke.php` (32) : sync create/update/unchanged, UUID stable, panne≠retrait, hidden
  préservé, retrait+anonymisation confirmé, recherche unifiée + filtre source, détail + 410,
  apply-redirect 302/410/refus, garde applications (aucune row), attribution, provider
  désactivé.

## Points À VALIDER
- Provider e-mail/clé **production** FT (compte + souscription) — hors code.
- Cohérence détail/apply-redirect quand la source est **désactivée** (aujourd'hui la
  recherche exclut, le détail par UUID résout encore) — à arbitrer.
- Périmètre d'import V1 (slices) + cadence précise selon quota constaté.
- SEO : application front (noindex/canonical/sitemap) — front non touché ce lot.
- Indeed / HelloWork / ATS : partenariats.

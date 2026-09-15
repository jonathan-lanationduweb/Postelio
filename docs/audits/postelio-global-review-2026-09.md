# Postelio — Revue globale avant poursuite (15 septembre 2026)

Audit complet : sécurité, backend, WordPress, back-office, front, API, comptes, offres, entreprises,
candidatures, fichiers, messagerie, entretiens, e-mails, Stripe, sources externes, modération,
alertes, savoir-faire, Site Builder, SEO, tests, production.

**Méthode.** Revue de code des 16 plugins (≈ 39 000 lignes PHP), du front statique et de la
configuration locale ; exécution de **15 suites unitaires (529 vérifications)** et **14 smokes
WordPress (≈ 850 vérifications)** ; **campagne HTTP réelle** (auth + matrice de 33 routes × 6
rôles) ; **campagne métier REST interne** de 182 vérifications (rest_do_request, garde de
permission appliquée) ; tests navigateur (front 375 px, back-office rendu statique) ; comparaison
avec la référence visuelle LNDW. Les comptes `audit_*` et toutes les données créées ont été
supprimés à la fin. Aucune correction de code n'a été faite pendant l'audit.

> **Transparence.** La commande `/security-review` n'existe pas dans cet environnement (skills
> vérifiés : aucune skill de sécurité, WordPress ou revue de code n'est installée). La revue de
> sécurité a donc été menée manuellement (grep systématique, lecture des contrôleurs, tests
> HTTP). Les captures du back-office ont été obtenues par **rendu statique** des écrans PHP réels
> (HTML des `Screen::render()` + CSS wp-admin + CSS du plugin) car l'injection d'une session
> wp-admin dans le navigateur d'audit a été refusée par la politique de sécurité de l'outil.

---

## 0. État Git (relevé avant tout)

| Élément | Valeur |
|---|---|
| Branche courante | `feature/front-public-jobs-companies` |
| `develop` | `00932f6` (Lots 01–14, postelio-site, postelio-backoffice, postelio-admin supprimé) |
| `main` | `027cbe1` — **intact** |
| Working tree | propre (2 fichiers non suivis hors périmètre : `.claude/`, `.vscode/settings.json`) |
| I2 | `5cc3c6b` → merge develop `33536f8` → correctif `930a9e2`, **non mergé** (3 commits d'avance) |
| Branches feature ouvertes | 26 branches `feature/*` (toutes mergées sauf I2) + `fix/notif-bell-overlay` |

**Anomalie Git relevée.** `main` n'est **pas** un ancêtre de `develop` : `main` porte 4 commits
absents de `develop` (`0fb4ecb` palette, `154748f`, `0e233e4` cloche, `027cbe1`). Le **contenu**
est identique (`git diff main develop -- assets/css` vide : la palette et le correctif `cine.css`
ont été réappliqués dans develop), mais le futur merge `develop → main` sera un merge à deux
parents avec historique divergent. À traiter au moment de la mise en ligne (merge `--no-ff`, pas de
rebase de main).

---

## 1. Executive summary

**Niveau de risque global : MOYEN.** Le socle métier est solide (les 16 plugins passent leurs
suites, la campagne métier confirme ownership, non-divulgation et machines à états), mais la
plateforme **ne peut pas fonctionner de bout en bout aujourd'hui** :

1. **Aucun e-mail ne part** (transport PHP `mail()` → `localhost:25` → rien). 26 livraisons de la
   campagne restent `pending`, les 2 échecs historiques sont le même symptôme. Vérification de
   compte, reset de mot de passe, alertes, entretiens : tout est bloqué.
2. **Job-sources : cause exacte trouvée.** Les 6 échecs smoke et l'incohérence liste/détail d'I2
   ont **une seule cause** : le filtre de recherche exclut les sources *enregistrées mais
   indisponibles* (`disabled_source_keys()`), donc une ligne dont la `source_key` n'est **pas
   enregistrée** (`smoke_src`, résidu du smoke postelio-alerts qui ne nettoie pas) reste listée,
   alors que le détail la déclare indisponible (404). **Sans cette ligne : 47/47.** Le bug est
   propagé aux alertes (l'aperçu d'une recherche sauvegardée la contient).
3. **Aucune protection anti-brute-force sur `/auth`** (12 mauvais mots de passe → 12 × 401, login
   toujours possible) et **vérification e-mail désactivée par défaut** (`postelio/require_email_verification` = false → tout nouveau compte est auto-vérifié).
4. **Stripe non configuré** (aucune clé, `billing/health = degraded`, vendeur non renseigné,
   facture légale non prête) — attendu, mais cela veut dire que le renouvellement payant n'a
   jamais été testé hors FakeProvider.
5. **Site Builder : ce qui est réglé dans wp-admin ne s'applique qu'à l'aperçu.** Le front public
   ne consomme pas `GET /site/config` : logo, favicon, couleurs, SEO, navigation, footer, vidéo ne
   changent pas sur le site réel.
6. **SEO du front non prêt** : aucun `canonical`, sitemap/robots pointant sur GitHub Pages et
   listant des pages privées (`espace-entreprise`, `paiement`, `connexion`), pas de `noindex` sur
   les pages privées, fiches offre/entreprise rendues côté client.
7. **Back-office fonctionnel mais visuellement en dessous de la référence LNDW** (voir §22).

Compteurs : **P0 : 3 · P1 : 9 · P2 : 12 · P3 : 8.** Aucune faille critique immédiatement
exploitable n'a été trouvée (pas d'injection SQL, XSS neutralisés partout où testé, fichiers privés
inaccessibles en HTTP direct, webhook signé, secrets hors dépôt).

---

## 2. Security review

### 2.1 Résultats positifs (vérifiés)
- **SQL** : 108 requêtes `$wpdb->prepare`, aucune interpolation de valeur utilisateur ; les
  concaténations restantes ne portent que des noms de table/clauses construites en interne
  (`ExternalJobRepository::search_public`, `ApplicationRepository::list`).
- **Superglobales** : 12 accès `$_GET/$_POST` hors tests, tous dans postelio-backoffice, tous
  `sanitize_*` + `wp_unslash`, mutations derrière `wp_verify_nonce` (`_pstnonce`) + capability.
- **Sortie** : tout le HTML admin passe par `Ui` (échappé) ; 2 `echo` de composition marqués phpcs.
- **Fonctions dangereuses** : aucun `eval`, `unserialize`, `exec`, `system`.
- **SSRF** : seuls appels sortants = Stripe (`API_BASE` constant) et France Travail (hôtes fixes,
  `UrlGuard`) ; redirection `apply-redirect` revalidée (https, pas de `javascript:`).
- **Redirections** : `wp_safe_redirect` côté admin ; `Location` du redirect externe validé.
- **Fichiers privés** : `wp-content/postelio-private/files/` → **403 en HTTP direct** (`.htaccess`
  deny vérifié), noms aléatoires, MIME `finfo` + signature `%PDF-`, traversal neutralisé
  (`../../../etc/passwd.pdf` → nom assaini), `.php` déguisé → 415, streaming avec `nosniff` +
  `CSP default-src 'none'; sandbox` + `Accept-Ranges` (206 vérifié), autres utilisateurs → 404,
  anonyme → 401, UUID inconnu / traversal dans l'URL → 404.
- **Webhook Stripe** : HMAC-SHA256 sur `t.payload`, `hash_equals`, tolérance 300 s, plusieurs
  `v1`, corps brut, UNIQUE(provider,event_id), rejeu → `duplicate`, double paiement → `manual_review`,
  signature invalide → 400 (vérifié HTTP).
- **Bearer** : `uid.tid.secret`, secret hashé SHA-256, expiration serveur, refresh invalide
  l'ancien (vérifié : ancien → 401, nouveau → 200), logout → 401, jeton bidon → 401.
- **Site Builder** : `postMessage` filtré sur `e.origin === location.origin` des deux côtés ;
  URLs via `esc_url_raw(['http','https'])` (bloque `javascript:`/`data:`) ; bridge inerte sans
  `?postelio_preview=1` ; XSS `<script>` et `javascript:` neutralisés (vérifié via `POST
  /site/admin/home`), recruteur/modérateur → 403.
- **Matrice rôles** (HTTP réel, 33 routes × 6 rôles) : cohérente — voir §6.

### 2.2 Constats
| # | Constat | Sévérité |
|---|---|---|
| S1 | Aucun rate limiting / verrouillage sur `POST /auth`, `/auth/lost-password`, `/auth/register` (décision D5 documentée, non implémentée). Seules messagerie, signalements et commentaires sont rate-limités. | **P1** |
| S2 | `postelio/require_email_verification` par défaut **false** : `POST /auth/register` renvoie `email_verified:true` immédiatement (D12 inopérante par défaut). La garde `pst_email_verified` fonctionne bien quand la méta est absente (403 vérifié). | **P1** |
| S3 | E-mails de vérification et de reset envoyés par `wp_mail()` brut (texte nu, lien avec `rest_route`+`token` en GET) sans passer par postelio-notifications ni ses templates. Le token de vérification est bien à usage unique et hashé, mais le flux est hors observabilité (pas de ligne `deliveries`). | P2 |
| S4 | Non-divulgation incomplète : recruteur B sur offre A → **403** (attendu 404) pour PUT/publish ; modérateur sur conversation → 403 ; recruteur B checkout offre A → 422. Révèle l'existence de la ressource. | P2 |
| S5 | Admin `postelio_admin` **ne peut pas** télécharger un CV (404) alors que security.md prévoit « admin pour audit ». Choix à trancher (privacy-first actuel = acceptable) ; documenter. | P3 |
| S6 | WordPress local : `WP_DEBUG=true` + `WP_DEBUG_DISPLAY` (défaut true), `display_errors=On`, XML-RPC ouvert (200), `wp/v2/users` public (énumère `admin`), `readme.html` 200, listing de répertoire `wp-content/plugins/` 200, aucun `DISALLOW_FILE_EDIT`, `WP_ENVIRONMENT_TYPE` absent (l'écran Réglages affiche « production » en local), `session.cookie_httponly` vide, `upload_max_filesize=8M` < limite CV 10 Mo. Aucun en-tête CSP/HSTS/X-Frame-Options sur le front. **Local uniquement, mais à verrouiller en production** (§26). | P1 (prod) |
| S7 | Interviews : les **listes** `GET /me/interviews` et `GET /companies/me/interviews` contiennent les coordonnées (`meeting_url`, téléphone…) — l'exigence « liste : jamais ; détail autorisé : oui » n'est respectée que dans le back-office. Le lecteur est autorisé, risque réel faible, mais contrat non tenu. | P2 |
| S8 | `PUT /jobs/{uuid}` accepte `titre: ""` (200) — la validation « Intitulé requis » n'existe qu'à la création. | P2 |
| S9 | Cookies/CORS : le front et l'API sont même origine en local (`/wordpress`). Si le front reste sur GitHub Pages en production, il faudra une politique CORS explicite (`Access-Control-Allow-Origin` restreint au domaine front, `Authorization` autorisé) — aujourd'hui rien n'est prévu. | P1 (prod) |

---

## 3. Backend

- 16 plugins actifs, schémas à jour (`*_schema = 1`), 22 tables `wp_postelio_*`, 104 routes REST
  `postelio/v1`, cron : `notifications_worker` (15 min), `billing_fulfillment_retry` (15 min),
  `job_sources_sync` (hourly), `jobs_expiration` (daily), `alerts_dispatch`, rappels entretien.
- Machines à états vérifiées en campagne : offre `draft → published → suspended → published →
  expiring → expired → (renouvellement contrat billing) published → filled → archived`
  (publish sur archivée → 409) ; candidature `new → review → shortlisted → interview`, refus avec
  motif interne non exposé, retrait interdit après refus ; entretien `proposed → confirmed →
  reschedule_requested → accepted → proposed (modif substantielle) → declined`, annulation
  recruteur, `completed` manuel, 3 canaux ; doublon actif identique → 409.
- **Exactly-once renouvellement** : rejouer `renew_after_payment` avec le même `order_uuid` sur
  une offre déjà `published` est refusé (« Offre non renouvelable »), 0 ligne `job_renewals`
  supplémentaire. Côté billing, l'idempotence repose sur UNIQUE(provider,event_id) + statut
  `duplicate` (smoke 69/69).
- **Constats** : voir S8 ; **B1** entreprise suspendue peut encore créer un brouillon (201) — à
  trancher ; **B2** cascade `company.suspended → job.suspended` **non inversée** à la réactivation
  (l'offre reste `suspended`, `notify:false`, republication admin manuelle par offre, aucune
  notification au recruteur) — comportement documenté (workflows.md l. 267) mais piège
  fonctionnel ; **B3** candidature acceptée **sans réponses de présélection** alors que l'offre
  définit une question (201) — la validation ne porte que sur les ids fournis ; **B4** filtre
  `company` de `GET /jobs` ignoré (UUID bidon → même total) ; **B5** `GET /companies` ignore `q`,
  `ville`, `secteur` (pagination OK).
- 4 événements ponctuels `notifications_flush` coexistent (args différents → `wp_next_scheduled`
  ne déduplique pas) : bruit inutile, pas de bug.

## 4. WordPress

- WordPress 7.1, PHP 8.3.28 (Apache mod_fcgid), MariaDB, `users_can_register=0`, rôle par défaut
  `subscriber`, SVG upload désactivé (bon).
- Rôles : 1 `administrator`, 30 candidats, 27 recruteurs, 1 modérateur ; `postelio_admin` **n'a
  pas** `manage_options` ni `install_plugins` (séparation saine : l'admin métier n'est pas un
  admin WordPress). Candidats/recruteurs sans `edit_posts`/`manage_options` (vérifié).
- Textdomain : plus aucune Notice (corrigé Phase A). `debug.log` absent.
- Durcissement production : voir S6 et §26.

## 5. Frontend

- 30 pages HTML statiques, 888 Ko JS / 348 Ko CSS non bundlés (≈ 20 requêtes par page), vidéo
  hero 3,1 Mo, aucune image > 300 Ko.
- I1/I2 : client API unique (`postelio-api.js`), Bearer uniquement (pas de cookie WP → pas de
  nonce), annuaire réel (offres, entreprises), plus aucun repli JSON dans les parcours I2 ;
  `data/*.json` encore utilisé par `guided-search.js` et les espaces privés (hors périmètre I1/I2).
- Mobile 375 px vérifié : accueil (intro vidéo puis hero lisible), offres, connexion, inscription,
  entreprises, fiche offre → **aucun débordement horizontal**, CTA pleine largeur, menu burger.
  Espaces candidat/recruteur non testés en navigateur (login interdit à l'outil) : ils restent en
  `localStorage` (hors I1/I2) — voir §28.
- Détails : fiche offre affiche « Lyon · · Publiée » quand le contrat est vide (séparateur
  doublé) ; `inscription.html`/`connexion.html` chargent `offers.js`/`directory.js` inutilement ;
  README décrit encore la palette « vert sapin & miel » (obsolète).

## 6. Authentication

Cycle complet HTTP réel : inscription 201 → doublon 409 → payload invalide 422 (3 erreurs
détaillées) → mauvais mot de passe / inexistant : **même message** « Identifiants invalides »
(pas d'énumération) → suspendu : 403 « Compte indisponible » → `/me` anonyme 401, jeton bidon 401
→ refresh (ancien 401, nouveau 200) → logout 200 puis 401 → lost-password 200 pour e-mail connu
**et** inconnu (bon) → reset clé bidon 409 → verify-email token bidon 409 → **12 tentatives
brute force : aucun verrou** (S1).

Matrice HTTP réelle (extrait ; 200 = accès, 401 anonyme, 403 interdit, 404 non-divulgation/aucune entreprise) :

| Action | Anonyme | Candidat | Recruteur | Modérateur | Support | Admin |
|---|---|---|---|---|---|---|
| GET /jobs, /companies, /skills, /site/config, /health | 200 | 200 | 200 | 200 | 200 | 200 |
| GET /me | 401 | 200 | 200 | 200 | 200 | 200 |
| GET /candidates/me/profile | 401 | 200 | 403 | 403 | 403 | 200 |
| GET /recruiters/me/profile | 401 | 403 | 200 | 403 | 403 | 200 |
| GET /companies/me · /jobs/me · /companies/me/applications | 401 | 403 | 404* | 403 | 403 | 404* |
| POST /jobs | 401 | 403 | 409* | 403 | 403 | 409* |
| GET /me/applications · /me/files/cv · /me/interviews · /me/favorites · /me/saved-searches · /me/export | 401 | 200 | 403 | 403 | 403 | 200 |
| GET /me/conversations · /me/skills · /me/moderation/reports | 401 | 200 | 200 | 403 | 403 | 200 |
| GET /companies/me/interviews · /billing/orders | 401 | 403 | 200 | 403 | 403 | 200 |
| GET /me/notifications | 401 | 200 | 200 | 200 | 200 | 200 |
| GET /billing/health · /billing/admin/orders · /job-sources/health · /site/admin/search | 401 | 403 | 403 | 403 | 403 | 200 |
| GET /moderation/cases · /moderation/health | 401 | 403 | 403 | 200 | 403 | 200 |
| POST /companies/{x}/verification/decision | 401 | 403 | 403 | 403 | 403 | 404 (uuid bidon) |

\* sans entreprise rattachée. **Tout PASS** : le support n'accède à aucune donnée métier, le
modérateur n'a ni facturation ni plateforme, le candidat n'atteint aucune route recruteur et
inversement.

## 7. Users

- Suspension : login 403, `POST /jobs` 403 pour recruteur suspendu, réversible via
  `UserModeration` (jetons révoqués). Suppression/anonymisation : `DELETE /me` présent, export
  `GET /me/export` 200 (candidat).
- Données de test : **136 profils candidats pour 30 comptes**, **45 profils recruteurs pour 27**
  → profils orphelins laissés par les smokes ; 1 448 lignes d'audit, 1 399 notifications, 1 549
  livraisons (1 516 `skipped`). Voir §23 « données ».

## 8. Companies

Campagne : création owner 201, 2e entreprise 409, SIREN doublon refusé, fiche publique sans
`legal_declared`/membres, demande → `manual_review`, recruteur/modérateur/support ne peuvent pas
vérifier (403), admin `verified`/`rejected` 200, motif de rejet non exposé, légal verrouillé après
vérification (403), éditorial modifiable, entreprise rejetée reste visible mais ne publie pas
(403/409). Suspension : fiche 404, offres 404, réactivation → fiche revient, **offres restent
suspendues** (B2).

**Contrat public confirmé insuffisant pour I2** : `q`/`ville`/`secteur` ignorés sur `/companies`
(B5), filtre `company` ignoré sur `/jobs` (B4) → la fiche entreprise ne peut pas lister ses
offres et renvoie vers la page Offres. Pagination OK.

## 9. Jobs

Voir §3 pour le cycle complet (draft, publication conditionnée à `verified`, édition, duplication,
suspension admin, réactivation admin, expiration cron J-7 → `expiring` (visible) → `expired`
(404), renouvellement, pourvue, archive). Ownership A/B : recruteur B → 403 (S4), candidat → 403.
Recherche : `q`, `ville`, `contrat`, `salaire_min` appliqués ; valeur hors liste → 200 / 0 item ;
`per_page=1000` borné ; pagination cohérente. Constats : S8 (titre vide), B1, B4.

## 10. Applications

Postuler 201, doublon 409, recruteur 403, brouillon 404/409, message XSS neutralisé, **snapshot
figé** (le titre renommé après candidature n'apparaît pas), recruteur B 404, autre candidat 404,
transitions et 409 sur transition invalide, notes recruteur : création 201, candidat 403/404,
absente du détail candidat, recruteur B 404. Motif de refus interne non exposé. Retrait après refus
409. Timeline présente. Constat B3 (présélection non exigée).

**Écarts machine backend ↔ UI front actuelle** (`espace-candidat.html`, `espace-entreprise-candidatures.html`, encore en `localStorage`) : le front connaît `envoyee/vue/entretien/retenue/refusee/retiree` ; le backend expose `new/review/shortlisted/interview/selected/rejected/withdrawn`, une timeline serveur, des notes recruteur, un `cv_reference`. Le mapping et le branchement réel sont le chantier **I3** (non commencé, conformément à la consigne).

## 11. Files

Voir §2.1 (tous PASS). Upload HTTP réel multipart 201, `.php` déguisé 415, extension `.exe`
refusée, 9 Mo → **422 « Fichier manquant ou upload en échec »** car `upload_max_filesize=8M` <
limite Postelio 10 Mo (message trompeur, config à aligner). Aucun `storage_key`/chemin dans les
réponses. `FileScanner` = `NullScanner` (pas d'antivirus, prévu). Recruteur autorisé : accès
uniquement via `postelio/files/authorize_download` (candidature référençant le CV) — non exercé
en HTTP car la candidature de test ne référençait pas le CV ; couvert par le smoke files (42/42).

## 12. Messaging

Ouverture par recruteur membre (200, idempotente), recruteur B 404, candidat 403/404, envoi 201,
XSS `onerror` neutralisé, vide 422, > 5 000 car. 422, lectures B/autre candidat 404, modérateur 403
(S4), `unread_count` et `read` OK, fermeture owner 200 / candidat 403, envoi après fermeture 409.
**Rate-limit non déclenché en 40 envois** (seuil plus haut : à confirmer/abaisser). Admin : le
back-office n'expose que des compteurs (corps jamais lu) — vérifié Phase 3.

## 13. Interviews

Cycle complet PASS (proposer visio, doublon 409, recruteur B 404, détail candidat avec
coordonnées, autre candidat/recruteur B 404, confirmer, re-créneau candidat → acceptation
recruteur, modification substantielle → `proposed`, décliner, téléphone + annulation, présentiel +
`completed`, candidat ne peut pas terminer). Instructions XSS neutralisées. **Constat S7** : les
listes API exposent les coordonnées. Le bug « Array to string » du back-office est bien corrigé.

## 14. Notifications / Email

**Réponse à la question « Postelio peut-il envoyer et recevoir ses e-mails ? » : NON.**
- Transport : `wp_mail()` → PHPMailer `mail()` → `SMTP=localhost:25`, `sendmail_path` vide, rien
  n'écoute sur 25 (ni 1025/8025 : Mailpit absent). Erreur reproduite en direct : « Impossible
  d'instancier la fonction mail. (code 2) ». Dernier test admin : 04/09 → échec.
- Campagne : 26 livraisons créées (`interview_*`, `job_alert_digest`, `application_received`…),
  worker exécuté → **26 `pending`**, `last_error = wp_mail_failed`, retentatives programmées
  (backoff) → elles finiront `failed` comme les 2 historiques.
- Aucun plugin SMTP, aucun `phpmailer_init`, provider unique `WpMailProvider`.
- **Templates existants (18)** : application_received, new_application, application_selected,
  application_rejected, new_message, interview_proposed, interview_confirmed_proof,
  interview_declined, interview_rescheduled, interview_cancelled, interview_reminder, job_expiring,
  job_expired, job_suspended, job_renewed, company_verified, company_rejected, company_suspended ;
  + `job_alert_digest` ajouté par postelio-alerts.
- **Manquants / hors système** : e-mail de **vérification** et **reset mot de passe** (wp_mail brut,
  S3), **bienvenue/inscription** (aucun), **commentaire savoir-faire** (`skill.comment_created`
  émis, aucun listener), **candidature retirée** (in-app seulement, voulu), rappel de paiement /
  reçu Stripe (délégué à Stripe), aucun template HTML (texte brut partout).

## 15. Billing / Stripe

- Implémenté et testé avec **FakeProvider** (smoke 69/69 : checkout, webhook signé/invalide,
  completed→paid→fulfilled, rejeu idempotent, async success/failed, expired, refund, dispute,
  double paiement → `duplicate` + `manual_review`, retry fulfillment, crash window).
- Campagne : checkout sur offre non renouvelable → 409/422, candidat 403, recruteur B 422 (S4),
  webhook signature invalide 400.
- **Ce qui manque pour un test Stripe TEST réel** : `POSTELIO_STRIPE_SECRET_KEY` (sk_test),
  `POSTELIO_STRIPE_WEBHOOK_SECRET`, exposition publique du webhook (Stripe CLI `stripe listen
  --forward-to`), `POSTELIO_SELLER_*`, une offre `expiring/expired` réelle, puis : paiement carte
  test, 3DS, `async_payment_*` (SEPA), expiration session, refund et dispute depuis le dashboard,
  rejeu d'événement, double clic checkout.
- **Production readiness** (§26) : BLOQUANT TECHNIQUE = clés live + webhook HTTPS + `seller_configured` ;
  BLOQUANT LÉGAL = identité vendeur (SIREN/TVA/adresse), **facture numérotée conforme** (V1 n'expose
  qu'un reçu Stripe, `invoice_legal_ready=false`), mentions TVA (B2C 20 % / B2B UE autoliquidation),
  conservation 10 ans, CGV/politique de remboursement, gestion chargeback ; OPTIONNEL = Stripe Tax,
  clé publiable, portail client.

## 16. Job Sources

**Cause exacte des 6 échecs** (reproduits puis prouvés) : la table `wp_postelio_external_jobs`
contient une ligne `source_key='smoke_src'` (créée le 01/09 par `postelio-alerts/tests/smoke.php`
l. 84, jamais nettoyée). `JobSourceRegistry::disabled_source_keys()` ne retourne que les sources
**enregistrées** indisponibles (`france_travail`) → `search_public()` (denylist `NOT IN`) laisse
passer toute ligne de source inconnue, alors que `resolve_external()` répond `source_available=false`
→ **liste 200 / détail 404 / apply-redirect 404**. Les 6 assertions (compte « aucune offre retirée »,
attribution, hidden absent, source désactivée, licence, date) échouent toutes parce que cette ligne
est comptée/renvoyée en premier. **Ligne retirée : 47/47 ; ligne restaurée : 41/47.**

| | |
|---|---|
| Impact | Utilisateur : clic sur une offre listée → « Offre introuvable » ; alertes : l'aperçu/digest peut contenir l'offre ; I2 ne peut pas honorer son contrat externe. |
| Sévérité | **P0** (bloque le merge d'I2 et fausse la CI depuis 2 semaines). |
| Correction proposée | (1) `search_public()` : passer d'une denylist à une **allowlist** des `source_key` enregistrées **et** disponibles (`IN (...)`, aucune si liste vide) ; (2) même prédicat dans le repository pour `hidden` (déjà) ; (3) smoke alerts : nettoyer sa ligne ; (4) purge unique de la ligne orpheline ; (5) test smoke « source inconnue en base → absente de la recherche ». |
| Risque de régression | Faible : allowlist strictement plus restrictive ; si aucun provider n'est enregistré → 0 offre externe (comportement attendu). Vérifier l'aperçu alertes et `source=partners`. |

Conformité licence FT (attribution, notice, licence_url, `source_updated_at`, anonymisation
`removed`, 410) : PASS une fois la ligne retirée. Provider FT non configuré localement
(`POSTELIO_FT_CLIENT_ID/SECRET` absents → indisponible, comme prévu).

## 17. Moderation

Signalement 201, dédup `duplicate:true`, mes signalements sans id SQL ni note, file modérateur 200,
support 403, assign, note interne (invisible du signaleur), `suspend_job` modérateur → **403**
(action admin), admin → 200 (offre 404 public), unsuspend + resolve OK, signalements `message`
(conversation), `company`, `job` acceptés. **Constat** : un signalement `external_job` sur la ligne
orpheline est accepté (201) alors que la ressource est publiquement indisponible — même cause
que §16. Fail-closed de la passerelle préventive et privacy des cas : couverts par le smoke
(66/66). Ressources `skill`/`skill_comment` : couvertes par smoke skills.

## 18. Alerts

Favori add/idempotent/404/remove PASS ; recherche sauvegardée 201, doublon 409, filtre inconnu
422, preview 200, run-now 200, autre candidat 404, recruteur 403. **Bug §16 propagé** : l'aperçu
inclut l'offre externe orpheline. Suspendu/suppression/digest/préférence OFF/Notifications
absent : couverts par le smoke alerts (62/62) — mais ce smoke **laisse un résidu en base**.

## 19. Skills

Brouillon 201, `<script>`/`javascript:` retirés, brouillon 404 public, autre utilisateur 404,
publication 200, byline sans e-mail, bloc `seo.noindex=false`, commentaire recruteur 201, anonyme
401, archive → 404 public. **Manquants** : notation, compteur de vues, réactions, avis employeur
(cf. nom « Savoir-faire & Avis ») — **après lancement** (V1 = contenu éditorial + commentaires
suffit), sauf si le positionnement marketing impose les avis employeur dès l'ouverture.

## 20. Site Builder

- Éditeur schéma-driven (10 pages), sauvegarde/rechargement, aperçu iframe du **vrai front**,
  Desktop/Tablette/Mobile, Footer mobile-only avec `target=footer`, Navigation `target=header`,
  identité centralisée (logo, favicon `#FF6B6B`/`#17324D`), media picker, collections, XSS/URL
  dangereuses neutralisés (vérifié).
- **Ce qui n'est visible QUE dans l'aperçu** : **tout**. Aucun script du front public ne lit
  `GET /site/config` hors `?postelio_preview=1` (`site-preview-bridge.js` l. 19 retourne
  immédiatement). Logo, favicon, couleurs, typo, SEO, navigation, footer, vidéo, sections : les
  29 pages HTML restent codées en dur. → **Chantier restant I12 « application publique de la
  config »** (P1 produit : sans lui le Site Builder n'a aucun effet réel).

## 21. SEO

Réellement présent dans le HTML : `<title>` unique par page, `meta description`, 6 balises Open
Graph, `lang="fr"`, JSON-LD sur l'accueil (2) et la liste des offres (1), `noindex` sur 404
uniquement ; **title dynamique** côté client sur la fiche offre (« Développeur – Lyon | Postelio »).
**Absent** : `rel=canonical` (0/30 pages), `noindex` sur pages privées (espaces, paiement,
connexion, inscription), meta description/OG dynamiques sur les fiches, JSON-LD `JobPosting`,
sitemap réel (19 URL statiques GitHub Pages dont pages privées et `offre-detail.html` sans
paramètre), robots pointant sur `github.io`, 410 pour offres retirées (le backend le fournit, le
front affiche un état unique « introuvable »). Le contrat SEO backend (`seo{noindex,canonical,
in_sitemap}`) n'est **pas rendu** : fiches indexables uniquement si pré-rendu/SSR ou sitemap
généré côté WordPress.

## 22. Backoffice UX/UI (analyse visuelle)

Comparaison écran par écran avec les captures LNDW (`Liste API`, `Arsenal`, `Carousel Missions`) :

| Axe | LNDW | Postelio aujourd'hui | Diagnostic |
|---|---|---|---|
| En-tête d'écran | Grande carte à **dégradé doux**, eyebrow violet, titre 32 px, description, **actions primaires alignées à droite dans l'en-tête** | Carte plate blanche, eyebrow corail, titre ~24 px, action isolée | Manque de présence ; l'en-tête ne « porte » pas l'écran |
| Navigation | Menu WP standard + tabs pills pleine largeur très lisibles (fond blanc, active violet plein) | Tabs pills correctes mais petites, compteurs peu contrastés | OK mais timide |
| Densité / largeur | Contenu large, blocs 2 colonnes (formulaire ↔ aperçu), peu de vide | 4 tuiles KPI + tabs + table sur **10 écrans identiques** ; Modération, Entretiens, Facturation, Sources, Réglages : 1–3 Ko de corps → **écran vide aux 2/3** | Sentiment « généré » : même squelette partout, pas de contenu spécifique |
| Cartes | Cartes riches : image, titre gras, chemin, statut chip, actions verticales, poignée de tri | Lignes de table avec avatar 24 px et 2 boutons | Pas de hiérarchie visuelle, tout se ressemble |
| Formulaires | Sections encadrées, labels forts, champs larges, compteur de caractères, aide contextuelle, boutons Remplacer/Réinitialiser | Éditeur Site : champs corrects mais sans aide, sans miniatures média visibles au repos | Moins « produit fini » |
| Media picker | Vignette 300 px + Remplacer + Réinitialiser | Champ URL + bouton | À aligner |
| Aperçu | Toujours visible à droite, cadre appareil (mockup tablette/mobile), légende | Zone aperçu grise avec message, loader ; pas de cadre appareil | Fonctionnel, pas séduisant |
| Statuts / feedback | Chips colorées douces (« Publiée » beige), alertes contextuelles | Points colorés + texte, alertes WP standard | Correct |
| États vides | Message d'aide + action proposée | « Aucun entretien ne correspond. » seul | Vide sec |
| Couleurs / typo | Violet/rose, beige chaud, Inter, contrastes forts | Bleu nuit/corail respectés, mais peu de surfaces colorées, chiffres KPI en Georgia | Identité présente mais froide |
| Responsive | Grille fluide | Grille fluide (CSS), tables non testées < 900 px | À vérifier |

**Ce que vous n'aimez pas — confirmé** : écrans trop simples (Sources, Facturation, Entretiens,
Réglages, Modération), trop de vide, répétition mécanique KPI→tabs→table, formulaires sans aide ni
miniatures, absence de vues détail riches (le bouton « Voir » ouvre une page pauvre), aucune
illustration ni état vide travaillé, pas d'actions groupées, pas de tri de colonnes.

**Proposition — Phase « Backoffice Polish » (ne pas coder avant validation)** :

| Écran | Problème | Référence LNDW | Correction | Priorité |
|---|---|---|---|---|
| Tous | En-tête plat, action orpheline | En-tête dégradé + actions à droite | En-tête « hero » avec dégradé bleu nuit → sable, eyebrow, actions primaires/secondaires, fil d'Ariane | P1 |
| Tableau de bord | KPI + 3 cartes « À traiter » petites | Carte cache frontend (icône, 3 sous-tuiles, CTA) | Blocs « À traiter » façon carte LNDW (icône, chiffres, CTA), colonne « Activité récente », santé e-mail/Stripe/sources en tuiles d'état | P1 |
| Listes (Utilisateurs, Entreprises, Offres, Candidatures) | Tables plates, avatar minuscule, actions répétées | Cartes missions (image, titre, méta, chip statut, actions) | Lignes plus hautes avec identité (logo entreprise, initiales colorées), chips statut, menu d'actions, tri, filtres persistants, sélection multiple | P1 |
| Détail (Voir) | Page pauvre | Formulaire sectionné + aperçu | Fiche à 2 colonnes : résumé + onglets (Offres / Membres / Candidatures / Historique), panneau latéral d'actions | P1 |
| Modération, Entretiens, Sources, Facturation, Réglages | 2/3 de vide | Contenu spécifique par écran | Contenu métier : timeline du cas, calendrier des entretiens, état des providers avec dernière sync/logs, courbe des commandes, réglages regroupés en cartes avec explications | P2 |
| Éditeur Site | Champs nus, aperçu gris | Vignettes média, Remplacer/Réinitialiser, cadre appareil | Vignettes 240 px, boutons Remplacer/Réinitialiser, compteur de caractères, cadre appareil dans l'aperçu, aide par section | P1 |
| États vides | Texte seul | Aide + action | Illustration légère + phrase d'aide + CTA | P2 |
| Notifications e-mail | Bonne base | — | Ajouter graphique 7 jours + liste des derniers destinataires masqués | P3 |

## 23. Testing

| Plugin | Unit | Smoke WP | E2E | HTTP réel | Browser | Couverture manquante |
|---|---|---|---|---|---|---|
| core | 34 | 21 | — | audit | — | rate limiting (absent) |
| users | 33 | 53 | — | **audit (cycle auth complet)** | — | brute force, reset e-mail réel |
| companies | 50 | 63 | — | audit | — | recherche/filtre publics (non implémentés) |
| jobs | 36 | 69 | — | audit | fiche mobile | validation update, filtre company |
| applications | 28 | 64 | — | audit | — | présélection obligatoire, UI I3 |
| files | 25 | 42 | — | **audit (upload multipart, download, range)** | — | recruteur autorisé en HTTP, antivirus |
| messaging | 20 | 59 | — | audit | — | seuil rate-limit |
| interviews | 48 | 67 (+18 fixture) | — | audit | — | coordonnées en liste |
| notifications | 32 | 75 | — | audit | — | **envoi réel (0)** |
| billing | 53 | 69 | — | audit (webhook 400) | — | **Stripe test réel (0)** |
| job-sources | 38 | **41/47 → 47/47 sans résidu** | — | audit | fiche externe | source inconnue, FT réel |
| moderation | 55 | 66 | — | audit | — | — |
| alerts | 29 | 62 (**laisse un résidu**) | — | audit | — | nettoyage |
| skills | 19 | 56 | — | audit | — | avis/notation (absents) |
| site | 29 | — | — | audit (XSS) | Site Builder (sessions précédentes) | application front |
| backoffice | 0 | 0 | — | — | rendu statique | **aucun test** (écrans, actions admin-post, privacy) |
| front | 23 (I1) + 30 (I2) node | — | — | — | mobile 375 | espaces privés, formulaires |

**Où l'on croit être couvert sans l'être** : e-mail (tous les tests passent avec un transport
mort), Stripe (Fake uniquement), back-office (0 test automatisé alors qu'il agrège tous les
domaines), front privé (aucun test, données localStorage), smokes non isolés (résidus en base qui
faussent d'autres suites — c'est exactement l'histoire des 6 échecs).

**E2E indispensables avant lancement** : (1) Candidat : inscription → e-mail de vérification
**reçu** → profil → CV → recherche → favori → alerte → candidature → message → entretien →
notifications ; (2) Recruteur : inscription → entreprise → vérification admin → offre publiée →
candidature reçue → statut → entretien → expiration → renouvellement **Stripe test** → offre
republiée ; (3) Admin : vérification entreprise → modération (signalement → décision) → source
externe (sync + indisponibilité) → facturation (commande, retry) → service e-mail ; (4) Externe :
offre FT listée → détail → apply-redirect → indisponible → disparition (410).

**Données de test** : fixtures JSON front (`offers.json`, `companies.json`, `articles.json`,
`savoir-faire.json`, `guided-search.json`) — les deux premières sont **obsolètes** pour I2 (encore
utilisées par la recherche guidée et les espaces privés) ; comptes de démonstration dans
`config.js` **obsolètes** depuis I1 ; base locale : profils orphelins, ligne `smoke_src`, 1 516
livraisons `skipped`, 6 conversations / 21 messages / 9 candidatures de smokes → **un seed
reproductible** (script `tools/seed-local.php` : 2 entreprises vérifiées, 5 offres, 3 candidats,
1 candidature par statut, 1 entretien par type, 1 offre externe) et un **reset** sont nécessaires.

## 24. Performance

Mesurable : ≈ 20 requêtes statiques par page (7 CSS + 13 JS non bundlés, cache navigateur OK),
vidéo hero 3,1 Mo en `mp4` unique (pas de `webm`, poster à vérifier), API : 1 requête liste + 1
requête détail + 1 requête « similaires » (pas de N+1 front), back-office : aucune boucle
`rest_do_request` par ligne (0 N+1), pagination serveur partout, `COUNT(*)` séparés sur listes
(acceptable à cette échelle). Fusion natif/externe plafonnée `merge_cap=100` (documenté). Rien
de bloquant ; bundling/minification et `webm`/poster = P3.

## 25. Accessibilité

Front (heuristiques) : 0 image sans `alt`, lien d'évitement sur 29/30 pages, `prefers-reduced-motion`
dans 5 feuilles, 34 règles `focus-visible`, 342 `aria-label`, `lang="fr"`, champs étiquetés.
Back-office : CSS avec 1 seule règle `focus-visible`/`reduced-motion`, 5 attributs aria dans `Ui`,
tables sans `scope`, modales inexistantes (bien). À faire : audit clavier réel des espaces privés
et de l'éditeur Site (tabs, media picker), contrastes des chips claires, `aria-live` sur les
notices.

## 26. Production readiness

**BLOQUANT AVANT MISE EN LIGNE**
- E-mail : transport production configuré (§14/§28), vérification e-mail **activée**, templates
  vérification/reset intégrés, SPF/DKIM/DMARC.
- Sécurité : rate limiting `/auth` + lost-password ; `WP_DEBUG=false`, `WP_DEBUG_DISPLAY=false`,
  `display_errors=Off`, `DISALLOW_FILE_EDIT`, XML-RPC désactivé, `wp/v2/users` restreint,
  `readme.html`/listing retirés, en-têtes HSTS/CSP/X-Frame/Referrer-Policy, cookies `Secure`
  `HttpOnly` `SameSite`, `FORCE_SSL_ADMIN`, `WP_ENVIRONMENT_TYPE=production`, sauvegardes
  automatiques BDD + `postelio-private/`.
- Job-sources : correctif §16 + merge I2.
- Site Builder appliqué au front public (I12) — sinon désactiver le menu « Mon site ».
- SEO : canonical, noindex pages privées, sitemap/robots réels, 410.
- RGPD : politique de confidentialité alignée (durées **À VALIDER** dans security.md §6),
  consentement à l'inscription journalisé, export/suppression testés, registre.
- Domaines/HTTPS/CORS : décider front même origine (recommandé) ou GitHub Pages + CORS strict.
- Cron : `DISABLE_WP_CRON` + cron système (workers 15 min, sync horaire).
- Monitoring/logs : alerting sur `deliveries.failed`, `sync_failed`, `fulfillment failed`,
  erreurs PHP ; rotation logs.

**IMPORTANT AVANT MISE EN LIGNE** — Stripe test bout en bout puis live + facture légale (si
renouvellement payant à l'ouverture, sinon désactiver le checkout), correctifs P2 backend (titre
vide, présélection, cascade suspension, 403→404), coordonnées hors listes, seed reproductible,
tests back-office, polish back-office, E2E des 4 parcours, accessibilité clavier, performance
(bundle, webm).

**PEUT ATTENDRE** — notation/avis savoir-faire, antivirus CV, 2FA admin, Stripe Tax, portail
client, PWA, bundling avancé.

## 27. Technical debt

`data/*.json` et comptes démo `config.js` (à retirer avec I3/I4) ; `SS.*` espaces privés en
localStorage ; smokes non isolés (résidus, dépendance à l'état de la base) ; harness de test sans
WP-CLI (script maison par session → à versionner dans `tools/`) ; docs : README palette obsolète,
`security.md` durées À VALIDER, `api-contract.md` §3 mentionne JWT/Application Passwords (obsolète) ;
historique `main`/`develop` divergent ; templates e-mail texte brut ; `notifications_flush`
multiples ; `postelio_admin` sans accès aux réglages WP (voulu, à documenter) ; `FileScanner` no-op.

## 28. Recommended roadmap

| Priorité | Chantier | Pourquoi | Risque | Effort | Action suivante |
|---|---|---|---|---|---|
| **P0** | Corriger job-sources (allowlist sources + nettoyage smoke alerts + purge) | Bloque I2, fausse la CI, propagé aux alertes | Faible | 2 h | Branche `fix/job-sources-source-allowlist` depuis develop, smoke 47/47, rejouer I2 en navigateur |
| **P0** | Mailpit local + test des 10 flux e-mail | Aucune preuve d'envoi ; vérification de compte impossible | Nul | 30 min install + 2 h tests | Installer Mailpit (`127.0.0.1:1025`, UI `:8025`), `SMTP=127.0.0.1`/`smtp_port=1025` dans le php.ini d'Apache, redémarrer, « Envoyer un e-mail de test », rejouer inscription/reset/candidature/entretien |
| **P0** | Intégrer vérification/reset dans postelio-notifications + activer `require_email_verification` | D12 inopérante, e-mails hors observabilité | Moyen (bloque l'inscription si e-mail HS) | 1 j | Après Mailpit : templates `account_verify`, `password_reset`, `welcome` ; filtre ON en local pour tester |
| **P1** | Rate limiting `/auth` (IP + compte, 429 + verrou temporaire, audit) | Brute force libre | Faible | 1 j | Implémenter D5 dans postelio-users (transients/table), tests |
| **P1** | Stripe TEST réel | Jamais testé hors Fake | Moyen | 1 j | Clés test + Stripe CLI, dérouler les 12 cas §15, décider facture légale |
| **P1** | Site Builder → front public (I12) | Le builder n'a aucun effet réel | Moyen | 3–4 j | Charger `/site/config` au boot du front (cache), appliquer identité/nav/footer/sections/SEO, favicon dynamique |
| **P1** | SEO front | Fiches non indexables, sitemap faux | Faible | 2 j | canonical/noindex/OG dynamiques, sitemap généré par WP (`in_sitemap`), robots réel, 410 |
| **P1** | Backend I2 : filtres `/companies` (q, ville, secteur) + `company` sur `/jobs` | Fiche entreprise sans offres | Faible | 0,5 j | `CompanyRepository::search` + `FilterValidator` company |
| **P1** | Durcissement WordPress prod (§26) | Exposition prod | Faible | 0,5 j | `wp-config` prod + mu-plugin sécurité (headers, xmlrpc, users) |
| **P1** | Coordonnées entretien hors listes + 403→404 | Contrat privacy | Faible | 0,5 j | Presenter `list_view` sans `*_data` ; `not_found` dans les gardes ownership |
| **P1** | Backoffice Polish (§22) | Niveau de finition insuffisant | Faible | 4–6 j | Valider la proposition, puis Phase 4 |
| **P2** | Titre vide, présélection obligatoire, cascade suspension réversible, brouillon si suspendue, rate-limit messagerie | Robustesse métier | Faible | 1 j | Un commit par point avec test |
| **P2** | Seed reproductible + isolation des smokes + harness versionné | Fiabilité des tests | Faible | 1 j | `tools/seed-local.php`, `tools/run-smokes.sh`, nettoyage alerts |
| **P2** | Tests back-office (rendu écrans, actions admin-post, privacy) | 0 test sur la couche la plus exposée | Faible | 1 j | Smoke backoffice |
| **P2** | Templates e-mail HTML + `skill.comment_created` | Qualité perçue | Faible | 1 j | Layout unique + variables |
| **P3** | Perf (bundle, webm/poster), a11y clavier, docs obsolètes, `notifications_flush` dédup, message upload 8M | Confort | Nul | 1–2 j | Au fil de l'eau |

### CE QUE NOUS DEVONS FAIRE AUJOURD'HUI

1. **Corriger job-sources** (allowlist des sources enregistrées et disponibles dans
   `search_public`, nettoyage du smoke alerts, purge de la ligne `smoke_src`) — smoke 47/47, puis
   revalider I2 en navigateur (liste → détail cohérents) et **seulement ensuite** ouvrir la
   question du merge d'I2.
2. **Installer Mailpit et prouver l'envoi** : e-mail de test admin, inscription, vérification,
   reset, candidature, entretien, alerte — lire les 26 livraisons en attente dans Mailpit.
3. **Brancher vérification/reset/bienvenue sur postelio-notifications** et activer
   `require_email_verification` en local pour tester le vrai parcours (D12).
4. **Rate limiting `/auth`** (D5) — petit, isolé, indispensable avant toute exposition.
5. **Valider la proposition Backoffice Polish (§22)** et cadrer I12 (Site Builder → front) : ce
   sont les deux chantiers produit suivants ; ne pas les coder aujourd'hui.

Stripe TEST réel, SEO et durcissement prod suivent immédiatement (cette semaine), une fois les
e-mails réels en place.

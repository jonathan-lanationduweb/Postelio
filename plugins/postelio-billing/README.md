# Postelio Billing (Lot 12)

Renouvellement d'offre **payant** (10 €/30 j) via **Stripe Checkout hosted**. Le domaine
**paie** puis **délègue** l'effet métier au contrat `JobLifecycle` de postelio-jobs — il
n'écrit jamais `pst_status`/`pst_date_expiration`. Le **webhook signé est la seule source de
vérité** (le retour navigateur ne confirme jamais un paiement). **Aucune dépendance Composer**
(client HTTP Stripe léger via `wp_remote_*`, comme le Lot 10). **Aucune donnée carte** ne
transite par Postelio (PCI SAQ-A). **Aucun envoi d'e-mail** : événements → Notifications.

## Architecture
```
POST /billing/checkout ─► OrderService
   auth + pst_pay_renewal + pst_email_verified + is_active
   + membre de l'entreprise DU job + entreprise verified & non suspended
   + JobLifecycle::can_renew()
   → snapshot (prix = catalogue, JAMAIS le front) → BillingOrder (awaiting_payment)
   → Stripe Customer LAZY (par entreprise) → Checkout Session (idempotency = order_uuid)
   → { order_uuid, checkout_url }

Stripe ─► POST /billing/webhook/stripe   (public, signé, sans session)
   ProviderRegistry->verify_webhook(raw, signature)   (HMAC + tolérance ; sinon 400)
   → WebhookProcessor : event store idempotent → Payment upsert → Order paid
   → payment.succeeded → FulfillmentService

FulfillmentService (exactly-once)
   revalide suspension (post-paiement) → sinon manual_review
   JobLifecycle::renew_after_payment(job, 30, idempotency_key = order_uuid)
   → Order fulfilled → renewal.applied     (retry borné via Core Scheduler /15 min)

Notifications écoute l'événement PROPRIÉTAIRE `job.renewed` (émis par Jobs) → « offre
renouvelée jusqu'au … ». Billing ne duplique jamais cette notification ni le reçu Stripe.
```

## Trois tables (EXACTEMENT ; aucune table invoice V1)
`wp_postelio_billing_orders` · `wp_postelio_billing_payments` · `wp_postelio_billing_events`.
Montants **en centimes (INTEGER)**, jamais de FLOAT. Migration idempotente
(`CREATE TABLE IF NOT EXISTS` + dédent), index composites en préfixe (limite 1000 o).
Désactivation **non destructive** (obligations comptables). UUID publics exposés, jamais d'ID
SQL ni de secret provider.

- **orders** : intention métier + snapshot figé. `idempotency_key` UNIQUE (= order_uuid),
  `provider_session_id`, `provider_customer_id`, `fulfillment_status`/`fulfillment_attempts`.
- **payments** : transaction financière (≥1 par ordre). UNIQUE `provider_session_id` /
  `provider_payment_intent_id` (idempotence provider).
- **events** : store provider (idempotence + reprise + observabilité). `UNIQUE(provider,event_id)`,
  statuts `received|processed|ignored|error`. **Pas** de payload Stripe brut permanent.

## Machines à états (serveur uniquement — le front ne fixe rien)
- **Order** : `created → awaiting_payment → paid → fulfillment_pending → fulfilled` ;
  branches `payment_failed`, `expired`, `fulfillment_failed` (retry), `manual_review`, `refunded`.
- **Payment** : `created → pending → succeeded | failed` ; `succeeded → refunded | disputed` ;
  `duplicate` (2e paiement d'un ordre déjà fulfilé → revue admin, jamais de 2e renouvellement).

## Exactly-once (point critique)
Le renouvellement est piloté par une **clé d'idempotence = `order_uuid`** passée à
`JobLifecycle::renew_after_payment($job_id, $days, ['idempotency_key' => order_uuid])`
(extension **additive** de postelio-jobs). Côté Jobs, un **registre** (`pst_renewal_ledger`)
stocke la **cible figée** du renouvellement ; l'application est un **SET ABSOLU** (jamais
`++`/`+=`). Le registre est écrit **avant** le SET. Conséquence : un rejeu de webhook, un retry
de fulfillment, ou un **crash entre l'application côté Jobs et la persistance côté Billing**
convergent vers la même valeur → **une seule extension, un seul `renewal_count++`, un seul
`job.renewed`**. Le calcul d'échéance (`max(exp, today) + days`) reste géré par Jobs.

## Catalogue & TVA
Catalogue **en code** (`ProductCatalog`), source d'autorité tarifaire. `job_renewal` =
`unit_amount 1000` (10 € TTC), `currency EUR`, `duration_days 30`. Le front n'envoie que
`product_code`/`resource_type`/`resource_uuid` — **jamais** montant/devise/durée/taxe (ignorés).
TVA **configurable** (`tax_mode`/`tax_rate` via filtres, défaut FR TTC 20 %) ; décomposition
HT/TVA/TTC figée dans le snapshot. **Valeur fiscale réelle À VALIDER** (comptable).

## Snapshot (figé à l'achat)
Produit + **acheteur** (identité légale vérifiée via `CompanyBilling::identity` : raison
sociale, SIREN/SIRET, TVA, adresse siège, e-mail de facturation) + **vendeur**
(`SellerConfig`). Immuable ensuite : une modif d'entreprise, du prix ou du catalogue n'altère
jamais l'historique financier.

## Reçu vs facture
V1 expose le **reçu Stripe** (`receipt_url`) comme justificatif. On **n'appelle pas
« facture »** un document qui ne remplit pas les obligations légales. La **facture légale
numérotée** est une phase suivante, conditionnée à l'identité **vendeur** réelle
(`SellerConfig` — valeurs À FOURNIR ; aucune valeur inventée), à la TVA et à la stratégie de
numérotation. `GET /billing/health` expose `seller_configured` / `invoice_legal_ready=false`
tant que l'identité vendeur est incomplète.

## Provider
Interface `PaymentProvider` (name/mode/is_configured/create_customer/create_checkout/
verify_webhook/refund/health). `StripePaymentProvider` (Checkout hosted, `wp_remote_*`,
vérification de signature `StripeSignature` — HMAC-SHA256 `t.payload` + tolérance).
`FakePaymentProvider` pour tests/smoke. Résolution au point d'usage via `ProviderRegistry`
(filtre `postelio/billing/provider`) → aucun code métier ne dépend directement de Stripe.
Détection **test/live** (`sk_test_`/`sk_live_`) ; incohérence ⇒ health `degraded`, pas de
checkout live.

## Événements Stripe V1
`checkout.session.completed`, `.async_payment_succeeded`, `.async_payment_failed`,
`.expired`, `charge.refunded`, `charge.dispute.created`. `payment_intent.*` / `invoice.*`
reçus → **ignorés** (pas de double pipeline). Remboursement → `payment.refunded` **sans
rollback** des jours du Job. Litige → `payment.disputed` **sans** suspension automatique.

## API
- `POST /billing/checkout` (`pst_pay_renewal` + `pst_email_verified`) → `{ order_uuid, checkout_url, expires_at }`.
- `GET /billing/orders` (paginé, scope entreprise) — historique dashboard.
- `GET /billing/orders/{uuid}` — statut synthétique (status / payment_status / fulfillment_status / reçu). Cross-company → 404.
- `POST /billing/webhook/stripe` — public **signé** (idempotent).
- `GET /billing/admin/orders[/{uuid}]`, `POST /billing/admin/orders/{uuid}/retry-fulfillment`, `GET /billing/health` — `pst_manage_billing`.

## Événements Postelio émis
`order.created`, `checkout.created`, `payment.succeeded`, `payment.failed`,
`payment.refunded`, `payment.disputed`, `renewal.applied`, `fulfillment.failed`,
`order.manual_review`. Nommage **par agrégat** (cohérent avec `job.*`/`external_job.*`),
pas de préfixe `billing.*`. Billing **n'écoute PAS `job.expiring`** (l'achat est initié par
l'utilisateur).

## Sécurité / PCI / Secrets
Checkout hosted → aucune donnée carte chez Postelio. Secrets `POSTELIO_STRIPE_SECRET_KEY` /
`POSTELIO_STRIPE_WEBHOOK_SECRET` (+ identité vendeur `POSTELIO_SELLER_*`) en env/wp-config,
**jamais** en base/Git/logs/REST/Audit. Publishable key non requise (redirection `session.url`).
Non-divulgation (404) hors entreprise. Logs structurés : `order_uuid, payment_uuid, event_id, status`.

## Contrats additifs (non destructifs)
- `postelio-jobs` : `JobLifecycle::renew_after_payment` (support `idempotency_key`, exactly-once
  via registre) ; `JobRepository::apply_renewal_idempotent` + `renewal_ledger` (SET absolu) ;
  `JobDirectory::company_id_of`.
- `postelio-companies` : `CompanyBilling::identity` (identité de facturation acheteur, lecture seule).
- `postelio-notifications` : écoute `job.renewed` → notification recruteur + e-mail `job_renewed`.

## Tests
- `tests/run-unit.php` (44, sans WP/réseau) : ProductCatalog (prix/TVA entiers), Order/Payment
  state machines, StripeSignature (HMAC + tolérance + mode), SellerConfig, BillingSnapshot.
- `tests/smoke.php` (61, WP vivant, FakePaymentProvider) : activation/3 tables ; checkout
  (sécurité, ownership, anti-tampering, double-clic) ; webhook (signature invalide, event
  inconnu, completed→paid→fulfilled, rejeu, expired, async failed, refund, dispute, double
  paiement) ; **exactly-once + fenêtre de crash** ; suspension user/company ; offre non
  renouvelable ; APIs order/history/admin/health ; événements.

## Développement local (Stripe)
Clés de test en `wp-config.php` (`POSTELIO_STRIPE_SECRET_KEY=sk_test_…`,
`POSTELIO_STRIPE_WEBHOOK_SECRET=whsec_…`). Webhook local via **Stripe CLI**
(`stripe listen --forward-to "http://postelio.local/wordpress/?rest_route=/postelio/v1/billing/webhook/stripe"`).
Non installé dans ce lot. Sans clés, la source est *non configurée* (health `degraded`) ; les
tests utilisent exclusivement le `FakePaymentProvider`.

## Décisions FIGÉES (V1)
Stripe Checkout hosted · Order ≠ Payment · 3 tables · catalogue en code · 10 € TTC / 30 j ·
1re publication gratuite · owner + recruteur membre · Stripe Customer lazy par entreprise ·
webhook = source de vérité · exactly-once par registre Jobs · reçu ≠ facture · pas de facture
légale V1 · pas de rollback refund · pas de suspension auto sur dispute · double paiement →
manual_review · front non modifié.

## Points À VALIDER (externes)
Identité légale **vendeur** réelle · règles **TVA**/comptabilité (B2B UE, reverse charge,
Stripe Tax) · numérotation de facture · **rétention comptable** exacte · politique
opérationnelle double paiement · politique avancée chargeback · vraies **clés Stripe**.

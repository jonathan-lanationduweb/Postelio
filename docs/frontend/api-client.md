# Postelio Front — Client API (socle I1)

Implémentation **unique** du transport HTTP vers le backend `postelio/v1`. Aucune page ne doit
refaire de `fetch('/postelio/v1/...')` : tout passe par `window.PostelioAPI`.

## Fichiers
- `assets/js/api/postelio-api.js` → `window.PostelioAPI = { config, ApiError, client }`.

## Configuration (`PostelioAPI.config`)
Résolue par environnement, **surchargeable sans toucher aux pages** via un objet global défini
AVANT le socle :
```html
<script>window.POSTELIO_CONFIG = {
  apiBaseUrl: "https://www.postelio.fr/wp-json/postelio/v1",
  wpBaseUrl:  "https://www.postelio.fr"
};</script>
```
Défaut **local** : `wpBaseUrl = origin + "/wordpress"`, `apiBaseUrl = wpBaseUrl + "/wp-json/postelio/v1"`
→ `http://postelio.local/wordpress/wp-json/postelio/v1` (même origine que le front, pas de CORS).
Aucune clé/secret n'est stocké côté JS.

## Transport d'authentification — Bearer uniquement
`config.credentials = "omit"` : on **n'envoie pas** le cookie WordPress. Sinon WP traite la requête
comme « cookie-authentifiée » et exige un **nonce REST** (absent d'un front statique) → 401 malgré
un Bearer valide. Le jeton Bearer (`Authorization: Bearer <token>`) est le mécanisme réellement
supporté par le backend et compatible **web + future app Tauri**. Voir `auth-integration.md`.

## Client (`PostelioAPI.client`)
`get(path, opts)`, `post`, `put`, `del`, `request(method, path, opts)`.
`opts` : `{ query, body, bearer }`.
- `query` : objet → querystring (les `false`/`null`/`""` sont omis ; `true` → `1`).
- `body` : objet → JSON (`Content-Type: application/json`).
- `bearer` : jeton à envoyer (fourni par la session).
Fonctions : timeout (`AbortController`, 15 s), réponse vide (204) tolérée, enveloppe standard
`{ data, meta }` → renvoie `{ data, meta, status }`. `client.onUnauthorized(fn)` : point
d'extension pour la gestion 401 centralisée (branché par le socle Auth).

## Erreurs (`PostelioAPI.ApiError`)
Reflète l'enveloppe backend `{ error: { code, message, details } }`. Le reste du front n'a jamais à
parser une réponse WordPress brute.
- Propriétés : `status` (HTTP ; `0` = réseau/timeout), `code` (interne stable), `message`, `details`.
- `userMessage()` : message utilisateur simple par statut (401 « session expirée », 429 « trop de
  tentatives », réseau « impossible de contacter », 5xx « problème temporaire »…). Jamais de
  `WP_Error`/stack/code brut à l'écran.
- `firstFieldError()` : `{ field, reason }` pour un message ciblé de formulaire (422).

## Sécurité
Le client ne rend jamais de HTML : les composants n'utilisent que `textContent` pour les données
serveur (aucun `innerHTML` sur des valeurs non maîtrisées).

## Tests
`node tests/front-socle.test.mjs` (23 assertions) : URL/query, Bearer, ApiError 401/422/429/réseau,
mapping de rôles, session, 401 → nettoyage. Vérification bout-en-bout dans le navigateur réel
(register/login/logout/gardes/en-tête) sur `postelio.local`.

# Postelio Front — Intégration de l'authentification (socle I1)

Authentification **réelle** (jeton Bearer + `GET /me`). Remplace l'auth simulée (`ss_session` +
comptes de démo). Les données **métier** restent en localStorage jusqu'aux lots suivants :
**mode hybride** assumé — AUTH = réel, DATA = mock.

## Fichiers
- `assets/js/auth/postelio-auth.js` → `window.PostelioAuth = { tokens, session, guards, ROLE }`.
- `assets/js/auth/postelio-boot.js` → amorçage + pont `SS.auth` réel + bandeau e-mail non vérifié.
- `assets/js/auth.js` → connexion / inscription / mot de passe oublié (formulaires).

## Ordre de chargement (sur les 29 pages)
`config.js → api/postelio-api.js → auth/postelio-auth.js → main.js → auth/postelio-boot.js → navigation.js`.
`boot` charge APRÈS `main.js` car il **remplace** `SS.auth` (défini par `main.js`) par un pont réel.

## Session (`PostelioAuth.session`) — source de vérité unique
- Méthodes : `load()` (valide le jeton via `GET /me`), `login(email,pw)`, `register({email,password,frontRole,displayName})`, `logout()`, `refresh()`, `resendVerification()`, `lostPassword(email)`, `resetPassword(login,key,pw)`.
- Getters : `isAuthenticated()`, `user()`, `frontRole()`, `isCandidate()`, `isEmployer()`, `emailVerified()`, `displayName()`, `initials()`, `homePath()`, `snapshot()` (instantané caché synchrone).
- `session.ready` : promesse résolue après le premier `load()`.
- Évènements : `ss:auth-changed` (à chaque changement), `ss:auth-ready` (après vérification `/me`) — la navigation se ré-affiche dessus.

## Mapping des rôles
Backend `candidate` ↔ front `candidate` ; backend `recruiter` ↔ front `employer`
(`PostelioAuth.ROLE.toFront/toBackend`). Le rôle d'inscription (`candidate|recruiter`) est le SEUL
champ de type de compte transmis à `/auth/register` ; jamais de capability/status/email_verified.

## Jeton (`PostelioAuth.tokens`) — fournisseur abstrait
Web : `localStorage` (`pst_token` + `pst_token_exp`). Instantané de session caché : `pst_auth_user`
(pour un premier rendu sans clignotement ; le jeton + `GET /me` font foi).
**Tauri (futur)** : `PostelioAuth.tokens.use(customProvider)` pour un stockage sécurisé, sans
réécrire la session. Compromis assumé en I1 : jeton en `localStorage` (exposé au XSS) ; l'ensemble
du front n'injecte que du `textContent` pour les données serveur.

## Gardes (`PostelioAuth.guards`)
`requireAuth()`, `requireCandidate()`, `requireRecruiter()`. Décision **optimiste** (instantané
caché) au chargement, puis **vérité** après `GET /me` (le backend reste l'autorité). Les pages
privées portent `data-guard="candidate|employer|auth"` sur `<body>` (12 pages) ; `boot` ré-applique
la garde après vérification (attrape le cas jeton expiré). Redirection sécurisée : `?next=` interne
uniquement (pas d'open redirect).

## Pont de compatibilité `SS.auth`
`boot` réinstalle `SS.auth` en pont vers `session` : `get()`, `isLogged()`, `isCandidate()`,
`isEmployer()`, `displayName()`, `initials()`, `logout(redirect)`, `require(role)`. Les pages
métier existantes (dashboards) continuent de fonctionner en lisant la session **réelle**.

## En-tête / navigation
`assets/js/navigation.js` : anonyme → « Se connecter / Créer un compte » (markup statique restauré) ;
connecté → cloche + avatar + menu court + « Déconnexion ». Ré-affichage sur `ss:auth-changed`.

## E-mail non vérifié
Bandeau non intrusif (backend `/auth/verify-email/resend`). En local l'inscription auto-vérifie
l'e-mail, donc le bandeau n'apparaît pas.

## Gaps (lots ultérieurs)
- Page front de **réinitialisation** de mot de passe (lien e-mail `/reinitialiser-mot-de-passe`) — backend `/auth/reset-password` prêt.
- Page front d'**atterrissage de vérification** e-mail (`uid`+`token`) — backend `/auth/verify-email` prêt.
- Inscription recruteur = compte seul ; création + vérification **entreprise** (Lot 03) → lot I5.

## Tauri (futur, non implémenté)
Bearer déjà en place (pas de dépendance cookie/nonce). Points à traiter : fournisseur de jeton
sécurisé, `POSTELIO_CONFIG.apiBaseUrl` absolu, CORS, ouverture navigateur système pour
apply-redirect / checkout Stripe.

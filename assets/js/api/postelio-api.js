/**
 * Socle API Postelio (I1) — implémentation UNIQUE du client HTTP.
 *
 * Expose `window.PostelioAPI = { config, ApiError, client }`.
 * Aucune page ne doit refaire de `fetch('/postelio/v1/...')` : tout passe par `PostelioAPI.client`.
 *
 * Transport d'authentification : jeton Bearer (contrat réel `postelio-users`). Le front public
 * étant statique (pas de nonce WordPress rendu côté serveur), on utilise l'en-tête
 * `Authorization: Bearer <token>` — mécanisme réellement supporté par le backend et compatible
 * avec la future application Tauri. Aucun secret n'est stocké ici.
 *
 * Sections : (1) config d'environnement · (2) ApiError · (3) client HTTP.
 */
(function () {
  "use strict";

  /* ============================================================= *
   * 1. Configuration d'environnement
   *    Surcharge possible sans toucher 30 fichiers, via un objet
   *    global `window.POSTELIO_CONFIG` (défini avant ce script) :
   *      window.POSTELIO_CONFIG = {
   *        apiBaseUrl:  "https://www.postelio.fr/wp-json/postelio/v1",
   *        wpBaseUrl:   "https://www.postelio.fr",
   *        frontBaseUrl:"https://www.postelio.fr"
   *      };
   *    Défaut LOCAL : WordPress servi sous /wordpress (même origine que le front).
   * ============================================================= */
  var override = (typeof window !== "undefined" && window.POSTELIO_CONFIG) || {};
  var origin = (typeof location !== "undefined" && location.origin) || "";

  var wpBaseUrl = String(override.wpBaseUrl || (origin + "/wordpress")).replace(/\/+$/, "");
  var apiBaseUrl = String(override.apiBaseUrl || (wpBaseUrl + "/wp-json/postelio/v1")).replace(/\/+$/, "");
  var frontBaseUrl = String(override.frontBaseUrl || origin).replace(/\/+$/, "");

  var config = {
    apiBaseUrl: apiBaseUrl,
    wpBaseUrl: wpBaseUrl,
    frontBaseUrl: frontBaseUrl,
    /* Délai maximal d'une requête (ms). */
    timeout: Number(override.timeout || 15000),
    /* Authentification par jeton Bearer UNIQUEMENT : on N'ENVOIE PAS le cookie WordPress.
       Sinon WordPress traite la requête comme « cookie-authentifiée » et EXIGE un nonce REST
       (absent d'un front statique) → 401 malgré un Bearer valide. `omit` = Bearer seul, cohérent
       web + future app Tauri. Surchargeable via window.POSTELIO_CONFIG.credentials si besoin. */
    credentials: override.credentials || "omit"
  };

  /* ============================================================= *
   * 2. Erreur applicative structurée
   *    Reflète l'enveloppe backend { error: { code, message, details } }.
   *    Le reste du front n'a jamais à parser une réponse WordPress brute.
   * ============================================================= */
  function ApiError(status, code, message, details) {
    this.name = "ApiError";
    this.status = status | 0;                 // code HTTP (0 = réseau/timeout)
    this.code = code || "error";              // code interne stable (ex. "unauthenticated")
    this.message = message || "Une erreur est survenue.";
    this.details = details || null;           // { champ: raison } éventuel
  }
  ApiError.prototype = Object.create(Error.prototype);
  ApiError.prototype.constructor = ApiError;

  /* Messages utilisateur simples (jamais de code interne brut / stack). */
  ApiError.prototype.userMessage = function () {
    switch (this.status) {
      case 0:   return "Impossible de contacter Postelio. Vérifiez votre connexion.";
      case 401: return "Votre session a expiré. Reconnectez-vous.";
      case 403: return this.message || "Vous n'avez pas accès à cette ressource.";
      case 404: return this.message || "Ressource introuvable.";
      case 409: return this.message || "Cette action entre en conflit avec l'état actuel.";
      case 410: return this.message || "Cette ressource n'est plus disponible.";
      case 422: return this.message || "Certaines informations sont invalides.";
      case 429: return "Trop de tentatives. Réessayez dans quelques instants.";
      default:
        if (this.status >= 500) { return "Postelio rencontre un problème temporaire. Réessayez plus tard."; }
        return this.message || "Une erreur est survenue.";
    }
  };

  /* Première erreur de champ (pour un focus / message ciblé de formulaire). */
  ApiError.prototype.firstFieldError = function () {
    if (this.details && typeof this.details === "object") {
      for (var k in this.details) {
        if (Object.prototype.hasOwnProperty.call(this.details, k)) {
          return { field: k, reason: this.details[k] };
        }
      }
    }
    return null;
  };

  /* ============================================================= *
   * 3. Client HTTP unique
   * ============================================================= */
  var unauthorizedHandler = null; // installé par le socle Auth (401 centralisé)

  function buildUrl(path, query) {
    var url = /^https?:\/\//i.test(path) ? path : (config.apiBaseUrl + (path.charAt(0) === "/" ? path : "/" + path));
    if (query && typeof query === "object") {
      var parts = [];
      for (var k in query) {
        if (!Object.prototype.hasOwnProperty.call(query, k)) { continue; }
        var v = query[k];
        if (v === undefined || v === null || v === "") { continue; }
        if (v === true) { v = "1"; }
        if (v === false) { continue; }
        parts.push(encodeURIComponent(k) + "=" + encodeURIComponent(String(v)));
      }
      if (parts.length) { url += (url.indexOf("?") === -1 ? "?" : "&") + parts.join("&"); }
    }
    return url;
  }

  function request(method, path, options) {
    options = options || {};
    var url = buildUrl(path, options.query);
    var headers = { "Accept": "application/json" };
    var token = options.bearer;

    if (token) { headers["Authorization"] = "Bearer " + token; }

    var init = {
      method: method,
      headers: headers,
      credentials: config.credentials
    };
    if (options.body !== undefined && options.body !== null) {
      headers["Content-Type"] = "application/json";
      init.body = JSON.stringify(options.body);
    }

    /* Timeout via AbortController. */
    var controller = (typeof AbortController !== "undefined") ? new AbortController() : null;
    var timer = null;
    if (controller) {
      init.signal = controller.signal;
      timer = setTimeout(function () { controller.abort(); }, config.timeout);
    }

    return fetch(url, init).then(function (res) {
      if (timer) { clearTimeout(timer); }
      return res.text().then(function (text) {
        var payload = null;
        if (text) { try { payload = JSON.parse(text); } catch (e) { payload = null; } }

        if (res.ok) {
          /* Réponse vide (204) tolérée. Enveloppe standard { data, meta } → renvoie data. */
          if (payload && Object.prototype.hasOwnProperty.call(payload, "data")) {
            return { data: payload.data, meta: payload.meta || null, status: res.status };
          }
          return { data: payload, meta: null, status: res.status };
        }

        /* Erreur : mappe l'enveloppe { error: { code, message, details } }. */
        var err = payload && payload.error ? payload.error : {};
        var apiError = new ApiError(
          res.status,
          err.code || ("http_" + res.status),
          err.message || null,
          (err.details && typeof err.details === "object") ? err.details : null
        );
        if (res.status === 401 && typeof unauthorizedHandler === "function") {
          try { unauthorizedHandler(apiError, path); } catch (e) { /* ignoré */ }
        }
        throw apiError;
      });
    }, function (networkErr) {
      if (timer) { clearTimeout(timer); }
      /* Abort (timeout) ou erreur réseau → status 0. */
      var aborted = networkErr && networkErr.name === "AbortError";
      throw new ApiError(0, aborted ? "timeout" : "network_error",
        aborted ? "Délai dépassé." : "Erreur réseau.");
    });
  }

  var client = {
    request: request,
    get: function (path, options) { return request("GET", path, options); },
    post: function (path, options) { return request("POST", path, options); },
    put: function (path, options) { return request("PUT", path, options); },
    del: function (path, options) { return request("DELETE", path, options); },
    /* Point d'extension : le socle Auth y branche la gestion 401 centralisée. */
    onUnauthorized: function (fn) { unauthorizedHandler = fn; }
  };

  window.PostelioAPI = { config: config, ApiError: ApiError, client: client };
})();

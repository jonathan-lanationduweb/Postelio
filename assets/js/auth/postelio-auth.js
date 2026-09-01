/**
 * Socle Auth Postelio (I1) — session utilisateur RÉELLE (source de vérité unique).
 *
 * Expose `window.PostelioAuth = { tokens, session, guards, ROLE }`.
 * Remplace l'authentification simulée (`SS.auth` + `ss_session`). Les données MÉTIER restent en
 * localStorage jusqu'aux lots suivants (mode hybride assumé : AUTH = réel, DATA = mock).
 *
 * Transport : jeton Bearer. Fournisseur de jeton ABSTRAIT (`tokens`) pour permettre plus tard un
 * stockage sécurisé côté Tauri sans réécrire la session.
 */
(function () {
  "use strict";

  var API = window.PostelioAPI;
  if (!API) { throw new Error("PostelioAPI requis avant PostelioAuth."); }

  /* Mapping des rôles : backend (candidate|recruiter) ↔ front (candidate|employer). */
  var ROLE = {
    toFront: function (backendRole) {
      if (backendRole === "recruiter") { return "employer"; }
      if (backendRole === "candidate") { return "candidate"; }
      return backendRole || null; // admin/moderator/support : non ciblés par le front public
    },
    toBackend: function (frontRole) {
      if (frontRole === "employer") { return "recruiter"; }
      return "candidate";
    }
  };

  /* ============================================================= *
   * Fournisseur de jeton (extensible). Web : localStorage.
   *   TODO Tauri : implémenter un provider adossé au stockage sécurisé et
   *   l'injecter via PostelioAuth.tokens.use(customProvider).
   * ============================================================= */
  var TOKEN_KEY = "pst_token";
  var TOKEN_EXP_KEY = "pst_token_exp";

  var webProvider = {
    get: function () {
      try {
        var t = localStorage.getItem(TOKEN_KEY);
        return t || null;
      } catch (e) { return null; }
    },
    getExpiry: function () {
      try { return Number(localStorage.getItem(TOKEN_EXP_KEY) || 0) || 0; } catch (e) { return 0; }
    },
    set: function (token, expiresAt) {
      try {
        localStorage.setItem(TOKEN_KEY, token);
        localStorage.setItem(TOKEN_EXP_KEY, String(expiresAt || 0));
      } catch (e) { /* stockage indisponible */ }
    },
    clear: function () {
      try { localStorage.removeItem(TOKEN_KEY); localStorage.removeItem(TOKEN_EXP_KEY); } catch (e) { /* */ }
    }
  };

  var activeProvider = webProvider;
  var tokens = {
    use: function (provider) { activeProvider = provider || webProvider; },
    get: function () { return activeProvider.get(); },
    set: function (token, expiresAt) { activeProvider.set(token, expiresAt); },
    clear: function () { activeProvider.clear(); },
    isExpired: function () {
      var exp = activeProvider.getExpiry ? activeProvider.getExpiry() : 0;
      return exp > 0 && (Date.now() / 1000) >= exp;
    }
  };

  /* ============================================================= *
   * Session : source de vérité unique. Instantané mis en cache pour un
   * rendu synchrone au boot ; le jeton + GET /me font foi.
   * ============================================================= */
  var SNAP_KEY = "pst_auth_user";
  var currentUser = null; // objet /me backend enrichi
  var loaded = false;

  function readSnapshot() {
    try {
      var raw = localStorage.getItem(SNAP_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch (e) { return null; }
  }
  function writeSnapshot(user) {
    try {
      if (user) { localStorage.setItem(SNAP_KEY, JSON.stringify(user)); }
      else { localStorage.removeItem(SNAP_KEY); }
    } catch (e) { /* */ }
  }

  function emitChanged() {
    try { window.dispatchEvent(new CustomEvent("ss:auth-changed", { detail: publicUser() })); } catch (e) { /* */ }
  }

  function setUser(user) {
    currentUser = user || null;
    writeSnapshot(currentUser ? publicUser() : null);
    emitChanged();
  }

  /* Vue publique minimale (I1) : ce dont la nav / les gardes / l'affichage ont besoin. */
  function publicUser() {
    var u = currentUser || readSnapshot();
    if (!u) { return null; }
    var backendRole = u.role || (u.roles && u.roles[0]) || null;
    return {
      id: u.id || 0,
      email: u.email || "",
      display_name: u.display_name || "",
      role: backendRole,
      front_role: ROLE.toFront(backendRole),
      email_verified: !!u.email_verified,
      status: u.status || "active",
      has_profile: !!u.has_profile
    };
  }

  function initials(name) {
    name = String(name || "").trim();
    if (!name) { return "?"; }
    var parts = name.split(/\s+/);
    if (parts.length >= 2) { return (parts[0][0] + parts[1][0]).toUpperCase(); }
    return parts[0][0].toUpperCase();
  }

  function homePath(frontRole) {
    return frontRole === "employer" ? "espace-entreprise.html" : "espace-candidat.html";
  }

  var readyResolve;
  var readyPromise = new Promise(function (r) { readyResolve = r; });

  var session = {
    ready: readyPromise,

    /* Instantané synchrone (cache) — pour un premier rendu sans flash. */
    snapshot: function () { return publicUser(); },

    isAuthenticated: function () { return !!publicUser(); },
    user: function () { return publicUser(); },
    frontRole: function () { var u = publicUser(); return u ? u.front_role : null; },
    isCandidate: function () { return this.frontRole() === "candidate"; },
    isEmployer: function () { return this.frontRole() === "employer"; },
    emailVerified: function () { var u = publicUser(); return !!(u && u.email_verified); },
    displayName: function () { var u = publicUser(); return u ? (u.display_name || u.email || "") : ""; },
    initials: function () { return initials(this.displayName()); },
    homePath: function () { return homePath(this.frontRole()); },

    /* Charge la session réelle : valide le jeton via GET /me. Idempotent. */
    load: function () {
      var token = tokens.get();
      if (!token) { setUser(null); loaded = true; readyResolve(publicUser()); return Promise.resolve(null); }
      return API.client.get("/me", { bearer: token }).then(function (res) {
        setUser(res.data);
        loaded = true; readyResolve(publicUser());
        return publicUser();
      }, function (err) {
        /* Jeton invalide/expiré → session anonyme (pas de boucle). */
        if (err && err.status === 401) { tokens.clear(); setUser(null); }
        loaded = true; readyResolve(publicUser());
        return null;
      });
    },

    isLoaded: function () { return loaded; },

    login: function (email, password) {
      return API.client.post("/auth", { body: { email: email, password: password } }).then(function (res) {
        var d = res.data || {};
        if (d.token) { tokens.set(d.token, d.expires_at); }
        setUser(d.user);
        return publicUser();
      });
    },

    /* frontRole ∈ {candidate, employer}. */
    register: function (payload) {
      var body = {
        email: payload.email,
        password: payload.password,
        role: ROLE.toBackend(payload.frontRole),
        display_name: payload.displayName || undefined
      };
      return API.client.post("/auth/register", { body: body }).then(function (res) {
        var d = res.data || {};
        if (d.token) { tokens.set(d.token, d.expires_at); }
        setUser(d.user);
        return publicUser();
      });
    },

    refresh: function () {
      var token = tokens.get();
      if (!token) { return Promise.resolve(null); }
      return API.client.post("/auth/refresh", { bearer: token }).then(function (res) {
        var d = res.data || {};
        if (d.token) { tokens.set(d.token, d.expires_at); }
        return d.token || null;
      });
    },

    logout: function () {
      var token = tokens.get();
      var done = function () {
        tokens.clear();
        setUser(null);
        try { localStorage.removeItem("ss_session"); } catch (e) { /* legacy */ }
      };
      var p = token
        ? API.client.post("/auth/logout", { bearer: token }).then(done, done)
        : Promise.resolve().then(done);
      return p;
    },

    resendVerification: function () {
      var token = tokens.get();
      if (!token) { return Promise.reject(new API.ApiError(401, "unauthenticated", "Session requise.")); }
      return API.client.post("/auth/verify-email/resend", { bearer: token }).then(function (res) { return res.data; });
    },

    lostPassword: function (email) {
      return API.client.post("/auth/lost-password", { body: { email: email } }).then(function (res) { return res.data; });
    },

    resetPassword: function (login, key, password) {
      return API.client.post("/auth/reset-password", { body: { login: login, key: key, password: password } }).then(function (res) { return res.data; });
    }
  };

  /* 401 centralisé : une seule invalidation, pas 5 redirections parallèles. */
  var handling401 = false;
  API.client.onUnauthorized(function () {
    if (handling401) { return; }
    handling401 = true;
    tokens.clear();
    setUser(null);
    setTimeout(function () { handling401 = false; }, 1500);
  });

  /* ============================================================= *
   * Gardes d'accès (backend reste autorité ; ceci n'est qu'UX).
   * Utiliser après session.ready pour une décision fiable ; l'instantané
   * cache permet une décision optimiste immédiate.
   * ============================================================= */
  function internalNext() {
    try {
      var params = new URLSearchParams(location.search);
      var next = params.get("next");
      if (next && /^\/[^/]/.test(next) && !/^\/\//.test(next)) { return next; } // interne uniquement
      if (next && /^[a-z0-9\-]+\.html([?#].*)?$/i.test(next)) { return next; }
    } catch (e) { /* */ }
    return null;
  }

  var guards = {
    internalNext: internalNext,
    requireAuth: function () {
      var u = session.snapshot();
      if (!u) {
        var here = location.pathname.split("/").pop() || "";
        location.href = "connexion.html" + (here ? ("?next=" + encodeURIComponent(here + location.search)) : "");
        return false;
      }
      return true;
    },
    requireCandidate: function () {
      var u = session.snapshot();
      if (!u) { return this.requireAuth(); }
      if (u.front_role !== "candidate") { location.href = homePath(u.front_role); return false; }
      return true;
    },
    requireRecruiter: function () {
      var u = session.snapshot();
      if (!u) { return this.requireAuth(); }
      if (u.front_role !== "employer") { location.href = homePath(u.front_role); return false; }
      return true;
    }
  };

  window.PostelioAuth = { tokens: tokens, session: session, guards: guards, ROLE: ROLE };
})();

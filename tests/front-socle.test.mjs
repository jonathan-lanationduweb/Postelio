/**
 * Tests unitaires déterministes du socle front (I1) — sans framework, node pur.
 *
 *   node tests/front-socle.test.mjs
 *
 * Charge assets/js/api/postelio-api.js + assets/js/auth/postelio-auth.js dans un contexte VM
 * muni de shims minimaux (window, localStorage, fetch mocké, location, CustomEvent). Couvre :
 * résolution d'URL + query params, parsing ApiError (401/422/429/réseau), mapping des rôles,
 * état de session (login/role-guard), gestion 401 (jeton effacé).
 */
import fs from "node:fs";
import vm from "node:vm";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
let tests = 0, failed = [];
function check(label, cond) { tests++; console.log((cond ? "  [ok]   " : "  [FAIL] ") + label); if (!cond) failed.push(label); }

/* ---- Shims ---- */
const store = {};
const localStorage = {
  getItem: (k) => (k in store ? store[k] : null),
  setItem: (k, v) => { store[k] = String(v); },
  removeItem: (k) => { delete store[k]; }
};
let fetchImpl = async () => { throw new Error("fetch non mocké"); };
function makeResponse(status, bodyObj) {
  return { ok: status >= 200 && status < 300, status, text: async () => (bodyObj === undefined ? "" : JSON.stringify(bodyObj)) };
}
const win = {};
const sandbox = {
  window: win, localStorage,
  location: { origin: "http://test.local", search: "" },
  fetch: (...a) => fetchImpl(...a),
  setTimeout, clearTimeout,
  AbortController, URLSearchParams,
  CustomEvent: class { constructor(t, o) { this.type = t; this.detail = o && o.detail; } },
  console, Promise, JSON, Object, Array, Date, Error, String, Number, encodeURIComponent
};
win.dispatchEvent = () => {};
win.addEventListener = () => {};
vm.createContext(sandbox);

function load(rel) { vm.runInContext(fs.readFileSync(path.join(root, rel), "utf8"), sandbox, { filename: rel }); }
load("assets/js/api/postelio-api.js");
load("assets/js/auth/postelio-auth.js");
const API = win.PostelioAPI, Auth = win.PostelioAuth;

/* ---- 1. Config / URL / query ---- */
console.log("== Config & URL ==");
check("apiBaseUrl dérivé de l'origine", API.config.apiBaseUrl === "http://test.local/wordpress/wp-json/postelio/v1");
check("credentials = omit (Bearer seul)", API.config.credentials === "omit");

let capturedUrl = null, capturedInit = null;
fetchImpl = async (url, init) => { capturedUrl = url; capturedInit = init; return makeResponse(200, { data: { ok: 1 } }); };
await API.client.get("/jobs", { query: { q: "dev", page: 2, flag: true, skip: false, empty: "", nul: null } });
check("query : q & page inclus", /[?&]q=dev(&|$)/.test(capturedUrl) && /[?&]page=2(&|$)/.test(capturedUrl));
check("query : booléen true → 1", /[?&]flag=1(&|$)/.test(capturedUrl));
check("query : false/empty/null exclus", !/skip=/.test(capturedUrl) && !/empty=/.test(capturedUrl) && !/nul=/.test(capturedUrl));
await API.client.get("/me", { bearer: "abc" });
check("Bearer envoyé dans l'en-tête", capturedInit.headers.Authorization === "Bearer abc");
check("pas de cookie (credentials omit)", capturedInit.credentials === "omit");

/* ---- 2. Enveloppe & ApiError ---- */
console.log("== ApiError ==");
fetchImpl = async () => makeResponse(422, { error: { code: "validation_error", message: "Invalide", details: { email: "Requis" } } });
let e422 = null;
try { await API.client.post("/auth", { body: {} }); } catch (e) { e422 = e; }
check("422 → ApiError.status", e422 && e422.status === 422);
check("422 → details + firstFieldError", e422 && e422.firstFieldError().field === "email");
check("401 → message session expirée", new API.ApiError(401, "unauthenticated").userMessage() === "Votre session a expiré. Reconnectez-vous.");
check("429 → message anti-spam", /Trop de tentatives/.test(new API.ApiError(429, "rate_limited").userMessage()));
check("réseau (status 0) → message contact", /Impossible de contacter/.test(new API.ApiError(0, "network_error").userMessage()));
check("5xx → message temporaire", /problème temporaire/.test(new API.ApiError(503, "server_error").userMessage()));

/* ---- 3. Mapping des rôles ---- */
console.log("== Rôles ==");
check("backend recruiter → front employer", Auth.ROLE.toFront("recruiter") === "employer");
check("backend candidate → front candidate", Auth.ROLE.toFront("candidate") === "candidate");
check("front employer → backend recruiter", Auth.ROLE.toBackend("employer") === "recruiter");
check("front défaut → candidate", Auth.ROLE.toBackend("xxx") === "candidate");

/* ---- 4. Session : login / rôle / guard ---- */
console.log("== Session ==");
fetchImpl = async (url) => {
  if (/\/auth$/.test(url)) return makeResponse(200, { data: { user: { id: 5, email: "r@x.fr", display_name: "R", role: "recruiter", email_verified: true, status: "active" }, token: "5.tid.sec", expires_at: 9999999999 } });
  return makeResponse(200, { data: {} });
};
const u = await Auth.session.login("r@x.fr", "pw");
check("login → front_role employer", u.front_role === "employer");
check("login → isEmployer()", Auth.session.isEmployer() === true && Auth.session.isCandidate() === false);
check("login → jeton stocké", Auth.tokens.get() === "5.tid.sec");
check("guard optimiste employer OK (snapshot)", Auth.session.snapshot().front_role === "employer");

/* ---- 5. 401 → session effacée ---- */
console.log("== 401 → nettoyage ==");
fetchImpl = async () => makeResponse(401, { error: { code: "unauthenticated", message: "x" } });
await Auth.session.load(); // GET /me → 401
check("401 sur /me → jeton effacé", Auth.tokens.get() === null);
check("401 sur /me → session anonyme", Auth.session.isAuthenticated() === false);

/* ---- Bilan ---- */
console.log("");
if (!failed.length) { console.log(`TOUS LES TESTS PASSENT (${tests}).`); process.exit(0); }
console.log(`${failed.length} ÉCHEC(S) sur ${tests} :`); failed.forEach((f) => console.log("  - " + f)); process.exit(1);

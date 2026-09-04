/**
 * Tests unitaires déterministes de l'annuaire front (I2) — node pur, sans framework.
 *
 *   node tests/front-jobs-companies.test.mjs
 *
 * Charge postelio-api.js + directory.js dans un contexte VM (fetch mocké). Couvre :
 * adaptateurs offre (native/externe) et entreprise, bulle déterministe, passage des
 * filtres/pagination à l'API, mapping total_is_exact, URL apply-redirect.
 */
import fs from "node:fs";
import vm from "node:vm";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
let tests = 0, failed = [];
function check(l, c) { tests++; console.log((c ? "  [ok]   " : "  [FAIL] ") + l); if (!c) failed.push(l); }

let fetchImpl = async () => { throw new Error("fetch non mocké"); };
function res(status, body) { return { ok: status >= 200 && status < 300, status, text: async () => (body === undefined ? "" : JSON.stringify(body)) }; }
const win = {};
const sandbox = {
  window: win, location: { origin: "http://test.local", search: "" },
  fetch: (...a) => fetchImpl(...a), setTimeout, clearTimeout, AbortController, URLSearchParams,
  console, Promise, JSON, Object, Array, Date, Error, String, Number, Math, encodeURIComponent,
  toLocaleString: undefined
};
win.dispatchEvent = () => {}; win.addEventListener = () => {};
vm.createContext(sandbox);
function load(rel) { vm.runInContext(fs.readFileSync(path.join(root, rel), "utf8"), sandbox, { filename: rel }); }
load("assets/js/api/postelio-api.js");
load("assets/js/api/directory.js");
const D = win.PostelioDirectory;

/* ---- 1. Adaptateur OFFRE native ---- */
console.log("== adaptJob (native) ==");
const nat = D.adaptJob({
  uuid: "u-1", titre: "Dev", ville: "Lyon", contrat: "CDI", temps_travail: "Temps plein",
  date_publication: "2026-09-01", salaire_annuel: 42000,
  company: { uuid: "c-1", nom: "ACME", verified: true, logo_url: null },
  source: { type: "native", key: "postelio", label: "Postelio", external: false },
  application: { mode: "postelio" }
});
check("id = uuid", nat.id === "u-1");
check("entrepriseNom + entrepriseId", nat.entrepriseNom === "ACME" && nat.entrepriseId === "c-1");
check("external = false", nat.external === false);
check("applicationMode = postelio", nat.applicationMode === "postelio");
check("salaire formaté depuis salaire_annuel", /42/.test(nat.salaire));
check("verifie = true", nat.verifie === true);
check("bulle : couleur + initiales", /^#/.test(nat.couleur) && nat.initiales.length >= 1);

/* ---- 2. Adaptateur OFFRE externe ---- */
console.log("== adaptJob (externe) ==");
const ext = D.adaptJob({
  uuid: "u-2", titre: "Data", ville: "Paris",
  company: { uuid: null, nom: "Partenaire", verified: false },
  source: { type: "external", key: "ft", label: "France Travail", external: true,
    attribution: { source_label: "France Travail", licence_url: "https://x/l", notice: "Offre proposée par France Travail" } },
  application: { mode: "external_redirect", redirect: "/jobs/u-2/apply-redirect" }
});
check("external = true", ext.external === true);
check("sourceLabel", ext.sourceLabel === "France Travail");
check("attribution transmise", ext.attribution && ext.attribution.licence_url === "https://x/l");
check("applicationMode = external_redirect", ext.applicationMode === "external_redirect");
check("applyRedirect chemin", ext.applyRedirect === "/jobs/u-2/apply-redirect");

/* ---- 3. Adaptateur ENTREPRISE ---- */
console.log("== adaptCompany ==");
const co = D.adaptCompany({ uuid: "c-9", nom: "Globex", description: "desc", verified: true,
  editorial: { logo_url: null, ville: "Nantes", secteur: "Tech" }, legal: { siren: "123456789" } });
check("id = uuid", co.id === "c-9");
check("verifie + label", co.verifie === true && co.verifieLabel === "Entreprise vérifiée");
check("ville/secteur editorial", co.ville === "Nantes" && co.secteur === "Tech");
check("legal.siren conservé", co.legal.siren === "123456789");

/* Clés éditoriales RÉELLES du contrat backend (CompanyService) : `effectif` et `site`.
   L'audit de reprise a montré que l'adaptateur lisait `taille` et `site_web`, absents de l'API. */
const coReal = D.adaptCompany({ uuid: "c-10", nom: "Initech", verified: false,
  editorial: { effectif: "50-99", site: "https://initech.test", activite: "Services", adresse: "1 rue A",
    telephone: "0102030405", email: "contact@initech.test", valeurs: ["Rigueur"], avantages: ["Télétravail"] } });
check("taille lue depuis editorial.effectif", coReal.taille === "50-99");
check("siteWeb lu depuis editorial.site", coReal.siteWeb === "https://initech.test");
check("activite/adresse/telephone/email transmis", coReal.activite === "Services" && coReal.adresse === "1 rue A"
  && coReal.telephone === "0102030405" && coReal.email === "contact@initech.test");
check("valeurs/avantages en tableaux", coReal.valeurs[0] === "Rigueur" && coReal.avantages[0] === "Télétravail");
check("departement neutralisé (absent du contrat)", coReal.departement === "");
check("non vérifiée → pas de label", coReal.verifie === false && coReal.verifieLabel === "");

/* ---- 4. Bulle déterministe ---- */
console.log("== bubble ==");
check("même nom → même couleur", D.bubble("ACME").couleur === D.bubble("ACME").couleur);
check("initiales 2 lettres", D.bubble("Le Fournil").initiales === "LF");

/* ---- 5. jobs.search : filtres + pagination + total_is_exact ---- */
console.log("== jobs.search ==");
let capturedUrl = null;
fetchImpl = async (url) => {
  capturedUrl = url;
  return res(200, { data: [ { uuid: "a", titre: "X", source: { type: "native" }, company: {}, application: {} } ],
    meta: { pagination: { total: 250, total_pages: 21, page: 2, per_page: 12, total_is_exact: false } } });
};
const sr = await D.jobs.search({ q: "dev", ville: "Lyon", source: "partners" }, 2, 12);
check("q & ville & source dans l'URL", /[?&]q=dev(&|$)/.test(capturedUrl) && /[?&]ville=Lyon(&|$)/.test(capturedUrl) && /[?&]source=partners(&|$)/.test(capturedUrl));
check("page & per_page dans l'URL", /[?&]page=2(&|$)/.test(capturedUrl) && /[?&]per_page=12(&|$)/.test(capturedUrl));
check("items adaptés", sr.items.length === 1 && sr.items[0].id === "a");
check("total + totalPages", sr.total === 250 && sr.totalPages === 21);
check("totalIsExact = false (approx.)", sr.totalIsExact === false);

/* ---- 6. applyRedirectUrl absolu ---- */
console.log("== applyRedirectUrl ==");
check("URL absolue vers apply-redirect", D.jobs.applyRedirectUrl("u-2") === "http://test.local/wordpress/wp-json/postelio/v1/jobs/u-2/apply-redirect");

console.log("");
if (!failed.length) { console.log(`TOUS LES TESTS PASSENT (${tests}).`); process.exit(0); }
console.log(`${failed.length} ÉCHEC(S) sur ${tests} :`); failed.forEach((f) => console.log("  - " + f)); process.exit(1);

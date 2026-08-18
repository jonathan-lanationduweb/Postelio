/**
 * Synchronise la navbar de toutes les pages depuis partials/header.html.
 * Usage :  node tools/sync-header.mjs
 * - index.html reçoit la variante « overlay » (navbar posée sur la vidéo) ;
 * - toutes les autres pages reçoivent la variante « light » ;
 * - aria-current="page" est posé sur le lien correspondant à la page.
 */
import { readFileSync, writeFileSync, readdirSync } from "node:fs";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = join(dirname(fileURLToPath(import.meta.url)), "..");

/* Page → lien actif dans le menu (les pages de détail activent leur liste). */
const ACTIVE = {
  "index.html": "index.html",
  "offres.html": "offres.html",
  "offre-detail.html": "offres.html",
  "entreprises.html": "entreprises.html",
  "entreprise-detail.html": "entreprises.html",
  "savoir-faire.html": "savoir-faire.html",
  "savoir-faire-detail.html": "savoir-faire.html",
  "publier-savoir-faire.html": "savoir-faire.html",
  "blog.html": "blog.html",
  "article.html": "blog.html",
  "a-propos.html": "a-propos.html",
  "recherche-guidee.html": "recherche-guidee.html",
};

const partial = readFileSync(join(ROOT, "partials", "header.html"), "utf8")
  .replace(/<!--[\s\S]*?-->\s*/, ""); // retire le commentaire d'en-tête

const HEADER_RE = /<header class="site-header[\s\S]*?<\/header>/;

let updated = 0;
for (const file of readdirSync(ROOT).filter((f) => f.endsWith(".html"))) {
  const path = join(ROOT, file);
  let html = readFileSync(path, "utf8");
  if (!HEADER_RE.test(html)) {
    console.warn(`~ ${file} : aucun <header class="site-header"> trouvé, ignoré`);
    continue;
  }

  /* Accueil : variante « cine » = navbar UNIQUE et persistante, cinématique
     (transparente/blanche) sur la vidéo puis normale (crème/vert) ensuite,
     pilotée par le scroll. Toutes les autres pages : variante « light ». */
  let header = partial
    .replace("{{variant}}", file === "index.html" ? "cine" : "light")
    .trimEnd();

  const active = ACTIVE[file];
  if (active) {
    header = header.replace(
      `<li><a href="${active}">`,
      `<li><a href="${active}" aria-current="page">`
    );
    header = header.replace(
      `<li class="nav-guided"><a href="${active}">`,
      `<li class="nav-guided"><a href="${active}" aria-current="page">`
    );
  }

  const next = html.replace(HEADER_RE, header);
  if (next !== html) {
    writeFileSync(path, next);
    updated++;
    console.log(`✓ ${file}`);
  } else {
    console.log(`= ${file} (déjà à jour)`);
  }
}
console.log(`${updated} page(s) synchronisée(s).`);

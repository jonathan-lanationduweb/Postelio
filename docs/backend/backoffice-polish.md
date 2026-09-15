# Back-office Postelio — grille de comparaison LNDW et système visuel affiné

Chantier `feature/backoffice-visual-polish` — 100 % UI/UX (aucun changement métier, table, API,
permission ou workflow). Référence : captures et vidéo du plugin WordPress « La Nation du Web »
(`Dossier LNDW/Dossier screen LNDW/wordpress`). Postelio conserve ses couleurs (bleu nuit `#17324D`,
corail `#FF6B6B`, fond clair).

## 1. Patterns LNDW relevés (à reproduire, pas seulement « s'inspirer »)

| Pattern | Observation LNDW | Transposition Postelio |
|---|---|---|
| En-tête d'écran | Surtitre 11 px capitales, titre 26–28 px, phrase courte, **actions à droite sur la même ligne** (« Réinitialiser » secondaire, « Enregistrer » primaire), pas de carte ni de hero | `Ui::page_header` sans carte : eyebrow + titre 22 px + description, actions à droite, filet bas |
| Navigation secondaire | Onglets **soulignés** fins (texte 14 px, actif bleu + trait 2 px), pas de pills | `Ui::tabs` : soulignement corail, compteurs discrets |
| Densité / largeur | Contenu pleine largeur utile, deux colonnes équilibrées (formulaire 50–55 % / aperçu 45–50 %) | `.pst-bo` sans plafond artificiel (1560 px), grilles `2fr/1fr` et `1fr/1fr` selon l'écran |
| Cartes | Blanches, bord 1 px gris clair, rayon 12 px, **ombre quasi nulle**, en-tête avec petit surtitre + titre + badge compteur | `.bo-card` sans ombre, en-tête optionnel `eyebrow / titre / actions`, jamais de carte dans une carte |
| Lignes d'éléments (missions, logos, étapes) | Ligne haute (72–88 px) : vignette carrée, titre gras, sous-ligne (chemin), chip statut beige, **actions verticales compactes** (Monter/Descendre/Retirer) ou icônes | `.bo-table` lignes 56–64 px avec `entity` (avatar 34 px + titre + sous-titre) et actions compactes (bouton « Voir » + menu ⋯) |
| Formulaires | Champs côte à côte (2 colonnes), labels nets 12,5 px, aide courte grise, compteur « 23 / 120 », switch dans l'en-tête de section, repeaters compacts | `.bo-form`, `.bo-field--half`, `.bo-count`, `.bo-switch` (Site Builder déjà conforme, harmonisé) |
| Aperçu | Toujours visible à droite, cadre appareil, boutons Desktop/Tablette/Mobile (segmentés bleus) | Conservé (Site Builder), segmenté harmonisé |
| États / feedback | Chips douces (« Publiée » beige, « Section active » vert clair), notices WordPress standard | `.bo-badge` teintes douces, `.bo-alert` fines |
| États vides | Une phrase + action, dans le flux | `.bo-empty` compact (padding 18 px, sans grande boîte) |
| Boutons | Primaire bleu plein arrondi 8 px, secondaire blanc bordé, tertiaire lien | `.bo-btn` (bordé) / `--primary` (bleu nuit) / `--accent` (corail, rare) / `--ghost` |

## 2. Grille écran par écran

| Écran | Postelio (avant) | LNDW | Problème | Correction |
|---|---|---|---|---|
| Tableau de bord | En-tête carte + 6 tuiles identiques (26 px) + « À traiter » + raccourcis boutons + ligne santé | Carte de synthèse avec 3 sous-tuiles, titre + description, CTA unique | 6 tuiles répétitives, raccourcis = boutons gris, aucune hiérarchie | Bande de 6 indicateurs compacts, « À traiter » en liste priorisée (compteur + libellé + CTA), raccourcis en tuiles cliquables (titre + description), santé en ligne discrète |
| Candidatures | Pipeline 5 blocs blancs + pills + table 6 colonnes (entités sans sous-ligne) | Liste d'étapes numérotées, ligne riche | Pipeline lourd, lignes pauvres (candidat sans métier, offre sans entreprise dans la même cellule) | Pipeline segmenté compact (compteur + libellé, actif souligné), tabs fins, ligne riche : avatar candidat + nom / offre + entreprise / statut / date / entretien / actions ; détail type mini-CRM (colonne principale + panneau latéral) |
| Utilisateurs | 4 tuiles + pills + recherche dans une carte + table avec 2 gros boutons par ligne | Ligne riche + actions icônes | Actions envahissantes, e-mail en clair non masqué, tuiles inutiles | Barre d'outils (tabs + recherche à droite), ligne avatar + nom + e-mail masqué, chips type/statut/vérification, date, menu ⋯ |
| Entreprises | Alerte + pills + table (entreprise, statut, SIREN, ville) | Cartes missions (vignette, titre, chemin, chip) | Peu d'information par ligne, actions verbeuses | Ligne logo + nom + ville · secteur, chips vérification/statut, SIREN, membres/offres (« — » si non exposés), menu ⋯ ; détail en fiche (identité + légal / vérification + membres / aperçu) |
| Offres | Pills + table 7 colonnes | Ligne riche | Titre et entreprise séparés, expiration brute | Titre + entreprise dans la même cellule, contrat, ville, source, statut, expiration formatée, menu ⋯ ; détail : contenu à gauche, entreprise / statut / cycle de vie / actions à droite |
| Modération | Pills + table 7 colonnes | File d'attente priorisée | Aucune priorité visuelle, tout se ressemble | File de cartes-lignes : barre de priorité colorée, ressource, nombre de signalements, ancienneté, « Examiner » ; détail : contexte / historique / décision / note |
| Réglages | Pills 9 onglets + 1 carte de 4 lignes | Navigation latérale / onglets très lisibles | Écran vide aux 2/3, réglages éparpillés | Navigation latérale (catégories) + panneau de domaine à droite, sections logiques |
| Facturation | 6 tuiles + alerte + pills + table | Tuiles de synthèse + table | Tuiles vides, pas de vue « revenus / commandes / anomalies » | Bande d'indicateurs (payées / en attente / anomalies / remboursées), table des commandes, détail avec chronologie |
| Sources d'offres | 1 carte kv | Cartes d'intégration | Aspect technique | Cartes « intégration » : logo lettre, nom, chip Connecté / Non connecté, grand compteur d'offres, dernière synchro, « Voir l'état » (détails repliés) |
| Santé | 4 tuiles + tables | Écran système | Tables techniques | État général en tête, groupes Plateforme / Données / E-mails / Sources / Paiements / Tâches automatiques en lignes nom · état · détail, technique replié |
| Messagerie | 4 tuiles + table | — | Table plate | Boîte de réception : colonne conversations (avatar, candidat, entreprise, statut, dernière activité) + panneau contexte / participants / état ; contenu toujours protégé |
| Entretiens | Pills + table | — | Table plate | Vue : Aujourd'hui / À venir / À confirmer / Historique en cartes compactes (heure, candidat, offre, type, statut) |
| Service e-mail | 5 tuiles + 2 cartes kv | — | Correct mais tuiles | Centre d'envoi : transport / état / dernier test en tête, file (attente, échecs) et historique séparés |
| Mon site | Déjà avancé | Référence directe | En-tête carte, pills | Header, tabs, cartes et champs alignés sur le nouveau système ; architecture inchangée |

## 3. Système visuel (backoffice.css — base unique, classes obsolètes retirées)

- Fond `#F7F7F5`, surfaces blanches, filets `#E6E8EC`, rayon 10 px (cartes) / 8 px (contrôles),
  ombres quasi nulles. Bleu nuit = titres, texte fort, bouton primaire, soulignement actif ; corail =
  eyebrow, accent d'onglet, compteur « à traiter », bouton d'action rare.
- Typographie : H1 22 px/700 · H2 15 px/700 · label 12,5 px/600 · texte 13,5 px · meta 12 px muted.
- Composants (`Ui`) : `page_header` · `tabs` · `toolbar` · `kpis/kpi` · `card` · `section` · `table`
  · `entity` · `badge` · `menu` (⋯) · `pipeline` · `tiles/tile` · `sidenav` · `split` · `timeline`
  · `empty` · `alert` · `details` · `kv` · `form` helpers.
- Responsive : 1440 / 1280 / 1024 / 782 / 390 — tables défilent horizontalement, colonnes se
  replient, navigation latérale devient onglets.

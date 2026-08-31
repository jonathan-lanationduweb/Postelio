# Postelio — Back-office WordPress (`postelio-admin`)

> Phase 1 livrée sur `feature/postelio-admin`. Le back-office est un **centre de contrôle
> wp-admin** : couche d'administration **pure**, sans logique métier ni table, consommant les
> contrats/services publics des plugins Postelio.

## 1. Architecture

```
wp-admin (menu « Postelio »)
   └── postelio-admin (VUE / ORCHESTRATION)
          ├── lecture : Api\*Directory  +  REST interne (rest_do_request, au nom de l'admin)
          └── écriture : services propriétaires (UserModeration, CompanyModeration,
                         VerificationService, JobModeration, API modération…)
```

**Règle absolue** : `postelio-admin` ne fait **jamais** d'`UPDATE` SQL direct dans les tables
d'un autre plugin, ni de lecture directe de meta/CPT (hors WordPress natif : `wp_users`, comptes
de posts). Toute donnée « propriété d'un domaine » passe par son contrat.

**Robustesse** : détection systématique (`Core\Registry::has` + `class_exists`) → si un module
est désactivé, une carte « Module indisponible » s'affiche, **jamais** de fatal.

## 2. Menu Postelio

Un seul menu de premier niveau (icône SVG bleu nuit), ordonné par groupes logiques (sans séparateur
WP fragile) : **Tableau de bord** · *Gestion* → Utilisateurs · Entreprises · Offres · Candidatures ·
Entretiens · *Communication* → Messagerie · Notifications · *Contenu & données* → CV & fichiers ·
Savoir-faire · Sources d'offres · Favoris & Alertes (préparé Lot 14) · *Contrôle* → Modération
(pastille dossiers ouverts) · Facturation · *Système* → Réglages · Santé du système.

Phases livrées :
- **Phase 1** : Tableau de bord, Utilisateurs, Entreprises, Offres, Modération (file), Santé.
- **Phase 2** : **détails** Utilisateur/Entreprise/Offre + **aperçus** (entreprise, offre, savoir-faire) ;
  **Facturation** complète (KPI + liste + détail + retry fulfillment) ; **Sources d'offres** ;
  **Notifications** (observabilité de la file d'envoi) ; **Savoir-faire** (liste + détail + hide/unhide) ;
  **Modération enrichie** (détail de case : contexte, historique, actions contextuelles, note interne).
- **Phase 3** : **Candidatures** (liste + détail), **Entretiens** (liste + détail, coordonnées
  capability-gated), **Messagerie** (privacy-first, sans contenu), **CV & fichiers** (KPI + liste
  technique métadonnées), **Réglages** structurés (8 onglets, états réels sans secrets), **Santé**
  finalisée (synthèse globale + sections + rafraîchir), navigation réordonnée + pastille modération
  + raccourcis tableau de bord. Voir §5 bis et la matrice de confidentialité (§5 ter).

Reste après Phase 3 : **Favoris & Alertes** uniquement (Lot 14).

## 3. Droits (capabilities réutilisées — aucune nouvelle)

| Zone | Capability | Rôles |
|---|---|---|
| Menu + Tableau de bord + Modération | `pst_view_moderation_queue` | admin, modérateur |
| Utilisateurs, Entreprises, Offres, Santé, Réglages, placeholders | `pst_manage_platform` | admin |
| Facturation | `pst_manage_billing` | admin |
| (support / recruteur / candidat) | — | aucun accès |

WordPress masque les sous-menus non autorisés ; **chaque page revérifie la capability côté
serveur** (défense en profondeur). Les actions passent par `admin-post` avec **nonce** +
capability + délégation au service propriétaire.

## 4. Contrats utilisés

- Lecture KPI/listes : `count_users()` (WP), `CompanyAdminDirectory`, `JobAdminDirectory`,
  `ModerationDirectory::open_cases_count`, `Core\Health\Status::snapshot`, endpoints REST
  `/moderation/cases`, `/moderation/health`, `/billing/health`, `/billing/admin/orders`,
  `/job-sources/health`, `/skills`.
- Actions : `UserModeration::suspend/unsuspend`, `CompanyModeration::suspend/unsuspend`,
  `VerificationService::decide`, `JobModeration::suspend/unsuspend`, endpoints modération
  `/moderation/cases/{uuid}/assign|decision`.

### Contrats additifs créés (lecture seule, non destructifs)
- `Postelio\Jobs\Api\JobAdminDirectory` — `counts()`, `list()`, **`detail(uuid)`** (Phase 2).
- `Postelio\Companies\Api\CompanyAdminDirectory` — `counts()`, `list()`, **`detail(uuid)` + membres** (Phase 2).
- `Postelio\Skills\Api\SkillAdminDirectory` — `counts()`, `list()`, `detail(uuid)` (Phase 2).
- `Postelio\Notifications\Api\NotificationDirectory::delivery_stats()` — observabilité de la file (Phase 2).
- `Postelio\Applications\Api\ApplicationAdminDirectory` — `counts()`, `list()`, `detail(uuid)`,
  `referenced_cv(uuids)` (Phase 3). **N'expose jamais les notes recruteur.**
- `Postelio\Interviews\Api\InterviewAdminDirectory` — `counts()`, `list()` (sans coordonnées),
  `detail(uuid, $include_coordinates)` — coordonnées sensibles (adresse/téléphone/visio) rendues
  seulement si l'appelant passe `true` après vérification de capacité (Phase 3).
- `Postelio\Messaging\Api\MessagingAdminDirectory` — `counts()`, `list()`, `detail(uuid)` —
  contexte + participants + compteurs, **jamais le contenu des messages** (Phase 3).
- `Postelio\Files\Api\FileAdminDirectory` — `counts()` (statut/type/provider/stockage), `list()` —
  **métadonnées uniquement** : jamais `storage_key`, chemin, nom de fichier, contenu (Phase 3).

Chaque façade fait un `SELECT` explicite des colonnes non sensibles, résout les libellés
(candidat/offre/entreprise) une fois par ligne (≤ 50/page, pas de N+1 non borné) et ne mute rien.

Ces façades interrogent le CPT/la table de leur PROPRE domaine (légitime) ; elles n'écrivent rien.
Facturation, Sources et Modération (détail) consomment directement les endpoints REST existants
(`/billing/admin/orders[/{uuid}]`, `/billing/health`, `/job-sources/health`, `/moderation/cases/{uuid}`).

### Actions Phase 2 (admin-post, nonce + capability + délégation)
Retry fulfillment (`/billing/.../retry-fulfillment`) · hide/unhide savoir-faire (`SkillModeration`) ·
hide/unhide offre externe (`JobSourcesModeration`) · décisions modération contextuelles
(hide/unhide/warning/close_conversation/dismiss/escalate/suspend_job/suspend_company/suspend_user +
note) via `/moderation/cases/{uuid}/decision|note` (les gardes de capability admin sont appliquées
par le domaine). Confidentialité : aucune donnée privée superflue (CV, messages), aucun secret Stripe,
aucun destinataire d'e-mail, aucun ID SQL.

## 5. Pages (Phase 1)

- **Tableau de bord** : KPI réels ou « — » si indisponible (jamais inventé) ; cartes Modération,
  État des services, Facturation (selon capability).
- **Utilisateurs** : onglets Tous/Candidats/Recruteurs/Suspendus ; Suspendre/Réactiver → `UserModeration`.
- **Entreprises** : onglets par statut de vérification ; Vérifier/Rejeter/Suspendre/Réactiver →
  `VerificationService` / `CompanyModeration`.
- **Offres** : onglets par statut métier + source ; Suspendre/Réactiver → `JobModeration`.
- **Modération** : file (À traiter/En cours/Escaladés/Résolus/Ignorés) via `GET /moderation/cases` ;
  M'assigner/Résoudre/Escalader via l'API modération.
- **Santé** : snapshot core + état des modules (OK/DÉGRADÉ/NON CONFIGURÉ/ABSENT) ; aucun secret.

## 5 bis. Pages (Phase 3 — finalisation métier)

- **Candidatures** : onglets par statut métier (Nouveau/À examiner/Présélection/Entretien/Retenu/
  Refusé/Retiré) ; colonnes Candidat/Offre/Entreprise/Statut/Reçue/Entretien ; deep-link
  `company_id`/`job_uuid`. Détail : snapshot offre, message candidat, réponses de présélection, CV
  **référencé** (métadonnée, sans téléchargement), historique. **Notes recruteur : toujours
  « Protégées »** (confidentielles à l'entreprise ; la façade ne les renvoie pas).
- **Entretiens** : onglets Tous/Proposés/Confirmés/Replanification/Refusés/Annulés/Terminés ;
  colonnes Candidat/Entreprise/Offre/Créneau/Type/Statut — **aucune coordonnée en liste**. Détail :
  planification, **coordonnées sensibles capability-gated** (`pst_manage_platform`), instructions,
  candidature liée, chronologie (proposed→confirmed→reschedule→…→completed).
- **Messagerie** (privacy-first) : KPI (conversations actives/fermées, messages, messages 7 j) ;
  liste Sujet/Candidat/Entreprise/Statut/Dernière activité — **jamais le dernier message**. Détail :
  contexte, participants, compteurs, candidature liée ; **contenu « Protégé — accessible uniquement
  dans le cadre d'une modération »**.
- **CV & fichiers** : PAS une bibliothèque navigable. KPI (actifs/archivés/quarantaine/supprimés,
  stockage vivant, providers) + liste technique en métadonnées (réf. courte/type/statut/taille/
  provider/date/référencé oui-non). **Aucun chemin, `storage_key`, nom de fichier, contenu ni
  bouton télécharger** (aucun contrat admin de téléchargement n'existe). Quarantaine affichée,
  **aucune action directe** sur les fichiers.
- **Réglages** : onglets Général/Comptes/Offres/Notifications/Modération/Sources/Facturation/
  Sécurité. Uniquement des **états réels détectables** (module actif, transport e-mail « Configuré
  par WP Mail SMTP », Stripe mode/configuré/webhook/vendeur/facture légale **sans clés**). L'onglet
  Sécurité = **indicateurs de santé** (vérif. e-mail, 2FA prévu, journal d'audit, stockage privé,
  modération, webhook Stripe, auth REST, Bearer Tauri), **pas de faux interrupteurs**.
- **Santé (finalisée)** : synthèse globale (OK/DÉGRADÉ/ERREUR) + sections Plateforme/Données/
  Workers/Providers/Sécurité + bouton « Rafraîchir l'état » (non destructif).
- **Navigation** : menu réordonné par groupes logiques (Tableau de bord → Gestion → Communication
  → Contenu/Données → Contrôle → Système), sans séparateur WP fragile ; pastille modération
  (dossiers ouverts) rendue côté serveur (convention `awaiting-mod`, aucun polling JS) ; raccourcis
  capability-gated sur le tableau de bord.

Reste en placeholder après la Phase 3 : **Favoris & Alertes** uniquement (Lot 14).

## 5 ter. Matrice de confidentialité (qui voit quoi)

Rôles : **Modérateur** (`pst_view_moderation_queue`), **Support** (`pst_view_support`),
**Admin** (`pst_manage_platform` = accès aux écrans de supervision). Les écrans Candidatures/
Entretiens/Messagerie/Fichiers sont gardés par `pst_manage_platform`.

| Donnée sensible                    | Modérateur | Support | Admin (supervision) |
|------------------------------------|:----------:|:-------:|:-------------------:|
| E-mail utilisateur                 | ❌ | ❌ | via fiche Utilisateur (capacité admin) |
| Données légales entreprise         | ❌ | ❌ | ✅ (fiche Entreprise) |
| CV — contenu / téléchargement      | ❌ | ❌ | ❌ (métadonnées seules, aucun contrat admin) |
| Contenu des messages               | via outils modération | ❌ | ❌ (contexte seul) |
| Notes recruteur                    | ❌ | ❌ | ❌ (« Protégées » — privées à l'entreprise) |
| Coordonnées d'entretien            | ❌ | ❌ | ✅ en détail, capability-gated (`pst_manage_platform`) |
| Snapshot de facturation            | ❌ | ❌ | ✅ si `pst_manage_billing` |
| Notes / décisions de modération    | ✅ | ❌ | ✅ |

Principe : là où aucun contrat de lecture ni capacité dédiée n'autorise une donnée sensible
(CV, contenu de message, notes recruteur), le back-office affiche « protégé » plutôt que de
contourner. La modération du contenu (messages, signalements) passe par les contrats de
modération, jamais par une lecture directe des tables depuis l'admin.

## 6. Design system

`assets/admin.css` — tokens Postelio (`--pst-primary #17324D`, `--pst-accent #FF6B6B`, fond
`#FAF7F1`, success/error/warning/info), tout préfixé `pst-` sous `.pst-admin`. Composants :
`pst-admin-header/card/grid/stat/badge/table/tabs/empty/alert/actions/pager/kv/cols`. Responsive
(2 → 1 colonne, tables en scroll). Aucun framework JS (`admin.js` minimal : confirmations).

## 7. Sécurité

Capability serveur (page + action) · nonces admin-post · échappement centralisé dans `Ui`
(`esc_html/esc_attr/esc_url`) · confirmation des actions destructives · aucun ID SQL/secret exposé ·
accès au contenu privé (CV, messages) **non** ouvert par défaut.

## 8. Différence avec les dashboards front

Le **front** candidat/recruteur (statique, hors périmètre de ce plugin) est l'espace des
utilisateurs finaux. `postelio-admin` est l'espace **d'administration de la plateforme**
(wp-admin), réservé aux administrateurs/modérateurs.

## 9. Phases suivantes (proposées)

- **Phase 2** : détails Utilisateur/Entreprise/Offre ; Facturation complète (retry fulfillment) ;
  Sources d'offres ; Notifications (observabilité) ; Savoir-faire.
- **Phase 3** *(livrée)* : Candidatures ; Entretiens ; Messagerie (privacy-first) ; CV & fichiers
  (état stockage, métadonnées) ; Réglages sûrs ; Santé finalisée ; navigation réordonnée.
- **Après Lot 14** : page Favoris & Alertes (favoris, recherches sauvegardées, alertes, digests).

## 10. Lot 14

Le Lot 14 (Favoris/Recherches/Alertes) suit son propre cycle. Le back-office **prépare** son
emplacement de menu mais **n'implémente aucune logique** en avance et ne modifie aucune décision
du Lot 14.

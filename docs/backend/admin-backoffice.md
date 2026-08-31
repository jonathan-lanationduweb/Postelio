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

Un seul menu de premier niveau (icône SVG bleu nuit), sous-pages :
Tableau de bord · Utilisateurs · Entreprises · Offres · Candidatures · CV & fichiers · Messagerie ·
Entretiens · Notifications · Sources d'offres · Modération · Facturation · Savoir-faire ·
Favoris & Alertes (préparé Lot 14) · Réglages · Santé du système.

Phase 1 : pages **complètes** = Tableau de bord, Utilisateurs, Entreprises, Offres, Modération,
Santé. Les autres = emplacement de menu + écran « en préparation » (implémentation phases suivantes).

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
- `Postelio\Jobs\Api\JobAdminDirectory` — `counts()`, `list(filters,page,per_page)`.
- `Postelio\Companies\Api\CompanyAdminDirectory` — `counts()`, `list(filters,page,per_page)`.

Ces façades interrogent le CPT de leur PROPRE domaine (légitime) ; elles n'écrivent rien.

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
- **Phase 3** : Candidatures ; Entretiens ; Messagerie (prudente) ; CV & fichiers (état stockage) ;
  Réglages sûrs ; aperçus en direct.
- **Après Lot 14** : page Favoris & Alertes (favoris, recherches sauvegardées, alertes, digests).

## 10. Lot 14

Le Lot 14 (Favoris/Recherches/Alertes) suit son propre cycle. Le back-office **prépare** son
emplacement de menu mais **n'implémente aucune logique** en avance et ne modifie aucune décision
du Lot 14.

# Postelio Admin — Back-office (Phase 1)

Centre de contrôle **wp-admin** de Postelio : tableau de bord, utilisateurs, entreprises, offres,
modération, santé du système… Une administration bien plus lisible que le wp-admin brut, aux
couleurs Postelio (**bleu nuit + corail** sur fond crème), sans que l'administrateur n'ait à
manipuler meta, CPT ou SQL.

## Principe d'architecture (règle absolue)
```
postelio-admin  →  contrats/services publics des plugins  →  domaines propriétaires
```
`postelio-admin` est une **couche d'ORCHESTRATION/VUE**. Il ne contient **aucune** logique métier,
**aucune** table, et ne fait **jamais** d'`UPDATE` SQL direct dans les tables d'un autre plugin.
Il lit via les contrats `Api\*Directory` / les endpoints REST internes, et agit via les services
(`UserModeration`, `CompanyModeration`, `VerificationService`, `JobModeration`, API modération…).
Les lectures de listes réutilisent les endpoints REST **au nom de l'utilisateur courant**
(`rest_do_request`) : les capabilities et présenteurs des domaines s'appliquent tels quels.

## Ne fatal jamais
Chaque appel est précédé d'une détection (`Registry::has` / `class_exists`). Si un module est
désactivé, la page/carte affiche « Module indisponible » — jamais de *fatal class not found*.

## Menu Postelio (centre de contrôle unique)
Tableau de bord · Utilisateurs · Entreprises · Offres · Candidatures · CV & fichiers · Messagerie ·
Entretiens · Notifications · Sources d'offres · Modération · Facturation · Savoir-faire ·
Favoris & Alertes (préparé pour le Lot 14) · Réglages · Santé du système.

**Phase 1 — pages complètes** : Tableau de bord, Utilisateurs, Entreprises, Offres, Modération,
Santé. Les autres entrées existent (menu + architecture) et renvoient un écran « en préparation ».

## Capabilities (réutilisées, aucune nouvelle)
- Menu + Tableau de bord + Modération : `pst_view_moderation_queue` (admin + modérateur).
- Utilisateurs, Entreprises, Offres, Santé, Réglages + placeholders : `pst_manage_platform` (admin).
- Facturation : `pst_manage_billing` (admin).
- Support / recruteur / candidat : **aucun** accès au menu.

Chaque page revérifie sa capability **côté serveur** (défense en profondeur, en plus du masquage
de menu WordPress). Les actions passent par `admin-post` avec **nonce** + capability + délégation.

## Pages (Phase 1)
- **Tableau de bord** : KPI réels (candidats, recruteurs, entreprises + vérifiées, offres publiées
  + expirant, modération ouverte + critiques, savoir-faire publiés, facturation). Toute valeur non
  disponible proprement affiche « — » (jamais de chiffre inventé). Cartes « Modération », « État
  des services », « Facturation » (selon capability).
- **Utilisateurs** : onglets Tous/Candidats/Recruteurs/Suspendus, recherche, statut, e-mail
  vérifié. Actions Suspendre/Réactiver → `UserModeration` (jamais `wp_users`).
- **Entreprises** : onglets par statut de vérification, actions Vérifier/Rejeter/Suspendre/
  Réactiver → `VerificationService` / `CompanyModeration`.
- **Offres** : onglets par statut métier, distinction visuelle de la source, actions Suspendre/
  Réactiver → `JobModeration`.
- **Modération** : file visuelle (À traiter / En cours / Escaladés / Résolus / Ignorés) consommant
  `GET /moderation/cases`, badges de priorité, actions M'assigner/Résoudre/Escalader via l'API
  modération.
- **Santé du système** : snapshot du core (version/schéma/DB/audit) + état des modules (OK /
  DÉGRADÉ / NON CONFIGURÉ / ABSENT), détails Stripe/France Travail. Aucun secret.

## Contrats additifs (lecture admin, non destructifs)
- `postelio-jobs` : `JobAdminDirectory` (compteurs par statut + liste filtrée).
- `postelio-companies` : `CompanyAdminDirectory` (compteurs par statut de vérification + liste).
Ces façades sont **en lecture seule** ; les écritures restent dans les services propriétaires.

## Design system (`assets/admin.css`)
Tokens Postelio (`--pst-primary #17324D`, `--pst-accent #FF6B6B`, fond `#FAF7F1`…), tout préfixé
`pst-` et encapsulé sous `.pst-admin` pour ne pas heurter wp-admin. Composants : header, card,
grid, stat, badge (success/error/warning/info/critical/high/medium/low/neutral), table, tabs,
empty, alert, actions, pager, kv, cols. Responsive (2 colonnes → 1, tables en scroll horizontal).
Aucun framework JS (un `admin.js` minimal pour la confirmation des actions sensibles).

## Sécurité
Capability serveur par page et par action · nonces (`admin-post`) · échappement systématique
(`esc_html`/`esc_attr`/`esc_url`) centralisé dans `Ui` · confirmation des actions destructives ·
aucun ID SQL ni secret exposé · aucune écriture inter-plugin directe.

## Ce que le back-office NE fait pas (par conception)
- Il n'ouvre pas librement tous les CV (stockage privé) ni toutes les conversations : l'accès au
  contenu privé reste conditionné aux capabilities et à la modération.
- Il n'affiche aucun secret (clés Stripe, webhook, payload brut) — seulement des états.

## Phases suivantes (proposées)
Phase 2 : détails Utilisateur/Entreprise/Offre, page Facturation complète (retry fulfillment),
Sources d'offres, Notifications (observabilité), Savoir-faire. Phase 3 : Candidatures, Entretiens,
Messagerie (prudente), CV & fichiers (état stockage), Réglages sûrs, aperçus en direct. Après Lot
14 : page Favoris & Alertes.

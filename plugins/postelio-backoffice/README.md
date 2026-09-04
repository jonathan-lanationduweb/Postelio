# Postelio Backoffice

Couche **unique** d'administration wp-admin de Postelio. Orchestration pure : consomme les contrats
publics des plugins métier et le REST de `postelio-site` ; aucune table, aucune logique métier,
aucune écriture directe dans les données d'un autre plugin.

- **Tous les écrans** Postelio sont rendus ici : Tableau de bord, Mon site (Vue d'ensemble, Accueil,
  Navigation, Footer, Apparence, SEO, Offres, Entreprises, Savoir-faire, Conseils, Contact),
  Utilisateurs, Entreprises, Offres, Candidatures, Messagerie, Entretiens, Savoir-faire, Modération,
  Facturation, Sources d'offres, Réglages, Service e-mail, CV & fichiers, Santé, Favoris & Alertes.
  Le plugin historique `postelio-admin` a été supprimé.
- Routage : table `Menu::SCREENS` (slug wp-admin → classe d'écran). Les slugs sont ceux de l'ancien
  back-office : les favoris existants continuent de fonctionner.
- Actions : `Actions\Actions` (slugs `pst_admin_*` inchangés) — nonce, capacité, validation, puis
  délégation au service propriétaire ou à l'endpoint du domaine.
- Assets chargés uniquement sur les écrans Postelio ; version = `POSTELIO_BACKOFFICE_VERSION`.
- Documentation complète : `docs/backend/backoffice.md` (audit, architecture, matrice de migration,
  confidentialité, tests).

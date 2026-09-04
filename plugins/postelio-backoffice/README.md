# Postelio Backoffice

Couche **unique** d'administration wp-admin de Postelio. Orchestration pure : consomme les contrats
des plugins métier et le REST de `postelio-site` ; aucune table, aucune logique métier, aucune
écriture directe.

- Documentation complète : `docs/backend/backoffice.md` (audit du legacy, architecture, stratégie
  de compatibilité, écrans, pages restant à migrer).
- Écrans migrés : `Menu::MIGRATED` = Tableau de bord + toute la zone Mon site (Vue d'ensemble, Accueil,
  Navigation, Footer, Apparence, SEO, Offres, Entreprises, Savoir-faire, Conseils, Contact). Tout
  autre slug est rendu par `postelio-admin` (legacy) tant qu'il n'est pas migré.
- Assets chargés uniquement sur les écrans migrés ; version = `POSTELIO_BACKOFFICE_VERSION`.

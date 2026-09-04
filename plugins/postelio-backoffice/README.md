# Postelio Backoffice

Couche **unique** d'administration wp-admin de Postelio. Orchestration pure : consomme les contrats
des plugins métier et le REST de `postelio-site` ; aucune table, aucune logique métier, aucune
écriture directe.

- Documentation complète : `docs/backend/backoffice.md` (audit du legacy, architecture, stratégie
  de compatibilité, écrans, pages restant à migrer).
- Écrans migrés (Phase 1) : `Menu::MIGRATED` = Tableau de bord, Mon site / Vue d'ensemble, Mon site /
  Accueil. Tout autre slug est rendu par `postelio-admin` (legacy) tant qu'il n'est pas migré.
- Assets chargés uniquement sur les écrans migrés ; version = `POSTELIO_BACKOFFICE_VERSION`.

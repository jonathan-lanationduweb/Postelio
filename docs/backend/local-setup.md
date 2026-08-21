# Postelio — Environnement local WAMP (préparation)

Objectif : environnement local propre pour développer le back-office WordPress
**sans casser le front** ni l'historique Git. Ce document décrit ce qui a été
**préparé automatiquement** et les **étapes manuelles** restantes (celles qui
exigent les services WAMP démarrés ou une décision de mot de passe).

## État des lieux (constaté)

- **Dépôt Git :** `C:\Users\jonat\Desktop\postelio` (branche de travail
  `feature/core-architecture`, remote GitHub). Front à la racine + `docs/`.
- **WAMP :** `C:\wamp64` — Apache 2.4.65, PHP 8.0 → 8.5 (8.3.28 vérifié),
  MySQL 8.4.7 **et** MariaDB 11.4.9. WP-CLI **absent**.
- **`www` :** autres projets présents (info-devis, orvelle, showroomarchitectes…),
  **aucun Postelio** au départ. WordPress déjà utilisé sur d'autres projets.
- **Services :** Apache/MySQL **arrêtés** au moment de la préparation (ports 80/3306
  non à l'écoute) → aucune opération DB forcée.

## Architecture retenue

Plutôt qu'un déplacement destructif, on **expose le dépôt à WAMP via une jonction**
(le dépôt reste l'unique source Git) :

```
C:\wamp64\www\Postelio   ──(jonction)──►   C:\Users\jonat\Desktop\postelio  (le dépôt)
        └── index.html … (front, versionné)
        └── docs/                          (versionné)
        └── plugins/postelio-*             (squelettes de plugins, VERSIONNÉS)
        └── wordpress/                     (cœur WP, NON versionné, .gitignore)
              └── wp-content/plugins/postelio-*  ──(jonctions)──►  ../../../plugins/postelio-*
              └── wp-config.php             (NON versionné)
```

- Le **front** reste versionné et servi (`http://localhost/Postelio/`).
- Le **cœur WordPress** vit dans `wordpress/` (ignoré par Git).
- Nos **plugins** sont versionnés dans `/plugins/` et **reliés** dans
  `wordpress/wp-content/plugins/` par des jonctions → ils apparaissent dans WordPress
  tout en restant dans le dépôt.

## Préparé automatiquement (fait)

1. **Jonction** `C:\wamp64\www\Postelio` → dépôt (non destructif, réversible).
2. **`.gitignore`** : ignore `wordpress/`, `wp-config.php`, `.env`, uploads/cache/logs,
   clés/secrets ; conserve le front et `/plugins`.
3. **`/plugins/postelio-*`** : 12 squelettes de plugins (en-tête WordPress + `index.php` +
   `README.md`), **sans logique métier**.
4. **`wordpress/wp-content/plugins/`** créé + **12 jonctions** vers `/plugins/postelio-*`.
5. **`wp-config-local-sample.php`** : modèle non secret (DB `postelio_local`).

## Étapes manuelles restantes

> Nécessitent WampServer démarré (icône verte) — donc à exécuter par toi.

### 1. Installer le cœur WordPress dans `wordpress/`
Option A — **WP-CLI** (recommandé, à installer une fois) :
```bash
# depuis C:\wamp64\www\Postelio
wp core download --locale=fr_FR --path=wordpress
```
Option B — **manuel** : télécharger https://fr.wordpress.org/latest-fr_FR.zip,
dézipper, et copier le **contenu** du dossier `wordpress` obtenu dans
`C:\wamp64\www\Postelio\wordpress\` (ne pas écraser `wp-content/plugins/postelio-*`).

### 2. Créer la base de données dédiée
Via phpMyAdmin (`http://localhost/phpmyadmin`) **ou** en ligne de commande :
```sql
CREATE DATABASE postelio_local CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```
> **Ne pas réutiliser** la base d'un autre projet. Identifiants **non commités**.

### 3. Configurer WordPress
```bash
copy wp-config-local-sample.php wordpress\wp-config.php
```
Puis, dans `wordpress/wp-config.php` : vérifier `DB_*`, générer les **salts**
(https://api.wordpress.org/secret-key/1.1/salt/) et les coller. `wp-config.php`
est **ignoré par Git**.

### 4. URL locale
- **Simple (sans vhost)** : `http://localhost/Postelio/wordpress` (déjà prévu dans le
  sample). Front : `http://localhost/Postelio/`.
- **Propre (vhost `http://postelio.local`)** — optionnel :
  1. Ajouter un VirtualHost WAMP (menu WAMP ▸ *Vos VirtualHosts* ▸ *Gérer*) pointant
     `DocumentRoot C:/wamp64/www/Postelio`.
  2. Ajouter dans `C:\Windows\System32\drivers\etc\hosts` (droits admin) :
     `127.0.0.1  postelio.local`
  3. Adapter `WP_HOME`/`WP_SITEURL` dans `wp-config.php`.
  > Implication : le fichier `hosts` est global à la machine ; l'entrée redirige
  > `postelio.local` vers le poste local uniquement (réversible en la retirant).

### 5. Finaliser l'installation
Ouvrir l'URL → assistant WordPress (langue, titre « Postelio », compte admin), **ou** :
```bash
wp core install --path=wordpress --url=http://localhost/Postelio/wordpress \
  --title="Postelio" --admin_user=admin --admin_email=you@example.test --prompt=admin_password
```
Puis activer les plugins Postelio au fur et à mesure des lots
(`wp plugin activate postelio-core` d'abord).

## Vérifications finales (checklist)
- [ ] WampServer vert (Apache + MySQL démarrés).
- [ ] `http://localhost/Postelio/` affiche le **front**.
- [ ] `http://localhost/Postelio/wordpress` affiche WordPress.
- [ ] `wp-admin` accessible, base `postelio_local` connectée.
- [ ] Menu Extensions : les 12 `postelio-*` apparaissent (via jonctions).
- [ ] `git status` : `wordpress/` et `wp-config.php` **non** listés (ignorés).

## Notes
- Rien n'a été committé sur `main`. Les changements d'environnement sont sur
  `feature/core-architecture`.
- Les jonctions (`www/Postelio` et `wp-content/plugins/*`) sont **machine-locales** :
  non versionnées, à recréer sur une autre machine (voir ce document).
- Choix moteur SGBD : MySQL 8.4 **ou** MariaDB 11.4 (WAMP en propose les deux) — indiquer
  `DB_HOST=127.0.0.1:3306` sur celui démarré.

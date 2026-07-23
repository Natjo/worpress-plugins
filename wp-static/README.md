# WP Static

Génère des versions **statiques (HTML)** des pages de votre site WordPress et les sert à la place de WordPress pour améliorer les performances, tout en gardant le site **automatiquement à jour** lors des modifications de contenu.

---

## Sommaire

- [Fonctionnement général](#fonctionnement-général)
- [Installation](#installation)
- [Interface d'administration](#interface-dadministration)
- [Génération des pages](#génération-des-pages)
- [Service des pages statiques](#service-des-pages-statiques)
- [Mise à jour automatique](#mise-à-jour-automatique)
- [Notice « site à régénérer »](#notice--site-à-régénérer-)
- [Carte de dépendances (remontées / push / cards)](#carte-de-dépendances-remontées--push--cards)
- [Pages « non statiques » (exclusions)](#pages--non-statiques--exclusions)
- [Pagination et archives](#pagination-et-archives)
- [Compatibilité WPML (multilingue)](#compatibilité-wpml-multilingue)
- [Intégrations tierces](#intégrations-tierces)
- [Tâche planifiée (cron)](#tâche-planifiée-cron)
- [Constantes, filtres et hooks](#constantes-filtres-et-hooks)
- [Stockage des fichiers](#stockage-des-fichiers)
- [Notes de version](#notes-de-version)
- [Limites connues](#limites-connues)

---

## Fonctionnement général

1. Le plugin parcourt les URLs publiques du site (accueil, contenus, archives, taxonomies, paginations) et enregistre le HTML rendu dans `wp-content/static-pages/`.
2. Quand le site statique est **activé**, chaque requête front est interceptée très tôt : si un fichier statique existe pour l'URL demandée, il est servi directement (avec l'en-tête `X-Static-Cache: HIT`) et WordPress n'est pas exécuté.
3. À chaque modification de contenu, le plugin régénère automatiquement les pages impactées, ou affiche une notice invitant à régénérer le site pour les changements globaux.

---

## Installation

1. Placer le dossier `wp-static` dans `wp-content/plugins/`.
2. Activer **WP Static** dans l'administration WordPress (menu *Extensions*).
3. Un menu **WP Static** apparaît dans la barre latérale d'administration.

À l’activation/désactivation, les tâches planifiées sont créées/supprimées (voir
[Tâche planifiée](#tâche-planifiée-cron)). La désactivation coupe le service,
retire l’injection du contrôleur frontal et conserve les fichiers générés : elle
reste ainsi rapide et réversible, même avec un gros cache. Utilisez le bouton
**Vider le cache statique** si les fichiers doivent réellement être supprimés.

---

## Interface d'administration

Menu **WP Static** (`admin.php?page=wp-static-generator`). Elle contient trois sections.

### 1. Réglages

Un interrupteur **« Activer le site statique »** :

- **Activé** : les pages statiques générées sont servies à la place de WordPress lorsqu'elles existent.
- **Désactivé** : WordPress fonctionne normalement, aucun fichier statique n'est servi (le hook n'est même pas branché).

L'enregistrement est automatique au changement (AJAX), sans bouton.
Si le service est activé alors que le cache ne contient encore aucune page, une
notice propose d'accéder à la génération. Aucune génération n'est lancée
implicitement : WordPress continue à servir normalement le site jusqu'à ce que
l'administrateur utilise le bouton **Générer les pages statiques**.

Dans l'onglet **Paramètres**, un menu **« Mode de régénération »** propose trois modes :

- **Manuel** : **aucune** page n'est régénérée automatiquement et la tâche planifiée est suspendue. Toute modification marque le site « à régénérer » et affiche la **notice rouge** + l'indicateur rouge dans la barre d'admin. La mise à jour se fait via le bouton **Générer** ou les boutons **Régénérer** par ligne.
- **Automatique (ciblé)** *(par défaut)* : seules les **pages impactées** sont régénérées à chaque modification de contenu (comportement décrit dans [Mise à jour automatique](#mise-à-jour-automatique)). Recommandé pour la plupart des sites.
- **Complet** : l'**intégralité du site** est régénérée à **chaque** événement automatique qui affecte le front public (sauvegarde d'un contenu publié, publication, suppression, commentaire, terme…). Les boutons **Régénérer** par ligne restent ciblés. Simple et toujours à jour, idéal pour les **petits sites** au contenu peu changeant — à éviter sur les gros sites car le coût croît avec le nombre de pages.

> Les deux champs ci-dessous (URLs forcées et classes) ainsi que la colonne « Dépend de » du tableau ne s'affichent qu'en mode **Automatique (ciblé)** : ils sont inutiles en mode Manuel (rien d'auto) comme en mode Complet (tout est déjà régénéré).

En mode **Automatique (ciblé)**, deux champs permettent de couvrir les pages que la carte de dépendances ne peut pas deviner (accueil « magazine », plan du site, listing à requête personnalisée…). Les URLs « non statique »/exclues sont ignorées à la génération.

- **« URLs à toujours régénérer »** (une entrée par ligne) : régénérées **à chaque enregistrement de contenu**, en plus des pages détectées automatiquement. Chaque ligne peut être :
  - une **URL complète** du site (seules les URLs du même hôte sont conservées) ;
  - un **motif** avec joker `*` (ex. `/actualites/*`, `/actualites/page/*`), déployé contre les URLs connues du site.
- **« Classes déclenchant une régénération »** (une classe CSS par ligne, ex. `.remontees-auto`) : à la génération, toute page dont le HTML contient une de ces classes est **détectée automatiquement** et mémorisée. Ces pages sont ensuite régénérées à chaque enregistrement de contenu, **sans mapping manuel**. Idéal pour les remontées dynamiques « exotiques » (requêtes custom, sélections ACF…) que le hook `the_post` ne trace pas : il suffit d'ajouter la classe sur le wrapper du composant dans le thème. L'index se met à jour à chaque génération (si la page ne contient plus la classe, elle sort de l'index ; vider la liste de classes purge l'index).

Modifier cette liste purge l’index construit avec l’ancien réglage et marque le
site « à régénérer ». Si la modification intervient pendant une génération, une
reconstruction complète est automatiquement mise en attente.

Dans l'onglet **Paramètres**, une case **« Minification »** : si activée, les commentaires HTML sont retirés et les blancs entre balises sont ramenés à un seul espace. Cet espace est volontairement conservé pour ne pas coller deux éléments inline. Le contenu, les espaces éditoriaux, les balises `<pre>`, `<textarea>`, `<script>`, `<style>` et les commentaires conditionnels IE sont préservés.

La section **Génération avancée** ajoute :

- **Purger les fichiers orphelins** : après une génération complète, supprime les anciens fichiers `index.html` qui ne correspondent plus à une URL collectée (page supprimée, slug changé, type masqué, motif exclu…).
- **Régénération planifiée** : choix entre désactivée, deux fois par jour, quotidienne ou hebdomadaire. Elle est active dans les modes Automatique et Complet, suspendue en mode Manuel, et sa fréquence reste mémorisée lors d'un changement de mode.
- **Exclusions par motif** : un motif par ligne, avec `*` supporté (ex. `/feed/*`, `*/preview/*`, `https://example.com/private/*`). Les motifs sont testés sur l'URL complète et sur le chemin.

> L'interface est organisée en onglets : **Statique** (activation + génération + cache + tableau des pages), **Paramètres** (régénération auto, minification, génération avancée, htaccess préprod) et **Export** (téléchargement ZIP du site statique).

### Htaccess préprod (authentification Basic)

Cette section n'apparaît **que sur la préproduction** (constante `ENV_PREPROD_LONSDALE`). La préprod étant protégée par un `.htaccess`, renseignez l'**utilisateur** et le **mot de passe** : la génération les envoie en en-tête `Authorization: Basic` pour récupérer les pages.

En préproduction, la génération appelle directement l'**URL publique du site** (résolvable depuis le serveur) et **n'utilise pas** de service interne type `nginx`.

L’utilisateur **et** le mot de passe sont obligatoires. S’il en manque un :

- une notice d’erreur apparaît dans l’administration avec un lien vers les réglages ;
- un avertissement est affiché sur la page WP Static ;
- le bouton de génération complète est désactivé ;
- les générations manuelles, automatiques et planifiées sont bloquées proprement
  et le site reste marqué « à régénérer ».

Le mot de passe n’est jamais réaffiché dans le navigateur. Un champ vide
conserve la valeur enregistrée ; le bouton **Effacer les identifiants** supprime
explicitement l’utilisateur et le mot de passe stockés.

### 2. Génération

Un bouton **« Générer les pages statiques »** lance une génération complète du site et reconstruit la carte des dépendances. Une notice récapitule le nombre de pages générées / ignorées / en erreur.

Le bouton **« Vider le cache statique »** supprime tous les fichiers générés et vide la carte des dépendances. WordPress sert alors les pages dynamiquement jusqu'à la prochaine génération.

### 3. Pages

Un tableau listant toutes les URLs publiques avec :

| Colonne | Description |
|---|---|
| **Page** | Titre cliquable + chemin simplifié (ex. `/contact/`) |
| **Langue** | Affichée uniquement si WPML est actif |
| **Modèle** | Nom du modèle de page (ou « Par défaut ») |
| **Static** | Case cochée = page statique ; décochée = page exclue du statique |
| **Dernière génération** | Date du fichier statique |
| **Dépend de** | Contenus tracés sur la page |
| **Action** | Bouton **Régénérer** (par ligne, en AJAX) |

---

## Génération des pages

La génération récupère chaque URL via une requête HTTP interne (loopback) et écrit le fichier `index.html` correspondant.

- Les réponses **404** sont ignorées (pas de fichier créé).
- Les **redirections (301/302/307/308)** sont ignorées (une redirection n'a pas de contenu statique propre).
- Les réponses vides ou explicitement non HTML sont refusées sans remplacer une
  version statique valide.
- Les URLs avec une **query string** (`?page_id=…`, `?cat=…`) ou un fragment
  sont ignorées et restent dynamiques. Elles ne peuvent ainsi jamais écraser le
  fichier d’une URL propre partageant le même chemin, notamment la home.
- Les contenus dont le **type normalement visible au premier niveau a été retiré
  du menu d'administration** (ex. « Articles » masqué via
  `remove_menu_page('edit.php')`) ne sont **pas générés**. Un CPT public
  volontairement sans interface ou placé dans un sous-menu reste générable. La
  liste est mise en cache pour les générations hors écran d’administration.
- Les URLs qui correspondent à un **motif d'exclusion** ne sont pas générées et ne sont pas servies en statique.
- Si l'option **Purger les fichiers orphelins** est activée, les anciens fichiers `index.html` qui ne correspondent plus à la liste des URLs générables sont supprimés à la fin d'une génération complète.
- L'écriture est **atomique** : le nouveau HTML est préparé dans le même dossier
  puis renommé, afin qu'un visiteur ne puisse jamais recevoir un fichier
  partiellement écrit.
- Une seule génération, suppression globale ou export peut modifier/lire le
  cache à la fois. Les demandes concurrentes sont mises en attente sans supprimer
  le fichier de verrou entre deux opérations.
- En environnement local Docker, si l'appel au domaine public échoue, le plugin
  réessaie automatiquement via les services web internes `apache`, puis `nginx`,
  en HTTPS puis HTTP, tout en conservant l'en-tête `Host` public. Voir
  [Constantes](#constantes-filtres-et-hooks).
- Une réponse `5xx` déclenche également le fallback suivant ; une réponse
  fonctionnelle, une redirection ou une `404` reste la réponse définitive.

### Générations concurrentes et file d’attente

Lorsqu’une génération est déjà en cours :

- les URLs ciblées suivantes sont fusionnées et dédupliquées dans une file
  persistante ;
- une demande complète remplace les demandes ciblées devenues inutiles ;
- le lot est normalement repris dès la libération du verrou ;
- un événement WP-Cron unique, planifié environ une minute plus tard, garantit
  la reprise si la demande arrive juste après le dernier contrôle ;
- si une exception interrompt le traitement, le lot est restauré avant la
  libération du verrou et le site reste marqué « à régénérer ».

L’administration affiche le statut « En attente » pour les URLs présentes dans
la file. Si WP-Cron est désactivé, les reprises immédiates continuent de
fonctionner, mais il est recommandé de déclencher régulièrement `wp-cron.php`
pour couvrir le cas de course résiduel.

---

## Service des pages statiques

Quand l'option est activée, `wp_static_serve_static_page()` est branché sur `init` (priorité 1) :

- Ignore l'admin, la recherche et les requêtes non-GET.
- Ne sert jamais une page en cours de génération (rendu « frais » garanti).
- Ne sert jamais une page marquée **« non statique »**.
- Sert le fichier correspondant à l'URL demandée avec l'en-tête `X-Static-Cache: HIT`.
- **Validation du chemin** : le fichier servi est vérifié via `realpath()` pour garantir qu'il se trouve bien sous `WP_STATIC_DIR` (protection contre la traversée de chemin).
- **En-têtes de cache** : `Last-Modified`, `ETag` et `Cache-Control` sont envoyés ; les requêtes conditionnelles (`If-None-Match` / `If-Modified-Since`) reçoivent une réponse **304** sans retélécharger la page.

### Service « pré‑WordPress » (injection dans index.php)

Pour le **maximum de rapidité**, l'activation du site statique **injecte un petit bloc en tête de `index.php`** (le contrôleur frontal). Ce bloc sert le fichier statique correspondant **avant même de charger WordPress** — pas de bootstrap WP, pas de base de données.

Le bloc injecté est volontairement **minimal** : il se contente d'inclure le service autonome du plugin (`pre-wp-cache.php`) et d'appeler sa fonction `wp_static_serve_pre_wp()`. **Toute la logique reste dans le plugin** (et peut donc évoluer sans réécrire `index.php`) :

```php
/* wp-static-cache:start */
$wpsc_service = __DIR__ . '/' . 'wp-content/plugins/wp-static/pre-wp-cache.php';
if (is_file($wpsc_service)) {
    require_once $wpsc_service;
    if (function_exists('wp_static_serve_pre_wp')) {
        wp_static_serve_pre_wp(__DIR__ . '/' . 'wp-content/static-pages');
    }
}
/* wp-static-cache:end */
```

- Activé via le toggle **« Activer le site statique »** : le bloc est inséré entre les marqueurs `/* wp-static-cache:start */` … `/* wp-static-cache:end */`.
- Désactivé : le bloc est retiré automatiquement (et aussi à la **désactivation du plugin**).
- `pre-wp-cache.php` est **autonome** (aucune dépendance à WordPress) puisqu'il s'exécute avant le chargement de WP.
- Les pages servies par ce bloc portent l'en-tête `X-Static-Cache: HIT-PRE-WP`.

Garde-fous du bloc injecté :

- uniquement les requêtes **GET**, **sans chaîne de requête** ;
- jamais pour `/wp-admin`, `/wp-login`, `/wp-json`, `/wp-cron`, `/xmlrpc` ;
- jamais pour un **utilisateur connecté** ou une page personnalisée par un cookie
  (`wordpress_logged_in`, `comment_author`, `wp-postpass`), aussi bien dans le
  service pré-WordPress que dans son fallback WordPress ;
- jamais pendant une **génération** lorsque l’en-tête `X-WP-Static-Token`
  correspond au jeton temporaire privé écrit par le plugin ; un en-tête forgé
  ne force plus le chargement de WordPress ;
- **protection contre la traversée de chemin** (`realpath()` confiné au dossier statique) ;
- en-têtes de cache `Last-Modified` / `ETag` + réponse **304**.

> Les pages **« non statiques »** (exclues) n'ont pas de fichier généré : le bloc ne trouve rien et laisse WordPress répondre. Le service sur `init` reste actif comme **filet de secours** (en-tête `X-Static-Cache: HIT`) si l'injection n'a pas pu écrire `index.php` (droits) ou si un fichier a été ajouté hors injection.

> ⚠️ Si `index.php` est régénéré par un déploiement (Composer/Bedrock, réinstallation du cœur), réactivez le site statique pour réinsérer le bloc.

> **Encore plus en amont.** Pour ne pas exécuter PHP du tout, vous pouvez aussi servir le statique directement au niveau **nginx**/`.htaccess` (voir ci‑dessous) — utile derrière un CDN ou pour des pics de charge.

---

## Servir le statique avant PHP (nginx / Apache)

Le service PHP décrit ci‑dessus économise le rendu mais charge quand même WordPress. Pour servir les fichiers **sans démarrer PHP**, faites pointer le serveur web vers le dossier `wp-content/static-pages`. Les fichiers y sont rangés en miroir de l'URL : `/contact/` → `wp-content/static-pages/contact/index.html`.

> Important : ces règles servent le statique à **tout le monde**. Pour éviter de servir une page en cache aux utilisateurs connectés ou aux requêtes POST, on restreint au `GET`, hors `wp-admin`, sans chaîne de requête et sans cookie de connexion. C'est un point de départ à adapter.

### nginx

```nginx
# Dans le server { } du site, AVANT le « location / » existant.
set $wpstatic "";
if ($request_method = GET)            { set $wpstatic "G"; }
if ($args = "")                       { set $wpstatic "${wpstatic}A"; }
if ($http_cookie !~* "wordpress_logged_in") { set $wpstatic "${wpstatic}C"; }

location / {
    # Sert le fichier statique seulement si les 3 conditions sont réunies (GAC).
    if ($wpstatic = "GAC") {
        rewrite ^ /wp-content/static-pages$uri/index.html last;
    }
    try_files $uri $uri/ /index.php?$args;
}

# Ne jamais servir l'admin en statique.
location ^~ /wp-admin/ {
    try_files $uri $uri/ /index.php?$args;
}
```

### Apache (`.htaccess`)

```apache
<IfModule mod_rewrite.c>
RewriteEngine On

# Statique uniquement : GET, sans query string, hors admin, non connecté.
RewriteCond %{REQUEST_METHOD} =GET
RewriteCond %{QUERY_STRING} ^$
RewriteCond %{REQUEST_URI} !^/wp-admin/
RewriteCond %{HTTP_COOKIE} !wordpress_logged_in [NC]
# Le fichier statique correspondant existe ?
RewriteCond %{DOCUMENT_ROOT}/wp-content/static-pages%{REQUEST_URI}/index.html -f
RewriteRule ^ /wp-content/static-pages%{REQUEST_URI}/index.html [L]
</IfModule>
```

> En mode **WPML multi‑domaines**, les fichiers sont rangés sous `static-pages/_hosts/<hôte>/…` : adaptez le chemin (`/wp-content/static-pages/_hosts/$host$uri/index.html`).

---

## Export (téléchargement ZIP)

L'onglet **Export** permet de télécharger une archive ZIP autonome du site statique. Il nécessite l'extension PHP `ZipArchive` et qu'au moins une génération ait eu lieu.

Options :

| Option | Description |
|---|---|
| **Uploads** (toggle) | Inclut ou non les médias de `wp-content/uploads` dans l'archive. |
| **Dossier de base** (champ) | Dossier racine du site dans l'archive **et** base des liens. `/` pour la racine, `test/` pour un sous-dossier. |
| **Exporter** (bouton) | Génère et télécharge le ZIP. |

L'archive contient :

- les **pages statiques** générées (HTML) ;
- les **assets** du thème actif (et du thème parent), sous `wp-content/themes/<thème>/assets/` ;
- les **uploads** sous `wp-content/uploads/` (si l'option est cochée).

Dans les fichiers HTML, les **URLs absolues du site** (`https://host/…`, `http://host/…`, `//host/…`) sont réécrites vers le dossier de base en chemins racine-relatifs (`/` ou `/test/`), pour que l'export fonctionne tel quel une fois déposé sur l'hébergement.

En WPML multidomaine, cette réécriture couvre tous les domaines de langue
actifs, et pas seulement le domaine principal.

Comportements complémentaires :

- **Dossier mémorisé** : le dossier de base est enregistré (option `wp_static_export_folder`) et pré-rempli au prochain export.
- **Loader** : le bouton **Exporter** affiche un spinner pendant la construction de l'archive ; il se réactive dès que le téléchargement démarre (détection via un cookie de fin de génération).
- **Vérification d'espace disque** : avant de construire le ZIP, le plugin estime la taille des sources (+ 10 % de marge) et la compare à `disk_free_space()` du dossier temporaire. Si l'espace est insuffisant, l'export est annulé avec un message explicite (rien n'est écrit).
- **Parcours unique** : la liste et la taille des fichiers sont collectées dans
  un manifeste réutilisé pour construire le ZIP ; les sources et les uploads ne
  sont plus parcourus deux fois.
- **Suppression du ZIP** : l'archive est créée dans le dossier temporaire système, supprimée juste après l'envoi, **et** via un `register_shutdown_function` (filet de sécurité en cas d'abandon/erreur, avec `ignore_user_abort`). Au lancement d'un export, les archives orphelines de plus d'une heure sont aussi nettoyées.

---

## Mise à jour automatique

À l'enregistrement d'un contenu publié (`save_post`), le plugin prépare la régénération
pour la **fin de requête**. En mode Automatique, il cible :

- la page du contenu modifié ;
- ses **remontées connues** (carte de dépendances) ;
- ses **listings naturels** : accueil, archive de son type de contenu, archives de ses taxonomies, et leurs paginations ;
- pour un contenu **hiérarchique** (page, CPT hiérarchique) : la **page parente et ses ancêtres** (qui affichent souvent une liste de leurs enfants).

En mode Complet, ces calculs ciblés sont ignorés : une seule demande complète est
mémorisée pour la requête et la liste des URLs n'est collectée qu'au moment de
son traitement.

> **Nouveau contenu et listings personnalisés.** La carte de dépendances ne peut pas connaître un contenu **jamais rendu** : pour une page fraîchement créée, seuls l'accueil, ses ancêtres et la page elle‑même sont régénérés automatiquement. Si une page de **listing personnalisé** (plan du site, navigation de section, push de pages…) doit aussi se mettre à jour, déclarez ses URLs via le filtre [`wp_static_listing_urls_for_post`](#filtres). À défaut, le **cron quotidien** rattrape le changement.

Autres événements gérés automatiquement :

| Événement | Action |
|---|---|
| Publication (y compris article **programmé** via cron) | Génère la page + listings |
| Dépublication (publié → brouillon/privé) | **Supprime** le fichier statique + régénère les listings |
| Mise à la corbeille / suppression | Supprime le fichier + régénère les listings |
| Changement de **slug** ou de parent | Supprime l'ancienne URL (fichier orphelin) |
| **Commentaire** ajouté / édité / modéré / supprimé | Régénère la page de l'article |
| Échec d'une régénération automatique | Marque le site « à régénérer » (notice) |

Les brouillons, révisions et sauvegardes sans changement de statut public ne
déclenchent aucune génération.

---

## Notice « site à régénérer »

Certains changements ont un impact **global** non devinable. Dans les modes
Manuel et Automatique, le plugin pose alors un drapeau et affiche une notice
d'administration invitant à régénérer le site. En mode Complet, ils déclenchent
directement une génération complète. Déclencheurs :

- modification d'un **menu** ;
- changement de **permaliens** (structure, base catégorie/étiquette) ;
- réglages de lecture (page d'accueil, page des articles, affichage) ;
- **widgets** ;
- titre / slogan du site, **URL du site** (`siteurl` / `home`) ;
- création / édition / suppression d'un **terme** (catégorie, étiquette, taxonomie) ;
- **bloc réutilisable** (`wp_block`) modifié ;
- page **d'options ACF** enregistrée ;
- réglages globaux **Yoast SEO** (général, titres & métas, réseaux sociaux) ;
- **régénération des miniatures** (plugin Regenerate Thumbnails).

Le drapeau est effacé après une génération complète.

La notice apparaît **en rouge**. Un indicateur **WP Static** est aussi présent dans la **barre d'administration** (header) : vert = service actif, gris = service désactivé, **rouge = régénération nécessaire**.

---

## Carte de dépendances (remontées / push / cards)

Pendant la génération, le plugin identifie quels contenus apparaissent sur chaque page afin de pouvoir, plus tard, régénérer précisément les pages impactées par une modification.

Une génération complète construit une nouvelle carte sans effacer l’ancienne au
préalable. Une URL générée avec succès reçoit ses nouvelles dépendances ; une URL
en erreur conserve les précédentes ; les URLs qui ne font plus partie du site
sont retirées. L’index des pages contenant une classe marqueur suit exactement
la même règle et n’est mis à jour qu’après l’écriture réussie du nouveau HTML.

La détection automatique repose sur le hook WordPress `the_post` : toute boucle standard (`WP_Query` + `the_post()` / `setup_postdata()`) est tracée.

### Composants personnalisés : `wp_static_register_dependency()`

Si un composant « push / cards / remontées » récupère des contenus **sans** boucle WordPress standard (sélection d'articles ACF, `get_posts()` sans `setup_postdata()`, requête SQL custom…), il ne sera pas détecté automatiquement. Déclarez alors la dépendance manuellement :

```php
wp_static_register_dependency( $post_id );
// ou un tableau d'IDs / d'objets WP_Post
wp_static_register_dependency( array( 12, 34, 56 ) );
```

- L'appel est **sans effet hors d'une génération WP Static** : vous pouvez l'appeler en permanence dans le thème sans risque ni surcoût.
- Accepte un ID, un objet `WP_Post`, ou un tableau de ceux-ci.

**Exemple dans un composant de remontée ACF :**

```php
$selection = get_field( 'articles_mis_en_avant' ); // tableau d'IDs ou de posts
foreach ( $selection as $item ) {
    $id = is_object( $item ) ? $item->ID : (int) $item;

    // Déclare la dépendance pour la régénération statique.
    wp_static_register_dependency( $id );

    // … rendu de la carte (titre, image, lien) …
}
```

Ainsi, la page contenant cette remontée sera automatiquement régénérée lorsqu'un des articles sélectionnés sera modifié.

### Alternative « zéro PHP » : classes marqueurs

Pour les composants à remontée dynamique, on peut **éviter tout code PHP** en déclarant une ou plusieurs **classes CSS marqueurs** dans **Paramètres → URLs à toujours régénérer → « Classes déclenchant une régénération »** (ex. `.remontees-auto`). À la génération, toute page dont le HTML contient une de ces classes est détectée et mémorisée ; elle est ensuite régénérée à **chaque** enregistrement de contenu (mode auto). Il suffit d'ajouter la classe sur le wrapper du composant dans le thème.

### Quelle approche choisir ? (performance)

L'écart de performance se joue **à la sauvegarde** d'un contenu : combien de pages sont régénérées.

| Critère | Boucle standard (`the_post`) / `wp_static_register_dependency()` | Classes marqueurs (textarea) |
|---|---|---|
| **Granularité** | **Précise** : « cette page dépend des contenus #12, #45 » | **Globale** : « cette page a une remontée » |
| **Déclenche une régénération** | Seulement quand **#12 ou #45** est modifié | À **chaque** sauvegarde de **n'importe quel** contenu |
| **Nombre de régénérations** | Minimal (ciblé) | Sur-régénération (toutes les pages flaguées, à chaque save) |
| **Coût à la génération** | Négligeable (marqueur HTML) | Scan regex du HTML de chaque page (léger) |
| **Effort développeur** | Boucle standard = nul ; `register_dependency()` = un appel PHP | Une classe CSS |

**Recommandation, par ordre de préférence :**

1. **Boucle WordPress standard** (`WP_Query` + `the_post()` / `setup_postdata()`) → **rien à faire**, dépendance tracée automatiquement et précise. Le plus performant **et** sans effort.
2. **Boucle non standard** (SQL custom, `get_posts()` sans `setup_postdata()`, ACF…) et perf recherchée → **`wp_static_register_dependency()`** : ciblé, donc moins de régénérations.
3. **Classes marqueurs** → pratique (zéro PHP) mais **régénère large**. À réserver aux cas où peu de pages sont flaguées, ou aux remontées qui dépendent de presque tout (type « derniers articles » où n'importe quel article peut apparaître — une régénération large y est de toute façon légitime).

> En clair : **classes = simple mais large**, **boucle standard / `register_dependency()` = ciblé et plus performant**.

---

## Pages « non statiques » (exclusions)

Dans le tableau, la case **« Non statique »** d'une ligne permet d'exclure une page :

- **Cochée** : le fichier statique est supprimé, la page n'est plus générée et est **servie dynamiquement par WordPress** (utile pour les pages à contenu dynamique : formulaires, espace connecté…).
- **Décochée** : la page est réintégrée et régénérée immédiatement.

Les exclusions sont conservées (option) et comparées de façon robuste (hôte + chemin, indépendamment du slash final ou du protocole).

---

## Pagination et archives

La génération couvre automatiquement :

- l'accueil et la page des articles ;
- les contenus (articles, pages, CPT publics) ;
- les **archives de type de contenu** (`/actualites/`) ;
- les **archives de taxonomies** (catégories, étiquettes, taxonomies personnalisées) ;
- les **pages paginées** de ces archives (`/actualites/page/2/`, `/category/xxx/page/3/`…) dès qu'il y a plus d'éléments que `posts_per_page`.

---

## Compatibilité WPML (multilingue)

Si **WPML** est actif, le plugin :

- parcourt **chaque langue** pour collecter accueils, contenus, archives, taxonomies et paginations ;
- affiche une colonne **Langue** dans le tableau ;
- en mode **domaines / sous-domaines** par langue, range les fichiers par hôte pour éviter les collisions ;
- utilise la langue du contenu modifié pour cibler les bons listings lors d'une régénération automatique.

La collecte des contenus travaille par lots de 500 IDs et libère les caches
temporaires entre les lots lorsqu’aucun cache objet persistant n’est utilisé.
La pagination des taxonomies repose sur le compteur de chaque terme, ce qui
évite une requête de comptage par archive. Le filtre
`wp_static_term_max_pages` permet de remplacer ce calcul lorsqu’un thème modifie
fortement la requête d’archive.

Si WPML est absent, le comportement reste inchangé.

---

## Intégrations tierces

- **Regenerate Thumbnails** : une régénération de miniatures marque le site « à régénérer » (les tailles d'images et `srcset` changent). Un simple upload média ne déclenche rien.
- **Yoast SEO** : les métas SEO par contenu sont couvertes par `save_post` ; les **réglages globaux** Yoast déclenchent la notice.
- **ACF** : l'enregistrement d'une **page d'options** déclenche la notice.

---

## Tâche planifiée (cron)

L'événement `wp_static_daily_regeneration` régénère intégralement le site comme filet de sécurité pour rattraper tout changement non détecté (imports SQL directs, WP-CLI, etc.). Sa fréquence est configurable dans **Paramètres → Génération avancée** : désactivée, deux fois par jour, quotidienne ou hebdomadaire. Il est planifié dans les modes Automatique et Complet, suspendu en mode Manuel, puis retiré à la désactivation du plugin.

---

## Constantes, filtres et hooks

### Constantes

| Constante | Rôle | Défaut |
|---|---|---|
| `WP_STATIC_DIR` | Dossier de stockage des pages statiques | `wp-content/static-pages` |
| `WP_STATIC_INTERNAL_HOST` | Hôte interne prioritaire pour les requêtes de génération Docker (`hôte` ou `hôte:port`) | `apache`, puis `nginx`, si `ENV_LOCAL` |

```php
// Exemple : essayer d'abord un service interne personnalisé.
define( 'WP_STATIC_INTERNAL_HOST', 'mon-service-web' );
```

### Filtres

| Filtre | Description |
|---|---|
| `wp_static_internal_host` | Surcharge le ou les hôtes internes de génération (chaîne ou tableau ordonné) |
| `wp_static_url_items` | Modifie la liste des URLs (avec métadonnées) à générer |
| `wp_static_urls` | Modifie la liste finale des URLs à générer |
| `wp_static_listing_urls_for_post` | Déclare des URLs de listing supplémentaires à régénérer quand un contenu est ajouté / modifié / supprimé |
| `wp_static_term_max_pages` | Modifie le nombre de pages calculé pour une archive de terme |

```php
// Exemple : régénérer le plan du site dès qu'une page est ajoutée/modifiée
add_filter( 'wp_static_listing_urls_for_post', function ( $urls, $post_id ) {
    if ( get_post_type( $post_id ) === 'page' ) {
        $urls[] = home_url( '/plan-du-site/' );
    }
    return $urls;
}, 10, 2 );
```

```php
// Exemple : ajouter une URL personnalisée à générer
add_filter( 'wp_static_urls', function ( $urls ) {
    $urls[] = home_url( '/page-virtuelle/' );
    return $urls;
} );
```

### Fonction publique

| Fonction | Description |
|---|---|
| `wp_static_register_dependency( $post_ids )` | Déclare une dépendance de contenu pour la page en cours de génération (voir [Carte de dépendances](#carte-de-dépendances-remontées--push--cards)) |

---

## Stockage des fichiers

- Les pages sont stockées dans `WP_STATIC_DIR` (par défaut `wp-content/static-pages/`), en miroir de l'arborescence des URLs : `…/contact/index.html`.
- En multi-hôtes (WPML domaines), les fichiers sont rangés sous `…/_hosts/<hôte>/…`.
- Dès que cet arbre multi-hôtes existe, un domaine sans fichier propre ne retombe
  jamais sur la version racine d'une autre langue.
- Les fichiers `wp-static-generating.lock` et `wp-static-pending.lock`, placés
  directement dans `wp-content`, sérialisent respectivement les opérations sur
  le cache et les mises à jour de la file d'attente. Ils sont persistants mais
  ne contiennent aucune donnée sensible.
- La désactivation conserve ce dossier mais désactive le service et les tâches.
  La suppression explicite s’effectue depuis **Vider le cache statique**.

---

## Notes de version

### 1.2.0 — 23 juillet 2026

- Réduction du coût des générations complètes : collecte paginée des contenus et
  des termes, parcours unique du manifeste d'export et limitation des données
  conservées en mémoire.
- Export de tous les domaines actifs avec WPML et isolation stricte des fichiers
  statiques entre les hôtes.
- Compatibilité renforcée avec Apache, Nginx et Docker grâce aux hôtes loopback
  de secours et à la conservation de leur ordre de priorité.
- Sécurisation du contrôleur frontal chargé avant WordPress : validation par
  jeton, absence de contournement possible par un en-tête forgé et écritures
  atomiques.
- Transmission de l'authentification Basic uniquement à l'hôte autorisé, sans
  propagation lors des redirections.
- Désactivation rapide et réversible : le service et les tâches sont arrêtés,
  tandis que le cache reste disponible jusqu'à sa suppression explicite.
- Cohérence des modes Manuel, Automatique et Complet : cron réellement suspendu
  en mode Manuel, actions par ligne toujours ciblées et collecte complète
  reportée au traitement de la file.

### 1.1.1 — 23 juillet 2026

- Ajout d'une file d'attente persistante pour les régénérations demandées
  pendant une génération déjà active.
- Fusion et déduplication des demandes ciblées ; une demande complète remplace
  automatiquement les demandes partielles devenues inutiles.
- Reprise de la file par WP-Cron après une interruption et affichage de son état
  dans l'administration.
- Mise à jour transactionnelle des dépendances et de l'index des classes
  dynamiques : les anciennes données restent valides si une page échoue.
- Une modification de configuration pendant une génération programme
  automatiquement une reconstruction complète.

### 1.1.0 — 23 juillet 2026

- Blocage des traversées de chemin et validation renforcée des URLs, hôtes et
  destinations d'écriture.
- Verrous séparés pour la génération et la file d'attente, avec écritures
  atomiques des pages statiques.
- Gestion plus robuste des réponses vides, non HTML, redirigées ou en erreur ;
  les détails enregistrés sont bornés pour protéger la mémoire.
- Conservation du rendu WordPress pour les visiteurs privés ou authentifiés et
  priorité correcte des en-têtes de cache (`ETag`, `Last-Modified`).
- Amélioration des exclusions, des commentaires, des archives, des types de
  contenus masqués et des environnements protégés par authentification Basic.
- Ajout de tests de contrat pour les scénarios critiques de génération et de
  service des fichiers.

### 1.0.0

- Première version : génération du HTML, activation du service statique,
  régénérations manuelles ou automatiques, exclusions, dépendances et
  compatibilité WPML.

---

## Limites connues

- **Contenu dynamique par requête** : les formulaires utilisant un **nonce** figé dans le HTML statique peuvent voir ce nonce expirer. Gérez ces cas via un rafraîchissement AJAX du nonce, ou marquez la page **« non statique »**.
- **Détection des remontées** : seules les boucles standard (`the_post`) sont tracées automatiquement. Pour les composants personnalisés, utilisez `wp_static_register_dependency()`.
- **Requêtes de génération** : la génération s'appuie sur des requêtes HTTP loopback ; l'environnement doit pouvoir s'auto-appeler (géré automatiquement en local Docker via le service interne).

# WP CDN

Synchronise les assets du site (dossier `uploads` + assets du thème) vers **Cloudflare R2** et réécrit automatiquement leurs URLs vers un domaine CDN dans les pages statiques générées par **WP Static**.

## Fonctionnement

1. Les fichiers d'`uploads` et du thème (parent + enfant) sont téléversés vers un bucket Cloudflare R2 via l'API S3‑compatible (signature AWS SigV4).
2. Un domaine public (custom domain Cloudflare lié au bucket) sert ces fichiers depuis le réseau CDN.
3. À la génération des pages statiques, WP CDN réécrit les URLs des assets vers ce domaine CDN, déchargeant ainsi l'origine.

Les clés sont organisées dans le bucket de la façon suivante :

| Source                                   | Clé R2 / URL CDN                         |
| ---------------------------------------- | ---------------------------------------- |
| `wp-content/uploads/img.webp`            | `uploads/img.webp`                       |
| `wp-content/themes/mon-theme/style.css`  | `theme/mon-theme/style.css`              |

## Prérequis

- Un compte Cloudflare avec **R2** activé.
- L'extension PHP cURL (utilisée par `wp_remote_request`).
- Le plugin **WP Static** pour la réécriture dans les pages statiques (filtre `wp_static_html`).

## Configuration Cloudflare

1. Créer un **bucket R2**.
2. Générer des **API tokens R2** : Access Key ID + Secret Access Key.
3. Lier un **custom domain** au bucket (ex. `cdn.exemple.com`) pour l'accès public.
4. Récupérer l'**Account ID** Cloudflare.

## Configuration du plugin

Menu **WP CDN** dans l'administration :

| Champ                  | Description                                                        |
| ---------------------- | ------------------------------------------------------------------ |
| Activer le CDN         | Active la réécriture des URLs d'assets dans les pages statiques.   |
| Account ID             | Identifiant de compte Cloudflare.                                  |
| Access Key ID          | Clé d'accès du token R2.                                           |
| Secret Access Key      | Clé secrète du token R2 (laisser vide pour conserver l'actuelle).  |
| Bucket R2              | Nom du bucket.                                                     |
| URL publique du CDN    | Domaine public lié au bucket (ex. `https://cdn.exemple.com`).      |
| Assets à synchroniser  | `uploads` et/ou assets du thème.                                  |

## Utilisation

1. Renseigner les identifiants R2 et enregistrer.
2. Cliquer sur **Tester la connexion R2** pour valider l'accès au bucket.
3. Cliquer sur **Synchroniser les assets vers R2** (traitement par lots avec barre de progression).
4. Activer le CDN, puis **régénérer les pages statiques** (WP Static) pour appliquer la réécriture des URLs.

### Synchronisation

- Le manifeste des fichiers est construit puis traité par lots de 20 (configurable via `WP_CDN_SYNC_BATCH`).
- Les fichiers de plus de 50 Mo sont ignorés.
- Les échecs sont listés en fin de traitement (clé + message d'erreur).

### Extensions synchronisées

Images (`jpg`, `jpeg`, `png`, `gif`, `webp`, `svg`, `avif`, `ico`), styles/scripts (`css`, `js`, `mjs`, `map`), polices (`woff`, `woff2`, `ttf`, `otf`, `eot`) et données (`json`, `xml`, `txt`).

## Réécriture des URLs

La réécriture s'effectue via le filtre `wp_static_html` (exposé par WP Static), **avant la minification**. Seules les pages statiques générées sont concernées : le front WordPress dynamique reste inchangé.

## Détails techniques

| Constante                 | Rôle                                              |
| ------------------------- | ------------------------------------------------- |
| `WP_CDN_OPTION`           | Clé d'option des réglages.                         |
| `WP_CDN_SYNC_TRANSIENT`   | Transient stockant le manifeste pendant la sync.   |
| `WP_CDN_SYNC_BATCH`       | Taille de lot de synchronisation (défaut : 20).    |

- Authentification R2 : signature **AWS Signature Version 4** (région `auto`, service `s3`).
- Le secret n'est jamais ré‑affiché : le champ reste vide et n'écrase la valeur stockée que s'il est renseigné.

## Limites / pistes d'évolution

- Pas (encore) de synchronisation automatique à l'upload / suppression d'un média.
- Pas (encore) de purge du cache Cloudflare à la régénération.

## Réécriture en mode dynamique (front WordPress)

Le filtre `wp_static_html` ne s'applique qu'aux **pages statiques** générées par WP Static. Pour que le CDN fonctionne aussi sur le **front WordPress dynamique** (uploads, assets du thème et fichiers CSS/JS enqueued), plusieurs points d'intégration ont été ajoutés. Tous sont **conditionnels** : si le plugin WP CDN est désactivé ou le CDN inactif, les URLs locales d'origine sont conservées et rien ne casse.

### 1. Fonction publique `wp_cdn_url()` (plugin)

`wp-cdn.php` expose `wp_cdn_url($url)` qui convertit une URL locale connue (uploads ou thème) vers son équivalent CDN, selon la même structure de clés que la synchronisation (`uploads/...`, `theme/<nom-du-theme>/...`). Toute URL hors périmètre (cœur WordPress, plugins, domaine externe) est retournée inchangée.

### 2. Assets enqueued (CSS / JS du thème)

Le plugin branche les filtres WordPress `style_loader_src` et `script_loader_src` (front uniquement, hors admin) :

```php
add_filter('style_loader_src', 'wp_cdn_rewrite_enqueued_src', 20);
add_filter('script_loader_src', 'wp_cdn_rewrite_enqueued_src', 20);
```

Les feuilles de style et scripts du thème enregistrés via `wp_enqueue_style` / `wp_enqueue_script` sont ainsi servis depuis le CDN. Les assets du cœur WordPress et des plugins restent en local (non synchronisés). Le paramètre de version (`?ver=...`) est conservé.

### 3. Helper `cdn()` côté thème

Un helper sûr est défini dans le thème (`functions.php`), avant les autres constantes :

```php
if (!function_exists('cdn')) {
    function cdn($url) {
        return function_exists('wp_cdn_url') ? wp_cdn_url($url) : $url;
    }
}
```

Il est utilisé aux endroits qui produisent des URLs d'images, pour couvrir les `uploads` en dynamique :

- `inc/methods.php` → `lsd_get_thumb()` : l'URL d'image retournée passe par `cdn()`.
- `front/methods.php` → `hasWebp()` : l'URL `.webp` est convertie **après** la vérification d'existence du fichier local (l'ordre est important : on teste le fichier en local, puis on réécrit en sortie).
- `template-parts/components/picture.php` : les `src`, `srcset` et sources WebP passent par `cdn()`, ce qui couvre aussi les URLs passées directement au composant.

### 4. Constante `THEME_ASSETS`

Dans `functions.php`, la constante est enveloppée par `cdn()` pour que tous les templates qui l'utilisent pointent vers le CDN :

```php
define('THEME_ASSETS', cdn(get_template_directory_uri() . '/assets/'));
```

### Couverture résultante

| Contexte | Mécanisme | Couverture |
| --- | --- | --- |
| Pages statiques (WP Static) | Filtre `wp_static_html` (regex sur tout le HTML) | Complète : uploads, contenu, `srcset`, enqueued, URLs en dur |
| Front dynamique | `cdn()` + filtres enqueued | Uploads via `lsd_get_thumb`/`hasWebp`/`picture`, assets enqueued, `THEME_ASSETS` |

En dynamique, ne sont pas automatiquement réécrits : les images insérées dans le contenu (`the_content`), les `srcset` générés par le cœur de WordPress et les URLs codées en dur — ajouter des appels à `cdn()` ou des filtres dédiés si nécessaire.

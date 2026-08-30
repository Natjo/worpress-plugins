# WP WebP

Plugin WordPress qui génère une version WebP de chaque image téléversée (original + toutes les déclinaisons `add_image_size`), compatible **Regenerate Thumbnails**.

Conversion via **Imagick**, avec profils de qualité et détection automatique des images graphiques.

## Prérequis

- PHP avec l’extension **Imagick**
- ImageMagick avec support du format **WebP**

## Utilisation

1. **Réglages → WP WebP** : choisir un profil de qualité (Best / Optimal / Green).
2. Activer ou désactiver la génération WebP par format d’image enregistré.
3. **Générer les WebP** : régénère toute la médiathèque (original + formats activés), un attachement et toutes ses déclinaisons par requête AJAX. Le bouton devient **Pause**, puis **Reprendre**, sans perdre la progression.
4. Les WebP sont aussi créés automatiquement à l’**upload** et via **Regenerate Thumbnails**.

Convention de nommage : `photo.jpg` → `photo.webp` (l’extension est remplacée, pas ajoutée).
Le plugin crée les fichiers ; leur diffusion est assurée dans ce starter par le
helper `lsd_image_webp_src()` et les composants `image` / `picture`, qui ne
référencent le WebP que lorsqu’il existe.

Le WebP pleine taille est redimensionné proportionnellement lorsque son côté le
plus long dépasse **2500 px**. Ce plafond concerne uniquement le WebP : le
JPG/PNG source n’est pas modifié et les déclinaisons conservent les dimensions
et cadrages calculés par WordPress. Le filtre `wp_webp_max_full_dimension`
permet d’ajuster cette limite (`0` la désactive).

La désactivation d’un format supprime progressivement ses anciens WebP par lots
de 100 attachements. La suppression globale parcourt `uploads` par lots de
250 entrées et conserve toujours les médias WebP téléversés directement ainsi
que leurs déclinaisons.

## WordPress et ACF

- un upload effectué depuis un champ image ACF utilise la médiathèque WordPress
  et déclenche la même génération WebP qu’un upload depuis **Médias** ;
- les champs image du projet stockent et retournent l’ID de l’attachement ;
- retirer une image d’un champ ACF enlève uniquement sa référence : le média et
  ses WebP restent disponibles dans la médiathèque ;
- supprimer définitivement le média depuis la médiathèque supprime l’original,
  ses miniatures et tous leurs WebP associés.

Le contrat de bout en bout correspondant s’exécute avec :

```bash
docker compose -f .docker/docker-compose.yml exec -T wordpress \
  php /var/www/html/wp-content/plugins/wp-webp/tests/wp-webp-lifecycle.php
```

## Détection graphique (near-lossless)

Pour les JPG et PNG contenant peu de couleurs distinctes (illustrations, logos, aplats), le plugin encode en **WebP near-lossless** afin de préserver les bords nets. Les photos (nombreuses couleurs) restent en WebP lossy classique.

L’analyse est effectuée **une fois par image source** (cache par attachement).

## Profils

| Profil | Photo lossy | Graphique near-lossless |
| --- | ---: | ---: |
| Best | 85 | 85 |
| Optimal | 75 | 55 |
| Green | 68 | 40 |

Si un graphique near-lossless est plus lourd que son fallback JPG/PNG, le
plugin réessaie en lossy puis réduit progressivement la qualité jusqu’au
plancher du profil. Si aucun essai n’est plus léger, le plus petit WebP obtenu
est tout de même publié afin de conserver le format moderne et le rendu issu
du resize Imagick.

## Performance et mémoire

- À l’upload et pendant Regenerate Thumbnails, l’original est décodé une seule
  fois puis cloné pour produire ses déclinaisons.
- La génération globale utilise le même traitement groupé : une seule requête
  et un seul décodage source par attachement, puis un clone Imagick par format.
  La pause intervient entre deux attachements.
- Les images trop grandes repassent automatiquement sur un décodage par fichier
  afin de limiter le pic mémoire. Le seuil tient compte de la limite Imagick,
  est plafonné à 12 mégapixels et peut être ajusté avec le filtre
  `wp_webp_reuse_source_max_pixels` (`0` désactive la réutilisation).
- La génération globale compte les attachements avec une requête SQL légère :
  elle ne charge plus les métadonnées de toute la médiathèque au démarrage.
- Chaque lancement possède sa propre session temporaire, isolée par utilisateur
  et par exécution. Deux administrateurs peuvent donc lancer une génération sans
  mélanger leur progression.
- La suppression globale est asynchrone et reprend son parcours de dossiers
  entre les requêtes AJAX, ce qui évite un timeout sur un gros dossier `uploads`.

---

## Notes de version

### 1.1.5 — 2026-08-30

**Interface**
- La colonne **Activer** est renommée **Compresser** et la colonne
  **Recadrage (crop)** devient **Crop**.
- Un crop actif affiche directement sa position (`Centré`, `Left / bottom`,
  etc.), sans le préfixe « Oui » ni parenthèses.
- La barre de progression utilise désormais une hauteur de 10 px.

**Robustesse**
- Réservation d’un basename unique à l’upload pour empêcher des sources JPG,
  PNG ou WebP natives de partager puis d’écraser le même WebP. Les collisions
  déjà présentes sont signalées et ne sont plus écrasées silencieusement.
- Calcul du resize et du crop confié à `image_resize_dimensions()` : les
  coordonnées et filtres de cadrage WordPress sont appliqués à la source
  originale décodée par Imagick, sans reconvertir les miniatures JPG.
- La date de dernière génération n’est mise à jour que si le run ne contient
  aucune erreur.
- Le plugin cherche d’abord un WebP strictement plus léger que son fallback
  JPG ou PNG. Un near-lossless trop lourd est réessayé en lossy avec une
  qualité progressivement réduite. Si nécessaire, le plus petit essai WebP
  est publié même s’il reste plus lourd que le fallback.

**Performance**
- La génération globale traite toutes les déclinaisons actives d’un attachement
  dans la même requête et partage son décodage Imagick. Les grandes sources
  conservent le fallback par fichier piloté par la garde mémoire.
- Le WebP pleine taille est limité à 2500 px sur son côté le plus long, sans
  modifier la source ni les dimensions des formats WordPress.

### 1.1.4 — 2026-08-30

**Interface**
- Suppression de la régénération par format (colonne **Action** retirée).
- Suppression de la ligne **original** dans la liste des formats.
- Le bouton **Générer les WebP** bascule en **Pause** / **Reprendre** (plus de bouton Pause séparé).
- Colonne **Dernière génération** conservée.

### 1.1.3 — 2026-08-28

**Interface**
- Section **Generate images** placée avant la liste des formats.
- Colonne **Dernière génération** (date/heure), mise à jour à la fin d’un run.
- Ligne **original** (toujours activée) pour les WebP des fichiers source non redimensionnés.

### 1.1.2 — 2026-08-28

**Nouveautés**
- Bouton **Pause** / **Reprendre** pendant une génération globale.
- (Retiré en 1.1.4) Bouton **Générer** par format dans la liste.

### 1.1.1 — 2026-07-23

**Robustesse**
- Correction du passage par référence avec Regenerate Thumbnails.
- Écriture atomique via un fichier temporaire unique, avec contrôle du renommage.
- Journalisation limitée des erreurs pendant l’upload et la régénération.
- La suppression globale préserve les attachements WebP natifs.
- Désactiver une taille nettoie ses anciens WebP par lots.
- Vérification explicite du support WebP et filtrage strict des sources JPEG/PNG.
- Validation des noms de fichiers et dimensions issus des métadonnées.
- Respect des positions de recadrage WordPress (`left`, `center`, `right`, `top`, `bottom`).
- Fusion des métadonnées existantes lors d’une régénération limitée aux tailles manquantes.
- Nettoyage des WebP correspondant aux anciennes tailles ou anciens noms de fichiers.

**Performance**
- Décodage partagé de l’original pour toutes ses déclinaisons, avec garde mémoire.
- Comptage initial sans chargement des métadonnées de la médiathèque.
- Sessions de génération indépendantes par utilisateur et par lancement.
- Suppression globale découpée en lots de 250 entrées.
- Retrait des anciennes fonctions internes inutilisées.

**Qualité**
- Profils photo définis à `85 / 75 / 68`.
- Near-lossless défini à `85 / 55 / 40` pour Best / Optimal / Green.
- Le profil Best privilégie désormais la fidélité et n’est plus plafonné au poids du fichier source.

### 1.1.0 — 2026-07-09

**Nouveautés**
- Détection automatique des images graphiques JPG/PNG (comptage des couleurs sur une miniature).
- Encodage **near-lossless** pour les graphiques détectés (selon le profil actif).
- Cache de détection par attachement (requête PHP + transient).
- Génération bulk : **un fichier WebP par requête AJAX** (stabilité en production).
- Support `webp:use-sharp-yuv` pour de meilleurs bords sur les aplats.

**Corrections**
- Regenerate Thumbnails : utilisation des métadonnées fraîches passées par WordPress (et non l’ancien cache en base).
- Robustesse serveur : `writeImage()` à la place de `getImageBlob()`, gestion des erreurs fatales AJAX, requêtes SQL légères.
- Plafond de poids (`cap_to_original`) désactivé en mode near-lossless.
- Accentuation (sharpen) désactivée sur les graphiques near-lossless (évite les halos).

**Technique**
- Convention WebP : `image.jpg` → `image.webp`.
- PNG et JPG partagent le même test de détection graphique.

### 1.0.0 — version initiale

- Génération WebP à l’upload et via Regenerate Thumbnails.
- Profils Best / Optimal / Green (qualité, sharpen, filtre de resize).
- Activation par format d’image (`add_image_size` + tailles WP par défaut).
- Régénération bulk de la médiathèque.
- Suppression de tous les WebP du dossier uploads.
- Recréation des déclinaisons depuis l’original (resize Imagick) plutôt que conversion des vignettes WordPress.
- Plafond de poids sur le profil Best (WebP jamais plus lourd que l’original).

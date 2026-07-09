# WP WebP

Plugin WordPress qui génère une version WebP de chaque image téléversée (original + toutes les déclinaisons `add_image_size`), compatible **Regenerate Thumbnails**.

Conversion via **Imagick**, avec profils de qualité et détection automatique des images graphiques.

## Prérequis

- PHP avec l’extension **Imagick**
- ImageMagick avec support du format **WebP**

## Utilisation

1. **Réglages → WP WebP** : choisir un profil de qualité (Best / Optimal / Green).
2. Activer ou désactiver la génération WebP par format d’image enregistré.
3. **Générer les WebP** : régénère toute la médiathèque (un fichier par requête AJAX).
4. Les WebP sont aussi créés automatiquement à l’**upload** et via **Regenerate Thumbnails**.

Convention de nommage : `photo.jpg` → `photo.webp` (l’extension est remplacée, pas ajoutée).

## Détection graphique (near-lossless)

Pour les JPG et PNG contenant peu de couleurs distinctes (illustrations, logos, aplats), le plugin encode en **WebP near-lossless** afin de préserver les bords nets. Les photos (nombreuses couleurs) restent en WebP lossy classique.

L’analyse est effectuée **une fois par image source** (cache par attachement).

---

## Notes de version

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

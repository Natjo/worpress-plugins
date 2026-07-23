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

La désactivation d’un format supprime progressivement ses anciens WebP par lots
de 100 attachements. La suppression globale parcourt `uploads` par lots de
250 entrées et conserve toujours les médias WebP téléversés directement ainsi
que leurs déclinaisons.

## Détection graphique (near-lossless)

Pour les JPG et PNG contenant peu de couleurs distinctes (illustrations, logos, aplats), le plugin encode en **WebP near-lossless** afin de préserver les bords nets. Les photos (nombreuses couleurs) restent en WebP lossy classique.

L’analyse est effectuée **une fois par image source** (cache par attachement).

## Performance et mémoire

- À l’upload et pendant Regenerate Thumbnails, l’original est décodé une seule
  fois puis cloné pour produire ses déclinaisons.
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
- Profils photo conservés à `85 / 80 / 70`.
- Near-lossless recalibré à `85 / 60 / 40` pour Best / Optimal / Green.
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

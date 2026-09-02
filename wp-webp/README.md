# WP WebP

Plugin WordPress qui génère une version WebP de chaque image téléversée (original + toutes les déclinaisons `add_image_size`), compatible **Regenerate Thumbnails**.

Conversion via **Imagick**, avec profils de qualité et détection automatique des images graphiques.

## Prérequis

- PHP **8.0 minimum**
- PHP avec l’extension **Imagick**
- ImageMagick avec support du format **WebP**

## Utilisation

1. **Réglages → WP WebP** : choisir un profil de qualité (Finest / Natural / Optimal / Green).
2. Activer ou désactiver la génération WebP par format (`original` + formats enregistrés).
3. **Générer les WebP** : régénère toute la médiathèque (original + formats activés) par requête AJAX. Un attachement est traité d’un bloc tant qu’il tient dans le budget de temps du lot, sinon il reprend à la déclinaison suivante au tour d’après. Le bouton devient **Pause**, puis **Reprendre**, sans perdre la progression.
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

La désactivation d’un format (case **Compresser**) n’efface plus ses WebP.
Utilisez **Effacer les WebP des formats non utilisés** (section Developer) pour
supprimer ceux des formats décochés. La suppression globale parcourt `uploads`
par lots de 250 entrées et conserve toujours les médias WebP téléversés
directement ainsi que leurs déclinaisons.

La section **Developer** permet aussi de masquer le format `original` dans la
liste. Lorsqu’il est masqué, sa génération est forcée et il reste toujours
actif. Lorsqu’il est affiché, sa case **Compresser** permet de l’activer ou de le
désactiver comme les autres formats.

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

Le contrat de l’interface d’administration génère une fixture temporaire à
ouvrir dans un vrai navigateur :

```bash
docker compose -f .docker/docker-compose.yml exec -T wordpress \
  php /var/www/html/wp-content/plugins/wp-webp/tests/wp-webp-admin-browser-contract.php \
  /tmp/wp-webp-admin-browser-contract.html
docker compose -f .docker/docker-compose.yml cp \
  wordpress:/tmp/wp-webp-admin-browser-contract.html \
  /tmp/wp-webp-admin-browser-contract.html
```

## Détection graphique (near-lossless)

Pour les JPG et PNG contenant peu de couleurs distinctes (illustrations, logos, aplats), le plugin encode en **WebP near-lossless** afin de préserver les bords nets. Les photos (nombreuses couleurs) restent en WebP lossy classique.

L’analyse est effectuée **une fois par image source** (cache par attachement).

## Profils

| Profil | Photo lossy | Graphique near-lossless | Sharpen |
| --- | ---: | ---: | :---: |
| Finest | 85 | 85 | oui |
| Natural | 80 | 70 | non |
| Optimal | 75 | 55 | oui |
| Green | 68 | 40 | oui |

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
  et par exécution. Un verrou global empêche deux générations manuelles de
  solliciter simultanément PHP-FPM et d’écrire les mêmes fichiers.
- La suppression globale est asynchrone et reprend son parcours de dossiers
  entre les requêtes AJAX. Les entrées d’un dossier sont mémorisées pendant le
  run pour ne pas rescanner les mêmes fichiers à chaque lot.
- Les fichiers temporaires `.wp-webp-*` abandonnés depuis plus d’une heure après
  un crash sont nettoyés progressivement lors des traitements suivants.
- Chaque requête de génération dispose d’un budget de **15 secondes**, ajustable
  avec le filtre `wp_webp_batch_time_budget`. La limite qui compte n’est pas
  `max_execution_time` mais celle du serveur web : au-delà, Apache renvoie sa
  propre page 500 et la réponse JSON est perdue. Le budget est vérifié entre
  deux déclinaisons et au moins une est toujours traitée par requête.

## Échec serveur pendant la génération

Si une requête ne renvoie pas de JSON (page 500 HTML d’Apache, connexion
coupée), l’interface ne s’arrête plus au premier incident :

1. le même lot est rejoué jusqu’à trois fois, à deux secondes d’intervalle ;
2. si l’échec persiste, le client demande au serveur d’ignorer cet attachement
   (`skip_attachment`) et transmet le message d’origine ;
3. l’attachement est journalisé dans `error_log` et compté comme erreur, ce qui
   empêche la mise à jour de la date de dernière génération ;
4. le parcours continue sur les médias suivants et le rapport final nomme les
   images ignorées.

Un média systématiquement ignoré signale un problème serveur sur ce fichier
précis : décodage trop lourd pour la mémoire d’ImageMagick, ou temps de
conversion supérieur au délai du serveur web. Le message conservé dans le
rapport et dans `error_log` permet de l’identifier.

---

## Notes de version

### 1.2.0 — 2026-09-02

**Réglages**
- Nouvelle case Developer pour afficher ou masquer le format `original`. Masqué,
  il reste systématiquement généré ; affiché, il peut être sélectionné ou non.

**Sécurité**
- Les contrats PHP refusent toute exécution HTTP et restent réservés à la CLI.
- Le prérequis PHP 8.0 est déclaré dans l’en-tête du plugin.

**Robustesse**
- Verrou global avec expiration pour empêcher deux générations ou nettoyages
  concurrents ; libération à la fin, à l’abandon et sur exception. Les réglages
  de profil et de formats sont bloqués pendant ces opérations.
- Nettoyage borné des fichiers temporaires anciens laissés par un crash fatal.
- Attribution des exceptions inattendues au format pointé par le curseur.

**Performance**
- Parcours linéaire des dossiers pendant la suppression globale, sans nouveau
  `scandir()` à chaque lot.
- Chargement unique des métadonnées d’un attachement lors du nettoyage de
  plusieurs formats désactivés.
- Boucles AJAX itératives et aperçu des erreurs borné côté navigateur.

### 1.1.15 — 2026-09-02

**Interface**
- La colonne **Erreurs** affiche `—` sans erreur, sinon un compteur et le lien
  **Afficher**.

**Robustesse**
- Après l’abandon d’un attachement en échec serveur, l’erreur est attribuée au
  format pointé par le curseur au lieu d’être systématiquement rattachée à
  `original`.
- Le contrat automatisé préserve le journal d’erreurs existant.

### 1.1.14 — 2026-09-02

**Interface**
- Choix du profil de qualité via un **select** (plus de radios).

### 1.1.13 — 2026-09-02

**Interface**
- Profil **Best** renommé **Finest** (l’ancien réglage `best` est migré
  automatiquement).
- Nouveau profil **Natural** (qualité 80, sans sharpen), entre Finest et Optimal.

### 1.1.12 — 2026-09-02

**Robustesse**
- Si `image_resize_dimensions()` refuse le resize (taille déjà OK ou upscale
  interdit par WordPress), le WebP est encodé tel quel au lieu d’échouer avec
  « Calcul des dimensions WordPress impossible ».

### 1.1.11 — 2026-09-02

**Interface**
- Colonne **Erreurs** (`—` sans erreur, sinon compteur + bouton **Afficher**)
  dans la liste des formats.
- Modale listant les échecs du dernier run pour ce format (journal plafonné).

### 1.1.10 — 2026-09-01

**Interface**
- Décocher **Compresser** n’efface plus les WebP : l’option est seulement
  ignorée à la génération / à l’upload.
- Nouveau bouton **Effacer les WebP des formats non utilisés** sous
  **Effacer tous les WebP des uploads**.

### 1.1.9 — 2026-09-01

**Interface**
- Confirmer **Quitter la page** pendant une génération annule le run (curseur
  effacé, transient serveur supprimé). Rester sur la page continue normalement.

### 1.1.8 — 2026-09-01

**Interface**
- Alerte navigateur si on ferme ou recharge la page pendant une génération
  (y compris en pause).
- Le curseur de génération est conservé en `sessionStorage` tant que la page
  reste ouverte (pause / Reprendre).

### 1.1.7 — 2026-09-01

**Interface**
- Ligne **original** réintroduite en tête de la liste des formats, avec case
  **Compresser** (désactivation = nettoyage des WebP pleine taille).

### 1.1.6 — 2026-08-31

**Robustesse**
- Budget de temps par requête de génération (15 s, filtre
  `wp_webp_batch_time_budget`). Un attachement lourd est réparti sur plusieurs
  lots au lieu de dépasser le délai du serveur web et de provoquer une page 500.
- Le curseur reprend à la déclinaison suivante du même attachement ; une image
  n’est comptée comme traitée que lorsque toutes ses déclinaisons sont faites.
- L’interface rejoue un lot en échec jusqu’à trois fois, puis fait ignorer
  l’attachement fautif par le serveur au lieu d’interrompre le run. Le message
  d’erreur d’origine est conservé dans le rapport et dans `error_log`.
- Le décodage par fichier des très grandes sources passe par la même boucle que
  le décodage partagé, donc par le même budget de temps.

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

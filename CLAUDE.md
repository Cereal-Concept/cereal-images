# Cereal Images — brief de reprise

Plugin WordPress maison pour remplacer ShortPixel sur les sites clients de Cereal Concept : générer des versions AVIF et WebP des images, optimiser les JPG/PNG d'origine en gardant une sauvegarde, et servir le meilleur format via `.htaccess`.

## Objectif

- Générer `photo.jpg.avif` et `photo.jpg.webp` à côté de chaque JPG/PNG de la médiathèque (toutes tailles).
- Optimiser les JPG/PNG sur place, après en avoir sauvegardé l'original.
- Servir via `.htaccess` : AVIF si le navigateur l'accepte et que le fichier existe, sinon WebP, sinon l'original.
- Ne jamais toucher aux URL, au contenu ni aux métadonnées des pièces jointes. Désactiver le plugin doit ramener le site à l'état d'origine sans rien casser.
- Pas de service cloud, pas de quota : tout se fait sur le serveur.

Hors périmètre pour l'instant : OVH, Nginx, CDN, réécriture HTML en `<picture>`, conversion des images hors médiathèque (thème, CSS).

## Conventions de code

- PHP 8.1 minimum (prod O2switch en 8.4), `declare(strict_types=1)`, namespace `CerealImages`, autoload maison dans `cereal-images.php` (pas de dépendance Composer à l'exécution).
- Code lisible avant tout, commentaires rares (seulement le pourquoi non évident). Les docblocks WP-CLI sont fonctionnels, ils restent.
- Pas de patterns pour le principe : pas de container, pas de couches d'abstraction sans deuxième implémentation réelle. L'interface `Encoder` existe parce que des encodeurs par binaire arriveront.
- Avancer par petites étapes vérifiables, chacune testée sur le serveur avant de passer à la suivante.
- Installation : soit dossier dans `web/app/plugins/` (Bedrock), soit via Composer (`type: wordpress-plugin`).

## Environnement cible : O2switch (mutualisé)

Résultat du script de diagnostic sur la préprod Sopara :

| Élément | Valeur |
|---|---|
| PHP | 8.4, Apache derrière `o2switch-PowerBoost-v3` |
| `memory_limit` / `max_execution_time` | 512M / 600 s |
| GD WebP / GD AVIF | OK / OK (`imageavif` fonctionnel) |
| Imagick | absent |
| `exec()` | autorisé |
| Binaires présents | `jpegoptim`, `optipng`, `convert` (ImageMagick CLI) |
| Binaires absents | `cwebp`, `avifenc`, `jpegtran`, `pngquant`, `oxipng`, `vips` |

Pour mémoire, OVH mutualisé (non ciblé pour l'instant) : PHP 8.3, GD sans AVIF, Imagick 6.9.10 sans AVIF, `cwebp` présent, aucun binaire d'optimisation JPG/PNG, `max_execution_time` 165 s. L'AVIF y demanderait un binaire `avifenc` statique embarqué (exécution à vérifier).

## Ce qui est validé sur le serveur

Règles placées dans `web/app/uploads/.htaccess`, testées avec `curl` sur une image réelle :

```apache
<IfModule mod_mime.c>
    AddType image/avif .avif
    AddType image/webp .webp
</IfModule>

<IfModule mod_rewrite.c>
    RewriteEngine On

    RewriteCond %{HTTP_ACCEPT} image/avif
    RewriteCond %{REQUEST_FILENAME}.avif -f
    RewriteRule \.(jpe?g|png)$ %{REQUEST_URI}.avif [NC,L]

    RewriteCond %{HTTP_ACCEPT} image/webp
    RewriteCond %{REQUEST_FILENAME}.webp -f
    RewriteRule \.(jpe?g|png)$ %{REQUEST_URI}.webp [NC,L]
</IfModule>

Header merge Vary Accept
```

Résultats :

- `Accept: image/avif,…` → `image/avif`, `Accept: image/webp,…` → `image/webp`, `Accept: */*` → `image/jpeg`. Pas de mélange entre requêtes successives, donc PowerBoost ne sert pas un format en cache au mauvais navigateur.
- PowerBoost **retire l'en-tête `Vary`** (un en-tête de test personnalisé passe, `Vary` non). Accepté : les caches navigateur sont par visiteur. Le risque ne concerne qu'un CDN devant le site (Cloudflare…), hors périmètre. La ligne `Header merge Vary Accept` est gardée pour les autres hébergements.
- Le `<FilesMatch>` autour de `Header` n'est pas nécessaire ; la ligne seule suffit.
- Sur une image `-scaled` déjà optimisée par ShortPixel : JPEG 168,5 Ko, AVIF q50 94,8 Ko (−44 %), WebP q80 169,7 Ko (plus lourd que la source → doit être rejeté).

Pour tester (la préprod est derrière une authentification HTTP) :

```bash
curl -sI -u "$AUTH" -H "Accept: image/avif,image/webp,*/*" "$URL" | grep -iE "^HTTP|content-type|content-length|vary"
```

## État du code (v0.1.0)

```
cereal-images.php          en-tête du plugin, autoload, enregistrement WP-CLI
src/Attachment.php         liste les fichiers JPG/PNG d'une pièce jointe (principal + tailles)
src/Converter.php          génère les sidecars, écrit en .tmp puis renomme, rejette si >= source
src/Encoders/Encoder.php   interface format()/isAvailable()/encode()
src/Encoders/GdEncoder.php GD : AVIF q55, WebP q80, PNG palette → truecolor, alpha conservé
src/Cli/Command.php        wp cereal-images convert <id>... [--avif-quality=55] [--webp-quality=80] (tableau : fichier, format, statut, tailles, gain, durée + totaux)
```

Testé en local (WordPress simulé, WebP seulement car pas d'AVIF dans cet environnement) : conversion OK, taille manquante ignorée, PNG transparent conservé (alpha 127 au coin), WebP plus lourd rejeté. **Pas encore testé sur O2switch.**

Choix assumés :

- Nommage par ajout d'extension (`photo.jpg.avif`) : règle `.htaccess` triviale, aucune métadonnée à stocker, pas de collision entre `photo.jpg` et `photo.png`.
- L'original non réduit (`original_image`, avant `-scaled`) n'est pas converti : rarement affiché, très lent en AVIF. Option possible plus tard.
- Les fichiers `-rotated`/`-scaled` créés par WordPress sont déjà correctement orientés, donc pas de gestion EXIF.

## Feuille de route

Chaque étape se termine par un test sur la préprod Sopara (O2switch).

### 1. Valider la conversion sur O2switch ← prochaine étape

```bash
wp post list --post_type=attachment --s=TUNNEL --fields=ID,post_title
wp cereal-images convert <ID>
```

Vérifier que l'AVIF est généré pour toutes les tailles et que les gains sont cohérents. Ajuster les qualités si besoin (AVIF 50–60, WebP 75–82).

### 2. Optimisation des JPG/PNG avec sauvegarde

- Avant toute modification, copier l'original dans `uploads/cereal-images-backup/` en reproduisant l'arborescence (`2026/06/photo.jpg`). Ne jamais écraser une sauvegarde existante : c'est elle qui fait foi.
- JPG : `jpegoptim` (avec perte : `-m82 --strip-all --all-progressive` ; sans perte : `--strip-all --all-progressive`). Si le binaire manque, réencodage GD avec perte, gardé seulement s'il est plus léger.
- PNG : `optipng -o2 -strip all` (sans perte, gains modestes). `pngquant` n'est pas installé ; un binaire statique embarqué est une option future.
- Générer l'AVIF et le WebP **à partir de la sauvegarde** (meilleure qualité, pas de double compression avec perte), et les comparer au JPG/PNG **optimisé** pour la règle « garder seulement si plus léger ».
- Appels binaires : `exec()` avec `escapeshellarg()`, test de disponibilité via `command -v`, travail sur une copie temporaire puis renommage.
- Une classe par outil (`JpegoptimOptimizer`, `OptipngOptimizer`, `GdJpegOptimizer`), avec la même logique de disponibilité que les encodeurs.
- Commande `wp cereal-images restore <id>...` : remet les originaux sauvegardés et supprime les sidecars.

### 3. Automatisation et traitement en masse

- À l'upload : se brancher sur `wp_generate_attachment_metadata` et **planifier** le traitement (`wp_schedule_single_event`) plutôt que de le faire pendant la requête : l'AVIF est lent et bloquerait l'upload.
- Après une modification dans l'éditeur d'images de WordPress (recadrage, rotation), régénérer les sidecars de la pièce jointe (`wp_update_attachment_metadata` ou `wp_save_image_editor_file`).
- En masse : `wp cereal-images convert --all [--skip-existing]` en premier (SSH disponible sur O2switch), avec une barre de progression. Une version admin par lots (AJAX, une pièce jointe par requête) viendra à l'étape 5.
- Marquer les pièces jointes traitées avec une meta légère (`_cereal_images` : date, version, statut) pour `--skip-existing` et les stats. Ne pas modifier `_wp_attachment_metadata`.

### 4. Cycle de vie

- Écrire et retirer le bloc `.htaccess` d'`uploads` avec `insert_with_markers()` (marqueur `Cereal Images`) à l'activation et à la désactivation.
- `delete_attachment` : supprimer les sidecars et la sauvegarde de chaque fichier.
- `uninstall.php` : retirer le bloc `.htaccess` et la meta. Supprimer les sidecars, mais **garder** les sauvegardes par prudence (option explicite pour les effacer).

### 5. Admin minimale

Une page Médias > Cereal Images : formats et outils disponibles sur le serveur, qualités, mode JPG avec ou sans perte, stats (pièces jointes traitées, gain total), lancement et progression du traitement en masse, restauration globale. Colonne de statut dans la médiathèque si c'est utile.

### 6. Migration depuis ShortPixel (à préciser sur un vrai site)

- Désactiver la livraison WebP/AVIF de ShortPixel et retirer son bloc du `.htaccess` racine (marqueur `ShortPixelWebp` à confirmer) avant d'activer Cereal Images.
- Le dossier `uploads/ShortpixelBackups/` contient les originaux d'avant ShortPixel : envisager de l'utiliser comme source de sauvegarde (arborescence à vérifier) plutôt que de sauvegarder des fichiers déjà compressés.
- Vérifier le nommage de leurs fichiers `.webp`/`.avif` pour les nettoyer ou les ignorer.

## Points d'attention techniques

- **Profils couleur** : GD ignore les profils ICC. Une photo en Display P3 (iPhone) peut ternir en AVIF/WebP. À faire : détecter un profil ICC non sRGB dans le JPEG (segment APP2 `ICC_PROFILE`) et, dans ce cas, ne pas générer de sidecar (l'original est servi).
- **Transparence** : `imagealphablending(false)` + `imagesavealpha(true)` avant l'encodage, PNG palette converti en truecolor (déjà fait).
- **Mémoire et temps** : traiter une pièce jointe par tâche. L'AVIF sur une image de 2560 px prend plusieurs secondes avec GD.
- **Écritures atomiques** : toujours écrire en `.tmp` puis `rename()`, pour que le `.htaccess` ne serve jamais un fichier partiel.
- **Ne jamais servir plus lourd** : supprimer le sidecar s'il fait au moins la taille de l'image servie en repli.

## Référence utile

Le plugin Modern Image Formats de l'équipe Performance (`github.com/WordPress/performance`, dossier `plugins/webp-uploads`) a été étudié puis écarté, car il modifie les tailles générées et les métadonnées, passe par `<picture>` au lieu du `.htaccess`, et n'optimise pas les JPG/PNG. On peut s'en inspirer pour :

- `webp_uploads_convert_palette_png_to_truecolor()` (`hooks.php`) ;
- `webp_uploads_should_discard_additional_image_file()` (`helper.php`) ;
- la synchronisation après modification dans l'éditeur (`image-edit.php`) ;
- la détection de la transparence AVIF avec Imagick 6 (`helper.php`, utile si OVH arrive).

Code sous GPL : garder une mention d'origine sur les fonctions reprises.

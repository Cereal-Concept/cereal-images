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

- PHP 8.1 minimum (prod O2switch en 8.4), `declare(strict_types=1)`, namespace `CerealImages`, autoload maison dans `cereal-images.php` (pas de dépendance Composer à l'exécution). Attention à la syntaxe 8.4 : `new X()->m()` est interdit, écrire `(new X())->m()`.
- Code lisible avant tout, commentaires rares (seulement le pourquoi non évident). Les docblocks WP-CLI sont fonctionnels, ils restent.
- Pas de patterns pour le principe : pas de container, pas de couches d'abstraction sans deuxième implémentation réelle. L'interface `Encoder` existe parce que des encodeurs par binaire arriveront.
- Avancer par petites étapes vérifiables, chacune testée sur le serveur avant de passer à la suivante.
- Installation : soit dossier dans `web/app/plugins/` (Bedrock), soit via Composer (`type: wordpress-plugin`).

## Qualité

Outils en `require-dev` uniquement (`composer install` dans le dossier du plugin) :

| Commande | Outil |
|---|---|
| `composer format` / `composer format:fix` | Mago, formatage (cible PHP 8.1) |
| `composer lint` | Mago, lint (`mago.toml`) |
| `composer analyse` | PHPStan niveau max + stubs WordPress et WP-CLI |
| `composer test` | PHPUnit (`tests/`) |
| `composer check` | les quatre, **à lancer avant chaque commit** |
| `npm run check` | JS de `assets/` : oxfmt (formatage) puis oxlint (`.oxlintrc.json`) ; `npm install` d'abord |

- Pas de baseline ni de `@phpstan-ignore` : corriger la cause. Les seules exceptions Mago sont des `@mago-expect` commentés (opérateur `@` autour de GD, qui avertit sur une image corrompue ou un codec absent).
- `mago.toml` : seuils de complexité relevés par classe (40) mais bornés par méthode (10) ; `kan-defect` désactivé.
- Tests : doublures des fonctions WordPress dans `tests/bootstrap.php` (état dans `tests/Wp.php`), uploads dans un dossier temporaire, faux `jpegoptim`/`optipng` dans `tests/bin/` (ajoutés au `PATH`), doublures `FixedSizeEncoder` et `GrowingOptimizer`. Le test AVIF est ignoré si GD n'a pas le codec, celui de la conversion sRGB si Imagick est absent (cas en local). `Attachment::ids()` (SQL direct) n'est pas couvert : à vérifier sur un vrai WordPress.
- **Déploiement par copie de dossier : ne pas envoyer `vendor/` ni `node_modules/`** (ni `tests/`, `mago.toml`, `package.json`…). `assets/` doit partir. `.gitattributes` les exclut d'une archive Git/Composer.
- JS sans build (pas de bundler) : `assets/admin.js` s'appuie sur `wp-api-fetch` (URL de l'API et nonce REST fournis par WordPress).

## Environnement cible : O2switch (mutualisé)

Résultat du script de diagnostic sur la préprod Sopara :

| Élément | Valeur |
|---|---|
| PHP | 8.4, Apache derrière `o2switch-PowerBoost-v3` |
| `memory_limit` / `max_execution_time` | 512M / 600 s |
| GD WebP / GD AVIF | OK / OK (`imageavif` fonctionnel) |
| Imagick | **présent** : WordPress l'utilise pour les tailles (`WP_Image_Editor_Imagick`, constaté le 2026-11-19 via WP-CLI et à l'upload). Le diagnostic initial le disait absent. Version et support AVIF à vérifier |
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

## État du code (v0.8.0, non commitée)

```
cereal-images.php          en-tête du plugin, autoload, activation/désactivation (bloc .htaccess), enregistrement WP-CLI
uninstall.php              retire le bloc .htaccess, tous les sidecars (parcours des uploads) et la meta ; garde les sauvegardes
src/Attachment.php         fichiers JPG/PNG d'une pièce jointe (principal + tailles), original non réduit, ID (SQL direct, %i : WP 6.2+)
src/Files.php              Files::remove() (suppression sans erreur si absent)
src/Uploads.php            dossier et URL des uploads, chemin relatif
src/Htaccess.php           bloc « Cereal Images » dans uploads/.htaccess (insert_with_markers), install/remove/isInstalled
src/Sidecars.php           suppression des .avif/.webp d'une image ou de tous les uploads, jamais d'un fichier de pièce jointe ; sidecars ShortPixel
src/ColorConverter.php     Imagick : copie convertie en sRGB (assets/srgb.icc, sRGB-v4 de Compact-ICC-Profiles, CC0) pour encoder les profils larges
src/ColorProfile.php       profil ICC (APP2 JPEG, iCCP PNG) : sRGB reconnu à ses primaires (rXYZ/gXYZ/bXYZ, tolérance 0,005)
src/Hooks.php              wp_update_attachment_metadata → événement cron cereal_images_process (+60 s) ; wp_delete_file → purge sidecars + sauvegarde
src/Backup.php             sauvegarde dans cereal-images-backup/, reprise (déplacement) de la sauvegarde ShortPixel, restauration
src/Shell.php              exec() + escapeshellarg, détection des binaires via command -v
src/Processor.php          par fichier : sauvegarde → optimisation → sidecars (encodés depuis la sauvegarde, comparés au fichier servi) ; meta _cereal_images
src/Encoders/Encoder.php   interface format()/isAvailable()/encode()
src/Encoders/GdEncoder.php GD : AVIF q50 speed 8, WebP q80 (PNG : avec et sans perte, le plus léger), PNG palette → truecolor, alpha conservé ; disponibilité testée par un vrai encodage 1×1
src/Optimizers/            interface Optimizer, JpegoptimOptimizer (-m82, ICC conservé), OptipngOptimizer (-o2)
src/Updater.php            mises à jour depuis les releases GitHub (en-tête Update URI → filtre update_plugins_github.com, update.json en cache 12 h)
src/Settings.php           option cereal_images_settings (qualités, vitesse, sans perte) ; Settings::processor($overrides) pour le cron et le WP-CLI
src/Stats.php              totaux médiathèque depuis la meta _cereal_images (Attachment::processedMeta, SQL direct non couvert)
src/Admin/Page.php         Médias > Cereal Images : statistiques, état du serveur, bouton « Réécrire le bloc .htaccess », réglages (Settings API)
src/Admin/Rest.php         cereal-images/v1 : GET queue?mode=unprocessed|processed|all, POST process/<id>, POST restore/<id> (manage_options)
assets/admin.js            boucle de traitement en masse, progression, arrêt, erreurs
src/Cli/Report.php         lignes des tableaux WP-CLI (tailles, gains, durées, erreurs)
src/Cli/Command.php        wp cereal-images htaccess [--remove]
                           wp cereal-images convert [<id>...] [--all] [--skip-existing] [--avif-quality=<n>] [--webp-quality=<n>] [--avif-speed=<n>] [--[no-]lossless] (par défaut : réglages)
                           wp cereal-images restore <id>...
                           wp cereal-images shortpixel-leftovers
                           wp cereal-images shortpixel-sidecars [--delete]
```

v0.2.0 testée en local (WordPress simulé, faux binaires `jpegoptim`/`optipng`) : sans outil → `unavailable` ; sauvegarde ShortPixel de mêmes dimensions déplacée, de dimensions différentes ignorée (reste dans `shortpixel-leftovers`) ; relance → `existing` + `already` ; `restore` remet l'original et supprime les sidecars, sauvegarde conservée ; `--lossless` retire `--max`. Testée sur O2switch (#1815 migrée ShortPixel : `shortpixel` + `already`, AVIF principal 134,6 Ko ; #2379 récente : `created` + jpegoptim −35 % sur le principal, AVIF −70 % au total ; `restore` OK ; `jpegoptim` 1.4.6 et `optipng` 0.7.7 présents). PNG pas encore testé sur serveur.

v0.4.0 testée en local puis sur O2switch : activation → bloc avec marqueurs, l'ancien bloc manuel retiré par `wp cereal-images htaccess` ; `curl` AVIF/WebP/JPEG OK ; suppression de #2381 (10 tailles) → sidecars et sauvegardes supprimés ; `htaccess --remove` puis réécriture OK. Désinstallation non testée sur serveur.

v0.3.0 testée en local puis sur O2switch : sauvegarde ShortPixel de l'original non réduit déplacée (#38), hook d'upload planifié puis traité via `wp cron event run --due-now`. `convert --all` pas encore lancé sur le serveur.

Historique v0.1.0 :

Testé en local (WordPress simulé, WebP seulement car pas d'AVIF dans cet environnement) : conversion OK, taille manquante ignorée, PNG transparent conservé (alpha 127 au coin), WebP plus lourd rejeté. Testé sur O2switch (#1815, 9 tailles, source déjà ShortPixel 586 Ko) : AVIF q60/55/50 → −18/−26/−36 %, WebP q80 rejeté sur la plupart des grandes tailles. AVIF speed 6 : ~17 s par pièce jointe, dont 10 s pour l'image principale et 6 s pour la 1536x2048 (0,4 s pour la 1152x1536). Speed 8 (q50) : 1,5 s au total pour +2 % de poids ; speed 9 identique à 8. Retenu par défaut : AVIF q50 speed 8, validé visuellement. `.htaccess` OK, aucun `.tmp` résiduel.

AVIF « moins chaud » que le JPEG dans Firefox seulement : le JPEG sans profil n'y est pas géré en couleur (`gfx.color_management.mode = 2`), donc sursaturé sur écran à gamut large. Identiques dans Chrome. Pas un défaut d'encodage.

Choix assumés :

- Nommage par ajout d'extension (`photo.jpg.avif`) : règle `.htaccess` triviale, aucune métadonnée à stocker, pas de collision entre `photo.jpg` et `photo.png`.
- L'original non réduit (`original_image`, avant `-scaled`) n'est pas converti : rarement affiché, très lent en AVIF. Option possible plus tard.
- Les fichiers `-rotated`/`-scaled` créés par WordPress sont déjà correctement orientés, donc pas de gestion EXIF.

## Publier une version

Dépôt public `github.com/Cereal-Concept/cereal-images`. Les sites voient la mise à jour dans Extensions (mise à jour automatique activable).

1. Même numéro dans l'en-tête `Version:` et la constante `VERSION` de `cereal-images.php`.
2. `composer check` et `npm run check`, commit.
3. `git tag -a v0.8.0 -m "notes de version"` puis `git push --follow-tags`. Le message du tag devient les notes affichées dans l'admin.
4. L'action `.github/workflows/release.yml` vérifie la version, construit `cereal-images.zip` (`git archive`, fichiers `export-ignore` exclus) et `update.json`, et publie la release.

Les sites lisent `releases/latest/download/update.json`, pas l'API GitHub (limitée à 60 appels/h par IP, partagée sur un mutualisé). Échec → nouvel essai après 1 h. Le zip doit contenir un dossier `cereal-images/` : le dossier du plugin sur les sites doit porter ce nom. Premier passage en 0.8.0 à faire à la main (les versions antérieures n'ont pas l'updater).

## Feuille de route

Chaque étape se termine par un test sur la préprod Sopara (O2switch).

### 1. Valider la conversion sur O2switch ✓ (fait le 2026-11-19)

```bash
wp post list --post_type=attachment --s=TUNNEL --fields=ID,post_title
wp cereal-images convert <ID>
```

Vérifier que l'AVIF est généré pour toutes les tailles et que les gains sont cohérents. Ajuster les qualités si besoin (AVIF 50–60, WebP 75–82).

### 2. Optimisation des JPG/PNG avec sauvegarde ✓ (2026-11-19)

Flux par fichier (principal + tailles) :

1. **Sauvegarde** dans `uploads/cereal-images-backup/2026/06/photo.jpg` : existante → gardée (jamais écrasée) ; sinon sauvegarde ShortPixel de **mêmes dimensions** → **déplacée** (`rename`, pas de copie : quota, et le dossier ShortPixel orphelin ne doit pas rester seul garant) ; sinon copie du fichier. Sans sauvegarde possible, le fichier n'est pas modifié (statut `no-backup`).
2. **Optimisation** seulement si le fichier est identique à sa sauvegarde (taille puis md5). Sinon il est déjà optimisé, par nous ou par ShortPixel (`already`) : on n'y retouche pas, pas de double perte. Travail sur `photo.cereal-tmp.jpg` (extension gardée pour optipng), gardé seulement si plus léger.
   - JPG : `jpegoptim --all-progressive --strip-com --strip-exif --strip-iptc --strip-xmp --max=82` (sans `--max` avec `--lossless`). Pas de `--strip-all`, qui retirerait le profil ICC.
   - PNG : `optipng -o2 -strip all`.
   - Pas de repli GD : O2switch a `jpegoptim`. À ajouter si un serveur ciblé en manque.
3. **Sidecars** encodés depuis la sauvegarde, gardés seulement s'ils sont plus légers que le fichier servi.

`restore <id>` remet la sauvegarde et supprime les sidecars ; la sauvegarde reste. Sur une image migrée de ShortPixel, cela remet l'original lourd, qu'un `convert` suivant compressera avec `jpegoptim -m82`.

`shortpixel-leftovers` liste ce qui reste dans `ShortpixelBackups/` (lecture seule ; le plugin ne supprime jamais ce dossier).

Retour éventuel à ShortPixel (depuis `uploads`, fichiers seulement ; ShortPixel garde aussi un état en base, perdu s'il a été désinstallé) :

```bash
mkdir -p ShortpixelBackups/app/uploads && mv cereal-images-backup/* ShortpixelBackups/app/uploads/
```

### 3. Automatisation et traitement en masse ✓ (2026-11-19)

- Filtre `wp_update_attachment_metadata` (upload, régénération, éditeur d'images) → `wp_schedule_single_event(time() + 60, 'cereal_images_process', [$id])`, une seule fois tant que l'événement est en attente. Le délai laisse WordPress finir les tailles : il met à jour les métadonnées après chacune.
- Après une modification dans l'éditeur, les nouveaux fichiers (`-e123…`) sont traités ; les anciens gardent leurs sidecars.
- En masse : `wp cereal-images convert --all [--skip-existing]`, barre de progression, erreurs listées à la fin, totaux. Une version admin par lots viendra à l'étape 5.
- Meta `_cereal_images` : `version`, `date`, `before`, `after` (poids servi par format). Supprimée par `restore`. `_wp_attachment_metadata` jamais modifiée.
- L'original non réduit (`original_image`) : seule sa sauvegarde ShortPixel est reprise (déplacée), sans copie, optimisation ni sidecars.
- **WP-Cron** : sur la préprod Sopara (authentification HTTP), il ne se déclenche jamais seul, toutes les tâches du site restaient en attente (constaté le 2026-11-19). Lancé à la main, `cereal_images_process` a traité un upload en 5 s (AVIF −75 %). Recommandé partout : `DISABLE_WP_CRON=true` dans le `.env` Bedrock + tâche cron cPanel toutes les 5 min : `cd ~/<site> && /chemin/vers/wp cron event run --due-now > /dev/null 2>&1`.
- Upload lent (~15 s) constaté sur une photo de 1,5 Mo : pas dû au plugin (le hook ne fait que planifier), sans doute la génération des tailles par WordPress. Non vérifié plugin désactivé.

### 4. Cycle de vie ✓ (2026-11-19)

Écarts au plan ci-dessous :

- Purge à la suppression par le filtre `wp_delete_file` (priorité `PHP_INT_MAX`) au lieu de `delete_attachment` : WordPress l'appelle pour le principal, les tailles, l'original non réduit et les anciennes versions de l'éditeur ; un chemin vidé par une autre extension (Polylang, fichier partagé entre traductions) n'est pas purgé. Limite : une taille absente du disque ne passe pas par ce filtre, sa sauvegarde éventuelle reste.
- Anti-collision via `attachment_url_to_postid()` (pas de SQL maison), seulement si la destination existe. Appliqué aussi à `restore`, à la purge et à la désinstallation. Statut `collision` listé avec les erreurs de `convert --all`.
- `uninstall.php` parcourt les uploads (hors `cereal-images-backup/` et `ShortpixelBackups/`) au lieu de `Attachment::ids()` : attrape aussi les sidecars orphelins (anciennes versions de l'éditeur).
- Pas de commande `purge-backups` (non décidée).

Plan initial :

Objectif : le plugin installe et retire lui-même ce qu'il ajoute, et ne laisse rien d'orphelin. Version cible : 0.4.0.

1. **`.htaccess` d'`uploads`** — nouvelle classe `src/Htaccess.php` :
   - `RULES` = le bloc validé (section « Ce qui est validé sur le serveur »), écrit avec `insert_with_markers($uploadsDir . '/.htaccess', 'Cereal Images', $lines)`. Charger `wp-admin/includes/misc.php` si `insert_with_markers` n'existe pas (activation, WP-CLI).
   - `install(): bool`, `remove(): bool` (bloc vidé : `insert_with_markers` avec `[]`), `isInstalled(): bool` (servira à la page admin).
   - `register_activation_hook` / `register_deactivation_hook` dans `cereal-images.php`.
   - Commande `wp cereal-images htaccess [--remove]` : réécrit ou retire le bloc (utile en déploiement par copie, où l'activation n'est pas rejouée) et signale un fichier non accessible en écriture.
   - **Préprod Sopara** : le bloc avait été ajouté à la main, sans marqueurs. Une copie identique des règles hors marqueurs (indentation ignorée) est retirée à chaque écriture du bloc.
2. **Suppression d'une pièce jointe** — action `delete_attachment` (les fichiers existent encore à ce moment) → `Processor::purgeAttachment($id)` : supprime les `.avif`/`.webp` de chaque fichier et les sauvegardes (`Backup::path()`) des fichiers et de l'original non réduit. Prévoir une liste des chemins **sans** filtre `is_file` (une taille déjà absente peut avoir une sauvegarde).
3. **`uninstall.php`** — `defined('WP_UNINSTALL_PLUGIN') || exit;`, charge `cereal-images.php` pour l'autoload (WordPress ne le charge pas à la désinstallation), retire le bloc `.htaccess`, supprime les sidecars de toutes les pièces jointes (`Attachment::ids()`), `delete_post_meta_by_key('_cereal_images')`. **Garde** `cereal-images-backup/` ; suppression seulement via une commande explicite (`wp cereal-images purge-backups --yes`, à décider).
4. **Anti-collision** — une pièce jointe WebP/AVIF peut s'appeler `photo.jpg.webp`, comme nos sidecars (vu sur Sopara, #2016). Dans `Processor::convert()`, si la destination existe déjà et appartient à une pièce jointe (`_wp_attached_file` égal au chemin relatif), ne rien écrire **ni supprimer** : statut `collision`. Attention, le cas `larger` fait aujourd'hui `Files::remove($destination)` : c'est là que le fichier d'une pièce jointe serait effacé.
5. **Tests** (doublures dans `tests/bootstrap.php`) : bloc `.htaccess` écrit/retiré/idempotent, `purgeAttachment` (sidecars et sauvegardes supprimés, rien d'autre), collision (fichier de pièce jointe intact dans les cas `ok` et `larger`), `uninstall.php` garde les sauvegardes. `composer check` vert.
6. **Test O2switch** : retirer le bloc manuel, `wp plugin deactivate` puis `activate` → bloc présent avec marqueurs, `curl` AVIF/WebP/JPEG comme à l'étape 1 ; supprimer une pièce jointe de test → sidecars et sauvegardes partis ; `wp cereal-images htaccess --remove` puis réécriture.

### Améliorations notées (après l'étape 4)

- ✓ v0.5.0 (testée sur O2switch le 2026-11-19 : P3 #2382 → tout en `icc` ; PNG #2054 → AVIF −68 %, WebP −60 % ; logos de 1 Ko toujours plus légers en PNG, normal ; `shortpixel-sidecars` : 1 567 fichiers, 109 Mo) : **profils couleur** (sidecars non générés, statut `icc`, ancien sidecar supprimé), **WebP sans perte pour les PNG** (`IMG_WEBP_LOSSLESS`, gardé s'il est plus léger que le WebP avec perte), **`shortpixel-sidecars [--delete]`** (aperçu par défaut, `X.avif`/`X.webp` avec un `X.jpg|jpeg|png` voisin, hors pièces jointes et dossiers de sauvegarde).
- ✓ v0.7.0 (O2switch le 2026-11-19 : P3 → AVIF/WebP `ok`, aucun fichier temporaire restant ; `#id` et tableaux bien alternés) :
  - **Profils larges convertis** : si Imagick est présent, sidecars encodés depuis une copie sRGB temporaire (`photo.cereal-tmp-srgb.jpg`, JPEG q98 sans sous-échantillonnage, supprimée après) ; sinon statut `icc` comme avant. Non testable en local (pas d'Imagick) : test ignoré.
  - **Ordre d'affichage WP-CLI** : `format_items()` écrit avec `echo` (via `WP_CLI::line`), `log()`/`success()` en `fwrite(STDOUT)` ; une extension qui ouvre un tampon de sortie retardait les tableaux (vu : tous les `#id` puis tous les tableaux). Les tableaux passent désormais par `ob_start()` + `WP_CLI::log()`.

### 5. Admin minimale ✓ (2026-11-19)

- **5a. Serveur et réglages** ✓ (O2switch : réglage AVIF 40 → 257,9 Ko, `--avif-quality=50` → 365,4 Ko). Réglages utilisés par le cron et par défaut en WP-CLI (options en ligne de commande prioritaires). Les images déjà traitées ne changent pas.
- **5b. Statistiques** ✓ (O2switch : 304 traitées sur 304) : traitées / total, poids servi avant/après par format, depuis la meta `_cereal_images`.
- **5c. Traitement en masse** ✓ (O2switch : non traitées puis arrêt de « Tout retraiter » OK). Boutons « Traiter les images non traitées » et « Tout retraiter » (avec confirmation, sidecars régénérés aux réglages actuels) : une pièce jointe par requête REST (pas de `max_execution_time`), progression, arrêt, erreurs listées. JS sans build dans `assets/admin.js`, oxlint + oxfmt (`npm run check`) ; `package.json`/`node_modules` exclus du déploiement, `assets/` envoyé.
- **5d. Restauration globale** ✓ (O2switch : arrêt après quelques pièces jointes, retraitées ensuite). Bouton « Tout restaurer » (liste : pièces jointes avec meta), confirmation qui prévient du poids des originaux ShortPixel : même boucle avec `restore`, confirmation explicite.
- Pas de colonne dans la médiathèque pour l'instant.

Description initiale :

Une page Médias > Cereal Images : formats et outils disponibles sur le serveur, qualités, mode JPG avec ou sans perte, stats (pièces jointes traitées, gain total), lancement et progression du traitement en masse, restauration globale. Colonne de statut dans la médiathèque si c'est utile.

### 6. Remplacement de ShortPixel sur un site (validé sur la préprod Sopara le 2026-11-19)

Pas de migration à faire : les sauvegardes ShortPixel sont reprises automatiquement pendant le traitement.

1. Désactiver ShortPixel.
2. Activer Cereal Images (bloc `.htaccess` écrit à l'activation).
3. Lancer le traitement : bouton « Traiter les images non traitées » (Médias > Cereal Images) ou `wp cereal-images convert --all` (~2,3 s par pièce jointe ; en SSH, via `nohup … > ~/cereal-all.log 2>&1 &`).

Ménage facultatif, une fois le site vérifié :

- `wp cereal-images shortpixel-sidecars --delete` : anciens AVIF/WebP de ShortPixel (Sopara : 1 567 fichiers, 109 Mo).
- `wp cereal-images shortpixel-leftovers` : ce qui reste dans `ShortpixelBackups/` (sauvegardes de pièces jointes supprimées ou WebP, hors périmètre). Si rien d'utile → `rm -r ShortpixelBackups`. Pour garder la sauvegarde d'une pièce jointe WebP : `mv -n` vers `cereal-images-backup/`, même arborescence.

Pièges rencontrés : originaux tournés par ShortPixel (orientation EXIF, dimensions inversées, gérés pour l'original non réduit seulement) ; photos HEIC converties par WordPress (type MIME `image/heic`, fichiers `.jpg` : la sélection se fait par extension du fichier affiché) ; pièce jointe WebP nommée `photo.jpg.webp`, identique à notre nommage de sidecar (pas de collision sur Sopara : aucun `photo.jpg` du même nom, mais un risque théorique d'écrasement existe).


- Bloc WebP de ShortPixel dans le `.htaccess` racine (marqueur `ShortPixelWebp` à confirmer), s'il reste après désactivation : sans effet sur les images, les règles de réécriture du `.htaccess` d'`uploads` remplaçant celles du dossier parent (pas de `RewriteOptions Inherit`). À retirer pour la propreté.
- `uploads/ShortpixelBackups/` contient les originaux d'avant ShortPixel, **toutes tailles comprises**. Arborescence = chemin d'URL des uploads sous `ShortpixelBackups/` : sur Bedrock `ShortpixelBackups/app/uploads/2026/06/photo.jpg` (à calculer depuis le chemin de `wp_upload_dir()['baseurl']`). Sopara : 1,1 Go.
- ShortPixel compresse fort (Sopara #1815 : principal 1 655 Ko → 174,5 Ko, tailles −65 %). Ne pas réoptimiser ces JPG (double perte, et `jpegoptim -m82` depuis l'original ne ferait pas mieux).
- Mesuré sur #1815 (AVIF q50 speed 8) : l'AVIF tiré de la sauvegarde ShortPixel ne pèse que 2 à 8 % de plus que celui tiré du JPEG ShortPixel, et reste bien sous le JPEG servi (principal 137,8 Ko contre 178,7 Ko). Donc : sauvegarde ShortPixel = sauvegarde de référence et source des sidecars, sans repasser par une compression avec perte.
- `--all` sur Sopara (2026-11-19) : 301 pièces jointes, 0 erreur, ~11 min ; poids servi 150,1 Mo → AVIF 89,6 Mo (−40 %), WebP 116,7 Mo (−22 %) ; JPG tous `already`. `ShortpixelBackups` : 1,1 Go → 25 Mo (31 fichiers). Restes analysés : originaux non réduits dont ShortPixel avait appliqué l'orientation EXIF (largeur/hauteur inversées → acceptées depuis pour l'original seulement, `adoptShortPixelOriginal`) ; fichiers orphelins sans pièce jointe ; pièces jointes WebP (hors périmètre, sauvegardes à déplacer à la main).
- Leurs sidecars remplacent l'extension (`photo.avif`, vu sur Sopara ; `photo.webp` probable) : aucune collision avec les nôtres (`photo.jpg.avif`), à nettoyer après bascule.

## Points d'attention techniques

- **Profils couleur** : GD ignore les profils ICC. Une photo en Display P3 (iPhone) peut ternir en AVIF/WebP. Fait (v0.5.0) : profil RGB non sRGB → pas de sidecar, l'original est servi. Sur O2switch, WordPress génère les tailles avec Imagick, qui garde le profil : toutes les tailles d'une photo P3 sont donc `icc` (vérifié, #2382). v0.7.0 : avec Imagick, conversion en sRGB (`profileImage`, LittleCMS) avant l'encodage, donc sidecars quand même. Profils vérifiés : sRGB (Linux, Windows) acceptés ; Adobe RGB, écran Dell P3 refusés. CMYK et gris laissés passer.
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

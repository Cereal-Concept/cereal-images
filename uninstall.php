<?php

declare(strict_types=1);

namespace CerealImages;

defined('WP_UNINSTALL_PLUGIN') || exit();

// WordPress ne charge pas le plugin à la désinstallation : il faut l'autoload.
require_once __DIR__ . '/cereal-images.php';

// Les sauvegardes (cereal-images-backup) sont gardées : ce sont les seuls originaux.
Htaccess::remove();
Sidecars::removeAll();
delete_post_meta_by_key(Processor::META);

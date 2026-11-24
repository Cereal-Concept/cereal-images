<?php

declare(strict_types=1);

namespace CerealImages;

/**
 * Mises à jour depuis les releases GitHub, affichées dans Extensions comme pour un plugin officiel.
 *
 * Lecture de `update.json` (fichier joint à chaque release) plutôt que de l'API GitHub : l'API est limitée
 * à 60 appels par heure et par IP, partagée par tous les sites d'un hébergement mutualisé.
 *
 * @phpstan-type Release array{version: string, package: string, notes: string}
 */
final class Updater
{
    public const REPOSITORY = 'Cereal-Concept/cereal-images';

    private const SLUG = 'cereal-images';

    private const CACHE = 'cereal_images_release';

    /** Court : WordPress espace déjà ses propres vérifications (12 h par le cron, 1 h sur la page Extensions). */
    private const CACHE_TTL = 3600;

    /** Après un échec (GitHub injoignable, pas encore de release) : nouvel essai plus tôt. */
    private const ERROR_TTL = 3600;

    private static string $basename = '';

    public static function register(string $pluginFile): void
    {
        self::$basename = plugin_basename($pluginFile);
        // Appelé grâce à l'en-tête « Update URI » ; WordPress.org n'est alors plus consulté pour ce plugin.
        add_filter('update_plugins_github.com', [self::class, 'update'], 10, 3);
        add_filter('plugins_api', [self::class, 'details'], 10, 3);
        // « Vérifier à nouveau », `wp plugin update`… vident le cache de WordPress : le nôtre suit, sinon il masquerait la release.
        add_action('delete_site_transient_update_plugins', [self::class, 'clearCache']);
    }

    public static function clearCache(): void
    {
        delete_site_transient(self::CACHE);
    }

    /**
     * Version disponible : WordPress la compare lui-même à la version installée.
     *
     * @param array<string, mixed> $pluginData
     */
    public static function update(mixed $update, array $pluginData, string $pluginFile): mixed
    {
        if ($pluginFile !== self::$basename) {
            return $update;
        }

        $release = self::latestRelease();

        if ($release === null) {
            return $update;
        }

        return [
            'id' => 'https://github.com/' . self::REPOSITORY,
            'slug' => self::SLUG,
            'version' => $release['version'],
            'package' => $release['package'],
            'url' => 'https://github.com/' . self::REPOSITORY,
            'requires_php' => '8.1',
        ];
    }

    /** Fenêtre « Voir les détails » de la mise à jour. */
    public static function details(mixed $result, string $action, mixed $args): mixed
    {
        if ($action !== 'plugin_information' || ! is_object($args) || ($args->slug ?? null) !== self::SLUG) {
            return $result;
        }

        $release = self::latestRelease();

        if ($release === null) {
            return $result;
        }

        return (object) [
            'name' => 'Cereal Images',
            'slug' => self::SLUG,
            'version' => $release['version'],
            'author' => 'Cereal Concept',
            'homepage' => 'https://github.com/' . self::REPOSITORY,
            'download_link' => $release['package'],
            'requires_php' => '8.1',
            'sections' => ['changelog' => nl2br(esc_html($release['notes'] ?: 'Voir les releases sur GitHub.'))],
        ];
    }

    /** @return Release|null */
    public static function latestRelease(): ?array
    {
        $cached = get_site_transient(self::CACHE);

        if (is_array($cached)) {
            return self::parse($cached);
        }

        $response = wp_remote_get('https://github.com/' . self::REPOSITORY . '/releases/latest/download/update.json', [
            'timeout' => 10,
        ]);
        $data = is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200
            ? null
            : json_decode(wp_remote_retrieve_body($response), true);
        $release = self::parse($data);

        // Tableau vide en cache après un échec : pas de nouvel appel à chaque chargement de l'admin.
        set_site_transient(self::CACHE, $release ?? [], $release === null ? self::ERROR_TTL : self::CACHE_TTL);

        return $release;
    }

    /**
     * Le zip est celui de la version annoncée, pas « latest » : une release publiée entre-temps ne change pas
     * ce qui est installé.
     *
     * @return Release|null
     */
    public static function parse(mixed $data): ?array
    {
        $version = is_array($data) ? $data['version'] ?? null : null;

        if (! is_string($version) || ! preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            return null;
        }

        $notes = $data['notes'] ?? '';

        return [
            'version' => $version,
            'package' =>
                'https://github.com/' . self::REPOSITORY . "/releases/download/v{$version}/" . self::SLUG . '.zip',
            'notes' => is_string($notes) ? $notes : '',
        ];
    }
}

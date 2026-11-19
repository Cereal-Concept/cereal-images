<?php

declare(strict_types=1);

namespace CerealImages;

/** Bloc de règles dans le .htaccess des uploads, entre marqueurs WordPress : le reste du fichier n'est jamais touché. */
final class Htaccess
{
    private const MARKER = 'Cereal Images';

    public const RULES = [
        '<IfModule mod_mime.c>',
        '    AddType image/avif .avif',
        '    AddType image/webp .webp',
        '</IfModule>',
        '',
        '<IfModule mod_rewrite.c>',
        '    RewriteEngine On',
        '',
        '    RewriteCond %{HTTP_ACCEPT} image/avif',
        '    RewriteCond %{REQUEST_FILENAME}.avif -f',
        '    RewriteRule \.(jpe?g|png)$ %{REQUEST_URI}.avif [NC,L]',
        '',
        '    RewriteCond %{HTTP_ACCEPT} image/webp',
        '    RewriteCond %{REQUEST_FILENAME}.webp -f',
        '    RewriteRule \.(jpe?g|png)$ %{REQUEST_URI}.webp [NC,L]',
        '</IfModule>',
        '',
        // Retiré par PowerBoost sur O2switch, gardé pour les autres hébergements.
        'Header merge Vary Accept',
    ];

    public static function path(): string
    {
        return Uploads::dir() . '/.htaccess';
    }

    public static function install(): bool
    {
        return self::write(self::RULES);
    }

    public static function remove(): bool
    {
        // Sans fichier, rien à retirer : insert_with_markers en créerait un avec un bloc vide.
        return ! is_file(self::path()) || self::write([]);
    }

    public static function isInstalled(): bool
    {
        self::loadWordPressFunctions();

        return extract_from_markers(self::path(), self::MARKER) === self::RULES;
    }

    /** @param list<string> $lines */
    private static function write(array $lines): bool
    {
        self::loadWordPressFunctions();

        return self::removeUnmarkedCopies() && insert_with_markers(self::path(), self::MARKER, $lines);
    }

    /** Les mêmes règles ajoutées à la main avant le plugin, hors marqueurs : retirées pour ne pas les doubler. */
    private static function removeUnmarkedCopies(): bool
    {
        $path = self::path();

        if (! is_file($path)) {
            return true;
        }

        $lines = explode("\n", (string) file_get_contents($path));
        $found = false;

        while (($start = self::findUnmarkedCopy($lines)) !== null) {
            array_splice($lines, $start, count(self::RULES));
            $found = true;
        }

        return ! $found || file_put_contents($path, implode("\n", $lines)) !== false;
    }

    /**
     * Comparaison sans l'indentation, qui peut changer au copier-coller.
     *
     * @param list<string> $lines
     */
    private static function findUnmarkedCopy(array $lines): ?int
    {
        $rules = array_map(trim(...), self::RULES);
        $inside = false;

        foreach ($lines as $index => $line) {
            $inside = match (true) {
                str_contains($line, '# BEGIN ' . self::MARKER) => true,
                str_contains($line, '# END ' . self::MARKER) => false,
                default => $inside,
            };

            if (! $inside && array_map(trim(...), array_slice($lines, $index, count($rules))) === $rules) {
                return $index;
            }
        }

        return null;
    }

    /** Fonctions d'admin, absentes à l'activation par WP-CLI et à la désinstallation. */
    private static function loadWordPressFunctions(): void
    {
        if (! function_exists('insert_with_markers')) {
            require_once ABSPATH . 'wp-admin/includes/misc.php';
        }
    }
}

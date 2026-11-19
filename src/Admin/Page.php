<?php

declare(strict_types=1);

namespace CerealImages\Admin;

use CerealImages\Htaccess;
use CerealImages\Processor;
use CerealImages\Settings;
use CerealImages\Stats;

/** Page Médias > Cereal Images : état du serveur et réglages. */
final class Page
{
    private const SLUG = 'cereal-images';

    private const GROUP = 'cereal_images';

    private const HTACCESS_ACTION = 'cereal_images_htaccess';

    private const CAPABILITY = 'manage_options';

    private const LABELS = [
        'avif_quality' => ['Qualité AVIF', '0 à 100. 50 : −40 % environ sur une photo, sans différence visible.'],
        'webp_quality' => ['Qualité WebP', '0 à 100. Pour les navigateurs sans AVIF.'],
        'avif_speed' => [
            'Vitesse AVIF',
            '0 (lent, un peu plus léger) à 10 (rapide). 8 : 1,5 s par photo au lieu de 17 s en 6.',
        ],
    ];

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addPage']);
        add_action('admin_init', [self::class, 'registerSetting']);
        add_action('admin_post_' . self::HTACCESS_ACTION, [self::class, 'rewriteHtaccess']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function enqueue(string $hook): void
    {
        if ($hook !== 'media_page_' . self::SLUG) {
            return;
        }

        // wp-api-fetch ajoute lui-même l'URL de l'API et le nonce REST.
        wp_enqueue_script(
            'cereal-images-admin',
            plugins_url('assets/admin.js', dirname(__DIR__, 2) . '/cereal-images.php'),
            ['wp-api-fetch'],
            \CerealImages\VERSION,
            ['in_footer' => true],
        );
        wp_add_inline_script(
            'cereal-images-admin',
            'window.cerealImages = ' . wp_json_encode(['namespace' => Rest::NAMESPACE]) . ';',
            'before',
        );
    }

    public static function addPage(): void
    {
        add_media_page('Cereal Images', 'Cereal Images', self::CAPABILITY, self::SLUG, [self::class, 'render']);
    }

    public static function registerSetting(): void
    {
        register_setting(self::GROUP, Settings::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [Settings::class, 'sanitize'],
            'default' => Settings::DEFAULTS,
        ]);
    }

    public static function rewriteHtaccess(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die('Accès refusé.', 403);
        }

        check_admin_referer(self::HTACCESS_ACTION);
        wp_safe_redirect(add_query_arg('htaccess', Htaccess::install() ? 'ok' : 'error', self::url()));
        exit();
    }

    /**
     * Libellé, valeur, et état : vrai (OK), faux (problème), null (information).
     *
     * @return list<array{string, string, ?bool}>
     */
    public static function serverRows(Processor $processor): array
    {
        $formats = $processor->formats();
        $optimizers = $processor->optimizers();
        $htaccess = Htaccess::isInstalled();

        return [
            ['PHP', PHP_VERSION, null],
            ['Formats générés', $formats ? implode(', ', $formats) : 'aucun (GD sans AVIF ni WebP)', $formats !== []],
            [
                'Optimiseurs JPG/PNG',
                $optimizers ? implode(', ', $optimizers) : 'aucun (jpegoptim, optipng absents)',
                $optimizers !== [],
            ],
            ['exec()', function_exists('exec') ? 'autorisé' : 'désactivé', function_exists('exec')],
            ['Imagick', self::imagick(), null],
            [
                '.htaccess des uploads',
                ($htaccess ? 'bloc en place' : 'bloc absent : AVIF et WebP ne sont pas servis')
                    . ' — '
                    . Htaccess::path(),
                $htaccess,
            ],
        ];
    }

    private static function imagick(): string
    {
        if (! class_exists(\Imagick::class)) {
            return 'absent';
        }

        $version = \Imagick::getVersion()['versionString'];
        $avif = \Imagick::queryFormats('AVIF') ? 'AVIF disponible' : 'sans AVIF';

        return (preg_match('/ImageMagick [\d.-]+/', $version, $match) ? $match[0] : $version) . ", {$avif}";
    }

    public static function render(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            return;
        }

        echo '<div class="wrap"><h1>Cereal Images</h1>';
        self::renderNotice();
        self::renderStats(Stats::collect());
        self::renderBulk();
        self::renderServer(Settings::processor());
        self::renderSettings(Settings::get());
        echo '</div>';
    }

    private static function renderNotice(): void
    {
        $status = $_GET['htaccess'] ?? null;

        if ($status !== 'ok' && $status !== 'error') {
            return;
        }

        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            $status === 'ok' ? 'success' : 'error',
            $status === 'ok'
                ? 'Bloc .htaccess réécrit.'
                : esc_html(Htaccess::path() . " n'est pas accessible en écriture."),
        );
    }

    /** @param array{total: int, processed: int, before: int, after: array<string, int>} $stats */
    private static function renderStats(array $stats): void
    {
        printf(
            '<h2>Médiathèque</h2><p>%d pièces jointes JPG/PNG traitées sur %d.</p>',
            $stats['processed'],
            $stats['total'],
        );

        if (! $stats['after']) {
            return;
        }

        echo
            '<table class="widefat striped" style="max-width: 40rem"><thead><tr>'
                . '<th>Format servi</th><th>Avant</th><th>Après</th><th>Gain</th></tr></thead><tbody>';

        foreach ($stats['after'] as $format => $after) {
            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html($format),
                esc_html(size_format($stats['before'], 1) ?: '-'),
                esc_html(size_format($after, 1) ?: '-'),
                $stats['before'] ? esc_html(round((1 - ($after / $stats['before'])) * 100) . ' %') : '-',
            );
        }

        echo '</tbody></table>';
        echo
            '<p class="description">Poids servi par format, toutes tailles comprises : un navigateur sans AVIF reçoit le WebP, '
                . 'sinon le JPG/PNG. Une version rejetée (plus lourde) compte le JPG/PNG.</p>';
    }

    private static function renderBulk(): void
    {
        echo <<<'HTML'
            <h2>Traitement en masse</h2>
            <div id="cereal-images-bulk">
                <p>
                    <button type="button" class="button button-primary" data-mode="unprocessed">Traiter les images non traitées</button>
                    <button type="button" class="button" data-mode="all"
                        data-confirm="Retraiter toute la médiathèque avec les réglages actuels ? Les JPG/PNG déjà optimisés ne sont pas retouchés, seules les versions AVIF et WebP sont régénérées.">
                        Tout retraiter</button>
                    <button type="button" class="button" data-mode="processed" data-action="restore"
                        data-confirm="Restaurer toutes les images traitées ? Les originaux sauvegardés remplacent les JPG/PNG optimisés et les versions AVIF/WebP sont supprimées. Sur un site migré de ShortPixel, ce sont les originaux lourds d'avant ShortPixel (plusieurs centaines de Mo de plus). Les sauvegardes sont conservées.">
                        Tout restaurer</button>
                    <button type="button" class="button" data-stop hidden>Arrêter</button>
                </p>
                <p data-status aria-live="polite"></p>
                <progress data-progress max="1" value="0" hidden style="width: 100%; max-width: 40rem"></progress>
                <ul data-errors></ul>
            </div>
            <p class="description">Une pièce jointe à la fois, environ 2 s chacune. Garder la page ouverte.</p>
            HTML;
    }

    private static function renderServer(Processor $processor): void
    {
        echo '<h2>Serveur</h2><table class="widefat striped" style="max-width: 60rem"><tbody>';

        foreach (self::serverRows($processor) as [$label, $value, $ok]) {
            printf(
                '<tr><th scope="row" style="width: 14rem">%s</th><td>%s %s</td></tr>',
                esc_html($label),
                match ($ok) {
                    true => '<span class="dashicons dashicons-yes" style="color: #00a32a"></span>',
                    false => '<span class="dashicons dashicons-warning" style="color: #d63638"></span>',
                    null => '',
                },
                esc_html($value),
            );
        }

        echo '</tbody></table>';

        printf(
            '<form method="post" action="%s"><input type="hidden" name="action" value="%s">',
            esc_url(admin_url('admin-post.php')),
            esc_attr(self::HTACCESS_ACTION),
        );
        wp_nonce_field(self::HTACCESS_ACTION);
        submit_button('Réécrire le bloc .htaccess', 'secondary', 'submit', false);
        echo '</form>';
    }

    /** @param array{avif_quality: int, webp_quality: int, avif_speed: int, lossless: bool} $settings */
    private static function renderSettings(array $settings): void
    {
        echo '<h2>Réglages</h2>';
        echo
            '<p>Utilisés pour les nouvelles images et par défaut en WP-CLI. Les images déjà traitées ne changent pas.</p>';
        echo '<form method="post" action="options.php">';
        settings_fields(self::GROUP);
        echo '<table class="form-table" role="presentation"><tbody>';

        foreach (self::LABELS as $key => [$label, $help]) {
            [$min, $max] = Settings::RANGES[$key];
            printf(
                '<tr><th scope="row"><label for="%1$s">%2$s</label></th><td>'
                . '<input type="number" id="%1$s" name="%3$s[%1$s]" value="%4$d" min="%5$d" max="%6$d" class="small-text">'
                . '<p class="description">%7$s</p></td></tr>',
                esc_attr($key),
                esc_html($label),
                esc_attr(Settings::OPTION),
                $settings[$key],
                $min,
                $max,
                esc_html($help),
            );
        }

        printf(
            '<tr><th scope="row">Optimisation JPG</th><td><input type="hidden" name="%1$s[lossless]" value="0">'
            . '<label><input type="checkbox" name="%1$s[lossless]" value="1"%2$s> Sans perte</label>'
            . '<p class="description">Décoché : jpegoptim en qualité 82 maximum (−35 %% environ sur une photo récente).</p></td></tr>',
            esc_attr(Settings::OPTION),
            checked($settings['lossless'], true, false),
        );

        echo '</tbody></table>';
        submit_button();
        echo '</form>';
    }

    private static function url(): string
    {
        return admin_url('upload.php?page=' . self::SLUG);
    }
}

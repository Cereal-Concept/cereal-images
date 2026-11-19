<?php

declare(strict_types=1);

namespace CerealImages\Cli;

use CerealImages\Attachment;
use CerealImages\Backup;
use CerealImages\Htaccess;
use CerealImages\Processor;
use CerealImages\Sidecars;
use CerealImages\Uploads;
use cli\progress\Bar;
use cli\Shell;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use WP_CLI;

use function WP_CLI\Utils\format_items;

/**
 * @phpstan-import-type FileReport from Processor
 * @phpstan-import-type Summary from Processor
 */
final class Command
{
    /**
     * Sauvegarde, optimise les JPG/PNG puis génère les versions AVIF et WebP de toutes les tailles.
     *
     * La sauvegarde ShortPixel d'un fichier, si elle existe, est déplacée dans cereal-images-backup.
     * Un fichier qui diffère de sa sauvegarde est considéré comme déjà optimisé et n'est pas retouché.
     *
     * ## OPTIONS
     *
     * [<id>...]
     * : ID des pièces jointes.
     *
     * [--all]
     * : Toutes les pièces jointes JPG et PNG de la médiathèque.
     *
     * [--skip-existing]
     * : Ignore les pièces jointes déjà traitées.
     *
     * [--avif-quality=<n>]
     * : Qualité AVIF (0-100).
     * ---
     * default: 50
     * ---
     *
     * [--webp-quality=<n>]
     * : Qualité WebP (0-100).
     * ---
     * default: 80
     * ---
     *
     * [--avif-speed=<n>]
     * : Vitesse d'encodage AVIF (0 = lent et compact, 10 = rapide).
     * ---
     * default: 8
     * ---
     *
     * [--lossless]
     * : Optimisation JPG sans perte.
     *
     * ## EXAMPLES
     *
     *     wp cereal-images convert 123 456
     *     wp cereal-images convert 123 --avif-quality=50 --webp-quality=75
     *     wp cereal-images convert 123 --lossless
     *     wp cereal-images convert --all --skip-existing
     *
     * @when after_wp_load
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function convert(array $args, array $assocArgs): void
    {
        $processor = Processor::make(
            $this->intOption($assocArgs, 'avif-quality', 0, 100),
            $this->intOption($assocArgs, 'webp-quality', 0, 100),
            $this->intOption($assocArgs, 'avif-speed', 0, 10),
            ! $this->flag($assocArgs, 'lossless'),
        );

        if (! $processor->formats()) {
            WP_CLI::error('Aucun encodeur AVIF ou WebP disponible sur ce serveur.');
        }

        WP_CLI::log('Formats disponibles : ' . implode(', ', $processor->formats()));
        WP_CLI::log('Optimiseurs disponibles : ' . (implode(', ', $processor->optimizers()) ?: 'aucun'));

        $skipExisting = $this->flag($assocArgs, 'skip-existing');

        if ($this->flag($assocArgs, 'all')) {
            $this->convertAll($processor, $skipExisting ? Attachment::unprocessedIds() : Attachment::ids());

            return;
        }

        if (! $args) {
            WP_CLI::error('Indiquer des ID de pièces jointes ou --all.');
        }

        foreach (array_map(intval(...), $args) as $id) {
            if ($skipExisting && get_post_meta($id, Processor::META, true)) {
                WP_CLI::log("#{$id} : déjà traitée.");

                continue;
            }

            $report = $processor->processAttachment($id);

            if (! $report) {
                WP_CLI::warning("#{$id} : aucune image JPG ou PNG trouvée.");

                continue;
            }

            WP_CLI::log("#{$id}");
            format_items('table', array_map($this->optimizationRow(...), $report), [
                'file',
                'backup',
                'status',
                'before',
                'after',
                'gain',
                'time',
            ]);
            format_items('table', $this->sidecarRows($report), [
                'file',
                'format',
                'status',
                'served',
                'result',
                'gain',
                'time',
            ]);
            format_items('table', $this->totalRows(Processor::summary($report)), [
                'total',
                'before',
                'after',
                'gain',
                'time',
            ]);
        }
    }

    /** @param list<int> $ids */
    private function convertAll(Processor $processor, array $ids): void
    {
        if (! $ids) {
            WP_CLI::success('Aucune pièce jointe à traiter.');

            return;
        }

        // Pas de barre quand la sortie est redirigée (nohup, fichier de log) : elle remplirait le fichier.
        $progress = Shell::isPiped() ? null : new Bar(sprintf('%d pièces jointes', count($ids)), count($ids));
        $total = ['before' => 0, 'after' => [], 'time' => []];
        $errors = [];

        foreach ($ids as $index => $id) {
            $report = $processor->processAttachment($id);
            $total = Processor::mergeSummaries($total, Processor::summary($report));
            $errors = [...$errors, ...$this->errors($id, $report)];

            // Le cache objet de WP-CLI grossit à chaque pièce jointe lue.
            if (($index % 50) === 49 && function_exists('wp_cache_flush_runtime')) {
                wp_cache_flush_runtime();
            }

            $progress?->tick();
        }

        $progress?->finish();

        foreach ($errors as $error) {
            WP_CLI::warning($error);
        }

        format_items('table', $this->totalRows($total), ['total', 'before', 'after', 'gain', 'time']);
        WP_CLI::success(sprintf('%d pièces jointes traitées, %d fichiers en erreur.', count($ids), count($errors)));
    }

    /**
     * @param list<FileReport> $report
     * @return list<string>
     */
    private function errors(int $attachmentId, array $report): array
    {
        $errors = [];

        foreach ($report as $item) {
            $statuses = [
                $item['backup'],
                $item['optimization']['status'],
                ...array_column($item['sidecars'], 'status'),
            ];

            if (array_intersect($statuses, ['error', 'no-backup', 'collision'])) {
                $errors[] = "#{$attachmentId} " . basename($item['file']) . ' : ' . implode(', ', $statuses);
            }
        }

        return $errors;
    }

    /**
     * Remet les originaux sauvegardés et supprime les versions AVIF et WebP. Les sauvegardes sont conservées.
     *
     * ## OPTIONS
     *
     * <id>...
     * : ID des pièces jointes.
     *
     * ## EXAMPLES
     *
     *     wp cereal-images restore 123 456
     *
     * @when after_wp_load
     *
     * @param list<string> $args
     */
    public function restore(array $args): void
    {
        $processor = Processor::make();

        foreach (array_map(intval(...), $args) as $id) {
            $report = $processor->restoreAttachment($id);

            if (! $report) {
                WP_CLI::warning("#{$id} : aucune image JPG ou PNG trouvée.");

                continue;
            }

            WP_CLI::log("#{$id}");
            format_items(
                'table',
                array_map(fn (array $item) => [
                    'file' => basename($item['file']),
                    'status' => $item['status'],
                    'sidecars removed' => $item['removed'],
                ], $report),
                ['file', 'status', 'sidecars removed'],
            );
        }
    }

    /**
     * Liste les fichiers restant dans ShortpixelBackups (pièces jointes supprimées, fichiers isolés…). Ne modifie rien.
     *
     * ## EXAMPLES
     *
     *     wp cereal-images shortpixel-leftovers
     *
     * @subcommand shortpixel-leftovers
     * @when after_wp_load
     */
    public function shortPixelLeftovers(): void
    {
        $root = Backup::shortPixelRoot();

        if (! is_dir($root)) {
            WP_CLI::success("Aucun dossier {$root}.");

            return;
        }

        $files = [];
        $size = 0;

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $root,
            RecursiveDirectoryIterator::SKIP_DOTS,
        )) as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $files[] = substr($file->getPathname(), strlen($root) + 1);
            $size += $file->getSize();
        }

        sort($files);
        $this->logFirst($files);

        WP_CLI::log(sprintf('%d fichiers, %s dans %s', count($files), $this->size($size, 1), $root));
    }

    /**
     * Liste les sidecars laissés par ShortPixel (photo.avif à côté de photo.jpg), plus servis après la bascule.
     *
     * Aperçu par défaut. Un fichier qui appartient à une pièce jointe n'est jamais retenu.
     *
     * ## OPTIONS
     *
     * [--delete]
     * : Supprime les fichiers listés.
     *
     * ## EXAMPLES
     *
     *     wp cereal-images shortpixel-sidecars
     *     wp cereal-images shortpixel-sidecars --delete
     *
     * @subcommand shortpixel-sidecars
     * @when after_wp_load
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function shortPixelSidecars(array $args, array $assocArgs): void
    {
        $files = Sidecars::shortPixel();
        $size = array_sum(array_map(static fn (string $file) => (int) filesize($file), $files));
        $prefix = strlen(Uploads::dir()) + 1;
        $this->logFirst(array_map(static fn (string $file) => substr($file, $prefix), $files));

        $summary = sprintf('%d fichiers, %s', count($files), $this->size($size, 1));

        if (! $this->flag($assocArgs, 'delete')) {
            WP_CLI::log("{$summary}. Relancer avec --delete pour les supprimer.");

            return;
        }

        $deleted = count(array_filter($files, Sidecars::deleteShortPixel(...)));
        WP_CLI::success("{$deleted} fichiers supprimés sur {$summary}.");
    }

    /**
     * Écrit ou retire le bloc Cereal Images du .htaccess des uploads (fait aussi à l'activation et à la désactivation).
     *
     * ## OPTIONS
     *
     * [--remove]
     * : Retire le bloc au lieu de l'écrire.
     *
     * ## EXAMPLES
     *
     *     wp cereal-images htaccess
     *     wp cereal-images htaccess --remove
     *
     * @when after_wp_load
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function htaccess(array $args, array $assocArgs): void
    {
        $remove = $this->flag($assocArgs, 'remove');
        $path = Htaccess::path();

        if (! ($remove ? Htaccess::remove() : Htaccess::install())) {
            WP_CLI::error("{$path} n'est pas accessible en écriture.");
        }

        WP_CLI::success(($remove ? 'Bloc retiré de ' : 'Bloc écrit dans ') . $path);
    }

    /** @param list<string> $lines */
    private function logFirst(array $lines, int $limit = 20): void
    {
        foreach (array_slice($lines, 0, $limit) as $line) {
            WP_CLI::log($line);
        }

        if (count($lines) > $limit) {
            WP_CLI::log('…');
        }
    }

    /** @param array<string, mixed> $assocArgs */
    private function flag(array $assocArgs, string $key): bool
    {
        return ($assocArgs[$key] ?? false) !== false;
    }

    /** @param array<string, mixed> $assocArgs */
    private function intOption(array $assocArgs, string $key, int $min, int $max): int
    {
        $value = filter_var($assocArgs[$key] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => $min, 'max_range' => $max],
        ]);

        if ($value === false) {
            WP_CLI::error("--{$key} doit être un entier entre {$min} et {$max}.");
        }

        return $value;
    }

    /**
     * @param FileReport $item
     * @return array<string, string>
     */
    private function optimizationRow(array $item): array
    {
        $optimization = $item['optimization'];

        return [
            'file' => basename($item['file']),
            'backup' => $item['backup'],
            'status' => $optimization['status'],
            'before' => $this->size($optimization['before'], 1),
            'after' => $this->size($optimization['after'], 1),
            'gain' => $optimization['status'] === 'ok'
                ? $this->gain($optimization['before'], $optimization['after'])
                : '-',
            'time' => $this->duration($optimization['time']),
        ];
    }

    /**
     * @param list<FileReport> $report
     * @return list<array<string, string>>
     */
    private function sidecarRows(array $report): array
    {
        $rows = [];

        foreach ($report as $item) {
            foreach ($item['sidecars'] as $sidecar) {
                $rows[] = [
                    'file' => basename($item['file']),
                    'format' => $sidecar['format'],
                    'status' => $sidecar['status'],
                    'served' => $this->size($sidecar['served'], 1),
                    'result' => $sidecar['result'] ? $this->size($sidecar['result'], 1) : '-',
                    'gain' => $sidecar['status'] === 'ok' ? $this->gain($sidecar['served'], $sidecar['result']) : '-',
                    'time' => $this->duration($sidecar['time']),
                ];
            }
        }

        return $rows;
    }

    /**
     * @param Summary $summary
     * @return list<array<string, string>>
     */
    private function totalRows(array $summary): array
    {
        $rows = [];

        foreach ($summary['after'] as $label => $after) {
            $rows[] = [
                'total' => $label,
                'before' => $this->size($summary['before'], 1),
                'after' => $this->size($after, 1),
                'gain' => $this->gain($summary['before'], $after),
                'time' => $this->duration($summary['time'][$label]),
            ];
        }

        return $rows;
    }

    private function size(int $bytes, int $decimals = 1): string
    {
        return size_format($bytes, $decimals) ?: '-';
    }

    private function gain(int $source, int $result): string
    {
        return $source ? round((1 - ($result / $source)) * 100) . ' %' : '-';
    }

    private function duration(float $seconds): string
    {
        return number_format($seconds, 1, ',', '') . ' s';
    }
}

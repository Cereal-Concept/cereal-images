<?php

declare(strict_types=1);

namespace CerealImages\Cli;

use CerealImages\Backup;
use CerealImages\Processor;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use WP_CLI;

use function WP_CLI\Utils\format_items;

/**
 * @phpstan-import-type FileReport from Processor
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
     * <id>...
     * : ID des pièces jointes.
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
     *
     * @when after_wp_load
     */
    public function convert(array $args, array $assocArgs): void
    {
        $processor = Processor::make(
            $this->intOption($assocArgs, 'avif-quality', 0, 100),
            $this->intOption($assocArgs, 'webp-quality', 0, 100),
            $this->intOption($assocArgs, 'avif-speed', 0, 10),
            ! isset($assocArgs['lossless']),
        );

        if (! $processor->formats()) {
            WP_CLI::error('Aucun encodeur AVIF ou WebP disponible sur ce serveur.');
        }

        WP_CLI::log('Formats disponibles : '.implode(', ', $processor->formats()));
        WP_CLI::log('Optimiseurs disponibles : '.(implode(', ', $processor->optimizers()) ?: 'aucun'));

        foreach (array_map('intval', $args) as $id) {
            $report = $processor->processAttachment($id);

            if (! $report) {
                WP_CLI::warning("#{$id} : aucune image JPG ou PNG trouvée.");

                continue;
            }

            WP_CLI::log("#{$id}");
            format_items('table', array_map($this->optimizationRow(...), $report), ['file', 'backup', 'status', 'before', 'after', 'gain', 'time']);
            format_items('table', $this->sidecarRows($report), ['file', 'format', 'status', 'served', 'result', 'gain', 'time']);
            format_items('table', $this->totalRows($report), ['total', 'before', 'after', 'gain', 'time']);
        }
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
     */
    public function restore(array $args): void
    {
        $processor = Processor::make();

        foreach (array_map('intval', $args) as $id) {
            $report = $processor->restoreAttachment($id);

            if (! $report) {
                WP_CLI::warning("#{$id} : aucune image JPG ou PNG trouvée.");

                continue;
            }

            WP_CLI::log("#{$id}");
            format_items('table', array_map(fn (array $item) => [
                'file' => basename($item['file']),
                'status' => $item['status'],
                'sidecars removed' => $item['removed'],
            ], $report), ['file', 'status', 'sidecars removed']);
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

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $files[] = substr($file->getPathname(), strlen($root) + 1);
                $size += $file->getSize();
            }
        }

        sort($files);

        foreach (array_slice($files, 0, 20) as $file) {
            WP_CLI::log($file);
        }

        if (count($files) > 20) {
            WP_CLI::log('…');
        }

        WP_CLI::log(sprintf('%d fichiers, %s dans %s', count($files), size_format($size, 1), $root));
    }

    private function intOption(array $assocArgs, string $key, int $min, int $max): int
    {
        $value = filter_var($assocArgs[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);

        if ($value === false) {
            WP_CLI::error("--{$key} doit être un entier entre {$min} et {$max}.");
        }

        return $value;
    }

    /** @param FileReport $item */
    private function optimizationRow(array $item): array
    {
        $optimization = $item['optimization'];

        return [
            'file' => basename($item['file']),
            'backup' => $item['backup'],
            'status' => $optimization['status'],
            'before' => size_format($optimization['before'], 1),
            'after' => size_format($optimization['after'], 1),
            'gain' => $optimization['status'] === 'ok' ? $this->gain($optimization['before'], $optimization['after']) : '-',
            'time' => $this->duration($optimization['time']),
        ];
    }

    /** @param list<FileReport> $report */
    private function sidecarRows(array $report): array
    {
        $rows = [];

        foreach ($report as $item) {
            foreach ($item['sidecars'] as $sidecar) {
                $rows[] = [
                    'file' => basename($item['file']),
                    'format' => $sidecar['format'],
                    'status' => $sidecar['status'],
                    'served' => size_format($sidecar['served'], 1),
                    'result' => $sidecar['result'] ? size_format($sidecar['result'], 1) : '-',
                    'gain' => $sidecar['status'] === 'ok' ? $this->gain($sidecar['served'], $sidecar['result']) : '-',
                    'time' => $this->duration($sidecar['time']),
                ];
            }
        }

        return $rows;
    }

    /**
     * Gains mesurés par rapport au poids servi avant le passage. Un sidecar rejeté compte le JPG/PNG, c'est ce que reçoit le navigateur.
     *
     * @param list<FileReport> $report
     */
    private function totalRows(array $report): array
    {
        $before = 0;
        $totals = ['jpg/png' => ['after' => 0, 'time' => 0.0]];

        foreach ($report as $item) {
            $optimization = $item['optimization'];
            $before += $optimization['before'];
            $totals['jpg/png']['after'] += $optimization['after'];
            $totals['jpg/png']['time'] += $optimization['time'];

            foreach ($item['sidecars'] as $sidecar) {
                $total = $totals[$sidecar['format']] ?? ['after' => 0, 'time' => 0.0];
                $total['after'] += $sidecar['status'] === 'ok' ? $sidecar['result'] : $sidecar['served'];
                $total['time'] += $sidecar['time'];
                $totals[$sidecar['format']] = $total;
            }
        }

        $rows = [];

        foreach ($totals as $label => $total) {
            $rows[] = [
                'total' => $label,
                'before' => size_format($before, 1),
                'after' => size_format($total['after'], 1),
                'gain' => $this->gain($before, $total['after']),
                'time' => $this->duration($total['time']),
            ];
        }

        return $rows;
    }

    private function gain(int $source, int $result): string
    {
        return $source ? round((1 - $result / $source) * 100).' %' : '-';
    }

    private function duration(float $seconds): string
    {
        return number_format($seconds, 1, ',', '').' s';
    }
}

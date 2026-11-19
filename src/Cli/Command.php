<?php

declare(strict_types=1);

namespace CerealImages\Cli;

use CerealImages\Converter;
use WP_CLI;

use function WP_CLI\Utils\format_items;

final class Command
{
    /**
     * Génère les versions AVIF et WebP de toutes les tailles d'une ou plusieurs pièces jointes.
     *
     * ## OPTIONS
     *
     * <id>...
     * : ID des pièces jointes.
     *
     * [--avif-quality=<n>]
     * : Qualité AVIF (0-100).
     * ---
     * default: 55
     * ---
     *
     * [--webp-quality=<n>]
     * : Qualité WebP (0-100).
     * ---
     * default: 80
     * ---
     *
     * ## EXAMPLES
     *
     *     wp cereal-images convert 123 456
     *     wp cereal-images convert 123 --avif-quality=50 --webp-quality=75
     *
     * @when after_wp_load
     */
    public function convert(array $args, array $assocArgs): void
    {
        $converter = Converter::make(
            $this->quality($assocArgs, 'avif-quality'),
            $this->quality($assocArgs, 'webp-quality'),
        );

        if (! $converter->formats()) {
            WP_CLI::error('Aucun encodeur AVIF ou WebP disponible sur ce serveur.');
        }

        WP_CLI::log('Formats disponibles : '.implode(', ', $converter->formats()));

        foreach (array_map('intval', $args) as $id) {
            $report = $converter->convertAttachment($id);

            if (! $report) {
                WP_CLI::warning("#{$id} : aucune image JPG ou PNG trouvée.");

                continue;
            }

            WP_CLI::log("#{$id}");
            format_items('table', array_map($this->row(...), $report), ['file', 'format', 'status', 'source', 'result', 'gain', 'time']);
            $this->totals($report);
        }
    }

    private function quality(array $assocArgs, string $key): int
    {
        $quality = filter_var($assocArgs[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100]]);

        if ($quality === false) {
            WP_CLI::error("--{$key} doit être un entier entre 0 et 100.");
        }

        return $quality;
    }

    /** @param array{file: string, format: string, status: string, source: int, result: int, time: float} $item */
    private function row(array $item): array
    {
        return [
            'file' => basename($item['file']),
            'format' => $item['format'],
            'status' => $item['status'],
            'source' => size_format($item['source'], 1),
            'result' => $item['result'] ? size_format($item['result'], 1) : '-',
            'gain' => $item['status'] === 'ok' ? $this->gain($item['source'], $item['result']) : '-',
            'time' => $this->duration($item['time']),
        ];
    }

    /**
     * Le poids servi compte l'original quand le sidecar a été rejeté, c'est ce que reçoit le navigateur.
     *
     * @param list<array{file: string, format: string, status: string, source: int, result: int, time: float}> $report
     */
    private function totals(array $report): void
    {
        $byFormat = [];

        foreach ($report as $item) {
            $total = $byFormat[$item['format']] ?? ['source' => 0, 'served' => 0, 'time' => 0.0];
            $total['source'] += $item['source'];
            $total['served'] += $item['status'] === 'ok' ? $item['result'] : $item['source'];
            $total['time'] += $item['time'];
            $byFormat[$item['format']] = $total;
        }

        foreach ($byFormat as $format => $total) {
            WP_CLI::log(sprintf(
                'Total %s : %s → %s (%s) en %s',
                $format,
                size_format($total['source'], 1),
                size_format($total['served'], 1),
                $this->gain($total['source'], $total['served']),
                $this->duration($total['time']),
            ));
        }

        WP_CLI::log('Durée totale : '.$this->duration(array_sum(array_column($byFormat, 'time'))));
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

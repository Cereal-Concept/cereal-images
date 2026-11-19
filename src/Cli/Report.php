<?php

declare(strict_types=1);

namespace CerealImages\Cli;

use CerealImages\Processor;

/**
 * Lignes des tableaux WP-CLI : tailles, gains et durées lisibles.
 *
 * @phpstan-import-type FileReport from Processor
 * @phpstan-import-type Summary from Processor
 */
final class Report
{
    /**
     * @param FileReport $item
     * @return array<string, string>
     */
    public static function optimizationRow(array $item): array
    {
        $optimization = $item['optimization'];

        return [
            'file' => basename($item['file']),
            'backup' => $item['backup'],
            'status' => $optimization['status'],
            'before' => self::size($optimization['before'], 1),
            'after' => self::size($optimization['after'], 1),
            'gain' => $optimization['status'] === 'ok'
                ? self::gain($optimization['before'], $optimization['after'])
                : '-',
            'time' => self::duration($optimization['time']),
        ];
    }

    /**
     * @param list<FileReport> $report
     * @return list<array<string, string>>
     */
    public static function sidecarRows(array $report): array
    {
        $rows = [];

        foreach ($report as $item) {
            foreach ($item['sidecars'] as $sidecar) {
                $rows[] = [
                    'file' => basename($item['file']),
                    'format' => $sidecar['format'],
                    'status' => $sidecar['status'],
                    'served' => self::size($sidecar['served'], 1),
                    'result' => $sidecar['result'] ? self::size($sidecar['result'], 1) : '-',
                    'gain' => $sidecar['status'] === 'ok' ? self::gain($sidecar['served'], $sidecar['result']) : '-',
                    'time' => self::duration($sidecar['time']),
                ];
            }
        }

        return $rows;
    }

    /**
     * @param Summary $summary
     * @return list<array<string, string>>
     */
    public static function totalRows(array $summary): array
    {
        $rows = [];

        foreach ($summary['after'] as $label => $after) {
            $rows[] = [
                'total' => $label,
                'before' => self::size($summary['before'], 1),
                'after' => self::size($after, 1),
                'gain' => self::gain($summary['before'], $after),
                'time' => self::duration($summary['time'][$label]),
            ];
        }

        return $rows;
    }

    public static function size(int $bytes, int $decimals = 1): string
    {
        return size_format($bytes, $decimals) ?: '-';
    }

    public static function gain(int $source, int $result): string
    {
        return $source ? round((1 - ($result / $source)) * 100) . ' %' : '-';
    }

    public static function duration(float $seconds): string
    {
        return number_format($seconds, 1, ',', '') . ' s';
    }
}

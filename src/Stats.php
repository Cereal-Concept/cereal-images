<?php

declare(strict_types=1);

namespace CerealImages;

/**
 * Totaux de la médiathèque, depuis la meta posée après chaque traitement.
 *
 * @phpstan-type Totals array{total: int, processed: int, before: int, after: array<string, int>}
 */
final class Stats
{
    /** @return Totals */
    public static function collect(): array
    {
        return ['total' => count(Attachment::ids()), ...self::summarize(Attachment::processedMeta())];
    }

    /**
     * Poids servi avant traitement, puis après pour chaque format (JPG/PNG en repli compris).
     *
     * @param iterable<mixed> $metas
     * @return array{processed: int, before: int, after: array<string, int>}
     */
    public static function summarize(iterable $metas): array
    {
        $totals = ['processed' => 0, 'before' => 0, 'after' => []];

        foreach ($metas as $meta) {
            if (! is_array($meta) || ! is_int($meta['before'] ?? null) || ! is_array($meta['after'] ?? null)) {
                continue;
            }

            $totals['processed']++;
            $totals['before'] += $meta['before'];

            foreach ($meta['after'] as $format => $after) {
                if (! is_int($after)) {
                    continue;
                }

                $totals['after'][(string) $format] = ($totals['after'][(string) $format] ?? 0) + $after;
            }
        }

        return $totals;
    }
}

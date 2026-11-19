<?php

declare(strict_types=1);

namespace CerealImages;

use CerealImages\Encoders\Encoder;
use CerealImages\Encoders\GdEncoder;
use CerealImages\Optimizers\JpegoptimOptimizer;
use CerealImages\Optimizers\Optimizer;
use CerealImages\Optimizers\OptipngOptimizer;

/**
 * @phpstan-type Optimization array{status: string, before: int, after: int, time: float}
 * @phpstan-type Sidecar array{format: string, status: string, served: int, result: int, time: float}
 * @phpstan-type FileReport array{file: string, backup: string, optimization: Optimization, sidecars: list<Sidecar>}
 */
final class Processor
{
    public const FORMATS = ['avif', 'webp'];

    /**
     * @param list<Encoder> $encoders
     * @param list<Optimizer> $optimizers
     */
    public function __construct(
        private readonly array $encoders,
        private readonly array $optimizers,
    ) {}

    public static function make(int $avifQuality = 50, int $webpQuality = 80, int $avifSpeed = 8, bool $lossy = true): self
    {
        $encoders = [
            new GdEncoder('avif', $avifQuality, $avifSpeed),
            new GdEncoder('webp', $webpQuality),
        ];

        $optimizers = [
            new JpegoptimOptimizer($lossy),
            new OptipngOptimizer(),
        ];

        return new self(
            array_values(array_filter($encoders, fn (Encoder $encoder) => $encoder->isAvailable())),
            array_values(array_filter($optimizers, fn (Optimizer $optimizer) => $optimizer->isAvailable())),
        );
    }

    /** @return list<string> */
    public function formats(): array
    {
        return array_map(fn (Encoder $encoder) => $encoder->format(), $this->encoders);
    }

    /** @return list<string> */
    public function optimizers(): array
    {
        return array_map(fn (Optimizer $optimizer) => $optimizer->name(), $this->optimizers);
    }

    /** @return list<FileReport> */
    public function processAttachment(int $attachmentId): array
    {
        $report = [];

        foreach (Attachment::files($attachmentId) as $file) {
            $backup = Backup::ensure($file);

            // Sans sauvegarde, on ne modifie pas le fichier, mais les sidecars restent possibles depuis celui-ci.
            if ($backup === 'error') {
                $size = (int) filesize($file);
                $optimization = ['status' => 'no-backup', 'before' => $size, 'after' => $size, 'time' => 0.0];
                $source = $file;
            } else {
                $optimization = $this->optimize($file);
                $source = (string) Backup::path($file);
            }

            $report[] = [
                'file' => $file,
                'backup' => $backup,
                'optimization' => $optimization,
                'sidecars' => array_map(
                    fn (Encoder $encoder) => ['format' => $encoder->format(), ...$this->convert($source, $file, $optimization['after'], $encoder)],
                    $this->encoders,
                ),
            ];
        }

        return $report;
    }

    /** @return list<array{file: string, status: string, removed: int}> */
    public function restoreAttachment(int $attachmentId): array
    {
        $report = [];

        foreach (Attachment::files($attachmentId) as $file) {
            $removed = 0;

            foreach (self::FORMATS as $format) {
                if (is_file("{$file}.{$format}") && unlink("{$file}.{$format}")) {
                    $removed++;
                }
            }

            $backup = Backup::path($file);
            $status = match (true) {
                $backup === null || ! is_file($backup) => 'no-backup',
                Backup::restore($file) => 'restored',
                default => 'error',
            };

            $report[] = ['file' => $file, 'status' => $status, 'removed' => $removed];
        }

        return $report;
    }

    /** @return Optimization */
    private function optimize(string $file): array
    {
        $before = (int) filesize($file);
        $result = fn (string $status, int $after = 0, float $time = 0.0) => [
            'status' => $status,
            'before' => $before,
            'after' => $status === 'ok' ? $after : $before,
            'time' => $time,
        ];

        if (! Backup::isPristine($file)) {
            return $result('already');
        }

        $optimizer = $this->optimizerFor($file);

        if (! $optimizer) {
            return $result('unavailable');
        }

        // L'extension est gardée : optipng renomme en .png un fichier qu'il ne reconnaît pas à son nom.
        $tmp = (string) preg_replace('/(\.[^.]+)$/', '.cereal-tmp$1', $file);

        if (! copy($file, $tmp)) {
            @unlink($tmp);

            return $result('error');
        }

        $start = hrtime(true);
        $optimized = $optimizer->optimize($tmp);
        $time = (hrtime(true) - $start) / 1e9;

        clearstatcache(true, $tmp);
        $after = is_file($tmp) ? (int) filesize($tmp) : 0;

        if (! $optimized || $after === 0) {
            @unlink($tmp);

            return $result('error', 0, $time);
        }

        if ($after >= $before) {
            unlink($tmp);

            return $result('larger', $after, $time);
        }

        rename($tmp, $file);

        return $result('ok', $after, $time);
    }

    private function optimizerFor(string $file): ?Optimizer
    {
        foreach ($this->optimizers as $optimizer) {
            if ($optimizer->supports($file)) {
                return $optimizer;
            }
        }

        return null;
    }

    /**
     * Encode depuis la sauvegarde (meilleure qualité) mais compare au fichier réellement servi en repli.
     *
     * @return array{status: string, served: int, result: int, time: float}
     */
    private function convert(string $source, string $file, int $servedSize, Encoder $encoder): array
    {
        $destination = "{$file}.{$encoder->format()}";
        $tmp = "{$destination}.tmp";

        $start = hrtime(true);
        $encoded = $encoder->encode($source, $tmp);
        $time = (hrtime(true) - $start) / 1e9;

        clearstatcache(true, $tmp);
        $resultSize = is_file($tmp) ? (int) filesize($tmp) : 0;

        if (! $encoded || $resultSize === 0) {
            @unlink($tmp);

            return ['status' => 'error', 'served' => $servedSize, 'result' => 0, 'time' => $time];
        }

        if ($resultSize >= $servedSize) {
            unlink($tmp);
            @unlink($destination);

            return ['status' => 'larger', 'served' => $servedSize, 'result' => $resultSize, 'time' => $time];
        }

        rename($tmp, $destination);

        return ['status' => 'ok', 'served' => $servedSize, 'result' => $resultSize, 'time' => $time];
    }
}

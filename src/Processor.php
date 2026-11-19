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
 * @phpstan-type Summary array{before: int, after: array<string, int>, time: array<string, float>}
 */
final class Processor
{
    public const FORMATS = ['avif', 'webp'];

    /** Meta légère posée après traitement (version, date, poids). `_wp_attachment_metadata` n'est jamais modifiée. */
    public const META = '_cereal_images';

    /**
     * @param list<Encoder> $encoders
     * @param list<Optimizer> $optimizers
     */
    public function __construct(
        private readonly array $encoders,
        private readonly array $optimizers,
    ) {}

    public static function make(
        int $avifQuality = 50,
        int $webpQuality = 80,
        int $avifSpeed = 8,
        bool $lossy = true,
    ): self {
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
        $this->adoptOriginalImage($attachmentId);
        $report = [];

        foreach (Attachment::files($attachmentId) as $file) {
            $backup = Backup::ensure($file);

            // Sans sauvegarde, on ne modifie pas le fichier, mais les sidecars restent possibles depuis celui-ci.
            $source = $backup === 'error' ? $file : (string) Backup::path($file);
            $optimization = $backup === 'error' ? $this->untouched($file) : $this->optimize($file);

            $report[] = [
                'file' => $file,
                'backup' => $backup,
                'optimization' => $optimization,
                'sidecars' => $this->sidecars($source, $file, $optimization['after']),
            ];
        }

        if ($report) {
            $summary = self::summary($report);
            update_post_meta($attachmentId, self::META, [
                'version' => VERSION,
                'date' => time(),
                'before' => $summary['before'],
                'after' => $summary['after'],
            ]);
        }

        return $report;
    }

    /**
     * Poids servi avant le passage, puis après pour chaque format. Un sidecar rejeté compte le JPG/PNG, c'est ce que reçoit le navigateur.
     *
     * @param list<FileReport> $report
     * @return Summary
     */
    public static function summary(array $report): array
    {
        $summary = ['before' => 0, 'after' => ['jpg/png' => 0], 'time' => ['jpg/png' => 0.0]];

        foreach ($report as $item) {
            $summary['before'] += $item['optimization']['before'];
            $summary['after']['jpg/png'] += $item['optimization']['after'];
            $summary['time']['jpg/png'] += $item['optimization']['time'];

            foreach ($item['sidecars'] as $sidecar) {
                $format = $sidecar['format'];
                $summary['after'][$format] =
                    ($summary['after'][$format] ?? 0)
                    + ($sidecar['status'] === 'ok' ? $sidecar['result'] : $sidecar['served']);
                $summary['time'][$format] = ($summary['time'][$format] ?? 0.0) + $sidecar['time'];
            }
        }

        return $summary;
    }

    /**
     * Fichiers à signaler : sans sauvegarde, en erreur ou en collision avec une pièce jointe.
     *
     * @param list<FileReport> $report
     * @return list<string>
     */
    public static function errors(int $attachmentId, array $report): array
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
     * @param Summary $a
     * @param Summary $b
     * @return Summary
     */
    public static function mergeSummaries(array $a, array $b): array
    {
        $merged = ['before' => $a['before'] + $b['before'], 'after' => $a['after'], 'time' => $a['time']];

        foreach ($b['after'] as $label => $after) {
            $merged['after'][$label] = ($merged['after'][$label] ?? 0) + $after;
            $merged['time'][$label] = ($merged['time'][$label] ?? 0.0) + ($b['time'][$label] ?? 0.0);
        }

        return $merged;
    }

    /**
     * L'original non réduit n'est ni optimisé ni converti, mais sa sauvegarde ShortPixel est reprise :
     * sinon elle resterait seule dans un dossier voué à être supprimé.
     */
    private function adoptOriginalImage(int $attachmentId): void
    {
        $original = Attachment::originalImage($attachmentId);

        if ($original !== null) {
            Backup::adoptShortPixelOriginal($original);
        }
    }

    /**
     * Profil couleur non sRGB : GD l'ignorerait, on encode depuis une copie convertie en sRGB par Imagick.
     *
     * @return list<Sidecar>
     */
    private function sidecars(string $source, string $file, int $servedSize): array
    {
        if (ColorProfile::isSafeForGd($source)) {
            return $this->encodeAll($source, $file, $servedSize);
        }

        // Même extension que l'image : les encodeurs choisissent leur lecture d'après elle.
        $srgb = (string) preg_replace('/(\.[^.]+)$/', '.cereal-tmp-srgb$1', $file);

        if (! ColorConverter::toSrgb($source, $srgb)) {
            return $this->skipForColorProfile($file, $servedSize);
        }

        try {
            return $this->encodeAll($srgb, $file, $servedSize);
        } finally {
            Files::remove($srgb);
        }
    }

    /** @return list<Sidecar> */
    private function encodeAll(string $source, string $file, int $servedSize): array
    {
        return array_map(fn (Encoder $encoder) => [
            'format' => $encoder->format(),
            ...$this->convert($source, $file, $servedSize, $encoder),
        ], $this->encoders);
    }

    /**
     * Profil couleur non sRGB et Imagick absent : l'original est servi, un ancien sidecar (aux couleurs faussées) est supprimé.
     *
     * @return list<Sidecar>
     */
    private function skipForColorProfile(string $file, int $servedSize): array
    {
        Sidecars::remove($file);

        return array_map(fn (Encoder $encoder) => [
            'format' => $encoder->format(),
            'status' => 'icc',
            'served' => $servedSize,
            'result' => 0,
            'time' => 0.0,
        ], $this->encoders);
    }

    /** @return list<array{file: string, status: string, removed: int}> */
    public function restoreAttachment(int $attachmentId): array
    {
        $report = [];
        $original = Attachment::originalImage($attachmentId);

        if ($original !== null && is_file((string) Backup::path($original))) {
            $report[] = [
                'file' => $original,
                'status' => Backup::restore($original) ? 'restored' : 'error',
                'removed' => 0,
            ];
        }

        foreach (Attachment::files($attachmentId) as $file) {
            $removed = Sidecars::remove($file);

            $backup = Backup::path($file);
            $status = match (true) {
                $backup === null || ! is_file($backup) => 'no-backup',
                Backup::restore($file) => 'restored',
                default => 'error',
            };

            $report[] = ['file' => $file, 'status' => $status, 'removed' => $removed];
        }

        delete_post_meta($attachmentId, self::META);

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
            Files::remove($tmp);

            return $result('error');
        }

        $start = hrtime(true);
        $optimized = $optimizer->optimize($tmp);
        $time = (hrtime(true) - $start) / 1e9;

        clearstatcache(true, $tmp);
        $after = is_file($tmp) ? (int) filesize($tmp) : 0;

        if (! $optimized || $after === 0) {
            Files::remove($tmp);

            return $result('error', 0, $time);
        }

        if ($after >= $before) {
            unlink($tmp);

            return $result('larger', $after, $time);
        }

        rename($tmp, $file);

        return $result('ok', $after, $time);
    }

    /** @return Optimization */
    private function untouched(string $file): array
    {
        $size = (int) filesize($file);

        return ['status' => 'no-backup', 'before' => $size, 'after' => $size, 'time' => 0.0];
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

        // Une pièce jointe porte déjà ce nom (photo.jpg.webp) : ni écrasée, ni supprimée.
        if (is_file($destination) && Attachment::isAttachmentFile($destination)) {
            return ['status' => 'collision', 'served' => $servedSize, 'result' => 0, 'time' => 0.0];
        }

        $start = hrtime(true);
        $encoded = $encoder->encode($source, $tmp);
        $time = (hrtime(true) - $start) / 1e9;

        clearstatcache(true, $tmp);
        $resultSize = is_file($tmp) ? (int) filesize($tmp) : 0;

        if (! $encoded || $resultSize === 0) {
            Files::remove($tmp);

            return ['status' => 'error', 'served' => $servedSize, 'result' => 0, 'time' => $time];
        }

        if ($resultSize >= $servedSize) {
            unlink($tmp);
            Files::remove($destination);

            return ['status' => 'larger', 'served' => $servedSize, 'result' => $resultSize, 'time' => $time];
        }

        rename($tmp, $destination);

        return ['status' => 'ok', 'served' => $servedSize, 'result' => $resultSize, 'time' => $time];
    }
}

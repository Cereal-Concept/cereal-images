<?php

declare(strict_types=1);

namespace CerealImages;

use CerealImages\Encoders\Encoder;
use CerealImages\Encoders\GdEncoder;

final class Converter
{
    /** @param list<Encoder> $encoders */
    public function __construct(private readonly array $encoders) {}

    public static function make(int $avifQuality = 55, int $webpQuality = 80): self
    {
        $encoders = [
            new GdEncoder('avif', $avifQuality),
            new GdEncoder('webp', $webpQuality),
        ];

        return new self(array_values(array_filter($encoders, fn (Encoder $encoder) => $encoder->isAvailable())));
    }

    /** @return list<string> */
    public function formats(): array
    {
        return array_map(fn (Encoder $encoder) => $encoder->format(), $this->encoders);
    }

    /** @return list<array{file: string, format: string, status: string, source: int, result: int, time: float}> */
    public function convertAttachment(int $attachmentId): array
    {
        $report = [];

        foreach (Attachment::files($attachmentId) as $file) {
            foreach ($this->encoders as $encoder) {
                $report[] = ['file' => $file, 'format' => $encoder->format(), ...$this->convert($file, $encoder)];
            }
        }

        return $report;
    }

    /** @return array{status: string, source: int, result: int, time: float} */
    private function convert(string $source, Encoder $encoder): array
    {
        $destination = "{$source}.{$encoder->format()}";
        $tmp = "{$destination}.tmp";
        $sourceSize = (int) filesize($source);

        $start = hrtime(true);
        $encoded = $encoder->encode($source, $tmp);
        $time = (hrtime(true) - $start) / 1e9;

        clearstatcache(true, $tmp);
        $resultSize = is_file($tmp) ? (int) filesize($tmp) : 0;

        if (! $encoded || $resultSize === 0) {
            @unlink($tmp);

            return ['status' => 'error', 'source' => $sourceSize, 'result' => 0, 'time' => $time];
        }

        if ($resultSize >= $sourceSize) {
            unlink($tmp);
            @unlink($destination);

            return ['status' => 'larger', 'source' => $sourceSize, 'result' => $resultSize, 'time' => $time];
        }

        rename($tmp, $destination);

        return ['status' => 'ok', 'source' => $sourceSize, 'result' => $resultSize, 'time' => $time];
    }
}

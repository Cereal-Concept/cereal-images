<?php

declare(strict_types=1);

namespace CerealImages;

/**
 * GD ignore les profils ICC : une photo Display P3 (iPhone, capture macOS) encodée en AVIF/WebP perdrait ses couleurs.
 * On reconnaît un profil sRGB à ses primaires plutôt qu'à son nom, qui varie selon le logiciel.
 */
final class ColorProfile
{
    /** Primaires sRGB adaptées D50, telles qu'écrites dans les profils (balises XYZ). */
    private const SRGB_PRIMARIES = [
        'rXYZ' => [0.4361, 0.2225, 0.0139],
        'gXYZ' => [0.3851, 0.7169, 0.0971],
        'bXYZ' => [0.1431, 0.0606, 0.7141],
    ];

    private const TOLERANCE = 0.005;

    /** Les segments qui précèdent l'image (EXIF, ICC) tiennent largement dans ce début de fichier. */
    private const READ_LENGTH = 1 << 20;

    /** Vrai si GD peut convertir l'image sans fausser les couleurs : pas de profil, profil sRGB ou non RGB. */
    public static function isSafeForGd(string $file): bool
    {
        $profile = self::read($file);

        return $profile === null || substr($profile, 16, 4) !== 'RGB ' || self::hasSrgbPrimaries($profile);
    }

    private static function read(string $file): ?string
    {
        $data = file_get_contents($file, false, null, 0, self::READ_LENGTH);

        if (! is_string($data)) {
            return null;
        }

        return match (true) {
            str_starts_with($data, "\xFF\xD8") => self::fromJpeg($data),
            str_starts_with($data, "\x89PNG\r\n\x1A\n") => self::fromPng($data),
            default => null,
        };
    }

    /** Profil réparti dans un ou plusieurs segments APP2 « ICC_PROFILE ». */
    private static function fromJpeg(string $data): ?string
    {
        $profile = '';

        foreach (self::jpegSegments($data) as [$marker, $segment]) {
            if ($marker !== 0xE2 || ! str_starts_with($segment, "ICC_PROFILE\0")) {
                continue;
            }

            // Après la signature : numéro du segment et nombre de segments, un octet chacun.
            $profile .= substr($segment, 14);
        }

        return $profile === '' ? null : $profile;
    }

    /**
     * Segments de métadonnées, jusqu'au début des données de l'image (SOS) ou à la fin du fichier.
     *
     * @return \Generator<int, array{int, string}>
     */
    private static function jpegSegments(string $data): \Generator
    {
        $offset = 2;

        while (($data[$offset] ?? '') === "\xFF") {
            $marker = ord($data[$offset + 1] ?? "\xD9");
            $length = self::uint16($data, $offset + 2);

            if ($marker === 0xDA || $marker === 0xD9 || $length === null || $length < 2) {
                return;
            }

            yield [$marker, substr($data, $offset + 4, $length - 2)];
            $offset += 2 + $length;
        }
    }

    /** Bloc iCCP : nom, octet nul, méthode de compression, profil compressé (zlib). Toujours avant IDAT. */
    private static function fromPng(string $data): ?string
    {
        $offset = 8;

        while (($length = self::uint32($data, $offset)) !== null) {
            $type = substr($data, $offset + 4, 4);

            if ($type === 'IDAT') {
                return null;
            }

            if ($type === 'iCCP') {
                $chunk = substr($data, $offset + 8, $length);
                $nul = strpos($chunk, "\0");
                // @mago-expect lint:no-error-control-operator Profil corrompu : avertissement puis false, géré juste après.
                $profile = $nul === false ? false : @gzuncompress(substr($chunk, $nul + 2));

                return is_string($profile) ? $profile : null;
            }

            $offset += 12 + $length;
        }

        return null;
    }

    private static function hasSrgbPrimaries(string $profile): bool
    {
        $tags = self::tags($profile);

        foreach (self::SRGB_PRIMARIES as $tag => $expected) {
            $position = $tags[$tag] ?? null;
            $xyz = $position === null ? null : self::xyz($profile, $position);

            if ($xyz === null) {
                return false;
            }

            foreach ($expected as $index => $value) {
                if (abs($xyz[$index] - $value) > self::TOLERANCE) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Table des balises après l'en-tête de 128 octets : nombre, puis signature, position et taille de chacune.
     *
     * @return array<string, int>
     */
    private static function tags(string $profile): array
    {
        $tags = [];
        $count = min(self::uint32($profile, 128) ?? 0, 100);

        for ($index = 0; $index < $count; $index++) {
            $entry = 132 + (12 * $index);
            $position = self::uint32($profile, $entry + 4);

            if ($position !== null) {
                $tags[substr($profile, $entry, 4)] = $position;
            }
        }

        return $tags;
    }

    /**
     * Type XYZ : signature, 4 octets réservés, puis trois nombres s15Fixed16.
     *
     * @return list<float>|null
     */
    private static function xyz(string $profile, int $position): ?array
    {
        if (substr($profile, $position, 4) !== 'XYZ ') {
            return null;
        }

        $values = [];

        foreach ([8, 12, 16] as $shift) {
            $raw = self::uint32($profile, $position + $shift);

            if ($raw === null) {
                return null;
            }

            $values[] = ($raw >= 0x8000_0000 ? $raw - 0x1_0000_0000 : $raw) / 65_536;
        }

        return $values;
    }

    private static function uint16(string $data, int $offset): ?int
    {
        return strlen($data) >= ($offset + 2) ? (ord($data[$offset]) << 8) | ord($data[$offset + 1]) : null;
    }

    private static function uint32(string $data, int $offset): ?int
    {
        $high = self::uint16($data, $offset);
        $low = self::uint16($data, $offset + 2);

        return $high === null || $low === null ? null : ($high << 16) | $low;
    }
}

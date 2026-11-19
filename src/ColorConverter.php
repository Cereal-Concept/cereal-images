<?php

declare(strict_types=1);

namespace CerealImages;

use Imagick;
use ImagickException;

/**
 * Conversion en sRGB avec Imagick (LittleCMS), pour donner des sidecars aux images Display P3 ou Adobe RGB
 * que GD ne sait pas convertir. Profil : sRGB-v4 de Compact-ICC-Profiles (saucecontrol), domaine public (CC0).
 */
final class ColorConverter
{
    private const PROFILE = __DIR__ . '/../assets/srgb.icc';

    /** Quasi sans perte : la copie ne sert que de source aux encodeurs, puis est supprimée. */
    private const JPEG_QUALITY = 98;

    public static function isAvailable(): bool
    {
        return class_exists(Imagick::class) && is_file(self::PROFILE);
    }

    /** Écrit dans $destination une copie convertie en sRGB, au même format que la source. */
    public static function toSrgb(string $source, string $destination): bool
    {
        if (! self::isAvailable()) {
            return false;
        }

        try {
            $image = new Imagick($source);
            $image->profileImage('icc', (string) file_get_contents(self::PROFILE));

            if ($image->getImageFormat() === 'JPEG') {
                $image->setImageCompressionQuality(self::JPEG_QUALITY);
                // Pas de sous-échantillonnage des couleurs : il s'ajouterait à celui de l'encodeur.
                $image->setSamplingFactors(['1x1', '1x1', '1x1']);
            }

            $written = $image->writeImage($destination);
            $image->clear();

            return $written;
        } catch (ImagickException) {
            Files::remove($destination);

            return false;
        }
    }
}

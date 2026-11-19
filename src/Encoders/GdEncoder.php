<?php

declare(strict_types=1);

namespace CerealImages\Encoders;

use GdImage;

final class GdEncoder implements Encoder
{
    /** @param 'avif'|'webp' $format */
    public function __construct(
        private readonly string $format,
        private readonly int $quality,
        private readonly int $speed = 8,
    ) {}

    public function format(): string
    {
        return $this->format;
    }

    /** @var array<string, bool> */
    private static array $available = [];

    /**
     * La fonction peut exister alors que GD a été compilé sans le codec (« AVIF image support has been disabled ») :
     * on encode réellement une image de 1 pixel.
     */
    public function isAvailable(): bool
    {
        return self::$available[$this->format] ??= $this->canEncode();
    }

    private function canEncode(): bool
    {
        if (! function_exists("image{$this->format}")) {
            return false;
        }

        $image = imagecreatetruecolor(1, 1);

        if ($image === false) {
            return false;
        }

        ob_start();
        // @mago-expect lint:no-error-control-operator GD émet un avertissement quand le codec est absent : c'est justement ce qu'on teste.
        $encoded = @match ($this->format) {
            'avif' => imageavif($image),
            'webp' => imagewebp($image),
        };

        $output = ob_get_clean();

        return $encoded && is_string($output) && $output !== '';
    }

    public function encode(string $source, string $destination): bool
    {
        $image = $this->load($source);

        if (! $image) {
            return false;
        }

        return match ($this->format) {
            'avif' => imageavif($image, $destination, $this->quality, $this->speed),
            'webp' => imagewebp($image, $destination, $this->quality),
        };
    }

    private function load(string $path): GdImage|false
    {
        $image = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            // @mago-expect lint:no-error-control-operator Image corrompue : GD avertit puis renvoie false, géré juste après.
            'jpg', 'jpeg' => @imagecreatefromjpeg($path),
            // @mago-expect lint:no-error-control-operator Idem.
            'png' => @imagecreatefrompng($path),
            default => false,
        };

        if (! $image) {
            return false;
        }

        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $image;
    }
}

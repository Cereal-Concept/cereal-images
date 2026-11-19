<?php

declare(strict_types=1);

namespace CerealImages\Tests\Doubles;

use CerealImages\Encoders\Encoder;

/** Écrit un fichier de taille fixe : permet de tester les règles de rejet sans dépendre de GD. */
final class FixedSizeEncoder implements Encoder
{
    public function __construct(
        private readonly string $format,
        private readonly int $bytes,
        private readonly bool $succeeds = true,
    ) {}

    public function format(): string
    {
        return $this->format;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function encode(string $source, string $destination): bool
    {
        if (! $this->succeeds) {
            return false;
        }

        return file_put_contents($destination, str_repeat('x', $this->bytes)) === $this->bytes;
    }
}

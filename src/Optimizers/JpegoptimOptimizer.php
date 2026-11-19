<?php

declare(strict_types=1);

namespace CerealImages\Optimizers;

use CerealImages\Shell;

final class JpegoptimOptimizer implements Optimizer
{
    public function __construct(
        private readonly bool $lossy,
        private readonly int $quality = 82,
    ) {}

    public function name(): string
    {
        return 'jpegoptim';
    }

    public function supports(string $file): bool
    {
        return (bool) preg_match('/\.jpe?g$/i', $file);
    }

    public function isAvailable(): bool
    {
        return Shell::has('jpegoptim');
    }

    public function optimize(string $path): bool
    {
        // Pas de --strip-all : il retire aussi le profil ICC et ternirait les photos en Display P3.
        $args = ['--quiet', '--all-progressive', '--strip-com', '--strip-exif', '--strip-iptc', '--strip-xmp'];

        if ($this->lossy) {
            $args[] = "--max={$this->quality}";
        }

        return Shell::run('jpegoptim', [...$args, $path]);
    }
}

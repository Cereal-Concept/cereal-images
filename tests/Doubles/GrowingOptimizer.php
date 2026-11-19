<?php

declare(strict_types=1);

namespace CerealImages\Tests\Doubles;

use CerealImages\Optimizers\Optimizer;

/** Alourdit le fichier : le résultat doit être jeté. */
final class GrowingOptimizer implements Optimizer
{
    public function name(): string
    {
        return 'growing';
    }

    public function supports(string $file): bool
    {
        return true;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function optimize(string $path): bool
    {
        return file_put_contents($path, str_repeat('x', 1024), FILE_APPEND) !== false;
    }
}

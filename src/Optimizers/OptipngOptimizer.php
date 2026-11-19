<?php

declare(strict_types=1);

namespace CerealImages\Optimizers;

use CerealImages\Shell;

final class OptipngOptimizer implements Optimizer
{
    public function name(): string
    {
        return 'optipng';
    }

    public function supports(string $file): bool
    {
        return (bool) preg_match('/\.png$/i', $file);
    }

    public function isAvailable(): bool
    {
        return Shell::has('optipng');
    }

    public function optimize(string $path): bool
    {
        return Shell::run('optipng', ['-quiet', '-o2', '-strip', 'all', $path]);
    }
}

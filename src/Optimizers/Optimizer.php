<?php

declare(strict_types=1);

namespace CerealImages\Optimizers;

interface Optimizer
{
    public function name(): string;

    public function supports(string $file): bool;

    public function isAvailable(): bool;

    /** Optimise le fichier sur place. */
    public function optimize(string $path): bool;
}

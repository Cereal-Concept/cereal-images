<?php

declare(strict_types=1);

namespace CerealImages\Encoders;

interface Encoder
{
    public function format(): string;

    public function isAvailable(): bool;

    public function encode(string $source, string $destination): bool;
}

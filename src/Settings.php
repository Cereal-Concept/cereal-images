<?php

declare(strict_types=1);

namespace CerealImages;

/**
 * Réglages enregistrés depuis la page d'admin, utilisés par le traitement automatique et par défaut en WP-CLI.
 *
 * @phpstan-type Values array{avif_quality: int, webp_quality: int, avif_speed: int, lossless: bool}
 */
final class Settings
{
    public const OPTION = 'cereal_images_settings';

    /** @var Values */
    public const DEFAULTS = ['avif_quality' => 50, 'webp_quality' => 80, 'avif_speed' => 8, 'lossless' => false];

    /** @var array<'avif_quality'|'webp_quality'|'avif_speed', array{int, int}> */
    public const RANGES = ['avif_quality' => [0, 100], 'webp_quality' => [0, 100], 'avif_speed' => [0, 10]];

    /** @return Values */
    public static function get(): array
    {
        return self::sanitize(get_option(self::OPTION, []));
    }

    /**
     * Valeur hors bornes ou absente : valeur par défaut.
     *
     * @return Values
     */
    public static function sanitize(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $values = self::DEFAULTS;

        foreach (self::RANGES as $key => [$min, $max]) {
            $value = filter_var($input[$key] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => $min, 'max_range' => $max],
            ]);
            $values[$key] = $value === false ? self::DEFAULTS[$key] : $value;
        }

        $values['lossless'] = filter_var($input['lossless'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return $values;
    }

    /**
     * Les valeurs passées (options WP-CLI) priment sur les réglages enregistrés.
     *
     * @param array<string, mixed> $overrides
     */
    public static function processor(array $overrides = []): Processor
    {
        $settings = self::sanitize([...self::get(), ...$overrides]);

        return Processor::make(
            $settings['avif_quality'],
            $settings['webp_quality'],
            $settings['avif_speed'],
            ! $settings['lossless'],
        );
    }
}

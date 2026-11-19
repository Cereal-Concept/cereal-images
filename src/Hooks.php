<?php

declare(strict_types=1);

namespace CerealImages;

final class Hooks
{
    public const EVENT = 'cereal_images_process';

    /** WordPress met à jour les métadonnées après chaque taille générée : on laisse finir avant de traiter. */
    private const DELAY = 60;

    public static function register(): void
    {
        // Couvre l'upload, la régénération des tailles et les modifications dans l'éditeur d'images.
        add_filter('wp_update_attachment_metadata', [self::class, 'schedule'], 10, 2);
        add_action(self::EVENT, [self::class, 'process']);
    }

    public static function schedule(mixed $metadata, int $attachmentId): mixed
    {
        // Le traitement AVIF est trop lent pour la requête d'upload : il est planifié.
        if (Attachment::isProcessable($attachmentId) && ! wp_next_scheduled(self::EVENT, [$attachmentId])) {
            wp_schedule_single_event(time() + self::DELAY, self::EVENT, [$attachmentId]);
        }

        return $metadata;
    }

    public static function process(int|string $attachmentId): void
    {
        Processor::make()->processAttachment((int) $attachmentId);
    }
}

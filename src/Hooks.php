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
        // En dernier : une extension qui annule la suppression a déjà vidé le chemin.
        add_filter('wp_delete_file', [self::class, 'purge'], PHP_INT_MAX);
    }

    public static function activate(): void
    {
        Htaccess::install();
    }

    /** Sidecars et sauvegardes restent : sans le bloc .htaccess, ils ne sont plus servis. */
    public static function deactivate(): void
    {
        Htaccess::remove();
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

    /**
     * Appelé pour chaque fichier supprimé par WordPress : principal, tailles, original non réduit, anciennes versions
     * de l'éditeur. Un chemin vide signale une suppression annulée (Polylang : fichier partagé entre traductions).
     */
    public static function purge(mixed $file): mixed
    {
        if (is_string($file) && $file !== '') {
            Sidecars::remove($file);
            Files::remove((string) Backup::path($file));
        }

        return $file;
    }
}

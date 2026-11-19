<?php

declare(strict_types=1);

namespace CerealImages\Admin;

use CerealImages\Attachment;
use CerealImages\Processor;
use CerealImages\Settings;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Traitement en masse depuis la page d'admin : le navigateur demande la liste puis traite une pièce jointe par requête,
 * ce qui reste loin du max_execution_time quelle que soit la taille de la médiathèque.
 */
final class Rest
{
    public const NAMESPACE = 'cereal-images/v1';

    public static function register(): void
    {
        add_action('rest_api_init', [self::class, 'registerRoutes']);
    }

    public static function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/queue', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'queue'],
            'permission_callback' => [self::class, 'canManage'],
            'args' => [
                'mode' => [
                    'type' => 'string',
                    'enum' => ['unprocessed', 'processed', 'all'],
                    'default' => 'unprocessed',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/restore/(?P<id>\d+)', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'restore'],
            'permission_callback' => [self::class, 'canManage'],
            'args' => ['id' => ['type' => 'integer', 'required' => true]],
        ]);

        register_rest_route(self::NAMESPACE, '/process/(?P<id>\d+)', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'process'],
            'permission_callback' => [self::class, 'canManage'],
            'args' => ['id' => ['type' => 'integer', 'required' => true]],
        ]);
    }

    public static function canManage(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     * @return array{ids: list<int>}
     */
    public static function queue(WP_REST_Request $request): array
    {
        return ['ids' => match ($request->get_param('mode')) {
            'all' => Attachment::ids(),
            'processed' => array_values(array_diff(Attachment::ids(), Attachment::unprocessedIds())),
            default => Attachment::unprocessedIds(),
        }];
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     * @return array{id: int, files: int, errors: list<string>}
     */
    public static function process(WP_REST_Request $request): array
    {
        $id = $request->get_param('id');

        return self::processAttachment(Settings::processor(), is_numeric($id) ? (int) $id : 0);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     * @return array{id: int, files: int, errors: list<string>}
     */
    public static function restore(WP_REST_Request $request): array
    {
        $id = $request->get_param('id');

        return self::restoreAttachment(Processor::make(), is_numeric($id) ? (int) $id : 0);
    }

    /** @return array{id: int, files: int, errors: list<string>} */
    public static function restoreAttachment(Processor $processor, int $id): array
    {
        $report = $processor->restoreAttachment($id);
        $errors = array_filter($report, static fn (array $item) => $item['status'] === 'error');

        return [
            'id' => $id,
            'files' => count($report),
            'errors' => array_values(array_map(
                static fn (array $item) => "#{$id} " . basename($item['file']) . ' : restauration impossible',
                $errors,
            )),
        ];
    }

    /** @return array{id: int, files: int, errors: list<string>} */
    public static function processAttachment(Processor $processor, int $id): array
    {
        $report = $processor->processAttachment($id);

        return ['id' => $id, 'files' => count($report), 'errors' => Processor::errors($id, $report)];
    }
}

<?php

declare(strict_types=1);

namespace Plugin\ExamplePlugin;

use CMS\Core\Http\JsonResponse;
use CMS\Core\Http\Request;
use CMS\Modules\Plugin\Contracts\PluginInterface;
use CMS\Modules\Plugin\Service\PluginContext;

/**
 * The acceptance test for the plugin platform, in object form.
 *
 * This class registers all seven contribution points **without touching core**:
 *
 *   1. a content type   (`note`)
 *   2. a taxonomy       (`note_channel`)
 *   3. a block          (`note_callout`)
 *   4. an action        (`note.created`)
 *   5. a filter         (`note.title`)
 *   6. an API route     (GET /api/v1/example/notes/ping)
 *   7. a capability     (`example.read_notes`)
 *
 * If any of these needs a change in `app/`, the platform has failed its own
 * requirement.
 */
final class Plugin implements PluginInterface
{
    public function register(PluginContext $context): void
    {
        // 1. Content type.
        $context->contentType('note', [
            'label' => 'Notes',
            'description' => 'Short internal notes written by plugin code.',
            'supports' => ['title', 'editor', 'excerpt'],
            'public' => false,
        ]);

        // 2. Taxonomy.
        $context->taxonomy('note_channel', [
            'label' => 'Note channels',
            'hierarchical' => false,
        ]);

        // 3. Block.
        $context->block('note_callout', [
            'label' => 'Note callout',
            'version' => 1,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'text' => ['type' => 'string'],
                    'tone' => ['type' => 'string', 'enum' => ['info', 'warning']],
                ],
                'required' => ['text'],
            ],
        ]);

        // 4. Action: another plugin or the host may react to note creation.
        $context->action('note.created', static function (int $noteId): void {
            // A real plugin would write an audit row here. Kept empty on purpose: this
            // plugin exists to prove the hook fires, not to do work.
        });

        // 5. Filter: let a site change how note titles are displayed.
        $context->filter('note.title', static function (string $title): string {
            return 'Note: ' . $title;
        });

        // 6. API route. The platform gates it per tenant: on a site where this plugin
        //    is not active the endpoint answers 404 rather than running.
        $context->route('GET', '/api/v1/example/notes/ping', static function (Request $request): JsonResponse {
            return new JsonResponse([
                'plugin' => 'example-plugin',
                'status' => 'ok',
            ]);
        });

        // 7. Capability. Identity seeds it on the next seeding run.
        $context->capability(
            'example.read_notes',
            'Read plugin notes',
            'View notes created by the example plugin.',
            ['administrator', 'editor']
        );
    }
}

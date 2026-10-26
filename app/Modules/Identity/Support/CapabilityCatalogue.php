<?php

declare(strict_types=1);

namespace CMS\Modules\Identity\Support;

/**
 * Baseline capability catalogue and the default role -> capability matrix.
 *
 * This is DATA, not logic. Authorisation checks never hardcode role names or
 * capability lists: they read the resolved capability set that this matrix
 * seeds into the database. Agents and site admins can extend the matrix
 * without touching authorisation code.
 *
 * Slugs follow `<resource>.<action>` per the architecture specification.
 */
final class CapabilityCatalogue
{
    /** @var array<string, array{name: string, description: string}> */
    private const CAPABILITIES = [
        // Content
        'content.read' => ['name' => 'Read content', 'description' => 'View content in the admin interface.'],
        'content.create' => ['name' => 'Create content', 'description' => 'Create new articles and pages.'],
        'content.update' => ['name' => 'Update content', 'description' => 'Edit existing content.'],
        'content.delete' => ['name' => 'Delete content', 'description' => 'Delete content.'],
        'content.publish' => ['name' => 'Publish content', 'description' => 'Publish or unpublish content.'],

        // Workflow
        'workflow.submit' => ['name' => 'Submit for review', 'description' => 'Submit drafts for editorial review.'],
        'workflow.review' => ['name' => 'Review content', 'description' => 'Approve or reject submitted content.'],

        // Media
        'media.read' => ['name' => 'Read media', 'description' => 'Browse the media library.'],
        'media.upload' => ['name' => 'Upload media', 'description' => 'Upload media files.'],
        'media.delete' => ['name' => 'Delete media', 'description' => 'Delete media files.'],

        // Advertising. Raw HTML: administrator only, deliberately not granted to
        // editors, because an ad body is executable markup printed on every page.
        'ads.manage' => ['name' => 'Manage ads', 'description' => 'Create and edit ad placements.'],

        // Comments
        'comment.moderate' => ['name' => 'Moderate comments', 'description' => 'Approve, mark as spam, or delete comments.'],

        // Taxonomy
        'taxonomy.manage' => ['name' => 'Manage taxonomy', 'description' => 'Create and edit categories and tags.'],

        // Users & roles
        'users.read' => ['name' => 'Read users', 'description' => 'List users on the site.'],
        'users.create' => ['name' => 'Create users', 'description' => 'Invite or create users.'],
        'users.update' => ['name' => 'Update users', 'description' => 'Edit user profiles and status.'],
        'users.delete' => ['name' => 'Delete users', 'description' => 'Delete users.'],
        'roles.assign' => ['name' => 'Assign roles', 'description' => 'Grant or revoke roles for users.'],

        // Settings & site
        'settings.read' => ['name' => 'Read settings', 'description' => 'View site settings.'],
        'settings.update' => ['name' => 'Update settings', 'description' => 'Change site settings.'],

        // Plugins & themes
        'plugins.manage' => ['name' => 'Manage plugins', 'description' => 'Enable, disable and configure plugins.'],
        'themes.manage' => ['name' => 'Manage themes', 'description' => 'Switch and configure themes.'],

        // Cache & operations
        'cache.purge' => ['name' => 'Purge cache', 'description' => 'Manually purge the site page cache.'],
    ];

    /** @var array<string, list<string>> */
    private const ROLE_MATRIX = [
        'administrator' => [
            'content.read', 'content.create', 'content.update', 'content.delete', 'content.publish',
            'workflow.submit', 'workflow.review',
            'media.read', 'media.upload', 'media.delete',
            'comment.moderate',
            'ads.manage',
            'taxonomy.manage',
            'users.read', 'users.create', 'users.update', 'users.delete', 'roles.assign',
            'settings.read', 'settings.update',
            'plugins.manage', 'themes.manage',
            'cache.purge',
        ],
        'editor' => [
            'content.read', 'content.create', 'content.update', 'content.delete', 'content.publish',
            'workflow.submit', 'workflow.review',
            'media.read', 'media.upload', 'media.delete',
            'comment.moderate',
            'taxonomy.manage',
            'users.read',
            'settings.read',
            'cache.purge',
        ],
        'reviewer' => [
            'content.read', 'content.update',
            'workflow.review',
            'media.read', 'media.upload',
            'settings.read',
        ],
        'author' => [
            'content.read', 'content.create', 'content.update',
            'workflow.submit',
            'media.read', 'media.upload',
        ],
        'contributor' => [
            'content.read', 'content.create',
            'workflow.submit',
            'media.read', 'media.upload',
        ],
        'subscriber' => [
            'content.read',
        ],
    ];

    /** @return array<string, array{name: string, description: string}> */
    public static function capabilities(): array
    {
        return self::CAPABILITIES;
    }

    /** @return array<string, list<string>> */
    public static function roleMatrix(): array
    {
        return self::ROLE_MATRIX;
    }

    /** @return list<string> */
    public static function capabilitiesFor(string $roleSlug): array
    {
        return self::ROLE_MATRIX[$roleSlug] ?? [];
    }
}

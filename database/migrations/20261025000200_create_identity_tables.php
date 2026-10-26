<?php

declare(strict_types=1);

use CMS\Core\Database\Connection;
use CMS\Core\Database\Migrations\MigrationInterface;

/**
 * Identity, authentication and RBAC schema.
 *
 * Owns: users, roles, capabilities, role_capabilities, user_roles,
 * password_resets. Follows docs/02-Database-Schema.md exactly.
 *
 * Multisite rules encoded here:
 *  - users are unique per (site_id, email) and per (site_id, username)
 *  - the same email/username may exist on two different sites
 *  - roles are unique per (site_id, slug); capabilities are global
 *  - password reset tokens are stored hashed only
 */
return new class implements MigrationInterface {
    public function up(Connection $db): void
    {
        $db->statement(
            'CREATE TABLE IF NOT EXISTS `users` (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT UNSIGNED NOT NULL,
                username VARCHAR(80) NOT NULL,
                email VARCHAR(190) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                display_name VARCHAR(150) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'active\',
                last_login_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_users_username (site_id, username),
                UNIQUE KEY uq_users_email (site_id, email),
                KEY idx_users_site_status (site_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS `roles` (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT UNSIGNED NOT NULL,
                name VARCHAR(80) NOT NULL,
                slug VARCHAR(80) NOT NULL,
                is_system TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_roles_slug (site_id, slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS `capabilities` (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                capability_key VARCHAR(120) NOT NULL,
                description VARCHAR(255) NULL,
                UNIQUE KEY uq_capability_key (capability_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS `role_capabilities` (
                role_id BIGINT UNSIGNED NOT NULL,
                capability_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (role_id, capability_id),
                KEY idx_role_capabilities_capability (capability_id),
                CONSTRAINT fk_role_capabilities_role FOREIGN KEY (role_id)
                    REFERENCES `roles` (id) ON DELETE CASCADE,
                CONSTRAINT fk_role_capabilities_capability FOREIGN KEY (capability_id)
                    REFERENCES `capabilities` (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS `user_roles` (
                user_id BIGINT UNSIGNED NOT NULL,
                role_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (user_id, role_id),
                KEY idx_user_roles_role (role_id),
                CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id)
                    REFERENCES `users` (id) ON DELETE CASCADE,
                CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id)
                    REFERENCES `roles` (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $db->statement(
            'CREATE TABLE IF NOT EXISTS `password_resets` (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                token_hash VARCHAR(255) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                KEY idx_password_reset_user (site_id, user_id),
                KEY idx_password_reset_expiry (expires_at),
                CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id)
                    REFERENCES `users` (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(Connection $db): void
    {
        $db->statement('DROP TABLE IF EXISTS `password_resets`');
        $db->statement('DROP TABLE IF EXISTS `user_roles`');
        $db->statement('DROP TABLE IF EXISTS `role_capabilities`');
        $db->statement('DROP TABLE IF EXISTS `capabilities`');
        $db->statement('DROP TABLE IF EXISTS `roles`');
        $db->statement('DROP TABLE IF EXISTS `users`');
    }
};

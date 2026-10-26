<?php

declare(strict_types=1);

namespace CMS\Modules\Migration;

use CMS\Core\Bootstrap\ServiceProviderInterface;
use CMS\Core\Container\Container;
use CMS\Core\Database\Connection;
use CMS\Modules\Comment\Contracts\CommentServiceInterface;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Content\Contracts\UrlGeneratorInterface;
use CMS\Modules\Identity\Contracts\UserServiceInterface;
use CMS\Modules\Media\Contracts\MediaServiceInterface;
use CMS\Modules\Migration\Contracts\MigrationServiceInterface;
use CMS\Modules\Migration\Repository\MigrationRepositoryInterface;
use CMS\Modules\Migration\Repository\PdoMigrationRepository;
use CMS\Modules\Migration\Service\MigrationService;
use CMS\Modules\Migration\Source\WordPressSource;
use CMS\Modules\Seo\Contracts\RedirectServiceInterface;
use CMS\Modules\Seo\Contracts\SeoServiceInterface;
use CMS\Modules\Site\Contracts\SiteServiceInterface;
use CMS\Modules\Taxonomy\Contracts\TaxonomyServiceInterface;
use CMS\Shared\Contracts\ConfigInterface;
use PDO;

/**
 * Wires the WordPress migration.
 *
 * The source is a **separate PDO**. It points at a foreign, legacy database, and reading it
 * through the platform's own `Connection` would blur the boundary between our data and
 * someone else's — and would let a migration query run inside our transaction.
 *
 * If `migration.source_dsn` is empty the source falls back to the platform connection with
 * a configurable prefix, which is the common case when WordPress and the new CMS share a
 * database during a cutover.
 */
final class MigrationServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        $container->singleton(
            MigrationRepositoryInterface::class,
            static fn (Container $c): MigrationRepositoryInterface => new PdoMigrationRepository(
                $c->get(Connection::class)
            )
        );

        $container->singleton(WordPressSource::class, static function (Container $c): WordPressSource {
            /** @var ConfigInterface $config */
            $config = $c->get(ConfigInterface::class);

            $dsn = (string) $config->get('migration.source_dsn', '');
            $prefix = (string) $config->get('migration.source_prefix', 'wp_');

            $pdo = $dsn === ''
                ? $c->get(Connection::class)->pdo()
                : new PDO(
                    $dsn,
                    (string) $config->get('migration.source_username', ''),
                    (string) $config->get('migration.source_password', ''),
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        // The source is read-only; emulation off keeps the driver honest
                        // about what it can and cannot do.
                        PDO::ATTR_EMULATE_PREPARES => false,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    ],
                );

            return new WordPressSource($pdo, $prefix);
        });

        $container->singleton(
            MigrationServiceInterface::class,
            static fn (Container $c): MigrationServiceInterface => new MigrationService(
                $c->get(WordPressSource::class),
                $c->get(MigrationRepositoryInterface::class),
                $c->get(ContentServiceInterface::class),
                $c->get(TaxonomyServiceInterface::class),
                $c->get(MediaServiceInterface::class),
                $c->get(CommentServiceInterface::class),
                $c->get(UserServiceInterface::class),
                $c->get(SeoServiceInterface::class),
                $c->get(RedirectServiceInterface::class),
                $c->get(UrlGeneratorInterface::class),
                $c->get(SiteServiceInterface::class),
                (string) $c->get(ConfigInterface::class)->get('migration.uploads_path', ''),
                'run_' . bin2hex(random_bytes(6)),
            )
        );
    }

    public function boot(Container $container): void
    {
        // No routes: the CLI is the normal runner, and the API only exposes the
        // inspect-and-run-one-batch endpoints.
    }
}

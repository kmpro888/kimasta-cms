<?php

declare(strict_types=1);

namespace CMS\Modules\Admin\Http\Controller;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Modules\Admin\Http\AdminController;
use CMS\Modules\Audit\Contracts\AuditLoggerInterface;
use CMS\Modules\Admin\Support\AdminView;
use CMS\Modules\Identity\Contracts\ActorProviderInterface;
use CMS\Modules\Identity\Contracts\RoleServiceInterface;
use CMS\Modules\Identity\Contracts\UserServiceInterface;
use CMS\Modules\Identity\Exceptions\DuplicateUserException;
use CMS\Modules\Identity\Support\PasswordPolicy;
use CMS\Modules\Cache\Contracts\PageCacheInterface;
use CMS\Modules\Site\Contracts\SiteServiceInterface;
use CMS\Modules\Site\Exceptions\DuplicateDomainException;
use CMS\Modules\Site\Exceptions\InvalidHostException;
use CMS\Modules\Site\Exceptions\SiteNotFoundException;
use CMS\Modules\Taxonomy\Contracts\TaxonomyServiceInterface;
use CMS\Shared\Contracts\CsrfTokenManagerInterface;
use CMS\Shared\Contracts\SessionInterface;

/**
 * Sites and their domains.
 *
 * Creating a site was previously a CLI snippet or SQL. It is more than an insert: the domain row,
 * the compiled domain map, the role catalogue and the taxonomy defaults all have to exist before the
 * tenant is usable — and a site nobody can sign in to is not usable either, so the form creates its
 * first administrator too.
 *
 * There is no site switcher here, and that is deliberate: users are tenant-scoped (`users.site_id`),
 * so an account belongs to one site. An operator manages each site by signing in at its own domain.
 * A switcher would need accounts shared across tenants, which is the isolation this project chose
 * not to have.
 */
final class SiteController extends AdminController
{
    public function __construct(
        AdminView $view,
        SessionInterface $session,
        CsrfTokenManagerInterface $csrf,
        ActorProviderInterface $actors,
        private readonly SiteServiceInterface $sites,
        private readonly RoleServiceInterface $roles,
        private readonly UserServiceInterface $users,
        private readonly TaxonomyServiceInterface $taxonomy,
        private readonly AuditLoggerInterface $audit,
        private readonly PageCacheInterface $cache,
    ) {
        parent::__construct($view, $session, $csrf, $actors);
    }

    public function index(Request $request): Response
    {
        $this->requireCapability($request, 'settings.update');

        return $this->page($request);
    }

    public function store(Request $request): Response
    {
        $this->requireCapability($request, 'settings.update');

        $name = trim($this->stringParam($request, 'name'));
        $slug = strtolower(trim($this->stringParam($request, 'slug')));
        $domain = trim($this->stringParam($request, 'domain'));
        $locale = trim($this->stringParam($request, 'default_locale', 'en'));
        $timezone = trim($this->stringParam($request, 'timezone', 'UTC'));

        $adminUser = trim($this->stringParam($request, 'admin_username'));
        $adminEmail = trim($this->stringParam($request, 'admin_email'));
        $adminPassword = $this->stringParam($request, 'admin_password');

        $errors = [];

        if ($name === '') {
            $errors['name'] = 'A site needs a name.';
        }

        if ($slug === '' || preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $slug) !== 1) {
            $errors['slug'] = 'Use lowercase letters, digits and hyphens.';
        }

        if ($domain === '') {
            $errors['domain'] = 'A site needs a domain: that is how it is reached.';
        }

        if ($adminUser === '' || $adminEmail === '') {
            $errors['admin_username'] = 'The first administrator needs a username and an email.';
        }

        if (mb_strlen($adminPassword) < PasswordPolicy::MIN_LENGTH) {
            $errors['admin_password'] = 'Use at least ' . PasswordPolicy::MIN_LENGTH . ' characters.';
        }

        if ($errors !== []) {
            return $this->page($request, $errors, 422);
        }

        try {
            $site = $this->sites->create(
                name: $name,
                slug: $slug,
                primaryDomain: $domain,
                defaultLocale: $locale,
                timezone: $timezone,
                baseUrl: 'http://' . $domain,
            );
        } catch (DuplicateDomainException|InvalidHostException $exception) {
            return $this->page($request, ['domain' => $exception->getMessage()], 422);
        } catch (\InvalidArgumentException $exception) {
            return $this->page($request, ['slug' => $exception->getMessage()], 422);
        } catch (\PDOException $exception) {
            // A duplicate slug reaches the unique index rather than a validation rule. 23000 is
            // the SQLSTATE for an integrity violation; anything else is a real failure and must
            // not be disguised as a form error.
            if ($exception->getCode() === '23000') {
                return $this->page($request, ['slug' => 'That slug is already in use.'], 422);
            }

            throw $exception;
        }

        $siteId = $site->id();

        // A tenant without roles cannot appoint anybody, and without taxonomies it cannot classify
        // anything; both are part of what "the site exists" means.
        $this->roles->seedDefaults($siteId);
        $this->taxonomy->seedDefaults($siteId);

        try {
            $user = $this->users->create($siteId, [
                'username' => $adminUser,
                'email' => $adminEmail,
                'password' => $adminPassword,
                'display_name' => $adminUser,
            ]);
        } catch (DuplicateUserException $exception) {
            $this->flash('error', 'Site created, but the first administrator could not be: '
                . $exception->getMessage());

            return $this->redirect('/admin/sites');
        }

        foreach ($this->roles->listForSite($siteId) as $role) {
            if ($role->slug === 'administrator') {
                $this->roles->assignRole((int) $user->id, (int) $role->id, $siteId);
            }
        }

        $this->audit->record($siteId, $this->actor($request), 'site.created', 'site', $siteId, ['name' => $name, 'domain' => $domain]);
        $this->flash('success', 'Created ' . $name . '. Sign in at http://' . $domain
            . '/admin/login as ' . $adminUser . '.');

        return $this->redirect('/admin/sites');
    }

    /**
     * Switch a site on or off.
     *
     * Deactivating is the closest thing this platform has to "take the site down": the public
     * controller answers 404 and the page cache is purged, while the admin stays reachable at the
     * site's own domain so the operator can turn it back on.
     */
    public function status(Request $request): Response
    {
        $this->requireCapability($request, 'settings.update');

        $siteId = $this->intParam($request, 'site_id');
        $status = $this->stringParam($request, 'status');

        try {
            $site = $this->sites->setStatus($siteId, $status);
        } catch (SiteNotFoundException) {
            throw HttpException::notFound();
        } catch (\InvalidArgumentException $exception) {
            $this->flash('error', $exception->getMessage());

            return $this->redirect('/admin/sites');
        }

        // The public controller checks status after the cache, so the entry has to go when the site
        // is switched off: otherwise a page cached while it was active outlives the decision.
        if (!$site->isActive()) {
            $this->cache->flushSite($siteId);
        }

        $this->audit->record(
            $siteId,
            $this->actor($request),
            'site.status_changed',
            'site',
            $siteId,
            ['status' => $site->isActive() ? 'active' : 'inactive']
        );

        $this->flash('success', $site->name() . ' is now ' . ($site->isActive() ? 'active' : 'inactive') . '.');

        return $this->redirect('/admin/sites');
    }
    public function addDomain(Request $request): Response
    {
        $this->requireCapability($request, 'settings.update');

        $siteId = $this->intParam($request, 'site_id');
        $domain = trim($this->stringParam($request, 'domain'));

        try {
            $this->sites->addDomain($siteId, $domain);
            $this->flash('success', 'Added ' . $domain . '.');
        } catch (DuplicateDomainException) {
            $this->flash('error', 'That domain is already mapped.');
        } catch (InvalidHostException|\InvalidArgumentException $exception) {
            $this->flash('error', $exception->getMessage());
        } catch (SiteNotFoundException) {
            throw HttpException::notFound();
        }

        return $this->redirect('/admin/sites');
    }

    public function removeDomain(Request $request): Response
    {
        $this->requireCapability($request, 'settings.update');

        $siteId = $this->intParam($request, 'site_id');
        $domain = trim($this->stringParam($request, 'domain'));

        try {
            $site = $this->sites->findById($siteId);

            if ($domain === $site->host()) {
                $this->flash('error', 'The primary domain cannot be removed: that is how the site is reached.');

                return $this->redirect('/admin/sites');
            }

            $this->sites->removeDomain($siteId, $domain);
            $this->flash('success', 'Removed ' . $domain . '.');
        } catch (SiteNotFoundException) {
            throw HttpException::notFound();
        }

        return $this->redirect('/admin/sites');
    }

    /**
     * @param array<string, string> $errors
     */
    private function page(Request $request, array $errors = [], int $status = 200): Response
    {
        $rows = [];

        foreach ($this->sites->all() as $site) {
            $rows[] = [
                'site' => $site,
                'domains' => $this->sites->domains($site->id()),
            ];
        }

        return $this->html($request, 'sites/index', 'Sites', [
            'rows' => $rows,
            'errors' => $errors,
            'minimum' => PasswordPolicy::MIN_LENGTH,
        ], $status);
    }
}

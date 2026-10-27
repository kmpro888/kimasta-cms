<?php

declare(strict_types=1);

namespace CMS\Modules\Admin\Http\Controller;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Modules\Admin\Http\AdminController;
use CMS\Modules\Admin\Support\AdminView;
use CMS\Modules\Identity\Contracts\ActorProviderInterface;
use CMS\Modules\Identity\Contracts\PasswordResetServiceInterface;
use CMS\Modules\Identity\Contracts\UserServiceInterface;
use CMS\Shared\Contracts\CsrfTokenManagerInterface;
use CMS\Shared\Contracts\SessionInterface;

/**
 * Issue a one-time password reset link for somebody else.
 *
 * The reset *mechanism* already existed — `PasswordResetService::issue()` returns a token and
 * `consume()` redeems it — but only the API could reach it. An administrator whose user forgot a
 * password had to ask them to use a "forgot password" page that the public site does not have.
 *
 * The link is **shown to the administrator** rather than emailed: the token is the thing, the
 * transport is a detail, and a screen that silently depends on working mail is a screen that fails
 * on a fresh install. The flash carries the full URL once.
 *
 * The token is not returned anywhere else and is not stored by this controller; issuing a new one
 * before the old is used is possible, which is the service's existing behaviour.
 */
final class PasswordResetAdminController extends AdminController
{
    public function __construct(
        AdminView $view,
        SessionInterface $session,
        CsrfTokenManagerInterface $csrf,
        ActorProviderInterface $actors,
        private readonly UserServiceInterface $users,
        private readonly PasswordResetServiceInterface $resets,
    ) {
        parent::__construct($view, $session, $csrf, $actors);
    }

    public function issue(Request $request): Response
    {
        $this->requireCapability($request, 'users.update');

        $siteId = $this->siteId($request);
        $userId = $this->intParam($request, 'id');

        $user = $this->users->find($userId, $siteId);

        if ($user === null) {
            throw HttpException::notFound();
        }

        $token = $this->resets->issue($user->email, $siteId);

        if ($token === null) {
            $this->flash('error', 'Could not issue a reset link for that account.');

            return $this->redirect('/admin/users/' . $userId);
        }

        // The path the public site would use; the token is single use and expires per the service.
        $this->flash('success', 'One-time reset link: /reset-password?token=' . $token
            . ' — send it to ' . $user->email . '. It is shown once.');

        return $this->redirect('/admin/users/' . $userId);
    }
}

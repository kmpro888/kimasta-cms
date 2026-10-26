<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Api;

use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Identity\Contracts\RoleServiceInterface;
use CMS\Modules\Identity\Contracts\UserServiceInterface;
use CMS\Modules\Identity\Repository\PdoUserRepository;
use CMS\Modules\Identity\Service\UserService;
use CMS\Modules\Taxonomy\Contracts\TaxonomyServiceInterface;
use CMS\Shared\Contracts\CsrfTokenManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;
use Tests\Support\FrozenClock;

/**
 * End-to-end REST API behaviour through the real container.
 *
 * This suite runs the composed application, not hand-wired services: a provider or
 * route-registration mistake fails here rather than in production. It exercises the
 * matrix the task pack requires for every mutation -
 * anonymous / unauthorized / authorized / other-site / validation / success -
 * and asserts the frozen envelopes.
 */
final class ApiEndpointTest extends ApiTestCase
{
    // ------------------------------------------------------------- read paths

    #[Test]
    public function anonymousCanListPublishedContent(): void
    {
        $this->publishArticle('Public One');

        $response = $this->call('GET', '/api/v1/content');

        self::assertSame(200, $response->status());

        $payload = $this->json($response);
        self::assertArrayHasKey('data', $payload);
        self::assertArrayHasKey('meta', $payload);
        self::assertNotEmpty($payload['data']);
    }

    #[Test]
    public function theSuccessEnvelopeSerialisesMetaAsAnObject(): void
    {
        $contentId = $this->publishArticle('Envelope Check');

        $response = $this->call('GET', '/api/v1/content/' . $contentId);

        // The frozen contract requires `"meta": {}`, never `"meta": []`.
        self::assertStringContainsString('"meta":{}', $response->content());
    }

    #[Test]
    public function theListingEnvelopeCarriesPaginationMetadata(): void
    {
        $this->publishArticle('Paged Post');

        $payload = $this->json($this->call('GET', '/api/v1/content'));

        self::assertArrayHasKey('page', $payload['meta']);
        self::assertArrayHasKey('per_page', $payload['meta']);
        self::assertArrayHasKey('total', $payload['meta']);
        self::assertArrayHasKey('total_pages', $payload['meta']);
    }

    #[Test]
    public function anonymousListingNeverIncludesDrafts(): void
    {
        $this->publishArticle('Visible Post');

        $this->app->container()->get(ContentServiceInterface::class)->create($this->siteId, [
            'title' => 'Draft Post',
            'author_id' => 1,
        ]);

        $payload = $this->json($this->call('GET', '/api/v1/content'));

        $titles = array_column($payload['data'], 'title');

        self::assertContains('Visible Post', $titles);
        self::assertNotContains('Draft Post', $titles);
    }

    #[Test]
    public function aDraftIsA404ForAnAnonymousCaller(): void
    {
        $newsroom = $this->makeUser('newsroom', 'administrator');

        $draftId = (int) $this->app->container()->get(ContentServiceInterface::class)->create($this->siteId, [
            'title' => 'Hidden Draft',
            'author_id' => $newsroom,
        ])->id;

        $response = $this->call('GET', '/api/v1/content/' . $draftId);

        self::assertSame(404, $response->status());
    }

    #[Test]
    public function anEditorCanReadAPrivateDraft(): void
    {
        $editor = $this->makeUser('editor', 'administrator');
        $this->loginAs($editor);

        $draftId = (int) $this->app->container()->get(ContentServiceInterface::class)->create($this->siteId, [
            'title' => 'Readable Draft',
            'author_id' => $editor,
        ])->id;

        $response = $this->call('GET', '/api/v1/content/' . $draftId);

        self::assertSame(200, $response->status());
        self::assertSame('Readable Draft', $this->json($response)['data']['title']);
    }

    #[Test]
    public function aCrossSiteContentIdIsA404(): void
    {
        $otherSite = $this->createSiteRow('other', 'other.example');

        $foreignId = (int) $this->app->container()->get(ContentServiceInterface::class)
            ->create($otherSite, ['title' => 'Other Tenant Article', 'author_id' => 1, 'status' => 'published'])->id;

        $response = $this->call('GET', '/api/v1/content/' . $foreignId);

        self::assertSame(404, $response->status());
        self::assertStringNotContainsString('Other Tenant Article', $response->content());
    }

    #[Test]
    public function anUnknownHostIsA404NotADefaultTenant(): void
    {
        $request = Request::create('GET', '/api/v1/content', 'unknown.test');

        self::assertSame(404, $this->app->handle($request)->status());
    }

    #[Test]
    public function aSiteIdQueryParameterIsIgnored(): void
    {
        $otherSite = $this->createSiteRow('other', 'other.example');

        $this->app->container()->get(ContentServiceInterface::class)
            ->create($otherSite, ['title' => 'Not Mine', 'author_id' => 1, 'status' => 'published']);

        $payload = $this->json($this->call('GET', '/api/v1/content', null, ['site_id' => (string) $otherSite]));

        self::assertNotContains('Not Mine', array_column($payload['data'], 'title'));
    }

    // ---------------------------------------------------------- authentication

    #[Test]
    public function meRequiresAuthentication(): void
    {
        self::assertSame(401, $this->call('GET', '/api/v1/auth/me')->status());
    }

    #[Test]
    public function loginSucceedsWithValidCredentials(): void
    {
        $userId = $this->makeUser('loginable');

        $response = $this->call('POST', '/api/v1/auth/login', [
            'identifier' => 'loginable',
            'password' => 'correct-horse-battery',
        ]);

        self::assertSame(200, $response->status());

        $payload = $this->json($response);
        self::assertSame($userId, $payload['data']['id']);
    }

    #[Test]
    public function loginWithAWrongPasswordIsAUniform401(): void
    {
        $this->makeUser('loginable');

        $response = $this->call('POST', '/api/v1/auth/login', [
            'identifier' => 'loginable',
            'password' => 'wrong',
        ]);

        self::assertSame(401, $response->status());
        self::assertSame('INVALID_CREDENTIALS', $this->json($response)['error']['code']);
    }

    #[Test]
    public function anUnknownAccountProducesTheSameErrorAsAWrongPassword(): void
    {
        $unknown = $this->call('POST', '/api/v1/auth/login', [
            'identifier' => 'does-not-exist',
            'password' => 'whatever',
        ]);

        $this->makeUser('known');
        $wrong = $this->call('POST', '/api/v1/auth/login', [
            'identifier' => 'known',
            'password' => 'whatever',
        ]);

        // Identical code and message: the endpoint cannot be used to enumerate users.
        self::assertSame(401, $unknown->status());
        self::assertSame(
            $this->json($wrong)['error']['message'],
            $this->json($unknown)['error']['message']
        );
    }

    #[Test]
    public function loginValidationIsReportedPerField(): void
    {
        $response = $this->call('POST', '/api/v1/auth/login', []);

        self::assertSame(422, $response->status());

        $error = $this->json($response)['error'];
        self::assertSame('VALIDATION_ERROR', $error['code']);
        self::assertArrayHasKey('identifier', $error['details']['fields']);
        self::assertArrayHasKey('password', $error['details']['fields']);
    }

    #[Test]
    public function forgotPasswordNeverRevealsWhetherTheAccountExists(): void
    {
        $response = $this->call('POST', '/api/v1/auth/forgot-password', ['identifier' => 'nobody@example.com']);

        self::assertSame(204, $response->status());
    }

    #[Test]
    public function noPasswordHashEverAppearsInAResponse(): void
    {
        $this->makeUser('leakcheck');
        $login = $this->call('POST', '/api/v1/auth/login', [
            'identifier' => 'leakcheck',
            'password' => 'correct-horse-battery',
        ]);

        self::assertStringNotContainsString('$2y$', $login->content());
        self::assertStringNotContainsString('password_hash', $login->content());
    }

    // -------------------------------------------------------------- mutations

    #[Test]
    public function creatingContentAnonymouslyIsA401(): void
    {
        $response = $this->call('POST', '/api/v1/content', ['title' => 'Sneaky']);

        self::assertSame(401, $response->status());
        self::assertSame('UNAUTHENTICATED', $this->json($response)['error']['code']);
    }

    #[Test]
    public function creatingContentWithoutTheCapabilityIsA403(): void
    {
        // A user with no role holds no capabilities.
        $this->loginAs($this->makeUser('norole'));

        $response = $this->call('POST', '/api/v1/content', ['title' => 'Nope']);

        self::assertSame(403, $response->status());
        self::assertStringContainsString('capability', (string) $this->json($response)['error']['message']);
    }

    #[Test]
    public function creatingContentWithTheCapabilitySucceeds(): void
    {
        $this->loginAs($this->makeUser('author', 'administrator'));

        $response = $this->call('POST', '/api/v1/content', [
            'title' => 'Created Through The API',
            'excerpt' => 'An excerpt.',
        ]);

        self::assertSame(201, $response->status());

        $data = $this->json($response)['data'];
        self::assertSame('Created Through The API', $data['title']);
        self::assertSame('draft', $data['status']);
        self::assertIsInt($data['id']);
    }

    #[Test]
    public function creatingContentReportsEveryValidationProblemAtOnce(): void
    {
        $this->loginAs($this->makeUser('author', 'administrator'));

        $response = $this->call('POST', '/api/v1/content', ['title' => '', 'type' => 'video']);

        self::assertSame(422, $response->status());

        $fields = $this->json($response)['error']['details']['fields'];
        self::assertArrayHasKey('title', $fields);
        self::assertArrayHasKey('type', $fields);
    }

    #[Test]
    public function anAuthorCanUpdateTheirOwnContent(): void
    {
        $authorId = $this->makeUser('author', 'contributor');
        $this->loginAs($authorId);

        $contentId = (int) $this->app->container()->get(ContentServiceInterface::class)->create($this->siteId, [
            'title' => 'Mine To Edit',
            'author_id' => $authorId,
        ])->id;

        $response = $this->call('PATCH', '/api/v1/content/' . $contentId, ['title' => 'Edited By Owner']);

        self::assertSame(200, $response->status());
        self::assertSame('Edited By Owner', $this->json($response)['data']['title']);
    }

    #[Test]
    public function anAuthorCannotUpdateSomeoneElsesContent(): void
    {
        $ownerId = $this->makeUser('owner', 'administrator');
        $intruderId = $this->makeUser('intruder', 'contributor');
        $this->loginAs($intruderId);

        $contentId = (int) $this->app->container()->get(ContentServiceInterface::class)->create($this->siteId, [
            'title' => 'Not Yours',
            'author_id' => $ownerId,
        ])->id;

        $response = $this->call('PATCH', '/api/v1/content/' . $contentId, ['title' => 'Hijacked']);

        self::assertSame(403, $response->status());
    }

    #[Test]
    public function patchingWithNoUpdatableFieldIsA422(): void
    {
        $authorId = $this->makeUser('author', 'administrator');
        $this->loginAs($authorId);

        $contentId = $this->publishArticle('Untouched');

        $response = $this->call('PATCH', '/api/v1/content/' . $contentId, []);

        self::assertSame(422, $response->status());
    }

    #[Test]
    public function deletingAnonymouslyIsA401AndWithoutCapabilityIsA403(): void
    {
        $contentId = $this->publishArticle('Delete Me');

        self::assertSame(401, $this->call('DELETE', '/api/v1/content/' . $contentId)->status());

        $this->loginAs($this->makeUser('norole'));
        self::assertSame(403, $this->call('DELETE', '/api/v1/content/' . $contentId)->status());
    }

    #[Test]
    public function deletingWithTheCapabilityTrashesTheRow(): void
    {
        $adminId = $this->makeUser('admin', 'administrator');
        $this->loginAs($adminId);

        $contentId = $this->publishArticle('Trash Me');

        self::assertSame(204, $this->call('DELETE', '/api/v1/content/' . $contentId)->status());

        $content = $this->app->container()->get(ContentServiceInterface::class)->get($contentId, $this->siteId);
        self::assertTrue($content->isTrashed());
    }

    // --------------------------------------------------------------- workflow

    #[Test]
    public function transitionsListsWhatIsLegalFromTheCurrentStatus(): void
    {
        $adminId = $this->makeUser('admin', 'administrator');
        $this->loginAs($adminId);

        $contentId = $this->publishArticle('Transition Target');

        $response = $this->call('GET', '/api/v1/content/' . $contentId . '/transitions');

        self::assertSame(200, $response->status());

        $data = $this->json($response)['data'];
        self::assertSame('published', $data['current_status']);
        self::assertIsArray($data['allowed']);
    }

    #[Test]
    public function aLegalTransitionIsApplied(): void
    {
        $adminId = $this->makeUser('admin', 'administrator');
        $this->loginAs($adminId);

        $contentId = (int) $this->app->container()->get(ContentServiceInterface::class)->create($this->siteId, [
            'title' => 'To Be Submitted',
            'author_id' => $adminId,
        ])->id;

        $response = $this->call('POST', '/api/v1/content/' . $contentId . '/transitions', [
            'status' => 'submitted',
        ]);

        self::assertSame(200, $response->status());
        self::assertSame('submitted', $this->json($response)['data']['status']);
    }

    #[Test]
    public function anIllegalTransitionIsRejected(): void
    {
        $adminId = $this->makeUser('admin', 'administrator');
        $this->loginAs($adminId);

        $contentId = $this->publishArticle('Already Published');

        $response = $this->call('POST', '/api/v1/content/' . $contentId . '/transitions', [
            'status' => 'nonsense',
        ]);

        self::assertContains($response->status(), [409, 422], 'An illegal transition must not succeed.');
        self::assertArrayHasKey('error', $this->json($response));
    }

    #[Test]
    public function theEditorialHistoryIsExposed(): void
    {
        $adminId = $this->makeUser('admin', 'administrator');
        $this->loginAs($adminId);

        $contentId = $this->publishArticle('History Target');

        $response = $this->call('GET', '/api/v1/content/' . $contentId . '/history');

        self::assertSame(200, $response->status());
        self::assertIsArray($this->json($response)['data']);
    }

    // -------------------------------------------------------------- revisions

    #[Test]
    public function aSnapshotIsCreatedAndListed(): void
    {
        $adminId = $this->makeUser('admin', 'administrator');
        $this->loginAs($adminId);

        $contentId = $this->publishArticle('Snapshot Target');

        $created = $this->call('POST', '/api/v1/content/' . $contentId . '/revisions');
        self::assertSame(201, $created->status());

        $listed = $this->call('GET', '/api/v1/content/' . $contentId . '/revisions');

        self::assertSame(200, $listed->status());
        self::assertNotEmpty($this->json($listed)['data']);
    }

    #[Test]
    public function aRevisionNeverCarriesCompiledHtml(): void
    {
        $adminId = $this->makeUser('admin', 'administrator');
        $this->loginAs($adminId);

        $contentId = $this->publishArticle('No Html Please');
        $this->call('POST', '/api/v1/content/' . $contentId . '/revisions');

        $data = $this->json($this->call('GET', '/api/v1/content/' . $contentId . '/revisions'))['data'][0];

        self::assertArrayNotHasKey('content_html', $data);
        self::assertArrayHasKey('blocks', $data);
    }

    // --------------------------------------------------------------- autosave

    #[Test]
    public function autosaveUpdatesDraftFieldsWithoutPublishing(): void
    {
        $authorId = $this->makeUser('author', 'administrator');
        $this->loginAs($authorId);

        $contentId = (int) $this->app->container()->get(ContentServiceInterface::class)->create($this->siteId, [
            'title' => 'Autosaved Draft',
            'author_id' => $authorId,
        ])->id;

        $response = $this->call('POST', '/api/v1/content/' . $contentId . '/autosave', [
            'title' => 'Autosaved Draft v2',
        ]);

        self::assertSame(200, $response->status());

        $content = $this->app->container()->get(ContentServiceInterface::class)->get($contentId, $this->siteId);

        self::assertSame('Autosaved Draft v2', $content->title);
        // The critical assertion: autosave must never publish.
        self::assertSame('draft', $content->status);
    }

    #[Test]
    public function autosaveRequiresAuthentication(): void
    {
        $contentId = $this->publishArticle('Autosave Target');

        self::assertSame(401, $this->call('POST', '/api/v1/content/' . $contentId . '/autosave', ['title' => 'x'])->status());
    }

    // -------------------------------------------------------------- taxonomies

    #[Test]
    public function termsAreReadableAnonymously(): void
    {
        $response = $this->call('GET', '/api/v1/taxonomies/tag/terms');

        self::assertSame(200, $response->status());
        self::assertIsArray($this->json($response)['data']);
    }

    #[Test]
    public function creatingATermNeedsTheCapability(): void
    {
        self::assertSame(401, $this->call('POST', '/api/v1/taxonomies/tag/terms', ['name' => 'X'])->status());

        $this->loginAs($this->makeUser('norole'));
        self::assertSame(403, $this->call('POST', '/api/v1/taxonomies/tag/terms', ['name' => 'X'])->status());
    }

    #[Test]
    public function aTermCanBeCreatedByAnAuthorizedUser(): void
    {
        $this->loginAs($this->makeUser('admin', 'administrator'));

        $response = $this->call('POST', '/api/v1/taxonomies/tag/terms', ['name' => 'Fresh Tag']);

        self::assertSame(201, $response->status());
        self::assertSame('fresh-tag', $this->json($response)['data']['slug']);
    }

    #[Test]
    public function contentTermsCanBeReadAndReplaced(): void
    {
        $this->loginAs($this->makeUser('admin', 'administrator'));

        $contentId = $this->publishArticle('Term Target');

        $term = $this->app->container()->get(TaxonomyServiceInterface::class)
            ->createTerm($this->siteId, 'tag', 'Attached');

        self::assertSame(204, $this->call(
            'PUT',
            '/api/v1/content/' . $contentId . '/terms',
            ['term_ids' => [(int) $term->id]]
        )->status());

        $terms = $this->json($this->call('GET', '/api/v1/content/' . $contentId . '/terms'))['data'];

        self::assertCount(1, $terms);
        self::assertSame('Attached', $terms[0]['name']);
    }

    // ---------------------------------------------------------------- routing

    #[Test]
    public function anUnknownApiPathKeepsTheFrozenErrorEnvelope(): void
    {
        $response = $this->call('GET', '/api/v1/does-not-exist');

        self::assertSame(404, $response->status());

        $error = $this->json($response)['error'];
        self::assertSame('RESOURCE_NOT_FOUND', $error['code']);
        self::assertArrayNotHasKey('status', $error);
    }

    #[Test]
    public function theThemeDoesNotSwallowApiPaths(): void
    {
        // `/api/...` must never be rendered by the public theme.
        $response = $this->call('GET', '/api/v1/does-not-exist');

        self::assertStringContainsString('application/json', (string) $response->header('Content-Type'));
    }

    #[Test]
    public function postsReturnsOnlyPosts(): void
    {
        $this->publishArticle('A Post');

        $this->app->container()->get(ContentServiceInterface::class)->create($this->siteId, [
            'type' => 'page',
            'title' => 'A Page',
            'author_id' => 1,
            'status' => 'published',
        ]);

        $titles = array_column($this->json($this->call('GET', '/api/v1/posts'))['data'], 'title');

        self::assertContains('A Post', $titles);
        self::assertNotContains('A Page', $titles);
    }

    #[Test]
    public function pagesReturnsOnlyPages(): void
    {
        $this->publishArticle('A Post');

        $this->app->container()->get(ContentServiceInterface::class)->create($this->siteId, [
            'type' => 'page',
            'title' => 'A Page',
            'author_id' => 1,
            'status' => 'published',
        ]);

        $titles = array_column($this->json($this->call('GET', '/api/v1/pages'))['data'], 'title');

        self::assertContains('A Page', $titles);
        self::assertNotContains('A Post', $titles);
    }

    #[Test]
    public function aTypeQueryParameterCannotOverrideThePinnedType(): void
    {
        // `/posts?type=page` must still behave as `/posts`.
        $this->app->container()->get(ContentServiceInterface::class)->create($this->siteId, [
            'type' => 'page',
            'title' => 'Sneaky Page',
            'author_id' => 1,
            'status' => 'published',
        ]);

        $titles = array_column(
            $this->json($this->call('GET', '/api/v1/posts', null, ['type' => 'page']))['data'],
            'title'
        );

        self::assertNotContains('Sneaky Page', $titles);
    }

    // -------------------------------------------------------------------- csrf

    #[Test]
    public function aCsrfTokenCanBeObtained(): void
    {
        $response = $this->call('GET', '/api/v1/auth/csrf');

        self::assertSame(200, $response->status());

        $token = $this->json($response)['data']['token'];
        self::assertIsString($token);
        self::assertNotSame('', $token);
    }

    #[Test]
    public function aMutationWithoutACsrfTokenIsRejected(): void
    {
        $this->loginAs($this->makeUser('author', 'administrator'));

        // This is the attack: a third-party page POSTs with the victim's cookie.
        $response = $this->call('POST', '/api/v1/content', ['title' => 'Forged'], [], null, false);

        self::assertSame(403, $response->status());
        self::assertSame('CSRF_TOKEN_MISMATCH', $this->json($response)['error']['code']);
    }

    #[Test]
    public function aMutationWithAWrongCsrfTokenIsRejected(): void
    {
        $this->loginAs($this->makeUser('author', 'administrator'));

        $response = $this->call('POST', '/api/v1/content', ['title' => 'Forged'], [], str_repeat('a', 64));

        self::assertSame(403, $response->status());
    }

    #[Test]
    public function theCsrfCheckHappensBeforeAuthentication(): void
    {
        // An anonymous forged POST is rejected for the token first, so the response
        // does not reveal that the endpoint expects a session.
        $response = $this->call('POST', '/api/v1/content', ['title' => 'Forged'], [], null, false);

        self::assertSame(403, $response->status());
    }

    #[Test]
    public function aCsrfTokenFromTheBodyIsAccepted(): void
    {
        $this->loginAs($this->makeUser('author', 'administrator'));

        $token = (string) $this->app->container()->get(CsrfTokenManagerInterface::class)->token();

        // Header omitted; token supplied as `_token` in the body instead.
        $request = Request::create(
            'POST',
            '/api/v1/content',
            $this->host,
            [],
            [],
            [],
            [],
            (string) json_encode(['title' => 'Body Token', '_token' => $token]),
        );

        self::assertSame(201, $this->app->handle($request)->status());
    }

    #[Test]
    public function safeMethodsDoNotNeedAToken(): void
    {
        $this->publishArticle('Readable');

        self::assertSame(200, $this->call('GET', '/api/v1/content')->status());
        self::assertSame(200, $this->call('HEAD', '/api/v1/content')->status());
    }

    #[Test]
    public function everyMutationRouteIsCsrfProtected(): void
    {
        // Guards against a future write endpoint being registered with only the
        // context middleware.
        $this->loginAs($this->makeUser('admin', 'administrator'));

        $contentId = $this->publishArticle('Csrf Matrix');

        $mutations = [
            ['POST', '/api/v1/content', ['title' => 'x']],
            ['PATCH', '/api/v1/content/' . $contentId, ['title' => 'x']],
            ['DELETE', '/api/v1/content/' . $contentId, null],
            ['POST', '/api/v1/content/' . $contentId . '/autosave', ['title' => 'x']],
            ['POST', '/api/v1/content/' . $contentId . '/revisions', null],
            ['POST', '/api/v1/content/' . $contentId . '/transitions', ['status' => 'draft']],
            ['PUT', '/api/v1/content/' . $contentId . '/terms', ['term_ids' => []]],
            ['POST', '/api/v1/taxonomies/tag/terms', ['name' => 'x']],
            ['POST', '/api/v1/auth/logout', null],
            ['POST', '/api/v1/auth/forgot-password', ['identifier' => 'x@example.com']],
            ['POST', '/api/v1/auth/reset-password', ['token' => 't', 'password' => 'p']],
            ['POST', '/api/v1/auth/login', ['identifier' => 'x', 'password' => 'y']],
        ];

        foreach ($mutations as [$method, $path, $body]) {
            $response = $this->call($method, $path, $body, [], null, false);

            self::assertSame(
                403,
                $response->status(),
                $method . ' ' . $path . ' is not CSRF protected.'
            );
        }
    }

    #[Test]
    public function editorialReadsRequireTheContentReadCapability(): void
    {
        $adminId = $this->makeUser('admin', 'administrator');

        $contentId = (int) $this->app->container()->get(ContentServiceInterface::class)->create($this->siteId, [
            'title' => 'Editorial Surface',
            'author_id' => $adminId,
        ])->id;

        // Anonymous callers must not see editorial state.
        self::assertContains($this->call('GET', '/api/v1/content/' . $contentId . '/transitions')->status(), [401, 403]);
        self::assertContains($this->call('GET', '/api/v1/content/' . $contentId . '/history')->status(), [401, 403]);
        self::assertContains($this->call('GET', '/api/v1/content/' . $contentId . '/revisions')->status(), [401, 403]);

        // With the capability they resolve.
        $this->loginAs($adminId);

        self::assertSame(200, $this->call('GET', '/api/v1/content/' . $contentId . '/transitions')->status());
        self::assertSame(200, $this->call('GET', '/api/v1/content/' . $contentId . '/history')->status());
        self::assertSame(200, $this->call('GET', '/api/v1/content/' . $contentId . '/revisions')->status());
    }
}


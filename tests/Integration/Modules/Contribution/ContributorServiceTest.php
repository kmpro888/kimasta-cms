<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Contribution;

use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Content\Entity\Content;
use CMS\Modules\Contribution\Contracts\ContributorServiceInterface;
use CMS\Modules\Contribution\Exceptions\ContentNotEditableException;
use CMS\Modules\Contribution\Exceptions\NotYourContentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AppTestCase;

/**
 * Contributor workflow rules.
 *
 * 14C's requirements are ownership and timing rules, so those are what is tested: an
 * author can only touch their own work, only while it is still theirs to change, and
 * cannot publish.
 */
#[CoversClass(\CMS\Modules\Contribution\Service\ContributorService::class)]
final class ContributorServiceTest extends AppTestCase
{
    private ContributorServiceInterface $contributors;

    private int $authorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contributors = $this->app->container()->get(ContributorServiceInterface::class);
        // `contributor` may create content and submit it, but not publish.
        $this->authorId = $this->makeUser('author', 'contributor');
    }

    private function draft(string $title = 'My Draft'): Content
    {
        return $this->contributors->createDraft($this->siteId, $this->authorId, [
            'title' => $title,
            'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body']]],
        ]);
    }

    #[Test]
    public function onlyDraftAndRevisionRequestedAreEditable(): void
    {
        self::assertSame(
            [Content::STATUS_DRAFT, Content::STATUS_REVISION_REQUESTED],
            $this->contributors->editableStatuses()
        );
    }

    #[Test]
    public function creatingADraftForcesOwnershipAndDraftStatus(): void
    {
        // A caller cannot file work under someone else's name, nor create it published.
        $content = $this->contributors->createDraft($this->siteId, $this->authorId, [
            'title' => 'Sneaky',
            'author_id' => 999,
            'status' => Content::STATUS_PUBLISHED,
        ]);

        self::assertSame($this->authorId, $content->authorId);
        self::assertSame(Content::STATUS_DRAFT, $content->status);
    }

    #[Test]
    public function myDraftsListsOnlyMyOwnWork(): void
    {
        $otherAuthor = $this->makeUser('otherauthor', 'contributor');

        $this->draft('Mine');
        $this->contributors->createDraft($this->siteId, $otherAuthor, ['title' => 'Theirs']);

        $titles = array_map(
            static fn ($c): string => $c->title,
            $this->contributors->myDrafts($this->siteId, $this->authorId)
        );

        self::assertContains('Mine', $titles);
        self::assertNotContains('Theirs', $titles);
    }

    #[Test]
    public function publishedWorkAppearsInThePublishedList(): void
    {
        $content = $this->draft('Will Publish');

        // Publishing is an editorial action; done here through the service layer the
        // workflow exposes, not through the contributor surface.
        $this->app->container()->get(ContentServiceInterface::class)
            ->setStatus((int) $content->id, $this->siteId, Content::STATUS_PUBLISHED);

        $titles = array_map(
            static fn ($c): string => $c->title,
            $this->contributors->myPublished($this->siteId, $this->authorId)
        );

        self::assertContains('Will Publish', $titles);
    }

    #[Test]
    public function anAuthorCanUpdateTheirOwnDraft(): void
    {
        $content = $this->draft();

        $updated = $this->contributors->updateOwn($this->siteId, $this->authorId, (int) $content->id, [
            'title' => 'Edited',
        ]);

        self::assertSame('Edited', $updated->title);
    }

    #[Test]
    public function anEditCannotChangeOwnershipOrStatus(): void
    {
        $content = $this->draft();

        $updated = $this->contributors->updateOwn($this->siteId, $this->authorId, (int) $content->id, [
            'title' => 'Still Mine',
            'author_id' => 999,
            'status' => Content::STATUS_PUBLISHED,
        ]);

        self::assertSame($this->authorId, $updated->authorId);
        self::assertSame(Content::STATUS_DRAFT, $updated->status);
    }

    #[Test]
    public function anotherAuthorsDraftIsA404(): void
    {
        $otherAuthor = $this->makeUser('otherauthor', 'contributor');
        $content = $this->contributors->createDraft($this->siteId, $otherAuthor, ['title' => 'Not Yours']);

        // 404, not 403: confirming the draft exists would leak unpublished work.
        $this->expectException(NotYourContentException::class);

        $this->contributors->updateOwn($this->siteId, $this->authorId, (int) $content->id, ['title' => 'Hijacked']);
    }

    #[Test]
    public function aNonexistentRowIsAlsoA404(): void
    {
        $this->expectException(NotYourContentException::class);

        $this->contributors->updateOwn($this->siteId, $this->authorId, 999999, ['title' => 'Ghost']);
    }

    #[Test]
    public function editingAfterSubmissionIsRefused(): void
    {
        $content = $this->draft();

        $this->contributors->submit($this->siteId, $this->authorId, (int) $content->id);

        // Editing now would mean the reviewer reads something different from what was
        // submitted.
        $this->expectException(ContentNotEditableException::class);

        $this->contributors->updateOwn($this->siteId, $this->authorId, (int) $content->id, ['title' => 'Changed']);
    }

    #[Test]
    public function submittingMovesThePieceToSubmitted(): void
    {
        $content = $this->draft();

        $submitted = $this->contributors->submit($this->siteId, $this->authorId, (int) $content->id, 'Ready for review.');

        self::assertSame(Content::STATUS_SUBMITTED, $submitted->status);
    }

    #[Test]
    public function submittingSomeoneElsesDraftIsA404(): void
    {
        $otherAuthor = $this->makeUser('otherauthor', 'contributor');
        $content = $this->contributors->createDraft($this->siteId, $otherAuthor, ['title' => 'Not Yours']);

        $this->expectException(NotYourContentException::class);

        $this->contributors->submit($this->siteId, $this->authorId, (int) $content->id);
    }

    #[Test]
    public function aRevisionRequestedPieceCanBeResubmitted(): void
    {
        $content = $this->draft('Needs Work');
        $contentId = (int) $content->id;

        // Drive the workflow to `revision_requested` through the real editorial service.
        $workflow = $this->app->container()->get(\CMS\Modules\Editorial\Contracts\EditorialWorkflowInterface::class);
        $editorId = $this->makeUser('editor', 'editor');
        $editor = $this->app->container()->get(\CMS\Modules\Identity\Contracts\ActorProviderInterface::class)
            ->actorForUser($editorId, $this->siteId);

        $workflow->transition($contentId, $this->siteId, $editor, Content::STATUS_SUBMITTED);
        $workflow->transition($contentId, $this->siteId, $editor, Content::STATUS_REVIEW);
        $workflow->transition($contentId, $this->siteId, $editor, Content::STATUS_REVISION_REQUESTED, 'Please expand.');

        // The author may edit again, and may resubmit.
        $edited = $this->contributors->updateOwn($this->siteId, $this->authorId, $contentId, ['title' => 'Needs Work v2']);
        self::assertSame('Needs Work v2', $edited->title);

        $resubmitted = $this->contributors->submit($this->siteId, $this->authorId, $contentId, 'Expanded.');
        self::assertSame(Content::STATUS_SUBMITTED, $resubmitted->status);
    }

    #[Test]
    public function aContributorCannotPublish(): void
    {
        $content = $this->draft('Should Not Publish');

        // `submitted -> published` is not an edge in the state machine, and the author
        // does not hold `content.publish` in any case.
        $this->expectException(\CMS\Modules\Editorial\Exceptions\IllegalTransitionException::class);

        $this->contributors->submit($this->siteId, $this->authorId, (int) $content->id);
        $this->app->container()->get(\CMS\Modules\Editorial\Contracts\EditorialWorkflowInterface::class)
            ->transition(
                (int) $content->id,
                $this->siteId,
                $this->app->container()->get(\CMS\Modules\Identity\Contracts\ActorProviderInterface::class)
                    ->actorForUser($this->authorId, $this->siteId),
                Content::STATUS_PUBLISHED
            );
    }

    #[Test]
    public function theHistoryOfMyOwnPieceIsVisible(): void
    {
        $content = $this->draft();
        $this->contributors->submit($this->siteId, $this->authorId, (int) $content->id);

        $history = $this->contributors->history($this->siteId, $this->authorId, (int) $content->id);

        self::assertNotEmpty($history);
        self::assertSame(Content::STATUS_SUBMITTED, $history[0]->toStatus);
    }

    #[Test]
    public function isEditableReflectsOwnershipAndStatus(): void
    {
        $otherAuthor = $this->makeUser('otherauthor', 'contributor');

        $mine = $this->draft('Mine');
        $theirs = $this->contributors->createDraft($this->siteId, $otherAuthor, ['title' => 'Theirs']);

        self::assertTrue($this->contributors->isEditable($this->siteId, $this->authorId, (int) $mine->id));
        self::assertFalse($this->contributors->isEditable($this->siteId, $this->authorId, (int) $theirs->id));
        self::assertFalse($this->contributors->isEditable($this->siteId, $this->authorId, 999999));
    }

    #[Test]
    public function crossSiteDraftsAreInvisible(): void
    {
        $otherSite = $this->createSiteRow('other', 'other.example');

        $content = $this->contributors->createDraft($otherSite, $this->authorId, ['title' => 'Other Tenant']);

        $this->expectException(NotYourContentException::class);

        $this->contributors->updateOwn($this->siteId, $this->authorId, (int) $content->id, ['title' => 'x']);
    }
}

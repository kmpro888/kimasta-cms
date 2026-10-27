<?php

declare(strict_types=1);

/**
 * The comment thread, and the form that adds to it.
 *
 * Posting is anonymous: there is no reader account, so the form asks for a name. Every comment
 * lands in the moderation queue as `pending` and nothing appears here until a moderator approves
 * it — which is why the note under the form says so rather than promising instant publication.
 *
 * @var \CMS\Modules\Theme\Entity\ViewModel $view
 */

$comments = $view->get('comments', []);
$open = (bool) $view->get('comments_open', false);
$contentId = (int) $view->get('comment_content_id', 0);
$count = is_array($comments) ? count($comments) : 0;

// Nothing to say and nothing to allow: do not print an empty section.
if ($count === 0 && !$open) {
    return;
}
?>
<section class="comments" id="comments">
  <h2 class="comments__title">
    <?= $view->e($count === 1 ? 'One comment' : $count . ' comments') ?>
  </h2>

  <?php if ($count === 0): ?>
    <p class="comments__empty">No comments yet.</p>
  <?php else: ?>
    <ol class="comments__list">
      <?php foreach ($comments as $comment): ?>
        <?php
        $depth = (int) ($comment->depth ?? 0);
        $author = (string) ($comment->authorName ?? '');
        $body = (string) ($comment->body ?? '');
        $at = $comment->createdAt ?? null;
        ?>
        <li class="comment<?= $depth > 0 ? ' comment--reply' : '' ?>"
            <?php if ($depth > 0): ?>style="margin-left: <?= min(4, $depth) * 1.5 ?>rem"<?php endif; ?>>
          <p class="comment__meta">
            <strong class="comment__author"><?= $view->e($author) ?></strong>
            <?php if ($at instanceof \DateTimeImmutable): ?>
              <time class="comment__time" datetime="<?= $view->e($at->format(DATE_ATOM)) ?>">
                <?= $view->e($at->format('j M Y, H:i')) ?>
              </time>
            <?php endif; ?>
          </p>
          <p class="comment__body"><?= nl2br($view->e($body), false) ?></p>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>

  <?php if ($open && $contentId > 0): ?>
    <form class="comment-form" method="post" action="/comment">
      <input type="hidden" name="content_id" value="<?= $view->e($contentId) ?>">

      <h3 class="comment-form__title">Leave a comment</h3>

      <p class="comment-form__field">
        <label class="comment-form__label" for="comment-author">Your name</label>
        <input class="comment-form__input" id="comment-author" type="text" name="author_name"
               maxlength="190" required autocomplete="name">
      </p>

      <p class="comment-form__field">
        <label class="comment-form__label" for="comment-body">Comment</label>
        <textarea class="comment-form__input comment-form__textarea" id="comment-body"
                  name="body" rows="5" required maxlength="5000"></textarea>
      </p>

      <?php /* Honeypot: hidden from people, filled by scripts. A real visitor never sees it. */ ?>
      <p class="comment-form__honeypot" aria-hidden="true">
        <label for="comment-website">Website</label>
        <input id="comment-website" type="text" name="website" tabindex="-1" autocomplete="off">
      </p>

      <p class="comment-form__actions">
        <button class="comment-form__submit" type="submit">Post comment</button>
      </p>

      <p class="comment-form__note">
        Your comment appears once a moderator has approved it.
      </p>
    </form>
  <?php elseif (!$open && $count > 0): ?>
    <p class="comments__closed">Comments are closed.</p>
  <?php endif; ?>
</section>

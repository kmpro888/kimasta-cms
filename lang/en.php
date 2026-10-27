<?php

declare(strict_types=1);

/*
 * English catalogue: the reference language.
 *
 * Every key used by a view must exist here; a test scans the views and fails when one does not.
 * Keys are namespaced by area (`nav.`, `public.`, `admin.`) so a missing translation is easy to
 * place.
 */

return [
    // Admin navigation.
    'nav.dashboard' => 'Dashboard',
    'nav.content' => 'Content',
    'nav.media' => 'Media',
    'nav.notifications' => 'Notifications',
    'nav.comments' => 'Comments',
    'nav.people' => 'People',
    'nav.redirects' => 'Redirects',
    'nav.ads' => 'Ads',
    'nav.plugins' => 'Plugins',
    'nav.settings' => 'Settings',
    'nav.sign_out' => 'Sign out',

    // Public chrome: the words a reader sees around the content.
    'public.comments.one' => 'One comment',
    'public.comments.many' => ':count comments',
    'public.comments.empty' => 'No comments yet.',
    'public.comments.closed' => 'Comments are closed.',
    'public.comments.form_title' => 'Leave a comment',
    'public.comments.name' => 'Your name',
    'public.comments.body' => 'Comment',
    'public.comments.submit' => 'Post comment',
    'public.comments.awaiting' => 'Your comment appears once a moderator has approved it.',
    'public.comments.honeypot' => 'Website',
];

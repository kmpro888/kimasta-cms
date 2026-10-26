<?php

declare(strict_types=1);

return [
    'session_lifetime' => 120,
    'session_name' => 'cms_session',
    'cookie_secure' => true,
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'csrf_token_name' => '_token',
    'headers' => [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ],
];
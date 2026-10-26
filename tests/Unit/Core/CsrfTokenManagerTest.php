<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use CMS\Core\Security\CsrfTokenManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\ArraySession;

/**
 * CSRF token manager.
 *
 * The frozen `CsrfTokenManagerInterface` shipped with no implementation, which left
 * every cookie-authenticated mutation forgeable. These tests pin the properties that
 * make the implementation worth having.
 */
#[CoversClass(CsrfTokenManager::class)]
final class CsrfTokenManagerTest extends TestCase
{
    private ArraySession $session;

    private CsrfTokenManager $manager;

    protected function setUp(): void
    {
        $this->session = new ArraySession();
        $this->manager = new CsrfTokenManager($this->session);
    }

    #[Test]
    public function itIssuesAToken(): void
    {
        $token = $this->manager->token();

        self::assertNotSame('', $token);
        // 32 random bytes rendered as hex.
        self::assertSame(64, strlen($token));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    #[Test]
    public function theSameTokenIsReturnedOnRepeatedCalls(): void
    {
        self::assertSame($this->manager->token(), $this->manager->token());
    }

    #[Test]
    public function aValidTokenIsAccepted(): void
    {
        self::assertTrue($this->manager->validate($this->manager->token()));
    }

    #[Test]
    public function anEmptyTokenIsRejected(): void
    {
        self::assertFalse($this->manager->validate(''));
    }

    #[Test]
    public function aWrongTokenIsRejected(): void
    {
        $this->manager->token();

        self::assertFalse($this->manager->validate(str_repeat('a', 64)));
    }

    #[Test]
    public function aTokenIsRejectedWhenNoTokenWasEverIssued(): void
    {
        // A fresh session must not accept a token the attacker simply invented.
        self::assertFalse($this->manager->validate('anything'));
    }

    #[Test]
    public function regenerationInvalidatesThePreviousToken(): void
    {
        $old = $this->manager->token();
        $new = $this->manager->regenerate();

        self::assertNotSame($old, $new);
        self::assertFalse($this->manager->validate($old));
        self::assertTrue($this->manager->validate($new));
    }

    #[Test]
    public function theTokenIsBoundToTheSession(): void
    {
        $token = $this->manager->token();

        // A different session must not accept the first session's token.
        $other = new CsrfTokenManager(new ArraySession());

        self::assertFalse($other->validate($token));
    }

    #[Test]
    public function validationIsNotPrefixBased(): void
    {
        $token = $this->manager->token();

        // hash_equals compares whole strings, so a truncated token must fail.
        self::assertFalse($this->manager->validate(substr($token, 0, 32)));
        self::assertFalse($this->manager->validate($token . 'x'));
    }

    #[Test]
    public function itSurvivesANonStringStoredValue(): void
    {
        $this->session->put('_csrf_token', ['not', 'a', 'string']);

        self::assertFalse($this->manager->validate('anything'));

        // And a fresh call recovers by issuing a proper token.
        self::assertNotSame('', $this->manager->token());
    }
}

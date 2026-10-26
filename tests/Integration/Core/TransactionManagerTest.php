<?php

declare(strict_types=1);

namespace Tests\Integration\Core;

use CMS\Core\Database\Exceptions\NestedTransactionException;
use CMS\Core\Database\TransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Tests\Support\DatabaseTestCase;

#[CoversClass(TransactionManager::class)]
#[CoversClass(NestedTransactionException::class)]
final class TransactionManagerTest extends DatabaseTestCase
{
    private TransactionManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }

        $this->db->statement('DROP TABLE IF EXISTS tx_probe');
        $this->db->statement(
            'CREATE TABLE tx_probe (id INT UNSIGNED NOT NULL PRIMARY KEY, label VARCHAR(32) NOT NULL)'
        );

        $this->manager = new TransactionManager($this->db);
    }

    protected function tearDown(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }

        $this->db->statement('DROP TABLE IF EXISTS tx_probe');

        parent::tearDown();
    }

    private function insert(int $id, string $label): void
    {
        $this->db->statement(
            'INSERT INTO tx_probe (id, label) VALUES (:id, :label)',
            ['id' => $id, 'label' => $label]
        );
    }

    private function rowCount(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM tx_probe');
    }

    public function testRunCommitsOnSuccess(): void
    {
        $result = $this->manager->run(function (): string {
            $this->insert(1, 'kept');

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(1, $this->rowCount());
        self::assertSame(0, $this->manager->level());
        self::assertFalse($this->db->inTransaction());
    }

    public function testRunRollsBackOnFailure(): void
    {
        try {
            $this->manager->run(function (): void {
                $this->insert(1, 'lost');

                throw new RuntimeException('boom');
            });

            self::fail('Expected exception was not thrown.');
        } catch (RuntimeException $exception) {
            self::assertSame('boom', $exception->getMessage());
        }

        self::assertSame(0, $this->rowCount());
        self::assertSame(0, $this->manager->level());
        self::assertFalse($this->db->inTransaction());
    }

    public function testRunRethrowsOriginalExceptionInstance(): void
    {
        $expected = new RuntimeException('original');

        try {
            $this->manager->run(static function () use ($expected): void {
                throw $expected;
            });

            self::fail('Expected exception was not thrown.');
        } catch (RuntimeException $exception) {
            self::assertSame($expected, $exception);
        }
    }

    public function testNestedBeginIsRejected(): void
    {
        $this->manager->begin();

        self::assertSame(1, $this->manager->level());

        try {
            $this->manager->begin();
            self::fail('Nested transaction must be rejected.');
        } catch (NestedTransactionException) {
            self::assertSame(1, $this->manager->level());
        } finally {
            $this->manager->rollback();
        }
    }

    public function testNestedRunIsRejectedAndLeavesNoOpenTransaction(): void
    {
        try {
            $this->manager->run(function (): void {
                $this->manager->run(static fn (): string => 'inner');
            });

            self::fail('Nested run must be rejected.');
        } catch (NestedTransactionException) {
            // expected
        }

        self::assertSame(0, $this->manager->level());
        self::assertFalse($this->db->inTransaction());
        self::assertSame(0, $this->rowCount());
    }

    public function testCommitAndRollbackWithoutBeginAreNoOps(): void
    {
        $this->manager->commit();
        $this->manager->rollback();

        self::assertSame(0, $this->manager->level());
        self::assertFalse($this->db->inTransaction());
    }

    public function testLevelReturnsToZeroAfterCommit(): void
    {
        $this->manager->begin();
        self::assertSame(1, $this->manager->level());

        $this->manager->commit();
        self::assertSame(0, $this->manager->level());
    }
}
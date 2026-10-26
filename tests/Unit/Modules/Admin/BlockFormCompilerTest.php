<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Admin;

use CMS\Modules\Admin\Support\BlockFormCompiler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The block form is a lossless view of content_json.
 *
 * The editor posts flat strings, the database stores typed blocks. Each case
 * here is a way that translation could silently drop or mangle authored
 * content, so the tests assert the stored arrays rather than just the rows.
 */
#[CoversClass(BlockFormCompiler::class)]
final class BlockFormCompilerTest extends TestCase
{
    #[Test]
    public function aParagraphKeepsItsInternalNewlines(): void
    {
        $blocks = $this->compiler()->fromForm([
            'blocks' => [
                ['type' => 'paragraph', 'data' => ['text' => "  First line\nSecond line  "]],
            ],
        ]);

        self::assertSame([
            ['type' => 'paragraph', 'version' => 1, 'data' => ['text' => "First line\nSecond line"]],
        ], $blocks);
    }

    #[Test]
    public function paragraphTextSurvivesTheRoundTrip(): void
    {
        $compiler = $this->compiler();

        $blocks = $compiler->fromForm([
            'blocks' => [
                ['type' => 'paragraph', 'data' => ['text' => "One\nTwo"]],
            ],
        ]);

        self::assertSame("One\nTwo", $compiler->toForm($blocks)[0]['text']);
    }

    #[Test]
    public function aHeadingRoundTripsWithItsLevel(): void
    {
        $compiler = $this->compiler();

        $blocks = $compiler->fromForm([
            'blocks' => [
                ['type' => 'heading', 'data' => ['text' => 'Title', 'level' => '3']],
            ],
        ]);

        self::assertSame(
            [['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Title', 'level' => 3]]],
            $blocks
        );
        self::assertSame(3, $compiler->toForm($blocks)[0]['level']);
        self::assertSame('Title', $compiler->toForm($blocks)[0]['text']);
    }

    #[Test]
    public function quoteAndCodeBlocksRoundTrip(): void
    {
        $compiler = $this->compiler();

        $blocks = $compiler->fromForm([
            'blocks' => [
                ['type' => 'quote', 'data' => ['text' => 'Quoted.']],
                ['type' => 'code', 'data' => ['text' => '$x = 1;']],
            ],
        ]);

        self::assertSame(['quote', 'code'], array_column($blocks, 'type'));
        self::assertSame('Quoted.', $blocks[0]['data']['text']);
        self::assertSame('$x = 1;', $blocks[1]['data']['text']);

        $rows = $compiler->toForm($blocks);

        self::assertSame('Quoted.', $rows[0]['text']);
        self::assertSame('$x = 1;', $rows[1]['text']);
    }

    #[Test]
    public function codeKeepsItsIndentationWhileProseIsTrimmed(): void
    {
        $compiler = $this->compiler();
        $code = "    if (\$x) {\n        \$y = 1;\n    }\n";

        $blocks = $compiler->fromForm([
            'blocks' => [
                ['type' => 'code', 'data' => ['text' => $code]],
                ['type' => 'paragraph', 'data' => ['text' => '  Prose  ']],
            ],
        ]);

        self::assertSame("    if (\$x) {\n        \$y = 1;\n    }", $blocks[0]['data']['text']);
        self::assertSame('Prose', $blocks[1]['data']['text']);
        self::assertSame(
            "    if (\$x) {\n        \$y = 1;\n    }",
            $compiler->toForm($blocks)[0]['text']
        );
    }

    #[Test]
    public function aHeadingLevelIsClampedToTheSupportedRange(): void
    {
        $compiler = $this->compiler();

        $low = $compiler->fromForm([
            'blocks' => [
                ['type' => 'heading', 'data' => ['text' => 'Low', 'level' => '0']],
            ],
        ]);

        $deep = $compiler->fromForm([
            'blocks' => [
                ['type' => 'heading', 'data' => ['text' => 'Deep', 'level' => '9']],
            ],
        ]);

        self::assertSame(1, $low[0]['data']['level']);
        self::assertSame(6, $deep[0]['data']['level']);
    }

    #[Test]
    public function aNonNumericHeadingLevelFallsBackToTwo(): void
    {
        $compiler = $this->compiler();

        $textual = $compiler->fromForm([
            'blocks' => [
                ['type' => 'heading', 'data' => ['text' => 'Title', 'level' => 'abc']],
            ],
        ]);

        $missing = $compiler->fromForm([
            'blocks' => [
                ['type' => 'heading', 'data' => ['text' => 'Title']],
            ],
        ]);

        self::assertSame(2, $textual[0]['data']['level']);
        self::assertSame(2, $missing[0]['data']['level']);
    }

    #[Test]
    public function theTypeIsMatchedCaseInsensitively(): void
    {
        $blocks = $this->compiler()->fromForm([
            'blocks' => [
                ['type' => '  HEADING ', 'data' => ['text' => 'Title']],
            ],
        ]);

        self::assertSame('heading', $blocks[0]['type']);
    }

    #[Test]
    public function listItemsAreSplitTrimmedAndReindexed(): void
    {
        $blocks = $this->compiler()->fromForm([
            'blocks' => [
                ['type' => 'list', 'data' => ['items' => "  one  \r\n\r\n two \r three\n\n"]],
            ],
        ]);

        self::assertSame(['one', 'two', 'three'], $blocks[0]['data']['items']);
        self::assertTrue(array_is_list($blocks[0]['data']['items']));
    }

    #[Test]
    public function orderedIsReadFromTruthyCheckboxValues(): void
    {
        foreach (['1', 'true', 'on', 'yes', 1, true, 'YES'] as $value) {
            self::assertTrue(
                $this->firstOrdered($value),
                'Expected ordered for ' . var_export($value, true)
            );
        }
    }

    #[Test]
    public function orderedIsFalseForAnythingElse(): void
    {
        foreach (['0', 'off', 'no', '', null, 0, false, '2'] as $value) {
            self::assertFalse(
                $this->firstOrdered($value),
                'Expected unordered for ' . var_export($value, true)
            );
        }
    }

    #[Test]
    public function anImageKeepsItsUrlAndAlt(): void
    {
        $blocks = $this->compiler()->fromForm([
            'blocks' => [
                ['type' => 'image', 'data' => ['url' => ' /uploads/a.png ', 'alt' => ' Alt text ']],
            ],
        ]);

        self::assertSame([
            [
                'type' => 'image',
                'version' => 1,
                'data' => ['url' => '/uploads/a.png', 'alt' => 'Alt text'],
            ],
        ], $blocks);
    }

    #[Test]
    public function anImageWithoutAUrlIsDropped(): void
    {
        $blocks = $this->compiler()->fromForm([
            'blocks' => [
                ['type' => 'image', 'data' => ['url' => '   ', 'alt' => 'no source']],
            ],
        ]);

        self::assertSame([], $blocks);
    }

    #[Test]
    public function altIsOptionalForAnImage(): void
    {
        $compiler = $this->compiler();

        $blocks = $compiler->fromForm([
            'blocks' => [
                ['type' => 'image', 'data' => ['url' => '/uploads/a.png']],
            ],
        ]);

        self::assertSame('', $blocks[0]['data']['alt']);
        self::assertSame('', $compiler->toForm($blocks)[0]['alt']);
    }

    #[Test]
    public function blocksWithoutContentAreDropped(): void
    {
        $blocks = $this->compiler()->fromForm([
            'blocks' => [
                ['type' => 'paragraph', 'data' => ['text' => "  \n\t "]],
                ['type' => 'heading', 'data' => ['text' => '', 'level' => 2]],
                ['type' => 'quote', 'data' => ['text' => '   ']],
                ['type' => 'code', 'data' => ['text' => "\n"]],
                ['type' => 'list', 'data' => ['items' => " \n\n \r\n"]],
                ['type' => 'image', 'data' => ['url' => '']],
            ],
        ]);

        self::assertSame([], $blocks);
    }

    #[Test]
    public function anUnknownTypeIsStoredAsAParagraph(): void
    {
        $blocks = $this->compiler()->fromForm([
            'blocks' => [
                ['type' => 'video', 'data' => ['text' => 'Still text']],
            ],
        ]);

        self::assertSame('paragraph', $blocks[0]['type']);
        self::assertSame('Still text', $blocks[0]['data']['text']);
    }

    #[Test]
    public function anExplicitDataFieldWinsOverAFlatRowField(): void
    {
        $blocks = $this->compiler()->fromForm([
            'blocks' => [
                ['type' => 'paragraph', 'data' => ['text' => 'nested'], 'text' => 'flat'],
            ],
        ]);

        self::assertSame('nested', $blocks[0]['data']['text']);
    }

    #[Test]
    public function anUnknownTypeIsReadableAsAParagraph(): void
    {
        $rows = $this->compiler()->toForm([
            ['type' => 'video', 'data' => ['text' => 'Still text']],
        ]);

        self::assertSame('paragraph', $rows[0]['type']);
        self::assertSame('Still text', $rows[0]['text']);
    }

    #[Test]
    public function textIsRecoveredFromTheSameKeysTheRendererUses(): void
    {
        $rows = $this->compiler()->toForm([
            ['type' => 'paragraph', 'content' => 'Legacy content'],
            ['type' => 'paragraph', 'text' => 'Legacy text', 'content' => 'ignored'],
            ['type' => 'paragraph', 'data' => ['text' => 'Modern'], 'text' => 'stale'],
        ]);

        self::assertSame('Legacy content', $rows[0]['text']);
        self::assertSame('Legacy text', $rows[1]['text']);
        self::assertSame('Modern', $rows[2]['text']);
    }

    #[Test]
    public function gapsInSubmittedKeysAreIgnoredAndOrderPreserved(): void
    {
        $blocks = $this->compiler()->fromForm([
            'blocks' => [
                3 => ['type' => 'paragraph', 'data' => ['text' => 'three']],
                7 => ['type' => 'paragraph', 'data' => ['text' => 'seven']],
                11 => ['type' => 'paragraph', 'data' => ['text' => 'eleven']],
            ],
        ]);

        self::assertSame(['three', 'seven', 'eleven'], array_map(
            static fn (array $block): string => $block['data']['text'],
            $blocks
        ));
        self::assertTrue(array_is_list($blocks));
    }

    #[Test]
    public function aScalarBlocksFieldYieldsNoBlocks(): void
    {
        self::assertSame([], $this->compiler()->fromForm(['blocks' => 'not-an-array']));
    }

    #[Test]
    public function aMissingBlocksFieldYieldsNoBlocks(): void
    {
        self::assertSame([], $this->compiler()->fromForm([]));
    }

    #[Test]
    public function scalarRowsAreSkipped(): void
    {
        $blocks = $this->compiler()->fromForm([
            'blocks' => [
                'not-a-row',
                42,
                null,
                ['type' => 'paragraph', 'data' => ['text' => 'kept']],
            ],
        ]);

        self::assertCount(1, $blocks);
        self::assertSame('kept', $blocks[0]['data']['text']);

        self::assertSame([], $this->compiler()->toForm(['not-a-block', 42, null]));
    }

    #[Test]
    public function theTypeMenuListsEverySupportedTypeInOrder(): void
    {
        self::assertSame(
            ['heading', 'paragraph', 'list', 'quote', 'code', 'image'],
            $this->compiler()->types()
        );
    }

    #[Test]
    public function toFormAlwaysReturnsCompleteTypedRows(): void
    {
        $rows = $this->compiler()->toForm([
            ['type' => 'paragraph', 'data' => ['text' => 'Body']],
            ['type' => 'heading', 'data' => ['text' => 'Title', 'level' => 4]],
            ['type' => 'list', 'data' => ['items' => ['one', 'two'], 'ordered' => true]],
            ['type' => 'quote', 'data' => ['text' => 'Quoted.']],
            ['type' => 'code', 'data' => ['text' => '$x = 1;']],
            ['type' => 'image', 'data' => ['url' => '/uploads/a.png', 'alt' => 'Alt']],
            ['type' => 'video', 'data' => ['text' => 'Odd']],
            [],
        ]);

        self::assertCount(8, $rows);

        foreach ($rows as $index => $row) {
            self::assertSame(
                ['type', 'text', 'level', 'items', 'ordered', 'url', 'alt'],
                array_keys($row),
                'Row ' . $index . ' is missing or reorders its keys.'
            );
            self::assertIsString($row['type']);
            self::assertIsString($row['text']);
            self::assertIsInt($row['level']);
            self::assertIsString($row['items']);
            self::assertIsBool($row['ordered']);
            self::assertIsString($row['url']);
            self::assertIsString($row['alt']);
        }

        self::assertSame('paragraph', $rows[6]['type']);
        self::assertSame('Odd', $rows[6]['text']);
        self::assertSame('/uploads/a.png', $rows[5]['url']);
        self::assertSame('Alt', $rows[5]['alt']);
        self::assertSame('', $rows[7]['text']);
        self::assertSame(2, $rows[7]['level']);
    }

    #[Test]
    public function listItemsAreRenderedBackAsOnePerLine(): void
    {
        $rows = $this->compiler()->toForm([
            ['type' => 'list', 'data' => ['items' => ['one', 'two', 'three'], 'ordered' => true]],
        ]);

        self::assertSame("one\ntwo\nthree", $rows[0]['items']);
        self::assertTrue($rows[0]['ordered']);
    }

    #[Test]
    public function aFormRoundTripPreservesEveryKeptBlock(): void
    {
        $compiler = $this->compiler();

        $blocks = $compiler->fromForm([
            'blocks' => [
                ['type' => 'heading', 'data' => ['text' => 'Title', 'level' => '9']],
                ['type' => 'paragraph', 'data' => ['text' => "Line one\nLine two"]],
                ['type' => 'list', 'data' => ['items' => "one\r\ntwo", 'ordered' => 'yes']],
                ['type' => 'quote', 'data' => ['text' => 'Quoted.']],
                ['type' => 'code', 'data' => ['text' => '$x = 1;']],
                ['type' => 'image', 'data' => ['url' => '/uploads/a.png', 'alt' => 'Alt']],
                ['type' => 'paragraph', 'data' => ['text' => '   ']],
            ],
        ]);

        self::assertCount(6, $blocks);
        self::assertSame($blocks, $compiler->fromForm(['blocks' => $compiler->toForm($blocks)]));
    }

    #[Test]
    public function theResultIsAListThatEncodesToJson(): void
    {
        $blocks = $this->compiler()->fromForm([
            'blocks' => [
                5 => ['type' => 'paragraph', 'data' => ['text' => 'Body']],
            ],
        ]);

        self::assertTrue(array_is_list($blocks));
        self::assertSame(
            '[{"type":"paragraph","version":1,"data":{"text":"Body"}}]',
            json_encode($blocks)
        );
    }

    private function compiler(): BlockFormCompiler
    {
        return new BlockFormCompiler();
    }

    private function firstOrdered(mixed $value): mixed
    {
        $blocks = $this->compiler()->fromForm([
            'blocks' => [
                ['type' => 'list', 'data' => ['items' => 'one', 'ordered' => $value]],
            ],
        ]);

        self::assertCount(1, $blocks);

        return $blocks[0]['data']['ordered'];
    }
}

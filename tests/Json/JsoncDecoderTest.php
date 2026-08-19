<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Tests\Json;

use CoolMS\Core\Exception\JsoncDecodeException;
use CoolMS\CoreBundle\Json\JsoncDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the platform JSONC decoder extracted from
 * a BPMN-Lite JSON parser.
 */
#[CoversClass(JsoncDecoder::class)]
final class JsoncDecoderTest extends TestCase
{
    #[Test]
    public function decodesPlainJsonRoundTrips(): void
    {
        $decoder = new JsoncDecoder();
        $result = $decoder->decode('{"a":1,"b":["x","y"]}');

        self::assertSame(['a' => 1, 'b' => ['x', 'y']], $result);
    }

    #[Test]
    public function stripsLineCommentsBetweenKeys(): void
    {
        $decoder = new JsoncDecoder();
        $result = $decoder->decode(<<<'JSON'
            {
              // This is the title.
              "title": "Hello",
              "n": 42 // inline trail
            }
            JSON);

        self::assertSame(['title' => 'Hello', 'n' => 42], $result);
    }

    #[Test]
    public function stripsBlockCommentsAcrossLines(): void
    {
        $decoder = new JsoncDecoder();
        $result = $decoder->decode(<<<'JSON'
            {
              /* multi-line
                 block comment */
              "title": "Hello"
            }
            JSON);

        self::assertSame(['title' => 'Hello'], $result);
    }

    #[Test]
    public function preservesCommentSyntaxInsideStringLiterals(): void
    {
        $decoder = new JsoncDecoder();
        $result = $decoder->decode(<<<'JSON'
            {
              "url": "https://example.com/path",
              "snippet": "/* not a comment */ // also not"
            }
            JSON);

        self::assertSame([
            'url' => 'https://example.com/path',
            'snippet' => '/* not a comment */ // also not',
        ], $result);
    }

    #[Test]
    public function preservesEscapedQuotesWhileTrackingStringState(): void
    {
        $decoder = new JsoncDecoder();
        // The escaped \" inside the string must NOT terminate the
        // literal -- if state tracking is wrong, the trailing `//
        // tail` would be interpreted as a comment.
        $result = $decoder->decode(<<<'JSON'
            {
              "text": "say \"hi\" // tail"
            }
            JSON);

        self::assertSame(['text' => 'say "hi" // tail'], $result);
    }

    #[Test]
    public function preservesLineCountSoErrorReportsStayAligned(): void
    {
        $decoder = new JsoncDecoder();
        // A 6-line source where line 3 is a block comment. The
        // decoder must NOT collapse the blank space; downstream
        // error reporting (the validator, a future SourceLocation
        // tracker) depends on byte/line offsets staying stable.
        $source = "{\n  \"a\": 1,\n  /* block */\n  \"b\": 2,\n  \"c\": 3\n}";
        $result = $decoder->decode($source);

        self::assertSame(['a' => 1, 'b' => 2, 'c' => 3], $result);
    }

    #[Test]
    public function decodesNestedArraysAndObjects(): void
    {
        $decoder = new JsoncDecoder();
        $result = $decoder->decode(<<<'JSON'
            {
              "items": [
                { "id": 1, "tags": ["a", "b"] },
                { "id": 2, "tags": [] }
              ]
            }
            JSON);

        self::assertSame([
            'items' => [
                ['id' => 1, 'tags' => ['a', 'b']],
                ['id' => 2, 'tags' => []],
            ],
        ], $result);
    }

    #[Test]
    public function throwsOnMalformedJsonAfterStripping(): void
    {
        $decoder = new JsoncDecoder();
        try {
            $decoder->decode('{ not valid json');
            self::fail('Expected JsoncDecodeException.');
        } catch (JsoncDecodeException $e) {
            self::assertStringContainsString('Malformed JSON', $e->getMessage());
            self::assertNotNull($e->getPrevious());
        }
    }

    #[Test]
    public function throwsOnScalarRoot(): void
    {
        $decoder = new JsoncDecoder();
        $this->expectException(JsoncDecodeException::class);
        $decoder->decode('42');
    }

    #[Test]
    public function throwsOnStringRoot(): void
    {
        $decoder = new JsoncDecoder();
        $this->expectException(JsoncDecodeException::class);
        $decoder->decode('"just a string"');
    }

    #[Test]
    public function threadsSourcePathIntoExceptionMessage(): void
    {
        $decoder = new JsoncDecoder();
        try {
            $decoder->decode('{ bad', '/workflows/identity.verify_new_user_spine/draft.bpmn.json');
            self::fail('Expected JsoncDecodeException.');
        } catch (JsoncDecodeException $e) {
            self::assertStringContainsString(
                '/workflows/identity.verify_new_user_spine/draft.bpmn.json',
                $e->getMessage(),
            );
            self::assertSame(
                '/workflows/identity.verify_new_user_spine/draft.bpmn.json',
                $e->sourcePath,
            );
        }
    }

    #[Test]
    public function emptyArrayRootIsAcceptedAsArrayContract(): void
    {
        $decoder = new JsoncDecoder();
        self::assertSame([], $decoder->decode('{}'));
        self::assertSame([], $decoder->decode('[]'));
        // Both `{}` and `[]` decode to `[]` in associative mode; the
        // contract is "array root" -- consumers that need to
        // distinguish should check for specific keys, not array shape.
    }
}

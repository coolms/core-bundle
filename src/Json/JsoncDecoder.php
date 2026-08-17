<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Json;

use CoolMS\Core\Exception\JsoncDecodeException;
use CoolMS\CoreModule\Json\JsoncDecoderInterface;
use JsonException;

use function is_array;
use function json_decode;
use function strlen;

use const JSON_THROW_ON_ERROR;

/**
 * Single-pass JSONC strip + json_decode delegate.
 *
 * **Algorithm.** One linear pass over the source bytes, tracking
 * string / escape state so `//` and `/*` inside string literals are
 * preserved as data. Comments are replaced with whitespace of equal
 * width (newlines preserved verbatim) so line/column numbers in
 * subsequent error reports stay aligned with the original source.
 *
 * **Provenance.** Extracted from
 * the BPMN-Lite parser's own `stripJsonComments()`
 * (M2.c). The original implementation served BPMN-Lite bodies; this
 * extraction makes the same primitive available to future config
 * loaders, fixture readers, and the M2.o conformance corpus without
 * each module re-implementing the comment-stripping dance.
 *
 * **Depth + decode flags.** `json_decode` is called with depth=64 and
 * `JSON_THROW_ON_ERROR`. Depth 64 matches the BPMN-Lite ceiling and
 * is generous enough for any handwritten config file; the throw flag
 * lets the decoder translate `JsonException` into the typed
 * {@see JsoncDecodeException} consumers depend on. The associative
 * flag is hard-coded `true` -- every consumer in this codebase wants
 * an array, not stdClass.
 */
final readonly class JsoncDecoder implements JsoncDecoderInterface
{
    public function decode(string $source, ?string $sourcePath = null): array
    {
        $cleaned = $this->stripComments($source);

        try {
            $decoded = json_decode($cleaned, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw JsoncDecodeException::malformedJson($e, $sourcePath);
        }

        if (!is_array($decoded)) {
            throw JsoncDecodeException::nonArrayRoot($sourcePath);
        }

        return $decoded;
    }

    /**
     * Strip `//` line comments and `/* ... *\/` block comments while
     * tracking string-literal state. Comments are replaced with
     * spaces of equal width so byte offsets / line numbers in
     * subsequent error reports stay aligned with the original source.
     */
    private function stripComments(string $source): string
    {
        $length = strlen($source);
        $out = '';
        $i = 0;
        $inString = false;
        $stringQuote = '';
        $escape = false;

        while ($i < $length) {
            $ch = $source[$i];

            if ($inString) {
                $out .= $ch;
                if ($escape) {
                    $escape = false;
                } elseif ('\\' === $ch) {
                    $escape = true;
                } elseif ($ch === $stringQuote) {
                    $inString = false;
                    $stringQuote = '';
                }
                ++$i;
                continue;
            }

            if ('"' === $ch || "'" === $ch) {
                $inString = true;
                $stringQuote = $ch;
                $out .= $ch;
                ++$i;
                continue;
            }

            if ('/' === $ch && $i + 1 < $length) {
                $next = $source[$i + 1];
                if ('/' === $next) {
                    // Line comment: replace bytes with spaces until \n.
                    while ($i < $length && "\n" !== $source[$i]) {
                        $out .= ' ';
                        ++$i;
                    }
                    continue;
                }
                if ('*' === $next) {
                    // Block comment: replace bytes with spaces until
                    // the closing marker. Preserve newlines verbatim
                    // so line numbers don't drift.
                    $out .= '  ';
                    $i += 2;
                    while ($i < $length) {
                        if ('*' === $source[$i] && $i + 1 < $length && '/' === $source[$i + 1]) {
                            $out .= '  ';
                            $i += 2;
                            break;
                        }
                        $out .= "\n" === $source[$i] ? "\n" : ' ';
                        ++$i;
                    }
                    continue;
                }
            }

            $out .= $ch;
            ++$i;
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Tests\Config;

use CoolMS\Core\Bundle\Config\ConfigCacheWarmer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\Filesystem\Filesystem;

use function count;
use function is_scalar;
use function str_contains;
use function str_replace;
use function sys_get_temp_dir;
use function uniqid;

/**
 * The warmer that validates every module config file.
 *
 * !! The case worth having is a KNOWN type producing no warning. The failure
 * that reached production was not a broken validator -- it was a type missing
 * from the list, which warns on every `cache:clear` while being the one config
 * type nothing checks. A test that only proves "unknown types warn" passes
 * happily in exactly that state.
 */
#[CoversClass(ConfigCacheWarmer::class)]
final class ConfigCacheWarmerTest extends TestCase
{
    private Filesystem $fs;
    private string $dir;

    #[Test]
    public function everyKnownTypeIsAcceptedWithoutAWarning(): void
    {
        // !! Driven off the constant itself, so adding a type cannot add an
        // untested one. Copying the list here is what let the docblock drift
        // to five entries while the constant held eight.
        $files = [];
        foreach (ConfigCacheWarmer::KNOWN_TYPES as $type) {
            $files['config/modules/mod/' . $type . '/a.yaml'] = 'type: ' . $type . "\nid: a\n";
        }
        self::assertNotSame([], $files, 'no known types to check -- the list is empty and this test proves nothing');

        $log = $this->warm($files);

        self::assertFalse($log->hasWarnings(), 'a declared type must not warn: ' . $log->firstWarning());
        self::assertTrue($log->has('scanned ' . (string) count($files) . ' files'), 'every file must be reached');
    }

    #[Test]
    public function theRuntimeSettingsTierIsAKnownType(): void
    {
        // !! Named rather than left to the loop above, because this is the one
        // that was missing. `config/modules/generated/settings/<key>--<scope>.yaml`
        // is written by the runtime module-settings tier on every save, so the gap
        // produced a warning per saved setting and validated none of them.
        //
        // Asserted through BEHAVIOUR, on a real generated filename. Asserting
        // `in_array('settings', KNOWN_TYPES)` instead is a tautology phpstan
        // rejects outright -- the constant is a literal list, so membership is
        // known at analysis time and the test would restate the source.
        $log = $this->warm(['config/modules/generated/settings/document.spaces--default.yaml' => "type: settings\nid: document.spaces--default\nenabled: true\n"]);

        self::assertFalse($log->hasWarnings(), $log->firstWarning());
    }

    #[Test]
    public function anUnknownTypeWarnsAndNamesWhatIsKnown(): void
    {
        // The list belongs in the message: a warning that says only "unknown"
        // leaves the reader guessing what they were supposed to write.
        $log = $this->warm(['config/modules/mod/thing/a.yaml' => "type: navigrpah\nid: a\n"]);

        self::assertTrue($log->has('unknown config type'));
        self::assertTrue($log->has('navigraph'), 'the known list must be in the message');
    }

    #[Test]
    public function aFileThatIsNotAMapAndAFileWithNoTypeAreBothReported(): void
    {
        // Distinct messages, because the fix differs: one is not a config file
        // at all, the other is a config file that forgot to say what it is.
        $log = $this->warm([
            'config/modules/mod/thing/scalar.yaml' => "just a string\n",
            'config/modules/mod/thing/untyped.yaml' => "id: a\n",
        ]);

        self::assertTrue($log->has('not a YAML map'));
        self::assertTrue($log->has('missing "type" key'));
    }

    #[Test]
    public function aYamlSequenceIsReportedAsMissingItsTypeRatherThanAsNotAMap(): void
    {
        // !! Measured, not assumed, and it is a sharp edge worth pinning: PHP
        // represents a YAML sequence AND a mapping as `array`, so the warmer's
        // `is_array` guard does not separate them and a list-shaped file falls
        // through to the type check. The message is therefore about a missing
        // key rather than about the shape. Not wrong -- the file is invalid
        // either way -- but the author is told the narrower thing.
        $log = $this->warm(['config/modules/mod/thing/list.yaml' => "- a\n- b\n"]);

        self::assertTrue($log->has('missing "type" key'));
        self::assertFalse($log->has('not a YAML map'));
    }

    #[Test]
    public function warmingIsNeverFatalSoProductionWarmupIsNotBlocked(): void
    {
        $log = $this->warm(['config/modules/mod/thing/broken.yaml' => "type: [unclosed\n"]);

        self::assertTrue($log->has('YAML parse error'));
        self::assertSame([], new ConfigCacheWarmer($this->dir . '/config', $log)->warmUp($this->dir . '/cache'));
    }

    protected function setUp(): void
    {
        $this->fs = new Filesystem();
        $this->dir = sys_get_temp_dir() . '/config-warmer-' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->fs->remove($this->dir);
    }

    /**
     * @param array<string, string> $files project-relative path => contents
     */
    private function warm(array $files): CollectingLogger
    {
        foreach ($files as $rel => $contents) {
            $this->fs->dumpFile($this->dir . '/' . $rel, $contents);
        }

        $log = new CollectingLogger();
        new ConfigCacheWarmer($this->dir . '/config', $log)->warmUp($this->dir . '/cache');

        return $log;
    }
}

/**
 * Keeps messages so a claim about what was reported can be checked rather than
 * assumed -- and keeps the warnings apart, since "it logged something" is not
 * the same as "it logged a warning".
 */
final class CollectingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $messages = [];

    /** @var list<string> */
    public array $warnings = [];

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $rendered = (string) $message;
        foreach ($context as $key => $value) {
            if (is_scalar($value)) {
                $rendered = str_replace('{' . $key . '}', (string) $value, $rendered);
            }
        }

        $this->messages[] = $rendered;
        if ('warning' === $level) {
            $this->warnings[] = $rendered;
        }
    }

    public function hasWarnings(): bool
    {
        return [] !== $this->warnings;
    }

    public function firstWarning(): string
    {
        return $this->warnings[0] ?? '(none)';
    }

    public function has(string $needle): bool
    {
        foreach ($this->messages as $message) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}

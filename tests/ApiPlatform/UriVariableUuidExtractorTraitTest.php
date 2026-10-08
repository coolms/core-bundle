<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Tests\ApiPlatform;

use CoolMS\Core\Bundle\Tests\ApiPlatform\Fixture\ItemIdReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * `UriVariableUuidExtractorTrait` turns an API Platform URI variable into a
 * UUID, with the HTTP answer for each way it can go wrong.
 *
 * A processor uses the required variant: a missing key is the client's mistake,
 * answered 400. A provider uses the optional one: a missing key yields null,
 * which API Platform reads as "no item". In both, a value that is present but
 * is not a UUID cannot name any item, and is answered 404.
 *
 * The fixture is the consumer's shape: one class that reads an item id the way
 * a processor does and the way a provider does.
 */
final class UriVariableUuidExtractorTraitTest extends TestCase
{
    private const string ID = '0199b3a4-6f1e-7c2a-9d3e-0a1b2c3d4e5f';

    #[Test]
    public function aProcessorReadsTheIdFromTheDefaultKey(): void
    {
        $uuid = new ItemIdReader()->forProcessor(['id' => self::ID]);

        self::assertSame(self::ID, $uuid->toRfc4122());
    }

    #[Test]
    public function aProcessorCanNameAnotherKey(): void
    {
        $uuid = new ItemIdReader()->forProcessor(['id' => 'ignored', 'templateId' => self::ID], 'templateId');

        self::assertSame(self::ID, $uuid->toRfc4122());
    }

    /**
     * API Platform may already have converted the variable to a UUID object;
     * that is read the same as its string.
     */
    #[Test]
    public function aValueAlreadyConvertedToAUuidIsReadTheSame(): void
    {
        $uuid = new ItemIdReader()->forProcessor(['id' => Uuid::fromString(self::ID)]);

        self::assertSame(self::ID, $uuid->toRfc4122());
    }

    /**
     * A key present with a null value counts as missing.
     */
    #[Test]
    public function aProcessorMissingItsKeyIsAnsweredBadRequestNamingTheKey(): void
    {
        $reader = new ItemIdReader();

        $this->assertBadRequest('id', static fn () => $reader->forProcessor([]));
        $this->assertBadRequest('id', static fn () => $reader->forProcessor(['id' => null]));
        $this->assertBadRequest('templateId', static fn () => $reader->forProcessor(['id' => self::ID], 'templateId'));
    }

    #[Test]
    public function aProcessorGivenSomethingThatIsNotAUuidIsAnsweredNotFound(): void
    {
        $this->assertNotFound(static fn () => new ItemIdReader()->forProcessor(['id' => 'not-a-uuid']));
    }

    #[Test]
    public function aProviderReadsTheIdFromTheDefaultKeyOrTheOneItNames(): void
    {
        $reader = new ItemIdReader();

        self::assertSame(self::ID, $reader->forProvider(['id' => self::ID])?->toRfc4122());
        self::assertSame(self::ID, $reader->forProvider(['slug' => self::ID], 'slug')?->toRfc4122());
    }

    #[Test]
    public function aProviderMissingItsKeyYieldsNoItemRatherThanAnError(): void
    {
        $reader = new ItemIdReader();

        self::assertNull($reader->forProvider([]));
        self::assertNull($reader->forProvider(['id' => null]));
        self::assertNull($reader->forProvider(['id' => self::ID], 'slug'));
    }

    #[Test]
    public function aProviderGivenSomethingThatIsNotAUuidIsAnsweredNotFound(): void
    {
        $this->assertNotFound(static fn () => new ItemIdReader()->forProvider(['id' => 'not-a-uuid']));
    }

    private function assertBadRequest(string $key, callable $read): void
    {
        try {
            $read();
            self::fail("a missing '{$key}' was accepted");
        } catch (BadRequestHttpException $e) {
            self::assertSame(400, $e->getStatusCode());
            self::assertSame("Missing {$key}.", $e->getMessage());
        }
    }

    private function assertNotFound(callable $read): void
    {
        try {
            $read();
            self::fail('a value that is not a UUID was accepted');
        } catch (NotFoundHttpException $e) {
            self::assertSame(404, $e->getStatusCode());
            self::assertSame('Invalid identifier.', $e->getMessage());
        }
    }
}

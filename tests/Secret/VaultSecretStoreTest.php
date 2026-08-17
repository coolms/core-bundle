<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Tests\Secret;

use CoolMS\Core\Secret\SecretNotFoundException;
use CoolMS\Core\Secret\SecretStoreException;
use CoolMS\CoreBundle\Secret\VaultSecretStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * F1.c -- the Vault KV v2 backend, driven by MockHttpClient (the standard
 * way to test an HTTP integration without a live Vault). Asserts the wire
 * call (URL + token header), the KV v2 unwrap, caching, and the failure
 * modes (404 → empty, other non-2xx → throw, missing token → throw).
 */
final class VaultSecretStoreTest extends TestCase
{
    private const string TOKEN_ENV = 'VAULT_TOKEN';

    #[Test]
    public function resolvesASecretAndHitsTheKvV2PathWithTheToken(): void
    {
        $captured = [];
        $store = $this->store($this->kvResponse(['stripe_api_key' => 'sk_live_v']), $captured);

        self::assertSame('sk_live_v', $store->get('stripe_api_key'));
        self::assertTrue($store->has('stripe_api_key'));
        self::assertSame('sk_live_v', $store->getRequired('stripe_api_key'));

        self::assertSame('GET', $captured['method']);
        self::assertSame('http://vault:8200/v1/secret/data/coolms', $captured['url']);
        self::assertContains('X-Vault-Token: vault-token-xyz', $captured['headers']);
    }

    #[Test]
    public function reportsAnUnknownKeyAsAbsent(): void
    {
        $store = $this->store($this->kvResponse(['a' => '1']));
        self::assertNull($store->get('nope'));
        self::assertFalse($store->has('nope'));
    }

    #[Test]
    public function treatsAnEmptyValueAsAbsent(): void
    {
        $store = $this->store($this->kvResponse(['blank' => '']));
        self::assertNull($store->get('blank'));
    }

    #[Test]
    public function getRequiredThrowsWithTheKeyWhenAbsent(): void
    {
        $store = $this->store($this->kvResponse([]));
        try {
            $store->getRequired('missing');
            self::fail('Expected SecretNotFoundException.');
        } catch (SecretNotFoundException $e) {
            self::assertSame('missing', $e->key);
        }
    }

    #[Test]
    public function aMissingPath404ReadsAsEmpty(): void
    {
        $store = $this->store(new MockResponse('{"errors":[]}', ['http_code' => 404]));
        self::assertNull($store->get('whatever'));
    }

    #[Test]
    public function aNon2xxIsAHardError(): void
    {
        $store = $this->store(new MockResponse('{"errors":["permission denied"]}', ['http_code' => 403]));
        $this->expectException(SecretStoreException::class);
        $store->get('x');
    }

    #[Test]
    public function aMissingTokenThrows(): void
    {
        unset($_ENV[self::TOKEN_ENV]);
        $store = $this->store($this->kvResponse(['a' => '1']));
        $this->expectException(SecretStoreException::class);
        $store->get('a');
    }

    #[Test]
    public function theMapIsFetchedOnlyOncePerInstance(): void
    {
        $calls = 0;
        $http = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return $this->kvResponse(['a' => '1', 'b' => '2']);
        });
        $store = new VaultSecretStore($http, 'http://vault:8200', self::TOKEN_ENV, 'secret/data/coolms');

        $store->get('a');
        $store->get('b');
        $store->has('a');

        self::assertSame(1, $calls);
    }

    protected function setUp(): void
    {
        $_ENV[self::TOKEN_ENV] = 'vault-token-xyz';
    }

    protected function tearDown(): void
    {
        unset($_ENV[self::TOKEN_ENV]);
    }

    /**
     * @param array<string, string> $data
     */
    private function kvResponse(array $data): MockResponse
    {
        return new MockResponse(
            (string) json_encode(['data' => ['data' => $data, 'metadata' => []]]),
            ['http_code' => 200],
        );
    }

    /**
     * @param array<string, mixed> $captured
     */
    private function store(MockResponse $response, array &$captured = []): VaultSecretStore
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($response, &$captured): MockResponse {
            $captured['method'] = $method;
            $captured['url'] = $url;
            $captured['headers'] = $options['headers'] ?? [];

            return $response;
        });

        return new VaultSecretStore($http, 'http://vault:8200', self::TOKEN_ENV, 'secret/data/coolms');
    }
}

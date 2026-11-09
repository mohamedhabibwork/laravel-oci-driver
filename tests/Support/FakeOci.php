<?php

namespace LaravelOCI\LaravelOciDriver\Tests\Support;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use LaravelOCI\LaravelOciDriver\OciAdapter;
use LaravelOCI\LaravelOciDriver\OciClient;
use LaravelOCI\LaravelOciDriver\Support\HttpClientFactory;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Test double that wires an OciClient to a Guzzle MockHandler and records every request.
 */
final class FakeOci
{
    private MockHandler $mock;

    /** @var array<int, array{request: RequestInterface, response: ?ResponseInterface, options: array<string, mixed>}> */
    private array $history = [];

    private OciClient $client;

    /**
     * @param  array<string, mixed>  $configOverrides
     */
    private function __construct(array $configOverrides)
    {
        $this->mock = new MockHandler;
        $config = array_merge(self::baseConfig(), $configOverrides);

        // History wraps the mock (innermost), so every retried attempt is recorded.
        $recordingHandler = Middleware::history($this->history)($this->mock);

        $this->client = OciClient::createWithConfiguration(
            $config,
            HttpClientFactory::make($config, $recordingHandler),
        );
    }

    /**
     * @param  array<string, mixed>  $configOverrides
     */
    public static function make(array $configOverrides = []): self
    {
        return new self($configOverrides);
    }

    /**
     * @return array<string, mixed>
     */
    public static function baseConfig(): array
    {
        return [
            'namespace' => 'ns',
            'region' => 'me-jeddah-1',
            'bucket' => 'bkt',
            'tenancy_id' => 'ocid1.tenancy.oc1..t',
            'user_id' => 'ocid1.user.oc1..u',
            'key_fingerprint' => 'aa:bb',
            'key_path' => __DIR__.'/../fixtures/test-key.pem',
            'url_path_prefix' => '',
            'storage_tier' => 'Standard',
            'retry_attempts' => 0,
        ];
    }

    public function client(): OciClient
    {
        return $this->client;
    }

    public function adapter(): OciAdapter
    {
        return new OciAdapter($this->client, static fn (int $microseconds) => null);
    }

    public function queue(ResponseInterface|\Throwable ...$responses): self
    {
        foreach ($responses as $response) {
            $this->mock->append($response);
        }

        return $this;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string|list<string>>  $headers
     */
    public static function json(int $status, array $body, array $headers = []): Response
    {
        return new Response(
            $status,
            array_merge(['Content-Type' => 'application/json'], $headers),
            json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return list<RequestInterface>
     */
    public function requests(): array
    {
        return array_map(fn (array $entry): RequestInterface => $entry['request'], $this->history);
    }

    public function lastRequest(): RequestInterface
    {
        $requests = $this->requests();

        return $requests[array_key_last($requests)]
            ?? throw new \LogicException('No requests were recorded.');
    }

    /**
     * @return list<array{request: RequestInterface, response: ?ResponseInterface, options: array<string, mixed>}>
     */
    public function history(): array
    {
        return array_values($this->history);
    }
}

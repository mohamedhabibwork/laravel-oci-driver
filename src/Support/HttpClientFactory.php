<?php

namespace LaravelOCI\LaravelOciDriver\Support;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\RequestOptions;

/**
 * Builds the Guzzle client used for OCI requests, with timeouts and retry middleware.
 *
 * @internal
 */
final class HttpClientFactory
{
    /**
     * @param  array<string, mixed>  $config
     * @param  callable|null  $handler  Low-level handler override (e.g. a MockHandler in tests)
     */
    public static function make(array $config, ?callable $handler = null): ClientInterface
    {
        $decider = new RetryDecider(
            (int) ($config['retry_attempts'] ?? 3),
            (int) ($config['retry_delay'] ?? 1000),
        );

        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::retry($decider, [$decider, 'delay']), 'oci_retry');

        return new Client([
            'handler' => $stack,
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::ALLOW_REDIRECTS => false,
            RequestOptions::TIMEOUT => (int) ($config['timeout'] ?? 30),
            RequestOptions::CONNECT_TIMEOUT => (int) ($config['connect_timeout'] ?? 10),
        ]);
    }
}

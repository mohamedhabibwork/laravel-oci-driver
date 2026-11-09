<?php

namespace LaravelOCI\LaravelOciDriver\Support;

use GuzzleHttp\Exception\ConnectException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Decides whether a failed OCI request is retried and how long to wait.
 *
 * @internal
 */
final class RetryDecider
{
    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];

    private const IDEMPOTENT_METHODS = ['GET', 'HEAD', 'PUT', 'DELETE'];

    public function __construct(
        private readonly int $maxAttempts,
        private readonly int $baseDelayMs,
    ) {}

    public function __invoke(int $retries, RequestInterface $request, ?ResponseInterface $response = null, ?\Throwable $exception = null): bool
    {
        if ($retries >= $this->maxAttempts) {
            return false;
        }

        if (! $this->isFailureRetryable($response, $exception) || ! $this->isRequestRetryable($request)) {
            return false;
        }

        $body = $request->getBody();
        if ($body->getSize() !== 0) {
            $body->rewind();
        }

        return true;
    }

    /**
     * Exponential backoff in milliseconds for the given retry number (1-based).
     */
    public function delay(int $retries): int
    {
        return $this->baseDelayMs * 2 ** ($retries - 1);
    }

    private function isFailureRetryable(?ResponseInterface $response, ?\Throwable $exception): bool
    {
        if ($exception !== null) {
            return $exception instanceof ConnectException;
        }

        return $response !== null && in_array($response->getStatusCode(), self::RETRYABLE_STATUSES, true);
    }

    private function isRequestRetryable(RequestInterface $request): bool
    {
        $method = strtoupper($request->getMethod());
        $retryableMethod = in_array($method, self::IDEMPOTENT_METHODS, true)
            || ($method === 'POST' && $request->hasHeader('opc-retry-token'));

        if (! $retryableMethod) {
            return false;
        }

        $body = $request->getBody();

        return $body->getSize() === 0 || $body->isSeekable();
    }
}

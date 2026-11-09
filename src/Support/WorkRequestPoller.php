<?php

declare(strict_types=1);

namespace LaravelOCI\LaravelOciDriver\Support;

use Closure;
use GuzzleHttp\Exception\GuzzleException;
use LaravelOCI\LaravelOciDriver\Exception\OciRequestFailed;
use LaravelOCI\LaravelOciDriver\OciClient;
use RuntimeException;

/**
 * Polls an OCI Object Storage work request until it reaches a terminal state.
 *
 * @internal
 */
final class WorkRequestPoller
{
    private readonly Closure $sleep;

    /**
     * @param  int  $timeoutSeconds  Give up once this much time has elapsed (checked after each poll)
     * @param  int  $intervalMs  Pause between polls, in milliseconds
     * @param  ?Closure  $sleep  Receives microseconds; defaults to usleep
     */
    public function __construct(
        private readonly OciClient $client,
        private readonly int $timeoutSeconds,
        private readonly int $intervalMs,
        ?Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? usleep(...);
    }

    /**
     * @throws RuntimeException When the work request fails, is canceled or times out
     * @throws OciRequestFailed|GuzzleException When the status request itself fails
     */
    public function wait(string $workRequestId): void
    {
        $uri = sprintf('%s/workRequests/%s', $this->client->getHost(), rawurlencode($workRequestId));
        $startedAt = hrtime(true);

        while (true) {
            $response = ResponseGuard::ok($this->client->send($uri, 'GET'), 'get work request');
            $decoded = json_decode((string) $response->getBody(), true);
            $status = is_array($decoded) ? (string) ($decoded['status'] ?? '') : '';

            if ($status === 'COMPLETED') {
                return;
            }

            if ($status === 'FAILED' || $status === 'CANCELED') {
                throw new RuntimeException("work request {$workRequestId} {$status}");
            }

            $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
            if ($elapsedSeconds >= $this->timeoutSeconds) {
                throw new RuntimeException("work request {$workRequestId} timed out");
            }

            ($this->sleep)($this->intervalMs * 1000);
        }
    }
}

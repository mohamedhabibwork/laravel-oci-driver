<?php

declare(strict_types=1);

namespace LaravelOCI\LaravelOciDriver\Support;

use LaravelOCI\LaravelOciDriver\Exception\OciRequestFailed;
use Psr\Http\Message\ResponseInterface;

/**
 * Turns unexpected HTTP statuses into OciRequestFailed.
 *
 * @internal
 */
final class ResponseGuard
{
    /**
     * @param  int  ...$accepted  Accepted status codes (default: 200)
     *
     * @throws OciRequestFailed
     */
    public static function ok(ResponseInterface $response, string $operation, int ...$accepted): ResponseInterface
    {
        $accepted = $accepted === [] ? [200] : $accepted;

        if (! in_array($response->getStatusCode(), $accepted, true)) {
            throw OciRequestFailed::fromResponse($response, $operation);
        }

        return $response;
    }
}

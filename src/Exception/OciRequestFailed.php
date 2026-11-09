<?php

declare(strict_types=1);

namespace LaravelOCI\LaravelOciDriver\Exception;

use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Raised when OCI answers with an unexpected HTTP status.
 *
 * @internal
 */
final class OciRequestFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?string $requestId = null,
        public readonly ?string $ociCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public static function fromResponse(ResponseInterface $response, string $operation): self
    {
        $status = $response->getStatusCode();
        $requestId = $response->getHeaderLine('opc-request-id') ?: null;

        $body = (string) $response->getBody();
        if ($response->getBody()->isSeekable()) {
            $response->getBody()->rewind();
        }

        $decoded = json_decode($body, true);
        $ociCode = is_array($decoded) && isset($decoded['code']) && is_string($decoded['code']) ? $decoded['code'] : null;
        $ociMessage = is_array($decoded) && isset($decoded['message']) && is_string($decoded['message']) ? $decoded['message'] : null;

        $message = sprintf('OCI %s failed with HTTP %d', $operation, $status);
        if ($ociCode !== null) {
            $message .= ' ('.$ociCode.')';
        }
        if ($ociMessage !== null) {
            $message .= ': '.$ociMessage;
        }
        if ($requestId !== null) {
            $message .= ' [opc-request-id: '.$requestId.']';
        }

        return new self($message, $status, $requestId, $ociCode);
    }
}

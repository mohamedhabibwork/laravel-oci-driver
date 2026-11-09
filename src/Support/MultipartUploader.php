<?php

declare(strict_types=1);

namespace LaravelOCI\LaravelOciDriver\Support;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Utils;
use LaravelOCI\LaravelOciDriver\Exception\OciRequestFailed;
use LaravelOCI\LaravelOciDriver\OciClient;
use Psr\Http\Message\StreamInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Native OCI multipart upload. Memory use peaks at roughly one part.
 *
 * @internal
 */
final class MultipartUploader
{
    private const MAX_PARTS = 10000;

    private const META_PREFIX = 'opc-meta-';

    public function __construct(private readonly OciClient $client, private readonly int $partSize) {}

    /**
     * @param  array<string, string>  $headers  storage-tier, Content-Type and opc-meta-* headers
     *
     * @throws \LengthException When the source needs more than 10000 parts
     * @throws OciRequestFailed
     * @throws GuzzleException
     */
    public function upload(string $objectName, StreamInterface $source, array $headers): void
    {
        $uploadId = $this->create($objectName, $headers);

        try {
            $parts = $this->uploadParts($objectName, $uploadId, $source);
            $this->commit($objectName, $uploadId, $parts);
        } catch (Throwable $exception) {
            $this->abort($objectName, $uploadId);

            throw $exception;
        }
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function create(string $objectName, array $headers): string
    {
        // OCI expects metadata keys in opc-meta-* form; headers already carry it.
        $metadata = [];
        foreach ($headers as $name => $value) {
            if (stripos($name, self::META_PREFIX) === 0) {
                $metadata[$name] = $value;
            }
        }

        $body = [
            'object' => $objectName,
            'contentType' => $headers['Content-Type'] ?? 'application/octet-stream',
            'storageTier' => $headers['storage-tier'] ?? $this->client->getStorageTier()->value(),
        ];
        if ($metadata !== []) {
            $body['metadata'] = $metadata;
        }

        $response = ResponseGuard::ok($this->client->send(
            $this->client->getBucketUri().'/u',
            'POST',
            ['opc-retry-token' => Uuid::uuid4()->toString()],
            json_encode($body, JSON_THROW_ON_ERROR),
        ), 'multipart create');

        $uploadId = json_decode((string) $response->getBody(), true)['uploadId'] ?? null;
        if (! is_string($uploadId) || $uploadId === '') {
            throw new \UnexpectedValueException('OCI multipart create returned no uploadId.');
        }

        return $uploadId;
    }

    /**
     * @return list<array{partNum: int, etag: string}>
     */
    private function uploadParts(string $objectName, string $uploadId, StreamInterface $source): array
    {
        $parts = [];
        $partNumber = 0;

        do {
            $part = Utils::streamFor(fopen('php://temp', 'w+'));
            Utils::copyToStream($source, $part, $this->partSize);

            // A trailing empty read (pipe ended exactly on a boundary) is not a part,
            // but an entirely empty source still needs one empty part.
            if ($part->getSize() === 0 && $partNumber > 0) {
                break;
            }

            if (++$partNumber > self::MAX_PARTS) {
                throw new \LengthException('Multipart upload would exceed '.self::MAX_PARTS.' parts; increase upload.chunk_size.');
            }

            $part->rewind();
            $response = ResponseGuard::ok($this->client->send(
                $this->uploadUri($objectName, $uploadId, $partNumber),
                'PUT',
                [],
                $part,
                'application/octet-stream',
                ['timeout' => 0],
            ), 'multipart part');

            $parts[] = ['partNum' => $partNumber, 'etag' => $response->getHeaderLine('etag')];
        } while (! $source->eof());

        return $parts;
    }

    /**
     * @param  list<array{partNum: int, etag: string}>  $parts
     */
    private function commit(string $objectName, string $uploadId, array $parts): void
    {
        ResponseGuard::ok($this->client->send(
            $this->uploadUri($objectName, $uploadId),
            'POST',
            ['opc-retry-token' => Uuid::uuid4()->toString()],
            json_encode(['partsToCommit' => $parts], JSON_THROW_ON_ERROR),
        ), 'multipart commit');
    }

    private function abort(string $objectName, string $uploadId): void
    {
        try {
            $this->client->send($this->uploadUri($objectName, $uploadId), 'DELETE');
        } catch (Throwable) {
            // Best effort; the original failure is what matters.
        }
    }

    private function uploadUri(string $objectName, string $uploadId, ?int $partNumber = null): string
    {
        $uri = $this->client->getBucketUri().'/u/'.ObjectNameEncoder::encode($objectName).'?uploadId='.rawurlencode($uploadId);

        return $partNumber === null ? $uri : $uri.'&uploadPartNum='.$partNumber;
    }
}

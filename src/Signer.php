<?php

namespace LaravelOCI\LaravelOciDriver;

use LaravelOCI\LaravelOciDriver\Exception\PrivateKeyFileNotFoundException;
use LaravelOCI\LaravelOciDriver\Exception\SignerValidateException;
use LaravelOCI\LaravelOciDriver\Exception\SigningValidationFailedException;
use LaravelOCI\LaravelOciDriver\KeyProvider\EnvironmentKeyProvider;
use LaravelOCI\LaravelOciDriver\KeyProvider\FileKeyProvider;
use LaravelOCI\LaravelOciDriver\KeyProvider\KeyProviderInterface;
use OpenSSLAsymmetricKey;

final class Signer
{
    const OCI_TENANCY_ID = 'OCI_TENANCY_ID';

    const OCI_USER_ID = 'OCI_USER_ID';

    const OCI_KEY_FINGERPRINT = 'OCI_KEY_FINGERPRINT';

    const OCI_PRIVATE_KEY_FILENAME = 'OCI_PRIVATE_KEY_FILENAME';

    const SIGNING_HEADER_DATE = 'date';

    const SIGNING_HEADER_REQUEST_TARGET = '(request-target)';

    const SIGNING_HEADER_HOST = 'host';

    const SIGNING_HEADER_CONTENT_LENGTH = 'content-length';

    const SIGNING_HEADER_CONTENT_TYPE = 'content-type';

    const SIGNING_HEADER_X_CONTENT_SHA256 = 'x-content-sha256';

    const CONTENT_TYPE_APPLICATION_JSON = 'application/json';

    private string $ociTenancyId;

    private string $ociUserId;

    private string $ociKeyFingerPrint;

    private string $ociPrivateKeyLocation;

    private ?KeyProviderInterface $keyProvider = null;

    private ?string $passphrase = null;

    private ?OpenSSLAsymmetricKey $parsedKey = null;

    /**
     * Signer constructor.
     */
    public function __construct(?string $ociTenancyId = null, ?string $ociUserId = null, ?string $keyFingerPrint = null, ?string $privateKeyLocation = null)
    {
        $this->ociTenancyId = $ociTenancyId ?? '';
        $this->ociUserId = $ociUserId ?? '';
        $this->ociKeyFingerPrint = $keyFingerPrint ?? '';
        $this->ociPrivateKeyLocation = $privateKeyLocation ?? '';
    }

    /**
     * Build a signer from a connection config array.
     *
     * Key source precedence: private_key, private_key_base64, key_path.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws PrivateKeyFileNotFoundException When no key source is configured or the base64 key is invalid
     */
    public static function fromConfig(array $config): self
    {
        $tenancy = (string) ($config['tenancy_id'] ?? '');
        $user = (string) ($config['user_id'] ?? '');
        $fingerprint = (string) ($config['key_fingerprint'] ?? '');
        $passphrase = isset($config['passphrase']) && $config['passphrase'] !== '' ? (string) $config['passphrase'] : null;

        $signer = new self($tenancy, $user, $fingerprint, (string) ($config['key_path'] ?? ''));
        $signer->passphrase = $passphrase;

        if (! empty($config['private_key'])) {
            $signer->keyProvider = new EnvironmentKeyProvider((string) $config['private_key'], $tenancy, $user, $fingerprint);
        } elseif (! empty($config['private_key_base64'])) {
            $decoded = base64_decode((string) $config['private_key_base64'], true);
            if ($decoded === false || $decoded === '') {
                throw new PrivateKeyFileNotFoundException('(invalid private_key_base64)');
            }
            $signer->keyProvider = new EnvironmentKeyProvider($decoded, $tenancy, $user, $fingerprint);
        } elseif (! empty($config['key_path'])) {
            $signer->keyProvider = new FileKeyProvider((string) $config['key_path'], $tenancy, $user, $fingerprint);
        } else {
            throw new PrivateKeyFileNotFoundException('(no private_key, private_key_base64 or key_path configured)');
        }

        return $signer;
    }

    /**
     * @return string[]
     *
     * @throws PrivateKeyFileNotFoundException
     * @throws SignerValidateException
     * @throws SigningValidationFailedException
     */
    public function getHeaders(string $url, string $method = 'GET', ?string $body = null, ?string $contentType = self::CONTENT_TYPE_APPLICATION_JSON, ?string $dateString = null, bool $excludeBodyHeaders = false): array
    {
        $this->validateParameters($url);

        $dateString ??= gmdate('D, d M Y H:i:s \G\M\T');
        $headersToSign = $this->getHeadersToSign($url, $method, $body, $contentType, $dateString, $excludeBodyHeaders);
        $signingString = $this->getSigningString($url, $method, $body, $contentType, $dateString, $excludeBodyHeaders);
        $signature = $this->calculateSignature($signingString, $this->getParsedKey());

        $headers = [];
        foreach ($headersToSign as $headerName => $value) {
            if ($headerName === self::SIGNING_HEADER_REQUEST_TARGET) {
                continue;
            }
            $headers[] = "$headerName: $value";
        }
        $keyId = $this->getKeyId();
        $headers[] = $this->getAuthorizationHeader($keyId, implode(' ', array_keys($headersToSign)), $signature);

        return $headers;
    }

    /**
     * @throws SigningValidationFailedException
     */
    public function calculateSignature(string $signingString, OpenSSLAsymmetricKey|string $privateKey): string
    {
        $privateKeyId = $privateKey instanceof OpenSSLAsymmetricKey ? $privateKey : $this->parsePrivateKey($privateKey);

        // compute signature
        $result = openssl_sign($signingString, $binarySignature, $privateKeyId, OPENSSL_ALGO_SHA256); // sha256WithRSAEncryption
        if (! $result) {
            throw new SigningValidationFailedException('Cannot generate signature.');
        }

        $signatureBase64 = base64_encode($binarySignature);

        // verify
        $details = openssl_pkey_get_details($privateKeyId);
        $public_key_pem = $details['key'];
        $r = openssl_verify($signingString, $binarySignature, $public_key_pem, 'sha256WithRSAEncryption');
        if ($r !== 1) {
            throw new SigningValidationFailedException('Cannot verify signature.');
        }

        return $signatureBase64;
    }

    public function getSigningString(
        string $url,
        string $method = 'GET',
        ?string $body = null,
        ?string $contentType = self::CONTENT_TYPE_APPLICATION_JSON,
        ?string $dateString = null,
        bool $excludeBodyHeaders = false
    ): string {
        $headersToSign = $this->getHeadersToSign($url, $method, $body, $contentType, $dateString, $excludeBodyHeaders);
        $signingHeaders = [];
        foreach ($headersToSign as $header => $value) {
            $signingHeaders[] = "$header: $value";
        }

        return implode("\n", $signingHeaders);
    }

    public function getKeyId(): string
    {
        if ($this->keyProvider !== null) {
            return $this->keyProvider->getKeyId();
        }

        return implode('/', [
            $this->ociTenancyId,
            $this->ociUserId,
            $this->ociKeyFingerPrint,
        ]);
    }

    public function getBodyHashBase64(?string $body): string
    {
        $bodyHashBinary = hash('sha256', $body ?? '', true);

        return base64_encode($bodyHashBinary);
    }

    /**
     * @return string[]
     */
    public function getSigningHeadersNames(string $method, bool $excludeBodyHeaders = false): array
    {
        $signingHeaders = $this->getGenericHeadersNames();
        if (! $excludeBodyHeaders && $this->shouldHashBody($method)) {
            $bodyHeaders = $this->getBodyHeadersNames();
            $signingHeaders = array_merge($signingHeaders, $bodyHeaders);
        }

        return $signingHeaders;
    }

    public function setKeyProvider(KeyProviderInterface $keyProvider): void
    {
        $this->keyProvider = $keyProvider;
        $this->parsedKey = null;
    }

    public function getAuthorizationHeader(string $keyId, string $signedHeaders, string $signatureBase64): string
    {
        return "Authorization: Signature version=\"1\",keyId=\"$keyId\",algorithm=\"rsa-sha256\",headers=\"$signedHeaders\",signature=\"$signatureBase64\"";
    }

    /**
     * Parse the private key once and cache it on the instance.
     *
     * @throws PrivateKeyFileNotFoundException
     * @throws SigningValidationFailedException
     */
    private function getParsedKey(): OpenSSLAsymmetricKey
    {
        if ($this->parsedKey === null) {
            $provider = $this->keyProvider ?? new FileKeyProvider(
                $this->ociPrivateKeyLocation,
                $this->ociTenancyId,
                $this->ociUserId,
                $this->ociKeyFingerPrint,
            );
            $this->parsedKey = $this->parsePrivateKey($provider->getPrivateKey());
        }

        return $this->parsedKey;
    }

    /**
     * @throws SigningValidationFailedException
     */
    private function parsePrivateKey(string $pem): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_get_private($pem, $this->passphrase ?? '');
        if ($key === false) {
            throw new SigningValidationFailedException('Cannot parse private key.');
        }

        return $key;
    }

    private function shouldHashBody(string $method): bool
    {
        return in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true);
    }

    /**
     * @return array<string, string>
     */
    private function getHeadersToSign(string $url, string $method, ?string $body, ?string $contentType, ?string $dateString = null, bool $excludeBodyHeaders = false): array
    {
        $parsed = parse_url($url);

        $headersMap = [];
        $headersNames = $this->getSigningHeadersNames($method, $excludeBodyHeaders);
        foreach ($headersNames as $headerName) {
            switch ($headerName) {
                case self::SIGNING_HEADER_DATE:
                    if (! $dateString) {
                        $dateString = gmdate('D, d M Y H:i:s \G\M\T');
                    }
                    $headersMap[self::SIGNING_HEADER_DATE] = $dateString;
                    break;
                case self::SIGNING_HEADER_REQUEST_TARGET:
                    $uri = $parsed['path'] ?? '';
                    if (! empty($parsed['query'])) {
                        $uri .= '?'.$parsed['query'];
                    }
                    $headersMap[self::SIGNING_HEADER_REQUEST_TARGET] = strtolower($method)." $uri";
                    break;
                case self::SIGNING_HEADER_HOST:
                    $headersMap[self::SIGNING_HEADER_HOST] = $parsed['host'] ?? '';
                    break;
                case self::SIGNING_HEADER_CONTENT_LENGTH:
                    $contentLength = $this->getContentLength($body);
                    $headersMap[self::SIGNING_HEADER_CONTENT_LENGTH] = $contentLength;
                    break;
                case self::SIGNING_HEADER_CONTENT_TYPE:
                    $headersMap[self::SIGNING_HEADER_CONTENT_TYPE] = $contentType ?? '';
                    break;
                case self::SIGNING_HEADER_X_CONTENT_SHA256:
                    $bodyHashBase64 = $this->getBodyHashBase64($body);
                    $headersMap[self::SIGNING_HEADER_X_CONTENT_SHA256] = $bodyHashBase64;
                    break;
                default:
                    break;
            }
        }

        return $headersMap;
    }

    /**
     * @return string[]
     */
    private function getGenericHeadersNames(): array
    {
        return [
            self::SIGNING_HEADER_DATE,
            self::SIGNING_HEADER_REQUEST_TARGET,
            self::SIGNING_HEADER_HOST,
        ];
    }

    /**
     * @return string[]
     */
    private function getBodyHeadersNames(): array
    {
        return [
            self::SIGNING_HEADER_CONTENT_LENGTH,
            self::SIGNING_HEADER_CONTENT_TYPE,
            self::SIGNING_HEADER_X_CONTENT_SHA256,
        ];
    }

    /**
     * @throws PrivateKeyFileNotFoundException
     * @throws SignerValidateException
     */
    private function validateParameters(string $url): void
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new SignerValidateException("URL is invalid: $url");
        }

        if ($this->keyProvider !== null) {
            return;
        }

        if (
            empty($this->ociUserId) ||
            empty($this->ociTenancyId) ||
            empty($this->ociKeyFingerPrint) ||
            empty($this->ociPrivateKeyLocation)
        ) {
            throw new SignerValidateException('OCI User ID, tenancy ID, key fingerprint and private key filename are required.');
        }

    }

    private function getContentLength(?string $body): int
    {
        return strlen($body ?? '');
    }
}

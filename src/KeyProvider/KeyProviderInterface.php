<?php

namespace LaravelOCI\LaravelOciDriver\KeyProvider;

interface KeyProviderInterface
{
    /**
     * Returns the contents of privatekey.pem
     */
    public function getPrivateKey(): string;

    public function getKeyId(): string;
}

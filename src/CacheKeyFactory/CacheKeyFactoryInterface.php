<?php

declare(strict_types=1);

namespace Webclient\Extension\Cache\CacheKeyFactory;

use Psr\Http\Message\UriInterface;

interface CacheKeyFactoryInterface
{
    public function getSettingsKey(UriInterface $uri): string;

    public function getResponseKey(UriInterface $uri, array $vary, ?string $privateId): string;
}

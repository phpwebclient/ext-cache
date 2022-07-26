<?php

declare(strict_types=1);

namespace Webclient\Extension\Cache\CacheKeyFactory;

use Psr\Http\Message\UriInterface;

final class CacheKeyFactory implements CacheKeyFactoryInterface
{
    public function getSettingsKey(UriInterface $uri): string
    {
        return 'http.settings.' . $this->getKey($uri);
    }

    public function getResponseKey(UriInterface $uri, array $vary, ?string $privateId): string
    {
        $prefix = is_null($privateId) ? 'public' : 'private_' . $this->hash($privateId);
        $varyParts = [];
        foreach ($vary as $headerField => $value) {
            $varyParts[] = $headerField . ':' . $value;
        }
        $varyPart = implode(',', $varyParts);
        $varyPartHash = $varyPart === '' ? '' : '_' . $this->hash($varyPart);
        return 'http.response.' . $prefix . '_' . $this->getKey($uri) . $varyPartHash;
    }

    private function getKey(UriInterface $uri): string
    {
        return $this->hash((string)$uri);
    }

    private function hash(string $string): string
    {
        return sha1($string) . md5($string);
    }
}

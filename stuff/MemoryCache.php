<?php

namespace Stuff\Webclient\Extension\Cache;

use Webclient\Cache\Contract\CacheInterface;

class MemoryCache implements CacheInterface
{
    private array $storage = [];

    /**
     * @inheritDoc
     */
    public function get(string $key): ?string
    {
        $item = $this->storage[$key] ?? null;
        if (is_null($item)) {
            return null;
        }
        $expired = $item['expired'] ?? null;
        if (is_null($expired)) {
            return $item['data'] ?? null;
        }
        if ($expired <= time()) {
            unset($this->storage[$key]);
            return null;
        }
        return $item['data'] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function set(string $key, string $data, ?int $ttl = null): void
    {
        $item = [
            'data' => $data,
        ];
        if (!is_null($ttl)) {
            $item['expired'] = time() + $ttl;
        }
        $this->storage[$key] = $item;
    }

    public function clear(): void
    {
        $this->storage = [];
    }
}

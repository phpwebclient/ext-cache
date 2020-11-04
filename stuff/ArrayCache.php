<?php

namespace Stuff\Webclient\Extension\Cache;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

class ArrayCache implements CacheInterface
{

    /**
     * @var array
     */
    private $storage = [];

    /**
     * @inheritDoc
     */
    public function get($key, $default = null)
    {
        if (!$this->has($key)) {
            return $default;
        }
        return $this->storage[$key]['value'];
    }

    /**
     * @inheritDoc
     */
    public function set($key, $value, $ttl = null)
    {
        $item = [
            'value' => $value,
        ];
        $seconds = is_int($ttl) ? $ttl : 0;
        if ($ttl instanceof DateInterval) {
            if ($ttl->invert === 1) {
                return false;
            }
            $seconds = $ttl->days * 86400 + $ttl->h * 3600 + $ttl->i * 60 + $ttl->s;
        }
        if ($seconds) {
            $item['expired'] = time() + $seconds;
        }
        $this->storage[$key] = $item;
        return true;
    }

    /**
     * @inheritDoc
     */
    public function delete($key)
    {
        if (array_key_exists($key, $this->storage)) {
            unset($this->storage[$key]);
        }
        return true;
    }

    /**
     * @inheritDoc
     */
    public function clear()
    {
        $this->storage = [];
        return true;
    }

    /**
     * @inheritDoc
     */
    public function getMultiple($keys, $default = null)
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    /**
     * @inheritDoc
     */
    public function setMultiple($values, $ttl = null)
    {
        $result = true;
        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                $result = false;
            }
        }
        return $result;
    }

    /**
     * @inheritDoc
     */
    public function deleteMultiple($keys)
    {
        $result = true;
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $result = false;
            }
        }
        return $result;
    }

    /**
     * @inheritDoc
     */
    public function has($key)
    {
        if (!array_key_exists($key, $this->storage)) {
            return false;
        }
        if (!array_key_exists('value', $this->storage[$key])) {
            unset($this->storage[$key]);
            return false;
        }
        $expired = false;
        if (array_key_exists('expired', $this->storage[$key])) {
            $expired = time() >= $this->storage[$key]['expired'];
        }
        if ($expired) {
            unset($this->storage[$key]);
            return false;
        }
        return true;
    }
}

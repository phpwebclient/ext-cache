<?php

declare(strict_types=1);

namespace Webclient\Extension\Cache;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use Throwable;

use function array_key_exists;
use function array_map;
use function array_replace;
use function array_shift;
use function explode;
use function implode;
use function in_array;
use function json_decode;
use function ksort;
use function mb_strtolower;
use function min;
use function sha1;
use function sort;
use function strpos;
use function strtotime;
use function time;
use function trim;

class Client implements ClientInterface
{

    /**
     * @var ClientInterface
     */
    private $client;

    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * @var ResponseFactoryInterface
     */
    private $responseFactory;

    /**
     * @var StreamFactoryInterface
     */
    private $streamFactory;

    /**
     * @var string
     */
    private $privateId;

    public function __construct(
        ClientInterface $client,
        CacheInterface $cache,
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        string $privateId
    ) {
        $this->client = $client;
        $this->cache = $cache;
        $this->responseFactory = $responseFactory;
        $this->streamFactory = $streamFactory;
        $this->privateId = $privateId;
    }

    /**
     * @inheritDoc
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $response = $this->getFromCache($request);
        if (!$response) {
            $response = $this->client->sendRequest($request);
        }
        $this->cacheResponse($response, $request);
        return $response;
    }

    private function getRequestHeaders(RequestInterface $request): array
    {
        $headers = [];
        foreach (array_keys($request->getHeaders()) as $header) {
            $parsed = $this->parseHeader($request->getHeader($header));
            sort($parsed['values']);
            ksort($parsed['attr']);
            $parts = [implode(';', $parsed['values'])];
            foreach ($parsed['attr'] as $attr => $item) {
                $parts[] = $attr . '=' . $item;
            }
            $headers[mb_strtolower($header)] = implode(';', $parts);
        }
        return $headers;
    }

    private function getCacheKeyPrefix(UriInterface $uri, string $method): string
    {
        return $method . '_' . sha1($uri->__toString());
    }

    private function cacheResponse(ResponseInterface $response, RequestInterface $request)
    {
        $metaKey = $this->getCacheKeyPrefix($request->getUri(), $request->getMethod());
        $dataKey = $metaKey . '.data';
        $metaKey .= '.meta';
        $status = $response->getStatusCode();
        if ($status >= 400 || $status == 304) {
            return;
        }
        $control = $this->parseHeader($response->getHeader('Cache-Control'));
        if (in_array('no-store', $control['values'])) {
            return;
        }
        $meta = [
            'no-cache' => in_array('no-cache', $control['values']),
            'private' => in_array('private', $control['values']),
            'only-if-cached' => in_array('only-if-cached', $control['values']),
            'must-revalidate' => in_array('must-revalidate', $control['values']),
            'stored' => time(),
            'expires' => 0,
            'last-modified' => $response->getHeaderLine('Last-Modified'),
            'etag' => $response->getHeaderLine('ETag'),
            'vary' => $this->parseHeader($response->getHeader('Vary'))['values'],
        ];
        $time = time();
        if (array_key_exists('max-age', $control['attr'])) {
            $meta['expires'] = (int)$control['attr']['max-age'] + $time;
        }
        if (!$meta['expires'] && $response->hasHeader('Expires')) {
            $meta['expires'] = strtotime($response->getHeaderLine('Expires'));
        }
        if (!$meta['expires'] || $time > $meta['expires']) {
            return;
        }

        $ttl = ($meta['expires'] - $time) * 2;
        $dataKey .= $this->getVaryHash($this->getRequestHeaders($request), $meta['vary']);
        $dataKey .= $this->getPrivateHash((bool)$meta['private']);
        try {
            $this->cache->set($metaKey, json_encode($meta), $ttl);
            $this->cache->set($dataKey, $this->encodeResponse($response), $ttl);
        } catch (InvalidArgumentException $e) {
        } catch (Throwable $e) {
        }
    }

    private function getVaryHash(array $headers, array $vary): string
    {
        $varyHeaders = [];
        ksort($vary);
        foreach ($vary as $header) {
            if (array_key_exists($header, $headers)) {
                $varyHeaders[] = $header . ':' . $headers[$header];
            }
        }
        if ($varyHeaders) {
            return '.' . sha1(implode('|', $varyHeaders));
        }
        return '';
    }

    private function getPrivateHash(bool $private): string
    {
        if ($private) {
            return '_' . sha1($this->privateId);
        }
        return '';
    }

    private function parseHeader(array $lines): array
    {
        $result = [
            'values' => [],
            'attr' => [],
        ];
        $lines = array_map('trim', $lines);
        foreach ($lines as $line) {
            $arr = explode('=', $line, 2);
            $key = mb_strtolower(trim($arr[0]));
            $value = array_key_exists(1, $arr) ? trim($arr[1]) : null;
            if ($value) {
                $result['attr'][$key] = $value;
            } else {
                $result['values'][] = $key;
            }
        }
        return $result;
    }

    /**
     * @param RequestInterface $request
     * @return null|ResponseInterface
     * @throws ClientExceptionInterface
     */
    private function getFromCache(RequestInterface $request)
    {
        $method = $request->getMethod();
        $uri = $request->getUri();
        $header = $this->parseHeader($request->getHeader('Cache-Control'));
        $control = [
            'no-cache' => in_array('no-cache', $header['values']),
            'no-store' => in_array('no-store', $header['values']),
            'only-if-cached' => in_array('no-store', $header['values']),
            'max-age' => in_array('max-age', $header['attr']) ? (int)$header['attr']['max-age'] : null,
            'max-stale' => in_array('max-stale', $header['attr']) ? (int)$header['attr']['max-stale'] : null,
            'min-fresh' => in_array('min-fresh', $header['attr']) ? (int)$header['attr']['min-fresh'] : null,
        ];
        $methods = ['GET', 'HEAD', 'OPTIONS'];
        if (!in_array($request->getMethod(), $methods) || $control['no-cache'] || $control['no-store']) {
            return $this->client->sendRequest($request);
        }
        $metaKey = $this->getCacheKeyPrefix($uri, $method);
        $dataKey = $metaKey . '.data';
        $metaKey .= '.meta';
        $time = time();
        $meta = [
            'no-cache' => false,
            'private' => true,
            'only-if-cached' => false,
            'must-revalidate' => false,
            'stored' => $time,
            'expires' => $time,
            'last-modified' => null,
            'etag' => null,
            'vary' => [],
        ];
        try {
            $cachedMeta = (array)json_decode((string)$this->cache->get($metaKey), true);
        } catch (InvalidArgumentException $exception) {
            return null;
        } catch (Throwable $exception) {
            return null;
        }
        if (!$cachedMeta) {
            return null;
        }
        $meta = array_replace($meta, $cachedMeta);
        if ($meta['only-if-cached']) {
            $control['only-if-cached'] = false;
        }
        if ($meta['must-revalidate']) {
            $control['max-stale'] = null;
        }
        $dataKey .= $this->getVaryHash($this->getRequestHeaders($request), $meta['vary']);
        $dataKey .= $this->getPrivateHash((bool)$meta['private']);

        $expired = false;
        $age = $time - (int)$meta['stored'];
        if (!is_null($control['max-stale']) && $age > $control['max-stale']) {
            $expired = true;
        }
        if (!$expired && !is_null($control['min-fresh']) && $age < $control['min-fresh']) {
            $expired = true;
        }
        $min = (int)$meta['expires'];
        if (!is_null($control['max-age'])) {
            $min = min($min, (int)$meta['stored'] + $control['max-age']);
        }
        if (!$expired && $time > $min) {
            $expired = true;
        }
        if ($control['only-if-cached'] && $expired) {
            return $this->responseFactory->createResponse(504);
        }
        if ($meta['no-cache']) {
            $expired = true;
        }

        if ($expired) {
            $response = $this->revalidateResponse(
                $request,
                (string)$meta['last-modified'],
                (string)$meta['etag'],
                !$meta['no-store']
            );
            if ($response) {
                return $response;
            }
        }

        try {
            $raw = $this->cache->get($dataKey);
        } catch (InvalidArgumentException $exception) {
            return null;
        } catch (Throwable $exception) {
            return null;
        }
        return $this->decodeResponse((string)$raw);
    }

    private function revalidateResponse(RequestInterface $request, string $lastModified, string $eTag, bool $store)
    {
        $original = $request;
        if ($lastModified) {
            $request = $request->withHeader('If-Modified-Since', [$lastModified]);
        }
        if ($eTag) {
            $request = $request->withHeader('If-None-Match', [$eTag]);
        }
        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            return null;
        }
        if ($response->getStatusCode() === 304) {
            return null;
        }
        if ($store) {
            $this->cacheResponse($response, $original);
        }
        return null;
    }

    private function encodeResponse(ResponseInterface $response): string
    {
        $eol = "\r\n";
        $code = (string)$response->getStatusCode();
        $raw = 'HTTP/' . $response->getProtocolVersion() . ' ' . $code . ' ' . $response->getReasonPhrase() . $eol;
        $headers = [];
        foreach (array_keys($response->getHeaders()) as $header) {
            $headers[] = $header . ': ' . $response->getHeaderLine($header);
        }
        $raw .= implode($eol, $headers);
        $body = $response->getBody();
        if ($body->getSize()) {
            $response->getBody()->rewind();
            $raw .= $eol . $eol . $response->getBody()->__toString();
            $response->getBody()->rewind();
        }
        return $raw;
    }

    /**
     * @param string $raw
     * @return ResponseInterface|null
     */
    private function decodeResponse(string $raw)
    {
        if (!$raw) {
            return null;
        }
        $eol = "\r\n";
        $parts = explode($eol . $eol, $raw, 2);
        $headers = explode($eol, $parts[0]);
        if (!$headers) {
            return null;
        }
        $statusLine = trim(array_shift($headers));
        list($http, $status, $phrase) = array_replace(['', '', ''], explode(' ', $statusLine));
        if (strpos($http, 'HTTP/') === 0) {
            $http = trim(substr($http, 5));
        }
        $status = (int)$status;
        $phrase = trim($phrase);
        if ($status < 100 || $status > 599 || !in_array($http, ['1.0', '1.1', '2.0', '2'])) {
            return null;
        }
        $response = $this
            ->responseFactory->createResponse($status, $phrase)
            ->withProtocolVersion($http)
        ;
        foreach ($headers as $header) {
            list($name, $value) = array_replace(['', ''], explode(':', $header));
            $name = trim($name);
            $value = trim($value);
            if (!$name || !$value) {
                continue;
            }
            $response = $response->withHeader($name, $value);
        }
        if (array_key_exists(1, $parts)) {
            $body = $this->streamFactory->createStream((string)$parts[1]);
            $response = $response->withBody($body);
        }
        return $response;
    }
}

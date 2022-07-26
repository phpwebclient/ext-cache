<?php

declare(strict_types=1);

namespace Webclient\Extension\Cache;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriInterface;
use Webclient\Cache\Contract\CacheInterface;
use Webclient\Extension\Cache\CacheKeyFactory\CacheKeyFactory;
use Webclient\Extension\Cache\CacheKeyFactory\CacheKeyFactoryInterface;

final class CacheClientDecorator implements ClientInterface
{
    private ClientInterface $client;
    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;
    private CacheInterface $cache;
    private CacheKeyFactoryInterface $cacheKeyFactory;
    private string $privateCacheKeyRequestHeader;
    private ?int $maxCacheItemSize;
    private int $maxTtl;
    private DateTimeZone $gmt;

    public function __construct(
        ClientInterface $client,
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        CacheInterface $cache,
        ?CacheKeyFactoryInterface $cacheKeyFactory = null,
        ?string $privateCacheKeyRequestHeader = null,
        ?int $maxCacheItemSize = null,
        int $maxTtl = 2147483648
    ) {
        $this->client = $client;
        $this->responseFactory = $responseFactory;
        $this->streamFactory = $streamFactory;
        $this->cache = $cache;
        $this->cacheKeyFactory = $cacheKeyFactory ?? new CacheKeyFactory();
        $this->privateCacheKeyRequestHeader = $privateCacheKeyRequestHeader ?? 'X-Private-Cache-Key';
        $this->maxCacheItemSize = $maxCacheItemSize;
        $this->maxTtl = $this->normalizeDeltaSeconds($maxTtl);
        $this->gmt = new DateTimeZone('GMT');
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        /**
         * This package does not support http v1.0
         */
        if (in_array($request->getProtocolVersion(), ['1', '1.0'])) {
            return $this->forceRequest($request);
        }

        /**
         * @link https://datatracker.ietf.org/doc/html/rfc7234#section-2
         *
         * The most common form of cache entry is a successful result of a
         * retrieval request: i.e., a 200 (OK) response to a GET request, which
         * contains a representation of the resource identified by the request
         * target (Section 4.3.1 of [RFC7231]).
         *
         * @link https://datatracker.ietf.org/doc/html/rfc7234#section-4.3.5
         *
         * A response to the HEAD method is identical to what an equivalent
         * request made with a GET would have been, except it lacks a body.
         */
        if ($request->getMethod() !== 'GET') {
            return $this->forceRequest($request);
        }

        /**
         * @link https://datatracker.ietf.org/doc/html/rfc7234#section-3.1
         *
         * However, a cache MUST NOT store
         * incomplete or partial-content responses if it does not support the
         * Range and Content-Range header fields or if it does not understand
         * the range units used in those fields.
         */
        if ($request->hasHeader('Range') || $request->hasHeader('Content-Range')) {
            return $this->forceRequest($request);
        }

        /**
         * If caching control headers are found in the request,
         * the application is expected to process the response itself.
         */
        if ($this->isRequestForced($request)) {
            return $this->forceRequest($request);
        }

        return $this->handleRequest($request);
    }

    /**
     * @throws ClientExceptionInterface
     */
    private function handleRequest(RequestInterface $request): ResponseInterface
    {
        /**
         * Get cache settings of this request from storage.
         */
        $cacheSettings = $this->getCacheSettings($request->getUri());
        if (is_null($cacheSettings)) {
            $response = $this->forceRequest($request);
            return $this->storeResponse($response, $request);
        }

        /**
         * @link https://datatracker.ietf.org/doc/html/rfc7234#section-3
         *
         * A cache MUST NOT store a response to any request, unless:
         * ...
         * o  the "no-store" cache directive (see Section 5.2) does not appear
         * in request or response header fields, and
         * ...
         */
        $isStore = !($cacheSettings['cache-control']['no-store'] ?? false);
        if (!$isStore) {
            $response = $this->forceRequest($request);
            return $this->storeResponse($response, $request);
        }

        /**
         * @link https://datatracker.ietf.org/doc/html/rfc7234#section-4.1
         *
         * A Vary header field-value of "*" always fails to match.
         */
        if (in_array('*', $cacheSettings['vary'])) {
            return $this->forceRequest($request);
        }

        $expires = $cacheSettings['expires'] ?? null;
        if (!is_null($expires) && $expires < time()) {
            return $this->forceRequest($request);
        }

        $date = $cacheSettings['date'] ?? null;
        if (!is_null($date)) {
            $age = time() - $date;

            $requestCacheControl = $this->parseCacheControlHeader($request->getHeaderLine('Cache-Control'));

            /**
             * @link https://datatracker.ietf.org/doc/html/rfc7234#section-5.2.1.1
             *
             * The "max-age" request directive indicates that the client is unwilling to accept a response
             * whose age is greater than the specified number of seconds. Unless the max-stale request directive
             * is also present, the client is not willing to accept a stale response.
             */
            $maxAge = $requestCacheControl['max-age'] ?? null;
            if (!is_null($maxAge) && $age > $maxAge) {
                $response = $this->forceRequest($request);
                return $this->storeResponse($response, $request);
            }

            /**
             * @link https://datatracker.ietf.org/doc/html/rfc7234#section-5.2.1.3
             *
             * The "min-fresh" request directive indicates that the client is willing to accept a response
             * whose freshness lifetime is no less than its current age plus the specified time in seconds.
             * That is, the client wants a response that will still be fresh for at least
             * the specified number of seconds.
             */
            $minFresh = $requestCacheControl['min-fresh'] ?? null;
            $itemMaxAge = $cacheSettings['max-age'] ?? null;
            if (!is_null($minFresh) && !is_null($itemMaxAge) &&  $itemMaxAge < $age + $minFresh) {
                $response = $this->forceRequest($request);
                return $this->storeResponse($response, $request);
            }
        }

        /**
         * @link https://datatracker.ietf.org/doc/html/rfc7234#section-3
         *
         * A cache MUST NOT store a response to any request, unless:
         * ...
         *  - the "private" response directive (see Section 5.2.2.6) does not appear in the response,
         *    if the cache is shared, and
         *  - the Authorization header field (see Section 4.2 of [RFC7235]) does not appear in the request,
         *    if the cache is shared, unless the response explicitly allows it (see Section 3.2), and
         * ...
         */
        $isPrivate = ($cacheSettings['cache-control']['private'] ?? false) || $request->hasHeader('Authorization');
        $privateCacheKey = $this->getPrivateCacheKey($request);
        if ($isPrivate && is_null($privateCacheKey)) {
            return $this->forceRequest($request);
        }
        if (!$isPrivate) {
            $privateCacheKey = null;
        }

        $vary = $this->getVaryParts($request, $cacheSettings['vary']);

        /**
         * @link https://datatracker.ietf.org/doc/html/rfc7234#section-4.3
         */
        $isMustRevalidate = $cacheSettings['cache-control']['must-revalidate'] ?? false;
        $eTag = $cacheSettings['etag'] ?? null;
        $lastModified = $cacheSettings['last-modified'] ?? null;
        if ($isMustRevalidate) {
            $revalidateRequest = $request;
            if (is_string($eTag)) {
                $revalidateRequest = $revalidateRequest->withHeader('If-None-Match', [$eTag]);
            }
            if (is_int($lastModified)) {
                $ifModifiedSince = $this->createHeaderDate($lastModified);
                $revalidateRequest = $revalidateRequest->withHeader('If-Modified-Since', [$ifModifiedSince]);
            }
            $revalidateResponse = $this->forceRequest($revalidateRequest);
            if ($revalidateResponse->getStatusCode() !== 304) {
                return $this->storeResponse($revalidateResponse, $request);
            }

            $response = $this->getResponseFromCache($request->getUri(), $vary, $privateCacheKey);
            if (is_null($response)) {
                $response = $this->forceRequest($request);
                return $this->storeResponse($response, $request);
            }
        }

        $response = $this->getResponseFromCache($request->getUri(), $vary, $privateCacheKey);
        if (is_null($response)) {
            $response = $this->forceRequest($request);
            return $this->storeResponse($response, $request);
        }

        $dateTimestamp = $cacheSettings['date'] ?? null;
        if (!is_null($dateTimestamp)) {
            $currentTime = (new DateTimeImmutable())->setTimezone($this->gmt);
            $date = DateTimeImmutable::createFromFormat('U', (string)$dateTimestamp, $this->gmt);
            if ($date instanceof DateTimeImmutable) {
                $age = $currentTime->getTimestamp() - $date->setTimezone($this->gmt)->getTimestamp();
                if ($age > 0) {
                    $response = $response->withHeader('Age', [(string)$age]);
                }
            }
        }

        return $response;
    }

    private function getCacheSettings(UriInterface $uri): ?array
    {
        $cacheKey = $this->cacheKeyFactory->getSettingsKey($uri);
        $cached = $this->cache->get($cacheKey);
        if (is_null($cached)) {
            return null;
        }

        $decoded = (array)json_decode($cached, true);

        $noStore = (bool)($decoded['cache-control']['no-store'] ?? false);
        $mustRevalidate = (bool)($decoded['cache-control']['must-revalidate'] ?? false);
        $noCache = (bool)($decoded['cache-control']['no-cache'] ?? false);
        $public = (bool)($decoded['cache-control']['public'] ?? false);
        $private = (bool)($decoded['cache-control']['private'] ?? false);
        $maxAge = (int)($decoded['cache-control']['max-age'] ?? 0);
        $sMaxAge = (int)($decoded['cache-control']['s-maxage'] ?? 0);
        $date = (int)($decoded['date'] ?? 0);
        $expires = (int)($decoded['expires'] ?? 0);
        $lastModified = (int)($decoded['last-modified'] ?? 0);
        $eTag = trim((string)($decoded['etag'] ?? ''));
        $vary = (array)($decoded['vary'] ?? []);
        $preparedVary = [];
        foreach ($vary as $field) {
            if (!is_string($field)) {
                continue;
            }
            $field = strtolower($field);
            $preparedVary[$field] = $field;
        }

        return [
            'cache-control' => [
                'no-store' => $noStore,
                'must-revalidate' => $mustRevalidate,
                'no-cache' => $noCache,
                'public' => $public,
                'private' => $private,
                'max-age' => $maxAge,
                's-maxage' => $sMaxAge,
            ],
            'date' => $date === 0 ? null : $date,
            'expires' => $expires === 0 ? null : $expires,
            'last-modified' => $lastModified === 0 ? null : $lastModified,
            'etag' => $eTag === '' ? null : $eTag,
            'vary' => array_values($preparedVary),
        ];
    }

    private function getCacheSettingsFromHeaderFields(array $headerFields): array
    {
        $result = [
            'cache-control' => [
                'no-store' => false,
                'must-revalidate' => false,
                'no-cache' => false,
                'public' => false,
                'private' => false,
                'max-age' => null,
                's-maxage' => null,
            ],
            'date' => null,
            'expires' => null,
            'last-modified' => null,
            'etag' => null,
            'vary' => [],
        ];

        if (array_key_exists('cache-control', $headerFields)) {
            $directives = $this->parseCacheControlHeader(implode(',', $headerFields['cache-control']));
            foreach ($directives as $directive => $value) {
                if (!array_key_exists($directive, $result['cache-control'])) {
                    continue;
                }
                $result['cache-control'][$directive] = $value;
            }
        }

        if (array_key_exists('date', $headerFields)) {
            $result['date'] = $this->parseDateHeader($headerFields['date'][0]);
        }
        if (array_key_exists('expires', $headerFields)) {
            $result['expires'] = $this->parseDateHeader($headerFields['expires'][0]);
        }
        if (array_key_exists('last-modified', $headerFields)) {
            $result['last-modified'] = $this->parseDateHeader($headerFields['last-modified'][0]);
        }
        if (array_key_exists('etag', $headerFields)) {
            $result['etag'] = $headerFields['etag'][0];
        }
        if (array_key_exists('vary', $headerFields)) {
            $result['vary'] = array_map(
                'mb_strtolower',
                array_map('trim', $this->explodeHeader(implode(',', $headerFields['vary'])))
            );
        }
        return $result;
    }

    private function createHeaderDate(int $timestamp): string
    {
        return DateTimeImmutable::createFromFormat('U', (string)$timestamp)
            ->setTimezone($this->gmt)
            ->format(DATE_RFC7231)
            ;
    }

    private function isRequestForced(RequestInterface $request): bool
    {
        if (
            $request->hasHeader('If-None-Match')
            || $request->hasHeader('If-Match')
            || $request->hasHeader('If-Range')
            || $request->hasHeader('If-Modified-Since')
            || $request->hasHeader('If-Unmodified-Since')
        ) {
            return true;
        }
        return false;
    }

    private function explodeHeader(string $headerValue): array
    {
        $result = [];
        $len = mb_strlen($headerValue);
        $current = '';
        $inQuotes = false;
        for ($i = 0; $i < $len; $i++) {
            $symbol = mb_substr($headerValue, $i, 1);
            if ($symbol === ',' && !$inQuotes) {
                $result[] = $current;
                $current = '';
                continue;
            }
            if ($symbol === '"') {
                $inQuotes = !$inQuotes;
            }
            $current .= $symbol;
        }
        if ($current !== '') {
            $result[] = $current;
        }
        return $result;
    }

    private function storeResponse(ResponseInterface $response, RequestInterface $request): ResponseInterface
    {
        /**
         * @link https://datatracker.ietf.org/doc/html/rfc7234#section-2
         *
         * The most common form of cache entry is a successful result of a retrieval request: i.e.,
         * a 200 (OK) response to a GET request, which contains a representation of the resource
         * identified by the request target (Section 4.3.1 of [RFC7231]).
         * However, it is also possible to cache permanent redirects, negative results (e.g., 404 (Not Found)),
         * incomplete results (e.g., 206 (Partial Content)), and responses to methods other than GET if the method's
         * definition allows such caching and defines something suitable for use as a cache key.
         */
        $statusCode = $response->getStatusCode();
        if (!in_array($statusCode, [200, 301])) {
            return $response;
        }

        $requestCacheControl = $this->parseCacheControlHeader($request->getHeaderLine('Cache-Control'));


        $headerFields = [];
        foreach ($response->getHeaders() as $headerField => $values) {
            $headerFields[mb_strtolower($headerField)] = $values;
        }
        $cacheSettings = $this->getCacheSettingsFromHeaderFields($headerFields);

        /**
         * @link https://datatracker.ietf.org/doc/html/rfc7234#section-3
         *
         * A cache MUST NOT store a response to any request, unless:
         * ...
         * o  the "no-store" cache directive (see Section 5.2) does not appear
         * in request or response header fields, and
         * ...
         */
        if (($requestCacheControl['no-store'] ?? false) || ($cacheSettings['no-store'] ?? false)) {
            return $response;
        }

        /**
         * @link https://datatracker.ietf.org/doc/html/rfc7234#section-4.1
         *
         * A Vary header field-value of "*" always fails to match.
         */
        if (in_array('*', $cacheSettings['vary'])) {
            return $response;
        }

        $vary = $this->getVaryParts($request, $cacheSettings['vary']);

        $currentTime = (new DateTimeImmutable())->setTimezone($this->gmt);
        $maxAge = $this->normalizeDeltaSeconds($cacheSettings['max-age'] ?? $this->maxTtl);
        $headerExpires = null;
        if ($response->hasHeader('Expires')) {
            $headerExpires = DateTimeImmutable::createFromFormat(
                DATE_RFC7231,
                $response->getHeader('Expires')[0],
                $this->gmt
            );
        }
        if (!$headerExpires instanceof DateTimeImmutable) {
            $headerExpires = $currentTime->modify('+' . $maxAge . ' seconds');
        }

        $date = null;
        if ($response->hasHeader('Date')) {
            $date = DateTimeImmutable::createFromFormat(DATE_RFC7231, $response->getHeader('Date')[0], $this->gmt);
        }
        if (!$date instanceof DateTimeImmutable) {
            $date = (new DateTimeImmutable())->setTimezone($this->gmt);
        }

        $calculatedExpires = $date->modify('+' . $maxAge . ' seconds');
        $expires = $calculatedExpires < $headerExpires ? $calculatedExpires : $headerExpires;
        $deltaSeconds = $expires->getTimestamp() - $currentTime->getTimestamp();
        $cacheTtl = min($this->maxTtl, $this->normalizeDeltaSeconds($deltaSeconds));

        /**
         * @link https://datatracker.ietf.org/doc/html/rfc7234#section-3
         *
         * A cache MUST NOT store a response to any request, unless:
         * ...
         *  - the "private" response directive (see Section 5.2.2.6) does not appear in the response,
         *    if the cache is shared, and
         *  - the Authorization header field (see Section 4.2 of [RFC7235]) does not appear in the request,
         *    if the cache is shared, unless the response explicitly allows it (see Section 3.2), and
         * ...
         */
        $isPrivate = ($cacheSettings['cache-control']['private'] ?? false) || $request->hasHeader('Authorization');
        $privateCacheKey = $this->getPrivateCacheKey($request);
        if ($isPrivate && is_null($privateCacheKey)) {
            return $response;
        }
        if (!$isPrivate) {
            $privateCacheKey = null;
        }

        $settingsCacheKey = $this->cacheKeyFactory->getSettingsKey($request->getUri());
        $responseCacheKey = $this->cacheKeyFactory->getResponseKey($request->getUri(), $vary, $privateCacheKey);

        $eol = "\r\n";
        $responseRaw = sprintf(
            'HTTP/%s %d %s%s',
            $response->getProtocolVersion(),
            $response->getStatusCode(),
            $response->getReasonPhrase(),
            $eol
        );
        $header = [];
        foreach ($response->getHeaders() as $headerField => $values) {
            foreach ($values as $value) {
                $responseRaw .= sprintf('%s: %s%s', $headerField, $value, $eol);
                $header[mb_strtolower($headerField)][] = $value;
            }
        }
        $responseRaw .= $eol;

        $cacheItemSize = strlen($responseRaw);

        /**
         * Some clients do not support stream rereading.
         * Therefore, we save the read data into a new stream and attach it to the response
         */
        $body = $response->getBody();
        $resource = fopen('php://temp', 'w+');
        $overhead = false;
        while (!$body->eof()) {
            $part = $body->read(2048);
            fwrite($resource, $part);
            if ($overhead) {
                continue;
            }
            $cacheItemSize += strlen($part);
            if (is_null($this->maxCacheItemSize) || $this->maxCacheItemSize >= $cacheItemSize) {
                $responseRaw .= $part;
            } else {
                $overhead = true;
            }
        }
        rewind($resource);
        $response = $response->withBody($this->streamFactory->createStreamFromResource($resource));

        if (!$overhead) {
            $this->cache->set($responseCacheKey, $responseRaw, $cacheTtl);

            $settings = json_encode($this->getCacheSettingsFromHeaderFields($header));
            $this->cache->set($settingsCacheKey, $settings, $cacheTtl);
        }
        return $response;
    }

    private function normalizeDeltaSeconds(int $age): int
    {
        /**
         * @link https://datatracker.ietf.org/doc/html/rfc7234#section-1.2.1
         *
         * If a cache receives a delta-seconds value greater than the greatest
         * integer it can represent, or if any of its subsequent calculations
         * overflows, the cache MUST consider the value to be either 2147483648 (2^31)
         * or the greatest positive integer it can conveniently represent.
         */
        return min(max(0, $age), 2147483648);
    }

    private function parseCacheControlHeader(string $cacheControl): array
    {
        $intDirectives = ['max-age', 'max-stale', 'min-fresh', 's-maxage'];
        $result = [];
        $directives = array_map('trim', $this->explodeHeader($cacheControl));
        foreach ($directives as $directive) {
            if (strpos($directive, '=') === false) {
                $result[mb_strtolower($directive)] = true;
                continue;
            }
            [$directive, $value] = array_map('trim', explode('=', $directive, 2));
            $directive = mb_strtolower($directive);
            $result[$directive] = in_array($directive, $intDirectives) ? (int)$value : (string)$value;
        }
        return $result;
    }

    private function parseDateHeader(string $dateFromHeader): ?int
    {
        $date = DateTimeImmutable::createFromFormat(DATE_RFC7231, $dateFromHeader, $this->gmt);
        if ($date instanceof DateTimeImmutable) {
            return $date->getTimestamp();
        }
        return null;
    }

    private function getResponseFromCache(
        UriInterface $uri,
        array $vary,
        ?string $privateId
    ): ?ResponseInterface {
        $responseKey = $this->cacheKeyFactory->getResponseKey($uri, $vary, $privateId);
        $cached = $this->cache->get($responseKey);
        if (is_null($cached)) {
            return null;
        }

        $response = $this->responseFactory->createResponse();
        [$encodedHeader, $contents] = explode("\r\n\r\n", $cached, 2);
        foreach (explode("\r\n", $encodedHeader) as $line) {
            if (strpos($line, 'HTTP/') === 0) {
                [$protocolVersion, $statusCode, $reasonPhrase] = array_map(
                    'trim',
                    (array_replace(['', '', ''], explode(' ', $line)))
                );
                $response = $response->withStatus((int)$statusCode, $reasonPhrase);
                $response = $response->withProtocolVersion(substr($protocolVersion, 5));
                continue;
            }
            if (strpos($line, ':') === false) {
                continue;
            }
            [$headerField, $value] = array_map('trim', explode(':', $line));
            $response = $response->withAddedHeader($headerField, $value);
        }

        if ($contents === '') {
            return $response;
        }

        $stream = $this->streamFactory->createStream($contents);
        return $response->withBody($stream);
    }

    private function getVaryParts(RequestInterface $request, $varyHeaderFields): array
    {
        /**
         * @link https://datatracker.ietf.org/doc/html/rfc7234#section-4.1
         *
         * When a cache receives a request that can be satisfied by a stored response that has
         * a Vary header field (Section 7.1.4 of [RFC7231]), it MUST NOT use that response unless
         * all of the selecting header fields nominated by the Vary header field match in both
         * the original request (i.e., that associated with the stored response), and the presented request.
         *
         * The selecting header fields from two requests are defined to match if and only if those
         * in the first request can be transformed to those in the second request by applying any of the following:
         * - adding or removing whitespace, where allowed in the header field's syntax
         * - combining multiple header fields with the same field name (see Section 3.2 of [RFC7230])
         * - normalizing both header field values in a way that is known to have identical semantics,
         *   according to the header field's specification (e.g., reordering field values when order
         *   is not significant; case-normalization, where values are defined to be case-insensitive)
         *
         * If (after any normalization that might take place) a header field is absent from a request,
         * it can only match another request if it is also absent there.
         */
        $vary = [];
        foreach ($varyHeaderFields as $headerField) {
            $vary[$headerField] = $request->getHeaderLine($headerField);
        }
        ksort($vary);
        return $vary;
    }

    /**
     * @throws ClientExceptionInterface
     */
    private function forceRequest(RequestInterface $request): ResponseInterface
    {
        return $this->client->sendRequest($request->withoutHeader($this->privateCacheKeyRequestHeader));
    }

    private function getPrivateCacheKey(RequestInterface $request): ?string
    {
        if (!$request->hasHeader($this->privateCacheKeyRequestHeader)) {
            return null;
        }
        return $request->getHeaderLine($this->privateCacheKeyRequestHeader);
    }
}

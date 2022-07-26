[![Latest Stable Version](https://img.shields.io/packagist/v/webclient/ext-cache.svg?style=flat-square)](https://packagist.org/packages/webclient/ext-cache)
[![Total Downloads](https://img.shields.io/packagist/dt/webclient/ext-cache.svg?style=flat-square)](https://packagist.org/packages/webclient/ext-cache/stats)
[![License](https://img.shields.io/packagist/l/webclient/ext-cache.svg?style=flat-square)](https://github.com/phpwebclient/ext-cache/blob/master/LICENSE)
[![PHP](https://img.shields.io/packagist/php-v/webclient/ext-cache.svg?style=flat-square)](https://php.net)

# webclient/ext-cache

Cache extension for PSR-18 HTTP client. 

# Install

Install this package and your favorite [psr-18 implementation](https://packagist.org/providers/psr/http-client-implementation), [psr-17 implementation](https://packagist.org/providers/psr/http-factory-implementation) and [cache implementation](https://packagist.org/providers/webclient/cache-contract-implementation).

```bash
composer require webclient/ext-cache:^2.0
```

# Using

```php
<?php

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Webclient\Cache\Contract\CacheInterface;
use Webclient\Extension\Cache\Client;
use Webclient\Extension\Cache\CacheKeyFactory\CacheKeyFactoryInterface;

/** 
 * @var ClientInterface $client Your PSR-18 HTTP Client
 * @var ResponseFactoryInterface $responseFactory Your PSR-17 response factory
 * @var StreamFactoryInterface $streamFactory Your PSR-17 stream factory
 * @var CacheInterface $cache Your cache adapter
 * @var CacheKeyFactoryInterface|null $cacheKeyFactory key factory for your cache
 */
$http = new CacheClientDecorator(
    $client, 
    $responseFactory, 
    $streamFactory, 
    $cache,
    $cacheKeyFactory,
    'X-Private-Cache-Key-Header', // name of the header in which the private cache key is contained
    4096, // Maximal response size (with header). null for unlimited.
    2147483648 // maximal TTL of cache items
);

/** @var RequestInterface $request */
$response = $http->sendRequest($request);

/** @var RequestInterface $request */
$response = $http->sendRequest($request);

/** 
 * For using private cache add header `X-Private-Cache-Key-Header` (or your configured) to request.
 * header `X-Private-Cache-Key-Header` (or your configured) do not be sent to original http-client.
 */
$response = $http->sendRequest($request->withHeader('X-Private-Cache-Key-Header', ['private-key-for-current-user']));
 
```

## Not handled requests

If the request contains `If-None-Match`, `If-Match`, `If-Modified-Since`, `If-Unmodified-Since`, or `If-Range` headers,
then the response will be returned as is.

## Partial Requests

Partial requests not supports.
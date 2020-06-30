[![Latest Stable Version](https://img.shields.io/packagist/v/webclient/ext-cache.svg?style=flat-square)](https://packagist.org/packages/webclient/ext-cache)
[![Total Downloads](https://img.shields.io/packagist/dt/webclient/ext-cache.svg?style=flat-square)](https://packagist.org/packages/webclient/ext-cache/stats)
[![License](https://img.shields.io/packagist/l/webclient/ext-cache.svg?style=flat-square)](https://github.com/phpwebclient/ext-cache/blob/master/LICENSE)
[![PHP](https://img.shields.io/packagist/php-v/webclient/ext-cache.svg?style=flat-square)](https://php.net)

# webclient/ext-cache

Cache extension for PSR-18 HTTP client. 

# Install

Install this package and your favorite [psr-18 implementation](https://packagist.org/providers/psr/http-client-implementation), [psr-17 implementation](https://packagist.org/providers/psr/http-factory-implementation) and [psr-6 implementation](https://packagist.org/providers/psr/simple-cache-implementation).

```bash
composer require webclient/ext-cache:^1.0
```

# Using

```php
<?php

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\SimpleCache\CacheInterface;
use Webclient\Extension\Cache\Client;

/** 
 * @var ClientInterface $client Your PSR-18 HTTP Client
 * @var CacheInterface $cache Your PSR-6 Simple cache
 * @var ResponseFactoryInterface $responseFactory Your PSR-17 response factory
 * @var StreamFactoryInterface $streamFactory Your PSR-17 stream factory
 */
$http = new Client($client, $cache, $responseFactory, $streamFactory, 'unique-string-for-private-caching');

/** @var RequestInterface $request */
$response = $http->sendRequest($request);
```

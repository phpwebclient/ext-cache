<?php

declare(strict_types=1);

namespace Tests\Webclient\Extension\Cache;

use DateTimeImmutable;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Stuff\Webclient\Extension\Cache\CacheHandler;
use Stuff\Webclient\Extension\Cache\MemoryCache;
use Webclient\Cache\Contract\CacheInterface;
use Webclient\Extension\Cache\CacheKeyFactory\CacheKeyFactory;
use Webclient\Extension\Cache\CacheKeyFactory\CacheKeyFactoryInterface;
use Webclient\Extension\Cache\CacheClientDecorator;
use Webclient\Fake\FakeHttpClient;

class CacheClientDecoratorTest extends TestCase
{
    /**
     * @throws ClientExceptionInterface
     */
    public function testHttp1Dot0(): void
    {
        $factory = new Psr17Factory();
        $handler = new CacheHandler($factory);
        $client = $this->createClient($handler);

        $uri = sprintf('http://localhost?%s', http_build_query([
            'cache-control' => 'public,max-age=100',
        ]));
        $request = $factory->createRequest('GET', $uri)->withProtocolVersion('1.0');
        for ($i = 1; $i <= 5; $i++) {
            $client->sendRequest($request);
            self::assertSame($i, $handler->getCounter());
        }
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testNotGet(): void
    {
        $factory = new Psr17Factory();
        $handler = new CacheHandler($factory);
        $client = $this->createClient($handler);

        $uri = sprintf('http://localhost?%s', http_build_query([
            'cache-control' => 'public,max-age=100',
        ]));
        $request = $factory->createRequest('GET', $uri)->withProtocolVersion('1.1');
        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'] as $i => $method) {
            $client->sendRequest($request->withMethod($method));
            self::assertSame($i + 1, $handler->getCounter());
        }
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testNotOk(): void
    {
        $factory = new Psr17Factory();
        $handler = new CacheHandler($factory);
        $client = $this->createClient($handler);

        $errors = array_fill_keys(range(100, 599), true);
        unset($errors[200]);
        unset($errors[301]);
        foreach (array_keys($errors) as $error) {
            $handler->resetCounter();
            $uri = sprintf('http://localhost?%s', http_build_query([
                'cache-control' => 'public,max-age=100',
                'error' => $error,
            ]));
            $request = $factory->createRequest('GET', $uri)->withProtocolVersion('1.1');
            for ($i = 1; $i <= 3; $i++) {
                $client->sendRequest($request);
                self::assertSame($i, $handler->getCounter());
            }
        }
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testForcedPartialRequest(): void
    {
        $factory = new Psr17Factory();
        $handler = new CacheHandler($factory);
        $client = $this->createClient($handler);

        $uri = sprintf('http://localhost?%s', http_build_query([
            'cache-control' => 'public,max-age=100',
        ]));
        $request1 = $factory->createRequest('GET', $uri)
            ->withProtocolVersion('1.1')
            ->withHeader('Range', ['1'])
        ;
        for ($i = 1; $i <= 5; $i++) {
            $client->sendRequest($request1);
            self::assertSame($i, $handler->getCounter());
        }

        $handler->resetCounter();
        $request2 = $factory->createRequest('GET', $uri)
            ->withProtocolVersion('1.1')
            ->withHeader('Content-Range', ['1'])
        ;
        for ($i = 1; $i <= 5; $i++) {
            $client->sendRequest($request2);
            self::assertSame($i, $handler->getCounter());
        }
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testForcedCacheRequest(): void
    {
        $factory = new Psr17Factory();
        $handler = new CacheHandler($factory);
        $client = $this->createClient($handler);
        $lastModified = new DateTimeImmutable();

        $uri = sprintf('http://localhost?%s', http_build_query([
            'cache-control' => 'public,max-age=100',
            'etag' => 'xxx',
            'last-modified' => $lastModified->getTimestamp(),
        ]));
        $request = $factory->createRequest('GET', $uri)
            ->withProtocolVersion('1.1')
        ;

        $headers = [
            'If-None-Match' => ['xxx'],
            'If-Match' => ['xxx'],
            'If-Range' => ['xxx', $lastModified->format(DATE_RFC7231)],
            'If-Modified-Since' => [$lastModified->format(DATE_RFC7231)],
            'If-Unmodified-Since' => [$lastModified->format(DATE_RFC7231)],
        ];

        foreach ($headers as $header => $values) {
            foreach ($values as $value) {
                $handler->resetCounter();
                $request1 = $request->withHeader($header, [$value]);
                for ($i = 1; $i <= 5; $i++) {
                    $client->sendRequest($request1);
                    self::assertSame($i, $handler->getCounter());
                }
            }
        }

        $handler->resetCounter();
        $request2 = $factory->createRequest('GET', $uri)
            ->withProtocolVersion('1.1')
            ->withHeader('Content-Range', ['1'])
        ;
        for ($i = 1; $i <= 5; $i++) {
            $client->sendRequest($request2);
            self::assertSame($i, $handler->getCounter());
        }
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testSimplePublicCache(): void
    {
        $factory = new Psr17Factory();
        $handler = new CacheHandler($factory);
        $cache = new MemoryCache();
        $keyFactory = new CacheKeyFactory();
        $client = $this->createClient($handler, $cache, $keyFactory);

        $uri = sprintf('http://localhost?%s', http_build_query([
            'cache-control' => 'public,max-age=300',
        ]));
        $expectedBody = 'Page' . PHP_EOL . PHP_EOL . 'Hello, world!';
        $request = $factory->createRequest('GET', $uri)
            ->withProtocolVersion('1.1')
            ->withHeader('Accept', 'text/plain')
        ;

        for ($i = 1; $i <= 5; $i++) {
            $response = $client->sendRequest($request);
            self::assertSame(1, $handler->getCounter());
            self::assertSame($expectedBody, (string)$response->getBody());
        }

        $settingsCacheKey = $keyFactory->getSettingsKey($request->getUri());
        $settings = json_decode($cache->get($settingsCacheKey), true);
        $settings['date'] -= 500;
        $settings['expires'] -= 500;
        $cache->set($settingsCacheKey, json_encode($settings), 300);

        $handler->resetCounter();
        $response = $client->sendRequest($request);
        self::assertSame(1, $handler->getCounter());
        self::assertSame($expectedBody, (string)$response->getBody());
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testAuthCache(): void
    {
        $factory = new Psr17Factory();
        $handler = new CacheHandler($factory);
        $cache = new MemoryCache();
        $client = $this->createClient($handler, $cache, null, $factory, $factory, 'X-Private-Key');

        $users = [
            'user1' => 'password1',
            'user2' => 'password2',
            'user3' => 'password3',
            'user4' => 'password4',
        ];
        foreach ($users as $user => $password) {
            $handler->addUser($user, $password);
        }

        $uri = sprintf('http://localhost?%s', http_build_query([
            'cache-control' => 'private,max-age=300',
        ]));
        $request = $factory->createRequest('GET', $uri)
            ->withProtocolVersion('1.1')
            ->withHeader('Accept', 'text/plain')
        ;


        foreach ($users as $user => $password) {
            $handler->resetCounter();
            $authRequest = $request
                ->withHeader('X-Private-Key', ['case-' . $user])
                ->withHeader('Authorization', ['basic ' . base64_encode($user . ':' . $password)])
            ;
            $expectedAuthBody = 'Page' . PHP_EOL . PHP_EOL . 'Hello, ' . $user . '!';
            $response = $client->sendRequest($authRequest);
            self::assertSame(1, $handler->getCounter());
            self::assertSame($expectedAuthBody, (string)$response->getBody());
        }

        foreach ($users as $user => $password) {
            $handler->resetCounter();
            $authRequest = $request
                ->withHeader('X-Private-Key', ['case-' . $user])
                ->withHeader('Authorization', ['basic ' . base64_encode($user . ':' . $password)])
            ;
            $expectedAuthBody = 'Page' . PHP_EOL . PHP_EOL . 'Hello, ' . $user . '!';
            for ($i = 1; $i <= 5; $i++) {
                $response = $client->sendRequest($authRequest);
                self::assertSame(0, $handler->getCounter());
                self::assertSame($expectedAuthBody, (string)$response->getBody());
            }
        }
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testPrivateCache(): void
    {
        $factory = new Psr17Factory();
        $handler = new CacheHandler($factory);
        $cache = new MemoryCache();
        $client = $this->createClient($handler, $cache, null, $factory, $factory, 'X-Private-Key');

        $uri = sprintf('http://localhost?%s', http_build_query([
            'cache-control' => 'private,max-age=300',
        ]));
        $expectedBody = 'Page' . PHP_EOL . PHP_EOL . 'Hello, world!';
        $request = $factory->createRequest('GET', $uri)
            ->withProtocolVersion('1.1')
            ->withHeader('Accept', 'text/plain')
        ;

        for ($i = 1; $i <= 5; $i++) {
            $response = $client->sendRequest($request);
            self::assertSame($i, $handler->getCounter());
            self::assertSame($expectedBody, (string)$response->getBody());
        }
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testVaryAll(): void
    {
        $factory = new Psr17Factory();
        $handler = new CacheHandler($factory);
        $cache = new MemoryCache();
        $client = $this->createClient($handler, $cache);

        $uri = sprintf('http://localhost?%s', http_build_query([
            'cache-control' => 'public,max-age=2',
            'vary' => '*',
        ]));
        $request = $factory->createRequest('GET', $uri)
            ->withProtocolVersion('1.1')
        ;

        for ($i = 1; $i <= 5; $i++) {
            $client->sendRequest($request);
            self::assertSame($i, $handler->getCounter());
        }
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testVaryHandling(): void
    {
        $factory = new Psr17Factory();
        $handler = new CacheHandler($factory);
        $cache = new MemoryCache();
        $client = $this->createClient($handler, $cache);

        $uri = sprintf('http://localhost?%s', http_build_query([
            'cache-control' => 'public,max-age=2',
            'vary' => 'accept-language',
        ]));
        $baseRequest = $factory->createRequest('GET', $uri)
            ->withProtocolVersion('1.1')
        ;

        $bodies = [
            'text/plain' => [
                'en' => 'Page' . PHP_EOL . PHP_EOL . 'Hello, world!',
                'ru' => 'Страница' . PHP_EOL . PHP_EOL . 'Привет, мир!',
                'fr' => 'Page' . PHP_EOL . PHP_EOL . 'Bonjour le monde!',
                'de' => 'Buchseite' . PHP_EOL . PHP_EOL . 'Hallo Welt!',
            ],
            'text/html' => [
                'en' => '<!DOCTYPE html><html lang="en"><head><title>Page</title>'
                    . '</head><body><h1>Page</h1><p>Hello, world!</p></body></html>',
                'ru' => '<!DOCTYPE html><html lang="ru"><head><title>Страница</title>'
                    . '</head><body><h1>Страница</h1><p>Привет, мир!</p></body></html>',
                'fr' => '<!DOCTYPE html><html lang="fr"><head><title>Page</title>'
                    . '</head><body><h1>Page</h1><p>Bonjour le monde!</p></body></html>',
                'de' => '<!DOCTYPE html><html lang="de"><head><title>Buchseite</title>'
                    . '</head><body><h1>Buchseite</h1><p>Hallo Welt!</p></body></html>',
            ],
            'application/xml' => [
                'en' => '<page title="Page" lang="en"><text>Hello, world!</text></page>',
                'ru' => '<page title="Страница" lang="ru"><text>Привет, мир!</text></page>',
                'fr' => '<page title="Page" lang="fr"><text>Bonjour le monde!</text></page>',
                'de' => '<page title="Buchseite" lang="de"><text>Hallo Welt!</text></page>',
            ],
            'application/json' => [
                'en' => json_encode(['title' => 'Page', 'text' => 'Hello, world!', 'lang' => 'en']),
                'ru' => json_encode(['title' => 'Страница', 'text' => 'Привет, мир!', 'lang' => 'ru']),
                'fr' => json_encode(['title' => 'Page', 'text' => 'Bonjour le monde!', 'lang' => 'fr']),
                'de' => json_encode(['title' => 'Buchseite', 'text' => 'Hallo Welt!', 'lang' => 'de']),
            ],
        ];
        foreach (['text/plain', 'text/html', 'application/json', 'application/xml'] as $contentType) {
            foreach (['en', 'ru', 'fr', 'de'] as $lang) {
                $handler->resetCounter();
                $request = $baseRequest
                    ->withHeader('Accept-Language', [$lang])
                    ->withHeader('Accept', [$contentType])
                ;
                for ($i = 1; $i <= 5; $i++) {
                    $response = $client->sendRequest($request);
                    self::assertSame($bodies[$contentType][$lang], (string)$response->getBody());
                    self::assertSame(1, $handler->getCounter());
                }
            }
        }
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testRevalidateCacheByLastModified(): void
    {
        $factory = new Psr17Factory();
        $handler = new CacheHandler($factory);
        $cache = new MemoryCache();
        $keyFactory = new CacheKeyFactory();
        $client = $this->createClient($handler, $cache, $keyFactory);

        $uri = sprintf('http://localhost?%s', http_build_query([
            'cache-control' => 'must-revalidate',
        ]));
        $expectedBody = 'Page' . PHP_EOL . PHP_EOL . 'Hello, world!';
        $request = $factory->createRequest('GET', $uri)
            ->withProtocolVersion('1.1')
            ->withHeader('Accept', 'text/plain')
        ;

        $now = time();
        $handler->setLastModified($now - 10);
        $response = $client->sendRequest($request);
        self::assertSame(1, $handler->getCounter()); // one request without cache
        self::assertSame($expectedBody, (string)$response->getBody());

        $handler->resetCounter();
        $response = $client->sendRequest($request);
        self::assertSame(1, $handler->getCounter()); // one request with 304 code and cached data
        self::assertSame($expectedBody, (string)$response->getBody());

        $handler->resetCounter();
        $handler->setLastModified($now - 8);
        $response = $client->sendRequest($request);
        self::assertSame(1, $handler->getCounter()); // one request with code 200 and new data
        self::assertSame($expectedBody, (string)$response->getBody());

        $handler->resetCounter();
        $settingsCacheKey = $keyFactory->getSettingsKey($request->getUri());
        $settings = $cache->get($settingsCacheKey);
        $cache->clear();
        $cache->set($settingsCacheKey, $settings, 100);
        $response = $client->sendRequest($request);
        // one request with code 304 and get new data (because response cache clean)
        self::assertSame(2, $handler->getCounter());
        self::assertSame($expectedBody, (string)$response->getBody());
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testRevalidateCacheByETag(): void
    {
        $factory = new Psr17Factory();
        $handler = new CacheHandler($factory);
        $cache = new MemoryCache();
        $keyFactory = new CacheKeyFactory();
        $client = $this->createClient($handler, $cache, $keyFactory);

        $uri = sprintf('http://localhost?%s', http_build_query([
            'cache-control' => 'must-revalidate',
        ]));
        $expectedBody = 'Page' . PHP_EOL . PHP_EOL . 'Hello, world!';
        $request = $factory->createRequest('GET', $uri)
            ->withProtocolVersion('1.1')
            ->withHeader('Accept', 'text/plain')
        ;

        $handler->setETag('xxx');
        $response = $client->sendRequest($request);
        self::assertSame(1, $handler->getCounter()); // one request without cache
        self::assertSame($expectedBody, (string)$response->getBody());

        $handler->resetCounter();
        $response = $client->sendRequest($request);
        self::assertSame(1, $handler->getCounter()); // one request with 304 code and cached data
        self::assertSame($expectedBody, (string)$response->getBody());

        $handler->resetCounter();
        $handler->setETag('yyy');
        $response = $client->sendRequest($request);
        self::assertSame(1, $handler->getCounter()); // one request with code 200 and new data
        self::assertSame($expectedBody, (string)$response->getBody());

        $handler->resetCounter();
        $settingsCacheKey = $keyFactory->getSettingsKey($request->getUri());
        $settings = $cache->get($settingsCacheKey);
        $cache->clear();
        $cache->set($settingsCacheKey, $settings, 100);
        $response = $client->sendRequest($request);
        // one request with code 304 and get new data (because response cache clean)
        self::assertSame(2, $handler->getCounter());
        self::assertSame($expectedBody, (string)$response->getBody());
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testBigResponse()
    {
        $factory = new Psr17Factory();
        $handler = new CacheHandler($factory);
        $cache = new MemoryCache();
        $client = $this->createClient($handler, $cache, null, null, null, 'X-Private-Key', 260);

        $handler->addUser('user', 'password');
        $handler->addUser('very-long-user-name', '1');
        $uri = sprintf('http://localhost?%s', http_build_query([
            'cache-control' => 'private,max-age=300',
        ]));
        $expectedBody = 'Page' . PHP_EOL . PHP_EOL . 'Hello, user!';

        // response len = 251
        $request = $factory->createRequest('GET', $uri)
            ->withProtocolVersion('1.1')
            ->withHeader('Accept', 'text/plain')
            ->withHeader('Authorization', ['basic ' . base64_encode('user:password')])
            ->withHeader('X-Private-Key', 'test-big-response-user')
        ;

        for ($i = 1; $i <= 5; $i++) {
            $response = $client->sendRequest($request);
            self::assertSame(1, $handler->getCounter());
            self::assertSame($expectedBody, (string)$response->getBody());
        }

        $handler->resetCounter();
        $expectedBody = 'Page' . PHP_EOL . PHP_EOL . 'Hello, very-long-user-name!';
        // response len = 266
        $request = $factory->createRequest('GET', $uri)
            ->withProtocolVersion('1.1')
            ->withHeader('Accept', 'text/plain')
            ->withHeader('Authorization', ['basic ' . base64_encode('very-long-user-name:1')])
            ->withHeader('X-Private-Key', 'test-big-response-very-long-user-name')
        ;

        for ($i = 1; $i <= 5; $i++) {
            $response = $client->sendRequest($request);
            self::assertSame($i, $handler->getCounter());
            self::assertSame($expectedBody, (string)$response->getBody());
        }
    }

    private function createClient(
        ?RequestHandlerInterface $handler = null,
        ?CacheInterface $cache = null,
        ?CacheKeyFactoryInterface $keyFactory = null,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?string $privateKey = null,
        ?int $maxCacheItemSize = null
    ): ClientInterface {
        $factory = new Psr17Factory();
        $responseFactory = $responseFactory ?? $factory;
        $streamFactory = $streamFactory ?? $factory;
        $cache = $cache ?? new MemoryCache();
        $keyFactory = $keyFactory ?? new CacheKeyFactory();
        $client = new FakeHttpClient($handler ?? new CacheHandler($responseFactory));
        return new CacheClientDecorator(
            $client,
            $responseFactory,
            $streamFactory,
            $cache,
            $keyFactory,
            $privateKey,
            $maxCacheItemSize,
            86400
        );
    }
}

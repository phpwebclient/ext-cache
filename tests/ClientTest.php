<?php

declare(strict_types=1);

namespace Tests\Webclient\Extension\Cache;

use Cache\Adapter\PHPArray\ArrayCachePool;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Stuff\Webclient\Extension\Cache\Handler;
use Webclient\Extension\Cache\Client;
use Webclient\Fake\Client as FakeClient;

class ClientTest extends TestCase
{

    /**
     * @throws ClientExceptionInterface
     */
    public function testClient()
    {
        $items = [];
        $factory = new Psr17Factory();
        $cache = new ArrayCachePool(null, $items);
        $client = new Client(
            new FakeClient(new Handler($factory, $factory)),
            $cache,
            $factory,
            $factory,
            'private'
        );
        $request = new Request('GET', 'http://localhost?etag=ok', ['User-Agent' => 'webclient/1.0']);
        $response = $client->sendRequest($request);
        $this->assertSame(200, $response->getStatusCode());
        $this->fail('Help me cover this with tests, please');
    }
}

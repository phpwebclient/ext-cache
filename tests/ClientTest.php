<?php

declare(strict_types=1);

namespace Tests\Webclient\Extension\Cache;

use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Stuff\Webclient\Extension\Cache\ArrayCache;
use Stuff\Webclient\Extension\Cache\Handler;
use Stuff\Webclient\Extension\Cache\HttpFactory;
use Webclient\Extension\Cache\Client;
use Webclient\Fake\Client as FakeClient;

class ClientTest extends TestCase
{

    /**
     * @throws ClientExceptionInterface
     */
    public function testClient()
    {
        $factory = new HttpFactory();
        $cache = new ArrayCache();
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
    }
}

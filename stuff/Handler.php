<?php

declare(strict_types=1);

namespace Stuff\Webclient\Extension\Cache;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

class Handler implements RequestHandlerInterface
{

    /**
     * @var ResponseFactoryInterface
     */
    private $responseFactory;

    /**
     * @var StreamFactoryInterface
     */
    private $streamFactory;

    public function __construct(ResponseFactoryInterface $responseFactory, StreamFactoryInterface $streamFactory)
    {
        $this->responseFactory = $responseFactory;
        $this->streamFactory = $streamFactory;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = [];
        if ($request->hasHeader('User-Agent')) {
            $body['user-agent'] = $request->getHeaderLine('User-Agent');
        }
        $query = $request->getQueryParams();
        $cacheControl = [];
        $etag = array_key_exists('etag', $query) ? $query['etag'] : null;

        $stream = $this->streamFactory->createStream(json_encode($body));
        $ifNoneMatch = $request->hasHeader('If-None-Match') ? (string)$request->getHeaderLine('If-None-Match') : null;
        if (!is_null($ifNoneMatch) && $etag === $ifNoneMatch) {
            return $this->responseFactory
                ->createResponse(304)
                ->withHeader('ETag', $etag)
                ->withHeader('Content-Type', ['application/json'])
                ->withHeader('Cache-Control', ['max-age=100'])
                ->withHeader('Vary', ['User-Agent'])
                ->withBody($stream)
            ;
        }
        $response = $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', ['application/json'])
            ->withHeader('Vary', ['User-Agent'])
        ;
        if (!is_null($etag)) {
            $response = $response->withHeader('ETag', $etag);
            $cacheControl['max-age'] = 'max-age=100';
        }
        if ($cacheControl) {
            $response = $response->withHeader('Cache-Control', [implode(';', $cacheControl)]);
        }
        return $response->withBody($stream);
    }
}

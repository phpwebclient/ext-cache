<?php

declare(strict_types=1);

namespace Stuff\Webclient\Extension\Cache;

use DateTimeImmutable;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CacheHandler implements RequestHandlerInterface
{
    private ResponseFactoryInterface $responseFactory;
    private int $counter = 0;
    private array $users = [];
    private ?int $lastModified = null;
    private ?string $eTag = null;

    public function __construct(ResponseFactoryInterface $responseFactory)
    {
        $this->responseFactory = $responseFactory;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = null;
        if ($request->hasHeader('Authorization')) {
            $auth = $request->getHeader('Authorization')[0];
            $encoded = explode('basic ', $auth)[1];
            $decoded = base64_decode(trim($encoded));
            [$user, $password] = explode(':', $decoded, 2);
            if (!array_key_exists($user, $this->users) || $password !== $this->users[$user]) {
                $this->counter++;
                return $this->responseFactory->createResponse(401);
            }
        }
        $query = $request->getQueryParams();
        $error = (int)($query['error'] ?? 0);
        if ($error !== 0) {
            $response = $this->responseFactory->createResponse($error);
            $response->getBody()->write('Error ' . $error . '!');
            $this->counter++;
            return $response;
        }
        $response = $this->responseFactory
            ->createResponse(200)
        ;
        $response = $this->attachCacheControlHeaderField($response, $query['cache-control'] ?? '');
        $response = $this->attachVaryHeaderField($response, $query['vary'] ?? '');
        if (!is_null($this->eTag)) {
            $response = $response->withHeader('ETag', [$this->eTag]);
        }
        $response = $this->attachHeaderFieldFromQuery($response, 'ETag', $query);

        $date = $query['date'] ?? (string)time();
        if (!is_null($date)) {
            $time = DateTimeImmutable::createFromFormat('U', $date);
            if (!$time instanceof DateTimeImmutable) {
                $date = null;
            } else {
                $response = $response->withHeader('Date', $time->format(DATE_RFC7231));
            }
        }

        $ifNoneMatch = $request->getHeaderLine('If-None-Match');
        if ($ifNoneMatch !== '' && $this->eTag === $ifNoneMatch) {
            $this->counter++;
            return $response->withStatus(304);
        }

        $lastModified = null;
        if (!is_null($this->lastModified)) {
            $time = DateTimeImmutable::createFromFormat('U', (string)$this->lastModified);
            if ($time instanceof DateTimeImmutable) {
                $response = $response->withHeader('Last-Modified', $time->format(DATE_RFC7231));
                $lastModified = $time->getTimestamp();
            }
        }

        $ifModifiedSince = $request->getHeaderLine('If-Modified-Since');
        if ($ifModifiedSince !== '' && strtotime($ifModifiedSince) >= $lastModified) {
            $this->counter++;
            return $response->withStatus(304);
        }

        $response = $this->attachBody($response, $request, $user);
        $this->counter++;
        return $response->withHeader('Connection', ['close']);
    }

    public function getCounter(): int
    {
        return $this->counter;
    }

    public function resetCounter(): void
    {
        $this->counter = 0;
    }

    public function addUser(string $login, string $password): void
    {
        $this->users[$login] = $password;
    }

    public function resetUsers(): void
    {
        $this->users = [];
    }

    public function setLastModified(int $lastModified): void
    {
        $this->lastModified = $lastModified;
    }

    public function resetLastModified(): void
    {
        $this->lastModified = null;
    }

    public function setETag(string $eTag): void
    {
        $this->eTag = $eTag;
    }

    public function resetETag(): void
    {
        $this->eTag = null;
    }

    private function attachBody(
        ResponseInterface $response,
        ServerRequestInterface $request,
        ?string $user = null
    ): ResponseInterface {
        $lang = $this->defineLanguage($request);
        $body = $response->getBody();
        $type = $this->detectContentType($request->getHeaderLine('Accept'));
        $response = $response->withHeader('Content-Type', [$type]);

        $translate = [
            'en' => [
                'title' => 'Page',
                'text' => 'Hello, {name}!',
                'world' => 'world',
            ],
            'ru' => [
                'title' => 'Страница',
                'text' => 'Привет, {name}!',
                'world' => 'мир',
            ],
            'fr' => [
                'title' => 'Page',
                'text' => 'Bonjour le {name}!',
                'world' => 'monde',
            ],
            'de' => [
                'title' => 'Buchseite',
                'text' => 'Hallo {name}!',
                'world' => 'Welt',
            ],
        ];
        $title = $translate[$lang]['title'];
        $text = $translate[$lang]['text'];
        $text = str_replace('{name}', $user ?? $translate[$lang]['world'], $text);

        switch ($type) {
            case 'application/json':
                $contents = json_encode(['title' => $title, 'text' => $text, 'lang' => $lang]);
                break;
            case 'text/plain':
                $contents = $title . PHP_EOL . PHP_EOL . $text;
                break;
            case 'application/xml':
                $contents = sprintf('<page title="%s" lang="%s"><text>%s</text></page>', $title, $lang, $text);
                break;
            default:
                $contents = sprintf(
                    '<!DOCTYPE html><html lang="%s">%s</html>',
                    $lang,
                    sprintf(
                        '<head><title>%s</title></head><body><h1>%s</h1><p>%s</p></body>',
                        $title,
                        $title,
                        $text
                    )
                );
                $response = $response->withHeader('Content-Type', ['text/html']);
                break;
        }
        if ($request->getMethod() !== 'HEAD') {
            $body->rewind();
            $body->write($contents);
            $body->rewind();
        }
        return $response
            ->withHeader('Content-Length', [(string)strlen($contents)])
            ->withHeader('Content-Language', [$lang])
            ;
    }

    private function detectContentType(string $accept): string
    {
        $variants = array_map('trim', explode(',', $accept));
        $find = [
            'text/html' => false,
            'application/json' => false,
            'application/xml' => false,
            'text/plain' => false,
        ];
        foreach ($variants as $variant) {
            if (strpos($variant, '*/*') === 0) {
                $find['text/html'] = true;
                $find['text/plain'] = true;
                $find['application/json'] = true;
                $find['application/xml'] = true;
            }
            if (strpos($variant, 'text/html') === 0) {
                $find['text/html'] = true;
            }
            if (strpos($variant, 'application/json') === 0) {
                $find['application/json'] = true;
            }
            if (strpos($variant, 'text/plain') === 0) {
                $find['text/plain'] = true;
            }
            if (strpos($variant, 'application/xml') === 0) {
                $find['application/xml'] = true;
            }
        }
        foreach ($find as $type => $has) {
            if ($has) {
                return $type;
            }
        }
        return 'text/html';
    }

    private function attachHeaderFieldFromQuery(
        ResponseInterface $response,
        string $headerField,
        array $query
    ): ResponseInterface {
        $key = mb_strtolower($headerField);
        if (!array_key_exists($key, $query)) {
            return $response;
        }
        if (!is_string($query[$key]) && !is_array($query[$key])) {
            return $response;
        }
        $values = is_array($query[$key]) ? $query[$key] : [$query[$key]];

        return $response->withHeader($headerField, $values);
    }

    private function attachCacheControlHeaderField(
        ResponseInterface $response,
        string $value
    ): ResponseInterface {
        if (trim($value) === '') {
            return $response;
        }
        $response = $this->attachHeaderFieldFromQuery($response, 'Cache-Control', ['cache-control' => $value]);
        $directives = $this->parseCacheControlHeader($value);

        $maxAge = $directives['max-age'] ?? 0;
        if ($maxAge > 0) {
            $expires = (new DateTimeImmutable())->modify('+ ' . $maxAge . ' seconds');
            $response = $response
                ->withHeader('Expires', [$expires->format(DATE_RFC7231)])
            ;
        }
        return $response;
    }

    private function attachVaryHeaderField(
        ResponseInterface $response,
        string $value
    ): ResponseInterface {
        $value = mb_strtolower(trim($value));
        $value = $value === '' ? 'accept' : $value . ', accept';

        $vary = array_filter(array_unique(array_map('trim', explode(',', $value))));
        return $this->attachHeaderFieldFromQuery($response, 'Vary', ['vary' => implode(', ', $vary)]);
    }

    private function parseCacheControlHeader(string $cacheControl): array
    {
        $intDirectives = ['max-age', 'max-stale', 'min-fresh', 's-maxage'];
        $stringDirectives = [
            'no-cache' => '*',
        ];
        $result = [];
        $directives = array_map('trim', $this->explodeHeader($cacheControl));
        foreach ($directives as $directive) {
            if (strpos($directive, '=') === false) {
                $result[mb_strtolower($directive)] = $stringDirectives[$directive] ?? true;
                continue;
            }
            [$directive, $value] = array_map('trim', explode('=', $directive, 2));
            $directive = mb_strtolower($directive);
            $result[$directive] = in_array($directive, $intDirectives) ? (int)$value : (string)$value;
        }
        return $result;
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

    private function defineLanguage(ServerRequestInterface $request): string
    {
        if (!$request->hasHeader('Accept-Language')) {
            return 'en';
        }

        $languages = [];
        $values = array_map('trim', explode(',', $request->getHeaderLine('Accept-Language')));
        foreach ($values as $value) {
            $q = null;
            $arr = array_map('trim', explode(';', $value));
            $code = array_shift($arr);
            foreach ($arr as $item) {
                if (!is_null($q)) {
                    continue;
                }
                if (strpos($item, 'q=') === 0) {
                    $q = (int)((float)substr($item, 2) * 10);
                }
            }
            if (is_null($q)) {
                $q = 10;
            }
            if (!array_key_exists($q, $languages)) {
                $languages[$q] = $code;
            }

            // fr-fr,en-us;q=0.7,en;q=0.3
        }

        if (empty($languages)) {
            return 'en';
        }
        ksort($languages);
        $code = array_shift($languages);
        $lang = strtolower(explode('-', $code)[0]);
        if (!in_array($lang, ['en', 'ru', 'fr', 'de'])) {
            return 'en';
        }
        return $lang;
    }
}

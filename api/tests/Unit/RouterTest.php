<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Unit;

use Muninn\Api\Http\HttpException;
use Muninn\Api\Http\Response;
use Muninn\Api\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testMatchesPlaceholdersAndReportsAllowedMethods(): void
    {
        $router = new Router();
        $router->add('DELETE', '/api/v1/items/{id}', fn (): Response => Response::noContent(), Router::ACCESS_USER);

        $matchedRoute = $router->match('DELETE', '/api/v1/items/abc-123');
        self::assertSame(['id' => 'abc-123'], $matchedRoute['parameters']);
        self::assertSame(Router::ACCESS_USER, $matchedRoute['access']);

        try {
            $router->match('GET', '/api/v1/items/abc-123');
            self::fail('Expected 405.');
        } catch (HttpException $methodException) {
            self::assertSame(405, $methodException->statusCode);
            self::assertSame('DELETE', $methodException->extraHeaders['Allow']);
        }
    }

    public function testUnknownPathIs404AndPlaceholdersDoNotCrossSegments(): void
    {
        $router = new Router();
        $router->add('GET', '/api/v1/items/{id}', fn (): Response => Response::noContent(), Router::ACCESS_USER);

        $this->expectExceptionObject(HttpException::notFound());
        $router->match('GET', '/api/v1/items/a/b');
    }
}

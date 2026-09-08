<?php

declare(strict_types=1);

namespace Proudnerds\ABTestPages\Tests\Unit\Middleware;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Records the request it was called with, so a test can assert what
 * AbTestPageResolver passed down the middleware stack.
 */
final class CapturingRequestHandler implements RequestHandlerInterface
{
    public ?ServerRequestInterface $lastRequest = null;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->lastRequest = $request;
        return new Response(200);
    }
}

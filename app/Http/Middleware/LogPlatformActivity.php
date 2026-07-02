<?php

namespace App\Http\Middleware;

use App\Services\ActivityLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogPlatformActivity
{
    public function __construct(
        private readonly ActivityLogger $logger,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $this->logger->logRequest($request, $response);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}

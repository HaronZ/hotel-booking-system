<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForceJsonResponse
{
    /**
     * Force every /api/* request to be treated as expecting JSON, regardless
     * of the client's Accept header. Without this, Laravel's auth middleware
     * and exception handler fall back to HTML redirects (e.g. to a "login"
     * route that doesn't exist in this API-only app) for plain HTTP clients
     * like curl or Postman that don't send Accept: application/json.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}

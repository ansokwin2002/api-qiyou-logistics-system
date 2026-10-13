<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records important mutating operations with user, time, IP and payload.
 * Read-only requests and authentication endpoints are skipped.
 */
class RecordActivity
{
    private const SKIP_PREFIXES = [
        'auth/login',
        'auth/admin-login',
        'auth/register',
        'delivery/login',
        'delivery/register',
        'delivery/location',
        'i18n/',
        'account/auth',
    ];

    private const SKIP_EXACT = ['upload'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                return $response;
            }

            $path = trim($request->path(), '/');
            if (in_array($path, self::SKIP_EXACT, true)) {
                return $response;
            }
            foreach (self::SKIP_PREFIXES as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    return $response;
                }
            }

            if ($response->getStatusCode() >= 400) {
                return $response;
            }

            ActivityLog::record(
                $this->deriveAction($request),
                $this->describe($request),
                [
                    'method' => $request->method(),
                    'path' => '/'.$path,
                    'ip_address' => $request->ip(),
                    'status_code' => $response->getStatusCode(),
                    'payload' => $this->payload($request),
                ]
            );
        } catch (\Throwable $e) {
            // Activity logging must never break the request.
        }

        return $response;
    }

    private function deriveAction(Request $request): string
    {
        $path = trim($request->path(), '/');
        $segments = explode('/', $path);

        // manageapi/<module>/<verb>  |  v1/<module>/<verb>
        $module = 'app';
        foreach ($segments as $segment) {
            if (in_array($segment, ['v1', 'manageapi', 'api'], true)) {
                continue;
            }
            $module = $segment;
            break;
        }

        $verb = strtolower($request->method());

        return substr($module.'.'.$verb, 0, 50);
    }

    private function describe(Request $request): ?string
    {
        $body = $this->payload($request);

        foreach (['id', 'order_no', 'barcode', 'package_no', 'key', 'name', 'email'] as $key) {
            if (! empty($body[$key]) && is_scalar($body[$key])) {
                return strtoupper($request->method()).' '.$request->path().' ('.$key.'='.$body[$key].')';
            }
        }

        return strtoupper($request->method()).' '.$request->path();
    }

    private function payload(Request $request): array
    {
        $data = $request->input('data', []);
        $source = is_array($data) && $data ? $data : $request->except(['password', 'password_confirmation', 'token', 'access_token']);

        $clean = [];
        foreach ($source as $key => $value) {
            if (is_scalar($value) || is_null($value)) {
                $clean[$key] = $value;
            } elseif (is_array($value)) {
                $clean[$key] = '[array]';
            }
        }

        return $clean;
    }
}

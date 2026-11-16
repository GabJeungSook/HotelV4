<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a short id (TRD §8): it goes into every log line of the request through
 * the log context, back to the browser as `X-Request-Id`, and onto the "Something went wrong.
 * Nothing was saved." message, so a front desk can read it out and the log line can be found.
 */
final class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $id = self::make();
        $request->attributes->set('request_id', $id);
        Context::add('request_id', $id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    /** The id of the current request, or a new one outside a request. */
    public static function current(?Request $request = null): string
    {
        $id = ($request ?? request())->attributes->get('request_id');

        return is_string($id) ? $id : self::make();
    }

    /** Eight characters people can read out over the phone: no 0/O or 1/I. */
    private static function make(): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $id = '';
        for ($i = 0; $i < 8; $i++) {
            $id .= $alphabet[random_int(0, 31)];
        }

        return Str::substr($id, 0, 4).'-'.Str::substr($id, 4);
    }
}

<?php

declare(strict_types=1);

namespace App\Evaluation;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class GatewayBoundary
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            // Rechecked even with cached routes or a changed environment.
            abort_unless(GatewayAccess::enabled(), 404);
            abort_unless(in_array(trim($request->getHost(), '[]'), ['localhost', '127.0.0.1', '::1'], true)
                && in_array($request->ip(), ['127.0.0.1', '::1'], true), 404);
            abort_if(count($request->query()) !== 0, 422, 'Query parameter tidak didukung.');
            abort_if(strlen($request->getContent()) > 16384, 413, 'Payload terlalu besar.');
            if ($request->isMethod('POST')) {
                abort_unless($request->isJson(), 415, 'Gunakan JSON object.');
                try { $body = json_decode($request->getContent(), false, 12, JSON_THROW_ON_ERROR); }
                catch (\JsonException) { abort(422, 'JSON tidak valid atau terlalu dalam.'); }
                abort_unless(is_object($body), 422, 'Gunakan JSON object.');
            }
            $response = $next($request);
        } catch (ValidationException $error) {
            $response = response()->json(['message' => 'Input tidak valid.', 'errors' => $error->errors()], 422);
        } catch (HttpExceptionInterface $error) {
            $response = response()->json(['message' => $error->getMessage() ?: 'Permintaan ditolak.'], $error->getStatusCode(), $error->getHeaders());
        } catch (Throwable $error) {
            report($error);
            $response = response()->json(['message' => 'Kesalahan internal; jangan mengganti request_key saat mencoba ulang.'], 500);
        }
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-NinjaLost-Protocol', 'evaluation-v1');
        return $response;
    }
}

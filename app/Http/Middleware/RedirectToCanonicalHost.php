<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Уводит все запросы на один адрес сайта — тот, что указан в APP_URL.
 *
 * Без этого домен с www и без него живут как два разных сайта: кука сессии привязана к хосту,
 * а Sanctum считает «своим» только адрес из APP_URL. Пользователь, попавший на www, входит
 * и тут же оказывается неавторизованным, потому что сессия там попросту не стартует.
 */
class RedirectToCanonicalHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $canonical = parse_url((string) config('app.url'), PHP_URL_HOST);

        if ($canonical === null || $request->getHost() === $canonical) {
            return $next($request);
        }

        // Перенаправляем только зеркала основного домена: локальные адреса и произвольные
        // хосты трогать незачем, иначе сломается разработка и доступ по временной ссылке.
        if ($request->getHost() !== "www.{$canonical}") {
            return $next($request);
        }

        return redirect()->away(
            $request->getScheme().'://'.$canonical.$request->getRequestUri(),
            301,
        );
    }
}

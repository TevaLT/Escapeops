<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Démarre la session PHP avec des cookies durcis :
 * HttpOnly (illisible en JavaScript), SameSite=Lax, Secure sous HTTPS.
 */
final class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(private string $name = 'escapeops_session')
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            session_name($this->name);
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => $request->getUri()->getScheme() === 'https',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }

        return $handler->handle($request);
    }
}

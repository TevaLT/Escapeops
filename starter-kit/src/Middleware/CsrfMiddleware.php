<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Http\ViewRenderer;
use App\Security\Csrf;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * US-12 : toute requête HTML qui modifie des données (POST, PUT, PATCH, DELETE)
 * doit porter le jeton CSRF de la session, sinon 403.
 *
 * L'API (/api/...) n'est pas concernée : elle n'accepte que du application/json
 * (sinon 415, voir JsonInput), qu'un formulaire d'un site tiers ne peut pas envoyer.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const UNSAFE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private Csrf $csrf,
        private ViewRenderer $renderer
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->mustCheck($request)) {
            return $handler->handle($request);
        }

        $body = $request->getParsedBody();
        $submitted = is_array($body) && array_key_exists(Csrf::FIELD, $body)
            ? $body[Csrf::FIELD]
            : $request->getHeaderLine(Csrf::HEADER);

        if (!$this->csrf->isValid($submitted)) {
            return $this->renderer->render(new Response(), 'erreur', [
                'titre' => 'Requête refusée',
                'message' => 'Le formulaire a expiré ou ne provient pas de ce site. Rechargez la page et recommencez.',
            ], 403);
        }

        return $handler->handle($request);
    }

    private function mustCheck(ServerRequestInterface $request): bool
    {
        if (!in_array(strtoupper($request->getMethod()), self::UNSAFE_METHODS, true)) {
            return false;
        }

        $path = $request->getUri()->getPath();

        return $path !== '/api' && !str_starts_with($path, '/api/');
    }
}

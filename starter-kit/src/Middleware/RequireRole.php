<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Domain\Role;
use App\Http\JsonResponder;
use App\Http\ViewRenderer;
use App\Security\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * US-11 : protège une route ou un groupe de routes.
 *  - personne de connecté → redirection vers /connexion (HTML) ou 401 (API) ;
 *  - connecté sans le bon rôle → 403.
 *
 * Exemple : $group->get(...)->add(new RequireRole($auth, $renderer, Role::RESPONSABLE));
 */
final class RequireRole implements MiddlewareInterface
{
    /** @var list<Role> */
    private array $roles;

    public function __construct(
        private Auth $auth,
        private ViewRenderer $renderer,
        Role ...$roles
    ) {
        $this->roles = $roles;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $this->auth->user();
        $isApi = str_starts_with($request->getUri()->getPath(), '/api/');

        if ($user === null) {
            if ($isApi) {
                return JsonResponder::respond(new Response(), [
                    'error' => 'UNAUTHENTICATED',
                    'message' => 'Authentification requise.',
                ], 401);
            }

            return (new Response())->withHeader('Location', '/connexion')->withStatus(302);
        }

        if (!$user->hasRole(...$this->roles)) {
            if ($isApi) {
                return JsonResponder::respond(new Response(), [
                    'error' => 'FORBIDDEN',
                    'message' => 'Votre rôle ne permet pas cette action.',
                ], 403);
            }

            return $this->renderer->render(new Response(), 'erreur', [
                'titre' => 'Accès refusé',
                'message' => 'Votre rôle (' . $user->role->label() . ') ne permet pas d\'accéder à cette page.',
            ], 403);
        }

        return $handler->handle($request);
    }
}

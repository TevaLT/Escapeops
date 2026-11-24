<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\ViewRenderer;
use App\Security\Auth;
use App\Service\AuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AuthController
{
    public function __construct(
        private AuthService $service,
        private Auth $auth,
        private ViewRenderer $renderer
    ) {
    }

    public function showLogin(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        if ($this->auth->check()) {
            return $this->redirect($response, '/espace');
        }

        return $this->renderer->render($response, 'auth/connexion', ['email' => '', 'erreur' => null]);
    }

    public function login(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        // Un champ envoyé sous forme de tableau (email[]=...) est traité comme vide, sans planter.
        $email = is_string($body['email'] ?? null) ? $body['email'] : '';
        $password = is_string($body['mot_de_passe'] ?? null) ? $body['mot_de_passe'] : '';

        if ($this->service->attempt($email, $password) === null) {
            return $this->renderer->render($response, 'auth/connexion', [
                'email' => $email,
                'erreur' => 'E-mail ou mot de passe incorrect.',
            ], 401);
        }

        return $this->redirect($response, '/espace');
    }

    public function logout(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $this->service->logout();

        return $this->redirect($response, '/');
    }

    private function redirect(ResponseInterface $response, string $to): ResponseInterface
    {
        // 303 : après un POST, le navigateur suit la redirection en GET.
        return $response->withHeader('Location', $to)->withStatus(303);
    }
}

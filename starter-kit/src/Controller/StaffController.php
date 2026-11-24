<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Authorization;
use App\Http\ViewRenderer;
use App\Security\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Pages du personnel. Les routes sont déjà filtrées par RequireRole.
 */
final class StaffController
{
    public function __construct(
        private Auth $auth,
        private ViewRenderer $renderer
    ) {
    }

    /** Accueil de tout membre du personnel connecté. */
    public function home(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $user = $this->auth->user();

        return $this->renderer->render($response, 'espace/index', [
            'utilisateur' => $user,
            'peutGererPlanning' => Authorization::canManagePlanning($user),
        ]);
    }

    /** Espace gestion (responsable, admin). Le planning et le tableau de bord s'y ajouteront (US-22, US-28). */
    public function management(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        return $this->renderer->render($response, 'gestion/index', [
            'utilisateur' => $this->auth->user(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Authorization;
use App\Http\ViewRenderer;
use App\Security\Auth;
use App\Service\DashboardService;
use App\Service\SessionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Accueil du personnel (/espace) et tableau de bord de gestion (/gestion, US-28).
 * Les routes sont déjà filtrées par RequireRole.
 */
final class StaffController
{
    public function __construct(
        private Auth $auth,
        private SessionService $sessions,
        private DashboardService $dashboard,
        private ViewRenderer $renderer
    ) {
    }

    /** Accueil de tout membre du personnel : ses sessions à animer. */
    public function home(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->auth->user();

        return $this->renderer->render($response, 'espace/index', [
            'utilisateur' => $user,
            'peutGererPlanning' => Authorization::canManagePlanning($user),
            'mesSessions' => $this->sessions->forGameMaster($user->id),
        ]);
    }

    /** Tableau de bord d'exploitation (responsable, admin). */
    public function management(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->renderer->render($response, 'gestion/index', [
            'utilisateur' => $this->auth->user(),
            'tableau' => $this->dashboard->overview(),
        ]);
    }
}

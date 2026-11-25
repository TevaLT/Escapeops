<?php

declare(strict_types=1);

use App\Domain\Authorization;
use App\Http\JsonResponder;
use App\Http\ViewRenderer;
use App\Middleware\RequireRole;
use App\Security\Auth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/**
 * Routes JSON. Contrat complet : docs/API.md
 *
 * Les contrôleurs de l'API appellent les mêmes services que le site HTML (US-54) :
 * une règle refusée sur le site (E1, droits…) l'est aussi ici.
 *
 * @param array<string, object> $controllers
 */
return static function (App $app, array $controllers, Auth $auth, ViewRenderer $renderer): void {
    $c = $controllers;

    $app->group('/api', function (RouteCollectorProxy $group) use ($c, $auth, $renderer): void {
        $group->get('/health', function (Request $request, Response $response): Response {
            return JsonResponder::respond($response, [
                'status' => 'ok',
                'service' => 'escapeops',
            ]);
        });

        // Public, comme la vitrine (US-50, US-51)
        $group->get('/rooms', [$c['rooms'], 'apiIndex']);
        $group->get('/rooms/{id:[0-9]+}', [$c['rooms'], 'apiShow']);
        $group->get('/sessions', [$c['apiSessions'], 'index']);

        // Réservation sans compte par une option de 15 minutes (US-52, E1)
        $group->post('/reservations', [$c['apiReservations'], 'store']);
        $group->get('/reservations/{jeton:[0-9a-f]{32}}', [$c['apiReservations'], 'show']);
        $group->post('/reservations/{jeton:[0-9a-f]{32}}/confirmation', [$c['apiReservations'], 'confirm']);

        // Personnel connecté uniquement : 401 sans session, 403 sans le bon rôle (US-53)
        $group->post('/incidents', [$c['apiIncidents'], 'store'])
            ->add(new RequireRole($auth, $renderer, ...Authorization::STAFF));

        // Toute autre adresse sous /api : 404 en JSON (et non la page HTML de Slim), comme le promet le contrat.
        // Déclarée en dernier : FastRoute essaie d'abord les routes précises ci-dessus.
        $group->map(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], '/{chemin:.+}', function (Request $request, Response $response): Response {
            return JsonResponder::respond($response, [
                'error' => 'NOT_FOUND',
                'message' => 'Aucune ressource à cette adresse. Voir docs/API.md.',
            ], 404);
        });
    });
};

<?php

declare(strict_types=1);

use App\Domain\Authorization;
use App\Http\ViewRenderer;
use App\Middleware\RequireRole;
use App\Security\Auth;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/**
 * Routes HTML (navigateur).
 *
 * @param array<string, object> $controllers
 */
return static function (App $app, array $controllers, Auth $auth, ViewRenderer $renderer): void {
    $rooms = $controllers['rooms'];
    $authController = $controllers['auth'];
    $staff = $controllers['staff'];

    // Vitrine publique
    $app->get('/', [$rooms, 'index']);
    $app->get('/salles/{id:[0-9]+}', [$rooms, 'show']);

    // Authentification (US-10)
    $app->get('/connexion', [$authController, 'showLogin']);
    $app->post('/connexion', [$authController, 'login']);
    $app->post('/deconnexion', [$authController, 'logout']);

    // Tout le personnel connecté (US-11)
    $app->get('/espace', [$staff, 'home'])
        ->add(new RequireRole($auth, $renderer, ...Authorization::STAFF));

    // Gestion du planning : responsable et admin uniquement (US-11, US-13)
    $app->group('/gestion', function (RouteCollectorProxy $group) use ($staff): void {
        $group->get('', [$staff, 'management']);
    })->add(new RequireRole($auth, $renderer, ...Authorization::PLANNING_MANAGERS));
};

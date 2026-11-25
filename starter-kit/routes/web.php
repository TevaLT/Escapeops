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
 *  /              public (vitrine, réservation)
 *  /espace/...    tout le personnel connecté
 *  /gestion/...   responsable et admin
 *
 * Les services revérifient chaque droit (US-13) : une route mal protégée ne suffit pas à contourner une règle.
 *
 * @param array<string, object> $controllers
 */
return static function (App $app, array $controllers, Auth $auth, ViewRenderer $renderer): void {
    $c = $controllers;

    // Vitrine publique (US-20) et réservation d'un groupe (US-23) par une option de 15 min (E1)
    $app->get('/', [$c['rooms'], 'index']);
    $app->get('/salles/{id:[0-9]+}', [$c['rooms'], 'show']);
    $app->get('/sessions/{id:[0-9]+}/reserver', [$c['reservations'], 'form']);
    $app->post('/sessions/{id:[0-9]+}/reserver', [$c['reservations'], 'reserve']);
    $app->get('/reservations/{jeton:[0-9a-f]{32}}', [$c['reservations'], 'show']);
    $app->post('/reservations/{jeton:[0-9a-f]{32}}/confirmer', [$c['reservations'], 'confirm']);
    $app->post('/reservations/{jeton:[0-9a-f]{32}}/annuler', [$c['reservations'], 'cancel']);

    // Authentification (US-10)
    $app->get('/connexion', [$c['auth'], 'showLogin']);
    $app->post('/connexion', [$c['auth'], 'login']);
    $app->post('/deconnexion', [$c['auth'], 'logout']);

    // Tout le personnel connecté (US-11)
    $app->group('/espace', function (RouteCollectorProxy $group) use ($c): void {
        $group->get('', [$c['staff'], 'home']);

        // Fiche session et changements d'état (US-27)
        $group->get('/sessions/{id:[0-9]+}', [$c['sessions'], 'show']);
        $group->post('/sessions/{id:[0-9]+}/etat', [$c['sessions'], 'changeStatus']);
        // Checklist d'ouverture du game master affecté (E10)
        $group->post('/sessions/{id:[0-9]+}/checklist', [$c['sessions'], 'checklist']);

        // Incidents (US-26)
        $group->get('/incidents', [$c['incidents'], 'index']);
        $group->get('/incidents/nouveau', [$c['incidents'], 'create']);
        $group->post('/incidents', [$c['incidents'], 'store']);
    })->add(new RequireRole($auth, $renderer, ...Authorization::STAFF));

    // Gestion : responsable et admin uniquement (US-11, US-13)
    $app->group('/gestion', function (RouteCollectorProxy $group) use ($c): void {
        // Tableau de bord (US-28)
        $group->get('', [$c['staff'], 'management']);

        // Planning des sessions (US-22) et filtres (US-29)
        $group->get('/sessions', [$c['sessions'], 'index']);
        $group->get('/sessions/nouvelle', [$c['sessions'], 'create']);
        $group->post('/sessions', [$c['sessions'], 'store']);
        $group->get('/sessions/{id:[0-9]+}/modifier', [$c['sessions'], 'edit']);
        $group->post('/sessions/{id:[0-9]+}', [$c['sessions'], 'update']);
        // Affectation du game master depuis la fiche session (E5)
        $group->post('/sessions/{id:[0-9]+}/game-master', [$c['sessions'], 'assignGameMaster']);

        // Salles (US-21) et leurs équipements (US-25)
        $group->get('/salles', [$c['roomAdmin'], 'index']);
        $group->get('/salles/nouvelle', [$c['roomAdmin'], 'create']);
        $group->post('/salles', [$c['roomAdmin'], 'store']);
        $group->get('/salles/{id:[0-9]+}', [$c['roomAdmin'], 'show']);
        $group->get('/salles/{id:[0-9]+}/modifier', [$c['roomAdmin'], 'edit']);
        $group->post('/salles/{id:[0-9]+}', [$c['roomAdmin'], 'update']);
        $group->post('/salles/{id:[0-9]+}/supprimer', [$c['roomAdmin'], 'delete']);
        $group->post('/salles/{id:[0-9]+}/equipements', [$c['roomAdmin'], 'assignEquipment']);
        $group->post('/salles/{id:[0-9]+}/equipements/{equipement:[0-9]+}/retirer', [$c['roomAdmin'], 'unassignEquipment']);
        $group->get('/equipements', [$c['equipment'], 'index']);
        $group->post('/equipements', [$c['equipment'], 'store']);

        // Fiches game master (US-24)
        $group->get('/game-masters', [$c['gameMasters'], 'index']);
        $group->get('/game-masters/{id:[0-9]+}', [$c['gameMasters'], 'show']);

        // Résolution d'un incident (US-26)
        $group->post('/incidents/{id:[0-9]+}/resoudre', [$c['incidents'], 'resolve']);
    })->add(new RequireRole($auth, $renderer, ...Authorization::PLANNING_MANAGERS));
};

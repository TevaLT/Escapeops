<?php

declare(strict_types=1);

use App\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/**
 * Routes JSON. Contrat complet : docs/API.md
 *
 * @param array<string, object> $controllers
 */
return static function (App $app, array $controllers): void {
    $rooms = $controllers['rooms'];

    $app->group('/api', function (RouteCollectorProxy $group) use ($rooms): void {
        $group->get('/health', function (Request $request, Response $response): Response {
            return JsonResponder::respond($response, [
                'status' => 'ok',
                'service' => 'escapeops',
            ]);
        });

        $group->get('/rooms', [$rooms, 'apiIndex']);
        $group->get('/rooms/{id:[0-9]+}', [$rooms, 'apiShow']);
    });
};

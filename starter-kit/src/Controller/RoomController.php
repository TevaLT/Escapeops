<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\JsonResponder;
use App\Http\ViewRenderer;
use App\Service\RoomService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RoomController
{
    public function __construct(
        private RoomService $service,
        private ViewRenderer $renderer
    ) {
    }

    /** Vitrine publique : la liste des salles. */
    public function index(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        return $this->renderer->render($response, 'salles/index', [
            'salles' => $this->service->list(),
        ]);
    }

    /** Fiche publique d'une salle et de ses créneaux. */
    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $salle = $this->service->get((int) ($args['id'] ?? 0));

        if ($salle === null) {
            return $this->renderer->render($response, 'erreur', [
                'titre' => 'Salle introuvable',
                'message' => 'Cette salle n\'existe pas ou n\'est plus proposée.',
            ], 404);
        }

        return $this->renderer->render($response, 'salles/show', ['salle' => $salle]);
    }

    public function apiIndex(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        return JsonResponder::respond($response, $this->service->list());
    }

    public function apiShow(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $salle = $this->service->get((int) ($args['id'] ?? 0));

        if ($salle === null) {
            return JsonResponder::respond($response, [
                'error' => 'ROOM_NOT_FOUND',
                'message' => 'Salle introuvable.',
            ], 404);
        }

        return JsonResponder::respond($response, $salle);
    }
}

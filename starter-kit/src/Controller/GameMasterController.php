<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\ViewRenderer;
use App\Service\GameMasterService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Fiches game master (US-24), sous /gestion/game-masters.
 */
final class GameMasterController
{
    use HtmlResponses;

    public function __construct(
        private GameMasterService $service,
        private ViewRenderer $renderer
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->renderer->render($response, 'gestion/game-masters/index', ['gameMasters' => $this->service->list()]);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $gm = $this->service->get((int) $args['id']);
        if ($gm === null) {
            return $this->notFound($response, 'Game master');
        }

        return $this->renderer->render($response, 'gestion/game-masters/show', ['gm' => $gm]);
    }
}

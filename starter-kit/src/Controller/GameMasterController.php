<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\AccessDeniedException;
use App\Domain\ValidationException;
use App\Http\ViewRenderer;
use App\Security\Auth;
use App\Security\Flash;
use App\Service\GameMasterService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Fiches game master (US-24) et ajout d'un game master, sous /gestion/game-masters.
 */
final class GameMasterController
{
    use HtmlResponses;

    /** Champs réaffichés après une erreur (les mots de passe ne le sont jamais). */
    private const FIELDS = ['prenom', 'nom', 'email'];

    public function __construct(
        private GameMasterService $service,
        private Auth $auth,
        private Flash $flash,
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

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->form($response, ['prenom' => '', 'nom' => '', 'email' => '']);
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->formValues($request, [...self::FIELDS, 'mot_de_passe', 'confirmation']);

        try {
            $id = $this->service->create($this->auth->user(), $input);
        } catch (ValidationException $exception) {
            return $this->form($response, array_intersect_key($input, array_flip(self::FIELDS)), $exception->errors(), 422);
        } catch (AccessDeniedException $exception) {
            return $this->denied($response, $exception);
        }

        $this->flash->success(sprintf(
            'Game master « %s %s » ajouté : le compte peut se connecter avec cet e-mail et le mot de passe choisi.',
            trim($input['prenom']),
            trim($input['nom'])
        ));

        return $this->redirect($response, '/gestion/game-masters/' . $id);
    }

    private function form(ResponseInterface $response, array $values, array $errors = [], int $status = 200): ResponseInterface
    {
        return $this->renderer->render($response, 'gestion/game-masters/form', [
            'valeurs' => $values,
            'erreurs' => $errors,
        ], $status);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\AccessDeniedException;
use App\Domain\NotFoundException;
use App\Domain\ValidationException;
use App\Http\ViewRenderer;
use App\Security\Auth;
use App\Security\Flash;
use App\Service\EquipmentService;
use App\Service\RoomService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Gestion des salles (US-21) et de leurs équipements (US-25), sous /gestion/salles.
 */
final class RoomAdminController
{
    use HtmlResponses;

    private const FIELDS = ['nom', 'theme', 'description', 'duree_minutes', 'capacite_min', 'capacite_max', 'temps_remise_etat'];

    public function __construct(
        private RoomService $rooms,
        private EquipmentService $equipment,
        private Auth $auth,
        private Flash $flash,
        private ViewRenderer $renderer
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $rooms = [];
        foreach ($this->rooms->list() as $room) {
            $rooms[] = $room + ['equipements' => count($this->equipment->forRoom($room['id']))];
        }

        return $this->renderer->render($response, 'gestion/salles/index', ['salles' => $rooms]);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->renderShow($response, (int) $args['id']);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->form($response, null, array_fill_keys(self::FIELDS, '') + ['temps_remise_etat' => '20']);
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $values = $this->formValues($request, self::FIELDS);

        try {
            $id = $this->rooms->create($this->auth->user(), $values);
        } catch (ValidationException $exception) {
            return $this->form($response, null, $values, $exception->errors(), 422);
        } catch (AccessDeniedException $exception) {
            return $this->denied($response, $exception);
        }

        $this->flash->success('Salle créée.');

        return $this->redirect($response, '/gestion/salles/' . $id);
    }

    public function edit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $room = $this->rooms->find((int) $args['id']);
        if ($room === null) {
            return $this->notFound($response, 'Salle');
        }

        $values = [];
        foreach (self::FIELDS as $field) {
            $values[$field] = (string) ($room[$field] ?? '');
        }

        return $this->form($response, $room, $values);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $values = $this->formValues($request, self::FIELDS);

        try {
            $this->rooms->update($this->auth->user(), $id, $values);
        } catch (NotFoundException) {
            return $this->notFound($response, 'Salle');
        } catch (ValidationException $exception) {
            return $this->form($response, $this->rooms->find($id), $values, $exception->errors(), 422);
        } catch (AccessDeniedException $exception) {
            return $this->denied($response, $exception);
        }

        $this->flash->success('Salle modifiée.');

        return $this->redirect($response, '/gestion/salles/' . $id);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];

        try {
            $name = $this->rooms->delete($this->auth->user(), $id);
        } catch (NotFoundException) {
            return $this->notFound($response, 'Salle');
        } catch (ValidationException $exception) {
            return $this->renderShow($response, $id, $exception->errors(), 409);
        } catch (AccessDeniedException $exception) {
            return $this->denied($response, $exception);
        }

        $this->flash->success('Salle « ' . $name . ' » supprimée.');

        return $this->redirect($response, '/gestion/salles');
    }

    public function assignEquipment(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];

        try {
            $name = $this->equipment->assign($this->auth->user(), $id, $this->formValues($request, ['equipement'])['equipement']);
        } catch (NotFoundException) {
            return $this->notFound($response, 'Salle');
        } catch (ValidationException $exception) {
            return $this->renderShow($response, $id, $exception->errors(), 422);
        } catch (AccessDeniedException $exception) {
            return $this->denied($response, $exception);
        }

        $this->flash->success('« ' . $name . ' » ajouté à la salle.');

        return $this->redirect($response, '/gestion/salles/' . $id);
    }

    public function unassignEquipment(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];

        try {
            $name = $this->equipment->unassign($this->auth->user(), $id, (int) $args['equipement']);
        } catch (NotFoundException) {
            return $this->notFound($response, 'Équipement');
        } catch (AccessDeniedException $exception) {
            return $this->denied($response, $exception);
        }

        $this->flash->success('« ' . $name . ' » retiré de la salle.');

        return $this->redirect($response, '/gestion/salles/' . $id);
    }

    private function renderShow(ResponseInterface $response, int $id, array $errors = [], int $status = 200): ResponseInterface
    {
        $room = $this->rooms->find($id);
        if ($room === null) {
            return $this->notFound($response, 'Salle');
        }

        return $this->renderer->render($response, 'gestion/salles/show', [
            'salle' => $room,
            'usage' => $this->rooms->usage($id),
            'equipements' => $this->equipment->forRoom($id),
            'disponibles' => $this->equipment->assignableTo($id),
            'erreurs' => $errors,
        ], $status);
    }

    private function form(ResponseInterface $response, ?array $room, array $values, array $errors = [], int $status = 200): ResponseInterface
    {
        return $this->renderer->render($response, 'gestion/salles/form', [
            'salle' => $room,
            'valeurs' => $values,
            'erreurs' => $errors,
        ], $status);
    }
}

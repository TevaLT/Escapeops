<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\AccessDeniedException;
use App\Domain\ValidationException;
use App\Http\ViewRenderer;
use App\Security\Auth;
use App\Security\Flash;
use App\Service\EquipmentService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Catalogue des équipements (US-25), sous /gestion/equipements.
 */
final class EquipmentController
{
    use HtmlResponses;

    public function __construct(
        private EquipmentService $service,
        private Auth $auth,
        private Flash $flash,
        private ViewRenderer $renderer
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($response, ['nom' => '', 'est_critique' => '']);
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $values = $this->formValues($request, ['nom', 'est_critique']);

        try {
            $this->service->create($this->auth->user(), $values);
        } catch (ValidationException $exception) {
            return $this->render($response, $values, $exception->errors(), 422);
        } catch (AccessDeniedException $exception) {
            return $this->denied($response, $exception);
        }

        $this->flash->success('Équipement « ' . trim($values['nom']) . ' » ajouté au catalogue.');

        return $this->redirect($response, '/gestion/equipements');
    }

    private function render(ResponseInterface $response, array $values, array $errors = [], int $status = 200): ResponseInterface
    {
        return $this->renderer->render($response, 'gestion/equipements/index', [
            'equipements' => $this->service->all(),
            'valeurs' => $values,
            'erreurs' => $errors,
        ], $status);
    }
}

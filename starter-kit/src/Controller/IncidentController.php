<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\AccessDeniedException;
use App\Domain\Authorization;
use App\Domain\NotFoundException;
use App\Domain\ValidationException;
use App\Http\ViewRenderer;
use App\Security\Auth;
use App\Security\Flash;
use App\Service\IncidentService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Incidents (US-26) : liste et déclaration sous /espace (tout le personnel),
 * résolution sous /gestion (responsable, admin).
 */
final class IncidentController
{
    use HtmlResponses;

    private const FIELDS = ['salle', 'equipement', 'session', 'description', 'gravite', 'est_bloquant'];

    public function __construct(
        private IncidentService $service,
        private Auth $auth,
        private Flash $flash,
        private ViewRenderer $renderer
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->renderer->render($response, 'espace/incidents/index', [
            'incidents' => $this->service->list(),
            'peutResoudre' => Authorization::canManagePlanning($this->auth->user()),
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $values = array_fill_keys(self::FIELDS, '');
        // Lien « Déclarer un incident » depuis une fiche session : salle et session pré-remplies.
        $query = $request->getQueryParams();
        $values['salle'] = is_string($query['salle'] ?? null) ? $query['salle'] : '';
        $values['session'] = is_string($query['session'] ?? null) ? $query['session'] : '';
        $values['gravite'] = 'MINEURE';

        return $this->form($response, $values);
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $values = $this->formValues($request, self::FIELDS);

        try {
            $this->service->report($this->auth->user(), $values);
        } catch (ValidationException $exception) {
            return $this->form($response, $values, $exception->errors(), 422);
        } catch (AccessDeniedException $exception) {
            return $this->denied($response, $exception);
        }

        $this->flash->success('Incident déclaré.');

        return $this->redirect($response, '/espace/incidents');
    }

    public function resolve(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $this->service->resolve($this->auth->user(), (int) $args['id']);
        } catch (NotFoundException) {
            return $this->notFound($response, 'Incident');
        } catch (ValidationException $exception) {
            return $this->renderer->render($response, 'erreur', [
                'titre' => 'Action impossible',
                'message' => implode(' ', $exception->errors()),
            ], 409);
        } catch (AccessDeniedException $exception) {
            return $this->denied($response, $exception);
        }

        $this->flash->success('Incident résolu.');

        return $this->redirect($response, '/espace/incidents');
    }

    private function form(ResponseInterface $response, array $values, array $errors = [], int $status = 200): ResponseInterface
    {
        return $this->renderer->render($response, 'espace/incidents/form', [
            'valeurs' => $values,
            'erreurs' => $errors,
            'salles' => $this->service->formOptions(),
            'gravites' => IncidentService::GRAVITIES,
        ], $status);
    }
}

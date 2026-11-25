<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Domain\AccessDeniedException;
use App\Domain\ValidationException;
use App\Http\HttpInputException;
use App\Security\Auth;
use App\Service\IncidentService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * US-53 : POST /api/incidents, réservé au personnel connecté.
 * La route est protégée par RequireRole (401/403 JSON) et IncidentService revérifie le droit (US-13, US-54).
 */
final class IncidentApiController
{
    use JsonResponses;

    private const FIELDS = [
        'salleId' => 'salle',
        'equipementId' => 'equipement',
        'sessionId' => 'session',
        'description' => 'description',
        'gravite' => 'gravite',
        'estBloquant' => 'est_bloquant',
    ];

    public function __construct(
        private IncidentService $service,
        private Auth $auth
    ) {
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $input = $this->readInput($request, self::FIELDS);
        } catch (HttpInputException $exception) {
            return $this->inputError($response, $exception);
        }

        // Champs facultatifs : absents ou null en JSON = non renseignés, comme un champ de formulaire vide.
        $input['equipement'] ??= '';
        $input['session'] ??= '';

        try {
            $id = $this->service->report($this->auth->user(), $input);
        } catch (AccessDeniedException $exception) {
            return $this->error($response, 403, 'FORBIDDEN', $exception->getMessage());
        } catch (ValidationException $exception) {
            return $this->error($response, 422, 'VALIDATION_ERROR', 'Données invalides.', self::apiFieldErrors($exception->errors(), self::FIELDS));
        }

        return $this->json($response, [
            'id' => $id,
            'statut' => 'OUVERT',
        ], 201);
    }
}

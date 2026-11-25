<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Domain\Field;
use App\Domain\NotFoundException;
use App\Service\SessionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * US-51 : GET /api/sessions?roomId=1&date=2026-11-27
 * Public, comme la vitrine : uniquement des informations publiques (pas de game master ni de groupe).
 */
final class SessionApiController
{
    use JsonResponses;

    public function __construct(private SessionService $service)
    {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();

        // Un filtre présent mais mal formé est une erreur du client (422), pas un filtre ignoré :
        // sinon « roomId=abc » renverrait toutes les salles sans prévenir.
        $errors = [];
        $roomId = null;
        if (array_key_exists('roomId', $query)) {
            $roomId = Field::positiveInt($query['roomId']);
            if ($roomId === null) {
                $errors['roomId'] = 'roomId doit être un identifiant de salle (entier positif).';
            }
        }
        $day = null;
        if (array_key_exists('date', $query)) {
            $day = Field::date($query['date']);
            if ($day === null) {
                $errors['date'] = 'date doit être au format AAAA-MM-JJ.';
            }
        }
        if ($errors !== []) {
            return $this->error($response, 422, 'VALIDATION_ERROR', 'Paramètres de filtre invalides.', $errors);
        }

        try {
            return $this->json($response, $this->service->publicSlots($roomId, $day));
        } catch (NotFoundException) {
            return $this->error($response, 404, 'ROOM_NOT_FOUND', 'Salle introuvable.');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Domain\Field;
use App\Domain\NotFoundException;
use App\Domain\ReservationOption;
use App\Domain\ReservationStateException;
use App\Domain\SlotUnavailableException;
use App\Domain\TrackingCode;
use App\Domain\ValidationException;
use App\Http\HttpInputException;
use App\Service\ReservationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Réservation par l'API (US-52), avec la règle E1 du même ReservationService que le site (US-54) :
 *  POST /api/reservations                        pose une option de 15 minutes
 *  GET  /api/reservations/{jeton}                état de l'option, recalculé à chaque appel
 *  POST /api/reservations/{jeton}/confirmation   confirme l'option si elle court encore
 *
 * La réservation est désignée par son jeton secret, comme le lien envoyé au groupe sur le site :
 * un identifiant numérique permettrait de deviner (et confirmer) les réservations des autres.
 */
final class ReservationApiController
{
    use JsonResponses;

    private const FIELDS = ['nomGroupe' => 'nom_groupe', 'contact' => 'contact', 'nbJoueurs' => 'nb_joueurs'];

    public function __construct(private ReservationService $service)
    {
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $input = $this->readInput($request, self::FIELDS + ['sessionId' => 'session']);
        } catch (HttpInputException $exception) {
            return $this->inputError($response, $exception);
        }

        $sessionId = Field::positiveInt($input['session']);
        if ($sessionId === null) {
            return $this->error($response, 422, 'VALIDATION_ERROR', 'Données invalides.', [
                'sessionId' => 'sessionId doit être l\'identifiant d\'une session (entier positif).',
            ]);
        }

        try {
            $option = $this->service->placeOption($sessionId, $input);
        } catch (NotFoundException) {
            return $this->error($response, 404, 'SESSION_NOT_FOUND', 'Session introuvable.');
        } catch (SlotUnavailableException $exception) {
            // 409 : le créneau est en option (E1), déjà réservé, passé ou fermé.
            return $this->json($response, [
                'error' => 'SLOT_UNAVAILABLE',
                'message' => $exception->getMessage(),
                'disponibilite' => $exception->availability,
            ], 409);
        } catch (ValidationException $exception) {
            return $this->error($response, 422, 'VALIDATION_ERROR', 'Données invalides.', self::apiFieldErrors($exception->errors(), self::FIELDS));
        }

        return $this->json($response, [
            'jeton' => $option['jeton'],
            'codeSuivi' => TrackingCode::format($option['code_suivi']),
            'sessionId' => (int) $option['id_session'],
            'salle' => $option['salle'],
            'debut' => $option['date_debut'],
            'statut' => ReservationOption::EN_ATTENTE,
            'expiration' => $option['date_expiration'],
        ], 201)
            ->withHeader('Location', '/api/reservations/' . $option['jeton']);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $reservation = $this->service->byToken((string) $args['jeton']);
        if ($reservation === null) {
            return $this->error($response, 404, 'RESERVATION_NOT_FOUND', 'Réservation introuvable.');
        }

        return $this->json($response, self::present($reservation));
    }

    public function confirm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = (string) $args['jeton'];

        try {
            $this->service->confirm($token);
        } catch (NotFoundException) {
            return $this->error($response, 404, 'RESERVATION_NOT_FOUND', 'Réservation introuvable.');
        } catch (ReservationStateException $exception) {
            // 409 : option expirée (E1, US-33), déjà confirmée, annulée, ou session fermée entre-temps.
            $code = $exception->state === ReservationOption::EXPIREE ? 'OPTION_EXPIRED' : 'RESERVATION_CONFLICT';

            return $this->json($response, [
                'error' => $code,
                'message' => $exception->getMessage(),
                'etat' => $exception->state,
            ], 409);
        }

        return $this->json($response, self::present($this->service->byToken($token) ?? []));
    }

    /** La réservation telle que l'API la montre : état calculé à l'instant, sans le contact du groupe. */
    private static function present(array $reservation): array
    {
        return [
            'jeton' => $reservation['jeton'],
            'sessionId' => (int) $reservation['id_session'],
            'salle' => $reservation['salle'],
            'debut' => $reservation['date_debut'],
            'nomGroupe' => $reservation['nom_groupe'],
            'nbJoueurs' => (int) $reservation['nb_joueurs'],
            'statut' => $reservation['etat'],
            'expiration' => $reservation['etat'] === ReservationOption::EN_ATTENTE ? $reservation['date_expiration'] : null,
        ];
    }
}

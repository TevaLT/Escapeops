<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\NotFoundException;
use App\Domain\ReservationStateException;
use App\Domain\SlotUnavailableException;
use App\Domain\ValidationException;
use App\Http\ViewRenderer;
use App\Security\Flash;
use App\Service\ReservationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Réservation publique d'un créneau, sans compte (US-23), par une option de 15 minutes (E1) :
 *  GET/POST /sessions/{id}/reserver        formulaire, pose de l'option
 *  GET      /reservations/{jeton}          suivi de l'option (lien secret du groupe)
 *  POST     /reservations/{jeton}/confirmer
 *  POST     /reservations/{jeton}/annuler
 */
final class ReservationController
{
    use HtmlResponses;

    private const FIELDS = ['nom_groupe', 'contact', 'nb_joueurs'];

    public function __construct(
        private ReservationService $service,
        private Flash $flash,
        private ViewRenderer $renderer
    ) {
    }

    public function form(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->renderForm($response, (int) $args['id'], ['nom_groupe' => '', 'contact' => '', 'nb_joueurs' => '']);
    }

    public function reserve(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $values = $this->formValues($request, self::FIELDS);

        try {
            $option = $this->service->placeOption($id, $values);
        } catch (NotFoundException) {
            return $this->notFound($response, 'Créneau');
        } catch (SlotUnavailableException) {
            // 409 Conflict : le créneau a été pris, mis en option ou fermé entre l'affichage et l'envoi.
            return $this->renderForm($response, $id, $values, [], 409);
        } catch (ValidationException $exception) {
            return $this->renderForm($response, $id, $values, $exception->errors(), 422);
        }

        $this->flash->success(sprintf(
            'Option posée pour « %s » jusqu\'à %s. Confirmez-la avant cette heure, sinon le créneau sera libéré.',
            trim($values['nom_groupe']),
            heure_fr($option['date_expiration'])
        ));

        return $this->redirect($response, '/reservations/' . $option['jeton'])->withHeader('Referrer-Policy', 'no-referrer');
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->renderShow($response, (string) $args['jeton']);
    }

    public function confirm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = (string) $args['jeton'];

        try {
            $reservation = $this->service->confirm($token);
        } catch (NotFoundException) {
            return $this->notFound($response, 'Réservation');
        } catch (ReservationStateException $exception) {
            // 409 : option expirée (US-33), déjà confirmée, annulée, ou session fermée.
            return $this->renderShow($response, $token, $exception->getMessage(), 409);
        }

        $this->flash->success(sprintf(
            'Réservation confirmée : %s, le %s. À bientôt !',
            $reservation['salle'],
            date_fr((string) $reservation['date_debut'])
        ));

        return $this->redirect($response, '/reservations/' . $token);
    }

    public function cancel(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = (string) $args['jeton'];

        try {
            $this->service->cancel($token);
        } catch (NotFoundException) {
            return $this->notFound($response, 'Réservation');
        } catch (ReservationStateException $exception) {
            return $this->renderShow($response, $token, $exception->getMessage(), 409);
        }

        $this->flash->success('Réservation annulée : le créneau est de nouveau proposé aux autres groupes.');

        return $this->redirect($response, '/reservations/' . $token);
    }

    private function renderShow(ResponseInterface $response, string $token, ?string $error = null, int $status = 200): ResponseInterface
    {
        $reservation = $this->service->byToken($token);
        if ($reservation === null) {
            return $this->notFound($response, 'Réservation');
        }

        // Le jeton dans l'URL est la seule « clé » du groupe : il ne doit ni partir vers un autre site
        // dans l'en-tête Referer, ni rester dans un cache partagé.
        return $this->renderer->render($response, 'reservations/show', [
            'reservation' => $reservation,
            'erreur' => $error,
        ], $status)
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Cache-Control', 'no-store');
    }

    private function renderForm(ResponseInterface $response, int $id, array $values, array $errors = [], int $status = 200): ResponseInterface
    {
        $slot = $this->service->slot($id);
        if ($slot === null) {
            return $this->notFound($response, 'Créneau');
        }

        return $this->renderer->render($response, 'reservations/form', [
            'creneau' => $slot,
            'valeurs' => $values,
            'erreurs' => $errors,
        ], $status);
    }
}

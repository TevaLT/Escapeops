<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\TooManyAttemptsException;
use App\Domain\ValidationException;
use App\Http\ViewRenderer;
use App\Security\Flash;
use App\Service\ClientAccessService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * « Mes réservations » (public, sans compte) :
 *  GET  /mes-reservations               formulaire contact + code, ou liste des réservations si le client est reconnu
 *  POST /mes-reservations/connexion
 *  POST /mes-reservations/deconnexion
 */
final class ClientController
{
    use HtmlResponses;

    public function __construct(
        private ClientAccessService $service,
        private Flash $flash,
        private ViewRenderer $renderer
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $mine = $this->service->myReservations();
        if ($mine === null) {
            return $this->form($response, ['contact' => '', 'code' => '']);
        }

        // La page liste les liens secrets des réservations : ni Referer vers un autre site, ni cache partagé.
        return $this->renderer->render($response, 'clients/mes-reservations', $mine)
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $values = $this->formValues($request, ['contact', 'code']);

        try {
            $this->service->login($values);
        } catch (ValidationException $exception) {
            return $this->form($response, $values, $exception->errors(), 422);
        } catch (TooManyAttemptsException $exception) {
            return $this->form($response, $values, ['code' => $exception->getMessage()], 429);
        }

        return $this->redirect($response, '/mes-reservations');
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->service->logout();
        $this->flash->success('Vous avez quitté « Mes réservations ».');

        return $this->redirect($response, '/mes-reservations');
    }

    private function form(ResponseInterface $response, array $values, array $errors = [], int $status = 200): ResponseInterface
    {
        return $this->renderer->render($response, 'clients/acces', ['valeurs' => $values, 'erreurs' => $errors], $status);
    }
}

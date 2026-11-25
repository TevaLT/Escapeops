<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\AccessDeniedException;
use App\Domain\Authorization;
use App\Domain\NotFoundException;
use App\Domain\OpeningChecklist;
use App\Domain\SessionSchedule;
use App\Domain\SessionStatus;
use App\Domain\ValidationException;
use App\Http\ViewRenderer;
use App\Security\Auth;
use App\Security\Flash;
use App\Service\SessionService;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Planning (US-22, US-29) sous /gestion, et fiche session avec ses changements d'état (US-27) sous /espace.
 * Les routes filtrent déjà l'accès ; le service revérifie les droits (US-13).
 */
final class SessionController
{
    use HtmlResponses;

    private const FIELDS = ['salle', 'debut', 'game_master'];

    public function __construct(
        private SessionService $service,
        private Auth $auth,
        private Flash $flash,
        private ViewRenderer $renderer
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $planning = $this->service->planning($request->getQueryParams());

        return $this->renderer->render($response, 'gestion/sessions/index', [
            'sessions' => $planning['sessions'],
            'filtres' => $planning['filtres'],
            'salles' => $this->service->rooms(),
            'etats' => SessionStatus::ALL,
            'statutModifiable' => SessionService::EDITABLE_STATUS,
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->form($response, null, ['salle' => '', 'debut' => '', 'game_master' => '']);
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $values = $this->formValues($request, self::FIELDS);

        try {
            $this->service->create($this->auth->user(), $values);
        } catch (ValidationException $exception) {
            return $this->form($response, null, $values, $exception->errors(), 422);
        } catch (AccessDeniedException $exception) {
            return $this->denied($response, $exception);
        }

        $this->flash->success('Session créée.');

        return $this->redirect($response, '/gestion/sessions');
    }

    public function edit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $session = $this->service->find((int) $args['id']);
        if ($session === null) {
            return $this->notFound($response, 'Session');
        }

        return $this->form($response, $session, [
            'salle' => (string) $session['id_salle'],
            'debut' => (new DateTimeImmutable($session['date_debut']))->format(SessionSchedule::INPUT_FORMAT),
            'game_master' => $session['id_game_master'] === null ? '' : (string) $session['id_game_master'],
        ]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $values = $this->formValues($request, self::FIELDS);

        try {
            $this->service->update($this->auth->user(), $id, $values);
        } catch (NotFoundException) {
            return $this->notFound($response, 'Session');
        } catch (ValidationException $exception) {
            return $this->form($response, $this->service->find($id), $values, $exception->errors(), 422);
        } catch (AccessDeniedException $exception) {
            return $this->denied($response, $exception);
        }

        $this->flash->success('Session modifiée.');

        return $this->redirect($response, '/gestion/sessions');
    }

    /** Fiche session (tout le personnel) : détail, réservations, boutons de changement d'état. */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->renderShow($response, (int) $args['id']);
    }

    public function changeStatus(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $target = $this->formValues($request, ['etat'])['etat'];

        try {
            $this->service->changeStatus($this->auth->user(), $id, $target);
        } catch (NotFoundException) {
            return $this->notFound($response, 'Session');
        } catch (ValidationException $exception) {
            return $this->renderShow($response, $id, $exception->errors(), 422);
        } catch (AccessDeniedException $exception) {
            return $this->denied($response, $exception);
        }

        $this->flash->success('Session passée à l\'état « ' . libelle_statut($target) . ' ».');

        return $this->redirect($response, '/espace/sessions/' . $id);
    }

    /** E5 : affecter, remplacer ou retirer le game master depuis la fiche (responsable, admin). */
    public function assignGameMaster(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $gameMaster = $this->formValues($request, ['game_master'])['game_master'];

        try {
            ['avant' => $before, 'apres' => $after] = $this->service->assignGameMaster($this->auth->user(), $id, $gameMaster);
        } catch (NotFoundException) {
            return $this->notFound($response, 'Session');
        } catch (ValidationException $exception) {
            return $this->renderShow($response, $id, $exception->errors(), 422);
        } catch (AccessDeniedException $exception) {
            return $this->denied($response, $exception);
        }

        if ($before === SessionStatus::PRETE && $after === SessionStatus::PREVUE) {
            // EV-TEV-01 : plus d'animateur, la session n'est plus prête (les réservations sont conservées).
            $this->flash->success('Game master retiré : la session n\'est plus prête et repasse à « Prévue » (personnel manquant). '
                . 'Affectez un game master : il devra valider la checklist d\'ouverture.');
        } else {
            $this->flash->success($gameMaster === '' ? 'Game master retiré de la session.' : 'Game master affecté.');
        }

        return $this->redirect($response, '/espace/sessions/' . $id);
    }

    /** E10 : envoi de la checklist d'ouverture par le game master affecté. */
    public function checklist(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $values = $this->formValues($request, array_keys(OpeningChecklist::POINTS));

        try {
            $this->service->submitChecklist($this->auth->user(), $id, $values);
        } catch (NotFoundException) {
            return $this->notFound($response, 'Session');
        } catch (ValidationException $exception) {
            // 2/3 : checklist enregistrée, mais la session reste « prévue » (« salle non prête »).
            return $this->renderShow($response, $id, $exception->errors(), 422, $values);
        } catch (AccessDeniedException $exception) {
            return $this->denied($response, $exception);
        }

        $this->flash->success('Checklist 3/3 enregistrée : la session est prête.');

        return $this->redirect($response, '/espace/sessions/' . $id);
    }

    private function renderShow(
        ResponseInterface $response,
        int $id,
        array $errors = [],
        int $status = 200,
        array $checklistValues = []
    ): ResponseInterface {
        $session = $this->service->detail($id);
        if ($session === null) {
            return $this->notFound($response, 'Session');
        }

        $user = $this->auth->user();

        return $this->renderer->render($response, 'espace/sessions/show', [
            'session' => $session,
            'transitions' => $this->service->allowedTransitions($user, $session),
            'checklistOuverte' => $this->service->canFillChecklist($user, $session),
            'checklistSaisie' => OpeningChecklist::fromInput($checklistValues),
            'affectationOuverte' => Authorization::canManagePlanning($user)
                && in_array($session['statut'], SessionService::GM_ASSIGNABLE_STATUS, true),
            'gameMasters' => $this->service->gameMasters(),
            'erreurs' => $errors,
            'voitContacts' => Authorization::canManagePlanning($user),
        ], $status);
    }

    private function form(
        ResponseInterface $response,
        ?array $session,
        array $values,
        array $errors = [],
        int $status = 200
    ): ResponseInterface {
        return $this->renderer->render($response, 'gestion/sessions/form', [
            'session' => $session,
            'valeurs' => $values,
            'erreurs' => $errors,
            'salles' => $this->service->rooms(),
            'gameMasters' => $this->service->gameMasters(),
        ], $status);
    }
}

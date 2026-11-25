<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Authorization;
use App\Domain\CurrentUser;
use App\Domain\Field;
use App\Domain\NotFoundException;
use App\Domain\ValidationException;
use App\Repository\EquipmentRepository;
use App\Repository\IncidentRepository;
use App\Repository\RoomRepository;
use App\Repository\SessionRepository;
use App\Support\Clock;

/**
 * Incidents (US-26) : tout le personnel déclare, le responsable ou l'admin résout.
 */
final class IncidentService
{
    public const GRAVITIES = ['MINEURE', 'CRITIQUE'];
    public const MAX_DESCRIPTION = 2000;

    public function __construct(
        private IncidentRepository $incidents,
        private RoomRepository $rooms,
        private EquipmentRepository $equipment,
        private SessionRepository $sessions,
        private Clock $clock
    ) {
    }

    public function list(?string $status = null): array
    {
        return $this->incidents->list($status);
    }

    public function open(): array
    {
        return $this->incidents->list('OUVERT');
    }

    /** Salles et équipements de chaque salle, pour le formulaire. */
    public function formOptions(): array
    {
        $rooms = [];
        foreach ($this->rooms->all() as $room) {
            $rooms[] = $room + ['equipements' => $this->equipment->forRoom((int) $room['id_salle'])];
        }

        return $rooms;
    }

    /**
     * @param array<string, mixed> $input salle, equipement, session, description, gravite, est_bloquant
     * @throws \App\Domain\AccessDeniedException|ValidationException
     */
    public function report(?CurrentUser $user, array $input): int
    {
        Authorization::denyUnlessStaff($user);

        $errors = [];

        $roomId = Field::positiveInt($input['salle'] ?? null);
        $room = $roomId === null ? null : $this->rooms->find($roomId);
        if ($room === null) {
            $errors['salle'] = 'Choisissez la salle concernée.';
        }

        // Équipement facultatif, mais s'il est indiqué il doit être installé dans cette salle.
        $equipmentId = null;
        if (Field::text($input['equipement'] ?? null) !== '') {
            $equipmentId = Field::positiveInt($input['equipement']);
            if ($equipmentId === null || $room === null || !$this->equipment->isAssigned($roomId, $equipmentId)) {
                $errors['equipement'] = 'Cet équipement n\'est pas installé dans la salle choisie.';
            }
        }

        // Session facultative, mais elle doit avoir lieu dans cette salle.
        $sessionId = null;
        if (Field::text($input['session'] ?? null) !== '') {
            $sessionId = Field::positiveInt($input['session']);
            $session = $sessionId === null ? null : $this->sessions->find($sessionId);
            if ($session === null || $room === null || (int) $session['id_salle'] !== $roomId) {
                $errors['session'] = 'Cette session n\'a pas lieu dans la salle choisie.';
            }
        }

        $description = Field::text($input['description'] ?? null);
        if ($description === '' || mb_strlen($description) > self::MAX_DESCRIPTION) {
            $errors['description'] = 'Décrivez le problème (' . self::MAX_DESCRIPTION . ' caractères au plus).';
        }

        $gravity = $input['gravite'] ?? null;
        if (!in_array($gravity, self::GRAVITIES, true)) {
            $errors['gravite'] = 'Choisissez une gravité : mineure ou critique.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $this->incidents->insert([
            'id_salle' => $roomId,
            'id_equipement' => $equipmentId,
            'id_session' => $sessionId,
            'id_declarant' => $user->id,   // le déclarant vient de la session, jamais du formulaire
            'description' => $description,
            'gravite' => $gravity,
            'est_bloquant' => Field::checked($input['est_bloquant'] ?? null),
            'statut' => 'OUVERT',
            'date_declaration' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);
    }

    /** @throws \App\Domain\AccessDeniedException|NotFoundException|ValidationException */
    public function resolve(?CurrentUser $user, int $id): void
    {
        Authorization::denyUnlessCanManagePlanning($user);

        $incident = $this->incidents->find($id);
        if ($incident === null) {
            throw new NotFoundException('Incident introuvable.');
        }
        if ($incident['statut'] !== 'OUVERT') {
            throw new ValidationException(['statut' => 'Cet incident est déjà résolu.']);
        }

        $this->incidents->resolve($id, $this->clock->now());
    }
}

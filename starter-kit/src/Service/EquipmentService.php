<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Authorization;
use App\Domain\CurrentUser;
use App\Domain\Field;
use App\Domain\NotFoundException;
use App\Domain\ValidationException;
use App\Repository\EquipmentRepository;
use App\Repository\RoomRepository;
use App\Support\Clock;

/**
 * Équipements et leur affectation aux salles (US-25, relation N–N).
 */
final class EquipmentService
{
    public function __construct(
        private EquipmentRepository $equipment,
        private RoomRepository $rooms,
        private Clock $clock
    ) {
    }

    public function all(): array
    {
        return $this->equipment->all();
    }

    public function forRoom(int $roomId): array
    {
        return $this->equipment->forRoom($roomId);
    }

    /** Équipements qui ne sont pas encore dans cette salle (liste « Affecter »). */
    public function assignableTo(int $roomId): array
    {
        $installed = array_column($this->equipment->forRoom($roomId), 'id_equipement');

        return array_values(array_filter(
            $this->equipment->all(),
            static fn (array $item): bool => !in_array($item['id_equipement'], $installed, false)
        ));
    }

    /** @throws \App\Domain\AccessDeniedException|ValidationException */
    public function create(?CurrentUser $user, array $input): int
    {
        Authorization::denyUnlessCanManageRooms($user);

        $name = Field::text($input['nom'] ?? null);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new ValidationException(['nom' => 'Le nom de l\'équipement est obligatoire (100 caractères au plus).']);
        }

        return $this->equipment->insert($name, Field::checked($input['est_critique'] ?? null));
    }

    /**
     * @return string nom de l'équipement affecté
     * @throws \App\Domain\AccessDeniedException|NotFoundException|ValidationException
     */
    public function assign(?CurrentUser $user, int $roomId, mixed $equipmentId): string
    {
        Authorization::denyUnlessCanManageRooms($user);
        $this->requireRoom($roomId);

        $id = Field::positiveInt($equipmentId);
        $item = $id === null ? null : $this->equipment->find($id);
        if ($item === null) {
            throw new ValidationException(['equipement' => 'Choisissez un équipement existant.']);
        }
        if ($this->equipment->isAssigned($roomId, $id)) {
            throw new ValidationException(['equipement' => '« ' . $item['nom'] . ' » est déjà dans cette salle.']);
        }

        $this->equipment->assign($roomId, $id, $this->clock->now());

        return (string) $item['nom'];
    }

    /** @throws \App\Domain\AccessDeniedException|NotFoundException */
    public function unassign(?CurrentUser $user, int $roomId, int $equipmentId): string
    {
        Authorization::denyUnlessCanManageRooms($user);
        $this->requireRoom($roomId);

        $item = $this->equipment->find($equipmentId);
        if ($item === null || !$this->equipment->isAssigned($roomId, $equipmentId)) {
            throw new NotFoundException('Cet équipement n\'est pas dans cette salle.');
        }

        $this->equipment->unassign($roomId, $equipmentId);

        return (string) $item['nom'];
    }

    private function requireRoom(int $roomId): void
    {
        if ($this->rooms->find($roomId) === null) {
            throw new NotFoundException('Salle introuvable.');
        }
    }
}

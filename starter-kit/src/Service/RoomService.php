<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Authorization;
use App\Domain\CurrentUser;
use App\Domain\Field;
use App\Domain\NotFoundException;
use App\Domain\SlotAvailability;
use App\Domain\ValidationException;
use App\Repository\RoomRepository;
use App\Support\Clock;

/**
 * Salles : vitrine publique (lecture) et gestion (US-21, responsable et admin).
 */
final class RoomService
{
    /** Limites des colonnes SQL (TINYINT / SMALLINT UNSIGNED, VARCHAR). */
    public const MAX_NAME = 100;
    public const MAX_DESCRIPTION = 2000;
    public const MAX_DURATION = 600;
    public const MAX_PLAYERS = 50;

    public function __construct(
        private RoomRepository $repository,
        private Clock $clock
    ) {
    }

    public function list(): array
    {
        return array_map([$this, 'normalize'], $this->repository->all());
    }

    /**
     * Salle et ses créneaux à venir, chacun avec sa disponibilité calculée.
     */
    public function get(int $id): ?array
    {
        $room = $this->repository->find($id);
        if ($room === null) {
            return null;
        }

        $now = $this->clock->now();
        $sessions = [];
        foreach ($this->repository->upcomingSessions($id, $now) as $session) {
            $availability = SlotAvailability::of($session, $now);
            $sessions[] = [
                'id' => (int) $session['id_session'],
                'debut' => $session['date_debut'],
                'fin' => $session['date_fin'],
                'statut' => $session['statut'],
                'disponibilite' => $availability,
                // E1 : fin de l'option qui bloque le créneau (null si le créneau n'est pas en option)
                'optionJusqua' => $availability === SlotAvailability::EN_OPTION ? $session['option_expire_le'] : null,
            ];
        }

        return $this->normalize($room) + ['sessions' => $sessions];
    }

    /** Ligne brute (formulaire de gestion). */
    public function find(int $id): ?array
    {
        return $this->repository->find($id);
    }

    /** @return array{sessions: int, incidents: int} */
    public function usage(int $id): array
    {
        return $this->repository->usage($id);
    }

    /** @throws \App\Domain\AccessDeniedException|ValidationException */
    public function create(?CurrentUser $user, array $input): int
    {
        Authorization::denyUnlessCanManageRooms($user);

        return $this->repository->insert($this->validate($input, null));
    }

    /** @throws \App\Domain\AccessDeniedException|NotFoundException|ValidationException */
    public function update(?CurrentUser $user, int $id, array $input): void
    {
        Authorization::denyUnlessCanManageRooms($user);
        $this->requireRoom($id);

        $this->repository->update($id, $this->validate($input, $id));
    }

    /**
     * Une salle qui a des sessions ou des incidents n'est pas supprimée : on perdrait l'historique.
     *
     * @throws \App\Domain\AccessDeniedException|NotFoundException|ValidationException
     */
    public function delete(?CurrentUser $user, int $id): string
    {
        Authorization::denyUnlessCanManageRooms($user);
        $room = $this->requireRoom($id);

        $usage = $this->repository->usage($id);
        if ($usage['sessions'] > 0 || $usage['incidents'] > 0) {
            throw new ValidationException(['salle' => sprintf(
                'Impossible de supprimer « %s » : elle a %d session(s) et %d incident(s) enregistrés.',
                $room['nom'],
                $usage['sessions'],
                $usage['incidents']
            )]);
        }

        $this->repository->delete($id);

        return (string) $room['nom'];
    }

    /** @return array<string, mixed> */
    private function requireRoom(int $id): array
    {
        $room = $this->repository->find($id);
        if ($room === null) {
            throw new NotFoundException('Salle introuvable.');
        }

        return $room;
    }

    /**
     * @return array<string, mixed> colonnes prêtes à enregistrer
     * @throws ValidationException
     */
    private function validate(array $input, ?int $excludeId): array
    {
        $errors = [];

        $name = Field::text($input['nom'] ?? null);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
            $errors['nom'] = 'Le nom est obligatoire (' . self::MAX_NAME . ' caractères au plus).';
        } elseif ($this->repository->nameTaken($name, $excludeId)) {
            $errors['nom'] = 'Une salle porte déjà ce nom.';
        }

        $theme = Field::text($input['theme'] ?? null);
        if ($theme === '' || mb_strlen($theme) > self::MAX_NAME) {
            $errors['theme'] = 'Le thème est obligatoire (' . self::MAX_NAME . ' caractères au plus).';
        }

        $description = Field::text($input['description'] ?? null);
        if (mb_strlen($description) > self::MAX_DESCRIPTION) {
            $errors['description'] = 'La description ne doit pas dépasser ' . self::MAX_DESCRIPTION . ' caractères.';
        }

        $duration = Field::intBetween($input['duree_minutes'] ?? null, 1, self::MAX_DURATION);
        if ($duration === null) {
            $errors['duree_minutes'] = 'La durée doit être comprise entre 1 et ' . self::MAX_DURATION . ' minutes.';
        }

        $min = Field::intBetween($input['capacite_min'] ?? null, 1, self::MAX_PLAYERS);
        if ($min === null) {
            $errors['capacite_min'] = 'La capacité minimale doit être comprise entre 1 et ' . self::MAX_PLAYERS . '.';
        }

        $max = Field::intBetween($input['capacite_max'] ?? null, 1, self::MAX_PLAYERS);
        if ($max === null) {
            $errors['capacite_max'] = 'La capacité maximale doit être comprise entre 1 et ' . self::MAX_PLAYERS . '.';
        } elseif ($min !== null && $max < $min) {
            $errors['capacite_max'] = 'La capacité maximale doit être supérieure ou égale à la capacité minimale.';
        }

        $cleanup = Field::intBetween($input['temps_remise_etat'] ?? null, 0, self::MAX_DURATION);
        if ($cleanup === null) {
            $errors['temps_remise_etat'] = 'Le temps de remise en état doit être compris entre 0 et ' . self::MAX_DURATION . ' minutes.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'nom' => $name,
            'theme' => $theme,
            'description' => $description === '' ? null : $description,
            'duree_minutes' => $duration,
            'capacite_min' => $min,
            'capacite_max' => $max,
            'temps_remise_etat' => $cleanup,
        ];
    }

    private function normalize(array $room): array
    {
        return [
            'id' => (int) $room['id_salle'],
            'nom' => $room['nom'],
            'theme' => $room['theme'],
            'description' => $room['description'],
            'dureeMinutes' => (int) $room['duree_minutes'],
            'capaciteMin' => (int) $room['capacite_min'],
            'capaciteMax' => (int) $room['capacite_max'],
            'tempsRemiseEtat' => (int) $room['temps_remise_etat'],
        ];
    }
}

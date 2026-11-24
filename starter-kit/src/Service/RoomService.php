<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\SlotAvailability;
use App\Repository\RoomRepository;
use App\Support\Clock;

final class RoomService
{
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
            $sessions[] = [
                'id' => (int) $session['id_session'],
                'debut' => $session['date_debut'],
                'fin' => $session['date_fin'],
                'statut' => $session['statut'],
                'disponibilite' => SlotAvailability::of($session, $now),
            ];
        }

        return $this->normalize($room) + ['sessions' => $sessions];
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

<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\DashboardRepository;
use App\Repository\IncidentRepository;
use App\Support\Clock;

/**
 * Tableau de bord du responsable (US-28) : prochaines sessions, incidents ouverts, disponibilité des salles.
 */
final class DashboardService
{
    public const BLOQUEE = 'BLOQUEE';
    public const EN_PARTIE = 'EN_PARTIE';
    public const DISPONIBLE = 'DISPONIBLE';

    public function __construct(
        private DashboardRepository $dashboard,
        private IncidentRepository $incidents,
        private SessionService $sessions,
        private Clock $clock
    ) {
    }

    public function overview(): array
    {
        $rooms = [];
        foreach ($this->dashboard->roomStatus($this->clock->now()) as $room) {
            $rooms[] = $room + ['etat' => self::roomState($room)];
        }

        return [
            'prochaines' => $this->sessions->upcoming(8),
            'incidents' => $this->incidents->list('OUVERT'),
            'salles' => $rooms,
        ];
    }

    /** Un incident bloquant ouvert rend la salle indisponible ; sinon une partie en cours l'occupe. */
    public static function roomState(array $room): string
    {
        if ((int) $room['incidents_bloquants'] > 0) {
            return self::BLOQUEE;
        }

        return (int) $room['parties_en_cours'] > 0 ? self::EN_PARTIE : self::DISPONIBLE;
    }
}

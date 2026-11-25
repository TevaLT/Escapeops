<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Role;
use App\Repository\SessionRepository;
use App\Repository\UserRepository;
use App\Support\Clock;

/**
 * Fiches game master (US-24) : liste et détail avec leurs sessions à venir.
 */
final class GameMasterService
{
    public function __construct(
        private UserRepository $users,
        private SessionRepository $sessions,
        private Clock $clock
    ) {
    }

    public function list(): array
    {
        $from = $this->clock->now()->setTime(0, 0);
        $list = [];
        foreach ($this->users->byRole(Role::GAME_MASTER->value) as $gm) {
            $upcoming = $this->sessions->search(['gameMaster' => $gm['id_utilisateur'], 'from' => $from, 'activeOnly' => true]);
            $list[] = $gm + ['nb_sessions' => count($upcoming), 'prochaine' => $upcoming[0]['date_debut'] ?? null];
        }

        return $list;
    }

    public function get(int $id): ?array
    {
        $gm = $this->users->findWithRole($id, Role::GAME_MASTER->value);
        if ($gm === null) {
            return null;
        }

        return $gm + ['sessions' => $this->sessions->search([
            'gameMaster' => $id,
            'from' => $this->clock->now()->setTime(0, 0),
        ])];
    }
}

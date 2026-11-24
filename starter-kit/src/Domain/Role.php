<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Rôles du personnel (colonne utilisateur.role, contrainte ck_utilisateur_role).
 * Le public (les groupes qui réservent) n'a pas de compte.
 */
enum Role: string
{
    case ADMIN = 'ADMIN';
    case RESPONSABLE = 'RESPONSABLE';
    case GAME_MASTER = 'GAME_MASTER';

    public function label(): string
    {
        return match ($this) {
            self::ADMIN => 'Administrateur',
            self::RESPONSABLE => 'Responsable',
            self::GAME_MASTER => 'Game master',
        };
    }
}

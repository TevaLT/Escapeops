<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Qui a le droit de faire quoi. Source unique des droits, utilisée :
 *  - par les middlewares de route (RequireRole) pour filtrer l'accès aux pages ;
 *  - par les services, qui revérifient avant chaque écriture sensible (US-13),
 *    pour qu'une règle ne dépende jamais d'un lien caché dans une vue.
 */
final class Authorization
{
    /** Créer, modifier, annuler une session ou changer son game master. */
    public const PLANNING_MANAGERS = [Role::ADMIN, Role::RESPONSABLE];

    /** Gérer les salles et leurs équipements. */
    public const ROOM_MANAGERS = [Role::ADMIN, Role::RESPONSABLE];

    /** Tout le personnel connecté. */
    public const STAFF = [Role::ADMIN, Role::RESPONSABLE, Role::GAME_MASTER];

    public static function canManagePlanning(?CurrentUser $user): bool
    {
        return $user !== null && $user->hasRole(...self::PLANNING_MANAGERS);
    }

    public static function canManageRooms(?CurrentUser $user): bool
    {
        return $user !== null && $user->hasRole(...self::ROOM_MANAGERS);
    }

    /**
     * Invariant US-13 : seul un rôle autorisé modifie une session.
     * À appeler en tête de chaque méthode d'écriture du futur SessionService.
     *
     * @throws AccessDeniedException
     */
    public static function denyUnlessCanManagePlanning(?CurrentUser $user): void
    {
        if (!self::canManagePlanning($user)) {
            throw new AccessDeniedException(
                'Seuls un responsable ou un administrateur peuvent modifier le planning.'
            );
        }
    }
}

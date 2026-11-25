<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Qui a le droit de faire quoi (cahier des charges, § 3). Source unique des droits, utilisée :
 *  - par les middlewares de route (RequireRole) pour filtrer l'accès aux pages ;
 *  - par les services, qui revérifient avant chaque écriture sensible (US-13),
 *    pour qu'une règle ne dépende jamais d'un lien caché dans une vue.
 */
final class Authorization
{
    /** Créer, modifier, annuler une session ; gérer réservations et incidents ; tableau de bord. */
    public const PLANNING_MANAGERS = [Role::ADMIN, Role::RESPONSABLE];

    /** Gérer les salles et leurs équipements. */
    public const ROOM_MANAGERS = [Role::ADMIN, Role::RESPONSABLE];

    /** Tout le personnel connecté. */
    public const STAFF = [Role::ADMIN, Role::RESPONSABLE, Role::GAME_MASTER];

    /**
     * Créer un compte game master : le responsable (qui constitue son équipe) et l'admin.
     * Seuls des comptes GAME_MASTER peuvent être créés ainsi : jamais un responsable ni un admin.
     */
    public const GAME_MASTER_RECRUITERS = [Role::ADMIN, Role::RESPONSABLE];

    public static function canManagePlanning(?CurrentUser $user): bool
    {
        return $user !== null && $user->hasRole(...self::PLANNING_MANAGERS);
    }

    public static function canCreateGameMaster(?CurrentUser $user): bool
    {
        return $user !== null && $user->hasRole(...self::GAME_MASTER_RECRUITERS);
    }

    /** @throws AccessDeniedException */
    public static function denyUnlessCanCreateGameMaster(?CurrentUser $user): void
    {
        if (!self::canCreateGameMaster($user)) {
            throw new AccessDeniedException('Seuls un responsable ou un administrateur peuvent ajouter un game master.');
        }
    }

    public static function canManageRooms(?CurrentUser $user): bool
    {
        return $user !== null && $user->hasRole(...self::ROOM_MANAGERS);
    }

    public static function isStaff(?CurrentUser $user): bool
    {
        return $user !== null && $user->hasRole(...self::STAFF);
    }

    /**
     * Changer l'état d'une session (US-27) :
     *  - l'annuler : responsable ou admin uniquement ;
     *  - la préparer, la démarrer, la terminer : responsable, admin, ou le game master affecté à cette session.
     *
     * @param array{id_game_master: int|string|null} $session
     */
    public static function canChangeSessionStatus(?CurrentUser $user, array $session, string $target): bool
    {
        if (self::canManagePlanning($user)) {
            return true;
        }

        return $target !== SessionStatus::ANNULEE
            && $user !== null
            && $user->role === Role::GAME_MASTER
            && self::isAssignedGameMaster($user, $session);
    }

    /**
     * E10 : la checklist d'ouverture est remplie par le game master affecté à la session,
     * et lui seul (c'est son nom qui est enregistré avec les trois points).
     *
     * @param array{id_game_master: int|string|null} $session
     */
    public static function canFillChecklist(?CurrentUser $user, array $session): bool
    {
        return $user !== null && $user->role === Role::GAME_MASTER && self::isAssignedGameMaster($user, $session);
    }

    /** @throws AccessDeniedException */
    public static function denyUnlessCanFillChecklist(?CurrentUser $user, array $session): void
    {
        if (!self::canFillChecklist($user, $session)) {
            throw new AccessDeniedException('Seul le game master affecté à cette session peut remplir sa checklist d\'ouverture.');
        }
    }

    private static function isAssignedGameMaster(CurrentUser $user, array $session): bool
    {
        return $session['id_game_master'] !== null && (int) $session['id_game_master'] === $user->id;
    }

    /**
     * Invariant US-13 : seul un rôle autorisé modifie une session.
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

    /** @throws AccessDeniedException */
    public static function denyUnlessCanManageRooms(?CurrentUser $user): void
    {
        if (!self::canManageRooms($user)) {
            throw new AccessDeniedException('Seuls un responsable ou un administrateur peuvent gérer les salles et les équipements.');
        }
    }

    /** @throws AccessDeniedException */
    public static function denyUnlessStaff(?CurrentUser $user): void
    {
        if (!self::isStaff($user)) {
            throw new AccessDeniedException('Action réservée au personnel connecté.');
        }
    }

    /** @throws AccessDeniedException */
    public static function denyUnlessCanChangeSessionStatus(?CurrentUser $user, array $session, string $target): void
    {
        if (!self::canChangeSessionStatus($user, $session, $target)) {
            throw new AccessDeniedException($target === SessionStatus::ANNULEE
                ? 'Seuls un responsable ou un administrateur peuvent annuler une session.'
                : 'Seul le game master affecté à cette session (ou un responsable) peut changer son état.');
        }
    }
}

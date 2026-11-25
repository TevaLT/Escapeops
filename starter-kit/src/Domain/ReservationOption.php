<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * E1 — « Une option pendant quinze minutes » (US-30 à US-33).
 *
 * Un groupe pose une option EN_ATTENTE gratuite, valable 15 minutes. Tant qu'elle court,
 * le créneau est bloqué pour les autres groupes (SlotAvailability). Une fois le délai passé,
 * elle ne bloque plus rien et ne peut plus être confirmée.
 *
 * L'expiration n'est jamais « exécutée » par une tâche planifiée : elle est recalculée à
 * chaque requête HTTP en comparant date_expiration à l'heure de l'horloge (Clock).
 * Bornes : l'option est active tant que maintenant < date_expiration ; à l'heure pile, elle a expiré.
 */
final class ReservationOption
{
    public const MINUTES = 15;

    /** États vus par le groupe (EXPIREE est calculé : en base, la ligne reste EN_ATTENTE). */
    public const EN_ATTENTE = 'EN_ATTENTE';
    public const EXPIREE = 'EXPIREE';
    public const CONFIRMEE = 'CONFIRMEE';
    public const ANNULEE = 'ANNULEE';

    public static function expiresAt(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify('+' . self::MINUTES . ' minutes');
    }

    public static function isActive(?string $expiresAt, DateTimeImmutable $now): bool
    {
        return $expiresAt !== null && new DateTimeImmutable($expiresAt) > $now;
    }

    /**
     * @param array{statut: string, date_expiration: ?string} $reservation
     */
    public static function state(array $reservation, DateTimeImmutable $now): string
    {
        if ($reservation['statut'] !== self::EN_ATTENTE) {
            return (string) $reservation['statut'];
        }

        return self::isActive($reservation['date_expiration'], $now) ? self::EN_ATTENTE : self::EXPIREE;
    }
}

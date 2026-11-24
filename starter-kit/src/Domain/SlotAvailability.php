<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * Décide si un créneau (session) est réservable par le public.
 *
 * Invariants du socle : pas de session passée, annulée ou déjà confirmée.
 * Prépare E1 : une option EN_ATTENTE encore valide bloque le créneau,
 * une option expirée ne bloque plus rien.
 */
final class SlotAvailability
{
    public const LIBRE = 'LIBRE';
    public const EN_OPTION = 'EN_OPTION';
    public const COMPLET = 'COMPLET';
    public const FERME = 'FERME';

    private const STATUTS_RESERVABLES = ['PREVUE', 'PRETE'];

    /**
     * @param array{date_debut: string, statut: string, nb_confirmees: int|string|null, option_expire_le: ?string} $session
     */
    public static function of(array $session, DateTimeImmutable $now): string
    {
        $debut = new DateTimeImmutable($session['date_debut']);

        if ($debut <= $now || !in_array($session['statut'], self::STATUTS_RESERVABLES, true)) {
            return self::FERME;
        }

        if ((int) ($session['nb_confirmees'] ?? 0) > 0) {
            return self::COMPLET;
        }

        $option = $session['option_expire_le'] ?? null;
        if ($option !== null && new DateTimeImmutable($option) > $now) {
            return self::EN_OPTION;
        }

        return self::LIBRE;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * « Mes réservations » : range chaque réservation du client dans « À venir » ou dans l'historique des parties,
 * et dit ce qu'il est advenu de la partie. Règles pures, sans base de données.
 *
 * À venir : la partie n'a pas commencé (session prévue ou prête, début dans le futur), et la réservation tient encore
 * (option en cours ou réservation confirmée). Tout le reste relève de l'historique.
 */
final class ClientHistory
{
    public const JOUEE = 'JOUEE';
    public const EN_COURS = 'EN_COURS';
    public const INTERROMPUE = 'INTERROMPUE';
    public const SESSION_ANNULEE = 'SESSION_ANNULEE';
    public const RESERVATION_ANNULEE = 'RESERVATION_ANNULEE';
    public const OPTION_EXPIREE = 'OPTION_EXPIREE';
    public const NON_JOUEE = 'NON_JOUEE';

    /**
     * @param array{etat: string, date_debut: string, statut_session: string} $reservation
     *        « etat » : état de la réservation calculé par ReservationOption::state
     */
    public static function isUpcoming(array $reservation, DateTimeImmutable $now): bool
    {
        // L'état de la session prime sur l'heure : une partie démarrée, terminée ou annulée n'est plus « à venir ».
        return new DateTimeImmutable($reservation['date_debut']) > $now
            && in_array($reservation['statut_session'], [SessionStatus::PREVUE, SessionStatus::PRETE], true)
            && in_array($reservation['etat'], [ReservationOption::EN_ATTENTE, ReservationOption::CONFIRMEE], true);
    }

    /**
     * Ce qu'il est advenu de la partie, pour une réservation de l'historique.
     *
     * @param array{etat: string, statut_session: string} $reservation
     */
    public static function outcome(array $reservation): string
    {
        return match (true) {
            $reservation['etat'] === ReservationOption::EXPIREE => self::OPTION_EXPIREE,
            $reservation['etat'] === ReservationOption::ANNULEE => self::RESERVATION_ANNULEE,
            $reservation['etat'] === ReservationOption::EN_ATTENTE => self::NON_JOUEE,   // option jamais confirmée
            $reservation['statut_session'] === SessionStatus::ANNULEE => self::SESSION_ANNULEE,
            $reservation['statut_session'] === SessionStatus::TERMINEE => self::JOUEE,
            $reservation['statut_session'] === SessionStatus::EN_COURS => self::EN_COURS,
            $reservation['statut_session'] === SessionStatus::INTERROMPUE => self::INTERROMPUE,
            default => self::NON_JOUEE,   // confirmée, mais la session n'a jamais démarré
        };
    }

    public static function label(string $outcome): string
    {
        return match ($outcome) {
            self::JOUEE => 'Partie jouée',
            self::EN_COURS => 'Partie en cours',
            self::INTERROMPUE => 'Partie interrompue',
            self::SESSION_ANNULEE => 'Session annulée par les Archives : nous vous recontactons',
            self::RESERVATION_ANNULEE => 'Réservation annulée',
            self::OPTION_EXPIREE => 'Option expirée sans confirmation',
            default => 'Partie non jouée',
        };
    }
}

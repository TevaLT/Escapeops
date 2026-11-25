<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Cycle de vie d'une session (US-27) :
 *
 *   PREVUE → PRETE → EN_COURS → TERMINEE
 *      └───────┴──→ ANNULEE
 *
 * Demain : PRETE exigera la checklist 3/3 (E10) et EN_COURS un game master affecté (E5).
 * INTERROMPUE est réservé à l'extension E6.
 */
final class SessionStatus
{
    public const PREVUE = 'PREVUE';
    public const PRETE = 'PRETE';
    public const EN_COURS = 'EN_COURS';
    public const INTERROMPUE = 'INTERROMPUE';
    public const TERMINEE = 'TERMINEE';
    public const ANNULEE = 'ANNULEE';

    public const ALL = [
        self::PREVUE, self::PRETE, self::EN_COURS, self::INTERROMPUE, self::TERMINEE, self::ANNULEE,
    ];

    private const TRANSITIONS = [
        self::PREVUE => [self::PRETE, self::ANNULEE],
        self::PRETE => [self::EN_COURS, self::ANNULEE],
        self::EN_COURS => [self::TERMINEE],
        self::INTERROMPUE => [],
        self::TERMINEE => [],
        self::ANNULEE => [],
    ];

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** @return list<string> états accessibles depuis $from */
    public static function nextSteps(string $from): array
    {
        return self::TRANSITIONS[$from] ?? [];
    }

    /** Libellé du bouton qui mène à l'état $to. */
    public static function actionLabel(string $to): string
    {
        return match ($to) {
            self::PRETE => 'Marquer prête',
            self::EN_COURS => 'Démarrer la partie',
            self::TERMINEE => 'Terminer la partie',
            self::ANNULEE => 'Annuler la session',
            default => $to,
        };
    }
}

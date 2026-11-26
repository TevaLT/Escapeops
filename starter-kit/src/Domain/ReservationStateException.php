<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/**
 * La réservation ne peut pas faire ce qui est demandé dans son état actuel (→ 409 Conflict) :
 * option expirée (E1, US-33), déjà confirmée, annulée, session qui n'est plus réservable,
 * ou ligne modifiée par une autre requête entre la lecture et l'écriture (CONFLIT).
 */
final class ReservationStateException extends RuntimeException
{
    /** La ligne a changé entre la lecture et l'écriture conditionnelle (0 ligne modifiée). */
    public const CONFLIT = 'CONFLIT';

    public function __construct(public readonly string $state, string $message)
    {
        parent::__construct($message);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/**
 * La réservation ne peut pas faire ce qui est demandé dans son état actuel (→ 409 Conflict) :
 * option expirée (E1, US-33), déjà confirmée, annulée, ou session qui n'est plus réservable.
 */
final class ReservationStateException extends RuntimeException
{
    public function __construct(public readonly string $state, string $message)
    {
        parent::__construct($message);
    }
}

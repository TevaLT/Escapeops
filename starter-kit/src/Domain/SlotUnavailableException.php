<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/**
 * Le créneau ne peut pas être réservé : passé, annulé, déjà confirmé ou en option (→ 409 Conflict).
 */
final class SlotUnavailableException extends RuntimeException
{
    public function __construct(public readonly string $availability, string $message)
    {
        parent::__construct($message);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/**
 * Données refusées par une règle métier (→ 422, formulaire réaffiché).
 * Les erreurs sont indexées par champ, pour être affichées à côté du bon champ.
 */
final class ValidationException extends RuntimeException
{
    /** @param array<string, string> $errors */
    public function __construct(private array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }
}

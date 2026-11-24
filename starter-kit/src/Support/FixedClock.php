<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

/**
 * Horloge figée pour les tests et la démonstration
 * (ex. simuler « 15 minutes plus tard » pour la règle E1).
 */
final class FixedClock implements Clock
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $interval): void
    {
        $this->now = $this->now->modify($interval);
    }
}

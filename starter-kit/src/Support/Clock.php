<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

/**
 * Source unique de « maintenant ».
 * Les règles temporelles (E1 : option de 15 min, E5 : chevauchements) l'utilisent
 * au lieu de new DateTime() : un test peut ainsi fixer l'heure sans attendre.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}

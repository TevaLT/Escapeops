<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/** Levée par un service quand l'utilisateur n'a pas le droit d'agir (→ 403). */
final class AccessDeniedException extends RuntimeException
{
}

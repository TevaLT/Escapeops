<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/** Ressource inconnue (→ 404). */
final class NotFoundException extends RuntimeException
{
}

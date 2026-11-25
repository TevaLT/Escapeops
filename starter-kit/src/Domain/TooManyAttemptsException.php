<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/**
 * Trop de tentatives d'accès en peu de temps (→ 429 Too Many Requests).
 */
final class TooManyAttemptsException extends RuntimeException
{
}

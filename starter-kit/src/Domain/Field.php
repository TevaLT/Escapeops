<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * Lecture stricte des valeurs reçues d'un formulaire.
 * Une valeur du mauvais type (un tableau, par exemple) est traitée comme absente : jamais de plantage.
 */
final class Field
{
    /** Entier strictement positif, ou null. Accepte un int ou une chaîne de chiffres. */
    public static function positiveInt(mixed $value): ?int
    {
        $int = self::int($value);

        return $int !== null && $int > 0 ? $int : null;
    }

    /** Entier compris entre $min et $max (inclus), ou null. */
    public static function intBetween(mixed $value, int $min, int $max): ?int
    {
        $int = self::int($value);

        return $int !== null && $int >= $min && $int <= $max ? $int : null;
    }

    /**
     * Texte sans espaces autour ; chaîne vide si la valeur n'est pas une chaîne
     * ou n'est pas de l'UTF-8 valide (sinon MySQL tronquerait le texte sans prévenir).
     */
    public static function text(mixed $value): string
    {
        return is_string($value) && mb_check_encoding($value, 'UTF-8') ? trim($value) : '';
    }

    /** Date au format AAAA-MM-JJ (celui d'un <input type="date">), ou null. */
    public static function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    /** Case à cocher : cochée si la valeur vaut « 1 » ou « on ». */
    public static function checked(mixed $value): bool
    {
        return $value === '1' || $value === 'on' || $value === true;
    }

    private static function int(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^[0-9]{1,9}$/', $value) === 1 ? (int) $value : null;
    }
}

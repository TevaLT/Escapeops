<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * « Mes réservations » : le client, sans compte, se reconnaît avec son contact (e-mail ou téléphone)
 * et le code de suivi reçu à la pose de son option.
 *
 * Le code fait 8 caractères pris dans un alphabet sans caractères ambigus (pas de 0/O, 1/I/L) :
 * 31^8 ≈ 850 milliards de combinaisons, et il ne sert qu'accompagné du bon contact.
 */
final class TrackingCode
{
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    public const LENGTH = 8;

    public static function generate(): string
    {
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }

    /** « mart-6789 », « MART 6789 » → « MART6789 » ; null si ce n'est pas un code possible. */
    public static function normalize(mixed $input): ?string
    {
        if (!is_string($input)) {
            return null;
        }

        $code = strtoupper(str_replace([' ', '-'], '', trim($input)));

        return preg_match('/^[' . self::ALPHABET . ']{' . self::LENGTH . '}$/', $code) === 1 ? $code : null;
    }

    /** « MART6789 » → « MART-6789 » (plus facile à recopier). */
    public static function format(string $code): string
    {
        return substr($code, 0, 4) . '-' . substr($code, 4);
    }

    /**
     * Forme comparable d'un contact : e-mail en minuscules ; téléphone réduit à ses chiffres (et au « + » initial),
     * pour que « 06 00 00 00 01 » et « 06.00.00.00.01 » désignent le même client.
     * La même transformation est écrite en SQL dans ReservationRepository::forContact.
     */
    public static function normalizeContact(mixed $input): string
    {
        $contact = Field::text($input);
        if (str_contains($contact, '@')) {
            return mb_strtolower($contact);
        }

        return str_replace([' ', '.', '-'], '', $contact);
    }
}

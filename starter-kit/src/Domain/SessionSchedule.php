<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * Règles de planification d'une session (US-22), sans base de données :
 *  - la salle est obligatoire ;
 *  - le début est une date valide, dans le futur ;
 *  - la fin est calculée (début + durée de la salle), donc toujours après le début.
 */
final class SessionSchedule
{
    /** Format envoyé par un champ <input type="datetime-local"> : 2026-11-24T18:00 */
    public const INPUT_FORMAT = 'Y-m-d\TH:i';

    public static function parseStart(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!' . self::INPUT_FORMAT, $value);

        // Le format relu doit être identique : « 2026-02-31T18:00 » ne devient pas le 3 mars.
        return $date !== false && $date->format(self::INPUT_FORMAT) === $value ? $date : null;
    }

    /**
     * @param array<string, mixed>|null $room ligne de la table salle (null si inconnue)
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable} début et fin
     * @throws ValidationException
     */
    public static function plan(?array $room, mixed $startInput, DateTimeImmutable $now): array
    {
        $errors = [];

        if ($room === null) {
            $errors['salle'] = 'Choisissez une salle existante.';
        }

        $start = self::parseStart($startInput);
        if ($start === null) {
            $errors['debut'] = 'Indiquez une date et une heure de début valides.';
        } elseif ($start <= $now) {
            $errors['debut'] = 'Le début de la session doit être dans le futur.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $end = $start->modify('+' . (int) $room['duree_minutes'] . ' minutes');

        // Invariant fin > début (aussi garanti par la contrainte ck_session_dates en base).
        if ($end <= $start) {
            throw new ValidationException(['debut' => 'La fin de la session doit être après son début.']);
        }

        return [$start, $end];
    }

    /**
     * Période pendant laquelle aucune autre session de la même salle ne peut avoir lieu :
     * la session elle-même, élargie du temps de remise en état avant et après.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    public static function blockedWindow(DateTimeImmutable $start, DateTimeImmutable $end, int $cleanupMinutes): array
    {
        $cleanupMinutes = max(0, $cleanupMinutes);

        return [
            $start->modify("-{$cleanupMinutes} minutes"),
            $end->modify("+{$cleanupMinutes} minutes"),
        ];
    }

    /**
     * E5 (US-36) : deux créneaux se chevauchent si a.début < b.fin ET b.début < a.fin.
     * Inégalités strictes : 18 h–19 h puis 19 h–20 h (créneaux jointifs) ne se chevauchent pas.
     * La même condition est écrite en SQL dans SessionRepository::findGameMasterConflict.
     */
    public static function overlaps(
        DateTimeImmutable $aStart,
        DateTimeImmutable $aEnd,
        DateTimeImmutable $bStart,
        DateTimeImmutable $bEnd
    ): bool {
        return $aStart < $bEnd && $bStart < $aEnd;
    }
}

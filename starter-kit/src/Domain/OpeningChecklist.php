<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * E10 — « Checklist avant ouverture » (US-39, US-40).
 *
 * Trois points à valider par le game master : salle rangée, matériel testé, consignes de sécurité
 * vérifiées. Une session ne passe à PRETE que si la dernière checklist enregistrée est complète (3/3).
 */
final class OpeningChecklist
{
    /** Champ du formulaire / colonne SQL => libellé. */
    public const POINTS = [
        'salle_rangee' => 'Salle rangée',
        'materiel_teste' => 'Matériel testé',
        'securite_verifiee' => 'Consignes de sécurité vérifiées',
    ];

    /**
     * Lit les cases envoyées. Une case absente, ou une valeur autre que « 1 » / « on », vaut « non » :
     * un POST forgé ne peut pas valider un point sans le cocher réellement.
     *
     * @param array<string, mixed> $input
     * @return array<string, bool>
     */
    public static function fromInput(array $input): array
    {
        $values = [];
        foreach (array_keys(self::POINTS) as $point) {
            $values[$point] = Field::checked($input[$point] ?? null);
        }

        return $values;
    }

    /** Nombre de points validés (0 à 3) d'une checklist (saisie ou ligne SQL). */
    public static function score(?array $checklist): int
    {
        return count(self::POINTS) - count(self::missing($checklist));
    }

    public static function isComplete(?array $checklist): bool
    {
        return self::score($checklist) === count(self::POINTS);
    }

    /**
     * Libellés des points non validés (tous si aucune checklist). Accepte une saisie (bool)
     * comme une ligne SQL (1 / « 1 »).
     *
     * @return list<string>
     */
    public static function missing(?array $checklist): array
    {
        $missing = [];
        foreach (self::POINTS as $point => $label) {
            if ((int) ($checklist[$point] ?? 0) !== 1) {
                $missing[] = $label;
            }
        }

        return $missing;
    }
}

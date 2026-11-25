<?php

declare(strict_types=1);

/**
 * Tests sans BDD de la disponibilité d'un créneau (invariants du socle + base de E1).
 * Lancement : composer test
 */

use App\Domain\SlotAvailability;
use App\Support\FixedClock;

require dirname(__DIR__) . '/vendor/autoload.php';

$failures = 0;

function check(string $label, string $expected, string $actual): void
{
    global $failures;
    if ($expected === $actual) {
        echo "✔ {$label}" . PHP_EOL;
        return;
    }
    $failures++;
    echo "✘ {$label} : attendu {$expected}, obtenu {$actual}" . PHP_EOL;
}

function slot(array $overrides = []): array
{
    return $overrides + [
        'date_debut' => '2026-11-23 18:00:00',
        'statut' => 'PREVUE',
        'nb_confirmees' => 0,
        'option_expire_le' => null,
    ];
}

$clock = new FixedClock(new DateTimeImmutable('2026-11-23 17:00:00'));

check('créneau futur sans réservation : libre', SlotAvailability::LIBRE, SlotAvailability::of(slot(), $clock->now()));
check('créneau déjà confirmé : complet', SlotAvailability::COMPLET, SlotAvailability::of(slot(['nb_confirmees' => '1']), $clock->now()));
check('session annulée : fermée', SlotAvailability::FERME, SlotAvailability::of(slot(['statut' => 'ANNULEE']), $clock->now()));
check('session passée : fermée', SlotAvailability::FERME, SlotAvailability::of(slot(['date_debut' => '2026-11-23 16:00:00']), $clock->now()));

// E1 : option posée à 17 h 00, valable jusqu'à 17 h 15
$option = slot(['option_expire_le' => '2026-11-23 17:15:00']);
check('E1 option active : bloque le créneau', SlotAvailability::EN_OPTION, SlotAvailability::of($option, $clock->now()));

$clock->advance('+15 minutes');
check('E1 option à l\'instant exact d\'expiration : libre', SlotAvailability::LIBRE, SlotAvailability::of($option, $clock->now()));

$clock->advance('+1 minute');
check('E1 option expirée : libre', SlotAvailability::LIBRE, SlotAvailability::of($option, $clock->now()));

if ($failures > 0) {
    echo PHP_EOL . "{$failures} test(s) en échec." . PHP_EOL;
    exit(1);
}

echo PHP_EOL . 'Tous les tests de disponibilité passent.' . PHP_EOL;

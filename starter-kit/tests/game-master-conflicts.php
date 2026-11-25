<?php

declare(strict_types=1);

/**
 * E5 — « Un game master ne peut pas se dédoubler » (US-35 à US-38).
 * Lancement : composer test
 */

use App\Domain\SessionSchedule;
use App\Support\FixedClock;

require __DIR__ . '/support/bootstrap.php';

['responsable' => $responsable, 'camille' => $camille] = testUsers();
$database = testDatabase();
$database->insert('salle', ['id_salle' => 3, 'nom' => 'La Crypte du Scribe', 'theme' => 'Fantastique', 'duree_minutes' => 75, 'capacite_min' => 2, 'capacite_max' => 5, 'temps_remise_etat' => 20]);
$service = sessionService($database, new FixedClock(new DateTimeImmutable('2026-11-24 09:00:00')));
$gmOf = static fn (int $id): ?int => ($v = $database->get('session', 'id_game_master', ['id_session' => $id])) === null ? null : (int) $v;
$status = static fn (int $id): string => (string) $database->get('session', 'statut', ['id_session' => $id]);

// --- Règle pure : a.début < b.fin ET b.début < a.fin -----------------------------------
$at = static fn (string $time): DateTimeImmutable => new DateTimeImmutable('2026-11-24 ' . $time);
check('US-38 18 h–19 h et 18 h 30–19 h 45 se chevauchent', true, SessionSchedule::overlaps($at('18:00'), $at('19:00'), $at('18:30'), $at('19:45')));
check('US-38 créneaux jointifs 18 h–19 h puis 19 h–20 h : pas de chevauchement', false, SessionSchedule::overlaps($at('18:00'), $at('19:00'), $at('19:00'), $at('20:00')));
check('US-38 jointifs dans l\'autre sens (17 h–18 h)', false, SessionSchedule::overlaps($at('18:00'), $at('19:00'), $at('17:00'), $at('18:00')));
check('US-38 créneau inclus dans un autre', true, SessionSchedule::overlaps($at('18:00'), $at('20:00'), $at('18:30'), $at('19:00')));
check('US-38 créneaux distincts', false, SessionSchedule::overlaps($at('14:00'), $at('15:00'), $at('18:00'), $at('19:00')));

// --- US-35 : une session peut rester sans game master ----------------------------------
$noGm = $service->create($responsable, ['salle' => '2', 'debut' => '2026-11-24T14:00', 'game_master' => '']);
check('US-35 session créée sans game master', null, $gmOf($noGm));
check('US-35 elle reste PREVUE', 'PREVUE', $status($noGm));

// --- US-35 : affecter un game master ---------------------------------------------------
$salleA = $service->create($responsable, ['salle' => '1', 'debut' => '2026-11-24T18:00', 'game_master' => '3']);
check('US-35 Camille affectée à 18 h en salle 1', 3, $gmOf($salleA));
check('US-35 game master inconnu refusé', ['game_master'], errorFields(fn () => $service->create($responsable, ['salle' => '2', 'debut' => '2026-11-24T10:00', 'game_master' => '99'])));
check('US-35 un responsable n\'est pas un game master', ['game_master'], errorFields(fn () => $service->create($responsable, ['salle' => '2', 'debut' => '2026-11-24T10:00', 'game_master' => '2'])));
check('US-35 valeur forgée (tableau) refusée', ['game_master'], errorFields(fn () => $service->create($responsable, ['salle' => '2', 'debut' => '2026-11-24T10:00', 'game_master' => ['3']])));

// --- US-36 : conflit refusé, créneaux jointifs acceptés --------------------------------
$before = (int) $database->count('session');
check('US-36 Camille à 18 h 30 en salle 3 (chevauche 18 h–19 h) : refus', ['game_master'], errorFields(fn () => $service->create($responsable, ['salle' => '3', 'debut' => '2026-11-24T18:30', 'game_master' => '3'])));
check('US-36 même heure exacte dans une autre salle : refus', ['game_master'], errorFields(fn () => $service->create($responsable, ['salle' => '2', 'debut' => '2026-11-24T18:00', 'game_master' => '3'])));
check('US-36 rien n\'est enregistré après les refus', $before, (int) $database->count('session'));
check('US-36 message qui nomme la session en conflit', true, str_contains(
    errorMessage(fn () => $service->create($responsable, ['salle' => '3', 'debut' => '2026-11-24T18:30', 'game_master' => '3']), 'game_master'),
    'Camille Leroy anime déjà « Le Cabinet des Ombres »'
));
check('US-36 même créneau sans game master : accepté (seul le GM pose problème)', 'OK', outcome(fn () => $service->create($responsable, ['salle' => '3', 'debut' => '2026-11-24T18:30', 'game_master' => ''])));
check('US-36 créneau jointif 19 h–20 h en salle 2 : accepté', 'OK', outcome(fn () => $service->create($responsable, ['salle' => '2', 'debut' => '2026-11-24T19:00', 'game_master' => '3'])));
check('US-36 créneau jointif avant (17 h–18 h) : accepté', 'OK', outcome(fn () => $service->create($responsable, ['salle' => '2', 'debut' => '2026-11-24T17:00', 'game_master' => '3'])));
check('US-36 un autre game master sur le même créneau : accepté', 'OK', outcome(fn () => $service->create($responsable, ['salle' => '2', 'debut' => '2026-11-24T21:00', 'game_master' => '4'])));

// Modification : la session ne se gêne pas elle-même, mais un déplacement qui crée un conflit est refusé
check('US-36 modifier une session sans changer d\'horaire : pas de conflit avec elle-même', 'OK', outcome(fn () => $service->update($responsable, $salleA, ['salle' => '1', 'debut' => '2026-11-24T18:00', 'game_master' => '3'])));
$service->create($responsable, ['salle' => '1', 'debut' => '2026-11-25T10:00', 'game_master' => '3']);
check('US-36 déplacer une session sur un créneau où Camille anime déjà : refus', ['game_master'], errorFields(fn () => $service->update($responsable, $noGm, ['salle' => '2', 'debut' => '2026-11-25T10:30', 'game_master' => '3'])));
check('US-36 le même déplacement sans game master : accepté', 'OK', outcome(fn () => $service->update($responsable, $noGm, ['salle' => '2', 'debut' => '2026-11-25T10:30', 'game_master' => ''])));

// Une session annulée ne bloque plus le game master
$repository = new App\Repository\SessionRepository($database);
check('US-36 avant annulation : Camille occupée de 18 h à 19 h', true, $repository->findGameMasterConflict(3, $at('18:00'), $at('19:00'), null) !== null);
$database->update('session', ['statut' => 'ANNULEE'], ['id_session' => $salleA]);
check('US-36 session annulée : Camille redevient disponible de 18 h à 19 h', null, $repository->findGameMasterConflict(3, $at('18:00'), $at('19:00'), null));

// --- US-37 : pas de démarrage sans game master ----------------------------------------
$orphan = addSession($database, 1, '2026-11-26 10:00:00', '2026-11-26 11:00:00', 'PRETE');
check('US-37 démarrage sans game master refusé', ['game_master'], errorFields(fn () => $service->changeStatus($responsable, $orphan, 'EN_COURS')));
check('US-37 message « aucun game master affecté »', true, str_contains(
    errorMessage(fn () => $service->changeStatus($responsable, $orphan, 'EN_COURS'), 'game_master'),
    'aucun game master affecté'
));
check('US-37 la session reste PRETE', 'PRETE', $status($orphan));
$staffed = addSession($database, 2, '2026-11-26 10:00:00', '2026-11-26 11:00:00', 'PRETE', 3);

// --- Affectation depuis la fiche (assignGameMaster) -----------------------------------
check('US-35 fiche : Camille déjà prise à 10 h le 26 → affectation à la salle 1 refusée', ['game_master'], errorFields(fn () => $service->assignGameMaster($responsable, $orphan, '3')));
check('US-35 fiche : David affecté à la session prête sans GM', 'OK', outcome(fn () => $service->assignGameMaster($responsable, $orphan, '4')));
check('US-35 fiche : affectation enregistrée', 4, $gmOf($orphan));
check('US-35 fiche : game master retiré (valeur vide)', 'OK', outcome(fn () => $service->assignGameMaster($responsable, $orphan, '')));
check('EV-TEV-01 GM retiré d\'une session prête : elle repasse à PREVUE', 'PREVUE', $status($orphan));
check('US-37 GM retiré : démarrage (POST forgé) refusé avec le motif E5', ['game_master'], errorFields(fn () => $service->changeStatus($responsable, $orphan, 'EN_COURS')));
check('US-13 fiche : un game master ne peut pas affecter', 'AccessDeniedException', outcome(fn () => $service->assignGameMaster($camille, $orphan, '3')));
check('US-35 fiche : valeur forgée (tableau) refusée', ['game_master'], errorFields(fn () => $service->assignGameMaster($responsable, $orphan, ['4'])));
check('US-35 fiche : session inconnue', 'NotFoundException', outcome(fn () => $service->assignGameMaster($responsable, 999, '4')));

check('US-37 démarrage avec game master accepté', 'OK', outcome(fn () => $service->changeStatus($camille, $staffed, 'EN_COURS')));
check('US-35 fiche : plus de changement de GM une fois la partie commencée', ['game_master'], errorFields(fn () => $service->assignGameMaster($responsable, $staffed, '4')));

finish('E5 (game master unique)');

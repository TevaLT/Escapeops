<?php

declare(strict_types=1);

/**
 * Planning des sessions (US-22), filtres (US-29), cycle d'états (US-27)
 * et contrôle de droit dans le service (US-13).
 * Lancement : composer test
 */

use App\Domain\SessionSchedule;
use App\Domain\SessionStatus;
use App\Repository\ReservationRepository;
use App\Repository\RoomRepository;
use App\Repository\SessionRepository;
use App\Service\SessionService;
use App\Support\FixedClock;

require __DIR__ . '/support/bootstrap.php';

$now = new DateTimeImmutable('2026-11-24 12:00:00');
$room = ['id_salle' => 1, 'nom' => 'Le Cabinet des Ombres', 'duree_minutes' => 60, 'temps_remise_etat' => 20];

// --- Domaine : SessionSchedule (sans base) -----------------------------------------
check('début au format datetime-local accepté', '2026-11-24 18:00', SessionSchedule::parseStart('2026-11-24T18:00')?->format('Y-m-d H:i'));
check('date impossible (31 février) refusée', null, SessionSchedule::parseStart('2026-02-31T18:00'));
check('texte libre refusé', null, SessionSchedule::parseStart('demain soir'));
check('tableau refusé sans erreur', null, SessionSchedule::parseStart(['2026-11-24T18:00']));

[$start, $end] = SessionSchedule::plan($room, '2026-11-24T18:00', $now);
check('fin calculée = début + durée de la salle', '19:00', $end->format('H:i'));
check('invariant fin > début', true, $end > $start);
check('salle inconnue : erreur sur le champ salle', ['salle'], errorFields(fn () => SessionSchedule::plan(null, '2026-11-24T18:00', $now)));
check('début dans le passé refusé', ['debut'], errorFields(fn () => SessionSchedule::plan($room, '2026-11-24T11:00', $now)));
check('début à l\'instant présent refusé', ['debut'], errorFields(fn () => SessionSchedule::plan($room, '2026-11-24T12:00', $now)));
check('salle et début absents : deux erreurs', ['salle', 'debut'], errorFields(fn () => SessionSchedule::plan(null, '', $now)));

// --- Domaine : cycle d'états (US-27) -----------------------------------------------
check('US-27 PREVUE → PRETE autorisé', true, SessionStatus::canTransition('PREVUE', 'PRETE'));
check('US-27 PRETE → EN_COURS autorisé', true, SessionStatus::canTransition('PRETE', 'EN_COURS'));
check('US-27 EN_COURS → TERMINEE autorisé', true, SessionStatus::canTransition('EN_COURS', 'TERMINEE'));
check('US-27 PREVUE → EN_COURS interdit (il faut passer par PRETE)', false, SessionStatus::canTransition('PREVUE', 'EN_COURS'));
check('US-27 EN_COURS → ANNULEE interdit', false, SessionStatus::canTransition('EN_COURS', 'ANNULEE'));
check('US-27 TERMINEE → PREVUE interdit', false, SessionStatus::canTransition('TERMINEE', 'PREVUE'));
check('US-27 ANNULEE : plus aucun changement', [], SessionStatus::nextSteps('ANNULEE'));
check('US-27 état inconnu : rien d\'autorisé', false, SessionStatus::canTransition('PIRATE', 'TERMINEE'));

// --- Service sur base SQLite --------------------------------------------------------
['admin' => $admin, 'responsable' => $responsable, 'camille' => $camille, 'david' => $david] = testUsers();
$database = testDatabase();
$service = sessionService($database, new FixedClock($now));
$count = static fn (): int => $database->count('session');

// US-13 : le service refuse, même appelé directement (sans middleware de route)
check('US-13 game master : création refusée par le service', 'AccessDeniedException', outcome(fn () => $service->create($camille, ['salle' => '1', 'debut' => '2026-11-24T18:00'])));
check('US-13 anonyme : création refusée par le service', 'AccessDeniedException', outcome(fn () => $service->create(null, ['salle' => '1', 'debut' => '2026-11-24T18:00'])));
check('US-13 aucune session enregistrée après les refus', 0, $count());

// US-22 création
$id = $service->create($responsable, ['salle' => '1', 'debut' => '2026-11-24T18:00']);
$created = $service->find($id);
check('US-22 responsable : session créée', 1, $count());
check('US-22 session créée à l\'état PREVUE', 'PREVUE', $created['statut']);
check('US-22 fin enregistrée = 19 h 00', '2026-11-24 19:00:00', $created['date_fin']);
check('US-22 admin : création acceptée', 'OK', outcome(fn () => $service->create($admin, ['salle' => '2', 'debut' => '2026-11-25T10:00'])));
check('US-22 salle inexistante refusée', ['salle'], errorFields(fn () => $service->create($responsable, ['salle' => '99', 'debut' => '2026-11-24T21:00'])));
check('US-22 identifiant de salle non numérique refusé', ['salle'], errorFields(fn () => $service->create($responsable, ['salle' => '1 OR 1=1', 'debut' => '2026-11-24T21:00'])));

// Une salle, une partie à la fois (remise en état de 20 min dans la salle 1)
check('US-22 chevauchement dans la même salle refusé', ['debut'], errorFields(fn () => $service->create($responsable, ['salle' => '1', 'debut' => '2026-11-24T18:30'])));
check('US-22 pendant la remise en état (19 h 10) refusé', ['debut'], errorFields(fn () => $service->create($responsable, ['salle' => '1', 'debut' => '2026-11-24T19:10'])));
check('US-22 juste après la remise en état (19 h 20) accepté', 'OK', outcome(fn () => $service->create($responsable, ['salle' => '1', 'debut' => '2026-11-24T19:20'])));
check('US-22 avant la session, remise en état comprise (16 h 40 → 17 h 40) accepté', 'OK', outcome(fn () => $service->create($responsable, ['salle' => '1', 'debut' => '2026-11-24T16:40'])));
check('US-22 même horaire dans une autre salle accepté', 'OK', outcome(fn () => $service->create($responsable, ['salle' => '2', 'debut' => '2026-11-24T18:00'])));

$database->update('session', ['statut' => 'ANNULEE'], ['id_session' => $id]);
check('US-22 une session annulée libère la salle', 'OK', outcome(fn () => $service->create($responsable, ['salle' => '1', 'debut' => '2026-11-24T18:00'])));

// US-22 modification
$movable = $service->create($responsable, ['salle' => '2', 'debut' => '2026-11-26T14:00']);
check('US-22 modification sur le même créneau : pas de conflit avec elle-même', 'OK', outcome(fn () => $service->update($responsable, $movable, ['salle' => '2', 'debut' => '2026-11-26T14:00'])));
$service->update($responsable, $movable, ['salle' => '1', 'debut' => '2026-11-26T15:00']);
check('US-22 modification : salle et fin recalculées', ['1', '2026-11-26 16:00:00'], [(string) $service->find($movable)['id_salle'], $service->find($movable)['date_fin']]);
check('US-13 game master : modification refusée par le service', 'AccessDeniedException', outcome(fn () => $service->update($camille, $movable, ['salle' => '1', 'debut' => '2026-11-26T17:00'])));
check('US-22 modification d\'une session inconnue : introuvable', 'NotFoundException', outcome(fn () => $service->update($responsable, 999, ['salle' => '1', 'debut' => '2026-11-26T17:00'])));

$database->update('session', ['statut' => 'TERMINEE'], ['id_session' => $movable]);
check('US-22 session non « prévue » : modification refusée', ['statut'], errorFields(fn () => $service->update($responsable, $movable, ['salle' => '1', 'debut' => '2026-11-26T17:00'])));

// --- US-29 : filtres -----------------------------------------------------------------
$database2 = testDatabase();
$filters = sessionService($database2, new FixedClock($now));
addSession($database2, 1, '2026-11-23 18:00:00', '2026-11-23 19:00:00', 'TERMINEE');   // hier
addSession($database2, 1, '2026-11-24 18:00:00', '2026-11-24 19:00:00');               // aujourd'hui
addSession($database2, 2, '2026-11-24 18:00:00', '2026-11-24 19:00:00', 'ANNULEE');
addSession($database2, 2, '2026-11-25 10:00:00', '2026-11-25 11:00:00', 'PRETE');      // demain
$ids = static fn (array $planning): array => array_map('intval', array_column($planning['sessions'], 'id_session'));

check('US-29 sans filtre : aujourd\'hui et à venir (hier exclu)', [2, 3, 4], $ids($filters->planning()));
check('US-29 filtre salle', [3, 4], $ids($filters->planning(['salle' => '2'])));
check('US-29 filtre date : uniquement ce jour-là', [1], $ids($filters->planning(['date' => '2026-11-23'])));
check('US-29 filtre état', [4], $ids($filters->planning(['etat' => 'PRETE'])));
check('US-29 filtres combinés : aucun résultat', [], $ids($filters->planning(['salle' => '1', 'etat' => 'PRETE'])));
check('US-29 filtre invalide ignoré (état inconnu, date mal formée)', [2, 3, 4], $ids($filters->planning(['etat' => 'PIRATE', 'date' => '24/11/2026'])));
check('US-29 filtres renvoyés pour le formulaire', ['salle' => '2', 'date' => '', 'etat' => 'PRETE'], $filters->planning(['salle' => '2', 'etat' => 'PRETE'])['filtres']);

// --- US-27 : changements d'état dans le service --------------------------------------
$database3 = testDatabase();
$workflow = sessionService($database3, new FixedClock($now));
$withCamille = addSession($database3, 1, '2026-11-24 18:00:00', '2026-11-24 19:00:00', 'PREVUE', 3);
$status = static fn (int $id): string => (string) $database3->get('session', 'statut', ['id_session' => $id]);

check('US-27 game master d\'une autre session : refus', 'AccessDeniedException', outcome(fn () => $workflow->changeStatus($david, $withCamille, 'PRETE')));
check('US-27 game master affecté : PREVUE → PRETE (par la checklist 3/3, E10)', 'OK', outcome(fn () => $workflow->submitChecklist($camille, $withCamille, ['salle_rangee' => '1', 'materiel_teste' => '1', 'securite_verifiee' => '1'])));
check('US-27 session passée à PRETE', 'PRETE', $status($withCamille));
check('US-27 game master affecté : PRETE → EN_COURS', 'OK', outcome(fn () => $workflow->changeStatus($camille, $withCamille, 'EN_COURS')));
check('US-27 EN_COURS → PRETE refusé par le service', ['statut'], errorFields(fn () => $workflow->changeStatus($camille, $withCamille, 'PRETE')));
check('US-27 game master affecté : EN_COURS → TERMINEE', 'OK', outcome(fn () => $workflow->changeStatus($camille, $withCamille, 'TERMINEE')));
check('US-27 état final enregistré', 'TERMINEE', $status($withCamille));
check('US-27 valeur d\'état forgée (tableau) refusée', ['statut'], errorFields(fn () => $workflow->changeStatus($responsable, $withCamille, ['EN_COURS'])));

$booked = addSession($database3, 2, '2026-11-25 18:00:00', '2026-11-25 19:00:00', 'PREVUE', 3);
$database3->insert('reservation', ['id_session' => $booked, 'nom_groupe' => 'Les Curieux', 'contact' => 'c@example.test', 'nb_joueurs' => 4, 'statut' => 'CONFIRMEE']);
check('US-27 game master affecté ne peut pas annuler', 'AccessDeniedException', outcome(fn () => $workflow->changeStatus($camille, $booked, 'ANNULEE')));
check('US-27 responsable annule', 'OK', outcome(fn () => $workflow->changeStatus($responsable, $booked, 'ANNULEE')));
check('US-27 annulation : groupe confirmé gardé et « à recontacter »', ['CONFIRMEE', 1], [
    $database3->get('reservation', 'statut', ['id_session' => $booked]),
    (int) $database3->get('reservation', 'a_recontacter', ['id_session' => $booked]),
]);
check('US-27 session inconnue : introuvable', 'NotFoundException', outcome(fn () => $workflow->changeStatus($responsable, 999, 'PRETE')));
check('US-27 boutons proposés à un game master non affecté : aucun', [], $workflow->allowedTransitions($david, ['statut' => 'PREVUE', 'id_game_master' => 3]));
check('US-27 boutons du game master affecté sur une session prévue : aucun (la checklist remplace « Marquer prête »)', [], $workflow->allowedTransitions($camille, ['statut' => 'PREVUE', 'id_game_master' => 3]));
check('US-27 boutons du game master affecté sur une session prête : démarrer (sans « Annuler »)', ['EN_COURS'], $workflow->allowedTransitions($camille, ['statut' => 'PRETE', 'id_game_master' => 3]));
check('US-27 boutons proposés au responsable', ['ANNULEE'], $workflow->allowedTransitions($responsable, ['statut' => 'PREVUE', 'id_game_master' => null]));

// --- E1 dans le planning : l'option en cours est visible, l'option expirée ne l'est plus ---
$database4 = testDatabase();
$clock4 = new FixedClock($now);
$planning4 = sessionService($database4, $clock4);
$optioned = addSession($database4, 1, '2026-11-24 18:00:00', '2026-11-24 19:00:00');
$database4->insert('reservation', [
    'id_session' => $optioned, 'nom_groupe' => 'Famille Martin', 'contact' => 'martin@example.test', 'nb_joueurs' => 4,
    'statut' => 'EN_ATTENTE', 'date_expiration' => $now->modify('+10 minutes')->format('Y-m-d H:i:s'),
]);
$row = static fn (): array => $planning4->planning()['sessions'][0];
check('E1 planning : option en cours visible (groupe et échéance)', [true, 'Famille Martin', null], [$row()['option_active'], $row()['option_groupe'], $row()['groupe']]);
check('E1 tableau de bord : même information dans les prochaines sessions', true, $planning4->upcoming(5)[0]['option_active']);
$clock4->advance('+10 minutes');
check('E1 planning : option expirée, plus affichée comme active', false, $row()['option_active']);

finish('du planning');

<?php

declare(strict_types=1);

/**
 * « Mes réservations » : le client, sans compte, retrouve ses réservations avec son contact et son code de suivi.
 * Lancement : composer test
 */

use App\Domain\ClientHistory;
use App\Domain\TrackingCode;
use App\Repository\ReservationRepository;
use App\Repository\SessionRepository;
use App\Security\ArraySession;
use App\Security\Auth;
use App\Security\ClientAccess;
use App\Service\ClientAccessService;
use App\Service\ReservationService;
use App\Support\FixedClock;

require __DIR__ . '/support/bootstrap.php';

$database = testDatabase();
$clock = new FixedClock(new DateTimeImmutable('2026-11-25 12:00:00'));
$reservations = new ReservationRepository($database);
$booking = new ReservationService(new SessionRepository($database), $reservations, $clock);
$session = new ArraySession();
$service = new ClientAccessService($reservations, new ClientAccess($session), $clock);
$ids = static fn (?array $mine): array => $mine === null ? [] : array_column($mine['reservations'], 'nom_groupe');

// --- Règles pures ---------------------------------------------------------------------
$generated = TrackingCode::generate();
check('Code généré : 8 caractères sans 0/O/1/I/L', 1, preg_match('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/', $generated));
check('Code saisi en minuscules avec tiret accepté', 'MART6789', TrackingCode::normalize(' mart-6789 '));
check('Code impossible (caractère ambigu O) refusé', null, TrackingCode::normalize('MART0789'));
check('Code affiché avec un tiret', 'MART-6789', TrackingCode::format('MART6789'));
check('Téléphone : espaces, points et tirets ignorés', ['0600000001', '0600000001'], [TrackingCode::normalizeContact('06 00 00 00 01'), TrackingCode::normalizeContact('06.00.00-00.01')]);
check('E-mail : casse ignorée, points gardés', 'jean.dupont@example.test', TrackingCode::normalizeContact(' Jean.Dupont@Example.test '));

// --- Le code est remis à la pose de l'option -----------------------------------------
$s1 = addSession($database, 1, '2026-11-26 18:00:00', '2026-11-26 19:00:00');
$s2 = addSession($database, 2, '2026-11-27 18:00:00', '2026-11-27 19:00:00');
$s3 = addSession($database, 1, '2026-11-28 18:00:00', '2026-11-28 19:00:00');
$first = $booking->placeOption($s1, ['nom_groupe' => 'Les Curieux', 'contact' => 'Curieux@Example.test', 'nb_joueurs' => '4']);
$booking->placeOption($s2, ['nom_groupe' => 'Les Curieux (revanche)', 'contact' => 'curieux@example.test', 'nb_joueurs' => '4']);
$other = $booking->placeOption($s3, ['nom_groupe' => 'Autre groupe', 'contact' => '06 11 22 33 44', 'nb_joueurs' => '3']);
check('Option : code de suivi enregistré', $first['code_suivi'], (string) $database->get('reservation', 'code_suivi', ['jeton' => $first['jeton']]));
check('Option : code visible sur la page de suivi', $first['code_suivi'], $booking->byToken($first['jeton'])['code_suivi']);

// --- Accès refusé ---------------------------------------------------------------------
check('Sans accès : pas de réservations', null, $service->myReservations());
check('Bon code, mauvais contact : refusé', ['code'], errorFields(fn () => $service->login(['contact' => 'autre@example.test', 'code' => $first['code_suivi']])));
check('Bon contact, mauvais code : refusé', ['code'], errorFields(fn () => $service->login(['contact' => 'curieux@example.test', 'code' => 'ZZZZ2222'])));
check('Code d\'un autre client avec mon contact : refusé', ['code'], errorFields(fn () => $service->login(['contact' => 'curieux@example.test', 'code' => $other['code_suivi']])));
check('Toujours pas d\'accès', null, $service->myReservations());
// (session neuve : la précédente a déjà 3 échecs, la limite est à 5)
$probe = new ClientAccessService($reservations, new ClientAccess(new ArraySession()), $clock);
check('Même message dans tous les cas (on ne révèle pas si le contact existe)', true,
    errorMessage(fn () => $probe->login(['contact' => 'inconnu@example.test', 'code' => 'ZZZZ2222']), 'code')
    === errorMessage(fn () => $probe->login(['contact' => 'curieux@example.test', 'code' => 'ZZZZ2222']), 'code'));
check('Valeurs forgées (tableaux) refusées sans plantage', ['code'], errorFields(fn () => $probe->login(['contact' => ['x'], 'code' => ['y']])));

// --- Accès accordé --------------------------------------------------------------------
$fresh = new ClientAccessService($reservations, new ClientAccess(new ArraySession()), $clock);
check('Contact en majuscules et code avec tiret : accès accordé', 'OK', outcome(fn () => $fresh->login(['contact' => 'CURIEUX@example.test', 'code' => TrackingCode::format($first['code_suivi'])])));
check('Le client voit ses 2 réservations (même e-mail, casse différente), la plus lointaine d\'abord', ['Les Curieux (revanche)', 'Les Curieux'], $ids($fresh->myReservations()));
check('Il ne voit pas celles des autres clients', false, in_array('Autre groupe', $ids($fresh->myReservations()), true));
check('État de l\'option calculé', 'EN_ATTENTE', $fresh->myReservations()['reservations'][0]['etat']);
$clock->advance('+16 minutes');
check('Après 15 min, l\'option apparaît expirée', 'EXPIREE', $fresh->myReservations()['reservations'][0]['etat']);

$phone = new ClientAccessService($reservations, new ClientAccess(new ArraySession()), $clock);
check('Téléphone saisi autrement (points) : accès accordé', 'OK', outcome(fn () => $phone->login(['contact' => '06.11.22.33.44', 'code' => $other['code_suivi']])));
check('Il ne voit que sa réservation', ['Autre groupe'], $ids($phone->myReservations()));

// --- Séparé du personnel, et déconnexion ----------------------------------------------
$shared = new ArraySession();
$client = new ClientAccessService($reservations, new ClientAccess($shared), $clock);
$client->login(['contact' => 'curieux@example.test', 'code' => $first['code_suivi']]);
check('Un client reconnu n\'est pas un membre du personnel', null, (new Auth($shared))->user());
$client->logout();
check('Après « Quitter », plus d\'accès', null, $client->myReservations());

// --- Limitation des tentatives ---------------------------------------------------------
$attacker = new ClientAccessService($reservations, new ClientAccess(new ArraySession()), $clock);
for ($i = 0; $i < ClientAccessService::MAX_FAILURES; $i++) {
    errorFields(fn () => $attacker->login(['contact' => 'curieux@example.test', 'code' => 'ZZZZ222' . ($i + 2)]));
}
check('Après 5 échecs : bloqué, même avec le bon code', 'TooManyAttemptsException', outcome(fn () => $attacker->login(['contact' => 'curieux@example.test', 'code' => $first['code_suivi']])));
$clock->advance('+15 minutes 1 second');
check('Un quart d\'heure plus tard : de nouveau possible', 'OK', outcome(fn () => $attacker->login(['contact' => 'curieux@example.test', 'code' => $first['code_suivi']])));

// --- Historique des parties -------------------------------------------------------------
$db = testDatabase();
$histClock = new FixedClock(new DateTimeImmutable('2026-11-25 12:00:00'));
$repo = new ReservationRepository($db);
$add = static function (string $start, string $sessionStatus, string $resStatus, string $group, ?string $expires = null) use ($db): void {
    $session = addSession($db, 1, $start, (new DateTimeImmutable($start))->modify('+1 hour')->format('Y-m-d H:i:s'), $sessionStatus);
    $db->insert('reservation', [
        'id_session' => $session, 'nom_groupe' => $group, 'contact' => 'fidele@example.test', 'nb_joueurs' => 4,
        'statut' => $resStatus, 'date_expiration' => $expires, 'code_suivi' => 'FDEB2345',
    ]);
};
$add('2026-11-10 18:00:00', 'TERMINEE', 'CONFIRMEE', 'Partie 1');
$add('2026-11-18 18:00:00', 'TERMINEE', 'CONFIRMEE', 'Partie 2');
$add('2026-11-20 18:00:00', 'ANNULEE', 'CONFIRMEE', 'Session annulée');
$add('2026-11-22 18:00:00', 'PREVUE', 'ANNULEE', 'Annulée par le client');
$add('2026-11-24 18:00:00', 'PREVUE', 'EN_ATTENTE', 'Option oubliée', '2026-11-20 12:15:00');
$add('2026-11-25 11:30:00', 'EN_COURS', 'CONFIRMEE', 'En train de jouer');
$add('2026-11-30 18:00:00', 'PREVUE', 'CONFIRMEE', 'Prochaine');
$add('2026-11-27 18:00:00', 'PRETE', 'EN_ATTENTE', 'Option du moment', '2026-11-25 12:10:00');
$add('2026-12-05 18:00:00', 'ANNULEE', 'CONFIRMEE', 'Future annulée');

$history = new ClientAccessService($repo, new ClientAccess(new ArraySession()), $histClock);
$history->login(['contact' => 'fidele@example.test', 'code' => 'FDEB-2345']);
$mine = $history->myReservations();
check('À venir : options en cours et réservations confirmées, la plus proche d\'abord', ['Option du moment', 'Prochaine'], array_column($mine['a_venir'], 'nom_groupe'));
check('Historique : tout le reste, le plus récent d\'abord', ['Future annulée', 'En train de jouer', 'Option oubliée', 'Annulée par le client', 'Session annulée', 'Partie 2', 'Partie 1'], array_column($mine['historique'], 'nom_groupe'));
check('Résultat de chaque partie', [
    ClientHistory::SESSION_ANNULEE, ClientHistory::EN_COURS, ClientHistory::OPTION_EXPIREE, ClientHistory::RESERVATION_ANNULEE,
    ClientHistory::SESSION_ANNULEE, ClientHistory::JOUEE, ClientHistory::JOUEE,
], array_column($mine['historique'], 'resultat'));
check('Compteur : 2 parties jouées', 2, $mine['parties_jouees']);
check('Libellé lisible', 'Partie jouée', ClientHistory::label(ClientHistory::JOUEE));
check('Session déjà terminée mais datée dans le futur (horaire modifié) : historique, pas « à venir »', false,
    ClientHistory::isUpcoming(['etat' => 'CONFIRMEE', 'date_debut' => '2026-11-25 14:00:00', 'statut_session' => 'TERMINEE'], new DateTimeImmutable('2026-11-25 12:00:00')));
check('Confirmée mais jamais démarrée (session passée restée PREVUE) : non jouée', ClientHistory::NON_JOUEE, ClientHistory::outcome(['etat' => 'CONFIRMEE', 'statut_session' => 'PREVUE']));
$histClock->advance('+15 minutes');
check('L\'option du moment expire : elle passe dans l\'historique', ['Prochaine'], array_column($history->myReservations()['a_venir'], 'nom_groupe'));

finish('de l\'accès client « Mes réservations »');

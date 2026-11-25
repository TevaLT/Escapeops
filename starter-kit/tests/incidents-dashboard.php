<?php

declare(strict_types=1);

/**
 * Incidents (US-26), fiches game master (US-24) et tableau de bord (US-28).
 * Lancement : composer test
 */

use App\Repository\DashboardRepository;
use App\Repository\EquipmentRepository;
use App\Repository\IncidentRepository;
use App\Repository\ReservationRepository;
use App\Repository\RoomRepository;
use App\Repository\SessionRepository;
use App\Repository\UserRepository;
use App\Service\DashboardService;
use App\Service\GameMasterService;
use App\Service\IncidentService;
use App\Service\SessionService;
use App\Support\FixedClock;

require __DIR__ . '/support/bootstrap.php';

['responsable' => $responsable, 'camille' => $camille] = testUsers();
$database = testDatabase();
$clock = new FixedClock(new DateTimeImmutable('2026-11-24 12:00:00'));
$sessionsRepo = new SessionRepository($database);
$incidentsRepo = new IncidentRepository($database);
$incidents = new IncidentService($incidentsRepo, new RoomRepository($database), new EquipmentRepository($database), $sessionsRepo, $clock);

$inRoom1 = addSession($database, 1, '2026-11-24 18:00:00', '2026-11-24 19:00:00', 'PREVUE', 3);
$inRoom2 = addSession($database, 2, '2026-11-24 18:00:00', '2026-11-24 19:00:00');
$report = static fn (array $overrides = []): array => $overrides + [
    'salle' => '1', 'equipement' => '', 'session' => '', 'description' => 'La porte secrète reste bloquée.',
    'gravite' => 'CRITIQUE', 'est_bloquant' => '1',
];

// --- US-26 déclarer ------------------------------------------------------------------
check('US-26 anonyme : déclaration refusée', 'AccessDeniedException', outcome(fn () => $incidents->report(null, $report())));
$id = $incidents->report($camille, $report(['equipement' => '1', 'session' => (string) $inRoom1]));
$saved = $database->get('incident', '*', ['id_incident' => $id]);
check('US-26 game master : incident déclaré, ouvert', 'OUVERT', $saved['statut']);
check('US-26 déclarant = utilisateur connecté (pas le formulaire)', 3, (int) $saved['id_declarant']);
check('US-26 lié à la salle, à l\'équipement et à la session', [1, 1, $inRoom1], [(int) $saved['id_salle'], (int) $saved['id_equipement'], (int) $saved['id_session']]);
check('US-26 bloquant enregistré', 1, (int) $saved['est_bloquant']);
check('US-26 date de déclaration = horloge', '2026-11-24 12:00:00', $saved['date_declaration']);
check('US-26 sans équipement ni session : accepté', 'OK', outcome(fn () => $incidents->report($camille, $report(['gravite' => 'MINEURE', 'est_bloquant' => '']))));
check('US-26 salle manquante refusée', ['salle'], errorFields(fn () => $incidents->report($camille, $report(['salle' => '']))));
check('US-26 équipement absent de cette salle refusé', ['equipement'], errorFields(fn () => $incidents->report($camille, $report(['salle' => '2', 'equipement' => '1']))));
check('US-26 session d\'une autre salle refusée', ['session'], errorFields(fn () => $incidents->report($camille, $report(['session' => (string) $inRoom2]))));
check('US-26 gravité inconnue et description vide refusées', ['description', 'gravite'], errorFields(fn () => $incidents->report($camille, $report(['description' => '', 'gravite' => 'APOCALYPSE']))));

// --- US-26 résoudre ------------------------------------------------------------------
check('US-26 game master : résolution refusée', 'AccessDeniedException', outcome(fn () => $incidents->resolve($camille, $id)));
$clock->advance('+2 hours');
check('US-26 responsable : incident résolu', 'OK', outcome(fn () => $incidents->resolve($responsable, $id)));
check('US-26 statut et date de résolution', ['RESOLU', '2026-11-24 14:00:00'], [$database->get('incident', 'statut', ['id_incident' => $id]), $database->get('incident', 'date_resolution', ['id_incident' => $id])]);
check('US-26 résoudre deux fois refusé', ['statut'], errorFields(fn () => $incidents->resolve($responsable, $id)));
check('US-26 incident inconnu : introuvable', 'NotFoundException', outcome(fn () => $incidents->resolve($responsable, 999)));
check('US-26 liste : l\'incident ouvert d\'abord', ['OUVERT', 'RESOLU'], array_column($incidents->list(), 'statut'));

// --- US-24 fiches game master --------------------------------------------------------
$gms = new GameMasterService(new UserRepository($database), $sessionsRepo, $clock);
check('US-24 liste des game masters (rôle GAME_MASTER uniquement)', ['Leroy', 'Nguyen'], array_column($gms->list(), 'nom'));
check('US-24 sessions à venir de Camille', 1, $gms->list()[0]['nb_sessions']);
check('US-24 fiche : sessions de Camille', [$inRoom1], array_map('intval', array_column($gms->get(3)['sessions'], 'id_session')));
check('US-24 fiche d\'un compte qui n\'est pas game master : introuvable', null, $gms->get(2));

// --- US-28 tableau de bord -----------------------------------------------------------
$dashboard = new DashboardService(
    new DashboardRepository($database),
    $incidentsRepo,
    sessionService($database, $clock),
    $clock
);
$incidents->report($camille, $report(['salle' => '2', 'gravite' => 'CRITIQUE', 'est_bloquant' => '1']));
$overview = $dashboard->overview();
$state = static fn (string $name): string => array_column($overview['salles'], 'etat', 'nom')[$name];

check('US-28 prochaines sessions (non terminées, à venir)', [$inRoom1, $inRoom2], array_map('intval', array_column($overview['prochaines'], 'id_session')));
check('US-28 incidents ouverts uniquement', 2, count($overview['incidents']));
check('US-28 salle avec incident bloquant ouvert : bloquée', DashboardService::BLOQUEE, $state('Le Laboratoire Interdit'));
check('US-28 salle avec incident mineur seulement : disponible', DashboardService::DISPONIBLE, $state('Le Cabinet des Ombres'));
$database->update('session', ['statut' => 'EN_COURS'], ['id_session' => $inRoom1]);
check('US-28 partie en cours : salle occupée', DashboardService::EN_PARTIE, array_column($dashboard->overview()['salles'], 'etat', 'nom')['Le Cabinet des Ombres']);

finish('des incidents et du tableau de bord');

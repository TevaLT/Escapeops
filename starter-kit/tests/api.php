<?php

declare(strict_types=1);

/**
 * API JSON (US-51 à US-54) : vraie application Slim, vraies routes (routes/api.php), vrais services,
 * sur la base SQLite de test et une horloge figée. Chaque requête traverse le routage et les middlewares
 * comme en production : on vérifie les codes HTTP, les corps JSON et les règles E1 et de droits.
 * Lancement : composer test
 */

use App\Controller\Api\IncidentApiController;
use App\Controller\Api\ReservationApiController;
use App\Controller\Api\SessionApiController;
use App\Controller\RoomController;
use App\Http\ViewRenderer;
use App\Repository\EquipmentRepository;
use App\Repository\IncidentRepository;
use App\Repository\ReservationRepository;
use App\Repository\RoomRepository;
use App\Repository\SessionRepository;
use App\Security\ArraySession;
use App\Security\Auth;
use App\Service\IncidentService;
use App\Service\ReservationService;
use App\Service\RoomService;
use App\Support\FixedClock;
use Psr\Http\Message\ResponseInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

require __DIR__ . '/support/bootstrap.php';

['responsable' => $responsable, 'camille' => $camille] = testUsers();
$database = testDatabase();
$clock = new FixedClock(new DateTimeImmutable('2026-11-25 14:00:00'));
$auth = new Auth(new ArraySession());
$renderer = new ViewRenderer(dirname(__DIR__) . '/templates');

$sessionsRepo = new SessionRepository($database);
$roomsRepo = new RoomRepository($database);
$reservations = new ReservationService($sessionsRepo, new ReservationRepository($database), $clock);
$controllers = [
    'rooms' => new RoomController(new RoomService($roomsRepo, $clock), $renderer),
    'apiSessions' => new SessionApiController(sessionService($database, $clock)),
    'apiReservations' => new ReservationApiController($reservations),
    'apiIncidents' => new IncidentApiController(
        new IncidentService(new IncidentRepository($database), $roomsRepo, new EquipmentRepository($database), $sessionsRepo, $clock),
        $auth
    ),
];

$app = AppFactory::create();
$app->addRoutingMiddleware();
$app->addBodyParsingMiddleware();
(require dirname(__DIR__) . '/routes/api.php')($app, $controllers, $auth, $renderer);

/** @return array{0: int, 1: mixed, 2: ResponseInterface} statut, JSON décodé, réponse */
function api(string $method, string $path, ?string $body = null, string $type = 'application/json'): array
{
    global $app;
    $request = (new ServerRequestFactory())->createServerRequest($method, $path);
    if ($body !== null) {
        $request = $request->withHeader('Content-Type', $type)->withBody((new StreamFactory())->createStream($body));
    }
    $response = $app->handle($request);

    return [$response->getStatusCode(), json_decode((string) $response->getBody(), true), $response];
}

$past = addSession($database, 1, '2026-11-25 10:00:00', '2026-11-25 11:00:00', 'TERMINEE');
$free = addSession($database, 1, '2026-11-25 18:00:00', '2026-11-25 19:00:00');
$room2 = addSession($database, 2, '2026-11-26 18:00:00', '2026-11-26 19:00:00');
$cancelled = addSession($database, 2, '2026-11-26 20:00:00', '2026-11-26 21:00:00', 'ANNULEE');
$yesterday = addSession($database, 1, '2026-11-24 18:00:00', '2026-11-24 19:00:00', 'TERMINEE');

$option = static fn (int $session, array $overrides = []): string => (string) json_encode($overrides + [
    'sessionId' => $session, 'nomGroupe' => 'Les Curieux', 'contact' => 'curieux@example.test', 'nbJoueurs' => 4,
]);

// --- US-51 GET /api/sessions ---------------------------------------------------------------
[$status, $list] = api('GET', '/api/sessions');
check('US-51 liste : 200', 200, $status);
check('US-51 sans filtre : aujourd\'hui et à venir (pas hier)', [$past, $free, $room2, $cancelled], array_column($list, 'id'));
$byId = array_column($list, null, 'id');
check('US-51 créneau futur libre : LIBRE', 'LIBRE', $byId[$free]['disponibilite']);
check('US-51 session passée : FERME', 'FERME', $byId[$past]['disponibilite']);
check('US-51 session annulée : FERME', 'FERME', $byId[$cancelled]['disponibilite']);
check('US-51 informations publiques seulement (pas de game master ni de groupe)',
    ['id', 'salleId', 'salle', 'debut', 'fin', 'statut', 'disponibilite', 'optionJusqua'], array_keys($byId[$free]));

[$status, $list] = api('GET', '/api/sessions?roomId=2');
check('US-51 filtre roomId=2 : seulement la salle 2', [$room2, $cancelled], array_column($list, 'id'));
[$status, $list] = api('GET', '/api/sessions?date=2026-11-24');
check('US-51 filtre date : les sessions de ce jour-là', [$yesterday], array_column($list, 'id'));
[$status, $body] = api('GET', '/api/sessions?roomId=99');
check('US-51 salle inconnue : 404 ROOM_NOT_FOUND', [404, 'ROOM_NOT_FOUND'], [$status, $body['error']]);
[$status, $body] = api('GET', '/api/sessions?roomId=abc&date=25/11/2026');
check('US-51 filtres mal formés : 422 avec le détail par paramètre', [422, ['roomId', 'date']], [$status, array_keys($body['details'])]);

// --- US-52 POST /api/reservations (E1) ---------------------------------------------------
[$status, $created, $response] = api('POST', '/api/reservations', $option($free));
check('US-52 option posée : 201', 201, $status);
check('US-52 statut EN_ATTENTE, expiration à +15 min', ['EN_ATTENTE', '2026-11-25 14:15:00'], [$created['statut'], $created['expiration']]);
check('US-52 en-tête Location vers la réservation', '/api/reservations/' . $created['jeton'], $response->getHeaderLine('Location'));
check('US-52 code de suivi renvoyé (« Mes réservations »)', 1, preg_match('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $created['codeSuivi']));

[$status, $body] = api('GET', '/api/sessions?roomId=1');
check('US-51 + E1 : le créneau apparaît EN_OPTION jusqu\'à 14 h 15',
    ['EN_OPTION', '2026-11-25 14:15:00'], [array_column($body, null, 'id')[$free]['disponibilite'], array_column($body, null, 'id')[$free]['optionJusqua']]);

[$status, $body] = api('POST', '/api/reservations', $option($free, ['nomGroupe' => 'Groupe B', 'contact' => 'b@example.test']));
check('US-52 E1 : un second groupe reçoit 409 SLOT_UNAVAILABLE', [409, 'SLOT_UNAVAILABLE', 'EN_OPTION'], [$status, $body['error'], $body['disponibilite']]);
check('US-52 E1 : message avec l\'heure de fin de l\'option', true, str_contains($body['message'], '14 h 15'));

[$status, $body] = api('POST', '/api/reservations', $option($room2, ['nbJoueurs' => 2, 'contact' => 'pas-un-contact', 'nomGroupe' => '']));
check('US-52 données invalides : 422 avec les champs de l\'API', [422, ['nomGroupe', 'contact', 'nbJoueurs']], [$status, array_keys($body['details'])]);
[$status, $body] = api('POST', '/api/reservations', $option($room2, ['nbJoueurs' => '4']));
check('US-52 nbJoueurs en chaîne de chiffres : accepté comme sur le site', 201, $status);
[$status, $body] = api('POST', '/api/reservations', $option(0));
check('US-52 sessionId absent ou invalide : 422', [422, ['sessionId']], [$status, array_keys($body['details'])]);
[$status, $body] = api('POST', '/api/reservations', $option(999));
check('US-52 session inconnue : 404 SESSION_NOT_FOUND', [404, 'SESSION_NOT_FOUND'], [$status, $body['error']]);
[$status, $body] = api('POST', '/api/reservations', $option($past));
check('US-52 session passée : 409 FERME', [409, 'FERME'], [$status, $body['disponibilite']]);
[$status, $body] = api('POST', '/api/reservations', 'nomGroupe=X', 'application/x-www-form-urlencoded');
check('US-52 corps non JSON : 415', [415, 'UNSUPPORTED_MEDIA_TYPE'], [$status, $body['error']]);
[$status, $body] = api('POST', '/api/reservations', '{"sessionId":');
check('US-52 JSON mal formé : 400', [400, 'BAD_REQUEST'], [$status, $body['error']]);

// --- Suivi et confirmation de l'option (E1, US-33) -----------------------------------------
$token = $created['jeton'];
[$status, $body] = api('GET', '/api/reservations/' . $token);
check('E1 suivi : 200, option EN_ATTENTE', [200, 'EN_ATTENTE'], [$status, $body['statut']]);
check('E1 suivi : le contact du groupe n\'est pas renvoyé', false, array_key_exists('contact', $body));
[$status, $body] = api('GET', '/api/reservations/' . str_repeat('0', 32));
check('E1 jeton inconnu : 404', [404, 'RESERVATION_NOT_FOUND'], [$status, $body['error']]);

[$status, $expiring] = api('POST', '/api/reservations', $option(addSession($database, 2, '2026-11-27 18:00:00', '2026-11-27 19:00:00'), ['nbJoueurs' => 3]));
$clock->advance('+10 minutes');
[$status, $body] = api('POST', '/api/reservations/' . $token . '/confirmation');
check('E1 confirmation dans le délai : 200 CONFIRMEE', [200, 'CONFIRMEE', null], [$status, $body['statut'], $body['expiration']]);
[$status, $body] = api('POST', '/api/reservations/' . $token . '/confirmation');
check('E1 seconde confirmation : 409 RESERVATION_CONFLICT', [409, 'RESERVATION_CONFLICT'], [$status, $body['error']]);
[$status, $body] = api('GET', '/api/sessions?roomId=1');
check('E1 créneau confirmé : COMPLET', 'COMPLET', array_column($body, null, 'id')[$free]['disponibilite']);

$clock->advance('+6 minutes');   // 14 h 16 : l'option posée à 14 h 00 a expiré à 14 h 15
[$status, $body] = api('POST', '/api/reservations/' . $expiring['jeton'] . '/confirmation');
check('E1 confirmation hors délai : 409 OPTION_EXPIRED', [409, 'OPTION_EXPIRED'], [$status, $body['error']]);
[$status, $body] = api('GET', '/api/reservations/' . $expiring['jeton']);
check('E1 option expirée : statut EXPIREE, plus d\'heure d\'expiration', ['EXPIREE', null], [$body['statut'], $body['expiration']]);
[$status, $body] = api('POST', '/api/reservations', $option($expiring['sessionId'], ['nomGroupe' => 'Groupe B', 'nbJoueurs' => 3]));
check('E1 option expirée : le créneau est de nouveau réservable (201)', 201, $status);

// --- US-53 POST /api/incidents --------------------------------------------------------------
$incident = (string) json_encode([
    'salleId' => 1, 'equipementId' => 1, 'sessionId' => $free,
    'description' => 'La porte secrète ne s\'ouvre plus.', 'gravite' => 'CRITIQUE', 'estBloquant' => true,
]);
[$status, $body] = api('POST', '/api/incidents', $incident);
check('US-53 sans connexion : 401 UNAUTHENTICATED', [401, 'UNAUTHENTICATED'], [$status, $body['error']]);

$auth->login($camille);
[$status, $body] = api('POST', '/api/incidents', $incident);
check('US-53 game master connecté : 201', [201, 'OUVERT'], [$status, $body['statut']]);
$saved = $database->get('incident', '*', ['id_incident' => $body['id']]);
check('US-53 incident enregistré : salle, équipement, session, bloquant, déclarant = connecté',
    [1, 1, $free, 1, 3], [(int) $saved['id_salle'], (int) $saved['id_equipement'], (int) $saved['id_session'], (int) $saved['est_bloquant'], (int) $saved['id_declarant']]);

[$status, $body] = api('POST', '/api/incidents', (string) json_encode(['salleId' => 1, 'description' => 'Ampoule grillée.', 'gravite' => 'MINEURE']));
check('US-53 équipement et session facultatifs : 201', 201, $status);
[$status, $body] = api('POST', '/api/incidents', (string) json_encode(['salleId' => 2, 'equipementId' => 1, 'description' => '', 'gravite' => 'GRAVE']));
check('US-53 données invalides : 422 avec les champs de l\'API', [422, ['equipementId', 'description', 'gravite']], [$status, array_keys($body['details'])]);
[$status, $body] = api('POST', '/api/incidents', 'description=x', 'text/plain');
check('US-53 corps non JSON : 415', 415, $status);

$auth->logout();
$auth->login($responsable);
[$status, $body] = api('POST', '/api/incidents', $incident);
check('US-53 responsable : 201', 201, $status);

// --- US-50 (non-régression) ---------------------------------------------------------------
[$status, $body] = api('GET', '/api/rooms/99');
check('US-50 salle inconnue : 404 JSON', [404, 'ROOM_NOT_FOUND'], [$status, $body['error']]);
[$status, $body] = api('GET', '/api/inconnu');
check('API adresse inconnue : 404 en JSON', [404, 'NOT_FOUND'], [$status, $body['error'] ?? null]);
[$status, $body] = api('POST', '/api/reservations/pas-un-jeton/confirmation');
check('API jeton mal formé : 404 en JSON', [404, 'NOT_FOUND'], [$status, $body['error'] ?? null]);
[$status, $body, $response] = api('GET', '/api/health');
check('API : réponses en JSON UTF-8', 'application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));

finish('de l\'API');

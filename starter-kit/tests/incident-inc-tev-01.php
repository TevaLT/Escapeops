<?php

declare(strict_types=1);

/**
 * INC-TEV-01 (recette, règle E1) : « option prise sur Le Cabinet des Ombres, onglet laissé ouvert plus
 * d'un quart d'heure, clic sur Confirmer : une page est apparue, mais était-ce enregistré ? Sur le
 * téléphone, le créneau semblait encore libre. » (horloge du PC du testeur en avance de 2-3 min).
 *
 * Preuve automatisée du comportement attendu, sur la vraie application Slim (routes/web.php et
 * routes/api.php, middleware CSRF), les vrais services et la base SQLite de test, horloge figée :
 *  - l'heure de référence est celle du serveur (Clock), jamais celle du navigateur ;
 *  - une option valide se confirme, à la seconde près ; à date_expiration pile, elle a expiré ;
 *  - une option expirée est refusée par les trois chemins (service, formulaire HTML depuis un onglet
 *    ancien, API), sans rien changer en base, et la page affichée le dit sans ambiguïté ;
 *  - une option expirée ne bloque plus un autre groupe (vitrine, API, planning) ;
 *  - jamais deux réservations confirmées sur la même session.
 * Lancement : composer test
 */

use App\Controller\Api\IncidentApiController;
use App\Controller\Api\ReservationApiController;
use App\Controller\Api\SessionApiController;
use App\Controller\AuthController;
use App\Controller\ClientController;
use App\Controller\EquipmentController;
use App\Controller\GameMasterController;
use App\Controller\IncidentController;
use App\Controller\ReservationController;
use App\Controller\RoomAdminController;
use App\Controller\RoomController;
use App\Controller\SessionController;
use App\Controller\StaffController;
use App\Domain\ReservationOption;
use App\Domain\ReservationStateException;
use App\Domain\SlotAvailability;
use App\Http\ViewRenderer;
use App\Middleware\CsrfMiddleware;
use App\Repository\ChecklistRepository;
use App\Repository\DashboardRepository;
use App\Repository\EquipmentRepository;
use App\Repository\IncidentRepository;
use App\Repository\ReservationRepository;
use App\Repository\RoomRepository;
use App\Repository\SessionRepository;
use App\Repository\UserRepository;
use App\Security\ArraySession;
use App\Security\Auth;
use App\Security\ClientAccess;
use App\Security\Csrf;
use App\Security\Flash;
use App\Service\AuthService;
use App\Service\ClientAccessService;
use App\Service\DashboardService;
use App\Service\EquipmentService;
use App\Service\GameMasterService;
use App\Service\IncidentService;
use App\Service\ReservationService;
use App\Service\RoomService;
use App\Support\Clock;
use App\Support\FixedClock;
use Psr\Http\Message\ResponseInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

require __DIR__ . '/support/bootstrap.php';

// --- Application complète, câblée comme public/index.php (session en mémoire, horloge figée) ----------
$database = testDatabase();
$clock = new FixedClock(new DateTimeImmutable('2026-11-26 14:00:00'));
$renderer = new ViewRenderer(dirname(__DIR__) . '/templates');
$session = new ArraySession();
$auth = new Auth($session);
$csrf = new Csrf($session);
$flash = new Flash($session);
$renderer->share('utilisateurConnecte', fn () => $auth->user());
$renderer->share('csrf', fn () => $csrf);
$renderer->share('flashes', fn () => $flash->pull());

$users = new UserRepository($database);
$roomRepository = new RoomRepository($database);
$sessionRepository = new SessionRepository($database);
$reservationRepository = new ReservationRepository($database);
$equipmentRepository = new EquipmentRepository($database);
$incidentRepository = new IncidentRepository($database);
$roomService = new RoomService($roomRepository, $clock);
$sessionService = sessionService($database, $clock);
$equipmentService = new EquipmentService($equipmentRepository, $roomRepository, $clock);
$incidentService = new IncidentService($incidentRepository, $roomRepository, $equipmentRepository, $sessionRepository, $clock);
$dashboardService = new DashboardService(new DashboardRepository($database), $incidentRepository, $sessionService, $clock);
$service = new ReservationService($sessionRepository, $reservationRepository, $clock);   // un seul service, site + API

$controllers = [
    'rooms' => new RoomController($roomService, $renderer),
    'reservations' => new ReservationController($service, $flash, $renderer),
    'auth' => new AuthController(new AuthService($users, $auth), $auth, $renderer),
    'staff' => new StaffController($auth, $sessionService, $dashboardService, $renderer),
    'sessions' => new SessionController($sessionService, $auth, $flash, $renderer),
    'roomAdmin' => new RoomAdminController($roomService, $equipmentService, $auth, $flash, $renderer),
    'equipment' => new EquipmentController($equipmentService, $auth, $flash, $renderer),
    'incidents' => new IncidentController($incidentService, $auth, $flash, $renderer),
    'gameMasters' => new GameMasterController(new GameMasterService($users, $sessionRepository, $clock), $auth, $flash, $renderer),
    'clients' => new ClientController(new ClientAccessService($reservationRepository, new ClientAccess($session), $clock), $flash, $renderer),
    'apiSessions' => new SessionApiController($sessionService),
    'apiReservations' => new ReservationApiController($service),
    'apiIncidents' => new IncidentApiController($incidentService, $auth),
];

$app = AppFactory::create();
$app->addRoutingMiddleware();
$app->add(new CsrfMiddleware($csrf, $renderer));
$app->addBodyParsingMiddleware();
(require dirname(__DIR__) . '/routes/web.php')($app, $controllers, $auth, $renderer);
(require dirname(__DIR__) . '/routes/api.php')($app, $controllers, $auth, $renderer);

/** Requête navigateur (formulaire). Le jeton CSRF de la session est ajouté sauf si $withCsrf = false. */
function web(string $method, string $path, array $fields = [], bool $withCsrf = true, array $headers = []): ResponseInterface
{
    global $app, $csrf;
    $request = (new ServerRequestFactory())->createServerRequest($method, $path);
    foreach ($headers as $name => $value) {
        $request = $request->withHeader($name, $value);
    }
    if ($method === 'POST') {
        if ($withCsrf) {
            $fields += [Csrf::FIELD => $csrf->token()];
        }
        $request = $request->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody((new StreamFactory())->createStream(http_build_query($fields)));
    }

    return $app->handle($request);
}

/** @return array{0: int, 1: mixed} statut et JSON décodé */
function api(string $method, string $path, ?array $body = null): array
{
    global $app;
    $request = (new ServerRequestFactory())->createServerRequest($method, $path);
    if ($body !== null) {
        $request = $request->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream((string) json_encode($body)));
    }
    $response = $app->handle($request);

    return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
}

/** Toutes les lignes de réservation d'une session (pour prouver qu'un refus ne change rien en base). */
function rowsOf(int $sessionId): array
{
    global $database;

    return $database->select('reservation', '*', ['id_session' => $sessionId, 'ORDER' => ['id_reservation' => 'ASC']]);
}

function confirmedCount(int $sessionId): int
{
    global $database;

    return (int) $database->count('reservation', ['id_session' => $sessionId, 'statut' => 'CONFIRMEE']);
}

/** État refusé par le service ('OK' si la confirmation passe). */
function confirmOutcome(string $token): string
{
    global $service;
    try {
        $service->confirm($token);
        return 'OK';
    } catch (ReservationStateException $exception) {
        return $exception->state;
    }
}

/** État refusé par le service pour une annulation ('OK' si elle passe). */
function cancelOutcome(string $token): string
{
    global $service;
    try {
        $service->cancel($token);
        return 'OK';
    } catch (ReservationStateException $exception) {
        return $exception->state;
    }
}

/** Pose une option par le formulaire public et renvoie le jeton lu dans la redirection 303. */
function optionByForm(int $sessionId, string $group, string $contact, int $players = 4): string
{
    $response = web('POST', '/sessions/' . $sessionId . '/reserver', ['nom_groupe' => $group, 'contact' => $contact, 'nb_joueurs' => (string) $players]);
    check("pose d'option par le formulaire ({$group}) : 303 vers le lien secret", 303, $response->getStatusCode());
    preg_match('#^/reservations/([0-9a-f]{32})$#', $response->getHeaderLine('Location'), $m);

    return $m[1] ?? '';
}

/** HTML de la réponse, entités décodées (les apostrophes sont échappées par e()). */
function htmlOf(ResponseInterface $response): string
{
    return html_entity_decode((string) $response->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function titleOf(string $html): string
{
    return preg_match('#<title>(.*?) · Les Archives Obscures</title>#s', $html, $m) === 1 ? trim($m[1]) : '';
}

function h1Of(string $html): string
{
    return preg_match('#<h1>(.*?)</h1>#s', $html, $m) === 1 ? trim($m[1]) : '';
}

/**
 * Horloge qui exécute une action juste avant de donner l'heure, une seule fois. ReservationService lit l'heure
 * APRÈS avoir lu la réservation et AVANT d'écrire : on simule ainsi une autre requête qui modifie la ligne
 * entre la lecture et l'écriture (impossible autrement sous SQLite, qui sérialise tout).
 */
final class HookClock implements Clock
{
    private ?Closure $hook = null;

    public function __construct(private Clock $inner)
    {
    }

    public function beforeNextCall(Closure $hook): void
    {
        $this->hook = $hook;
    }

    public function now(): DateTimeImmutable
    {
        if ($this->hook !== null) {
            $hook = $this->hook;
            $this->hook = null;
            $hook();
        }

        return $this->inner->now();
    }
}

function availabilityOf(int $sessionId): string
{
    global $service;

    return (string) $service->slot($sessionId)['disponibilite'];
}

// =====================================================================================================
// 1. Scénario du signalement : Le Cabinet des Ombres (salle 1), onglet laissé ouvert 16 minutes
// =====================================================================================================
$cabinet = addSession($database, 1, '2026-11-26 18:00:00', '2026-11-26 19:00:00');
$tokenA = optionByForm($cabinet, 'Les Testeurs', 'testeur@example.test');
$rowA = $database->get('reservation', '*', ['jeton' => $tokenA]);
check('horloge serveur : date_creation = heure du serveur (14 h 00)', '2026-11-26 14:00:00', $rowA['date_creation']);
check('horloge serveur : date_expiration = création + 15 min', '2026-11-26 14:15:00', $rowA['date_expiration']);

$page = (string) web('GET', '/reservations/' . $tokenA)->getBody();
check('onglet ouvert à 14 h 00 : bouton « Confirmer la réservation » présent', true, str_contains($page, 'Confirmer la réservation'));
check('onglet ouvert à 14 h 00 : « gardé jusqu\'à 14 h 15 »', true, str_contains($page, '14 h 15'));
check('pendant l\'option : le créneau est EN_OPTION pour les autres', SlotAvailability::EN_OPTION, availabilityOf($cabinet));

// Le testeur revient 16 minutes plus tard et clique sur Confirmer depuis l'ancien onglet (formulaire déjà affiché).
$clock->advance('+16 minutes');   // 14 h 16
$before = rowsOf($cabinet);
// Le navigateur pourrait envoyer son heure (en avance de 2-3 min ou en retard) : le serveur l'ignore.
$response = web('POST', '/reservations/' . $tokenA . '/confirmer', ['maintenant' => '2026-11-26 14:10:00'], true, ['Date' => 'Thu, 26 Nov 2026 13:10:00 GMT']);
$html = htmlOf($response);
check('HTML onglet ancien, option expirée : 409 (pas de redirection 303)', [409, ''], [$response->getStatusCode(), $response->getHeaderLine('Location')]);
check('HTML refus : titre d\'onglet explicite', 'Réservation non enregistrée : option expirée', titleOf($html));
check('HTML refus : titre de page explicite (plus « Le Cabinet des Ombres » seul)', 'Réservation non enregistrée : option expirée', h1Of($html));
check('HTML refus : un seul message, avec l\'heure d\'expiration', 'Votre option a expiré à 14 h 15 : la réservation n\'a pas été enregistrée.',
    preg_match('#<p class="alert alert-error" role="alert">(.*?)</p>#s', $html, $m) === 1 ? $m[1] : '');
check('HTML refus : pas de répétition (« expiré à » et « 14 h 15 » une seule fois)', [1, 1], [substr_count($html, 'expiré à'), substr_count($html, '14 h 15')]);
check('HTML refus : le message vient avant le récapitulatif', true, (int) strpos($html, 'alert-error') < (int) strpos($html, 'Groupe : Les Testeurs'));
check('HTML refus : plus de phrase conditionnelle « Si le créneau est encore libre »', false, str_contains($html, 'Si le créneau est encore libre'));
check('HTML refus : disponibilité réelle calculée par le serveur (libre)', true, str_contains($html, 'Le créneau est encore libre'));
check('HTML refus : lien pour reposer une option sur ce créneau', true, str_contains($html, 'href="/sessions/' . $cabinet . '/reserver"'));
check('HTML refus : badge « Option expirée »', true, str_contains($html, 'Option expirée'));
check('HTML refus : aucun message « Réservation confirmée »', false, str_contains($html, 'Réservation confirmée'));
check('HTML refus : plus de bouton « Confirmer la réservation »', false, str_contains($html, 'Confirmer la réservation'));
check('HTML refus : plus de bouton « Libérer le créneau » (option expirée)', false, str_contains($html, 'Libérer le créneau'));
check('HTML refus : rien n\'a changé en base (ligne identique, statut EN_ATTENTE)', $before, rowsOf($cabinet));
check('HTML refus : page non mise en cache (lien secret)', 'no-store', $response->getHeaderLine('Cache-Control'));

check('service : confirmation de l\'option expirée refusée (EXPIREE)', ReservationOption::EXPIREE, confirmOutcome($tokenA));
[$status, $body] = api('POST', '/api/reservations/' . $tokenA . '/confirmation');
check('API : confirmation de l\'option expirée → 409 OPTION_EXPIRED', [409, 'OPTION_EXPIRED', 'EXPIREE'], [$status, $body['error'], $body['etat']]);
check('service + API : toujours aucun changement en base', $before, rowsOf($cabinet));
check('aucune réservation confirmée sur le créneau', 0, confirmedCount($cabinet));

// Rejouer la requête sans jeton CSRF (HTTP direct, autre site) : 403, avant même le service.
$response = web('POST', '/reservations/' . $tokenA . '/confirmer', [], false);
check('HTTP direct sans jeton CSRF : 403, rien en base', [403, $before], [$response->getStatusCode(), rowsOf($cabinet)]);

// Simple consultation du lien après expiration (sans clic) : titre normal, un seul message, pas de bouton Libérer.
$html = htmlOf(web('GET', '/reservations/' . $tokenA));
check('lien consulté après expiration : titre « Ma réservation · salle »', 'Ma réservation · Le Cabinet des Ombres', titleOf($html));
check('lien consulté après expiration : message unique avec l\'heure, disponibilité libre',
    [1, true], [substr_count($html, 'expiré à 14 h 15'), str_contains($html, 'Le créneau est encore libre')]);
check('lien consulté après expiration : ni Confirmer ni Libérer', [false, false], [str_contains($html, 'Confirmer la réservation'), str_contains($html, 'Libérer le créneau')]);
check('service : une option expirée n\'est plus annulable (peut_annuler = false)', false, $service->byToken($tokenA)['peut_annuler']);

// Point 2 : « Libérer le créneau » par un POST forgé sur une option expirée → 409, rien en base.
$response = web('POST', '/reservations/' . $tokenA . '/annuler');
$html = htmlOf($response);
check('annulation forgée d\'une option expirée : 409 sans redirection', [409, ''], [$response->getStatusCode(), $response->getHeaderLine('Location')]);
check('annulation refusée : titre explicite', ['Annulation refusée : option expirée', 'Annulation refusée : option expirée'], [titleOf($html), h1Of($html)]);
check('annulation refusée : pas de « le créneau est de nouveau proposé aux autres groupes »', false, str_contains($html, 'est de nouveau proposé aux autres groupes'));
check('annulation refusée : rien n\'a changé en base', $before, rowsOf($cabinet));
check('service : annulation d\'une option expirée refusée (EXPIREE)', ReservationOption::EXPIREE, cancelOutcome($tokenA));

// « Sur mon téléphone, le créneau semblait encore libre » : c'est le comportement attendu d'une option expirée.
check('option expirée : le créneau est LIBRE (calcul E1 du service)', SlotAvailability::LIBRE, availabilityOf($cabinet));
$salle = (string) web('GET', '/salles/1')->getBody();
check('vitrine /salles/1 : plus de mention « jusqu\'à 14 h 15 »', false, str_contains($salle, '14 h 15'));
$cabinetSlot = array_column($roomService->get(1)['sessions'] ?? [], null, 'id')[$cabinet] ?? [];
check('vitrine (RoomService) : créneau LIBRE, sans heure d\'option', [SlotAvailability::LIBRE, null], [$cabinetSlot['disponibilite'] ?? null, array_key_exists('optionJusqua', $cabinetSlot) ? $cabinetSlot['optionJusqua'] : 'absent']);
[$status, $list] = api('GET', '/api/sessions?roomId=1');
$apiSlot = array_column($list, null, 'id')[$cabinet];
check('GET /api/sessions : LIBRE, optionJusqua null', [SlotAvailability::LIBRE, null], [$apiSlot['disponibilite'], $apiSlot['optionJusqua']]);
$planning = array_column($sessionService->upcoming(50), null, 'id_session')[$cabinet];
check('planning (v_planning_detaille) : la ligne reste EN_ATTENTE en base mais option_active = false',
    ['2026-11-26 14:15:00', false], [$planning['option_expire_le'], $planning['option_active']]);

// « Mes réservations » : l'option expirée n'est ni « à venir » ni confirmable.
web('POST', '/mes-reservations/connexion', ['contact' => 'testeur@example.test', 'code' => (string) $rowA['code_suivi']]);
$mine = (string) web('GET', '/mes-reservations')->getBody();
check('Mes réservations : « Option expirée sans confirmation »', true, str_contains($mine, 'Option expirée sans confirmation'));
check('Mes réservations : pas de lien « Confirmer ou libérer »', false, str_contains($mine, 'Confirmer ou libérer'));
web('POST', '/mes-reservations/deconnexion');

// Un autre groupe prend le créneau libéré, le confirme ; l'ancien onglet reste refusé : pas de double réservation.
[$status, $optionB] = api('POST', '/api/reservations', ['sessionId' => $cabinet, 'nomGroupe' => 'Groupe B', 'contact' => 'b@example.test', 'nbJoueurs' => 3]);
check('option expirée : un autre groupe peut poser une option (201)', 201, $status);
check('…et le créneau est de nouveau EN_OPTION (option de B jusqu\'à 14 h 31)', SlotAvailability::EN_OPTION, availabilityOf($cabinet));
check('A ne peut toujours pas confirmer pendant l\'option de B', ReservationOption::EXPIREE, confirmOutcome($tokenA));
$html = htmlOf(web('POST', '/reservations/' . $tokenA . '/confirmer'));
check('refus pendant l\'option de B : « repris par un autre groupe » jusqu\'à 14 h 31',
    [true, true], [str_contains($html, 'repris par un autre groupe'), str_contains($html, '14 h 31')]);
check('refus pendant l\'option de B : pas de lien pour reposer une option', false, str_contains($html, 'href="/sessions/' . $cabinet . '/reserver"'));
// Scénario de la reproduction MySQL : A clique « Libérer » (onglet ancien) pendant que B tient le créneau.
$beforeB = rowsOf($cabinet);
$response = web('POST', '/reservations/' . $tokenA . '/annuler');
check('A « libère » pendant l\'option de B : 409, l\'option de B et la ligne de A intactes', [409, $beforeB], [$response->getStatusCode(), rowsOf($cabinet)]);
check('…et aucun message faux « de nouveau proposé »', false, str_contains(htmlOf($response), 'est de nouveau proposé aux autres groupes'));
[$status, $body] = api('POST', '/api/reservations/' . $optionB['jeton'] . '/confirmation');
check('B confirme dans le délai : 200 CONFIRMEE', [200, 'CONFIRMEE'], [$status, $body['statut']]);
$response = web('POST', '/reservations/' . $tokenA . '/confirmer');
check('A retente depuis l\'ancien onglet : 409, toujours refusé', 409, $response->getStatusCode());
check('refus après la confirmation de B : « réservé par un autre groupe »', true, str_contains(htmlOf($response), 'réservé par un autre groupe'));
check('pas de double réservation : une seule CONFIRMEE (B)', [1, 'Groupe B'], [confirmedCount($cabinet), $database->get('reservation', 'nom_groupe', ['id_session' => $cabinet, 'statut' => 'CONFIRMEE'])]);
check('créneau COMPLET partout', [SlotAvailability::COMPLET, SlotAvailability::COMPLET],
    [availabilityOf($cabinet), array_column(api('GET', '/api/sessions?roomId=1')[1], null, 'id')[$cabinet]['disponibilite']]);

// =====================================================================================================
// 2. Bornes : 1 s avant, à l'heure pile, 1 s après ; option valide par le formulaire HTML
// =====================================================================================================
$placeAt = static function (string $at, int $room, string $start, string $group) use ($clock, $database): array {
    $clock->set(new DateTimeImmutable($at));
    $id = addSession($database, $room, $start, (new DateTimeImmutable($start))->modify('+1 hour')->format('Y-m-d H:i:s'));

    return [$id, optionByForm($id, $group, strtolower($group) . '@example.test', 3)];
};

[$valid, $tokenValid] = $placeAt('2026-11-26 15:00:00', 1, '2026-11-27 18:00:00', 'Valide');
$clock->set(new DateTimeImmutable('2026-11-26 15:14:59'));
[$status, $body] = api('POST', '/api/reservations', ['sessionId' => $valid, 'nomGroupe' => 'Intrus', 'contact' => 'i@example.test', 'nbJoueurs' => 3]);
check('borne : 1 s avant l\'échéance, l\'option bloque encore un autre groupe (409 EN_OPTION)', [409, 'EN_OPTION'], [$status, $body['disponibilite']]);
$response = web('POST', '/reservations/' . $tokenValid . '/confirmer');
check('borne : 1 s avant l\'échéance, confirmation HTML acceptée (303 vers le lien secret)', [303, '/reservations/' . $tokenValid], [$response->getStatusCode(), $response->getHeaderLine('Location')]);
$page = (string) web('GET', '/reservations/' . $tokenValid)->getBody();
check('après succès : message « Réservation confirmée » et badge « Confirmée »', [true, true], [str_contains($page, 'Réservation confirmée'), str_contains($page, '>Confirmée<')]);
check('après succès : statut CONFIRMEE en base', 'CONFIRMEE', $database->get('reservation', 'statut', ['jeton' => $tokenValid]));
$response = web('POST', '/reservations/' . $tokenValid . '/confirmer');
check('double clic : 2e confirmation refusée (409 « déjà confirmée »), une seule ligne confirmée',
    [409, true, 1], [$response->getStatusCode(), str_contains((string) $response->getBody(), 'déjà confirmée'), confirmedCount($valid)]);

[$exact, $tokenExact] = $placeAt('2026-11-26 15:00:00', 2, '2026-11-27 20:00:00', 'Pile');
$clock->set(new DateTimeImmutable('2026-11-26 15:15:00'));
$before = rowsOf($exact);
check('borne : à date_expiration pile, confirmation refusée (borne exclue)', ReservationOption::EXPIREE, confirmOutcome($tokenExact));
check('borne : à l\'heure pile, le créneau est libéré', SlotAvailability::LIBRE, availabilityOf($exact));
check('borne : refus sans changement en base', $before, rowsOf($exact));

[$late, $tokenLate] = $placeAt('2026-11-26 15:00:00', 2, '2026-11-27 22:00:00', 'Tard');
$clock->set(new DateTimeImmutable('2026-11-26 15:15:01'));
$response = web('POST', '/reservations/' . $tokenLate . '/confirmer');
check('borne : expirée depuis 1 s, confirmation HTML refusée (409)', 409, $response->getStatusCode());
check('borne : expirée depuis 1 s, statut EN_ATTENTE inchangé', 'EN_ATTENTE', $database->get('reservation', 'statut', ['jeton' => $tokenLate]));

// Fractions de seconde : la pose à 15 h 00 min 00,7 s est enregistrée à la seconde (15 h 15 min 00 s).
$clock->set(new DateTimeImmutable('2026-11-26 15:00:00.700000'));
$fraction = addSession($database, 1, '2026-11-28 18:00:00', '2026-11-28 19:00:00');
$tokenFraction = optionByForm($fraction, 'Fraction', 'fraction@example.test', 3);
check('fractions de seconde : expiration tronquée à la seconde', '2026-11-26 15:15:00', $database->get('reservation', 'date_expiration', ['jeton' => $tokenFraction]));
$clock->set(new DateTimeImmutable('2026-11-26 15:14:59.999999'));
check('fractions de seconde : encore confirmable à 15 h 14 min 59,999 s', 'OK', confirmOutcome($tokenFraction));

// =====================================================================================================
// 3. Autres refus : option libérée par le groupe, session annulée pendant l'option
// =====================================================================================================
$clock->set(new DateTimeImmutable('2026-11-26 16:00:00'));
$freed = addSession($database, 2, '2026-11-28 20:00:00', '2026-11-28 21:00:00');
$tokenFreed = optionByForm($freed, 'Libere', 'libere@example.test', 3);
web('POST', '/reservations/' . $tokenFreed . '/annuler');
check('option libérée puis confirmée (onglet ancien) : refus ANNULEE', ReservationOption::ANNULEE, confirmOutcome($tokenFreed));
check('option libérée : aucune réservation confirmée', 0, confirmedCount($freed));

$closed = addSession($database, 2, '2026-11-29 20:00:00', '2026-11-29 21:00:00');
$tokenClosed = optionByForm($closed, 'Ferme', 'ferme@example.test', 3);
$database->update('session', ['statut' => 'ANNULEE'], ['id_session' => $closed]);
check('session annulée pendant l\'option : confirmation refusée (FERME)', SlotAvailability::FERME, confirmOutcome($tokenClosed));

// =====================================================================================================
// 4. Écriture conditionnelle : une autre requête modifie la ligne entre la lecture et l'écriture
// =====================================================================================================
$hookClock = new HookClock($clock);
$hooked = new ReservationService($sessionRepository, $reservationRepository, $hookClock);
$refusedState = static function (callable $action): string {
    try {
        $action();
        return 'OK';
    } catch (ReservationStateException $exception) {
        return $exception->state;
    }
};

$clock->set(new DateTimeImmutable('2026-11-26 17:00:00'));
$raceA = addSession($database, 1, '2026-11-30 18:00:00', '2026-11-30 19:00:00');
$tokenRace = optionByForm($raceA, 'Course', 'course@example.test', 3);
$hookClock->beforeNextCall(fn () => $database->update('reservation', ['statut' => 'ANNULEE'], ['jeton' => $tokenRace]));
check('course : option annulée entre lecture et écriture → confirmation refusée (CONFLIT)', 'CONFLIT', $refusedState(fn () => $hooked->confirm($tokenRace)));
// La modification simulée passe sur la même connexion : le rollback du refus l'annule aussi. On vérifie donc que
// l'écriture du service n'a pas eu lieu (avant le correctif : CONFIRMEE validée, l'annulation écrasée).
check('course : rien n\'est confirmé (l\'annulation n\'aurait pas été écrasée)', ['EN_ATTENTE', 0],[$database->get('reservation', 'statut', ['jeton' => $tokenRace]), confirmedCount($raceA)]);

$raceB = addSession($database, 1, '2026-11-30 20:00:00', '2026-11-30 21:00:00');
$tokenRace = optionByForm($raceB, 'Course', 'course@example.test', 3);
$hookClock->beforeNextCall(fn () => $database->update('reservation', ['date_expiration' => '2026-11-26 16:59:00'], ['jeton' => $tokenRace]));
check('course : échéance dépassée entre lecture et écriture → confirmation refusée (CONFLIT)', 'CONFLIT', $refusedState(fn () => $hooked->confirm($tokenRace)));
check('course : la ligne reste EN_ATTENTE', 'EN_ATTENTE', $database->get('reservation', 'statut', ['jeton' => $tokenRace]));

$raceC = addSession($database, 2, '2026-11-30 18:00:00', '2026-11-30 19:00:00');
$tokenRace = optionByForm($raceC, 'Course', 'course@example.test', 3);
$hookClock->beforeNextCall(fn () => $database->update('reservation', ['statut' => 'CONFIRMEE'], ['jeton' => $tokenRace]));
check('course : confirmée entre lecture et écriture → « Libérer » refusé (CONFLIT)', 'CONFLIT', $refusedState(fn () => $hooked->cancel($tokenRace)));
check('course : rien n\'est annulé (la confirmation n\'aurait pas été annulée en silence)', 'EN_ATTENTE', $database->get('reservation', 'statut', ['jeton' => $tokenRace]));
check('la garde ne bloque pas le cas normal : l\'option est confirmée', 'OK', outcome(fn () => $service->confirm($tokenRace)));
$response = web('POST', '/reservations/' . $tokenRace . '/annuler');
check('annulation d\'une réservation confirmée (partie non commencée) toujours possible : 303', [303, 'ANNULEE'],
    [$response->getStatusCode(), $database->get('reservation', 'statut', ['jeton' => $tokenRace])]);

// Le repository lui-même : 0 ligne modifiée → false, base inchangée.
$now = $clock->now();
$raceD = addSession($database, 2, '2026-11-30 20:00:00', '2026-11-30 21:00:00');
$tokenRace = optionByForm($raceD, 'Repo', 'repo@example.test', 3);
$idRace = (int) $database->get('reservation', 'id_reservation', ['jeton' => $tokenRace]);
$guard = static fn (string $from, string $to, ?DateTimeImmutable $at): string => outcome(function () use ($reservationRepository, $idRace, $from, $to, $at): void {
    if (!$reservationRepository->changeStatusIf($idRace, $from, $to, $at)) {
        throw new RuntimeException('0 ligne modifiée');
    }
});
check('repository : statut attendu différent → 0 ligne', 'RuntimeException', $guard('CONFIRMEE', 'ANNULEE', null));
check('repository : option expirée à l\'instant donné → 0 ligne', 'RuntimeException', $guard('EN_ATTENTE', 'CONFIRMEE', $now->modify('+15 minutes')));
check('repository : après 0 ligne, base inchangée', 'EN_ATTENTE', $database->get('reservation', 'statut', ['id_reservation' => $idRace]));
check('repository : option active et bon statut → 1 ligne', 'OK', $guard('EN_ATTENTE', 'CONFIRMEE', $now));
check('repository : lecture verrouillée (FOR UPDATE sous MySQL) = même ligne',
    $tokenRace, outcome(fn () => $reservationRepository->findByToken($tokenRace, lock: true)) === 'OK' ? $tokenRace : 'erreur');

finish('INC-TEV-01');

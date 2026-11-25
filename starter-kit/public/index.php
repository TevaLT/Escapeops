<?php

declare(strict_types=1);

use App\Controller\AuthController;
use App\Controller\ClientController;
use App\Security\ClientAccess;
use App\Service\ClientAccessService;
use App\Controller\EquipmentController;
use App\Controller\GameMasterController;
use App\Controller\IncidentController;
use App\Controller\ReservationController;
use App\Controller\RoomAdminController;
use App\Controller\RoomController;
use App\Controller\SessionController;
use App\Controller\StaffController;
use App\Database\DatabaseFactory;
use App\Http\ViewRenderer;
use App\Middleware\CsrfMiddleware;
use App\Middleware\SessionMiddleware;
use App\Repository\ChecklistRepository;
use App\Repository\DashboardRepository;
use App\Repository\EquipmentRepository;
use App\Repository\IncidentRepository;
use App\Repository\ReservationRepository;
use App\Repository\RoomRepository;
use App\Repository\SessionRepository;
use App\Repository\UserRepository;
use App\Security\Auth;
use App\Security\Csrf;
use App\Security\Flash;
use App\Security\NativeSession;
use App\Service\AuthService;
use App\Service\DashboardService;
use App\Service\EquipmentService;
use App\Service\GameMasterService;
use App\Service\IncidentService;
use App\Service\ReservationService;
use App\Service\RoomService;
use App\Service\SessionService;
use App\Support\SystemClock;
use Dotenv\Dotenv;
use Slim\Factory\AppFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);

Dotenv::createImmutable($root)->safeLoad();

$settings = require $root . '/config/settings.php';

date_default_timezone_set($settings['app']['timezone']);

$app = AppFactory::create();

$database = DatabaseFactory::create($settings['database']);
$clock = new SystemClock();
$renderer = new ViewRenderer($root . '/templates');

$session = new NativeSession();
$auth = new Auth($session);
$csrf = new Csrf($session);
$flash = new Flash($session);

// Disponibles dans toutes les vues : en-tête (connecté ou non) et champ CSRF des formulaires.
$renderer->share('utilisateurConnecte', fn () => $auth->user());
$renderer->share('csrf', fn () => $csrf);
$renderer->share('flashes', fn () => $flash->pull());

// Accès aux données
$users = new UserRepository($database);
$roomRepository = new RoomRepository($database);
$sessionRepository = new SessionRepository($database);
$reservationRepository = new ReservationRepository($database);
$equipmentRepository = new EquipmentRepository($database);
$incidentRepository = new IncidentRepository($database);
$checklistRepository = new ChecklistRepository($database);

// Règles métier
$roomService = new RoomService($roomRepository, $clock);
$sessionService = new SessionService(
    $sessionRepository,
    $roomRepository,
    $reservationRepository,
    $users,
    $checklistRepository,
    $clock
);
$equipmentService = new EquipmentService($equipmentRepository, $roomRepository, $clock);
$incidentService = new IncidentService($incidentRepository, $roomRepository, $equipmentRepository, $sessionRepository, $clock);
$dashboardService = new DashboardService(new DashboardRepository($database), $incidentRepository, $sessionService, $clock);

$controllers = [
    'rooms' => new RoomController($roomService, $renderer),
    'reservations' => new ReservationController(
        new ReservationService($sessionRepository, $reservationRepository, $clock),
        $flash,
        $renderer
    ),
    'auth' => new AuthController(new AuthService($users, $auth), $auth, $renderer),
    'staff' => new StaffController($auth, $sessionService, $dashboardService, $renderer),
    'sessions' => new SessionController($sessionService, $auth, $flash, $renderer),
    'roomAdmin' => new RoomAdminController($roomService, $equipmentService, $auth, $flash, $renderer),
    'equipment' => new EquipmentController($equipmentService, $auth, $flash, $renderer),
    'incidents' => new IncidentController($incidentService, $auth, $flash, $renderer),
    'gameMasters' => new GameMasterController(new GameMasterService($users, $sessionRepository, $clock), $auth, $flash, $renderer),
    'clients' => new ClientController(
        new ClientAccessService($reservationRepository, new ClientAccess($session), $clock),
        $flash,
        $renderer
    ),
];

// Slim exécute en premier le dernier middleware ajouté. Ordre réel d'une requête :
// erreurs → session → lecture du corps → CSRF → routage → route (RequireRole) → contrôleur
$app->addRoutingMiddleware();
$app->add(new CsrfMiddleware($csrf, $renderer));
$app->addBodyParsingMiddleware();
$app->add(new SessionMiddleware());
$app->addErrorMiddleware(
    $settings['app']['debug'],
    true,
    true
);

(require $root . '/routes/web.php')($app, $controllers, $auth, $renderer);
(require $root . '/routes/api.php')($app, $controllers);

$app->run();

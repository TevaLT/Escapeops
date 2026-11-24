<?php

declare(strict_types=1);

use App\Controller\AuthController;
use App\Controller\RoomController;
use App\Controller\StaffController;
use App\Database\DatabaseFactory;
use App\Http\ViewRenderer;
use App\Middleware\CsrfMiddleware;
use App\Middleware\SessionMiddleware;
use App\Repository\RoomRepository;
use App\Repository\UserRepository;
use App\Security\Auth;
use App\Security\Csrf;
use App\Security\NativeSession;
use App\Service\AuthService;
use App\Service\RoomService;
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

// Disponibles dans toutes les vues : en-tête (connecté ou non) et champ CSRF des formulaires.
$renderer->share('utilisateurConnecte', fn () => $auth->user());
$renderer->share('csrf', fn () => $csrf);

$controllers = [
    'rooms' => new RoomController(
        new RoomService(new RoomRepository($database), $clock),
        $renderer
    ),
    'auth' => new AuthController(
        new AuthService(new UserRepository($database), $auth),
        $auth,
        $renderer
    ),
    'staff' => new StaffController($auth, $renderer),
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

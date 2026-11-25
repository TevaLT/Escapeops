<?php

declare(strict_types=1);

/**
 * Tests sans BDD de l'épic 1 : session, rôles, CSRF et invariant de modification du planning.
 * Lancement : composer test
 */

use App\Domain\AccessDeniedException;
use App\Domain\Authorization;
use App\Domain\CurrentUser;
use App\Domain\Role;
use App\Http\ViewRenderer;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RequireRole;
use App\Security\ArraySession;
use App\Security\Auth;
use App\Security\Csrf;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

require dirname(__DIR__) . '/vendor/autoload.php';

$failures = 0;

function check(string $label, mixed $expected, mixed $actual): void
{
    global $failures;
    if ($expected === $actual) {
        echo "✔ {$label}" . PHP_EOL;
        return;
    }
    $failures++;
    echo "✘ {$label} : attendu " . var_export($expected, true) . ', obtenu ' . var_export($actual, true) . PHP_EOL;
}

/** Gestionnaire final qui répond 200 : sert à savoir si le middleware a laissé passer. */
function okHandler(): RequestHandlerInterface
{
    return new class implements RequestHandlerInterface {
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            return (new Response())->withStatus(200);
        }
    };
}

function req(string $method, string $path, ?array $body = null): ServerRequestInterface
{
    $request = (new ServerRequestFactory())->createServerRequest($method, $path);

    return $body === null ? $request : $request->withParsedBody($body);
}

$admin = new CurrentUser(1, 'Alice', 'Admin', Role::ADMIN);
$responsable = new CurrentUser(2, 'Bastien', 'Moreau', Role::RESPONSABLE);
$gm = new CurrentUser(3, 'Camille', 'Leroy', Role::GAME_MASTER);

// --- US-13 : seul un rôle autorisé modifie une session -------------------------------
check('US-13 admin peut modifier le planning', true, Authorization::canManagePlanning($admin));
check('US-13 responsable peut modifier le planning', true, Authorization::canManagePlanning($responsable));
check('US-13 game master ne peut pas modifier le planning', false, Authorization::canManagePlanning($gm));
check('US-13 visiteur anonyme ne peut pas modifier le planning', false, Authorization::canManagePlanning(null));

$refus = null;
try {
    Authorization::denyUnlessCanManagePlanning($gm);
} catch (AccessDeniedException $exception) {
    $refus = $exception->getMessage();
}
check('US-13 le service refuse un game master (exception)', true, $refus !== null);

$refus = null;
try {
    Authorization::denyUnlessCanManagePlanning($responsable);
} catch (AccessDeniedException) {
    $refus = 'refusé';
}
check('US-13 le service accepte un responsable', null, $refus);

// --- US-10 : connexion / déconnexion -------------------------------------------------
$session = new ArraySession();
$auth = new Auth($session);
check('US-10 personne de connecté au départ', null, $auth->user());
$auth->login($gm);
check('US-10 identifiant de session régénéré au login', 1, $session->regenerations);
check('US-10 utilisateur retrouvé dans la session', 'Camille Leroy', $auth->user()?->displayName());
check('US-10 rôle retrouvé dans la session', Role::GAME_MASTER, $auth->user()?->role);
$auth->logout();
check('US-10 déconnexion : session vidée', null, $auth->user());

$falsified = new Auth(new ArraySession(['auth_user' => ['id' => 9, 'prenom' => 'X', 'nom' => 'Y', 'role' => 'SUPERUSER']]));
check('US-11 rôle inconnu en session : traité comme non connecté', null, $falsified->user());

// --- US-11 : middleware de rôle ------------------------------------------------------
$renderer = new ViewRenderer(dirname(__DIR__) . '/templates');
$renderer->share('utilisateurConnecte', fn () => null);
$renderer->share('csrf', fn () => new Csrf(new ArraySession()));

$guard = static function (?CurrentUser $user, Role ...$roles) use ($renderer): RequireRole {
    $auth = new Auth(new ArraySession());
    if ($user !== null) {
        $auth->login($user);
    }

    return new RequireRole($auth, $renderer, ...$roles);
};

$anonymous = $guard(null, ...Authorization::PLANNING_MANAGERS)->process(req('GET', '/gestion'), okHandler());
check('US-11 anonyme sur /gestion : redirection', 302, $anonymous->getStatusCode());
check('US-11 anonyme sur /gestion : vers /connexion', '/connexion', $anonymous->getHeaderLine('Location'));
check('US-11 game master sur /gestion : 403', 403, $guard($gm, ...Authorization::PLANNING_MANAGERS)->process(req('GET', '/gestion'), okHandler())->getStatusCode());
check('US-11 responsable sur /gestion : 200', 200, $guard($responsable, ...Authorization::PLANNING_MANAGERS)->process(req('GET', '/gestion'), okHandler())->getStatusCode());
check('US-11 admin sur /gestion : 200', 200, $guard($admin, ...Authorization::PLANNING_MANAGERS)->process(req('GET', '/gestion'), okHandler())->getStatusCode());
check('US-11 game master sur /espace : 200', 200, $guard($gm, ...Authorization::STAFF)->process(req('GET', '/espace'), okHandler())->getStatusCode());
check('US-11 API anonyme : 401', 401, $guard(null, Role::RESPONSABLE)->process(req('POST', '/api/incidents'), okHandler())->getStatusCode());
check('US-11 API mauvais rôle : 403', 403, $guard($gm, Role::RESPONSABLE)->process(req('POST', '/api/incidents'), okHandler())->getStatusCode());

// --- US-12 : CSRF --------------------------------------------------------------------
$csrf = new Csrf(new ArraySession());
$token = $csrf->token();
check('US-12 jeton de 64 caractères hexadécimaux', 1, preg_match('/^[0-9a-f]{64}$/', $token));
check('US-12 jeton stable pendant la session', $token, $csrf->token());
check('US-12 jeton valide accepté', true, $csrf->isValid($token));
check('US-12 jeton absent refusé', false, $csrf->isValid(null));
check('US-12 jeton vide refusé', false, $csrf->isValid(''));
check('US-12 jeton faux refusé', false, $csrf->isValid(str_repeat('0', 64)));
check('US-12 jeton envoyé en tableau refusé sans erreur', false, $csrf->isValid([$token]));
check('US-12 session sans jeton : tout est refusé', false, (new Csrf(new ArraySession()))->isValid(''));

$middleware = new CsrfMiddleware($csrf, $renderer);
check('US-12 POST sans jeton : 403', 403, $middleware->process(req('POST', '/connexion', ['email' => 'a']), okHandler())->getStatusCode());
check('US-12 POST avec jeton faux : 403', 403, $middleware->process(req('POST', '/connexion', ['_csrf' => 'abc']), okHandler())->getStatusCode());
check('US-12 POST avec jeton valide : accepté', 200, $middleware->process(req('POST', '/connexion', ['_csrf' => $token]), okHandler())->getStatusCode());
check('US-12 jeton dans l\'en-tête X-CSRF-Token : accepté', 200, $middleware->process(req('POST', '/deconnexion')->withHeader('X-CSRF-Token', $token), okHandler())->getStatusCode());
check('US-12 GET : pas de contrôle', 200, $middleware->process(req('GET', '/connexion'), okHandler())->getStatusCode());
check('US-12 API JSON : hors CSRF (415 si pas JSON)', 200, $middleware->process(req('POST', '/api/reservations'), okHandler())->getStatusCode());

echo PHP_EOL . ($failures === 0 ? 'Tous les tests passent.' : "{$failures} échec(s).") . PHP_EOL;
exit($failures === 0 ? 0 : 1);

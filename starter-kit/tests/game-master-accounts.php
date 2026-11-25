<?php

declare(strict_types=1);

/**
 * Ajout d'un game master par le responsable (ou l'admin).
 * Lancement : composer test
 */

use App\Domain\Role;
use App\Repository\SessionRepository;
use App\Repository\UserRepository;
use App\Security\ArraySession;
use App\Security\Auth;
use App\Service\AuthService;
use App\Service\GameMasterService;
use App\Support\FixedClock;

require __DIR__ . '/support/bootstrap.php';

['admin' => $admin, 'responsable' => $responsable, 'camille' => $camille] = testUsers();
$database = testDatabase();
$users = new UserRepository($database);
$service = new GameMasterService($users, new SessionRepository($database), new FixedClock(new DateTimeImmutable('2026-11-25 12:00:00')));
$count = static fn (): int => (int) $database->count('utilisateur');
$valid = static fn (array $overrides = []): array => $overrides + [
    'prenom' => 'Élise', 'nom' => 'Martin', 'email' => 'elise@escapeops.test',
    'mot_de_passe' => 'Lanterne2026', 'confirmation' => 'Lanterne2026',
];

// --- Droits ---------------------------------------------------------------------------
check('Un game master ne peut pas ajouter de game master', 'AccessDeniedException', outcome(fn () => $service->create($camille, $valid())));
check('Un anonyme non plus', 'AccessDeniedException', outcome(fn () => $service->create(null, $valid())));
check('Aucun compte créé après les refus', 4, $count());

// --- Validation -----------------------------------------------------------------------
check('Prénom et nom obligatoires', ['prenom', 'nom'], errorFields(fn () => $service->create($responsable, $valid(['prenom' => ' ', 'nom' => '']))));
check('Nom trop long refusé', ['nom'], errorFields(fn () => $service->create($responsable, $valid(['nom' => str_repeat('a', 101)]))));
check('E-mail invalide refusé', ['email'], errorFields(fn () => $service->create($responsable, $valid(['email' => 'pas-un-email']))));
check('E-mail déjà utilisé refusé', ['email'], errorFields(fn () => $service->create($responsable, $valid(['email' => 'camille@escapeops.test']))));
check('E-mail déjà utilisé, en majuscules : refusé aussi', ['email'], errorFields(fn () => $service->create($responsable, $valid(['email' => 'CAMILLE@escapeops.test']))));
check('Mot de passe trop court refusé', ['mot_de_passe'], errorFields(fn () => $service->create($responsable, $valid(['mot_de_passe' => 'court1', 'confirmation' => 'court1']))));
check('Mot de passe sans chiffre refusé', ['mot_de_passe'], errorFields(fn () => $service->create($responsable, $valid(['mot_de_passe' => 'lanternemagique', 'confirmation' => 'lanternemagique']))));
check('Confirmation différente refusée', ['confirmation'], errorFields(fn () => $service->create($responsable, $valid(['confirmation' => 'Lanterne2027']))));
check('Valeur forgée (tableau) refusée sans plantage', ['email'], errorFields(fn () => $service->create($responsable, $valid(['email' => ['x@y.z']]))));
check('Toujours aucun compte créé', 4, $count());

// --- Création -------------------------------------------------------------------------
$id = $service->create($responsable, $valid(['email' => ' Elise@EscapeOps.test ', 'role' => 'ADMIN']));
$row = $database->get('utilisateur', ['nom', 'prenom', 'email', 'mot_de_passe', 'role'], ['id_utilisateur' => $id]);
check('Responsable : game master ajouté', 5, $count());
check('Le rôle est toujours GAME_MASTER (un champ « role » forgé est ignoré)', 'GAME_MASTER', $row['role']);
check('E-mail enregistré en minuscules, sans espaces', 'elise@escapeops.test', $row['email']);
check('Accents conservés', 'Élise', $row['prenom']);
check('Mot de passe stocké sous forme de hash, jamais en clair', [false, true], [$row['mot_de_passe'] === 'Lanterne2026', password_verify('Lanterne2026', $row['mot_de_passe'])]);
check('Il apparaît dans la liste des game masters', true, in_array('elise@escapeops.test', array_column($service->list(), 'email'), true));
check('L\'admin peut aussi ajouter un game master', 'OK', outcome(fn () => $service->create($admin, $valid(['email' => 'hugo@escapeops.test', 'prenom' => 'Hugo']))));

// --- Le nouveau compte peut se connecter -----------------------------------------------
$login = new AuthService($users, new Auth(new ArraySession()));
$logged = $login->attempt('elise@escapeops.test', 'Lanterne2026');
check('Connexion avec le nouveau compte', [true, Role::GAME_MASTER], [$logged !== null, $logged?->role]);
check('Mauvais mot de passe refusé', null, $login->attempt('elise@escapeops.test', 'Lanterne2025'));

finish('d\'ajout de game master');

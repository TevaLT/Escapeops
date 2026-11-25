<?php

declare(strict_types=1);

/**
 * Socle commun des tests métier : vérifications et base SQLite en mémoire.
 * Les vrais services et repositories tournent dessus, sans MySQL.
 */

use App\Domain\CurrentUser;
use App\Domain\Role;
use App\Domain\ValidationException;
use Medoo\Medoo;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

date_default_timezone_set('Europe/Paris');

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

/** Exécute $action et renvoie le nom court de l'exception levée, ou 'OK'. */
function outcome(callable $action): string
{
    try {
        $action();
        return 'OK';
    } catch (Throwable $exception) {
        return (new ReflectionClass($exception))->getShortName();
    }
}

/** Champs en erreur renvoyés par $action (liste vide si aucune erreur de validation). */
function errorFields(callable $action): array
{
    try {
        $action();
        return [];
    } catch (ValidationException $exception) {
        return array_keys($exception->errors());
    }
}

/** Message d'erreur de validation du champ $field levé par $action ('' si aucune erreur sur ce champ). */
function errorMessage(callable $action, string $field): string
{
    try {
        $action();
        return '';
    } catch (ValidationException $exception) {
        return $exception->errors()[$field] ?? '';
    }
}

function finish(string $suite): never
{
    global $failures;
    echo PHP_EOL . ($failures === 0 ? "Tous les tests {$suite} passent." : "{$failures} échec(s) {$suite}.") . PHP_EOL;
    exit($failures === 0 ? 0 : 1);
}

/** @return array{admin: CurrentUser, responsable: CurrentUser, camille: CurrentUser, david: CurrentUser} */
function testUsers(): array
{
    return [
        'admin' => new CurrentUser(1, 'Alice', 'Admin', Role::ADMIN),
        'responsable' => new CurrentUser(2, 'Bastien', 'Moreau', Role::RESPONSABLE),
        'camille' => new CurrentUser(3, 'Camille', 'Leroy', Role::GAME_MASTER),
        'david' => new CurrentUser(4, 'David', 'Nguyen', Role::GAME_MASTER),
    ];
}

/**
 * Base SQLite neuve, au schéma équivalent à 001_schema.sql (contraintes CHECK comprises) :
 * 2 salles, 4 comptes, 2 équipements installés dans la salle 1 (dont 1 critique).
 */
function testDatabase(): Medoo
{
    $database = new Medoo(['type' => 'sqlite', 'database' => ':memory:']);
    $database->pdo->exec('PRAGMA foreign_keys = ON');
    $database->pdo->exec(
        "CREATE TABLE utilisateur (id_utilisateur INTEGER PRIMARY KEY, nom TEXT NOT NULL, prenom TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE, mot_de_passe TEXT NOT NULL DEFAULT '', role TEXT NOT NULL);
         CREATE TABLE salle (id_salle INTEGER PRIMARY KEY AUTOINCREMENT, nom TEXT NOT NULL UNIQUE, theme TEXT NOT NULL,
            description TEXT NULL, duree_minutes INTEGER NOT NULL CHECK (duree_minutes > 0),
            capacite_min INTEGER NOT NULL, capacite_max INTEGER NOT NULL, temps_remise_etat INTEGER NOT NULL DEFAULT 20,
            CHECK (capacite_min >= 1 AND capacite_max >= capacite_min));
         CREATE TABLE equipement (id_equipement INTEGER PRIMARY KEY AUTOINCREMENT, nom TEXT NOT NULL,
            est_critique INTEGER NOT NULL DEFAULT 0, statut TEXT NOT NULL DEFAULT 'DISPONIBLE');
         CREATE TABLE salle_equipement (id_salle INTEGER NOT NULL REFERENCES salle (id_salle) ON DELETE CASCADE,
            id_equipement INTEGER NOT NULL REFERENCES equipement (id_equipement) ON DELETE CASCADE,
            date_affectation TEXT NULL, PRIMARY KEY (id_salle, id_equipement));
         CREATE TABLE session (id_session INTEGER PRIMARY KEY AUTOINCREMENT, id_salle INTEGER NOT NULL REFERENCES salle (id_salle),
            id_game_master INTEGER NULL REFERENCES utilisateur (id_utilisateur), date_debut TEXT NOT NULL, date_fin TEXT NOT NULL,
            statut TEXT NOT NULL DEFAULT 'PREVUE', CHECK (date_fin > date_debut));
         CREATE TABLE reservation (id_reservation INTEGER PRIMARY KEY AUTOINCREMENT, id_session INTEGER NOT NULL REFERENCES session (id_session),
            nom_groupe TEXT NOT NULL, contact TEXT NOT NULL, nb_joueurs INTEGER NOT NULL CHECK (nb_joueurs > 0),
            statut TEXT NOT NULL DEFAULT 'EN_ATTENTE', a_recontacter INTEGER NOT NULL DEFAULT 0,
            date_creation TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, date_expiration TEXT NULL, jeton TEXT NULL UNIQUE, code_suivi TEXT NULL);
         CREATE TABLE incident (id_incident INTEGER PRIMARY KEY AUTOINCREMENT, id_salle INTEGER NOT NULL REFERENCES salle (id_salle),
            id_equipement INTEGER NULL REFERENCES equipement (id_equipement), id_session INTEGER NULL REFERENCES session (id_session),
            id_declarant INTEGER NOT NULL REFERENCES utilisateur (id_utilisateur), description TEXT NOT NULL,
            gravite TEXT NOT NULL DEFAULT 'MINEURE', est_bloquant INTEGER NOT NULL DEFAULT 0, statut TEXT NOT NULL DEFAULT 'OUVERT',
            date_declaration TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, date_resolution TEXT NULL,
            CHECK (date_resolution IS NULL OR date_resolution >= date_declaration));
         CREATE TABLE checklist (id_checklist INTEGER PRIMARY KEY AUTOINCREMENT,
            id_session INTEGER NOT NULL REFERENCES session (id_session) ON DELETE CASCADE,
            id_game_master INTEGER NOT NULL REFERENCES utilisateur (id_utilisateur),
            salle_rangee INTEGER NOT NULL DEFAULT 0, materiel_teste INTEGER NOT NULL DEFAULT 0,
            securite_verifiee INTEGER NOT NULL DEFAULT 0, date_validation TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);

         INSERT INTO utilisateur (id_utilisateur, nom, prenom, email, role) VALUES
            (1, 'Admin', 'Alice', 'admin@escapeops.test', 'ADMIN'),
            (2, 'Moreau', 'Bastien', 'responsable@escapeops.test', 'RESPONSABLE'),
            (3, 'Leroy', 'Camille', 'camille@escapeops.test', 'GAME_MASTER'),
            (4, 'Nguyen', 'David', 'david@escapeops.test', 'GAME_MASTER');
         INSERT INTO salle VALUES
            (1, 'Le Cabinet des Ombres', 'Enquête victorienne', NULL, 60, 2, 6, 20),
            (2, 'Le Laboratoire Interdit', 'Science-fiction', NULL, 60, 3, 6, 25);
         INSERT INTO equipement VALUES (1, 'Mécanisme de la porte secrète', 1, 'DISPONIBLE'), (2, 'Coffre à code', 0, 'DISPONIBLE');
         INSERT INTO salle_equipement VALUES (1, 1, '2026-01-15'), (1, 2, '2026-01-15');"
    );

    return $database;
}

/** Ajoute une session et renvoie son identifiant. */
function addSession(Medoo $database, int $room, string $start, string $end, string $status = 'PREVUE', ?int $gm = null): int
{
    $database->insert('session', [
        'id_salle' => $room,
        'id_game_master' => $gm,
        'date_debut' => $start,
        'date_fin' => $end,
        'statut' => $status,
    ]);

    return (int) $database->id();
}

/** SessionService réel branché sur la base de test. */
function sessionService(Medoo $database, App\Support\Clock $clock): App\Service\SessionService
{
    return new App\Service\SessionService(
        new App\Repository\SessionRepository($database),
        new App\Repository\RoomRepository($database),
        new App\Repository\ReservationRepository($database),
        new App\Repository\UserRepository($database),
        new App\Repository\ChecklistRepository($database),
        $clock
    );
}

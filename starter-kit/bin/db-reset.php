<?php

declare(strict_types=1);

/**
 * Reconstruit la base depuis zéro : composer db:reset
 *
 * 1. supprime les vues, procédures et tables existantes ;
 * 2. applique database/migrations/*.sql dans l'ordre des numéros ;
 * 3. charge database/seed.sql.
 *
 * Chaque fichier est envoyé en une fois à MySQL (pas de DELIMITER : c'est une
 * commande du client mysql, pas du serveur). Un CREATE TRIGGER / PROCEDURE avec
 * BEGIN ... END fonctionne donc tel quel.
 */

use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

Dotenv::createImmutable($root)->safeLoad();
$db = (require $root . '/config/settings.php')['database'];

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $db['host'], $db['port'], $db['database'], $db['charset']),
    $db['username'],
    $db['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$name = $db['database'];
$pdo->exec('SET time_zone = ' . $pdo->quote($db['timezone']));

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

$objects = $pdo->prepare(
    'SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?'
);
$objects->execute([$name]);
foreach ($objects->fetchAll(PDO::FETCH_ASSOC) as $object) {
    $kind = $object['TABLE_TYPE'] === 'VIEW' ? 'VIEW' : 'TABLE';
    $pdo->exec(sprintf('DROP %s IF EXISTS `%s`', $kind, $object['TABLE_NAME']));
}

$routines = $pdo->prepare(
    'SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ?'
);
$routines->execute([$name]);
foreach ($routines->fetchAll(PDO::FETCH_ASSOC) as $routine) {
    $pdo->exec(sprintf('DROP %s IF EXISTS `%s`', $routine['ROUTINE_TYPE'], $routine['ROUTINE_NAME']));
}

$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

$files = glob($root . '/database/migrations/*.sql') ?: [];
sort($files);
$files[] = $root . '/database/seed.sql';

foreach ($files as $file) {
    // Parcourir chaque résultat fait remonter une erreur située après la 1re instruction.
    $statement = $pdo->query((string) file_get_contents($file));
    do {
        $statement->closeCursor();
    } while ($statement->nextRowset());
    echo '✔ ' . basename($file) . PHP_EOL;
}

echo "Base « {$name} » reconstruite." . PHP_EOL;

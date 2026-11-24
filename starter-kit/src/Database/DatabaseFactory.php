<?php

declare(strict_types=1);

namespace App\Database;

use Medoo\Medoo;

final class DatabaseFactory
{
    public static function create(array $config): Medoo
    {
        return new Medoo([
            'type' => $config['type'],
            'host' => $config['host'],
            'port' => $config['port'],
            'database' => $config['database'],
            'username' => $config['username'],
            'password' => $config['password'],
            'charset' => $config['charset'],
            // PHP et MySQL doivent partager le même fuseau : les règles E1 et E5 comparent des heures.
            'command' => [
                'SET time_zone = ' . self::quote($config['timezone'] ?? 'Europe/Paris'),
            ],
        ]);
    }

    private static function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}

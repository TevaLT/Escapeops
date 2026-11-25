<?php

declare(strict_types=1);

namespace App\Repository;

use Medoo\Medoo;

final class UserRepository
{
    public function __construct(private Medoo $database)
    {
    }

    /** Ligne complète, hash compris : réservée à la vérification du mot de passe. */
    public function findByEmail(string $email): ?array
    {
        $row = $this->database->get('utilisateur', [
            'id_utilisateur',
            'nom',
            'prenom',
            'email',
            'mot_de_passe',
            'role',
        ], ['email' => $email]);

        return is_array($row) ? $row : null;
    }

    /** Comptes d'un rôle, sans le hash du mot de passe. */
    public function byRole(string $role): array
    {
        return $this->database->select('utilisateur', ['id_utilisateur', 'nom', 'prenom', 'email', 'role'], [
            'role' => $role,
            'ORDER' => ['nom' => 'ASC', 'prenom' => 'ASC'],
        ]);
    }

    public function findWithRole(int $id, string $role): ?array
    {
        $row = $this->database->get('utilisateur', ['id_utilisateur', 'nom', 'prenom', 'email', 'role'], [
            'id_utilisateur' => $id,
            'role' => $role,
        ]);

        return is_array($row) ? $row : null;
    }

    public function updatePasswordHash(int $id, string $hash): void
    {
        $this->database->update('utilisateur', ['mot_de_passe' => $hash], ['id_utilisateur' => $id]);
    }

    public function emailExists(string $email): bool
    {
        return $this->database->has('utilisateur', ['email' => $email]);
    }

    /** Crée un compte ; $hash est déjà le résultat de password_hash (jamais de mot de passe en clair ici). */
    public function insert(string $lastName, string $firstName, string $email, string $hash, string $role): int
    {
        $this->database->insert('utilisateur', [
            'nom' => $lastName,
            'prenom' => $firstName,
            'email' => $email,
            'mot_de_passe' => $hash,
            'role' => $role,
        ]);

        return (int) $this->database->id();
    }
}

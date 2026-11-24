<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Le membre du personnel connecté. Ne contient jamais le hash du mot de passe.
 */
final class CurrentUser
{
    public function __construct(
        public readonly int $id,
        public readonly string $prenom,
        public readonly string $nom,
        public readonly Role $role
    ) {
    }

    public function displayName(): string
    {
        return $this->prenom . ' ' . $this->nom;
    }

    public function hasRole(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }
}

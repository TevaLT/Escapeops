<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\CurrentUser;
use App\Domain\Role;

/**
 * Qui est connecté ? Mémorise l'utilisateur dans la session.
 * La vérification du mot de passe est faite avant, par AuthService.
 */
final class Auth
{
    private const KEY = 'auth_user';

    public function __construct(private SessionStorage $session)
    {
    }

    public function login(CurrentUser $user): void
    {
        // Nouvel identifiant de session : un identifiant connu avant le login ne vaut plus rien.
        $this->session->regenerate();
        $this->session->set(self::KEY, [
            'id' => $user->id,
            'prenom' => $user->prenom,
            'nom' => $user->nom,
            'role' => $user->role->value,
        ]);
    }

    public function logout(): void
    {
        $this->session->destroy();
    }

    public function user(): ?CurrentUser
    {
        $data = $this->session->get(self::KEY);
        if (!is_array($data)) {
            return null;
        }

        $role = Role::tryFrom((string) ($data['role'] ?? ''));
        if ($role === null) {
            return null;
        }

        return new CurrentUser((int) $data['id'], (string) $data['prenom'], (string) $data['nom'], $role);
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }
}

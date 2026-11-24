<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\CurrentUser;
use App\Domain\Role;
use App\Repository\UserRepository;
use App\Security\Auth;

/**
 * Connexion du personnel (US-10).
 */
final class AuthService
{
    /** Hash factice : un e-mail inconnu coûte autant de temps qu'un mauvais mot de passe. */
    private const DUMMY_HASH = '$2y$12$AEPGUJZB3cwKhdsUgWUfIeQqIUZCAN6yTGSO4MRI0I8xOAvAuQYmq';

    public function __construct(
        private UserRepository $users,
        private Auth $auth
    ) {
    }

    /**
     * Connecte l'utilisateur si e-mail et mot de passe correspondent.
     * Le message d'erreur reste le même dans tous les cas, pour ne pas révéler
     * quels e-mails existent.
     */
    public function attempt(string $email, string $password): ?CurrentUser
    {
        $email = trim($email);
        if ($email === '' || $password === '' || mb_strlen($email) > 150 || strlen($password) > 1024) {
            return null;
        }

        $row = $this->users->findByEmail($email);
        $hash = $row['mot_de_passe'] ?? self::DUMMY_HASH;

        if (!password_verify($password, $hash) || $row === null) {
            return null;
        }

        $role = Role::tryFrom((string) $row['role']);
        if ($role === null) {
            return null;
        }

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $this->users->updatePasswordHash((int) $row['id_utilisateur'], password_hash($password, PASSWORD_DEFAULT));
        }

        $user = new CurrentUser((int) $row['id_utilisateur'], (string) $row['prenom'], (string) $row['nom'], $role);
        $this->auth->login($user);

        return $user;
    }

    public function logout(): void
    {
        $this->auth->logout();
    }
}

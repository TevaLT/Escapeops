<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Authorization;
use App\Domain\CurrentUser;
use App\Domain\Field;
use App\Domain\Role;
use App\Domain\ValidationException;
use App\Repository\SessionRepository;
use App\Repository\UserRepository;
use App\Support\Clock;
use PDOException;

/**
 * Fiches game master (US-24) : liste et détail avec leurs sessions à venir,
 * et ajout d'un game master par le responsable ou l'admin.
 */
final class GameMasterService
{
    /** Limites des colonnes SQL (utilisateur.nom / prenom VARCHAR(100), email VARCHAR(150)). */
    public const MAX_NAME = 100;
    public const MAX_EMAIL = 150;
    public const MIN_PASSWORD = 10;
    /** Au-delà, bcrypt ignore la fin du mot de passe ; la connexion refuse aussi plus de 1024 octets. */
    public const MAX_PASSWORD = 72;

    public function __construct(
        private UserRepository $users,
        private SessionRepository $sessions,
        private Clock $clock
    ) {
    }

    public function list(): array
    {
        $from = $this->clock->now()->setTime(0, 0);
        $list = [];
        foreach ($this->users->byRole(Role::GAME_MASTER->value) as $gm) {
            $upcoming = $this->sessions->search(['gameMaster' => $gm['id_utilisateur'], 'from' => $from, 'activeOnly' => true]);
            $list[] = $gm + ['nb_sessions' => count($upcoming), 'prochaine' => $upcoming[0]['date_debut'] ?? null];
        }

        return $list;
    }

    public function get(int $id): ?array
    {
        $gm = $this->users->findWithRole($id, Role::GAME_MASTER->value);
        if ($gm === null) {
            return null;
        }

        return $gm + ['sessions' => $this->sessions->search([
            'gameMaster' => $id,
            'from' => $this->clock->now()->setTime(0, 0),
        ])];
    }

    /**
     * Ajoute un game master (responsable ou admin). Le rôle est toujours GAME_MASTER, quel que soit le formulaire reçu.
     * Le mot de passe initial est choisi par le responsable et stocké uniquement sous forme de hash.
     *
     * @param array<string, mixed> $input prenom, nom, email, mot_de_passe, confirmation
     * @return int identifiant du nouveau compte
     * @throws \App\Domain\AccessDeniedException|ValidationException
     */
    public function create(?CurrentUser $user, array $input): int
    {
        Authorization::denyUnlessCanCreateGameMaster($user);

        [$firstName, $lastName, $email, $password] = $this->validate($input);

        try {
            return $this->users->insert($lastName, $firstName, $email, password_hash($password, PASSWORD_DEFAULT), Role::GAME_MASTER->value);
        } catch (PDOException $exception) {
            // Deux créations simultanées avec le même e-mail : la contrainte UNIQUE de la base tranche.
            if ($exception->getCode() === '23000') {
                throw new ValidationException(['email' => 'Un compte utilise déjà cette adresse e-mail.']);
            }
            throw $exception;
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     * @throws ValidationException
     */
    private function validate(array $input): array
    {
        $errors = [];

        $firstName = Field::text($input['prenom'] ?? null);
        if ($firstName === '' || mb_strlen($firstName) > self::MAX_NAME) {
            $errors['prenom'] = 'Indiquez un prénom (' . self::MAX_NAME . ' caractères au plus).';
        }

        $lastName = Field::text($input['nom'] ?? null);
        if ($lastName === '' || mb_strlen($lastName) > self::MAX_NAME) {
            $errors['nom'] = 'Indiquez un nom (' . self::MAX_NAME . ' caractères au plus).';
        }

        // E-mail enregistré en minuscules : c'est l'identifiant de connexion.
        $email = mb_strtolower(Field::text($input['email'] ?? null));
        if ($email === '' || mb_strlen($email) > self::MAX_EMAIL || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Indiquez une adresse e-mail valide.';
        } elseif ($this->users->emailExists($email)) {
            $errors['email'] = 'Un compte utilise déjà cette adresse e-mail.';
        }

        $password = is_string($input['mot_de_passe'] ?? null) ? $input['mot_de_passe'] : '';
        $confirmation = is_string($input['confirmation'] ?? null) ? $input['confirmation'] : '';
        if (mb_strlen($password) < self::MIN_PASSWORD || strlen($password) > self::MAX_PASSWORD) {
            $errors['mot_de_passe'] = sprintf('Le mot de passe doit contenir au moins %d caractères.', self::MIN_PASSWORD);
        } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $errors['mot_de_passe'] = 'Le mot de passe doit contenir au moins une lettre et un chiffre.';
        } elseif (!hash_equals($password, $confirmation)) {
            $errors['confirmation'] = 'Les deux mots de passe ne sont pas identiques.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [$firstName, $lastName, $email, $password];
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Field;
use App\Domain\NotFoundException;
use App\Domain\ReservationOption;
use App\Domain\ReservationStateException;
use App\Domain\SlotAvailability;
use App\Domain\SlotUnavailableException;
use App\Domain\TrackingCode;
use App\Domain\ValidationException;
use App\Repository\ReservationRepository;
use App\Repository\SessionRepository;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * Réservation d'un créneau par un groupe, sans compte (US-23), en deux temps (E1) :
 *
 *  1. le groupe pose une option EN_ATTENTE de 15 minutes (US-30) et reçoit un lien secret ;
 *  2. depuis ce lien, il confirme dans le délai (→ CONFIRMEE) ou annule.
 *
 * Invariants : refus si la session est passée, annulée, déjà confirmée ou en option (US-31) ;
 * au plus une réservation confirmée par session ; une confirmation hors délai est refusée (US-33).
 * Le délai est recalculé à chaque requête avec l'horloge injectée, sans tâche planifiée (US-32).
 */
final class ReservationService
{
    public const MAX_GROUP = 100;
    public const MAX_CONTACT = 150;

    public function __construct(
        private SessionRepository $sessions,
        private ReservationRepository $reservations,
        private Clock $clock
    ) {
    }

    /**
     * Le créneau et sa disponibilité, pour afficher le formulaire.
     *
     * @return array<string, mixed>|null
     */
    public function slot(int $sessionId): ?array
    {
        $info = $this->sessions->bookingInfo($sessionId);
        if ($info === null) {
            return null;
        }

        $availability = SlotAvailability::of($info, $this->clock->now());

        return $info + [
            'disponibilite' => $availability,
            'motif' => $availability === SlotAvailability::LIBRE ? null : self::reason($availability, $info),
        ];
    }

    /**
     * E1 (US-30) : pose une option EN_ATTENTE de 15 minutes sur un créneau libre.
     *
     * @param array<string, mixed> $input nom_groupe, contact, nb_joueurs
     * @return array<string, mixed> la session (salle, début…) + jeton et date_expiration de l'option
     * @throws NotFoundException|SlotUnavailableException|ValidationException
     */
    public function placeOption(int $sessionId, array $input): array
    {
        // Lecture, vérification et écriture dans une seule transaction, ligne de la session verrouillée
        // (SELECT ... FOR UPDATE) : deux groupes qui valident à la même seconde passent l'un après l'autre,
        // et le second voit l'option du premier.
        return $this->reservations->transaction(function () use ($sessionId, $input): array {
            $now = $this->clock->now();
            $info = $this->sessions->bookingInfo($sessionId, lock: true);
            if ($info === null) {
                throw new NotFoundException('Session introuvable.');
            }

            $availability = SlotAvailability::of($info, $now);
            if ($availability !== SlotAvailability::LIBRE) {
                throw new SlotUnavailableException($availability, self::reason($availability, $info));
            }

            [$group, $contact, $players] = self::validate($input, $info);

            $expiresAt = ReservationOption::expiresAt($now);
            $token = bin2hex(random_bytes(16));
            $code = TrackingCode::generate();   // pour retrouver ses réservations sur « Mes réservations »
            $this->reservations->insert($sessionId, $group, $contact, $players, ReservationOption::EN_ATTENTE, $now, $expiresAt, $token, $code);

            return $info + ['jeton' => $token, 'code_suivi' => $code, 'date_expiration' => $expiresAt->format('Y-m-d H:i:s')];
        });
    }

    /**
     * La réservation vue par le groupe (page du lien secret), avec son état calculé à l'instant.
     *
     * @return array<string, mixed>|null
     */
    public function byToken(string $token): ?array
    {
        $reservation = $this->reservations->findByToken($token);
        if ($reservation === null) {
            return null;
        }

        $now = $this->clock->now();
        $state = ReservationOption::state($reservation, $now);
        $started = new DateTimeImmutable((string) $reservation['date_debut']) <= $now;

        return $reservation + [
            'etat' => $state,
            'peut_confirmer' => $state === ReservationOption::EN_ATTENTE,
            // Une option expirée ne garde plus rien : il n'y a rien à libérer (INC-TEV-01).
            'peut_annuler' => !in_array($state, [ReservationOption::ANNULEE, ReservationOption::EXPIREE], true) && !$started,
            'creneau' => $state === ReservationOption::EXPIREE ? $this->slotAfterExpiry((int) $reservation['id_session'], $now) : null,
        ];
    }

    /**
     * Option expirée : ce qu'est devenu le créneau à cet instant (même calcul E1 que pour poser une option ;
     * l'option expirée du groupe ne compte plus). La page dit donc si le créneau est vraiment libre.
     *
     * @return array{disponibilite: string, message: string}
     */
    private function slotAfterExpiry(int $sessionId, DateTimeImmutable $now): array
    {
        $info = $this->sessions->bookingInfo($sessionId);
        $availability = SlotAvailability::of($info, $now);

        return [
            'disponibilite' => $availability,
            'message' => match ($availability) {
                SlotAvailability::LIBRE => 'Le créneau est encore libre : vous pouvez poser une nouvelle option.',
                SlotAvailability::EN_OPTION => 'Le créneau a été repris par un autre groupe, qui le garde en option jusqu\'à '
                    . heure_fr((string) $info['option_expire_le']) . ' : il sera de nouveau proposé si cette option n\'est pas confirmée.',
                SlotAvailability::COMPLET => 'Le créneau a été réservé par un autre groupe : il n\'est plus disponible.',
                default => 'Le créneau n\'est plus réservable : la partie a commencé ou la session a été annulée.',
            },
        ];
    }

    /**
     * E1 (US-33) : confirme l'option, seulement si elle court encore.
     *
     * @return array<string, mixed> la réservation confirmée
     * @throws NotFoundException|ReservationStateException
     */
    public function confirm(string $token): array
    {
        return $this->reservations->transaction(function () use ($token): array {
            $reservation = $this->lockedReservation($token);
            $now = $this->clock->now();

            $state = ReservationOption::state($reservation, $now);
            if ($state === ReservationOption::EXPIREE) {
                // Un seul message : la disponibilité réelle du créneau est donnée à part par byToken().
                throw new ReservationStateException($state, sprintf(
                    'Votre option a expiré à %s : la réservation n\'a pas été enregistrée.',
                    heure_fr((string) $reservation['date_expiration'])
                ));
            }
            if ($state === ReservationOption::CONFIRMEE) {
                throw new ReservationStateException($state, 'Cette réservation est déjà confirmée.');
            }
            if ($state === ReservationOption::ANNULEE) {
                throw new ReservationStateException($state, 'Cette réservation a été annulée : elle ne peut plus être confirmée.');
            }

            // L'option court encore, mais la session a pu être annulée (ou commencer) entre-temps.
            $info = $this->sessions->bookingInfo((int) $reservation['id_session'], lock: true);
            $availability = SlotAvailability::of(['option_expire_le' => null] + $info, $now);
            if ($availability !== SlotAvailability::LIBRE) {
                throw new ReservationStateException($availability, self::reason($availability, $info));
            }

            // Écriture conditionnelle : la ligne doit être encore EN_ATTENTE et l'option encore active.
            $confirmed = $this->reservations->changeStatusIf(
                (int) $reservation['id_reservation'],
                ReservationOption::EN_ATTENTE,
                ReservationOption::CONFIRMEE,
                $now
            );
            if (!$confirmed) {
                throw self::concurrentChange();
            }

            return ['statut' => ReservationOption::CONFIRMEE] + $reservation;
        });
    }

    /**
     * Le groupe annule son option encore valide (le créneau est libéré tout de suite) ou sa réservation
     * confirmée, tant que la partie n'a pas commencé. Une option expirée est refusée (409) : elle ne garde
     * déjà plus le créneau, et la passer à ANNULEE effacerait de l'historique qu'elle a expiré.
     *
     * @throws NotFoundException|ReservationStateException
     */
    public function cancel(string $token): array
    {
        return $this->reservations->transaction(function () use ($token): array {
            $reservation = $this->lockedReservation($token);
            $now = $this->clock->now();

            $state = ReservationOption::state($reservation, $now);
            if ($state === ReservationOption::ANNULEE) {
                throw new ReservationStateException($state, 'Cette réservation est déjà annulée.');
            }
            if ($state === ReservationOption::EXPIREE) {
                throw new ReservationStateException($state, sprintf(
                    'Votre option a expiré à %s : elle ne vous gardait déjà plus le créneau, il n\'y avait rien à libérer.',
                    heure_fr((string) $reservation['date_expiration'])
                ));
            }
            if (new DateTimeImmutable((string) $reservation['date_debut']) <= $now) {
                throw new ReservationStateException('COMMENCEE', 'La partie a déjà commencé : la réservation ne peut plus être annulée en ligne.');
            }

            // Écriture conditionnelle : le statut doit être celui qui vient d'être lu (et l'option encore active).
            $cancelled = $this->reservations->changeStatusIf(
                (int) $reservation['id_reservation'],
                $state,
                ReservationOption::ANNULEE,
                $state === ReservationOption::EN_ATTENTE ? $now : null
            );
            if (!$cancelled) {
                throw self::concurrentChange();
            }

            return ['statut' => ReservationOption::ANNULEE] + $reservation;
        });
    }

    /**
     * Lit la réservation en la verrouillant (SELECT ... FOR UPDATE, qui verrouille aussi sa session par la
     * jointure), puis verrouille la session comme placeOption : deux clics simultanés, ou une confirmation
     * pendant qu'un autre groupe tente sa chance, passent l'un après l'autre.
     *
     * La toute première lecture est verrouillante : sous MySQL (REPEATABLE READ), une lecture simple figerait
     * un instantané antérieur au verrou, et l'on relirait un statut périmé (INC-TEV-01).
     *
     * @throws NotFoundException
     */
    private function lockedReservation(string $token): array
    {
        $reservation = $this->reservations->findByToken($token, lock: true)
            ?? throw new NotFoundException('Réservation introuvable.');

        $this->sessions->bookingInfo((int) $reservation['id_session'], lock: true);

        return $reservation;
    }

    /** 0 ligne modifiée par l'écriture conditionnelle : une autre requête est passée entre-temps. */
    private static function concurrentChange(): ReservationStateException
    {
        return new ReservationStateException(
            ReservationStateException::CONFLIT,
            'Cette réservation vient d\'être modifiée par une autre demande : rien n\'a été enregistré. Rechargez la page pour voir son état.'
        );
    }

    /**
     * @return array{0: string, 1: string, 2: int}
     * @throws ValidationException
     */
    private static function validate(array $input, array $info): array
    {
        $errors = [];

        $group = Field::text($input['nom_groupe'] ?? null);
        if ($group === '') {
            $errors['nom_groupe'] = 'Indiquez le nom de votre groupe.';
        } elseif (mb_strlen($group) > self::MAX_GROUP) {
            $errors['nom_groupe'] = 'Le nom du groupe ne doit pas dépasser ' . self::MAX_GROUP . ' caractères.';
        }

        $contact = Field::text($input['contact'] ?? null);
        $isEmail = filter_var($contact, FILTER_VALIDATE_EMAIL) !== false;
        $isPhone = preg_match('/^\+?[0-9][0-9 .-]{7,19}$/', $contact) === 1;
        if ($contact === '' || mb_strlen($contact) > self::MAX_CONTACT || (!$isEmail && !$isPhone)) {
            $errors['contact'] = 'Indiquez une adresse e-mail ou un numéro de téléphone valide.';
        }

        $min = (int) $info['capacite_min'];
        $max = (int) $info['capacite_max'];
        $players = Field::intBetween($input['nb_joueurs'] ?? null, $min, $max);
        if ($players === null) {
            $errors['nb_joueurs'] = sprintf('Cette salle accueille de %d à %d joueurs.', $min, $max);
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [$group, $contact, $players];
    }

    private static function reason(string $availability, array $info): string
    {
        return match ($availability) {
            SlotAvailability::COMPLET => 'Ce créneau est déjà réservé par un autre groupe.',
            SlotAvailability::EN_OPTION => 'Ce créneau est en option jusqu\'à ' . heure_fr((string) $info['option_expire_le'])
                . ' : réessayez après cette heure, il sera libéré si l\'option n\'est pas confirmée.',
            default => 'Ce créneau n\'est plus réservable : il est passé ou a été annulé.',
        };
    }
}

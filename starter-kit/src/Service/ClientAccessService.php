<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\ClientHistory;
use App\Domain\ReservationOption;
use App\Domain\TooManyAttemptsException;
use App\Domain\TrackingCode;
use App\Domain\ValidationException;
use App\Repository\ReservationRepository;
use App\Security\ClientAccess;
use App\Support\Clock;

/**
 * « Mes réservations » : le client, sans compte (cahier des charges § 3), retrouve ses réservations
 * avec son contact et le code de suivi reçu à la pose de son option.
 *
 *  - même message d'erreur que le contact ou le code soit faux : on ne révèle pas quels contacts existent ;
 *  - au plus 5 échecs par quart d'heure et par session, ensuite 429.
 */
final class ClientAccessService
{
    public const MAX_FAILURES = 5;
    public const WINDOW_SECONDS = 900;

    public function __construct(
        private ReservationRepository $reservations,
        private ClientAccess $access,
        private Clock $clock
    ) {
    }

    /**
     * @param array<string, mixed> $input contact, code
     * @throws ValidationException|TooManyAttemptsException
     */
    public function login(array $input): void
    {
        $now = $this->clock->now()->getTimestamp();
        $recent = array_values(array_filter(
            $this->access->failures(),
            static fn (int $at): bool => $at > $now - self::WINDOW_SECONDS
        ));
        if (count($recent) >= self::MAX_FAILURES) {
            throw new TooManyAttemptsException('Trop de tentatives : réessayez dans un quart d\'heure.');
        }

        $contactKey = TrackingCode::normalizeContact($input['contact'] ?? null);
        $code = TrackingCode::normalize($input['code'] ?? null);
        $reservation = $contactKey === '' || $code === null
            ? null
            : $this->reservations->findByCodeAndContact($code, $contactKey);

        if ($reservation === null) {
            $this->access->setFailures([...$recent, $now]);
            throw new ValidationException([
                'code' => 'Aucune réservation ne correspond à ce contact et à ce code. Vérifiez-les tels qu\'ils ont été saisis lors de la réservation.',
            ]);
        }

        $this->access->login($contactKey, (string) $reservation['contact']);
    }

    /**
     * Réservations du client reconnu (état de l'option calculé maintenant), ou null s'il n'est pas reconnu.
     * Elles sont aussi rangées en « à venir » (la plus proche d'abord) et en historique des parties
     * (la plus récente d'abord, avec ce qu'il est advenu de la partie).
     *
     * @return array{contact: string, reservations: list<array>, a_venir: list<array>, historique: list<array>, parties_jouees: int}|null
     */
    public function myReservations(): ?array
    {
        $contact = $this->access->contact();
        if ($contact === null) {
            return null;
        }

        $now = $this->clock->now();
        $reservations = array_map(
            static fn (array $r): array => $r + ['etat' => ReservationOption::state($r, $now)],
            $this->reservations->forContact($contact['cle'])
        );

        $upcoming = [];
        $history = [];
        foreach ($reservations as $reservation) {
            if (ClientHistory::isUpcoming($reservation, $now)) {
                $upcoming[] = $reservation;
            } else {
                $history[] = $reservation + ['resultat' => ClientHistory::outcome($reservation)];
            }
        }

        return [
            'contact' => $contact['libelle'],
            'reservations' => $reservations,
            'a_venir' => array_reverse($upcoming),   // la liste arrive de la plus lointaine à la plus proche
            'historique' => $history,
            'parties_jouees' => count(array_filter($history, static fn (array $r): bool => $r['resultat'] === ClientHistory::JOUEE)),
        ];
    }

    public function logout(): void
    {
        $this->access->logout();
    }
}

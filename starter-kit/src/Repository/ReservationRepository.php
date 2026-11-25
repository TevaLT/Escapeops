<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use Medoo\Medoo;
use PDO;
use Throwable;

final class ReservationRepository
{
    public function __construct(private Medoo $database)
    {
    }

    /**
     * Exécute $work dans une transaction : tout est enregistré, ou rien.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        $pdo = $this->database->pdo;
        $pdo->beginTransaction();
        try {
            $result = $work();
            $pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    public function insert(
        int $sessionId,
        string $group,
        string $contact,
        int $players,
        string $status,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $expiresAt = null,
        ?string $token = null
    ): int {
        $this->database->insert('reservation', [
            'id_session' => $sessionId,
            'nom_groupe' => $group,
            'contact' => $contact,
            'nb_joueurs' => $players,
            'statut' => $status,
            'date_creation' => $createdAt->format('Y-m-d H:i:s'),
            'date_expiration' => $expiresAt?->format('Y-m-d H:i:s'),
            'jeton' => $token,
        ]);

        return (int) $this->database->id();
    }

    /**
     * Réservation retrouvée par le lien secret du groupe, avec sa session et sa salle.
     */
    public function findByToken(string $token): ?array
    {
        $statement = $this->database->pdo->prepare(
            "SELECT r.id_reservation, r.id_session, r.nom_groupe, r.contact, r.nb_joueurs, r.statut,
                    r.date_creation, r.date_expiration, r.jeton,
                    s.id_salle, s.date_debut, s.date_fin, s.statut AS statut_session, sa.nom AS salle
               FROM reservation r
               JOIN session s ON s.id_session = r.id_session
               JOIN salle sa ON sa.id_salle = s.id_salle
              WHERE r.jeton = :jeton"
        );
        $statement->execute(['jeton' => $token]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function updateStatus(int $id, string $status): void
    {
        $this->database->update('reservation', ['statut' => $status], ['id_reservation' => $id]);
    }

    public function forSession(int $sessionId): array
    {
        return $this->database->select('reservation', [
            'id_reservation',
            'nom_groupe',
            'contact',
            'nb_joueurs',
            'statut',
            'a_recontacter',
            'date_creation',
            'date_expiration',
        ], ['id_session' => $sessionId, 'ORDER' => ['date_creation' => 'ASC']]);
    }

    /** Session annulée : le groupe confirmé est à recontacter (la réservation est gardée). */
    public function flagConfirmedForContact(int $sessionId): void
    {
        $this->database->update('reservation', ['a_recontacter' => true], [
            'id_session' => $sessionId,
            'statut' => 'CONFIRMEE',
        ]);
    }
}

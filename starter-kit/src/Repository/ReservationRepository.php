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
        ?string $token = null,
        ?string $trackingCode = null
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
            'code_suivi' => $trackingCode,
        ]);

        return (int) $this->database->id();
    }

    private const WITH_SESSION = "SELECT r.id_reservation, r.id_session, r.nom_groupe, r.contact, r.nb_joueurs, r.statut,
                    r.date_creation, r.date_expiration, r.jeton, r.code_suivi,
                    s.id_salle, s.date_debut, s.date_fin, s.statut AS statut_session, sa.nom AS salle
               FROM reservation r
               JOIN session s ON s.id_session = r.id_session
               JOIN salle sa ON sa.id_salle = s.id_salle";

    /**
     * Contact « comparable » en SQL : e-mail en minuscules, téléphone sans espaces, points ni tirets.
     * Même règle que TrackingCode::normalizeContact (fonctions LOWER / REPLACE communes à MySQL et SQLite).
     */
    private const CONTACT_KEY = "CASE WHEN r.contact LIKE '%@%' THEN LOWER(r.contact)
                ELSE REPLACE(REPLACE(REPLACE(r.contact, ' ', ''), '.', ''), '-', '') END";

    /**
     * Réservation retrouvée par le lien secret du groupe, avec sa session et sa salle.
     */
    /**
     * Avec $lock (SELECT ... FOR UPDATE), la ligne de la réservation et celle de sa session (jointure) sont
     * verrouillées jusqu'à la fin de la transaction, et la lecture renvoie la dernière version validée.
     * Sans verrou, MySQL (REPEATABLE READ) lirait l'instantané pris à la première lecture de la transaction.
     */
    public function findByToken(string $token, bool $lock = false): ?array
    {
        $statement = $this->database->pdo->prepare(
            self::WITH_SESSION . ' WHERE r.jeton = :jeton'
            // SQLite (tests) ne connaît pas FOR UPDATE : il verrouille déjà toute la base pendant une transaction.
            . ($lock && $this->database->type === 'mysql' ? ' FOR UPDATE' : '')
        );
        $statement->execute(['jeton' => $token]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** « Mes réservations » : la réservation qui porte ce code ET ce contact (normalisé), ou null. */
    public function findByCodeAndContact(string $code, string $contactKey): ?array
    {
        $statement = $this->database->pdo->prepare(
            self::WITH_SESSION . ' WHERE r.code_suivi = :code AND ' . self::CONTACT_KEY . ' = :contact LIMIT 1'
        );
        $statement->execute(['code' => $code, 'contact' => $contactKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** « Mes réservations » : toutes les réservations faites avec ce contact, la plus proche d'abord. */
    public function forContact(string $contactKey): array
    {
        $statement = $this->database->pdo->prepare(
            self::WITH_SESSION . ' WHERE ' . self::CONTACT_KEY . ' = :contact ORDER BY s.date_debut DESC, r.id_reservation DESC'
        );
        $statement->execute(['contact' => $contactKey]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Change le statut seulement si la ligne est encore dans l'état lu par le service ($from) et, avec
     * $activeAt, si l'option court encore à cet instant (date_expiration > $activeAt, même borne que
     * ReservationOption::isActive). Renvoie false si aucune ligne n'a été modifiée : une autre requête
     * l'a changée entre la lecture et l'écriture.
     */
    public function changeStatusIf(int $id, string $from, string $to, ?DateTimeImmutable $activeAt = null): bool
    {
        $sql = 'UPDATE reservation SET statut = :vers WHERE id_reservation = :id AND statut = :depuis';
        $params = ['vers' => $to, 'id' => $id, 'depuis' => $from];
        if ($activeAt !== null) {
            $sql .= ' AND date_expiration > :maintenant';
            $params['maintenant'] = $activeAt->format('Y-m-d H:i:s');
        }

        $statement = $this->database->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount() === 1;
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

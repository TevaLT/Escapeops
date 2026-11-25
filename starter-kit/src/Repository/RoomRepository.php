<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use Medoo\Medoo;
use PDO;

final class RoomRepository
{
    private const COLUMNS = [
        'id_salle',
        'nom',
        'theme',
        'description',
        'duree_minutes',
        'capacite_min',
        'capacite_max',
        'temps_remise_etat',
    ];

    public function __construct(private Medoo $database)
    {
    }

    public function all(): array
    {
        return $this->database->select('salle', self::COLUMNS, ['ORDER' => ['nom' => 'ASC']]);
    }

    public function find(int $id): ?array
    {
        $row = $this->database->get('salle', self::COLUMNS, ['id_salle' => $id]);

        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $data colonnes de la table salle */
    public function insert(array $data): int
    {
        $this->database->insert('salle', $data);

        return (int) $this->database->id();
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->database->update('salle', $data, ['id_salle' => $id]);
    }

    public function delete(int $id): void
    {
        $this->database->delete('salle', ['id_salle' => $id]);
    }

    public function nameTaken(string $name, ?int $excludeId): bool
    {
        $where = ['nom' => $name];
        if ($excludeId !== null) {
            $where['id_salle[!]'] = $excludeId;
        }

        return $this->database->has('salle', $where);
    }

    /** Sessions et incidents rattachés : une salle qui en a ne peut pas être supprimée. */
    public function usage(int $id): array
    {
        return [
            'sessions' => (int) $this->database->count('session', ['id_salle' => $id]),
            'incidents' => (int) $this->database->count('incident', ['id_salle' => $id]),
        ];
    }

    /**
     * Sessions à venir d'une salle, avec ce qu'il faut pour décider de leur disponibilité :
     * nombre de réservations confirmées et fin de la dernière option EN_ATTENTE.
     */
    public function upcomingSessions(int $roomId, DateTimeImmutable $now): array
    {
        $statement = $this->database->pdo->prepare(
            "SELECT s.id_session, s.date_debut, s.date_fin, s.statut,
                    SUM(r.statut = 'CONFIRMEE') AS nb_confirmees,
                    MAX(CASE WHEN r.statut = 'EN_ATTENTE' THEN r.date_expiration END) AS option_expire_le
               FROM session s
               LEFT JOIN reservation r ON r.id_session = s.id_session
              WHERE s.id_salle = :salle
                AND s.date_debut > :maintenant
                AND s.statut IN ('PREVUE', 'PRETE')
              GROUP BY s.id_session, s.date_debut, s.date_fin, s.statut
              ORDER BY s.date_debut"
        );
        $statement->execute([
            'salle' => $roomId,
            'maintenant' => $now->format('Y-m-d H:i:s'),
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}

<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use Medoo\Medoo;
use PDO;

/**
 * Équipements et association N–N salle_equipement (US-25).
 */
final class EquipmentRepository
{
    public function __construct(private Medoo $database)
    {
    }

    /** Tous les équipements, avec le nombre de salles où chacun est installé. */
    public function all(): array
    {
        $statement = $this->database->pdo->query(
            'SELECT e.id_equipement, e.nom, e.est_critique, e.statut,
                    (SELECT COUNT(*) FROM salle_equipement se WHERE se.id_equipement = e.id_equipement) AS nb_salles
               FROM equipement e
              ORDER BY e.nom'
        );

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $row = $this->database->get('equipement', ['id_equipement', 'nom', 'est_critique', 'statut'], ['id_equipement' => $id]);

        return is_array($row) ? $row : null;
    }

    public function insert(string $name, bool $critical): int
    {
        $this->database->insert('equipement', ['nom' => $name, 'est_critique' => $critical, 'statut' => 'DISPONIBLE']);

        return (int) $this->database->id();
    }

    public function forRoom(int $roomId): array
    {
        $statement = $this->database->pdo->prepare(
            'SELECT e.id_equipement, e.nom, e.est_critique, e.statut, se.date_affectation
               FROM salle_equipement se
               JOIN equipement e ON e.id_equipement = se.id_equipement
              WHERE se.id_salle = :salle
              ORDER BY e.est_critique DESC, e.nom'
        );
        $statement->execute(['salle' => $roomId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function isAssigned(int $roomId, int $equipmentId): bool
    {
        return $this->database->has('salle_equipement', ['id_salle' => $roomId, 'id_equipement' => $equipmentId]);
    }

    public function assign(int $roomId, int $equipmentId, DateTimeImmutable $date): void
    {
        $this->database->insert('salle_equipement', [
            'id_salle' => $roomId,
            'id_equipement' => $equipmentId,
            'date_affectation' => $date->format('Y-m-d'),
        ]);
    }

    public function unassign(int $roomId, int $equipmentId): void
    {
        $this->database->delete('salle_equipement', ['id_salle' => $roomId, 'id_equipement' => $equipmentId]);
    }
}

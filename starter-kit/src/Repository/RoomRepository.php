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

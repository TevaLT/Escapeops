<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use Medoo\Medoo;
use PDO;

final class IncidentRepository
{
    private const FORMAT = 'Y-m-d H:i:s';

    public function __construct(private Medoo $database)
    {
    }

    /**
     * Incidents avec la salle, l'équipement et le déclarant.
     * $status : 'OUVERT', 'RESOLU' ou null pour tous ; les ouverts d'abord, puis les plus récents.
     */
    public function list(?string $status = null, ?int $limit = null): array
    {
        $statement = $this->database->pdo->prepare(
            'SELECT i.id_incident, i.id_salle, i.id_equipement, i.id_session, i.description, i.gravite,
                    i.est_bloquant, i.statut, i.date_declaration, i.date_resolution,
                    sa.nom AS salle, e.nom AS equipement, e.est_critique,
                    u.prenom AS declarant_prenom, u.nom AS declarant_nom
               FROM incident i
               JOIN salle sa ON sa.id_salle = i.id_salle
               JOIN utilisateur u ON u.id_utilisateur = i.id_declarant
               LEFT JOIN equipement e ON e.id_equipement = i.id_equipement'
            . ($status !== null ? ' WHERE i.statut = :statut' : '')
            . " ORDER BY CASE WHEN i.statut = 'OUVERT' THEN 0 ELSE 1 END, i.date_declaration DESC"
            . ($limit !== null ? ' LIMIT ' . max(1, $limit) : '')
        );
        $statement->execute($status !== null ? ['statut' => $status] : []);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $row = $this->database->get('incident', ['id_incident', 'id_salle', 'statut', 'date_declaration'], ['id_incident' => $id]);

        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $data */
    public function insert(array $data): int
    {
        $this->database->insert('incident', $data);

        return (int) $this->database->id();
    }

    public function resolve(int $id, DateTimeImmutable $at): void
    {
        $this->database->update('incident', [
            'statut' => 'RESOLU',
            'date_resolution' => $at->format(self::FORMAT),
        ], ['id_incident' => $id]);
    }
}

<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use Medoo\Medoo;
use PDO;

/**
 * Indicateurs du tableau de bord (US-28).
 * (Cette requête deviendra la vue v_dashboard_exploitation, US-63.)
 */
final class DashboardRepository
{
    public function __construct(private Medoo $database)
    {
    }

    /** Pour chaque salle : incidents ouverts, incidents bloquants, partie en cours, prochaine session. */
    public function roomStatus(DateTimeImmutable $now): array
    {
        $statement = $this->database->pdo->prepare(
            "SELECT sa.id_salle, sa.nom,
                    (SELECT COUNT(*) FROM incident i
                      WHERE i.id_salle = sa.id_salle AND i.statut = 'OUVERT') AS incidents_ouverts,
                    (SELECT COUNT(*) FROM incident i
                      WHERE i.id_salle = sa.id_salle AND i.statut = 'OUVERT' AND i.est_bloquant = 1) AS incidents_bloquants,
                    (SELECT COUNT(*) FROM session s
                      WHERE s.id_salle = sa.id_salle AND s.statut = 'EN_COURS') AS parties_en_cours,
                    (SELECT MIN(s.date_debut) FROM session s
                      WHERE s.id_salle = sa.id_salle AND s.date_debut > :maintenant
                        AND s.statut IN ('PREVUE', 'PRETE')) AS prochaine_session
               FROM salle sa
              ORDER BY sa.nom"
        );
        $statement->execute(['maintenant' => $now->format('Y-m-d H:i:s')]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}

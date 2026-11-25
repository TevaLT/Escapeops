<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use Medoo\Medoo;
use PDO;

/**
 * Indicateurs du tableau de bord (US-28), lus dans la vue v_dashboard_exploitation (US-63).
 * Les « prochaines sessions » du tableau de bord viennent, elles, de v_planning_detaille
 * (SessionService::upcoming() → SessionRepository::search()).
 */
final class DashboardRepository
{
    public function __construct(private Medoo $database)
    {
    }

    /**
     * Pour chaque salle : incidents ouverts, incidents bloquants, partie en cours, salle bloquée (vue),
     * plus la prochaine session, calculée ici car elle dépend de l'heure de l'application.
     */
    public function roomStatus(DateTimeImmutable $now): array
    {
        $statement = $this->database->pdo->prepare(
            "SELECT v.id_salle, v.nom, v.incidents_ouverts, v.incidents_bloquants, v.parties_en_cours, v.est_bloquee,
                    (SELECT MIN(s.date_debut) FROM session s
                      WHERE s.id_salle = v.id_salle AND s.date_debut > :maintenant
                        AND s.statut IN ('PREVUE', 'PRETE')) AS prochaine_session
               FROM v_dashboard_exploitation v
              ORDER BY v.nom"
        );
        $statement->execute(['maintenant' => $now->format('Y-m-d H:i:s')]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}

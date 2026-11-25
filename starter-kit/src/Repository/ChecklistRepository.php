<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use Medoo\Medoo;
use PDO;

/**
 * Checklists d'ouverture (E10). Chaque envoi est gardé, complet ou non : c'est l'historique.
 */
final class ChecklistRepository
{
    public function __construct(private Medoo $database)
    {
    }

    public function insert(int $sessionId, int $gameMasterId, bool $tidy, bool $tested, bool $safety, DateTimeImmutable $at): int
    {
        $this->database->insert('checklist', [
            'id_session' => $sessionId,
            'id_game_master' => $gameMasterId,
            'salle_rangee' => $tidy ? 1 : 0,
            'materiel_teste' => $tested ? 1 : 0,
            'securite_verifiee' => $safety ? 1 : 0,
            'date_validation' => $at->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->database->id();
    }

    /** Dernière checklist envoyée pour la session (celle qui fait foi), ou null. */
    public function latestForSession(int $sessionId): ?array
    {
        return $this->forSession($sessionId)[0] ?? null;
    }

    /** Historique, du plus récent au plus ancien, avec le nom du game master. */
    public function forSession(int $sessionId): array
    {
        $statement = $this->database->pdo->prepare(
            'SELECT c.id_checklist, c.id_game_master, c.salle_rangee, c.materiel_teste, c.securite_verifiee,
                    c.date_validation, u.prenom AS gm_prenom, u.nom AS gm_nom
               FROM checklist c
               JOIN utilisateur u ON u.id_utilisateur = c.id_game_master
              WHERE c.id_session = :session
              ORDER BY c.date_validation DESC, c.id_checklist DESC'
        );
        $statement->execute(['session' => $sessionId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}

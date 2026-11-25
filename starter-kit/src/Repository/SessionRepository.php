<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use Medoo\Medoo;
use PDO;

final class SessionRepository
{
    private const FORMAT = 'Y-m-d H:i:s';

    public function __construct(private Medoo $database)
    {
    }

    /**
     * Sessions avec la salle, le game master et le groupe dont la réservation est confirmée,
     * lues dans la vue v_planning_detaille (US-62, database/migrations/002_vues.sql).
     * Les clés renvoyées sont celles des colonnes de la vue.
     *
     * Critères possibles (tous facultatifs) :
     *  id, from (finit après), day (commence ce jour-là), room, status, gameMaster,
     *  activeOnly (ni terminée ni annulée), limit.
     *
     * @param array<string, mixed> $criteria
     */
    public function search(array $criteria = []): array
    {
        $where = [];
        $params = [];

        if (isset($criteria['id'])) {
            $where[] = 'v.id_session = :id';
            $params['id'] = (int) $criteria['id'];
        }
        if (isset($criteria['from'])) {
            $where[] = 'v.date_fin >= :depuis';
            $params['depuis'] = $criteria['from']->format(self::FORMAT);
        }
        if (isset($criteria['day'])) {
            $where[] = 'v.date_debut >= :jour AND v.date_debut < :lendemain';
            $params['jour'] = $criteria['day']->setTime(0, 0)->format(self::FORMAT);
            $params['lendemain'] = $criteria['day']->setTime(0, 0)->modify('+1 day')->format(self::FORMAT);
        }
        if (isset($criteria['room'])) {
            $where[] = 'v.id_salle = :salle';
            $params['salle'] = (int) $criteria['room'];
        }
        if (isset($criteria['status'])) {
            $where[] = 'v.statut = :statut';
            $params['statut'] = (string) $criteria['status'];
        }
        if (isset($criteria['gameMaster'])) {
            $where[] = 'v.id_game_master = :gm';
            $params['gm'] = (int) $criteria['gameMaster'];
        }
        if (!empty($criteria['activeOnly'])) {
            $where[] = "v.statut NOT IN ('TERMINEE', 'ANNULEE')";
        }

        $sql = 'SELECT v.id_session, v.id_salle, v.id_game_master, v.date_debut, v.date_fin, v.statut,
                       v.salle, v.gm_prenom, v.gm_nom, v.groupe, v.option_groupe, v.option_expire_le
                  FROM v_planning_detaille v'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY v.date_debut, v.salle'
            . (isset($criteria['limit']) ? ' LIMIT ' . max(1, (int) $criteria['limit']) : '');

        $statement = $this->database->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Une session avec sa salle, son game master et son groupe (fiche session). */
    public function detail(int $id): ?array
    {
        return $this->search(['id' => $id])[0] ?? null;
    }

    public function find(int $id): ?array
    {
        $row = $this->database->get('session', [
            'id_session',
            'id_salle',
            'id_game_master',
            'date_debut',
            'date_fin',
            'statut',
        ], ['id_session' => $id]);

        return is_array($row) ? $row : null;
    }

    /**
     * Ce qu'il faut pour décider si la session est réservable (SlotAvailability) et contrôler
     * le nombre de joueurs. Avec $lock, la ligne est verrouillée jusqu'à la fin de la transaction
     * (SELECT ... FOR UPDATE) : deux groupes qui valident en même temps passent l'un après l'autre.
     */
    public function bookingInfo(int $id, bool $lock = false): ?array
    {
        $statement = $this->database->pdo->prepare(
            "SELECT s.id_session, s.id_salle, s.date_debut, s.date_fin, s.statut,
                    sa.nom AS salle, sa.capacite_min, sa.capacite_max,
                    (SELECT COUNT(*) FROM reservation r
                      WHERE r.id_session = s.id_session AND r.statut = 'CONFIRMEE') AS nb_confirmees,
                    (SELECT MAX(r.date_expiration) FROM reservation r
                      WHERE r.id_session = s.id_session AND r.statut = 'EN_ATTENTE') AS option_expire_le
               FROM session s
               JOIN salle sa ON sa.id_salle = s.id_salle
              WHERE s.id_session = :id"
            // SQLite (tests) ne connaît pas FOR UPDATE : il verrouille déjà toute la base pendant une transaction.
            . ($lock && $this->database->type === 'mysql' ? ' FOR UPDATE' : '')
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function insert(int $roomId, DateTimeImmutable $start, DateTimeImmutable $end, ?int $gameMasterId = null): int
    {
        $this->database->insert('session', [
            'id_salle' => $roomId,
            'id_game_master' => $gameMasterId,
            'date_debut' => $start->format(self::FORMAT),
            'date_fin' => $end->format(self::FORMAT),
            'statut' => 'PREVUE',
        ]);

        return (int) $this->database->id();
    }

    public function update(int $id, int $roomId, DateTimeImmutable $start, DateTimeImmutable $end, ?int $gameMasterId = null): void
    {
        $this->database->update('session', [
            'id_salle' => $roomId,
            'id_game_master' => $gameMasterId,
            'date_debut' => $start->format(self::FORMAT),
            'date_fin' => $end->format(self::FORMAT),
        ], ['id_session' => $id]);
    }

    /**
     * E5 (US-36) : première session non annulée du game master qui chevauche [start, end[.
     * Condition a.début < b.fin ET b.début < a.fin, inégalités strictes : un créneau jointif
     * (qui finit exactement à $start, ou commence exactement à $end) ne gêne pas.
     * Pas de temps de remise en état ici : il concerne la salle, pas la personne.
     */
    public function findGameMasterConflict(int $gameMasterId, DateTimeImmutable $start, DateTimeImmutable $end, ?int $excludeId): ?array
    {
        $statement = $this->database->pdo->prepare(
            "SELECT s.id_session, s.date_debut, s.date_fin, sa.nom AS salle
               FROM session s
               JOIN salle sa ON sa.id_salle = s.id_salle
              WHERE s.id_game_master = :gm
                AND s.statut <> 'ANNULEE'
                AND s.id_session <> :exclure
                AND s.date_debut < :fin
                AND :debut < s.date_fin
              ORDER BY s.date_debut
              LIMIT 1"
        );
        $statement->execute([
            'gm' => $gameMasterId,
            'exclure' => $excludeId ?? 0,
            'debut' => $start->format(self::FORMAT),
            'fin' => $end->format(self::FORMAT),
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Écrit le game master et l'état en un seul UPDATE, seulement si la session est toujours dans l'état
     * $expectedStatus (lu juste avant) : si la partie a démarré entre-temps, rien n'est modifié.
     *
     * @return bool la ligne a-t-elle été modifiée ?
     */
    public function assignGameMaster(int $id, ?int $gameMasterId, string $expectedStatus, string $newStatus): bool
    {
        $statement = $this->database->update(
            'session',
            ['id_game_master' => $gameMasterId, 'statut' => $newStatus],
            ['id_session' => $id, 'statut' => $expectedStatus]
        );

        return $statement !== null && $statement->rowCount() > 0;
    }

    public function updateStatus(int $id, string $status): void
    {
        $this->database->update('session', ['statut' => $status], ['id_session' => $id]);
    }

    /**
     * Première session non annulée de la salle qui chevauche [from, to[.
     * Inégalités strictes : une session qui se termine exactement à $from ne gêne pas.
     */
    public function findRoomConflict(int $roomId, DateTimeImmutable $from, DateTimeImmutable $to, ?int $excludeId): ?array
    {
        $statement = $this->database->pdo->prepare(
            "SELECT id_session, date_debut, date_fin
               FROM session
              WHERE id_salle = :salle
                AND statut <> 'ANNULEE'
                AND id_session <> :exclure
                AND date_debut < :jusqua
                AND date_fin > :depuis
              ORDER BY date_debut
              LIMIT 1"
        );
        $statement->execute([
            'salle' => $roomId,
            'exclure' => $excludeId ?? 0,
            'depuis' => $from->format(self::FORMAT),
            'jusqua' => $to->format(self::FORMAT),
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}

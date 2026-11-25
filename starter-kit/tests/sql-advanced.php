<?php

declare(strict_types=1);

/**
 * SQL avancé (US-60 à US-63) : trigger d'historique, bilan de salle, vues du planning et du tableau de bord.
 * Tourne sur SQLite : le trigger et les vues sont recréés dans tests/support/bootstrap.php,
 * le bilan passe par la requête de secours de RoomRepository::bilan (pas de procédure stockée en SQLite).
 * Lancement : php tests/sql-advanced.php
 */

use App\Repository\DashboardRepository;
use App\Repository\RoomRepository;
use App\Repository\SessionRepository;
use App\Service\RoomService;
use App\Support\FixedClock;

require __DIR__ . '/support/bootstrap.php';

['responsable' => $responsable, 'camille' => $camille] = testUsers();
$database = testDatabase();
$clock = new FixedClock(new DateTimeImmutable('2026-11-24 12:00:00'));
$sessions = new SessionRepository($database);
$rooms = new RoomRepository($database);
$history = static fn (int $id): array => $database->select(
    'historique_session',
    ['ancien_statut', 'nouveau_statut'],
    ['id_session' => $id, 'ORDER' => ['id_historique' => 'ASC']]
);

// --- US-60 trigger trg_session_history -----------------------------------------------
$id = addSession($database, 1, '2026-11-24 18:00:00', '2026-11-24 19:00:00', 'PREVUE', 3);
check('US-60 une session créée n\'a pas d\'historique (AFTER UPDATE seulement)', [], $history($id));

$sessions->updateStatus($id, 'PRETE');
check('US-60 changement de statut : une ligne ancien → nouveau', [['ancien_statut' => 'PREVUE', 'nouveau_statut' => 'PRETE']], $history($id));
check('US-60 date du changement renseignée', true, $database->get('historique_session', 'date_changement', ['id_session' => $id]) !== null);

$sessions->updateStatus($id, 'PRETE');
check('US-60 même statut réécrit : aucune ligne de plus', 1, count($history($id)));

$sessions->update($id, 1, new DateTimeImmutable('2026-11-24 18:30:00'), new DateTimeImmutable('2026-11-24 19:30:00'), 4);
check('US-60 horaires et game master modifiés sans toucher au statut : aucune ligne', 1, count($history($id)));

$sessions->updateStatus($id, 'EN_COURS');
$sessions->updateStatus($id, 'INTERROMPUE');
$sessions->updateStatus($id, 'EN_COURS');
$sessions->updateStatus($id, 'TERMINEE');
check(
    'US-60 interruption et reprise tracées dans l\'ordre (E6)',
    ['PRETE', 'EN_COURS', 'INTERROMPUE', 'EN_COURS', 'TERMINEE'],
    array_column($history($id), 'nouveau_statut')
);

$sessions->assignGameMaster($id, 3, 'PREVUE', 'PRETE');
check('US-60 UPDATE conditionnel qui ne touche aucune ligne : rien de tracé', 5, count($history($id)));

// --- US-61 bilan de salle (sp_bilan_salle) -------------------------------------------
check('US-61 salle sans session ni contrôle : tout à zéro', [
    'nb_terminees' => 0, 'nb_annulees' => 0, 'nb_interrompues' => 0, 'dernier_controle' => null, 'terminees_depuis_controle' => 0,
], $rooms->bilan(2));

// Salle 1 : $id (TERMINEE le 24/11) + 2 terminées avant le contrôle du 20/11, 1 annulée, 1 interrompue.
addSession($database, 1, '2026-11-10 14:00:00', '2026-11-10 15:00:00', 'TERMINEE');
addSession($database, 1, '2026-11-12 14:00:00', '2026-11-12 15:00:00', 'TERMINEE');
addSession($database, 1, '2026-11-21 14:00:00', '2026-11-21 15:00:00', 'ANNULEE');
addSession($database, 1, '2026-11-22 14:00:00', '2026-11-22 15:00:00', 'INTERROMPUE');
addSession($database, 2, '2026-11-15 14:00:00', '2026-11-15 15:00:00', 'TERMINEE');

$bilan = $rooms->bilan(1);
check('US-61 sans contrôle : terminées / annulées / interrompues', [3, 1, 1], [$bilan['nb_terminees'], $bilan['nb_annulees'], $bilan['nb_interrompues']]);
check('US-61 sans contrôle : toutes les terminées comptent depuis le « dernier contrôle »', [null, 3], [$bilan['dernier_controle'], $bilan['terminees_depuis_controle']]);

$database->insert('controle_salle', ['id_salle' => 1, 'id_responsable' => 2, 'date_controle' => '2026-11-20 09:00:00']);
$bilan = $rooms->bilan(1);
check('US-61 après un contrôle : seules les terminées après lui comptent (annulée exclue)', ['2026-11-20 09:00:00', 1], [$bilan['dernier_controle'], $bilan['terminees_depuis_controle']]);
check('US-61 le contrôle ne change pas les totaux', [3, 1, 1], [$bilan['nb_terminees'], $bilan['nb_annulees'], $bilan['nb_interrompues']]);
check('US-61 le contrôle d\'une salle ne touche pas l\'autre', [1, null, 1], [$rooms->bilan(2)['nb_terminees'], $rooms->bilan(2)['dernier_controle'], $rooms->bilan(2)['terminees_depuis_controle']]);

$roomService = new RoomService($rooms, $clock);
check('US-61 bilan : anonyme refusé', 'AccessDeniedException', outcome(fn () => $roomService->bilan(null, 1)));
check('US-61 bilan : game master refusé', 'AccessDeniedException', outcome(fn () => $roomService->bilan($camille, 1)));
check('US-61 bilan : responsable autorisé', 3, $roomService->bilan($responsable, 1)['nb_terminees']);
check('US-61 bilan : salle inconnue introuvable', 'NotFoundException', outcome(fn () => $roomService->bilan($responsable, 999)));

// --- US-62 v_planning_detaille : mêmes données qu'avant la vue -----------------------
$database->insert('reservation', ['id_session' => $id, 'nom_groupe' => 'Les Curieux', 'contact' => 'a@example.test', 'nb_joueurs' => 4, 'statut' => 'CONFIRMEE']);
$pending = addSession($database, 2, '2026-11-25 10:00:00', '2026-11-25 11:00:00');
$database->insert('reservation', ['id_session' => $pending, 'nom_groupe' => 'Famille Martin', 'contact' => 'm@example.test', 'nb_joueurs' => 3, 'statut' => 'EN_ATTENTE', 'date_expiration' => '2026-11-24 12:15:00']);

// Requête de SessionRepository::search() avant US-62, gardée ici comme référence.
$legacy = $database->pdo->query(
    "SELECT s.id_session, s.id_salle, s.id_game_master, s.date_debut, s.date_fin, s.statut,
            sa.nom AS salle, gm.prenom AS gm_prenom, gm.nom AS gm_nom,
            (SELECT r.nom_groupe FROM reservation r WHERE r.id_session = s.id_session AND r.statut = 'CONFIRMEE' LIMIT 1) AS groupe,
            (SELECT r.nom_groupe FROM reservation r WHERE r.id_session = s.id_session AND r.statut = 'EN_ATTENTE'
              ORDER BY r.date_expiration DESC LIMIT 1) AS option_groupe,
            (SELECT MAX(r.date_expiration) FROM reservation r WHERE r.id_session = s.id_session AND r.statut = 'EN_ATTENTE') AS option_expire_le
       FROM session s
       JOIN salle sa ON sa.id_salle = s.id_salle
       LEFT JOIN utilisateur gm ON gm.id_utilisateur = s.id_game_master
      ORDER BY s.date_debut, sa.nom"
)->fetchAll(PDO::FETCH_ASSOC);
check('US-62 search() par la vue : mêmes lignes, mêmes clés, même ordre', $legacy, $sessions->search());

$detail = $sessions->detail($id);
check('US-62 détail : salle, game master et groupe confirmé', ['Le Cabinet des Ombres', 'David', 'Les Curieux'], [$detail['salle'], $detail['gm_prenom'], $detail['groupe']]);
$option = $sessions->detail($pending);
check('US-62 détail : option en attente et son expiration', ['Famille Martin', '2026-11-24 12:15:00', null], [$option['option_groupe'], $option['option_expire_le'], $option['groupe']]);
check('US-62 filtres conservés (salle + actives)', [$pending], array_map('intval', array_column($sessions->search(['room' => 2, 'activeOnly' => true]), 'id_session')));
check('US-62 filtre game master', [$id], array_map('intval', array_column($sessions->search(['gameMaster' => 4]), 'id_session')));

// --- US-63 v_dashboard_exploitation --------------------------------------------------
$database->insert('incident', ['id_salle' => 2, 'id_declarant' => 3, 'description' => 'Fumigène HS', 'gravite' => 'CRITIQUE', 'est_bloquant' => 1, 'date_declaration' => '2026-11-24 11:00:00']);
$database->insert('incident', ['id_salle' => 2, 'id_declarant' => 3, 'description' => 'Ampoule', 'date_declaration' => '2026-11-24 11:00:00']);
$status = array_column((new DashboardRepository($database))->roomStatus($clock->now()), null, 'nom');
check('US-63 salle 2 : 2 incidents ouverts dont 1 bloquant, salle bloquée', [2, 1, 1], [
    (int) $status['Le Laboratoire Interdit']['incidents_ouverts'],
    (int) $status['Le Laboratoire Interdit']['incidents_bloquants'],
    (int) $status['Le Laboratoire Interdit']['est_bloquee'],
]);
check('US-63 salle 1 : aucun incident, pas bloquée', [0, 0], [(int) $status['Le Cabinet des Ombres']['incidents_ouverts'], (int) $status['Le Cabinet des Ombres']['est_bloquee']]);
check('US-63 prochaine session selon l\'horloge de l\'application', '2026-11-25 10:00:00', $status['Le Laboratoire Interdit']['prochaine_session']);

finish('du SQL avancé');

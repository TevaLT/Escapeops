<?php

declare(strict_types=1);

/**
 * E10 — « Checklist avant ouverture » (US-39 à US-42).
 * Lancement : composer test
 */

use App\Domain\OpeningChecklist;
use App\Support\FixedClock;

require __DIR__ . '/support/bootstrap.php';

['admin' => $admin, 'responsable' => $responsable, 'camille' => $camille, 'david' => $david] = testUsers();
$database = testDatabase();
$service = sessionService($database, new FixedClock(new DateTimeImmutable('2026-11-24 17:30:00')));
$status = static fn (int $id): string => (string) $database->get('session', 'statut', ['id_session' => $id]);
$saved = static fn (int $id): int => (int) $database->count('checklist', ['id_session' => $id]);
$full = ['salle_rangee' => '1', 'materiel_teste' => '1', 'securite_verifiee' => '1'];
$twoOfThree = ['salle_rangee' => '1', 'materiel_teste' => '1'];

$session = addSession($database, 1, '2026-11-24 18:00:00', '2026-11-24 19:00:00', 'PREVUE', 3);

// --- Règle pure -----------------------------------------------------------------------
check('US-42 3/3 : complète', true, OpeningChecklist::isComplete(OpeningChecklist::fromInput($full)));
check('US-42 2/3 : incomplète', false, OpeningChecklist::isComplete(OpeningChecklist::fromInput($twoOfThree)));
check('US-42 valeur forgée (« oui », tableau) : non cochée', 0, OpeningChecklist::score(OpeningChecklist::fromInput(['salle_rangee' => 'oui', 'materiel_teste' => ['1'], 'securite_verifiee' => '2'])));
check('US-42 aucune checklist : incomplète', false, OpeningChecklist::isComplete(null));
check('US-42 ligne SQL 1/1/1 : complète', true, OpeningChecklist::isComplete(['salle_rangee' => 1, 'materiel_teste' => '1', 'securite_verifiee' => 1]));

// --- Droits : seul le game master affecté remplit la checklist -------------------------
check('US-39 autre game master : refus', 'AccessDeniedException', outcome(fn () => $service->submitChecklist($david, $session, $full)));
check('US-39 responsable : refus (c\'est le GM qui valide)', 'AccessDeniedException', outcome(fn () => $service->submitChecklist($responsable, $session, $full)));
check('US-39 anonyme : refus', 'AccessDeniedException', outcome(fn () => $service->submitChecklist(null, $session, $full)));
check('US-39 aucune checklist enregistrée après les refus de droit', 0, $saved($session));
check('US-39 formulaire proposé au game master affecté', true, $service->canFillChecklist($camille, ['statut' => 'PREVUE', 'id_game_master' => 3]));
check('US-39 formulaire non proposé au responsable', false, $service->canFillChecklist($responsable, ['statut' => 'PREVUE', 'id_game_master' => 3]));

// --- US-40 : 2/3 → non prête ----------------------------------------------------------
check('US-40 checklist 2/3 refusée', ['checklist'], errorFields(fn () => $service->submitChecklist($camille, $session, $twoOfThree)));
check('US-40 la session reste PREVUE', 'PREVUE', $status($session));
check('US-40 la checklist 2/3 est tout de même enregistrée (historique)', 1, $saved($session));
$message = errorMessage(fn () => $service->submitChecklist($camille, $session, $twoOfThree), 'checklist');
check('US-40 message « salle non prête » avec le point manquant', 'Salle non prête : checklist 2/3. Point(s) à valider : consignes de sécurité vérifiées.', $message);

// --- US-40 : POST forgé « etat=PRETE » sans checklist complète ------------------------
check('US-40 POST forgé etat=PRETE par le GM : refusé côté serveur', ['checklist'], errorFields(fn () => $service->changeStatus($camille, $session, 'PRETE')));
check('US-40 POST forgé etat=PRETE par l\'admin : refusé aussi', ['checklist'], errorFields(fn () => $service->changeStatus($admin, $session, 'PRETE')));
$empty = addSession($database, 2, '2026-11-24 18:00:00', '2026-11-24 19:00:00', 'PREVUE', 4);
check('US-40 sans aucune checklist : refusé', ['checklist'], errorFields(fn () => $service->changeStatus($david, $empty, 'PRETE')));
check('US-40 toujours PREVUE après les tentatives forgées', 'PREVUE', $status($session));

// --- US-40 : 3/3 → PRETE --------------------------------------------------------------
check('US-40 checklist 3/3 acceptée', 'OK', outcome(fn () => $service->submitChecklist($camille, $session, $full)));
check('US-40 la session passe à PRETE', 'PRETE', $status($session));
check('US-39 checklist enregistrée avec le GM, la date et les 3 valeurs', [3, 1, 1, 1, '2026-11-24 17:30:00'], (function () use ($database, $session): array {
    $row = $database->get('checklist', ['id_game_master', 'salle_rangee', 'materiel_teste', 'securite_verifiee', 'date_validation'], [
        'id_session' => $session, 'ORDER' => ['id_checklist' => 'DESC'],
    ]);
    return [(int) $row['id_game_master'], (int) $row['salle_rangee'], (int) $row['materiel_teste'], (int) $row['securite_verifiee'], $row['date_validation']];
})());
check('US-39 une session PRETE n\'accepte plus de checklist', ['statut'], errorFields(fn () => $service->submitChecklist($camille, $session, $full)));
check('US-39 formulaire non proposé sur une session prête', false, $service->canFillChecklist($camille, ['statut' => 'PRETE', 'id_game_master' => 3]));

// --- US-41 : historique sur la fiche session ------------------------------------------
$history = $service->detail($session)['checklists'];
check('US-41 historique : 3 checklists, la plus récente d\'abord', [3, 3, 2, 2], [count($history), OpeningChecklist::score($history[0]), OpeningChecklist::score($history[1]), OpeningChecklist::score($history[2])]);
check('US-41 historique : nom du game master', 'Camille Leroy', $history[0]['gm_prenom'] . ' ' . $history[0]['gm_nom']);

// --- Après PRETE : démarrage possible -------------------------------------------------
check('E10 session prête : le game master la démarre', 'OK', outcome(fn () => $service->changeStatus($camille, $session, 'EN_COURS')));

finish('E10 (checklist d\'ouverture)');

<?php

declare(strict_types=1);

/**
 * Évolution EV-TEV-01 (issue #8) — « Une checklist ne remplace pas un game master ».
 * Une session n'est PRETE que si la checklist est 3/3 ET qu'un game master admissible, sans conflit (E5),
 * est affecté. Si elle perd son unique game master avant le début, elle repasse à PREVUE.
 * Analyse : docs/evolutions/EV-TEV-01.md · Lancement : composer test
 */

use App\Support\FixedClock;

require __DIR__ . '/support/bootstrap.php';

['admin' => $admin, 'responsable' => $responsable, 'camille' => $camille, 'david' => $david] = testUsers();
$database = testDatabase();
$service = sessionService($database, new FixedClock(new DateTimeImmutable('2026-11-25 14:00:00')));
$status = static fn (int $id): string => (string) $database->get('session', 'statut', ['id_session' => $id]);
$gmOf = static fn (int $id): ?int => ($v = $database->get('session', 'id_game_master', ['id_session' => $id])) === null ? null : (int) $v;
$full = ['salle_rangee' => '1', 'materiel_teste' => '1', 'securite_verifiee' => '1'];
$addChecklist = static function (int $session, int $gm) use ($database): void {
    $database->insert('checklist', [
        'id_session' => $session, 'id_game_master' => $gm, 'salle_rangee' => 1, 'materiel_teste' => 1,
        'securite_verifiee' => 1, 'date_validation' => '2026-11-25 13:00:00',
    ]);
};

// --- Critère 1 : checklist 3/3, aucun game master → PRETE refusé ------------------------
// (cas réel : la checklist a été validée, puis le game master a été retiré)
$noGm = addSession($database, 1, '2026-11-25 18:00:00', '2026-11-25 19:00:00');
$addChecklist($noGm, 3);
check('Critère 1 : checklist 3/3 sans game master → PRETE refusé (POST forgé du responsable)', ['game_master'], errorFields(fn () => $service->changeStatus($responsable, $noGm, 'PRETE')));
check('Critère 1 : refusé aussi pour l\'admin', ['game_master'], errorFields(fn () => $service->changeStatus($admin, $noGm, 'PRETE')));
check('Critère 1 : la session reste PREVUE', 'PREVUE', $status($noGm));
check('Critère 1 : sans checklist ni game master, les deux motifs sont donnés', ['checklist', 'game_master'], errorFields(
    fn () => $service->changeStatus($responsable, addSession($database, 2, '2026-11-25 18:00:00', '2026-11-25 19:00:00'), 'PRETE')
));

// --- Critère 2 : checklist 3/3, game master affecté et sans conflit → PRETE autorisé -------
$staffed = addSession($database, 1, '2026-11-26 10:00:00', '2026-11-26 11:00:00', 'PREVUE', 3);
check('Critère 2 : checklist 3/3 du game master affecté → PRETE', 'OK', outcome(fn () => $service->submitChecklist($camille, $staffed, $full)));
check('Critère 2 : session PRETE', 'PRETE', $status($staffed));

// Game master affecté, mais déjà pris sur un créneau qui chevauche (donnée incohérente, ex. import) :
$busy = addSession($database, 1, '2026-11-26 14:00:00', '2026-11-26 15:00:00', 'PREVUE', 4);
addSession($database, 2, '2026-11-26 14:30:00', '2026-11-26 15:30:00', 'PREVUE', 4);
check('Critère 2 : game master en conflit horaire → PRETE refusé', ['game_master'], errorFields(fn () => $service->submitChecklist($david, $busy, $full)));
check('Critère 2 : la session en conflit reste PREVUE', 'PREVUE', $status($busy));

// --- Critère 3 : une session PRETE perd son unique game master avant le début -------------
$ready = addSession($database, 2, '2026-11-26 18:00:00', '2026-11-26 19:00:00', 'PREVUE', 3);
$database->insert('reservation', [
    'id_session' => $ready, 'nom_groupe' => 'Les Curieux', 'contact' => 'curieux@example.test',
    'nb_joueurs' => 4, 'statut' => 'CONFIRMEE', 'jeton' => str_repeat('a', 32),
]);
$service->submitChecklist($camille, $ready, $full);
check('Critère 3 : session prête avant le retrait', 'PRETE', $status($ready));
check('Critère 3 : le responsable retire le game master → état renvoyé PRETE puis PREVUE', ['avant' => 'PRETE', 'apres' => 'PREVUE'], $service->assignGameMaster($responsable, $ready, ''));
check('Critère 3 : la session n\'est plus prête (PREVUE)', 'PREVUE', $status($ready));
check('Critère 3 : plus de game master', null, $gmOf($ready));
check('Critère 3 : aucune réservation supprimée ni modifiée', [1, 'CONFIRMEE', 0], [
    (int) $database->count('reservation', ['id_session' => $ready]),
    $database->get('reservation', 'statut', ['id_session' => $ready]),
    (int) $database->get('reservation', 'a_recontacter', ['id_session' => $ready]),
]);
check('Critère 3 : elle ne peut plus démarrer', ['game_master'], errorFields(fn () => $service->changeStatus($responsable, $ready, 'EN_COURS')));
check('Critère 3 : l\'historique des checklists est conservé', 1, (int) $database->count('checklist', ['id_session' => $ready]));

// Un nouveau game master est affecté : il retrouve la checklist et revalide la salle
check('Critère 3 : David affecté à la session redevenue prévue', 'PREVUE', $service->assignGameMaster($responsable, $ready, '4')['apres']);
check('Critère 3 : David voit le formulaire de checklist', true, $service->canFillChecklist($david, $service->find($ready)));
check('Critère 3 : David valide 3/3 → de nouveau PRETE', 'OK', outcome(fn () => $service->submitChecklist($david, $ready, $full)));
check('Critère 3 : session de nouveau PRETE', 'PRETE', $status($ready));

// Remplacer le game master (et non le retirer) : il y a toujours un animateur, la session reste prête
check('Critère 3 bis : remplacement David → Camille sur une session prête : reste PRETE', 'PRETE', $service->assignGameMaster($responsable, $ready, '3')['apres']);
check('Critère 3 bis : Camille affectée', 3, $gmOf($ready));
addSession($database, 2, '2026-11-26 10:30:00', '2026-11-26 11:30:00', 'PREVUE', 4);
check('Critère 3 bis : remplacement par un game master en conflit refusé (E5)', ['game_master'], errorFields(fn () => $service->assignGameMaster($responsable, $staffed, '4')));
check('Critère 3 bis : après ce refus, rien ne change (Camille, PRETE)', [3, 'PRETE'], [$gmOf($staffed), $status($staffed)]);
check('Critère 3 bis : réaffecter le même game master ne change rien', 'PRETE', $service->assignGameMaster($responsable, $ready, '3')['apres']);

// --- Critère 4 : une session EN_COURS n'est pas modifiée automatiquement ------------------
$service->changeStatus($camille, $ready, 'EN_COURS');
check('Critère 4 : retrait du game master d\'une partie en cours refusé', ['game_master'], errorFields(fn () => $service->assignGameMaster($responsable, $ready, '')));
check('Critère 4 : la session reste EN_COURS avec son game master', ['EN_COURS', 3], [$status($ready), $gmOf($ready)]);

finish('de l\'évolution EV-TEV-01');

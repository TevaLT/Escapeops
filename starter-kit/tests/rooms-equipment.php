<?php

declare(strict_types=1);

/**
 * Gestion des salles (US-21) et des équipements d'une salle (US-25, relation N–N).
 * Lancement : composer test
 */

use App\Repository\EquipmentRepository;
use App\Repository\RoomRepository;
use App\Service\EquipmentService;
use App\Service\RoomService;
use App\Support\FixedClock;

require __DIR__ . '/support/bootstrap.php';

['admin' => $admin, 'responsable' => $responsable, 'camille' => $camille] = testUsers();
$database = testDatabase();
$clock = new FixedClock(new DateTimeImmutable('2026-11-24 12:00:00'));
$rooms = new RoomService(new RoomRepository($database), $clock);
$equipment = new EquipmentService(new EquipmentRepository($database), new RoomRepository($database), $clock);

$valid = static fn (array $overrides = []): array => $overrides + [
    'nom' => 'La Tour de l\'Horloge', 'theme' => 'Steampunk', 'description' => '',
    'duree_minutes' => '60', 'capacite_min' => '2', 'capacite_max' => '5', 'temps_remise_etat' => '15',
];

// --- US-21 salles -------------------------------------------------------------------
check('US-21 game master : création de salle refusée', 'AccessDeniedException', outcome(fn () => $rooms->create($camille, $valid())));
$tower = $rooms->create($responsable, $valid());
check('US-21 responsable : salle créée', 'La Tour de l\'Horloge', $rooms->find($tower)['nom']);
check('US-21 description vide enregistrée comme NULL', null, $rooms->find($tower)['description']);
check('US-21 nom déjà pris refusé', ['nom'], errorFields(fn () => $rooms->create($admin, $valid())));
check('US-21 nom vide et thème vide refusés', ['nom', 'theme'], errorFields(fn () => $rooms->create($admin, $valid(['nom' => '', 'theme' => ' ']))));
check('US-21 durée nulle refusée', ['duree_minutes'], errorFields(fn () => $rooms->create($admin, $valid(['nom' => 'X', 'duree_minutes' => '0']))));
check('US-21 capacité max < min refusée', ['capacite_max'], errorFields(fn () => $rooms->create($admin, $valid(['nom' => 'X', 'capacite_min' => '5', 'capacite_max' => '3']))));
check('US-21 capacité min nulle refusée', ['capacite_min'], errorFields(fn () => $rooms->create($admin, $valid(['nom' => 'X', 'capacite_min' => '0']))));
check('US-21 remise en état négative refusée', ['temps_remise_etat'], errorFields(fn () => $rooms->create($admin, $valid(['nom' => 'X', 'temps_remise_etat' => '-5']))));

check('US-21 modification acceptée', 'OK', outcome(fn () => $rooms->update($admin, $tower, $valid(['duree_minutes' => '90']))));
check('US-21 durée modifiée', 90, (int) $rooms->find($tower)['duree_minutes']);
check('US-21 garder son propre nom n\'est pas un doublon', 'OK', outcome(fn () => $rooms->update($admin, $tower, $valid())));
check('US-21 prendre le nom d\'une autre salle refusé', ['nom'], errorFields(fn () => $rooms->update($admin, $tower, $valid(['nom' => 'Le Cabinet des Ombres']))));
check('US-21 game master : modification refusée', 'AccessDeniedException', outcome(fn () => $rooms->update($camille, $tower, $valid())));
check('US-21 salle inconnue : introuvable', 'NotFoundException', outcome(fn () => $rooms->update($admin, 999, $valid())));

addSession($database, 1, '2026-11-24 18:00:00', '2026-11-24 19:00:00');
check('US-21 salle avec des sessions : suppression refusée', ['salle'], errorFields(fn () => $rooms->delete($admin, 1)));
check('US-21 salle sans session ni incident : supprimée', 'OK', outcome(fn () => $rooms->delete($responsable, $tower)));
check('US-21 salle supprimée introuvable', null, $rooms->find($tower));

// --- US-25 équipements (N–N) --------------------------------------------------------
check('US-25 équipements de la salle 1 (critique en premier)', ['Mécanisme de la porte secrète', 'Coffre à code'], array_column($equipment->forRoom(1), 'nom'));
check('US-25 game master : affectation refusée', 'AccessDeniedException', outcome(fn () => $equipment->assign($camille, 2, '1')));
check('US-25 un même équipement dans une deuxième salle (N–N)', 'OK', outcome(fn () => $equipment->assign($responsable, 2, '1')));
check('US-25 indicateur critique conservé', 1, (int) $equipment->forRoom(2)[0]['est_critique']);
check('US-25 date d\'affectation = aujourd\'hui', '2026-11-24', $equipment->forRoom(2)[0]['date_affectation']);
check('US-25 affecter deux fois le même équipement refusé', ['equipement'], errorFields(fn () => $equipment->assign($responsable, 2, '1')));
check('US-25 équipement inconnu refusé', ['equipement'], errorFields(fn () => $equipment->assign($responsable, 2, '99')));
check('US-25 salle inconnue : introuvable', 'NotFoundException', outcome(fn () => $equipment->assign($responsable, 99, '1')));
check('US-25 liste « à affecter » sans ce qui est déjà installé', ['Coffre à code'], array_column($equipment->assignableTo(2), 'nom'));
check('US-25 retirer un équipement', 'OK', outcome(fn () => $equipment->unassign($responsable, 2, 1)));
check('US-25 retiré de la salle 2, toujours dans la salle 1', [0, 2], [count($equipment->forRoom(2)), count($equipment->forRoom(1))]);
check('US-25 retirer un équipement absent : introuvable', 'NotFoundException', outcome(fn () => $equipment->unassign($responsable, 2, 1)));

$audio = $equipment->create($admin, ['nom' => 'Système audio', 'est_critique' => '']);
check('US-25 nouvel équipement non critique', 0, (int) $database->get('equipement', 'est_critique', ['id_equipement' => $audio]));
$equipment->create($admin, ['nom' => 'Panneau électrique', 'est_critique' => '1']);
check('US-25 nouvel équipement critique', 1, (int) $database->get('equipement', 'est_critique', ['nom' => 'Panneau électrique']));
check('US-25 nom d\'équipement vide refusé', ['nom'], errorFields(fn () => $equipment->create($admin, ['nom' => ''])));

finish('des salles et équipements');

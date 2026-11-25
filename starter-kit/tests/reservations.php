<?php

declare(strict_types=1);

/**
 * Réservation d'un groupe (US-23) et règle E1 « une option pendant quinze minutes » (US-30 à US-34).
 * L'horloge est simulée (FixedClock) : les 15 minutes passent sans attendre.
 * Lancement : composer test
 */

use App\Domain\ReservationOption;
use App\Domain\ReservationStateException;
use App\Domain\SlotAvailability;
use App\Domain\SlotUnavailableException;
use App\Repository\ReservationRepository;
use App\Repository\SessionRepository;
use App\Service\ReservationService;
use App\Support\FixedClock;

require __DIR__ . '/support/bootstrap.php';

$database = testDatabase();
$clock = new FixedClock(new DateTimeImmutable('2026-11-24 12:00:00'));
$service = new ReservationService(new SessionRepository($database), new ReservationRepository($database), $clock);
$group = static fn (array $overrides = []): array => $overrides + ['nom_groupe' => 'Les Curieux', 'contact' => 'curieux@example.test', 'nb_joueurs' => '4'];
$count = static fn (int $session, string $status): int => (int) $database->count('reservation', ['id_session' => $session, 'statut' => $status]);
$statusOf = static fn (string $token): string => (string) $database->get('reservation', 'statut', ['jeton' => $token]);

/** Disponibilité renvoyée par un refus de créneau, ou 'OK'. */
function refusal(callable $action): string
{
    try {
        $action();
        return 'OK';
    } catch (SlotUnavailableException $exception) {
        return $exception->availability;
    }
}

/** État renvoyé par un refus de confirmation / d'annulation, ou 'OK'. */
function stateRefusal(callable $action): string
{
    try {
        $action();
        return 'OK';
    } catch (ReservationStateException $exception) {
        return $exception->state;
    }
}

/** Message d'un refus de confirmation / d'annulation, ou ''. */
function refusalMessage(callable $action): string
{
    try {
        $action();
        return '';
    } catch (ReservationStateException $exception) {
        return $exception->getMessage();
    }
}

$free = addSession($database, 1, '2026-11-24 18:00:00', '2026-11-24 19:00:00');
$past = addSession($database, 1, '2026-11-24 10:00:00', '2026-11-24 11:00:00');
$cancelled = addSession($database, 2, '2026-11-24 18:00:00', '2026-11-24 19:00:00', 'ANNULEE');
$ready = addSession($database, 2, '2026-11-25 10:00:00', '2026-11-25 11:00:00', 'PRETE');

check('US-23 créneau libre affiché comme réservable', SlotAvailability::LIBRE, $service->slot($free)['disponibilite']);

// --- US-23 : champs invalides, rien n'est enregistré ----------------------------------
check('US-23 nom de groupe vide refusé', ['nom_groupe'], errorFields(fn () => $service->placeOption($free, $group(['nom_groupe' => '   ']))));
check('US-23 nom de groupe trop long refusé', ['nom_groupe'], errorFields(fn () => $service->placeOption($free, $group(['nom_groupe' => str_repeat('a', 101)]))));
check('US-23 nom avec accents (UTF-8) accepté tel quel', 'Les Enquêteurs', App\Domain\Field::text(' Les Enquêteurs '));
check('US-23 texte qui n\'est pas de l\'UTF-8 valide refusé (pas de troncature silencieuse)', ['nom_groupe'], errorFields(fn () => $service->placeOption($free, $group(['nom_groupe' => "Les Enqu\xEAteurs"]))));
check('US-23 contact ni e-mail ni téléphone refusé', ['contact'], errorFields(fn () => $service->placeOption($free, $group(['contact' => 'pas un contact']))));
check('US-23 trop de joueurs pour la salle (7 > 6) refusé', ['nb_joueurs'], errorFields(fn () => $service->placeOption($free, $group(['nb_joueurs' => '7']))));
check('US-23 pas assez de joueurs (1 < 2) refusé', ['nb_joueurs'], errorFields(fn () => $service->placeOption($free, $group(['nb_joueurs' => '1']))));
check('US-23 nombre de joueurs non numérique refusé', ['nb_joueurs'], errorFields(fn () => $service->placeOption($free, $group(['nb_joueurs' => '4; DROP TABLE reservation']))));
check('US-23 aucune réservation après les refus', 0, (int) $database->count('reservation', ['id_session' => $free]));
check('US-23 contact au format téléphone accepté', 'OK', outcome(fn () => $service->placeOption($ready, $group(['contact' => '06 00 00 00 01']))));

// --- US-23 : sessions passées, annulées, inconnues -------------------------------------
check('US-23 session passée : refus', SlotAvailability::FERME, refusal(fn () => $service->placeOption($past, $group())));
check('US-23 session annulée (supprimée) : refus', SlotAvailability::FERME, refusal(fn () => $service->placeOption($cancelled, $group())));
check('US-23 session inconnue : introuvable', 'NotFoundException', outcome(fn () => $service->placeOption(999, $group())));

// --- E1 / US-30 : pose de l'option ----------------------------------------------------
$optionA = $service->placeOption($free, $group(['nom_groupe' => 'Groupe A']));
check('US-30 option enregistrée EN_ATTENTE', 'EN_ATTENTE', $statusOf($optionA['jeton']));
check('US-30 expires_at = maintenant + 15 min', '2026-11-24 12:15:00', (string) $database->get('reservation', 'date_expiration', ['jeton' => $optionA['jeton']]));
check('US-30 jeton secret de 32 caractères hexadécimaux', 1, preg_match('/^[0-9a-f]{32}$/', $optionA['jeton']));
check('US-30 l\'option n\'est pas une réservation confirmée', 0, $count($free, 'CONFIRMEE'));
check('US-30 page de suivi : option en cours, confirmable', [ReservationOption::EN_ATTENTE, true], [
    $service->byToken($optionA['jeton'])['etat'], $service->byToken($optionA['jeton'])['peut_confirmer'],
]);
check('US-30 lien inconnu : introuvable', null, $service->byToken(str_repeat('0', 32)));

// --- E1 / US-31 : une option active bloque les autres groupes -------------------------
check('US-31 créneau affiché « en option »', SlotAvailability::EN_OPTION, $service->slot($free)['disponibilite']);
check('US-31 groupe B refusé pendant l\'option de A', SlotAvailability::EN_OPTION, refusal(fn () => $service->placeOption($free, $group(['nom_groupe' => 'Groupe B']))));
check('US-31 message clair avec l\'heure de fin d\'option', true, str_starts_with((string) $service->slot($free)['motif'], 'Ce créneau est en option jusqu\'à 12 h 15'));
$clock->advance('+14 minutes 59 seconds');
check('US-31 toujours bloqué à 12 h 14 min 59 s', SlotAvailability::EN_OPTION, refusal(fn () => $service->placeOption($free, $group(['nom_groupe' => 'Groupe B']))));
check('US-31 une seule option enregistrée sur le créneau', 1, $count($free, 'EN_ATTENTE'));

// --- E1 : succès, A confirme dans le délai --------------------------------------------
check('E1 confirmation dans le délai acceptée', 'OK', outcome(fn () => $service->confirm($optionA['jeton'])));
check('E1 réservation passée CONFIRMEE', 'CONFIRMEE', $statusOf($optionA['jeton']));
check('E1 deuxième confirmation refusée (déjà confirmée)', ReservationOption::CONFIRMEE, stateRefusal(fn () => $service->confirm($optionA['jeton'])));
check('US-23 créneau confirmé : groupe B refusé (complet)', SlotAvailability::COMPLET, refusal(fn () => $service->placeOption($free, $group(['nom_groupe' => 'Groupe B']))));
check('US-23 une seule réservation confirmée par session', 1, $count($free, 'CONFIRMEE'));

// --- E1 / US-32 et US-33 : expiration, libération, confirmation hors délai -------------
$clock->set(new DateTimeImmutable('2026-11-24 13:00:00'));
$slot2 = addSession($database, 2, '2026-11-24 20:00:00', '2026-11-24 21:00:00');
$late = $service->placeOption($slot2, $group(['nom_groupe' => 'Groupe A']));
$clock->advance('+15 minutes');
check('US-32 à 15 min pile, l\'option a expiré (borne exclue)', SlotAvailability::LIBRE, $service->slot($slot2)['disponibilite']);
check('US-32 page de suivi : option expirée, plus confirmable', [ReservationOption::EXPIREE, false], [
    $service->byToken($late['jeton'])['etat'], $service->byToken($late['jeton'])['peut_confirmer'],
]);
check('US-33 confirmation après expiration refusée', ReservationOption::EXPIREE, stateRefusal(fn () => $service->confirm($late['jeton'])));
check('US-33 l\'option ne passe pas en CONFIRMEE', 'EN_ATTENTE', $statusOf($late['jeton']));
check('US-33 message explicite avec l\'heure d\'expiration', true, str_starts_with(refusalMessage(fn () => $service->confirm($late['jeton'])), 'Votre option a expiré à 13 h 15'));
$optionB = $service->placeOption($slot2, $group(['nom_groupe' => 'Groupe B']));
check('US-32 le groupe B peut poser une option après l\'expiration', 'EN_ATTENTE', $statusOf($optionB['jeton']));
check('US-33 A toujours refusé quand B a repris le créneau', ReservationOption::EXPIREE, stateRefusal(fn () => $service->confirm($late['jeton'])));
check('E1 B confirme dans son propre délai', 'OK', outcome(fn () => $service->confirm($optionB['jeton'])));

// --- Annulation par le groupe ---------------------------------------------------------
$clock->set(new DateTimeImmutable('2026-11-24 13:00:00'));
$slot3 = addSession($database, 1, '2026-11-25 18:00:00', '2026-11-25 19:00:00');
$released = $service->placeOption($slot3, $group());
check('E1 le groupe libère son option', 'OK', outcome(fn () => $service->cancel($released['jeton'])));
check('E1 option libérée : créneau aussitôt réservable', SlotAvailability::LIBRE, $service->slot($slot3)['disponibilite']);
check('E1 option annulée : plus confirmable', ReservationOption::ANNULEE, stateRefusal(fn () => $service->confirm($released['jeton'])));
check('E1 annulation en double refusée', ReservationOption::ANNULEE, stateRefusal(fn () => $service->cancel($released['jeton'])));

// --- Session annulée par le responsable pendant l'option ------------------------------
$slot4 = addSession($database, 2, '2026-11-25 18:00:00', '2026-11-25 19:00:00');
$orphan = $service->placeOption($slot4, $group());
$database->update('session', ['statut' => 'ANNULEE'], ['id_session' => $slot4]);
check('E1 option sur une session annulée entre-temps : confirmation refusée', SlotAvailability::FERME, stateRefusal(fn () => $service->confirm($orphan['jeton'])));

// --- Partie commencée -----------------------------------------------------------------
$clock->set(new DateTimeImmutable('2026-11-24 18:30:00'));
check('E1 réservation d\'une partie commencée : annulation en ligne refusée', 'COMMENCEE', stateRefusal(fn () => $service->cancel($optionA['jeton'])));

// --- Règle pure (ReservationOption) ---------------------------------------------------
$t = new DateTimeImmutable('2026-11-24 18:00:00');
check('US-34 option active une seconde avant l\'échéance', ReservationOption::EN_ATTENTE, ReservationOption::state(['statut' => 'EN_ATTENTE', 'date_expiration' => '2026-11-24 18:00:01'], $t));
check('US-34 option expirée à l\'échéance', ReservationOption::EXPIREE, ReservationOption::state(['statut' => 'EN_ATTENTE', 'date_expiration' => '2026-11-24 18:00:00'], $t));
check('US-34 statut confirmé inchangé', ReservationOption::CONFIRMEE, ReservationOption::state(['statut' => 'CONFIRMEE', 'date_expiration' => '2026-11-24 17:00:00'], $t));

finish('de réservation (US-23 et E1)');

<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Authorization;
use App\Domain\CurrentUser;
use App\Domain\Field;
use App\Domain\NotFoundException;
use App\Domain\OpeningChecklist;
use App\Domain\ReservationOption;
use App\Domain\Role;
use App\Domain\SessionSchedule;
use App\Domain\SessionStatus;
use App\Domain\SlotAvailability;
use App\Domain\ValidationException;
use App\Repository\ChecklistRepository;
use App\Repository\ReservationRepository;
use App\Repository\RoomRepository;
use App\Repository\SessionRepository;
use App\Repository\UserRepository;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * Planning des sessions (US-22), filtres (US-29), cycle d'états (US-27), et deux règles contractuelles :
 *  - E5 : un game master ne peut pas animer deux sessions qui se chevauchent ; pas de démarrage sans lui ;
 *  - E10 : une session ne devient PRETE qu'avec une checklist d'ouverture 3/3.
 * Chaque écriture commence par un contrôle de droit (US-13) : même appelé sans passer
 * par une route protégée, le service refuse un utilisateur qui n'a pas le droit.
 */
final class SessionService
{
    /** Seule une session pas encore préparée peut changer de salle, d'horaire ou de game master. */
    public const EDITABLE_STATUS = SessionStatus::PREVUE;

    /** Le game master se change (ou se retire) tant que la partie n'a pas commencé. */
    public const GM_ASSIGNABLE_STATUS = [SessionStatus::PREVUE, SessionStatus::PRETE];

    public function __construct(
        private SessionRepository $sessions,
        private RoomRepository $rooms,
        private ReservationRepository $reservations,
        private UserRepository $users,
        private ChecklistRepository $checklists,
        private Clock $clock
    ) {
    }

    /**
     * Planning filtré (US-29). Sans date : aujourd'hui et à venir ; avec une date : ce jour-là.
     * Un filtre invalide (salle inconnue, état inexistant, date mal formée) est ignoré.
     *
     * @param array<string, mixed> $query paramètres d'URL salle, date, etat
     * @return array{sessions: array, filtres: array{salle: string, date: string, etat: string}}
     */
    public function planning(array $query = []): array
    {
        $roomId = Field::positiveInt($query['salle'] ?? null);
        $day = Field::date($query['date'] ?? null);
        $status = in_array($query['etat'] ?? null, SessionStatus::ALL, true) ? $query['etat'] : null;

        $criteria = ['room' => $roomId, 'status' => $status];
        if ($day !== null) {
            $criteria['day'] = $day;
        } else {
            $criteria['from'] = $this->clock->now()->setTime(0, 0);
        }

        return [
            'sessions' => $this->withLiveOption($this->sessions->search($criteria)),
            'filtres' => [
                'salle' => $roomId !== null ? (string) $roomId : '',
                'date' => $day?->format('Y-m-d') ?? '',
                'etat' => $status ?? '',
            ],
        ];
    }

    /** Prochaines sessions non terminées (tableau de bord). */
    public function upcoming(int $limit): array
    {
        return $this->withLiveOption($this->sessions->search(['from' => $this->clock->now(), 'activeOnly' => true, 'limit' => $limit]));
    }

    /**
     * Créneaux publics (US-51, GET /api/sessions) : aujourd'hui et à venir, ou un jour donné,
     * éventuellement d'une seule salle, avec la disponibilité calculée à l'instant (E1).
     * Ne renvoie que des informations publiques : ni game master, ni nom de groupe.
     *
     * @return list<array<string, mixed>>
     * @throws NotFoundException si la salle demandée n'existe pas
     */
    public function publicSlots(?int $roomId = null, ?DateTimeImmutable $day = null): array
    {
        if ($roomId !== null && $this->rooms->find($roomId) === null) {
            throw new NotFoundException('Salle introuvable.');
        }

        $now = $this->clock->now();
        $criteria = ['room' => $roomId];
        if ($day !== null) {
            $criteria['day'] = $day;
        } else {
            $criteria['from'] = $now->setTime(0, 0);
        }

        $slots = [];
        foreach ($this->sessions->search($criteria) as $session) {
            // Même calcul que la vitrine : une réservation confirmée rend le créneau COMPLET,
            // une option encore active le met EN_OPTION, une option expirée ne compte plus.
            $availability = SlotAvailability::of(
                $session + ['nb_confirmees' => $session['groupe'] !== null ? 1 : 0],
                $now
            );
            $slots[] = [
                'id' => (int) $session['id_session'],
                'salleId' => (int) $session['id_salle'],
                'salle' => $session['salle'],
                'debut' => $session['date_debut'],
                'fin' => $session['date_fin'],
                'statut' => $session['statut'],
                'disponibilite' => $availability,
                'optionJusqua' => $availability === SlotAvailability::EN_OPTION ? $session['option_expire_le'] : null,
            ];
        }

        return $slots;
    }

    /**
     * E1 : ajoute à chaque session « option_active » (une option court encore à cet instant).
     * Une option expirée reste EN_ATTENTE en base mais ne compte plus : le calcul se fait ici, à chaque requête.
     */
    private function withLiveOption(array $sessions): array
    {
        $now = $this->clock->now();

        return array_map(
            static fn (array $s): array => $s + ['option_active' => ReservationOption::isActive($s['option_expire_le'] ?? null, $now)],
            $sessions
        );
    }

    /** Sessions d'aujourd'hui et à venir d'un game master. */
    public function forGameMaster(int $userId): array
    {
        return $this->sessions->search(['gameMaster' => $userId, 'from' => $this->clock->now()->setTime(0, 0)]);
    }

    public function find(int $id): ?array
    {
        return $this->sessions->find($id);
    }

    /** Fiche session : détail, réservations (état de l'option calculé) et historique des checklists. */
    public function detail(int $id): ?array
    {
        $session = $this->sessions->detail($id);
        if ($session === null) {
            return null;
        }

        $now = $this->clock->now();
        $reservations = array_map(
            static fn (array $r): array => $r + ['etat' => ReservationOption::state($r, $now)],
            $this->reservations->forSession($id)
        );

        return $session + [
            'reservations' => $reservations,
            'checklists' => $this->checklists->forSession($id),
        ];
    }

    public function rooms(): array
    {
        return $this->rooms->all();
    }

    public function gameMasters(): array
    {
        return $this->users->byRole(Role::GAME_MASTER->value);
    }

    /**
     * Changements d'état que $user peut déclencher par un bouton sur la fiche.
     * PRETE n'en fait pas partie : on y arrive en envoyant la checklist d'ouverture (E10).
     *
     * @return list<string>
     */
    public function allowedTransitions(?CurrentUser $user, array $session): array
    {
        return array_values(array_filter(
            SessionStatus::nextSteps((string) $session['statut']),
            static fn (string $target): bool => $target !== SessionStatus::PRETE
                && Authorization::canChangeSessionStatus($user, $session, $target)
        ));
    }

    /** Le formulaire de checklist est-il proposé à $user sur cette session ? */
    public function canFillChecklist(?CurrentUser $user, array $session): bool
    {
        return $session['statut'] === SessionStatus::PREVUE && Authorization::canFillChecklist($user, $session);
    }

    /**
     * @param array<string, mixed> $input champs salle, debut et game_master (facultatif) du formulaire
     * @throws \App\Domain\AccessDeniedException|ValidationException
     */
    public function create(?CurrentUser $user, array $input): int
    {
        Authorization::denyUnlessCanManagePlanning($user);

        [$room, $start, $end, $gameMaster] = $this->validate($input, null);

        return $this->sessions->insert((int) $room['id_salle'], $start, $end, $gameMaster);
    }

    /**
     * @param array<string, mixed> $input
     * @throws \App\Domain\AccessDeniedException|NotFoundException|ValidationException
     */
    public function update(?CurrentUser $user, int $id, array $input): void
    {
        Authorization::denyUnlessCanManagePlanning($user);

        $session = $this->sessions->find($id);
        if ($session === null) {
            throw new NotFoundException('Session introuvable.');
        }

        if ($session['statut'] !== self::EDITABLE_STATUS) {
            throw new ValidationException([
                'statut' => 'Seule une session « prévue » peut être modifiée (état actuel : '
                    . libelle_statut((string) $session['statut']) . ').',
            ]);
        }

        [$room, $start, $end, $gameMaster] = $this->validate($input, $id);

        $this->sessions->update($id, (int) $room['id_salle'], $start, $end, $gameMaster);
    }

    /**
     * E5 (US-35, US-36) : affecte, remplace ou retire ('') le game master d'une session depuis sa fiche.
     * Possible tant que la partie n'a pas commencé (PREVUE ou PRETE : un GM absent se remplace).
     *
     * @return array{avant: string, apres: string} l'état avant et après (PREVUE si la session prête a perdu son game master, EV-TEV-01)
     * @throws \App\Domain\AccessDeniedException|NotFoundException|ValidationException
     */
    public function assignGameMaster(?CurrentUser $user, int $id, mixed $input): array
    {
        Authorization::denyUnlessCanManagePlanning($user);

        $session = $this->sessions->find($id);
        if ($session === null) {
            throw new NotFoundException('Session introuvable.');
        }

        if (!in_array($session['statut'], self::GM_ASSIGNABLE_STATUS, true)) {
            throw new ValidationException(['game_master' => sprintf(
                'Le game master ne change plus sur une session « %s ».',
                libelle_statut((string) $session['statut'])
            )]);
        }

        [$gameMasterId, $gameMaster, $errors] = $this->resolveGameMaster(is_string($input) ? $input : 'invalide');
        $errors += $this->gameMasterConflict(
            $gameMaster,
            new DateTimeImmutable((string) $session['date_debut']),
            new DateTimeImmutable((string) $session['date_fin']),
            $id
        );
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        // EV-TEV-01 : une session prête qui perd son unique game master avant le début n'est plus prête.
        // Elle repasse « à préparer » (PREVUE) ; ses réservations ne sont pas touchées.
        $from = (string) $session['statut'];
        $to = $from === SessionStatus::PRETE && $gameMasterId === null ? SessionStatus::PREVUE : $from;

        if (!$this->sessions->assignGameMaster($id, $gameMasterId, $from, $to)) {
            // 0 ligne modifiée : soit rien ne changeait (même game master), soit la session a changé d'état
            // entre la lecture et l'écriture (partie démarrée) : dans ce cas, on ne touche à rien.
            $now = $this->sessions->find($id);
            if ($now === null || $now['statut'] !== $to) {
                throw new ValidationException(['game_master' => 'La session a changé d\'état entre-temps : rechargez la fiche.']);
            }
        }

        return ['avant' => $from, 'apres' => $to];
    }

    /**
     * EV-TEV-01 : la session a-t-elle un game master admissible ? (compte GAME_MASTER, sans conflit horaire E5)
     *
     * @return array<string, string> erreur sur le champ game_master, ou rien
     */
    private function staffingErrors(array $session): array
    {
        if ($session['id_game_master'] === null) {
            return ['game_master' => 'Salle non prête : aucun game master affecté. Une checklist ne remplace pas un animateur.'];
        }

        $gameMaster = $this->users->findWithRole((int) $session['id_game_master'], Role::GAME_MASTER->value);
        if ($gameMaster === null) {
            return ['game_master' => 'Salle non prête : le compte affecté n\'est plus un game master.'];
        }

        return $this->gameMasterConflict(
            $gameMaster,
            new DateTimeImmutable((string) $session['date_debut']),
            new DateTimeImmutable((string) $session['date_fin']),
            (int) $session['id_session']
        );
    }

    /**
     * US-27 : fait passer la session à l'état $target si le cycle, les droits et les règles le permettent.
     *
     * @throws \App\Domain\AccessDeniedException|NotFoundException|ValidationException
     */
    public function changeStatus(?CurrentUser $user, int $id, mixed $target): void
    {
        $session = $this->sessions->find($id);
        if ($session === null) {
            throw new NotFoundException('Session introuvable.');
        }

        $target = is_string($target) ? $target : '';
        Authorization::denyUnlessCanChangeSessionStatus($user, $session, $target);

        // E5 (US-37) : pas de partie sans game master. Vérifié avant le cycle d'états pour qu'une requête
        // forgée sur une session sans game master reçoive ce motif-là.
        if ($target === SessionStatus::EN_COURS && $session['id_game_master'] === null) {
            throw new ValidationException([
                'game_master' => 'Impossible de démarrer la partie : aucun game master affecté à cette session.',
            ]);
        }

        $from = (string) $session['statut'];
        if (!SessionStatus::canTransition($from, $target)) {
            throw new ValidationException(['statut' => sprintf(
                'Passage interdit : une session « %s » ne peut pas devenir « %s ».',
                libelle_statut($from),
                libelle_statut($target)
            )]);
        }

        // E10 (US-40) + évolution EV-TEV-01 : « Prête » = checklist 3/3 ET game master admissible affecté.
        // Même un POST forgé « etat=PRETE » est refusé côté serveur.
        if ($target === SessionStatus::PRETE) {
            $latest = $this->checklists->latestForSession($id);
            $errors = OpeningChecklist::isComplete($latest) ? [] : ['checklist' => self::notReadyMessage($latest)];
            $errors += $this->staffingErrors($session);
            if ($errors !== []) {
                throw new ValidationException($errors);
            }
        }

        $this->sessions->updateStatus($id, $target);

        if ($target === SessionStatus::ANNULEE) {
            // Le groupe confirmé n'est pas supprimé : il est signalé « à recontacter ».
            $this->reservations->flagConfirmedForContact($id);
        }
    }

    /**
     * E10 (US-39, US-40) : le game master affecté enregistre sa checklist d'ouverture.
     * Elle est toujours gardée (nom du GM, date, 3 valeurs) ; à 3/3, la session passe à PRETE,
     * sinon elle reste PREVUE avec le message « salle non prête ».
     *
     * @param array<string, mixed> $input cases salle_rangee, materiel_teste, securite_verifiee
     * @throws \App\Domain\AccessDeniedException|NotFoundException|ValidationException
     */
    public function submitChecklist(?CurrentUser $user, int $id, array $input): void
    {
        $session = $this->sessions->find($id);
        if ($session === null) {
            throw new NotFoundException('Session introuvable.');
        }

        Authorization::denyUnlessCanFillChecklist($user, $session);

        if ($session['statut'] !== SessionStatus::PREVUE) {
            throw new ValidationException(['statut' => sprintf(
                'La checklist se remplit sur une session « prévue » (état actuel : %s).',
                libelle_statut((string) $session['statut'])
            )]);
        }

        $values = OpeningChecklist::fromInput($input);
        $this->checklists->insert(
            $id,
            $user->id,
            $values['salle_rangee'],
            $values['materiel_teste'],
            $values['securite_verifiee'],
            $this->clock->now()
        );

        if (!OpeningChecklist::isComplete($values)) {
            throw new ValidationException(['checklist' => self::notReadyMessage($values)]);
        }

        $this->changeStatus($user, $id, SessionStatus::PRETE);
    }

    private static function notReadyMessage(?array $checklist): string
    {
        if ($checklist === null) {
            return 'Salle non prête : aucune checklist d\'ouverture n\'a été enregistrée pour cette session.';
        }

        return sprintf(
            'Salle non prête : checklist %d/%d. Point(s) à valider : %s.',
            OpeningChecklist::score($checklist),
            count(OpeningChecklist::POINTS),
            implode(', ', array_map('mb_strtolower', OpeningChecklist::missing($checklist)))
        );
    }

    /**
     * @return array{0: array<string, mixed>, 1: DateTimeImmutable, 2: DateTimeImmutable, 3: ?int}
     * @throws ValidationException
     */
    private function validate(array $input, ?int $excludeId): array
    {
        $roomId = Field::positiveInt($input['salle'] ?? null);
        $room = $roomId === null ? null : $this->rooms->find($roomId);

        // Le game master est facultatif (E5, US-35) : une session peut rester PREVUE sans lui.
        [$gameMasterId, $gameMaster, $errors] = $this->resolveGameMaster($input['game_master'] ?? '');

        try {
            [$start, $end] = SessionSchedule::plan($room, $input['debut'] ?? null, $this->clock->now());
        } catch (ValidationException $exception) {
            throw new ValidationException($exception->errors() + $errors);
        }

        // Une salle ne peut accueillir qu'une partie à la fois, remise en état comprise.
        $cleanup = (int) $room['temps_remise_etat'];
        [$from, $to] = SessionSchedule::blockedWindow($start, $end, $cleanup);
        $conflict = $this->sessions->findRoomConflict((int) $room['id_salle'], $from, $to, $excludeId);
        if ($conflict !== null) {
            $errors['debut'] = sprintf(
                '« %s » est déjà occupée le %s jusqu\'à %s, et il faut %d min de remise en état entre deux parties.',
                $room['nom'],
                date_fr((string) $conflict['date_debut']),
                heure_fr((string) $conflict['date_fin']),
                $cleanup
            );
        }

        $errors += $this->gameMasterConflict($gameMaster, $start, $end, $excludeId);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [$room, $start, $end, $gameMasterId];
    }

    /**
     * Champ « game master » du formulaire : '' = aucun ; sinon l'id d'un compte GAME_MASTER.
     *
     * @return array{0: ?int, 1: ?array, 2: array<string, string>} id, ligne utilisateur, erreurs
     */
    private function resolveGameMaster(mixed $input): array
    {
        if ($input === '') {
            return [null, null, []];
        }

        $id = Field::positiveInt($input);
        $gameMaster = $id === null ? null : $this->users->findWithRole($id, Role::GAME_MASTER->value);

        return $gameMaster === null
            ? [null, null, ['game_master' => 'Choisissez un game master existant, ou aucun.']]
            : [$id, $gameMaster, []];
    }

    /**
     * E5 (US-36) : un game master ne peut pas se dédoubler.
     *
     * @return array<string, string> erreur sur le champ game_master, ou rien
     */
    private function gameMasterConflict(?array $gameMaster, DateTimeImmutable $start, DateTimeImmutable $end, ?int $excludeId): array
    {
        if ($gameMaster === null) {
            return [];
        }

        $busy = $this->sessions->findGameMasterConflict((int) $gameMaster['id_utilisateur'], $start, $end, $excludeId);
        if ($busy === null) {
            return [];
        }

        return ['game_master' => sprintf(
            '%s %s anime déjà « %s » le %s jusqu\'à %s : les deux créneaux se chevauchent.',
            $gameMaster['prenom'],
            $gameMaster['nom'],
            $busy['salle'],
            date_fr((string) $busy['date_debut']),
            heure_fr((string) $busy['date_fin'])
        )];
    }
}

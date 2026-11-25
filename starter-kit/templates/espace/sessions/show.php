<?php
/** @var array<string, mixed> $session */
/** @var list<string> $transitions */
/** @var bool $checklistOuverte le formulaire de checklist est proposé (game master affecté, session prévue) */
/** @var array<string, bool> $checklistSaisie cases déjà cochées (réaffichage après un refus) */
/** @var bool $affectationOuverte formulaire d'affectation du game master (responsable, session prévue ou prête) */
/** @var list<array<string, mixed>> $gameMasters */
/** @var array<string, string> $erreurs */
/** @var bool $voitContacts */
/** @var \App\Security\Csrf $csrf */
use App\Domain\OpeningChecklist;

$titre = 'Session du ' . date_fr($session['date_debut']);
$libellesReservation = ['EN_ATTENTE' => 'Option', 'EXPIREE' => 'Option expirée', 'CONFIRMEE' => 'Confirmée', 'ANNULEE' => 'Annulée'];
require __DIR__ . '/../../partials/header.php';
?>
<p><a href="/espace">← Mon espace</a></p>

<section class="hero">
    <p class="eyebrow"><?= e($session['salle']) ?></p>
    <h1><?= e(date_fr($session['date_debut'])) ?> → <?= e(heure_fr($session['date_fin'])) ?></h1>
    <ul class="facts">
        <li>État : <span class="badge badge-statut-<?= e(strtolower($session['statut'])) ?>"><?= e(libelle_statut($session['statut'])) ?></span></li>
        <li>Game master : <?= $session['gm_nom'] !== null ? e($session['gm_prenom'] . ' ' . $session['gm_nom']) : 'non affecté' ?></li>
    </ul>
</section>

<?= bandeau_erreurs($erreurs, [], 'Action refusée.') ?>

<?php if ($session['id_game_master'] === null && in_array($session['statut'], ['PREVUE', 'PRETE'], true)): ?>
    <p class="alert alert-error" role="status">Personnel manquant : aucun game master n'est affecté. La session ne peut être ni déclarée prête, ni démarrée.</p>
<?php endif; ?>

<?php if ($affectationOuverte): ?>
    <h2>Game master</h2>
    <form class="form" method="post" action="/gestion/sessions/<?= (int) $session['id_session'] ?>/game-master">
        <?= $csrf->field() ?>
        <label for="game_master">Game master affecté</label>
        <select id="game_master" name="game_master">
            <option value="">— Aucun —</option>
            <?php foreach ($gameMasters as $gm): ?>
                <option value="<?= (int) $gm['id_utilisateur'] ?>" <?= (int) $gm['id_utilisateur'] === (int) $session['id_game_master'] ? 'selected' : '' ?>>
                    <?= e($gm['prenom'] . ' ' . $gm['nom']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="button" type="submit">Enregistrer l'affectation</button>
    </form>
<?php endif; ?>

<?php if ($checklistOuverte): ?>
    <h2>Checklist d'ouverture</h2>
    <p>Les trois points doivent être validés pour que la session passe à « Prête ».</p>
    <form class="form" method="post" action="/espace/sessions/<?= (int) $session['id_session'] ?>/checklist">
        <?= $csrf->field() ?>
        <?php foreach (OpeningChecklist::POINTS as $point => $libelle): ?>
            <label class="check">
                <input type="checkbox" name="<?= e($point) ?>" value="1" <?= !empty($checklistSaisie[$point]) ? 'checked' : '' ?>>
                <?= e($libelle) ?>
            </label>
        <?php endforeach; ?>
        <button class="button" type="submit">Valider la checklist</button>
    </form>
<?php endif; ?>

<h2>Actions</h2>
<?php if ($transitions === []): ?>
    <p class="empty">Aucune action possible pour vous sur cette session.</p>
<?php else: ?>
    <div class="actions">
        <?php foreach ($transitions as $etat): ?>
            <form class="inline" method="post" action="/espace/sessions/<?= (int) $session['id_session'] ?>/etat">
                <?= $csrf->field() ?>
                <input type="hidden" name="etat" value="<?= e($etat) ?>">
                <button class="button <?= $etat === 'ANNULEE' ? 'button-danger' : '' ?>" type="submit"><?= e(\App\Domain\SessionStatus::actionLabel($etat)) ?></button>
            </form>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<p><a href="/espace/incidents/nouveau?salle=<?= (int) $session['id_salle'] ?>&amp;session=<?= (int) $session['id_session'] ?>">Déclarer un incident sur cette session</a></p>

<h2>Réservations</h2>
<?php if ($session['reservations'] === []): ?>
    <p class="empty">Aucune réservation.</p>
<?php else: ?>
    <table>
        <thead><tr><th>Groupe</th><th>Joueurs</th><?php if ($voitContacts): ?><th>Contact</th><?php endif; ?><th>État</th></tr></thead>
        <tbody>
        <?php foreach ($session['reservations'] as $reservation): ?>
            <tr>
                <td><?= e($reservation['nom_groupe']) ?></td>
                <td><?= (int) $reservation['nb_joueurs'] ?></td>
                <?php if ($voitContacts): ?><td><?= e($reservation['contact']) ?></td><?php endif; ?>
                <td>
                    <?= e($libellesReservation[$reservation['etat']] ?? $reservation['etat']) ?>
                    <?php if ($reservation['etat'] === 'EN_ATTENTE'): ?>jusqu'à <?= e(heure_fr($reservation['date_expiration'])) ?><?php endif; ?>
                    <?php if ((int) $reservation['a_recontacter'] === 1): ?><span class="badge badge-statut-annulee">à recontacter</span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<h2>Historique des checklists</h2>
<?php if ($session['checklists'] === []): ?>
    <p class="empty">Aucune checklist enregistrée.</p>
<?php else: ?>
    <table>
        <thead><tr><th>Date</th><th>Game master</th><?php foreach (OpeningChecklist::POINTS as $libelle): ?><th><?= e($libelle) ?></th><?php endforeach; ?><th>Résultat</th></tr></thead>
        <tbody>
        <?php foreach ($session['checklists'] as $checklist): ?>
            <tr>
                <td><?= e(date_fr($checklist['date_validation'])) ?></td>
                <td><?= e($checklist['gm_prenom'] . ' ' . $checklist['gm_nom']) ?></td>
                <?php foreach (array_keys(OpeningChecklist::POINTS) as $point): ?>
                    <td><?= (int) $checklist[$point] === 1 ? '✔' : '✘' ?></td>
                <?php endforeach; ?>
                <td>
                    <?php $score = OpeningChecklist::score($checklist); ?>
                    <span class="badge <?= $score === 3 ? 'badge-statut-prete' : 'badge-statut-annulee' ?>"><?= $score ?>/3<?= $score === 3 ? '' : ' · non prête' ?></span>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<?php require __DIR__ . '/../../partials/footer.php'; ?>

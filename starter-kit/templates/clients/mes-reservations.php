<?php
/** @var string $contact */
/** @var list<array<string, mixed>> $a_venir */
/** @var list<array<string, mixed>> $historique */
/** @var int $parties_jouees */
/** @var \App\Security\Csrf $csrf */
use App\Domain\ClientHistory;
use App\Domain\ReservationOption;

$titre = 'Mes réservations';
$classesResultat = [
    ClientHistory::JOUEE => 'badge-statut-prete',
    ClientHistory::EN_COURS => 'badge-statut-en_cours',
    ClientHistory::INTERROMPUE => 'badge-statut-interrompue',
    ClientHistory::SESSION_ANNULEE => 'badge-statut-annulee',
    ClientHistory::RESERVATION_ANNULEE => 'badge-statut-annulee',
    ClientHistory::OPTION_EXPIREE => 'badge-statut-annulee',
];
require __DIR__ . '/../partials/header.php';
?>
<section class="hero">
    <p class="eyebrow">Espace client</p>
    <h1>Mes réservations</h1>
    <p>Réservations faites avec <strong><?= e($contact) ?></strong>
        · <?= $parties_jouees ?> partie<?= $parties_jouees > 1 ? 's' : '' ?> jouée<?= $parties_jouees > 1 ? 's' : '' ?> aux Archives Obscures.</p>
    <form class="inline" method="post" action="/mes-reservations/deconnexion">
        <?= $csrf->field() ?>
        <button class="link" type="submit">Quitter mon espace</button>
    </form>
</section>

<h2>À venir</h2>
<?php if ($a_venir === []): ?>
    <p class="empty">Aucune partie prévue. <a href="/">Choisir un créneau</a></p>
<?php else: ?>
    <table>
        <thead><tr><th>Créneau</th><th>Salle</th><th>Groupe</th><th>Joueurs</th><th>État</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($a_venir as $reservation): ?>
            <?php $option = $reservation['etat'] === ReservationOption::EN_ATTENTE; ?>
            <tr>
                <td><?= e(date_fr($reservation['date_debut'])) ?></td>
                <td><?= e($reservation['salle']) ?></td>
                <td><?= e($reservation['nom_groupe']) ?></td>
                <td><?= (int) $reservation['nb_joueurs'] ?></td>
                <td>
                    <?php if ($option): ?>
                        <span class="badge badge-en_option">Option jusqu'à <?= e(heure_fr($reservation['date_expiration'])) ?></span>
                    <?php else: ?>
                        <span class="badge badge-statut-prete">Confirmée</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($reservation['jeton'] !== null): ?>
                        <a href="/reservations/<?= e($reservation['jeton']) ?>"><?= $option ? 'Confirmer ou libérer' : 'Détail' ?></a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<h2>Historique des parties</h2>
<?php if ($historique === []): ?>
    <p class="empty">Pas encore de partie passée.</p>
<?php else: ?>
    <table>
        <thead><tr><th>Date</th><th>Salle</th><th>Groupe</th><th>Joueurs</th><th>Résultat</th></tr></thead>
        <tbody>
        <?php foreach ($historique as $reservation): ?>
            <tr>
                <td><?= e(date_fr($reservation['date_debut'])) ?></td>
                <td><?= e($reservation['salle']) ?></td>
                <td><?= e($reservation['nom_groupe']) ?></td>
                <td><?= (int) $reservation['nb_joueurs'] ?></td>
                <td><span class="badge <?= e($classesResultat[$reservation['resultat']] ?? 'badge-statut-prevue') ?>"><?= e(ClientHistory::label($reservation['resultat'])) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<p><a href="/">Réserver un autre créneau</a></p>
<?php require __DIR__ . '/../partials/footer.php'; ?>

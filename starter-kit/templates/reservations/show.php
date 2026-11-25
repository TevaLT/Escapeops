<?php
/** @var array<string, mixed> $reservation */
/** @var string|null $erreur */
/** @var \App\Security\Csrf $csrf */
use App\Domain\ReservationOption;

$titre = 'Ma réservation · ' . $reservation['salle'];
$libelles = [
    ReservationOption::EN_ATTENTE => 'Option en cours',
    ReservationOption::EXPIREE => 'Option expirée',
    ReservationOption::CONFIRMEE => 'Confirmée',
    ReservationOption::ANNULEE => 'Annulée',
];
$classes = [
    ReservationOption::EN_ATTENTE => 'badge-en_option',
    ReservationOption::EXPIREE => 'badge-statut-annulee',
    ReservationOption::CONFIRMEE => 'badge-statut-prete',
    ReservationOption::ANNULEE => 'badge-statut-annulee',
];
$base = '/reservations/' . $reservation['jeton'];
require __DIR__ . '/../partials/header.php';
?>
<p><a href="/salles/<?= (int) $reservation['id_salle'] ?>">← <?= e($reservation['salle']) ?></a></p>

<section class="hero narrow">
    <p class="eyebrow">Ma réservation</p>
    <h1><?= e($reservation['salle']) ?></h1>
    <p><?= e(date_fr($reservation['date_debut'])) ?> → <?= e(heure_fr($reservation['date_fin'])) ?></p>
    <ul class="facts">
        <li>Groupe : <?= e($reservation['nom_groupe']) ?> (<?= (int) $reservation['nb_joueurs'] ?> joueurs)</li>
        <li>État : <span class="badge <?= e($classes[$reservation['etat']] ?? '') ?>"><?= e($libelles[$reservation['etat']] ?? $reservation['etat']) ?></span></li>
        <?php if ($reservation['code_suivi'] !== null): ?>
            <li>Code de suivi : <strong><?= e(\App\Domain\TrackingCode::format($reservation['code_suivi'])) ?></strong></li>
        <?php endif; ?>
    </ul>
    <?php if ($reservation['code_suivi'] !== null): ?>
        <p class="empty">Notez ce code : avec votre e-mail ou téléphone, il vous permet de retrouver toutes vos réservations sur <a href="/mes-reservations">Mes réservations</a>.</p>
    <?php endif; ?>

    <?php if ($erreur !== null): ?>
        <p class="alert alert-error" role="alert"><?= e($erreur) ?></p>
    <?php endif; ?>

    <?php if ($reservation['etat'] === ReservationOption::EN_ATTENTE): ?>
        <p>Le créneau vous est gardé jusqu'à <strong><?= e(heure_fr($reservation['date_expiration'])) ?></strong>. Passé cette heure, l'option expire et le créneau est rendu aux autres groupes.</p>
    <?php elseif ($reservation['etat'] === ReservationOption::EXPIREE): ?>
        <p>Votre option a expiré à <?= e(heure_fr($reservation['date_expiration'])) ?> sans être confirmée : le créneau n'est plus gardé pour vous.</p>
        <a class="button" href="/salles/<?= (int) $reservation['id_salle'] ?>">Voir les créneaux disponibles</a>
    <?php endif; ?>

    <div class="actions">
        <?php if ($reservation['peut_confirmer']): ?>
            <form class="inline" method="post" action="<?= e($base) ?>/confirmer">
                <?= $csrf->field() ?>
                <button class="button" type="submit">Confirmer la réservation</button>
            </form>
        <?php endif; ?>
        <?php if ($reservation['peut_annuler']): ?>
            <form class="inline" method="post" action="<?= e($base) ?>/annuler">
                <?= $csrf->field() ?>
                <button class="button button-danger" type="submit"><?= $reservation['etat'] === ReservationOption::CONFIRMEE ? 'Annuler la réservation' : 'Libérer le créneau' ?></button>
            </form>
        <?php endif; ?>
    </div>

    <p class="empty">Gardez l'adresse de cette page : c'est votre lien personnel pour suivre la réservation.</p>
</section>
<?php require __DIR__ . '/../partials/footer.php'; ?>

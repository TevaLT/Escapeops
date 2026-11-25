<?php
/** @var array<string, mixed> $creneau */
/** @var array{nom_groupe: string, contact: string, nb_joueurs: string} $valeurs */
/** @var array<string, string> $erreurs */
/** @var \App\Security\Csrf $csrf */
$titre = 'Réserver · ' . $creneau['salle'];
$libre = $creneau['disponibilite'] === \App\Domain\SlotAvailability::LIBRE;
require __DIR__ . '/../partials/header.php';
?>
<p><a href="/salles/<?= (int) $creneau['id_salle'] ?>">← <?= e($creneau['salle']) ?></a></p>

<section class="hero narrow">
    <p class="eyebrow">Réservation</p>
    <h1><?= e($creneau['salle']) ?></h1>
    <p><?= e(date_fr($creneau['date_debut'])) ?> → <?= e(heure_fr($creneau['date_fin'])) ?> · de <?= (int) $creneau['capacite_min'] ?> à <?= (int) $creneau['capacite_max'] ?> joueurs</p>

    <?php if (!$libre): ?>
        <p class="alert alert-error" role="alert"><?= e($creneau['motif']) ?></p>
        <a class="button" href="/salles/<?= (int) $creneau['id_salle'] ?>">Voir les autres créneaux</a>
    <?php else: ?>
        <?= bandeau_erreurs($erreurs, ['nom_groupe', 'contact', 'nb_joueurs'], 'La réservation n\'a pas été enregistrée.') ?>

        <form class="form" method="post" action="/sessions/<?= (int) $creneau['id_session'] ?>/reserver">
            <?= $csrf->field() ?>

            <label for="nom_groupe">Nom du groupe</label>
            <input id="nom_groupe" name="nom_groupe" required maxlength="100" value="<?= e($valeurs['nom_groupe']) ?>">
            <?= erreur_champ($erreurs, 'nom_groupe') ?>

            <label for="contact">E-mail ou téléphone</label>
            <input id="contact" name="contact" required maxlength="150" value="<?= e($valeurs['contact']) ?>" placeholder="groupe@example.test">
            <?= erreur_champ($erreurs, 'contact') ?>

            <label for="nb_joueurs">Nombre de joueurs</label>
            <input id="nb_joueurs" name="nb_joueurs" type="number" required min="<?= (int) $creneau['capacite_min'] ?>" max="<?= (int) $creneau['capacite_max'] ?>" value="<?= e($valeurs['nb_joueurs']) ?>">
            <?= erreur_champ($erreurs, 'nb_joueurs') ?>

            <button class="button" type="submit">Poser une option de <?= \App\Domain\ReservationOption::MINUTES ?> minutes</button>
        </form>
        <p class="empty">L'option est gratuite : le créneau vous est gardé <?= \App\Domain\ReservationOption::MINUTES ?> minutes, le temps de réunir votre équipe. Confirmez-la ensuite depuis la page de suivi.</p>
        <p class="empty">Données fictives : aucun e-mail ni SMS n'est envoyé.</p>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../partials/footer.php'; ?>

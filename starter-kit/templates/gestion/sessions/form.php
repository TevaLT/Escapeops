<?php
/** @var array<string, mixed>|null $session null = création */
/** @var array{salle: string, debut: string, game_master: string} $valeurs */
/** @var array<string, string> $erreurs */
/** @var list<array<string, mixed>> $salles */
/** @var list<array<string, mixed>> $gameMasters */
/** @var \App\Security\Csrf $csrf */
$creation = $session === null;
$titre = $creation ? 'Nouvelle session' : 'Modifier la session';
$action = $creation ? '/gestion/sessions' : '/gestion/sessions/' . (int) $session['id_session'];
require __DIR__ . '/../../partials/header.php';
?>
<p><a href="/gestion/sessions">← Planning</a></p>

<section class="hero narrow">
    <p class="eyebrow">Planning</p>
    <h1><?= e($titre) ?></h1>
    <p>La fin est calculée automatiquement : début + durée de la salle.</p>

    <?= bandeau_erreurs($erreurs, ['salle', 'debut', 'game_master'], 'La session n\'a pas été enregistrée.') ?>

    <form class="form" method="post" action="<?= e($action) ?>">
        <?= $csrf->field() ?>

        <label for="salle">Salle</label>
        <select id="salle" name="salle" required>
            <option value="">— Choisir une salle —</option>
            <?php foreach ($salles as $salle): ?>
                <option value="<?= (int) $salle['id_salle'] ?>" <?= (string) $salle['id_salle'] === $valeurs['salle'] ? 'selected' : '' ?>>
                    <?= e($salle['nom']) ?> (<?= (int) $salle['duree_minutes'] ?> min + <?= (int) $salle['temps_remise_etat'] ?> min de remise en état)
                </option>
            <?php endforeach; ?>
        </select>
        <?= erreur_champ($erreurs, 'salle') ?>

        <label for="debut">Début</label>
        <input id="debut" name="debut" type="datetime-local" required value="<?= e($valeurs['debut']) ?>">
        <?= erreur_champ($erreurs, 'debut') ?>

        <label for="game_master">Game master (facultatif)</label>
        <select id="game_master" name="game_master">
            <option value="">— Aucun pour l'instant —</option>
            <?php foreach ($gameMasters as $gm): ?>
                <option value="<?= (int) $gm['id_utilisateur'] ?>" <?= (string) $gm['id_utilisateur'] === $valeurs['game_master'] ? 'selected' : '' ?>>
                    <?= e($gm['prenom'] . ' ' . $gm['nom']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?= erreur_champ($erreurs, 'game_master') ?>
        <p class="empty">Un game master ne peut pas animer deux sessions qui se chevauchent ; une partie ne démarre pas sans lui.</p>

        <button class="button" type="submit"><?= $creation ? 'Créer la session' : 'Enregistrer' ?></button>
    </form>
</section>
<?php require __DIR__ . '/../../partials/footer.php'; ?>

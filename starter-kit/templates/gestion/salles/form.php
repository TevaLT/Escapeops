<?php
/** @var array<string, mixed>|null $salle null = création */
/** @var array<string, string> $valeurs */
/** @var array<string, string> $erreurs */
/** @var \App\Security\Csrf $csrf */
$creation = $salle === null;
$titre = $creation ? 'Nouvelle salle' : 'Modifier « ' . $salle['nom'] . ' »';
$action = $creation ? '/gestion/salles' : '/gestion/salles/' . (int) $salle['id_salle'];
$champs = ['nom', 'theme', 'description', 'duree_minutes', 'capacite_min', 'capacite_max', 'temps_remise_etat'];
require __DIR__ . '/../../partials/header.php';
?>
<p><a href="<?= $creation ? '/gestion/salles' : e($action) ?>">← Retour</a></p>

<section class="hero narrow">
    <p class="eyebrow">Salles</p>
    <h1><?= e($titre) ?></h1>

    <?= bandeau_erreurs($erreurs, $champs, 'La salle n\'a pas été enregistrée.') ?>

    <form class="form" method="post" action="<?= e($action) ?>">
        <?= $csrf->field() ?>

        <label for="nom">Nom</label>
        <input id="nom" name="nom" required maxlength="100" value="<?= e($valeurs['nom']) ?>">
        <?= erreur_champ($erreurs, 'nom') ?>

        <label for="theme">Thème</label>
        <input id="theme" name="theme" required maxlength="100" value="<?= e($valeurs['theme']) ?>">
        <?= erreur_champ($erreurs, 'theme') ?>

        <label for="description">Description</label>
        <textarea id="description" name="description" rows="4" maxlength="2000"><?= e($valeurs['description']) ?></textarea>
        <?= erreur_champ($erreurs, 'description') ?>

        <label for="duree_minutes">Durée d'une partie (minutes)</label>
        <input id="duree_minutes" name="duree_minutes" type="number" required min="1" max="600" value="<?= e($valeurs['duree_minutes']) ?>">
        <?= erreur_champ($erreurs, 'duree_minutes') ?>

        <label for="capacite_min">Joueurs minimum</label>
        <input id="capacite_min" name="capacite_min" type="number" required min="1" max="50" value="<?= e($valeurs['capacite_min']) ?>">
        <?= erreur_champ($erreurs, 'capacite_min') ?>

        <label for="capacite_max">Joueurs maximum</label>
        <input id="capacite_max" name="capacite_max" type="number" required min="1" max="50" value="<?= e($valeurs['capacite_max']) ?>">
        <?= erreur_champ($erreurs, 'capacite_max') ?>

        <label for="temps_remise_etat">Remise en état entre deux parties (minutes)</label>
        <input id="temps_remise_etat" name="temps_remise_etat" type="number" required min="0" max="600" value="<?= e($valeurs['temps_remise_etat']) ?>">
        <?= erreur_champ($erreurs, 'temps_remise_etat') ?>

        <button class="button" type="submit"><?= $creation ? 'Créer la salle' : 'Enregistrer' ?></button>
    </form>
</section>
<?php require __DIR__ . '/../../partials/footer.php'; ?>

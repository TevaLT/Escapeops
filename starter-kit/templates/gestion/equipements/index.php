<?php
/** @var list<array<string, mixed>> $equipements */
/** @var array{nom: string, est_critique: string} $valeurs */
/** @var array<string, string> $erreurs */
/** @var \App\Security\Csrf $csrf */
$titre = 'Équipements';
require __DIR__ . '/../../partials/header.php';
?>
<p><a href="/gestion">← Tableau de bord</a></p>

<section class="hero">
    <p class="eyebrow">Gestion</p>
    <h1>Équipements</h1>
    <p>Un équipement peut être installé dans plusieurs salles. On l'affecte depuis la fiche d'une salle.</p>
</section>

<table>
    <thead><tr><th>Équipement</th><th>Critique</th><th>État</th><th>Salles</th></tr></thead>
    <tbody>
    <?php foreach ($equipements as $equipement): ?>
        <tr>
            <td><?= e($equipement['nom']) ?></td>
            <td><?= (int) $equipement['est_critique'] === 1 ? '<span class="badge badge-statut-annulee">critique</span>' : '—' ?></td>
            <td><?= $equipement['statut'] === 'HORS_SERVICE' ? 'Hors service' : 'Disponible' ?></td>
            <td><?= (int) $equipement['nb_salles'] ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<h2>Ajouter au catalogue</h2>
<?= bandeau_erreurs($erreurs, ['nom'], 'L\'équipement n\'a pas été ajouté.') ?>
<form class="form narrow" method="post" action="/gestion/equipements">
    <?= $csrf->field() ?>
    <label for="nom">Nom</label>
    <input id="nom" name="nom" required maxlength="100" value="<?= e($valeurs['nom']) ?>">
    <?= erreur_champ($erreurs, 'nom') ?>
    <label class="check"><input type="checkbox" name="est_critique" value="1" <?= $valeurs['est_critique'] === '1' ? 'checked' : '' ?>> Équipement critique (sa panne empêche de jouer)</label>
    <button class="button" type="submit">Ajouter</button>
</form>
<?php require __DIR__ . '/../../partials/footer.php'; ?>

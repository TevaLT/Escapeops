<?php
/** @var list<array<string, mixed>> $salles */
$titre = 'Salles';
require __DIR__ . '/../../partials/header.php';
?>
<p><a href="/gestion">← Tableau de bord</a></p>

<section class="hero">
    <p class="eyebrow">Gestion</p>
    <h1>Salles</h1>
    <a class="button" href="/gestion/salles/nouvelle">Nouvelle salle</a>
</section>

<table>
    <thead><tr><th>Salle</th><th>Thème</th><th>Durée</th><th>Joueurs</th><th>Remise en état</th><th>Équipements</th></tr></thead>
    <tbody>
    <?php foreach ($salles as $salle): ?>
        <tr>
            <td><a href="/gestion/salles/<?= (int) $salle['id'] ?>"><?= e($salle['nom']) ?></a></td>
            <td><?= e($salle['theme']) ?></td>
            <td><?= (int) $salle['dureeMinutes'] ?> min</td>
            <td><?= (int) $salle['capaciteMin'] ?> à <?= (int) $salle['capaciteMax'] ?></td>
            <td><?= (int) $salle['tempsRemiseEtat'] ?> min</td>
            <td><?= (int) $salle['equipements'] ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php require __DIR__ . '/../../partials/footer.php'; ?>

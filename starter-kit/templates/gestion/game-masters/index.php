<?php
/** @var list<array<string, mixed>> $gameMasters */
$titre = 'Game masters';
require __DIR__ . '/../../partials/header.php';
?>
<p><a href="/gestion">← Tableau de bord</a></p>

<section class="hero">
    <p class="eyebrow">Gestion</p>
    <h1>Game masters</h1>
    <a class="button" href="/gestion/game-masters/nouveau">Ajouter un game master</a>
</section>

<?php if ($gameMasters === []): ?>
    <p class="empty">Aucun game master.</p>
<?php else: ?>
    <table>
        <thead><tr><th>Nom</th><th>E-mail</th><th>Sessions à venir</th><th>Prochaine session</th></tr></thead>
        <tbody>
        <?php foreach ($gameMasters as $gm): ?>
            <tr>
                <td><a href="/gestion/game-masters/<?= (int) $gm['id_utilisateur'] ?>"><?= e($gm['prenom'] . ' ' . $gm['nom']) ?></a></td>
                <td><?= e($gm['email']) ?></td>
                <td><?= (int) $gm['nb_sessions'] ?></td>
                <td><?= $gm['prochaine'] !== null ? e(date_fr($gm['prochaine'])) : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<?php require __DIR__ . '/../../partials/footer.php'; ?>

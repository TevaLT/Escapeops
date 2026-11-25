<?php
/** @var array<string, mixed> $gm */
$titre = $gm['prenom'] . ' ' . $gm['nom'];
require __DIR__ . '/../../partials/header.php';
?>
<p><a href="/gestion/game-masters">← Game masters</a></p>

<section class="hero">
    <p class="eyebrow">Game master</p>
    <h1><?= e($gm['prenom'] . ' ' . $gm['nom']) ?></h1>
    <p><?= e($gm['email']) ?></p>
</section>

<h2>Sessions d'aujourd'hui et à venir</h2>
<?php if ($gm['sessions'] === []): ?>
    <p class="empty">Aucune session affectée.</p>
<?php else: ?>
    <table>
        <thead><tr><th>Créneau</th><th>Fin</th><th>Salle</th><th>Groupe</th><th>État</th></tr></thead>
        <tbody>
        <?php foreach ($gm['sessions'] as $session): ?>
            <tr>
                <td><a href="/espace/sessions/<?= (int) $session['id_session'] ?>"><?= e(date_fr($session['date_debut'])) ?></a></td>
                <td><?= e(heure_fr($session['date_fin'])) ?></td>
                <td><?= e($session['salle']) ?></td>
                <td><?= $session['groupe'] !== null ? e($session['groupe']) : '<span class="empty">—</span>' ?></td>
                <td><span class="badge badge-statut-<?= e(strtolower($session['statut'])) ?>"><?= e(libelle_statut($session['statut'])) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<?php require __DIR__ . '/../../partials/footer.php'; ?>

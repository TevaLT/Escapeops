<?php
/** @var array<string, mixed> $salle */
$titre = $salle['nom'];
$libelles = [
    'LIBRE' => 'Disponible',
    'EN_OPTION' => 'En option',
    'COMPLET' => 'Complet',
    'FERME' => 'Fermé',
];
require __DIR__ . '/../partials/header.php';
?>
<p><a href="/">← Toutes les salles</a></p>

<section class="hero">
    <p class="eyebrow"><?= e($salle['theme']) ?></p>
    <h1><?= e($salle['nom']) ?></h1>
    <p><?= e($salle['description']) ?></p>
    <ul class="facts">
        <li>⏱ <?= (int) $salle['dureeMinutes'] ?> min</li>
        <li>👥 <?= (int) $salle['capaciteMin'] ?> à <?= (int) $salle['capaciteMax'] ?> joueurs</li>
    </ul>
</section>

<h2>Prochains créneaux</h2>

<?php if ($salle['sessions'] === []): ?>
    <p class="empty">Aucun créneau publié pour cette salle.</p>
<?php else: ?>
    <table>
        <thead><tr><th>Créneau</th><th>Fin</th><th>Disponibilité</th></tr></thead>
        <tbody>
        <?php foreach ($salle['sessions'] as $session): ?>
            <tr>
                <td><?= e(date_fr($session['debut'])) ?></td>
                <td><?= e(heure_fr($session['fin'])) ?></td>
                <td>
                    <span class="badge badge-<?= e(strtolower($session['disponibilite'])) ?>">
                        <?= e($libelles[$session['disponibilite']] ?? $session['disponibilite']) ?>
                    </span>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<?php require __DIR__ . '/../partials/footer.php'; ?>

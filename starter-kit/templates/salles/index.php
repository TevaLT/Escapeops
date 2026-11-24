<?php
/** @var array<int, array<string, mixed>> $salles */
$titre = 'Nos salles';
require __DIR__ . '/../partials/header.php';
?>
<section class="hero">
    <p class="eyebrow">Escape game · 3 salles privatisées</p>
    <h1>Entrez. Cherchez. Sortez à temps.</h1>
    <p>Chaque créneau est réservé pour <strong>un seul groupe</strong>. Choisissez votre salle et posez une option gratuite de 15 minutes le temps de réunir votre équipe.</p>
</section>

<?php if ($salles === []): ?>
    <p class="empty">Aucune salle n'est proposée pour le moment.</p>
<?php else: ?>
    <div class="grid">
        <?php foreach ($salles as $salle): ?>
            <article class="card">
                <p class="eyebrow"><?= e($salle['theme']) ?></p>
                <h2><?= e($salle['nom']) ?></h2>
                <p><?= e($salle['description']) ?></p>
                <ul class="facts">
                    <li>⏱ <?= (int) $salle['dureeMinutes'] ?> min</li>
                    <li>👥 <?= (int) $salle['capaciteMin'] ?> à <?= (int) $salle['capaciteMax'] ?> joueurs</li>
                </ul>
                <a class="button" href="/salles/<?= (int) $salle['id'] ?>">Voir les créneaux</a>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../partials/footer.php'; ?>

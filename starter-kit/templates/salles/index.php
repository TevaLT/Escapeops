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

<?php /* Présentation de l'entreprise (client fictif du projet : histoire inventée) */ ?>
<section class="about" aria-labelledby="a-propos">
    <div>
        <p class="eyebrow">Qui sommes-nous ?</p>
        <h2 id="a-propos">Les Archives Obscures</h2>
        <p>
            Tout a commencé en 2019, quand deux anciens archivistes ont récupéré le sous-sol d'une imprimerie fermée,
            ses rayonnages poussiéreux et ses caisses de documents jamais classés. Plutôt que de tout jeter, ils en ont fait
            un terrain de jeu : chaque salle raconte une histoire tirée de ces archives imaginaires, et c'est à vous de la
            reconstituer avant la fin du temps imparti.
        </p>
        <p>
            Aujourd'hui, une petite équipe de game masters prépare chaque salle avant votre arrivée, vous accompagne
            pendant la partie et vous débriefe à la sortie. Ici, pas de groupes mélangés : la salle est à vous seuls,
            le temps d'une enquête.
        </p>
    </div>
    <?php if ($salles !== []): ?>
        <?php
        // Chiffres calculés à partir des salles en base : ils suivent les ajouts et modifications du responsable.
        $plage = static fn (int $min, int $max): string => $min === $max ? (string) $min : $min . ' à ' . $max;
        $durees = array_map('intval', array_column($salles, 'dureeMinutes'));
        ?>
        <ul class="about-facts">
            <li><strong><?= count($salles) ?></strong> salle<?= count($salles) > 1 ? 's' : '' ?> privatisée<?= count($salles) > 1 ? 's' : '' ?></li>
            <li><strong><?= e($plage(min($durees), max($durees))) ?></strong> minutes par partie</li>
            <li><strong><?= e($plage(min(array_map('intval', array_column($salles, 'capaciteMin'))), max(array_map('intval', array_column($salles, 'capaciteMax'))))) ?></strong> joueurs par groupe</li>
            <li><strong>1</strong> game master dédié à chaque partie</li>
        </ul>
    <?php endif; ?>
</section>

<h2>Nos salles</h2>

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

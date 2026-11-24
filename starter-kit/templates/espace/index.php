<?php
/** @var \App\Domain\CurrentUser $utilisateur */
/** @var bool $peutGererPlanning */
$titre = 'Mon espace';
require __DIR__ . '/../partials/header.php';
?>
<section class="hero">
    <p class="eyebrow"><?= e($utilisateur->role->label()) ?></p>
    <h1>Bonjour <?= e($utilisateur->prenom) ?></h1>
    <p>Vous êtes connecté·e à l'espace personnel des Archives Obscures.</p>
</section>

<div class="grid">
    <?php if ($peutGererPlanning): ?>
        <article class="card">
            <h2>Gestion</h2>
            <p>Planning des sessions, salles, tableau de bord d'exploitation.</p>
            <a class="button" href="/gestion">Ouvrir</a>
        </article>
    <?php else: ?>
        <article class="card">
            <h2>Mes sessions</h2>
            <p>Vos sessions à animer et leurs checklists apparaîtront ici.</p>
        </article>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>

<?php
/** @var \App\Domain\CurrentUser $utilisateur */
$titre = 'Gestion';
require __DIR__ . '/../partials/header.php';
?>
<section class="hero">
    <p class="eyebrow">Espace gestion · <?= e($utilisateur->role->label()) ?></p>
    <h1>Gestion de l'exploitation</h1>
    <p>Cette zone n'est accessible qu'au responsable et à l'administrateur. Un game master reçoit un refus (403).</p>
</section>

<p class="empty">Le planning des sessions (US-22) et le tableau de bord (US-28) seront ajoutés ici.</p>
<?php require __DIR__ . '/../partials/footer.php'; ?>

<?php
/** @var string $titre */
/** @var string $message */
require __DIR__ . '/partials/header.php';
?>
<section class="hero">
    <h1><?= e($titre) ?></h1>
    <p><?= e($message) ?></p>
    <a class="button" href="/">Retour aux salles</a>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>

<?php
/** @var string $email */
/** @var string|null $erreur */
/** @var \App\Security\Csrf $csrf */
$titre = 'Connexion';
require __DIR__ . '/../partials/header.php';
?>
<section class="hero narrow">
    <p class="eyebrow">Espace personnel</p>
    <h1>Connexion</h1>
    <p>Réservé à l'équipe des Archives Obscures (administration, responsable, game masters).</p>

    <?php if ($erreur !== null): ?>
        <p class="alert alert-error" role="alert"><?= e($erreur) ?></p>
    <?php endif; ?>

    <form class="form" method="post" action="/connexion">
        <?= $csrf->field() ?>
        <label for="email">E-mail</label>
        <input id="email" name="email" type="email" required maxlength="150" autocomplete="username" value="<?= e($email) ?>">

        <label for="mot_de_passe">Mot de passe</label>
        <input id="mot_de_passe" name="mot_de_passe" type="password" required autocomplete="current-password">

        <button class="button" type="submit">Se connecter</button>
    </form>
</section>
<?php require __DIR__ . '/../partials/footer.php'; ?>

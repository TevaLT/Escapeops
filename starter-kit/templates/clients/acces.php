<?php
/** @var array{contact: string, code: string} $valeurs */
/** @var array<string, string> $erreurs */
/** @var \App\Security\Csrf $csrf */
$titre = 'Mes réservations';
require __DIR__ . '/../partials/header.php';
?>
<section class="hero narrow">
    <p class="eyebrow">Espace client</p>
    <h1>Mes réservations</h1>
    <p>Pas besoin de compte : indiquez le contact donné lors de la réservation et le <strong>code de suivi</strong> affiché sur la page de votre option.</p>

    <?= bandeau_erreurs($erreurs, ['contact', 'code'], 'Accès refusé.') ?>

    <form class="form" method="post" action="/mes-reservations/connexion">
        <?= $csrf->field() ?>

        <label for="contact">E-mail ou téléphone</label>
        <input id="contact" name="contact" required maxlength="150" autocomplete="email" value="<?= e($valeurs['contact']) ?>" placeholder="groupe@example.test">
        <?= erreur_champ($erreurs, 'contact') ?>

        <label for="code">Code de suivi</label>
        <input id="code" name="code" required maxlength="12" autocomplete="off" value="<?= e($valeurs['code']) ?>" placeholder="ABCD-2345">
        <?= erreur_champ($erreurs, 'code') ?>

        <button class="button" type="submit">Voir mes réservations</button>
    </form>
    <p class="empty">Le code vous est donné quand vous posez une option. Vous ne l'avez plus ? Contactez l'accueil des Archives Obscures.</p>
</section>
<?php require __DIR__ . '/../partials/footer.php'; ?>

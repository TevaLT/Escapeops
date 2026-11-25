<?php
/** @var string $titre */
/** @var \App\Domain\CurrentUser|null $utilisateurConnecte */
/** @var \App\Security\Csrf $csrf */
/** @var list<array{type: string, message: string}> $flashes */
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titre) ?> · Les Archives Obscures</title>
    <link rel="stylesheet" href="/css/app.css">
</head>
<body>
<header class="site-header">
    <a class="brand" href="/">Les Archives <span>Obscures</span></a>
    <nav>
        <a href="/">Nos salles</a>
        <?php if ($utilisateurConnecte !== null): ?>
            <a href="/espace">Mon espace</a>
            <a href="/espace/incidents">Incidents</a>
            <?php if (\App\Domain\Authorization::canManagePlanning($utilisateurConnecte)): ?>
                <a href="/gestion">Gestion</a>
            <?php endif; ?>
            <span class="who"><?= e($utilisateurConnecte->displayName()) ?> · <?= e($utilisateurConnecte->role->label()) ?></span>
            <form class="inline" method="post" action="/deconnexion">
                <?= $csrf->field() ?>
                <button type="submit" class="link">Déconnexion</button>
            </form>
        <?php else: ?>
            <a href="/mes-reservations">Mes réservations</a>
            <a href="/connexion">Espace personnel</a>
        <?php endif; ?>
    </nav>
</header>
<main>
<?php foreach ($flashes ?? [] as $flash): ?>
    <p class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></p>
<?php endforeach; ?>

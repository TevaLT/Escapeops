<?php
/** @var array{prenom: string, nom: string, email: string} $valeurs */
/** @var array<string, string> $erreurs */
/** @var \App\Security\Csrf $csrf */
use App\Service\GameMasterService;

$titre = 'Ajouter un game master';
require __DIR__ . '/../../partials/header.php';
?>
<p><a href="/gestion/game-masters">← Game masters</a></p>

<section class="hero narrow">
    <p class="eyebrow">Équipe</p>
    <h1>Ajouter un game master</h1>
    <p>Le compte est créé avec le rôle « game master » : la personne pourra consulter ses sessions, remplir la checklist d'ouverture et déclarer des incidents.</p>

    <?= bandeau_erreurs($erreurs, ['prenom', 'nom', 'email', 'mot_de_passe', 'confirmation'], 'Le game master n\'a pas été ajouté.') ?>

    <form class="form" method="post" action="/gestion/game-masters" autocomplete="off">
        <?= $csrf->field() ?>

        <label for="prenom">Prénom</label>
        <input id="prenom" name="prenom" required maxlength="<?= GameMasterService::MAX_NAME ?>" value="<?= e($valeurs['prenom']) ?>">
        <?= erreur_champ($erreurs, 'prenom') ?>

        <label for="nom">Nom</label>
        <input id="nom" name="nom" required maxlength="<?= GameMasterService::MAX_NAME ?>" value="<?= e($valeurs['nom']) ?>">
        <?= erreur_champ($erreurs, 'nom') ?>

        <label for="email">E-mail (identifiant de connexion)</label>
        <input id="email" name="email" type="email" required maxlength="<?= GameMasterService::MAX_EMAIL ?>" value="<?= e($valeurs['email']) ?>">
        <?= erreur_champ($erreurs, 'email') ?>

        <label for="mot_de_passe">Mot de passe initial</label>
        <input id="mot_de_passe" name="mot_de_passe" type="password" required minlength="<?= GameMasterService::MIN_PASSWORD ?>" maxlength="<?= GameMasterService::MAX_PASSWORD ?>" autocomplete="new-password">
        <?= erreur_champ($erreurs, 'mot_de_passe') ?>

        <label for="confirmation">Confirmer le mot de passe</label>
        <input id="confirmation" name="confirmation" type="password" required autocomplete="new-password">
        <?= erreur_champ($erreurs, 'confirmation') ?>

        <p class="empty">Au moins <?= GameMasterService::MIN_PASSWORD ?> caractères, avec une lettre et un chiffre. Transmettez-le au game master de vive voix : aucun e-mail n'est envoyé.</p>

        <button class="button" type="submit">Ajouter le game master</button>
    </form>
</section>
<?php require __DIR__ . '/../../partials/footer.php'; ?>

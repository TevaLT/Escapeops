<?php
/** @var array<string, string> $valeurs */
/** @var array<string, string> $erreurs */
/** @var list<array<string, mixed>> $salles chaque salle avec ses equipements */
/** @var list<string> $gravites */
/** @var \App\Security\Csrf $csrf */
$titre = 'Déclarer un incident';
require __DIR__ . '/../../partials/header.php';
?>
<p><a href="/espace/incidents">← Incidents</a></p>

<section class="hero narrow">
    <p class="eyebrow">Incidents</p>
    <h1>Déclarer un incident</h1>

    <?= bandeau_erreurs($erreurs, ['salle', 'equipement', 'session', 'description', 'gravite'], 'L\'incident n\'a pas été enregistré.') ?>

    <form class="form" method="post" action="/espace/incidents">
        <?= $csrf->field() ?>
        <input type="hidden" name="session" value="<?= e($valeurs['session']) ?>">
        <?= erreur_champ($erreurs, 'session') ?>

        <label for="salle">Salle</label>
        <select id="salle" name="salle" required>
            <option value="">— Choisir une salle —</option>
            <?php foreach ($salles as $salle): ?>
                <option value="<?= (int) $salle['id_salle'] ?>" <?= (string) $salle['id_salle'] === $valeurs['salle'] ? 'selected' : '' ?>><?= e($salle['nom']) ?></option>
            <?php endforeach; ?>
        </select>
        <?= erreur_champ($erreurs, 'salle') ?>

        <label for="equipement">Équipement concerné (facultatif)</label>
        <select id="equipement" name="equipement">
            <option value="">— Aucun en particulier —</option>
            <?php foreach ($salles as $salle): ?>
                <?php if ($salle['equipements'] !== []): ?>
                    <optgroup label="<?= e($salle['nom']) ?>">
                        <?php foreach ($salle['equipements'] as $equipement): ?>
                            <option value="<?= (int) $equipement['id_equipement'] ?>" <?= (string) $equipement['id_equipement'] === $valeurs['equipement'] ? 'selected' : '' ?>>
                                <?= e($equipement['nom']) ?><?= (int) $equipement['est_critique'] === 1 ? ' (critique)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>
            <?php endforeach; ?>
        </select>
        <?= erreur_champ($erreurs, 'equipement') ?>

        <label for="description">Description</label>
        <textarea id="description" name="description" rows="4" required maxlength="2000"><?= e($valeurs['description']) ?></textarea>
        <?= erreur_champ($erreurs, 'description') ?>

        <label for="gravite">Gravité</label>
        <select id="gravite" name="gravite">
            <?php foreach ($gravites as $gravite): ?>
                <option value="<?= e($gravite) ?>" <?= $gravite === $valeurs['gravite'] ? 'selected' : '' ?>><?= e(ucfirst(strtolower($gravite))) ?></option>
            <?php endforeach; ?>
        </select>
        <?= erreur_champ($erreurs, 'gravite') ?>

        <label class="check"><input type="checkbox" name="est_bloquant" value="1" <?= $valeurs['est_bloquant'] === '1' ? 'checked' : '' ?>> Bloquant : la salle ne peut pas accueillir de partie</label>

        <button class="button" type="submit">Déclarer</button>
    </form>
</section>
<?php require __DIR__ . '/../../partials/footer.php'; ?>

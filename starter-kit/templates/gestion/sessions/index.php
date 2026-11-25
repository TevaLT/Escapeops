<?php
/** @var list<array<string, mixed>> $sessions */
/** @var array{salle: string, date: string, etat: string} $filtres */
/** @var list<array<string, mixed>> $salles */
/** @var list<string> $etats */
/** @var string $statutModifiable */
$titre = 'Planning des sessions';
$filtre = $filtres['salle'] !== '' || $filtres['date'] !== '' || $filtres['etat'] !== '';
require __DIR__ . '/../../partials/header.php';
?>
<p><a href="/gestion">← Tableau de bord</a></p>

<section class="hero">
    <p class="eyebrow">Gestion</p>
    <h1>Planning des sessions</h1>
    <p>Sans filtre de date : les sessions d'aujourd'hui et à venir. La fin est calculée à partir de la durée de la salle.</p>
    <a class="button" href="/gestion/sessions/nouvelle">Nouvelle session</a>
</section>

<form class="filters" method="get" action="/gestion/sessions">
    <label>Salle
        <select name="salle">
            <option value="">Toutes</option>
            <?php foreach ($salles as $salle): ?>
                <option value="<?= (int) $salle['id_salle'] ?>" <?= (string) $salle['id_salle'] === $filtres['salle'] ? 'selected' : '' ?>><?= e($salle['nom']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Date
        <input type="date" name="date" value="<?= e($filtres['date']) ?>">
    </label>
    <label>État
        <select name="etat">
            <option value="">Tous</option>
            <?php foreach ($etats as $etat): ?>
                <option value="<?= e($etat) ?>" <?= $etat === $filtres['etat'] ? 'selected' : '' ?>><?= e(libelle_statut($etat)) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button class="button" type="submit">Filtrer</button>
    <?php if ($filtre): ?><a href="/gestion/sessions">Effacer les filtres</a><?php endif; ?>
</form>

<?php if ($sessions === []): ?>
    <p class="empty"><?= $filtre ? 'Aucun résultat pour ces filtres.' : 'Aucune session programmée.' ?></p>
<?php else: ?>
    <table>
        <thead>
        <tr><th>Créneau</th><th>Fin</th><th>Salle</th><th>Game master</th><th>Groupe confirmé</th><th>État</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($sessions as $session): ?>
            <tr>
                <td><a href="/espace/sessions/<?= (int) $session['id_session'] ?>"><?= e(date_fr($session['date_debut'])) ?></a></td>
                <td><?= e(heure_fr($session['date_fin'])) ?></td>
                <td><?= e($session['salle']) ?></td>
                <td><?= cellule_game_master($session) ?></td>
                <td><?= cellule_groupe($session, '—') ?></td>
                <td><span class="badge badge-statut-<?= e(strtolower($session['statut'])) ?>"><?= e(libelle_statut($session['statut'])) ?></span></td>
                <td>
                    <?php if ($session['statut'] === $statutModifiable): ?>
                        <a href="/gestion/sessions/<?= (int) $session['id_session'] ?>/modifier">Modifier</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="empty"><?= count($sessions) ?> session(s).</p>
<?php endif; ?>
<?php require __DIR__ . '/../../partials/footer.php'; ?>

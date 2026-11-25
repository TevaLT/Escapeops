<?php
/** @var \App\Domain\CurrentUser $utilisateur */
/** @var array{prochaines: array, incidents: array, salles: array} $tableau */
$titre = 'Tableau de bord';
$etatsSalle = [
    'BLOQUEE' => ['Bloquée (incident bloquant)', 'annulee'],
    'EN_PARTIE' => ['Partie en cours', 'en_cours'],
    'DISPONIBLE' => ['Disponible', 'prete'],
];
require __DIR__ . '/../partials/header.php';
?>
<section class="hero">
    <p class="eyebrow">Gestion · <?= e($utilisateur->role->label()) ?></p>
    <h1>Tableau de bord</h1>
    <p>L'état de l'exploitation en ce moment. Zone réservée au responsable et à l'administrateur.</p>
</section>

<nav class="subnav">
    <a href="/gestion/sessions">Planning</a>
    <a href="/gestion/salles">Salles</a>
    <a href="/gestion/equipements">Équipements</a>
    <a href="/gestion/game-masters">Game masters</a>
    <a href="/espace/incidents">Incidents</a>
</nav>

<h2>Salles</h2>
<div class="grid">
    <?php foreach ($tableau['salles'] as $salle): ?>
        <?php [$libelle, $classe] = $etatsSalle[$salle['etat']]; ?>
        <article class="card">
            <h3><a href="/gestion/salles/<?= (int) $salle['id_salle'] ?>"><?= e($salle['nom']) ?></a></h3>
            <p><span class="badge badge-statut-<?= e($classe) ?>"><?= e($libelle) ?></span></p>
            <ul class="facts">
                <li>Incidents ouverts : <?= (int) $salle['incidents_ouverts'] ?></li>
                <li>Prochaine session : <?= $salle['prochaine_session'] !== null ? e(date_fr($salle['prochaine_session'])) : '—' ?></li>
            </ul>
        </article>
    <?php endforeach; ?>
</div>

<h2>Prochaines sessions</h2>
<?php if ($tableau['prochaines'] === []): ?>
    <p class="empty">Aucune session à venir.</p>
<?php else: ?>
    <table>
        <thead><tr><th>Créneau</th><th>Salle</th><th>Game master</th><th>Groupe</th><th>État</th></tr></thead>
        <tbody>
        <?php foreach ($tableau['prochaines'] as $session): ?>
            <tr>
                <td><a href="/espace/sessions/<?= (int) $session['id_session'] ?>"><?= e(date_fr($session['date_debut'])) ?></a></td>
                <td><?= e($session['salle']) ?></td>
                <td><?= cellule_game_master($session) ?></td>
                <td><?= cellule_groupe($session, 'libre') ?></td>
                <td><span class="badge badge-statut-<?= e(strtolower($session['statut'])) ?>"><?= e(libelle_statut($session['statut'])) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<h2>Incidents ouverts</h2>
<?php if ($tableau['incidents'] === []): ?>
    <p class="empty">Aucun incident ouvert.</p>
<?php else: ?>
    <table>
        <thead><tr><th>Salle</th><th>Équipement</th><th>Description</th><th>Gravité</th><th>Déclaré le</th></tr></thead>
        <tbody>
        <?php foreach ($tableau['incidents'] as $incident): ?>
            <tr>
                <td><?= e($incident['salle']) ?></td>
                <td><?= $incident['equipement'] !== null ? e($incident['equipement']) : '—' ?></td>
                <td><?= e($incident['description']) ?></td>
                <td><?= e(ucfirst(strtolower($incident['gravite']))) ?><?= (int) $incident['est_bloquant'] === 1 ? ' · <strong>bloquant</strong>' : '' ?></td>
                <td><?= e(date_fr($incident['date_declaration'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<?php require __DIR__ . '/../partials/footer.php'; ?>

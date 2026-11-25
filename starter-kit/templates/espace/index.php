<?php
/** @var \App\Domain\CurrentUser $utilisateur */
/** @var bool $peutGererPlanning */
/** @var list<array<string, mixed>> $mesSessions */
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
            <p>Tableau de bord, planning, salles, équipements, game masters.</p>
            <a class="button" href="/gestion">Ouvrir</a>
        </article>
    <?php endif; ?>
    <article class="card">
        <h2>Incidents</h2>
        <p>Signaler une panne ou un problème dans une salle, suivre les incidents ouverts.</p>
        <a class="button" href="/espace/incidents">Ouvrir</a>
    </article>
</div>

<h2>Mes sessions à animer</h2>
<?php if ($mesSessions === []): ?>
    <p class="empty">Aucune session ne vous est affectée aujourd'hui ni dans les jours à venir.</p>
<?php else: ?>
    <table>
        <thead><tr><th>Créneau</th><th>Fin</th><th>Salle</th><th>Groupe</th><th>État</th></tr></thead>
        <tbody>
        <?php foreach ($mesSessions as $session): ?>
            <tr>
                <td><a href="/espace/sessions/<?= (int) $session['id_session'] ?>"><?= e(date_fr($session['date_debut'])) ?></a></td>
                <td><?= e(heure_fr($session['date_fin'])) ?></td>
                <td><?= e($session['salle']) ?></td>
                <td><?= $session['groupe'] !== null ? e($session['groupe']) : '<span class="empty">—</span>' ?></td>
                <td><span class="badge badge-statut-<?= e(strtolower($session['statut'])) ?>"><?= e(libelle_statut($session['statut'])) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<?php require __DIR__ . '/../partials/footer.php'; ?>

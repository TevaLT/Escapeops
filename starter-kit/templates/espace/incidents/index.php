<?php
/** @var list<array<string, mixed>> $incidents */
/** @var bool $peutResoudre */
/** @var \App\Security\Csrf $csrf */
$titre = 'Incidents';
require __DIR__ . '/../../partials/header.php';
?>
<p><a href="/espace">← Mon espace</a></p>

<section class="hero">
    <p class="eyebrow">Exploitation</p>
    <h1>Incidents</h1>
    <p>Les incidents ouverts d'abord, puis les plus récents.</p>
    <a class="button" href="/espace/incidents/nouveau">Déclarer un incident</a>
</section>

<?php if ($incidents === []): ?>
    <p class="empty">Aucun incident déclaré.</p>
<?php else: ?>
    <table>
        <thead><tr><th>Déclaré le</th><th>Salle</th><th>Équipement</th><th>Description</th><th>Gravité</th><th>Par</th><th>État</th></tr></thead>
        <tbody>
        <?php foreach ($incidents as $incident): ?>
            <tr>
                <td><?= e(date_fr($incident['date_declaration'])) ?></td>
                <td><?= e($incident['salle']) ?></td>
                <td>
                    <?= $incident['equipement'] !== null ? e($incident['equipement']) : '—' ?>
                    <?= (int) ($incident['est_critique'] ?? 0) === 1 ? '<span class="badge badge-statut-annulee">critique</span>' : '' ?>
                </td>
                <td><?= e($incident['description']) ?></td>
                <td><?= e(ucfirst(strtolower($incident['gravite']))) ?><?= (int) $incident['est_bloquant'] === 1 ? ' · <strong>bloquant</strong>' : '' ?></td>
                <td><?= e($incident['declarant_prenom'] . ' ' . $incident['declarant_nom']) ?></td>
                <td>
                    <?php if ($incident['statut'] === 'OUVERT'): ?>
                        <span class="badge badge-statut-en_cours">Ouvert</span>
                        <?php if ($peutResoudre): ?>
                            <form class="inline" method="post" action="/gestion/incidents/<?= (int) $incident['id_incident'] ?>/resoudre">
                                <?= $csrf->field() ?>
                                <button class="link" type="submit">Résoudre</button>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="badge badge-statut-prete">Résolu</span>
                        <?= $incident['date_resolution'] !== null ? '<br><small>' . e(date_fr($incident['date_resolution'])) . '</small>' : '' ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<?php require __DIR__ . '/../../partials/footer.php'; ?>

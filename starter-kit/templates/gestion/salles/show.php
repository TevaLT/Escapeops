<?php
/** @var array<string, mixed> $salle */
/** @var array{sessions: int, incidents: int} $usage */
/** @var array{nb_terminees: int, nb_annulees: int, nb_interrompues: int, dernier_controle: ?string, terminees_depuis_controle: int} $bilan */
/** @var list<array<string, mixed>> $equipements */
/** @var list<array<string, mixed>> $disponibles */
/** @var array<string, string> $erreurs */
/** @var \App\Security\Csrf $csrf */
$titre = $salle['nom'];
$id = (int) $salle['id_salle'];
require __DIR__ . '/../../partials/header.php';
?>
<p><a href="/gestion/salles">← Salles</a></p>

<section class="hero">
    <p class="eyebrow"><?= e($salle['theme']) ?></p>
    <h1><?= e($salle['nom']) ?></h1>
    <p><?= e((string) $salle['description']) ?></p>
    <ul class="facts">
        <li>⏱ <?= (int) $salle['duree_minutes'] ?> min</li>
        <li>👥 <?= (int) $salle['capacite_min'] ?> à <?= (int) $salle['capacite_max'] ?> joueurs</li>
        <li>🧹 <?= (int) $salle['temps_remise_etat'] ?> min de remise en état</li>
        <li><?= $usage['sessions'] ?> session(s) · <?= $usage['incidents'] ?> incident(s)</li>
    </ul>
    <div class="actions">
        <a class="button" href="/gestion/salles/<?= $id ?>/modifier">Modifier</a>
        <a class="button" href="/gestion/sessions?salle=<?= $id ?>">Voir son planning</a>
        <form class="inline" method="post" action="/gestion/salles/<?= $id ?>/supprimer">
            <?= $csrf->field() ?>
            <button class="button button-danger" type="submit">Supprimer</button>
        </form>
    </div>
</section>

<?= bandeau_erreurs($erreurs, [], 'Action refusée.') ?>

<h2>Bilan d'exploitation</h2>
<?php /* Calculé par la procédure stockée sp_bilan_salle (US-61). */ ?>
<ul class="facts">
    <li><?= $bilan['nb_terminees'] ?> session(s) terminée(s)</li>
    <li><?= $bilan['nb_annulees'] ?> annulée(s)</li>
    <li><?= $bilan['nb_interrompues'] ?> interrompue(s)</li>
    <li>Dernier contrôle : <?= $bilan['dernier_controle'] !== null ? e(date_fr($bilan['dernier_controle'])) : 'jamais' ?></li>
    <li><?= $bilan['terminees_depuis_controle'] ?> session(s) terminée(s) depuis</li>
</ul>

<h2>Équipements</h2>
<?php if ($equipements === []): ?>
    <p class="empty">Aucun équipement dans cette salle.</p>
<?php else: ?>
    <table>
        <thead><tr><th>Équipement</th><th>Critique</th><th>État</th><th>Installé le</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($equipements as $equipement): ?>
            <tr>
                <td><?= e($equipement['nom']) ?></td>
                <td><?= (int) $equipement['est_critique'] === 1 ? '<span class="badge badge-statut-annulee">critique</span>' : '—' ?></td>
                <td><?= $equipement['statut'] === 'HORS_SERVICE' ? 'Hors service' : 'Disponible' ?></td>
                <td><?= $equipement['date_affectation'] !== null ? e((new DateTimeImmutable($equipement['date_affectation']))->format('d/m/Y')) : '—' ?></td>
                <td>
                    <form class="inline" method="post" action="/gestion/salles/<?= $id ?>/equipements/<?= (int) $equipement['id_equipement'] ?>/retirer">
                        <?= $csrf->field() ?>
                        <button class="link" type="submit">Retirer</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php if ($disponibles !== []): ?>
    <form class="filters" method="post" action="/gestion/salles/<?= $id ?>/equipements">
        <?= $csrf->field() ?>
        <label>Ajouter un équipement
            <select name="equipement" required>
                <option value="">— Choisir —</option>
                <?php foreach ($disponibles as $equipement): ?>
                    <option value="<?= (int) $equipement['id_equipement'] ?>"><?= e($equipement['nom']) ?><?= (int) $equipement['est_critique'] === 1 ? ' (critique)' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="button" type="submit">Affecter</button>
    </form>
<?php endif; ?>
<p><a href="/gestion/equipements">Catalogue des équipements</a></p>
<?php require __DIR__ . '/../../partials/footer.php'; ?>

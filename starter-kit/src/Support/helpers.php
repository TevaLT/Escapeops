<?php

declare(strict_types=1);

/**
 * Petits utilitaires d'affichage pour les vues PHP.
 */

/** Échappe une valeur pour l'afficher dans du HTML (protection XSS). */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** « 2026-11-23 18:00:00 » → « lundi 23 novembre · 18 h 00 » */
function date_fr(string $datetime): string
{
    $jours = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    $mois = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet',
        'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    $date = new DateTimeImmutable($datetime);

    return sprintf(
        '%s %d %s · %s',
        $jours[(int) $date->format('w')],
        (int) $date->format('j'),
        $mois[(int) $date->format('n') - 1],
        $date->format('H \h i')
    );
}

/** Message d'erreur sous un champ de formulaire (chaîne vide s'il n'y en a pas). */
function erreur_champ(array $erreurs, string $champ): string
{
    return isset($erreurs[$champ]) ? '<p class="field-error">' . e($erreurs[$champ]) . '</p>' : '';
}

/**
 * Bandeau d'erreurs en haut d'un formulaire : les erreurs liées à un champ s'affichent
 * sous ce champ ($champs), les autres ici.
 *
 * @param list<string> $champs
 */
function bandeau_erreurs(array $erreurs, array $champs, string $titre = 'Le formulaire contient des erreurs.'): string
{
    if ($erreurs === []) {
        return '';
    }

    $html = '<div class="alert alert-error" role="alert"><p>' . e($titre) . '</p>';
    foreach (array_diff_key($erreurs, array_flip($champs)) as $message) {
        $html .= '<p>' . e($message) . '</p>';
    }

    return $html . '</div>';
}

/** « EN_COURS » → « En cours » */
function libelle_statut(string $statut): string
{
    return [
        'PREVUE' => 'Prévue',
        'PRETE' => 'Prête',
        'EN_COURS' => 'En cours',
        'INTERROMPUE' => 'Interrompue',
        'TERMINEE' => 'Terminée',
        'ANNULEE' => 'Annulée',
    ][$statut] ?? $statut;
}

/** « 2026-11-23 19:00:00 » → « 19 h 00 » */
function heure_fr(string $datetime): string
{
    return (new DateTimeImmutable($datetime))->format('H \h i');
}

/**
 * Cellule « game master » d'une liste de sessions : nom, ou « personnel manquant » (EV-TEV-01)
 * tant que la session n'a pas commencé.
 */
function cellule_game_master(array $session): string
{
    if ($session['gm_nom'] !== null) {
        return e($session['gm_prenom'] . ' ' . $session['gm_nom']);
    }

    return in_array($session['statut'], ['PREVUE', 'PRETE'], true)
        ? '<span class="badge badge-statut-annulee">personnel manquant</span>'
        : '<span class="empty">—</span>';
}

/**
 * Cellule « groupe » d'une liste de sessions : groupe confirmé, sinon option en cours (E1), sinon $vide.
 * Attend « option_active », calculé par SessionService à l'heure de la requête.
 */
function cellule_groupe(array $session, string $vide): string
{
    if ($session['groupe'] !== null) {
        return e($session['groupe']);
    }

    if (!empty($session['option_active'])) {
        return e($session['option_groupe']) . ' <span class="badge badge-en_option">option jusqu\'à '
            . e(heure_fr((string) $session['option_expire_le'])) . '</span>';
    }

    return '<span class="empty">' . e($vide) . '</span>';
}

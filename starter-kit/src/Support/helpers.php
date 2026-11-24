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

/** « 2026-11-23 19:00:00 » → « 19 h 00 » */
function heure_fr(string $datetime): string
{
    return (new DateTimeImmutable($datetime))->format('H \h i');
}

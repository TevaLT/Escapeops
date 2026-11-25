# Réservation de la personnalisation

**Étudiant :**
**Projet :** EscapeOps
**Date :** lundi 23 novembre 2026
**Validation enseignant :**

## 3 règles contractuelles à livrer

| ID | Famille | Difficulté | Entités concernées | Démo / cas de refus |
|---|---|---|---|---|
| E1 — Une option pendant quinze minutes | Réservation / temps | Intermédiaire | `reservation` (statut, date_expiration), `session` | Le groupe A pose une option et le groupe B est refusé sur le même créneau. 15 minutes plus tard, l'option a expiré : la confirmation de A est refusée et B peut réserver. |
| E5 — Un game master ne peut pas se dédoubler | Personnel / temps | Intermédiaire | `session`, `utilisateur` (game master), `salle` | Camille anime la salle 1 à 18 h. L'affecter à la salle 3 à 18 h 30 est refusé, alors qu'un créneau jointif est accepté. Démarrer une session sans game master est refusé. |
| E10 — Checklist avant ouverture | Qualité / workflow | Intermédiaire | `checklist`, `session`, `utilisateur` | Avec une checklist 2/3, la session reste « non prête ». Avec 3/3, elle passe à `PRETE`. Un POST forgé est également refusé par le serveur. |

**Triplet trié (sans ordre) :** `E1 + E5 + E10`

## 3 extensions réservées

| Priorité | ID | Intérêt pour le client | Quand l'envisager ? |
|---:|---|---|---|
| 1 | E2 — Mécanisme critique hors service | Ne pas vendre une partie qui ne pourra pas avoir lieu, et savoir quels groupes recontacter | Après le socle et les 3 contractuelles |
| 2 | E6 — Partie interrompue | Sécurité des joueurs et traçabilité de la reprise (s'appuie sur le trigger d'historique) | Si le temps le permet |
| 3 | E9 — Maintenance après dix parties | Anticiper l'usure des mécanismes (s'appuie sur la procédure `sp_bilan_salle`) | Piste d'évolution |

## Mon scénario de démonstration en une phrase

> « Vendredi, je montrerai qu'un client qui pose une option bloque le créneau pendant 15 minutes, que le responsable ne peut pas affecter un game master déjà occupé, et qu'un game master ne peut ouvrir la salle qu'après avoir coché les 3 points de la checklist. L'application refuse chaque action interdite en expliquant pourquoi. »

## Mon risque technique le plus important

> **E1 : la gestion du temps et de la concurrence.**
> - Deux groupes peuvent poser une option au même instant. Je placerai la vérification et l'insertion dans une même transaction, avec `SELECT … FOR UPDATE`.
> - Les 15 minutes doivent être testables sans attendre. J'utilise pour cela une horloge injectable (`Clock` / `FixedClock`), déjà en place.
> - PHP et MySQL doivent avoir le même fuseau horaire : `APP_TIMEZONE` est appliqué aux deux.

## Vérifications avant validation

- [ ] même triplet interdit pour les étudiants ayant le même sujet *(à vérifier avec l'enseignant)* ;
- [x] deux familles différentes ou plus : réservation/temps, personnel/temps, qualité/workflow ;
- [x] au moins une règle multi-entités : E5 (session, game master, salle) ;
- [x] au moins une règle intermédiaire ou avancée : les trois sont intermédiaires ;
- [x] trois règles réellement démontrables, et non trois champs `required`.

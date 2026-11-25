# EV-TEV-01 — Une checklist ne remplace pas un game master

**Ticket :** issue GitHub #8 · communiqué le mercredi 25 novembre 2026
**Nature :** évolution croisée E10 (checklist avant ouverture) + E5 (game master)
**Analyse rédigée avant toute modification du code.**

## 1. Besoin reformulé

« Prête » doit vouloir dire : la salle est préparée **et** un animateur est réellement là.

- Une session ne passe à `PRETE` que si **(1)** sa dernière checklist est 3/3 **et (2)** un game master admissible
  est affecté (compte `GAME_MASTER`, sans autre session qui chevauche — règle E5).
- Si une session `PRETE` perd son unique game master **avant de commencer**, elle cesse d'être prête.
- Aucune réservation client n'est supprimée.
- Une session déjà `EN_COURS` n'est jamais modifiée automatiquement.

## 2. Situation actuelle (constat)

| Point | Avant l'évolution |
|---|---|
| Passage à `PRETE` par la checklist | Seul le game master affecté peut remplir la checklist : un GM est donc présent **au moment** du passage |
| Passage à `PRETE` par `changeStatus` (POST forgé, futur appel API) | Vérifie la checklist 3/3, **mais pas la présence du game master** |
| Retrait du game master d'une session `PRETE` (fiche session, `assignGameMaster`) | Autorisé, et **la session reste `PRETE` sans animateur** : c'est exactement le cas signalé par la cliente |
| Suppression d'un compte game master | La clé étrangère `ON DELETE SET NULL` aurait le même effet. Aucun écran ne supprime de compte aujourd'hui : risque noté, pas traité ici |
| Démarrage (`EN_COURS`) sans game master | Déjà refusé (E5, US-37) |

## 3. Composants concernés

| Couche | Fichier | Changement |
|---|---|---|
| Service | `SessionService::changeStatus` | `PRETE` exige aussi un game master affecté, admissible et sans conflit horaire |
| Service | `SessionService::assignGameMaster` | Retirer le game master d'une session `PRETE` la repasse à `PREVUE` (« à préparer ») |
| Repository | `SessionRepository::assignGameMaster` | Écrit le game master et le nouvel état dans **un seul `UPDATE`**, uniquement si l'état n'a pas changé entre-temps |
| Vue | `espace/sessions/show.php` | Bandeau « personnel manquant » sur une session sans game master |
| Contrôleur | `SessionController::assignGameMaster` | Message qui explique le retour à « Prévue » |
| Tests | `tests/evolution-ev-tev-01.php` (nouveau), `tests/game-master-conflicts.php` | Les 4 critères d'acceptation + non-régression E1/E5/E10 |
| BDD / migration | — | **Aucune** : l'état reste dans `session.statut` |
| API | — | Pas encore de route d'écriture sur les sessions ; la future API passera par le même service |

## 4. Décision : état stocké ou calculé ? (ADR-001)

**Contexte.** Une session `PRETE` qui perd son game master doit cesser d'être prête. Deux façons de l'obtenir.

**Options envisagées.**
1. **État calculé** : laisser `PRETE` en base et afficher « personnel manquant » quand `id_game_master` est vide.
2. **État stocké** : au moment du retrait, repasser `session.statut` à `PREVUE`.

**Décision : option 2, état stocké.**

**Raisons.**
- Une seule source de vérité : le planning, les filtres par état (US-29), le tableau de bord, la future API (US-51)
  et les vues SQL de jeudi (`v_planning_detaille`, `v_dashboard_exploitation`) lisent `statut` sans rien recalculer.
  Avec l'option 1, chacun de ces lecteurs devrait refaire le calcul, et un oubli suffirait à réafficher « Prête ».
- Le changement d'état sera tracé par le trigger `trg_session_history` (US-60) : on verra `PRETE → PREVUE`.
- Le retour à `PREVUE` réutilise un parcours existant : le nouveau game master affecté retrouve le formulaire de
  checklist et revalide la salle.

**Conséquences.**
- Positives : aucune migration ; règle concentrée dans `SessionService` ; impossible d'avoir `PRETE` sans game master
  par l'application.
- Compromis : `PRETE → PREVUE` n'est pas une transition proposée à l'utilisateur (pas de bouton) : c'est un effet du
  retrait du game master, écrit par le service. Le **remplacement** d'un game master par un autre (A → B) garde la
  session `PRETE` : il y a toujours un animateur, et l'affectation de B est contrôlée par E5.

## 5. Cas de recette

| # | Situation | Attendu |
|---|---|---|
| 1 | Checklist 3/3, aucun game master, POST `etat=PRETE` | Refus 422 « aucun game master affecté », reste `PREVUE` |
| 2 | Checklist 3/3, game master affecté sans conflit | Passage à `PRETE` |
| 2 bis | Checklist 3/3, game master affecté mais déjà pris sur un créneau qui chevauche (donnée incohérente) | Refus 422, reste `PREVUE` |
| 3 | Session `PRETE`, le responsable retire son game master avant le début | Repasse à `PREVUE`, bandeau « personnel manquant », réservation confirmée intacte |
| 3 bis | Session `PRETE`, game master remplacé par un autre disponible | Reste `PRETE` |
| 4 | Session `EN_COURS` | Affectation refusée, état inchangé |
| — | Non-régression | E1, E5, E10 : toute la suite `composer test` reste verte |

## 6. Estimation

≈ 1 h 30 : 15 min d'analyse, 45 min de code et de tests, 30 min de recette et de documentation.

## 7. Risques de régression

- Tests US-27 / E5 qui retiraient un game master d'une session prête puis tentaient de la démarrer : le scénario change
  (la session repasse à `PREVUE`). À réécrire, pas à supprimer.
- Démonstration E5 « démarrer sans game master » : une session `PRETE` sans game master n'existe plus par l'interface.
  Le refus reste démontrable par un POST forgé `etat=EN_COURS`, qui doit renvoyer le message E5 (contrôle du game master
  placé avant celui du cycle d'états).
- Concurrence : retrait du game master pendant que la partie démarre. Parade : `UPDATE ... WHERE statut = <état lu>`.
- Réservations : ne pas les toucher (pas de « à recontacter », la session n'est pas annulée).

## 8. Version réduite (si retard)

Contrôler seulement le game master au passage à `PRETE` ; documenter le retour automatique comme extension.
**Non retenue** : le temps le permet.

## 9. Réalisation (mercredi 25 novembre)

| Livrable | Où |
|---|---|
| Analyse d'impact E5/E10 | ce document (§ 1 à 7), rédigé avant le code |
| Décision état stocké / calculé | § 4 (ADR-001 : état stocké) |
| Code | `SessionService::changeStatus` (contrôle `staffingErrors` au passage à `PRETE`), `SessionService::assignGameMaster` (retour à `PREVUE`), `SessionRepository::assignGameMaster` (un seul `UPDATE ... WHERE statut = <état lu>`), bandeau « personnel manquant » sur la fiche session |
| Tests | `tests/evolution-ev-tev-01.php` (4 critères + cas 2 bis et 3 bis), `tests/game-master-conflicts.php` mis à jour ; `composer test` : 299 vérifications vertes, E1/E5/E10 inchangés |

### Scénario de démonstration avant / après

Après `composer db:reset`, connecté en responsable, fiche de la **session 7** (salle 3, demain 14 h, `PRETE`, David, checklist 3/3) :

| Étape | Avant l'évolution | Après l'évolution |
|---|---|---|
| Retirer David (« — Aucun — » → Enregistrer) | La session reste **Prête** sans animateur | Message « la session n'est plus prête et repasse à Prévue (personnel manquant) », badge **Prévue**, bandeau « Personnel manquant » |
| POST forgé `etat=PRETE` (la checklist 3/3 existe toujours) | Accepté | **422** « Salle non prête : aucun game master affecté. Une checklist ne remplace pas un animateur. » |
| POST forgé `etat=EN_COURS` | 422 (E5) | **422** « aucun game master affecté » (inchangé) |
| Réaffecter David, qui revalide la checklist 3/3 (compte david@) | — | Session de nouveau **Prête** |
| Réservations de la session | — | Intactes (aucune suppression, pas de « à recontacter ») |

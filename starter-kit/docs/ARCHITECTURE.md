# Architecture du starter

## Vue d'ensemble

```text
public/index.php
      │
      ├── charge .env + settings
      ├── crée Slim
      ├── crée Medoo
      ├── câble Repository / Service / Controller
      │
      └── charge les routes
              │
              ▼
          Controller
              │
              ▼
            Service
              │
              ▼
          Repository
              │
              ▼
             Medoo
              │
              ▼
              SQL
```

## Pourquoi un Service ?

Le repository sait **lire et écrire les données**.

Le service sait **appliquer les règles métier**.

Exemple :
- Repository : « donne-moi les réservations qui se chevauchent ».
- Service : « si une réservation se chevauche, refuser la nouvelle réservation ».

Ne mettez pas vos règles importantes directement dans les routes ou les vues.

## Pourquoi un Repository ?

Le contrôleur ne doit pas connaître chaque détail de requête SQL/Medoo.

Cela rend :
- la lecture du code plus facile ;
- la logique métier plus claire ;
- les tests plus simples ;
- le changement d'accès aux données moins invasif.

## Pourquoi des fichiers de routes séparés ?

On distingue ici :
- `routes/web.php` : HTML / navigation utilisateur ;
- `routes/api.php` : JSON / échanges applicatifs.

Ce n'est pas obligatoire dans Slim, mais c'est lisible et adapté au projet.

## Sécurité ajoutée (épic 1)

```text
requête → SessionMiddleware → lecture du corps → CsrfMiddleware → routage → RequireRole → contrôleur → service (Authorization)
```

Le middleware filtre l'accès aux pages ; le service revérifie le droit avant d'écrire (une règle ne dépend jamais d'un lien caché dans une vue).

## Socle métier (épic 2)

Chaque fonctionnalité suit le même chemin, de la base de données à la page :

| Couche | Rôle | Exemples |
|---|---|---|
| `src/Repository` | Uniquement des requêtes SQL (Medoo ou PDO préparé), aucune règle | `SessionRepository::search`, `findRoomConflict`, `findGameMasterConflict`, `bookingInfo`, `ChecklistRepository` |
| `src/Domain` | Règles pures, testables sans base | `SessionSchedule` (horaires, chevauchement E5), `SessionStatus` (cycle d'états), `SlotAvailability` (réservable ?), `ReservationOption` (E1 : échéance et état de l'option), `OpeningChecklist` (E10 : 3/3), `Field` (lecture stricte des champs), `Authorization` (droits) |
| `src/Service` | Applique les règles : **droit d'abord**, puis validation, puis écriture | `SessionService`, `ReservationService`, `RoomService`, `EquipmentService`, `IncidentService`, `GameMasterService`, `DashboardService` |
| `src/Controller` | Lit le formulaire, appelle le service, traduit le résultat en code HTTP | 303 succès · 422 données invalides · 409 conflit · 403 sans droit · 404 introuvable |
| `templates` | Affichage, tout passe par `e()` | `bandeau_erreurs()` et `erreur_champ()` pour les formulaires |

Les erreurs métier sont des exceptions du domaine : `ValidationException` (erreurs par champ → 422),
`SlotUnavailableException` (créneau pris ou en option → 409), `ReservationStateException` (option expirée,
déjà confirmée ou annulée → 409), `AccessDeniedException` (→ 403), `NotFoundException` (→ 404).

**Transaction de réservation (E1)** : `ReservationService::placeOption` lit la session avec `SELECT ... FOR UPDATE`,
vérifie sa disponibilité et enregistre l'option `EN_ATTENTE` (échéance = maintenant + 15 min, jeton secret)
dans une seule transaction. Deux groupes qui valident à la même seconde passent l'un après l'autre : le second
voit l'option du premier. `confirm` et `cancel` verrouillent la même ligne de session avant de relire la réservation.
L'expiration n'est jamais écrite par une tâche planifiée : `ReservationOption::state` la recalcule à chaque requête
(option active tant que `maintenant < date_expiration`).

**Où sont branchées E5 et E10** : dans `SessionService`. `validate` (création, modification) et `assignGameMaster`
(fiche) appellent `findGameMasterConflict` ; `changeStatus` refuse `EN_COURS` sans game master et `PRETE` sans
dernière checklist 3/3 ; `submitChecklist` enregistre toujours la checklist, puis passe la session à `PRETE` via
`changeStatus` si elle est complète. Le bouton « Marquer prête » n'existe plus : on y arrive par la checklist.

**Évolution EV-TEV-01** ([docs/evolutions/EV-TEV-01.md](evolutions/EV-TEV-01.md)) : `PRETE` exige aussi un game master
affecté, admissible et sans conflit (`staffingErrors`). Retirer l'unique game master d'une session `PRETE` la repasse
à `PREVUE` : l'état est **stocké** dans `session.statut` (pas recalculé à l'affichage), écrit avec le game master dans
un seul `UPDATE ... WHERE statut = <état lu>` pour ne jamais toucher une partie qui vient de démarrer.

**Tests** : `tests/support/bootstrap.php` crée une base SQLite en mémoire au schéma équivalent à `001_schema.sql`.
Les vrais services et repositories tournent dessus, sans MySQL ; l'horloge `FixedClock` fixe l'heure.

**Vues SQL à venir (jeudi)** : la jointure de `SessionRepository::search` deviendra `v_planning_detaille`,
celle de `DashboardRepository::roomStatus` deviendra `v_dashboard_exploitation`.

## Ce qui manque volontairement

- logs applicatifs ;
- pagination.

Vous devez les ajouter selon votre projet.

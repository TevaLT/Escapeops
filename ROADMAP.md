# Roadmap — EscapeOps

**Semaine :** du lundi 23 au vendredi 27 novembre 2026 · **Mise à jour :** mercredi 25/11, midi
**Détail des tickets :** [BACKLOG.md](BACKLOG.md) · **Suivi au jour le jour :** [docs/journal.md](docs/journal.md)

> Principe : un produit **testable et poussé chaque soir**. On ne commence pas une étape tant que la précédente n'est pas verte.
> Ordre de sacrifice si on manque de temps : bonus → extensions → US-55 → rien d'autre (E1/E5/E10, API, SQL avancé, incident et livraison sont obligatoires).

---

## Où en est-on

| Étape | Contenu | État |
|---|---|---|
| Lundi | Épic 0 — socle, MCD, schéma, seed, premier parcours | ✅ |
| Mardi | Épics 1-2 — authentification, rôles, CSRF, socle métier, tableau de bord | ✅ |
| Mercredi matin | Épic 3 — règles E1, E5, E10 + tests | ✅ 🟢 |
| Mercredi | Épic 6 — évolution client EV-TEV-01 (US-70, US-71) | ✅ |
| Hors backlog | Création de comptes GM par le responsable · « Mes réservations » (code de suivi + historique) | ✅ |
| | `composer test` | 358 vérifications vertes |

⚠️ **Dette immédiate :** tout le travail depuis le commit `b9af613` (évolution, comptes GM, accès client) n'est **pas encore commité**. Il faut le commiter avant d'attaquer l'API, sous forme de commits séparés qui citent les ID des tickets.

---

## Jalon 1 — Mercredi après-midi : API JSON (Épic 4)

| # | Tâche | Ticket |
|---|---|---|
| 1 | Commiter le travail en attente (évolution / comptes GM / accès client) | — |
| 2 | `GET /api/sessions?roomId=` avec l'état et la disponibilité (E1 calculée) | US-51 |
| 3 | `POST /api/reservations` → réutilise `ReservationService::placeOption` · 201 / 409 / 422 | US-52, US-54 |
| 4 | `POST /api/incidents` · 201 / 422 / 401 / 403 | US-53, US-54 |
| 5 | Contrat API dans le README : exemples de requêtes et d'erreurs | US-55 (S) |
| 6 | Mettre à jour README, BACKLOG, CSV Notion et journal, puis pousser | — |

**✅ Jalon atteint si :** les 3 règles + 1 collection, 1 détail et 1 écriture en API, sans aucune règle dupliquée dans le contrôleur API.

---

## Jalon 2 — Jeudi matin : SQL avancé (Épic 5)

| # | Tâche | Ticket |
|---|---|---|
| 1 | `002_vues.sql` : `v_planning_detaille` (planning + `GET /api/sessions`) et `v_dashboard_exploitation` (tableau de bord) | US-62, US-63 |
| 2 | `003_procedures.sql` : `sp_bilan_salle(room_id)`, appelée dans `RoomRepository` et affichée sur la fiche salle | US-61 |
| 3 | `004_triggers.sql` : `trg_session_history` (AFTER UPDATE, rien si le statut ne change pas) | US-60 |
| 4 | Reconstruire sur une **base vierge** avec le client `mysql` (pas PDO, à cause de `DELIMITER`), ordre écrit dans le README | US-64 |
| 5 | Preuves : trigger avant/après, `CALL`, extrait du repository, page qui dépend de chaque vue | US-65 |

**✅ Jalon atteint si :** la base se reconstruit de zéro et chaque objet SQL est utilisé par une vraie page.

---

## Jalon 3 — Jeudi après-midi : incident + première extension

| # | Tâche | Ticket |
|---|---|---|
| 1 | Incident imposé : reproduire → diagnostiquer → corriger → test qui prouve le correctif → `templates/RAPPORT_INCIDENT.md` | US-72 |
| 2 | Non-régression : `composer test` vert, E1/E5/E10 rejoués à la main | — |
| 3 | **Si tout est vert :** extension n°1 **E2** (équipement critique HS) | US-90 à 92 |
| 4 | Sinon, ou en plus : **E9**, peu coûteuse une fois `sp_bilan_salle` en place | US-96 à 98 |

**✅ Jalon atteint si :** l'évolution et l'incident sont traçables (fiche + rapport + tests) et les trois règles restent stables.

---

## Jalon 4 — Vendredi matin : gel et recette (Épic 7)

| # | Tâche | Ticket |
|---|---|---|
| 1 | **Gel du périmètre** : on n'ajoute plus rien, on corrige seulement | US-80 |
| 2 | Recette complète (`templates/RECETTE.md`) | US-80 |
| 3 | Revue sécurité : requêtes préparées, `e()` partout, CSRF, droits dans les services, pas de `.env` dans Git, limite de 5 essais sur « Mes réservations » | US-81 |
| 4 | `mysqldump` puis restauration testée | US-82 |

## Jalon 5 — Vendredi après-midi : livraison

| # | Tâche | Ticket |
|---|---|---|
| 1 | README final : installation, comptes de test, ordre des migrations, contrat API | US-83 |
| 2 | Captures de preuve (`docs/10-preuves-bts.md`) | — |
| 3 | Tag `v1.0` | US-84 |
| 4 | Démo de 5 à 7 min : option E1 qui bloque le groupe B → GM affecté et conflit E5 → checklist 2/3 puis 3/3 (E10) → démarrage. Bonus : « Mes réservations » et l'API | US-85 |

**✅ Jalon atteint si :** quelqu'un d'autre peut lancer, comprendre et vérifier le projet avec le seul README.

---

## Après la v1 (si du temps reste)

1. Extensions encore non faites : E6 (partie interrompue, US-93 à 95, qui s'appuie sur le trigger US-60)
2. Pistes déjà notées dans le journal : changement de mot de passe par le game master, limitation des essais de « Mes réservations » par adresse IP
3. Bonus du backlog : planning en calendrier, écran « régie », QR code de check-in, taux d'occupation

## Points de vigilance

| Risque | Parade |
|---|---|
| Gros volume de travail non commité | Commiter avant l'API, puis à chaque ticket |
| API qui duplique les règles | Les contrôleurs API appellent les mêmes services que le HTML (US-54) |
| `DELIMITER` refusé par PDO | Migrations SQL importées avec le client `mysql` |
| Vues qui cassent les pages existantes | Brancher une vue à la fois, en gardant `composer test` vert |
| Débordement de jeudi sur vendredi | Couper l'extension en premier, jamais l'incident ni la recette |

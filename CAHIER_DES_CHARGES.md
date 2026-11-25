# Cahier des charges personnalisé — EscapeOps

**Client :** Les Archives Obscures (client fictif)
**Projet :** EscapeOps, outil de réservation et d'exploitation d'un escape game
**Étudiant :**
**Période :** du lundi 23 au vendredi 27 novembre 2026
**Règles contractuelles :** E1 + E5 + E10
**Extensions réservées :** E2, E6, E9

---

## 1. Organisation cliente

Les Archives Obscures est une petite société qui exploite **trois salles d'escape game**. Elle accueille des groupes de joueurs sur des créneaux privatisés et emploie des **game masters** qui préparent et animent les parties.

**Le problème :** aujourd'hui, le planning, les réservations et l'état des salles sont suivis par messages entre le personnel. Juste avant l'ouverture, le responsable n'a pas le temps de tout recouper. Il en résulte plusieurs difficultés :

- un client qui hésite perd son créneau, ou au contraire le bloque sans jamais confirmer ;
- un même game master peut être prévu sur deux salles à la même heure ;
- une salle peut être ouverte aux joueurs sans avoir été rangée, testée et vérifiée côté sécurité.

**Promesse du produit :** « Personne ne réserve une salle indisponible ; l'équipe sait pourquoi une session peut ou non démarrer. »

## 2. Objectifs

| # | Objectif | Indicateur mesurable |
|---|---|---|
| O1 | Permettre au public de réserver un créneau sans compte | Une réservation est enregistrée en moins de 2 minutes depuis la vitrine |
| O2 | Garantir qu'un créneau ne soit jamais réservé deux fois | 0 réservation confirmée en double par session (contrôle serveur et contrainte en base) |
| O3 | Laisser un temps de réflexion au client sans bloquer indéfiniment le créneau | Une option est automatiquement libérée 15 minutes après sa pose |
| O4 | Éviter tout conflit d'affectation des game masters | 0 game master affecté à deux sessions qui se chevauchent |
| O5 | Interdire l'ouverture d'une salle non préparée | 100 % des sessions passées à `PRETE` ont une checklist 3/3 enregistrée |
| O6 | Donner au responsable une vue d'ensemble de l'exploitation | Le tableau de bord affiche les prochaines sessions, les incidents ouverts et la disponibilité des salles |

## 3. Acteurs

| Acteur | Accès | Capacités principales |
|---|---|---|
| **Client (public)** | Sans compte, avec un contact fictif | Consulter les salles et créneaux, poser une option, confirmer ou annuler sa réservation, retrouver ses réservations (« Mes réservations » : contact + code de suivi, sans mot de passe) |
| **Game master** | Compte, rôle `GAME_MASTER` | Consulter ses sessions, remplir la checklist d'ouverture, démarrer et terminer une session, déclarer un incident |
| **Responsable** | Compte, rôle `RESPONSABLE` | Créer et modifier les sessions, affecter les game masters, gérer les réservations et incidents, consulter le tableau de bord |
| **Administrateur** | Compte, rôle `ADMIN` | Gérer les comptes, les salles et les équipements, avec en plus tous les droits du responsable |

Les droits sont **vérifiés côté serveur** pour chaque action. Masquer un bouton dans l'interface ne suffit pas.

## 4. Périmètre fonctionnel

### Must have

- **Vitrine publique :** les salles (thème, durée, capacité) et leurs créneaux publiés et réservables.
- **Authentification :** connexion et déconnexion, trois rôles distincts, protection CSRF des formulaires.
- **Gestion des salles :** durée, capacité minimale et maximale, temps de remise en état.
- **Gestion des sessions :** création, modification, affectation d'un game master, cycle d'états `PREVUE → PRETE → EN_COURS → TERMINEE`, ou `ANNULEE`.
- **Réservations de groupes :** une session accepte **au plus une réservation confirmée**. États `EN_ATTENTE` (option), `CONFIRMEE` et `ANNULEE`.
- **Fiches game master.**
- **Équipements liés aux salles** par une relation N–N (`room_equipment`), avec un indicateur « critique ».
- **Incidents :** déclaration, suivi et résolution.
- **Tableau de bord** du responsable : prochaines sessions, incidents ouverts, disponibilité.
- **Recherche et filtres** : sessions par salle, date et état, avec un message « aucun résultat ».
- **API JSON** :
  - `GET /api/rooms`
  - `GET /api/rooms/{id}`
  - `GET /api/sessions?roomId=`
  - `POST /api/reservations`
  - `POST /api/incidents`
- **Clause SQL avancé :**
  - 1 trigger : historique des changements d'état des sessions ;
  - 1 procédure stockée, appelée depuis un repository : bilan par salle ;
  - 2 vues : le planning détaillé et le tableau de bord d'exploitation.
- **Invariants du socle :**
  - la fin d'une session est postérieure à son début ;
  - on ne peut pas réserver une session supprimée, passée ou déjà confirmée ;
  - aucune modification sans le droit correspondant.

### Should have

- Contrat d'API documenté (README ou OpenAPI), avec les cas d'erreur.
- Historique consultable des checklists et des transitions de session.
- Interface immersive, adaptée au thème des Archives Obscures.

### Hors périmètre

- Paiement en ligne, facturation, gestion des acomptes.
- Billetterie multi-groupes : une session correspond à un seul groupe.
- Envoi de SMS ou d'e-mails réels.
- Serrures connectées et tout pilotage matériel.
- Création des énigmes, moteur de jeu.
- Tâches planifiées (cron) : les délais sont vérifiés à chaque requête.

## 5. Règles contractuelles

### E1 — Une option pendant quinze minutes

**Scénario :** *« Je suis un client. Je veux réserver une salle mais j'hésite encore. Comme j'ai posé une option de réservation, l'application doit garder mon créneau réservé pendant 15 minutes. »*

Quand un client choisit un créneau libre, il peut poser une **option gratuite** (`EN_ATTENTE`) valable 15 minutes. Pendant ce temps, le créneau est indisponible pour les autres groupes. Une fois le délai écoulé, l'option ne bloque plus rien et le créneau redevient réservable. Si le client tente de confirmer après l'expiration, la confirmation est refusée. Le délai est contrôlé à chaque requête HTTP, sans tâche planifiée.

- **Succès :** le groupe A pose une option, puis la confirme dans le délai.
- **Refus :** le groupe B demande le même créneau pendant l'option de A. De même, A tente de confirmer après 15 minutes.
- **Entités :** `reservation` (`status`, `expires_at`), `session`.

### E5 — Un game master ne peut pas se dédoubler

**Scénario :** *« Je suis le gérant des Archives Obscures. Je veux planifier les sessions. Comme un game master ne peut pas être affecté à deux sessions sur le même créneau, l'application doit bloquer l'affectation d'un game master sur un créneau qui chevauche, et permettre de créer une session sans affectation. »*

Une session peut être planifiée sans game master. En revanche, on ne peut pas affecter un game master à une session dont l'horaire chevauche une autre session qui lui est déjà attribuée. Deux sessions qui se suivent sans se chevaucher (18 h–19 h puis 19 h–20 h) restent autorisées. Une session ne peut pas démarrer sans game master affecté.

- **Succès :** affectation sur des créneaux distincts ou jointifs.
- **Refus :** même game master demandé sur deux salles à 18 h. De même, une session sans game master qu'on tente de démarrer.
- **Entités :** `session`, `game_master` (utilisateur), `room`.

### E10 — Checklist avant ouverture

**Scénario :** *« Je suis un game master. Je veux valider l'ouverture d'une salle. Comme je dois valider une checklist, l'application doit empêcher l'ouverture d'une salle avant que la checklist soit entièrement validée. »*

Avant qu'une session passe à l'état `PRETE`, le game master remplit trois points : **salle rangée, matériel testé, consignes de sécurité vérifiées**. Le nom du game master, la date et les trois valeurs sont enregistrés. Le passage à `PRETE` n'est possible qu'avec les trois points validés. Ce contrôle est fait par le serveur, donc une requête forgée est également refusée.

- **Succès :** checklist 3/3, la session devient `PRETE`.
- **Refus :** checklist 2/3, la session reste `PREVUE` avec le message « salle non prête ».
- **Entités :** `checklist`, `session`, utilisateur game master.

Ces trois règles relèvent de familles différentes (réservation et temps, personnel et temps, qualité et workflow). Elles touchent plusieurs entités et sont de difficulté intermédiaire.

## 6. Extensions

Les extensions ne sont réalisées **qu'une fois le socle et les trois règles contractuelles stabilisés et testés.**

| Priorité | Règle | Description | Intérêt pour le client |
|---:|---|---|---|
| 1 | **E2 — Mécanisme critique hors service** | Un incident bloquant sur un équipement critique interdit les nouvelles réservations de la salle. Les réservations déjà confirmées passent « à recontacter » sans être supprimées. | Ne pas vendre une partie qui ne pourra pas avoir lieu |
| 2 | **E6 — Partie interrompue** | Un incident critique déclaré pendant une session `EN_COURS` la passe à `INTERROMPUE`. Seul le responsable peut la reprendre, et chaque action laisse une trace. | Sécurité des joueurs et traçabilité |
| 3 | **E9 — Maintenance après dix parties** | Une salle affiche `CONTROLE_A_PREVOIR` après 10 sessions terminées depuis son dernier contrôle. Enregistrer un contrôle remet le compteur à zéro, et les sessions annulées ne sont pas comptées. | Anticiper l'usure des mécanismes |

Le socle SQL est conçu pour accueillir ces extensions : le trigger d'historique sert de trace pour E6, la procédure de bilan par salle calcule le compteur de E9, et la vue du tableau de bord liste les réservations à recontacter de E2.

## 7. Données

### Entités principales

| Entité | Attributs clés |
|---|---|
| `user` | id, nom, e-mail, mot de passe haché, rôle (`ADMIN` / `RESPONSABLE` / `GAME_MASTER`) |
| `room` | id, nom, thème, durée, capacité min et max, temps de remise en état |
| `session` | id, room_id, game_master_id (peut être vide), début, fin, état |
| `reservation` | id, session_id, nom et contact du groupe, nombre de joueurs, état, `expires_at`, date de création, indicateur « à recontacter », jeton du lien de suivi (confirmer ou annuler sans compte), code de suivi (« Mes réservations ») |
| `equipment` | id, nom, critique (booléen), statut |
| `room_equipment` | room_id, equipment_id (table d'association N–N) |
| `incident` | id, room_id, equipment_id, description, bloquant, statut, dates de déclaration et de résolution |
| `checklist` | id, session_id, user_id, salle_rangee, materiel_teste, securite_verifiee, date |
| `session_history` | id, session_id, ancien état, nouvel état, date (alimentée par trigger) |
| `room_control` | id, room_id, user_id, date (pour E9) |

### Relations

- Une **salle** a plusieurs **sessions**, et une session appartient à une seule salle.
- Une **session** a **au plus une** réservation confirmée, et zéro ou un **game master**.
- **Salle ↔ équipement** est une relation N–N via `room_equipment`.
- Un **incident** concerne une salle et, éventuellement, un équipement.
- Une **session** a zéro ou plusieurs **checklists** et plusieurs lignes d'**historique**.

### Objets SQL avancés

| Objet | Rôle | Utilisé par |
|---|---|---|
| `trg_session_history` (AFTER UPDATE) | Enregistre chaque changement d'état d'une session | Fiche session, traçabilité E6 |
| `sp_bilan_salle(room_id)` | Sessions terminées, annulées et interrompues, et sessions depuis le dernier contrôle | `RoomRepository`, fiche salle, E9 |
| `v_planning_detaille` | Sessions avec salle, game master et réservation | Page planning, `GET /api/sessions` |
| `v_dashboard_exploitation` | Prochaines sessions, incidents ouverts, salles bloquées | Tableau de bord |

### Jeu de données de test

- 3 salles, 2 game masters, 1 responsable, 1 administrateur.
- 8 sessions, dont 2 dans la même salle et 2 qui se chevauchent dans des salles différentes (pour démontrer E5).
- 4 réservations, dont 1 option `EN_ATTENTE`.
- 5 équipements, dont 1 critique.
- 2 incidents.

## 8. Contraintes

- **Stack :** PHP 8.2+, Slim 4, Medoo/PDO, MySQL ou MariaDB, Composer (PSR-4), HTML/CSS, architecture MVC avec services et repositories. Pas de framework JavaScript.
- **Sécurité :**
  - mots de passe hachés avec `password_hash` ;
  - sessions PHP régénérées à la connexion ;
  - jetons CSRF ;
  - requêtes préparées ;
  - échappement des sorties (XSS) ;
  - droits contrôlés côté serveur ;
  - aucun secret dans Git (`.env` ignoré) ;
  - transaction avec verrou lors de la pose d'une option, pour éviter les doubles réservations.
- **Délais :** une semaine, du 23 au 27 novembre 2026, avec un jalon démontrable chaque soir. Le périmètre est gelé vendredi matin.
- **Déploiement :** lancement local documenté dans le README. Les scripts SQL sont numérotés dans `database/migrations/` et reconstructibles sur une base vierge. La sauvegarde et la restauration de la base sont testées. La version livrée porte le tag Git `v1.0`.
- **Tests :**
  - au moins un test de succès et un test de refus par règle contractuelle ;
  - des tests de l'API (codes 200, 201, 404, 409, 422) ;
  - un test de l'effet du trigger ;
  - une horloge injectable pour tester les délais (E1, E5) sans attendre.

## 9. Critères d'acceptation

Le client valide le produit en déroulant le **scénario de recette** suivant :

1. **Le responsable crée les sessions du jour.**
   - Une session dont la fin précède le début est refusée.
   - Il affecte un game master à 18 h en salle A, puis tente de l'affecter à 18 h en salle B : **refus (E5)**.
   - L'affectation sur un créneau jointif est acceptée.
   - Une session peut rester sans game master.
2. **Le public réserve un créneau.**
   - Le groupe A pose une option.
   - Le groupe B demande le même créneau : **refus (E1)**.
   - Après 15 minutes simulées, la confirmation de A est refusée et B peut réserver.
3. **Le game master prépare la salle.**
   - Avec une checklist 2/3, le passage à `PRETE` est **refusé (E10)**.
   - Avec une checklist 3/3, la session passe à `PRETE`.
   - Démarrer une session sans game master est **refusé (E5)**.
4. **Le tableau de bord** reflète l'état réel : prochaines sessions, incidents ouverts et disponibilités.
5. **L'API** renvoie les mêmes décisions que le site : 409 pour un créneau en option, 404 pour un identifiant inconnu, 403 sans droit.
6. **La traçabilité** est vérifiable : l'historique des états de session (trigger) et les checklists enregistrées apparaissent dans l'application.
7. **L'installation est reproductible :** une autre personne installe le projet sur une base vierge en suivant le README.

Le produit est accepté si toutes les étapes se déroulent sans erreur et si les tests automatisés passent.

## 10. Risques

| Risque | Impact | Réponse prévue |
|---|---|---|
| Deux clients posent une option sur le même créneau au même instant (E1) | Double réservation | Vérification et insertion dans une même transaction avec `SELECT … FOR UPDATE`, plus une contrainte en base |
| Les délais (15 minutes, chevauchements) sont difficiles à tester | Règles non prouvées | Service d'horloge injectable pour simuler l'heure dans les tests et la démonstration |
| Erreur de bornes sur les créneaux jointifs (E5) | Refus injustifié ou conflit accepté | Comparaison stricte `a.début < b.fin AND b.début < a.fin`, avec un test dédié au cas jointif |
| Le contrôle de la checklist n'est fait que dans le formulaire (E10) | Contournement par une requête forgée | Contrôle dans le service métier, avec un test qui envoie un POST incomplet |
| Les scripts SQL avec `DELIMITER` échouent via PDO | Base impossible à reconstruire | Import des migrations avec le client `mysql`, reconstruction testée sur une base vierge |
| Le temps est consommé par les extensions ou le visuel | Règles contractuelles inachevées | Ordre strict du backlog : socle, puis E1, E5, E10, puis API et SQL, et seulement ensuite les extensions |

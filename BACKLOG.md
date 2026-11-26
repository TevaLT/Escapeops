   # Backlog — EscapeOps

**Règles contractuelles :** E1 (option 15 min) · E5 (game master unique) · E10 (checklist avant ouverture)
**Extensions réservées :** 1. E2 (équipement critique HS) · 2. E6 (partie interrompue) · 3. E9 (maintenance après 10 parties)
**Semaine :** du lundi 23 au vendredi 27 novembre 2026

> Légende priorité (MoSCoW) : **M** = Must · **S** = Should · **C** = Could · **W** = Won't
> Statut : ✅ fait · 🟡 en cours · (rien) à faire

---

## Definition of Done (vaut pour chaque ticket)

- [ ] Code poussé sur Git (commit qui référence l'ID du ticket, ex. `US-12: ...`)
- [ ] Cas de succès **et** cas de refus démontrables dans le navigateur (ou l'API)
- [ ] Contrôle effectué **côté serveur** (service), pas seulement dans le formulaire
- [ ] Droits vérifiés selon le rôle
- [ ] Au moins un test écrit et passant pour une règle métier
- [ ] Scripts SQL versionnés dans `database/migrations/`

---

## Épic 0 — Socle technique · *Lundi*

| ID | Priorité | Ticket | Critères d'acceptation |
|---|---|---|---|
| ✅ US-01 | M | Installer le starter Slim + Medoo et le lancer en local | Page d'accueil affichée, `.env` configuré, procédure dans le README |
| ✅ US-02 | M | Modéliser la BDD (MCD/MLD) | Tables : `user`, `room`, `session`, `reservation`, `game_master` (ou rôle), `equipment`, `room_equipment` (N–N), `incident`, `session_history`, `checklist`, `room_control`. Statuts prévus : réservation `EN_ATTENTE` / `CONFIRMEE` / `ANNULEE` (+ `expires_at`), session `PREVUE` / `PRETE` / `EN_COURS` / `INTERROMPUE` / `TERMINEE` / `ANNULEE` · MCD : `MCD_EscapeOps.png` · MLD (jeudi 26) : `docs/MLD.md`, 10 tables en notation textuelle et en `erDiagram` |
| ✅ US-03 | M | Écrire `001_schema.sql` | Contraintes : FK, `CHECK (end_at > start_at)`, index sur `room_id` + `start_at` |
| ✅ US-04 | M | Écrire `seed.sql` | 3 salles, 2 GM, 1 responsable, 1 admin, 8 sessions (dont 2 dans la même salle **et 2 qui se chevauchent dans des salles différentes** pour E5), 4 réservations, 5 équipements (dont 1 critique), 2 incidents, 1 option `EN_ATTENTE` |
| ✅ US-05 | M | Premier parcours vertical : liste des salles issue de la BDD | Route → contrôleur → service → repository → vue |
| ✅ US-06 | M | Créer le dépôt Git et faire le premier push | Branche `main`, `.gitignore` sans `.env` |
| ✅ US-07 | S | Service `Clock` injectable (heure courante) | Permet de tester E1 et E5 sans attendre 15 minutes réelles |

**Fin lundi :** 🟢 une page affiche des salles venant de la BDD et le modèle est poussé. → ✅ **atteint** (code poussé sur GitHub mardi matin)

---

## Épic 1 — Authentification et rôles · *Mardi matin*

| ID | Priorité | Ticket | Critères d'acceptation |
|---|---|---|---|
| ✅ US-10 | M | Connexion et déconnexion | Mot de passe stocké avec `password_hash`, `session_regenerate_id` après le login |
| ✅ US-11 | M | Rôles admin, responsable et game master | Middleware qui refuse l'accès (403) à une route non autorisée |
| ✅ US-12 | M | Protection CSRF sur tous les formulaires POST | Un jeton absent ou invalide est refusé |
| ✅ US-13 | M | Invariant : seul un rôle autorisé modifie une session | Un game master qui tente de modifier le planning reçoit un refus |

---

## Épic 2 — Socle métier · *Mardi après-midi*

| ID | Priorité | Ticket | Critères d'acceptation |
|---|---|---|---|
| ✅ US-20 | M | Vitrine publique : salles (thème, durée, capacité) et créneaux publiés | Accessible sans compte, n'affiche que les sessions futures et réservables |
| ✅ US-21 | M | CRUD des salles (admin/responsable) | Durée, capacité min/max, temps de remise en état |
| ✅ US-22 | M | CRUD des sessions (responsable) | Invariant fin > début, salle obligatoire |
| ✅ US-23 | M | Réservation d'un groupe (public, contact fictif) | Invariants : refus si la session est passée, supprimée ou déjà confirmée ; 1 réservation confirmée max par session |
| ✅ US-24 | M | Fiches game master | Liste et détail |
| ✅ US-25 | M | Équipements liés à une salle (N–N) | Affecter et retirer un équipement, avec un indicateur `critique` |
| ✅ US-26 | M | Déclarer et résoudre un incident | Lié à une salle et/ou un équipement, statut ouvert/résolu |
| ✅ US-27 | M | Transitions de session `PREVUE → PRETE → EN_COURS → TERMINEE` et `ANNULEE` | Une transition interdite est refusée |
| ✅ US-28 | M | Tableau de bord du responsable | Prochaines sessions, incidents ouverts, disponibilité des salles |
| ✅ US-29 | S | Recherche et filtres | Sessions par salle, par date, par état |

**Fin mardi :** 🟢 un responsable crée une session, un client la réserve, et la session apparaît au tableau de bord. → ✅ **atteint**

---

## Épic 3 — Règles contractuelles · *Mercredi* (à faire dans cet ordre, la plus risquée d'abord)

### E1 — « Une option pendant quinze minutes »

| ID | Priorité | Ticket | Critères d'acceptation |
|---|---|---|---|
| ✅ US-30 | M | Poser une option `EN_ATTENTE` sur un créneau libre | `expires_at = now + 15 min` enregistré |
| ✅ US-31 | M | Une option active bloque les autres groupes | Le groupe B reçoit un message clair (« créneau en option jusqu'à 18 h 12 ») |
| ✅ US-32 | M | Une option expirée libère le créneau | Vérifié **à chaque requête HTTP**, sans cron |
| ✅ US-33 | M | Une confirmation après expiration est refusée | Message explicite, l'option ne passe pas en `CONFIRMEE` |
| ✅ US-34 | M | Tests E1 | Option active bloquante · option expirée libératrice · confirmation hors délai refusée (avec `Clock` simulé) |

### E5 — « Un game master ne peut pas se dédoubler »

| ID | Priorité | Ticket | Critères d'acceptation |
|---|---|---|---|
| ✅ US-35 | M | Affecter un game master à une session | Une session peut rester `PREVUE` sans GM |
| ✅ US-36 | M | Refuser l'affectation sur un créneau qui chevauche | Condition : `a.start < b.end AND b.start < a.end` ; les créneaux jointifs (18 h–19 h puis 19 h–20 h) sont acceptés |
| ✅ US-37 | M | Refuser le démarrage (`EN_COURS`) d'une session sans GM | Message « aucun game master affecté » |
| ✅ US-38 | M | Tests E5 | Conflit refusé · créneaux jointifs acceptés · démarrage sans GM refusé |

### E10 — « Checklist avant ouverture »

| ID | Priorité | Ticket | Critères d'acceptation |
|---|---|---|---|
| ✅ US-39 | M | Formulaire de checklist (GM) : salle rangée, matériel testé, consignes sécurité vérifiées | Nom du GM, date et les 3 valeurs enregistrés |
| ✅ US-40 | M | Passage en `PRETE` uniquement si 3/3 | 2/3 donne « non prête », contrôlé **côté serveur** (un POST forgé est refusé) |
| ✅ US-41 | M | Afficher la checklist sur la fiche session | Historique consultable par le responsable |
| ✅ US-42 | M | Tests E10 | Incomplète refusée · complète acceptée · contrôle serveur |

**Fin mercredi :** 🟢 les 3 règles marchent avec leurs refus. 🟠 2 règles. 🔴 moins de 2. → ✅ **🟢 atteint dès le mercredi matin** (E1, E5, E10 démontrables, 272 vérifications vertes)

---

## Épic 4 — API JSON · *Mercredi après-midi*

| ID | Priorité | Ticket | Critères d'acceptation |
|---|---|---|---|
| ✅ US-50 | M | `GET /api/rooms` et `GET /api/rooms/{id}` | 200, ou 404 JSON si l'id est inconnu |
| ✅ US-51 | M | `GET /api/sessions?roomId=1` | Filtre fonctionnel, état et disponibilité inclus |
| ✅ US-52 | M | `POST /api/reservations` (pose d'une option E1) | 201 créé · 409 si le créneau est en option ou réservé · 422 si les données sont invalides |
| ✅ US-53 | M | `POST /api/incidents` | 201 · 422 · 401/403 sans droit |
| ✅ US-54 | M | Réutiliser **les mêmes services** que le HTML | Aucune règle dupliquée dans le contrôleur API |
| ✅ US-55 | S | Contrat API documenté (README ou OpenAPI) | Exemples de requêtes et de réponses, y compris les erreurs |

**Fin épic 4 :** ✅ mercredi 25 après-midi : 5 routes JSON (`src/Controller/Api/`), contrat complet dans `starter-kit/docs/API.md`, `tests/api.php` (44 vérifications à travers la vraie application Slim). En plus du backlog : suivi et confirmation de l'option par l'API, 404 en JSON sur toute adresse inconnue.

---

## Épic 5 — SQL avancé (clause obligatoire) · *Mardi pour le choix, jeudi pour l'intégration*

| ID | Priorité | Ticket | Critères d'acceptation |
|---|---|---|---|
| ✅ US-60 | M | **Trigger** `trg_session_history` : AFTER UPDATE sur `session.status` | Insère `(session_id, ancien, nouveau, date)` dans `session_history` ; ne fait rien si le statut ne change pas. Sert aussi de trace pour E6 |
| ✅ US-61 | M | **Procédure** `sp_bilan_salle(room_id)` | Retourne le nombre de sessions terminées / annulées / interrompues et les sessions depuis le dernier contrôle (sert E9). Appelée via PDO dans `RoomRepository` et affichée sur la fiche salle |
| ✅ US-62 | M | **Vue** `v_planning_detaille` | Sessions + salle + GM + réservation et statut ; utilisée par le planning et `GET /api/sessions` |
| ✅ US-63 | M | **Vue** `v_dashboard_exploitation` | Prochaines sessions, incidents ouverts par salle, salles bloquées ; utilisée par le tableau de bord |
| ✅ US-64 | M | Migrations `002_vues.sql`, `003_procedures.sql`, `004_triggers.sql` | Reconstruction testée sur une **base vierge**, ordre décrit dans le README |
| ✅ US-65 | M | Preuves | Capture avant/après du trigger, `CALL`, extrait du repository, page qui dépend de chaque vue |

**État mercredi 25 soir :** vues, procédure et trigger écrits, branchés et testés (`tests/sql-advanced.php`, 25 vérifications ; vérifiés aussi dans la CI). Trigger créé en local après `SET PERSIST log_bin_trust_function_creators = 1` (erreur 1419, voir le README du starter-kit) ; `composer db:reset` reconstruit 001 → 004 + seed sans erreur ; trigger vérifié sur MySQL (PREVUE → PRETE tracé, même statut ou autre colonne : rien). Preuves US-65 : 12 captures dans `docs/preuves/US-65.md` (trigger déclenché depuis l'application, condition fausse, CALL, extraits des repositories, pages et API qui dépendent des vues).

---

## Épic 6 — Évolution et incident imposés · *Mercredi soir et jeudi*

| ID | Priorité | Ticket | Critères d'acceptation |
|---|---|---|---|
| ✅ US-70 | M | Analyser l'impact du ticket d'évolution client | Fiche d'analyse rédigée **avant** de toucher au code · fait : ticket EV-TEV-01 (issue #8), `starter-kit/docs/evolutions/EV-TEV-01.md` |
| ✅ US-71 | M | Implémenter l'évolution | Tests de non-régression sur E1, E5 et E10 toujours verts · fait : 299 vérifications vertes |
| ✅ US-72 | M | Incident : reproduire, diagnostiquer, corriger | Utiliser `templates/RAPPORT_INCIDENT.md`, avec un test qui prouve la correction · fait : incident INC-TEV-01 (issue #13 de l'enseignant), rapport `docs/incidents/INC-TEV-01.md`, preuves `docs/preuves/inc-tev-01/`, `tests/incident-inc-tev-01.php` (33 échecs avant, 92 vertes après) |

**Jeudi 26 matin :** incident INC-TEV-01 « option peut-être confirmée après 15 min » (E1). Défaut E1 **non reproduit** en 16 essais sur MySQL, dont un onglet resté ouvert 15 min 20 s sans aucun SQL (409, base inchangée, créneau libéré). Cause probable du signalement : une page de refus qui ressemblait à une confirmation, et un bouton « Libérer le créneau » encore affiché sur une option expirée. Corrigé : titre « Réservation non enregistrée : option expirée » avec la disponibilité réelle du créneau, annulation d'une option expirée refusée en 409, écriture conditionnelle (`changeStatusIf`) et relecture en `FOR UPDATE`. `composer test` : 15 suites vertes. Vérification MySQL après correction : voir `docs/preuves/inc-tev-01/apres-correction.md`. 🟡 Commit, PR `fix/inc-tev-01` et issue de suivi à faire.

---

## Épic 7 — Livraison · *Vendredi*

| ID | Priorité | Ticket | Critères d'acceptation |
|---|---|---|---|
| US-80 | M | Gel du périmètre et recette complète | `templates/RECETTE.md` rempli |
| US-81 | M | Vérification sécurité | Requêtes préparées, échappement à l'affichage (XSS), CSRF, droits, pas de secret dans Git |
| US-82 | M | Sauvegarde et restauration de la BDD | `mysqldump` puis restauration testée |
| 🟡 US-83 | M | README final | Installation, comptes de test, ordre des migrations · jeudi 26 : sections « Déploiement » et « Limites connues » de `starter-kit/README.md` rédigées |
| US-84 | M | Release Git (tag `v1.0`) | |
| US-85 | M | Démonstration de 5 à 7 minutes | Scénario : option E1 qui bloque le groupe B → GM affecté et conflit E5 → checklist 2/3 puis 3/3 (E10) → démarrage |

---

## Épic 8 — Extensions · *seulement si les épics 0 à 7 sont verts*

### Extension 1 — E2 « Le mécanisme critique est hors service » · **C**

| ID | Ticket | Critères d'acceptation |
|---|---|---|
| US-90 | Un incident bloquant sur un équipement critique empêche les nouvelles réservations de la salle | Équipement non critique : pas de blocage |
| US-91 | Les réservations confirmées passent « à recontacter », sans être supprimées | Liste visible au tableau de bord (s'appuie sur `v_dashboard_exploitation`) |
| US-92 | Résoudre l'incident débloque la salle | Tests : non critique · critique · résolution |

### Extension 2 — E6 « Partie interrompue » · **C**

| ID | Ticket | Critères d'acceptation |
|---|---|---|
| US-93 | Un incident critique pendant `EN_COURS` passe la session à `INTERROMPUE` | |
| US-94 | Reprise par une action explicite du responsable uniquement | Un GM qui tente la reprise est refusé |
| US-95 | Trace de l'interruption et de la reprise | Déjà couverte par le trigger US-60 |

### Extension 3 — E9 « Maintenance après dix parties » · **C**

| ID | Ticket | Critères d'acceptation |
|---|---|---|
| US-96 | Badge `CONTROLE_A_PREVOIR` après 10 sessions terminées depuis le dernier contrôle | Calculé par `sp_bilan_salle` (US-61), les sessions annulées ne comptent pas |
| US-97 | Enregistrer un contrôle (responsable) | Remet le compteur à zéro |
| US-98 | Tests | 9/10 sans badge · 10/10 avec badge · contrôle qui remet à zéro |

---

## Épic 9 — Demandes ajoutées en cours de semaine · *Mercredi*

Ajoutées après le démarrage, une fois les règles contractuelles vertes (fusionnées dans la PR #1).

| ID | Priorité | Ticket | Critères d'acceptation |
|---|---|---|---|
| ✅ US-100 | S | Le responsable ajoute un game master (compte `GAME_MASTER` uniquement) | Droit vérifié dans le service (responsable ou admin), rôle forcé, e-mail unique, mot de passe ≥ 10 caractères haché · `tests/game-master-accounts.php` |
| ✅ US-101 | S | « Mes réservations » : le client retrouve ses réservations sans compte (contact + code de suivi) | Code de suivi de 8 caractères · même message si le contact ou le code est faux · 5 essais max en 15 min (429) · accès séparé du personnel · `tests/client-access.php` |
| ✅ US-102 | C | Historique des parties du client | À venir / historique, résultat de chaque partie, nombre de parties jouées |
| ✅ US-103 | C | Vitrine : « Qui sommes-nous ? » et palette « nuit » | Chiffres calculés depuis les salles en base |

---

## Bonus (Could, après les extensions)

Planning en calendrier · écran « régie » sur tablette · QR code de check-in · taux d'occupation · historique de maintenance

## Hors périmètre (Won't)

Paiement · billetterie multi-groupes par session · SMS · serrures connectées · moteur de jeu

---

## Risques identifiés

| Risque | Parade |
|---|---|
| E1 : deux groupes posent une option au même instant | Vérification et insertion dans une **transaction**, avec un verrou `SELECT ... FOR UPDATE` sur la session |
| E1 et E5 difficiles à tester (heure réelle) | Service `Clock` injectable (US-07) |
| E5 : erreur de bornes sur les créneaux jointifs | Inégalités strictes, avec un test dédié |
| `DELIMITER` envoyé à PDO | Importer les migrations avec le client `mysql`, pas avec PDO |

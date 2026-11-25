# Journal de bord — EscapeOps

## Lundi 23 novembre 2026

### Début de journée

```text
Date : lundi 23 novembre 2026
Objectif principal : socle technique (épic 0) — une page issue de la BDD, la maquette et les règles choisies
3 tâches prioritaires :
  1. Choisir les règles et organiser le backlog
  2. Cahier des charges et MCD
  3. Installer le starter, écrire le schéma SQL et le jeu de données, premier parcours
Risque principal :
Question éventuelle :
```

### Fin de journée

```text
Réalisé :
  - Règles choisies : E1, E5, E10 (contractuelles) · E2, E6, E9 (extensions) → CHOIX_REGLES.md
  - BACKLOG.md (9 épics) et backlog-notion.csv (64 tickets) pour l'import Notion
  - CAHIER_DES_CHARGES.md à partir du template, avec les scénarios de scene_de_demonstration.txt
  - MCD : modèle Looping (Modele_de_BDD.loo), puis image sans croisement (MCD_EscapeOps.png / .svg)
  - Poste : Composer installé, pdo_mysql activé, MySQL 8.4 dans le conteneur Docker escapeops-db
  - US-01 à US-07 : starter Slim + Medoo, 001_schema.sql (10 tables), seed.sql (dates relatives),
    composer db:reset, vitrine / et /salles/{id}, horloge injectable (Clock, FixedClock)
  - US-50 : GET /api/rooms et /api/rooms/{id} (404 JSON si inconnu) · contrat docs/API.md
Non terminé :
  - Commit et push du code (US-06) : en attente de validation
Problème rencontré :
  - MySQL était en UTC : décalage d'une heure avec PHP
Décision importante :
  - Fuseau Europe/Paris appliqué à PHP ET à la connexion MySQL (les règles E1 et E5 comparent des heures)
  - Ordre des règles mercredi : E1, puis E5, puis E10 (la plus risquée d'abord)
  - Dossier enseignant/ à ne pas mettre dans le dépôt (cartes d'incident et d'évolution)
Tests effectués :
  - composer test vert (contrat JSON, disponibilité des créneaux et E1 avec FixedClock)
  - Codes HTTP des routes vérifiés, pages contrôlées dans le navigateur
Premier objectif de demain : authentification et rôles (épic 1)
```

## Mardi 24 novembre 2026

### Début de journée

```text
Date : mardi 24 novembre 2026
Objectif principal : authentification, rôles et socle métier (épics 1 et 2)
3 tâches prioritaires :
  1. Connexion / déconnexion et rôles (US-10, US-11)
  2. Protection CSRF et invariant de modification du planning (US-12, US-13)
  3. CRUD des sessions et réservation d'un groupe (US-22, US-23)
Risque principal : prendre du retard sur l'épic 2 avant les règles E1/E5/E10 de mercredi
Question éventuelle :
```

### Point de mi-journée (matin)

```text
Réalisé :
  - US-10 : /connexion, /deconnexion, password_verify, session_regenerate_id, cookie HttpOnly + SameSite
  - US-11 : rôles ADMIN / RESPONSABLE / GAME_MASTER, middleware RequireRole (302, 403, 401/403 JSON)
  - US-12 : jeton CSRF obligatoire sur tous les POST HTML (403 sinon)
  - US-13 : Authorization::denyUnlessCanManagePlanning ; un game master reçoit 403 sur /gestion
Non terminé :
  - US-13 à brancher dans le futur SessionService (US-22)
Problème rencontré :
Décision importante :
  - l'API /api n'utilise pas de jeton CSRF : elle n'accepte que du JSON (415 sinon)
  - les droits sont vérifiés à deux niveaux : middleware de route ET service
Tests effectués :
  - composer test : tests/auth-roles.php (34 vérifications) vert
  - parcours manuel : connexion sans jeton 403, mauvais mot de passe 401,
    game master sur /gestion 403, responsable 200, déconnexion sans jeton 403, saisie HTML échappée
Autres actions :
  - .gitignore du starter-kit complété (.env.*, sauvegardes BDD, fichiers Windows) et .gitignore racine créé (*.lo1)
  - Documentation mise à jour : README, docs/API.md (erreurs 401/403), docs/ARCHITECTURE.md
  - Backlog (.md et .csv) : US-10 à US-13 « Fait », US-20 à US-29 « En cours »
  - Deux commits faits sans validation puis annulés (git reset, fichiers intacts) :
    règle posée, aucun commit sans accord explicite
```

### Après-midi

```text
US-22 — CRUD des sessions (responsable) :
  - /gestion/sessions : planning (salle, game master, groupe confirmé, état)
  - création et modification : salle + début, fin calculée = début + durée de la salle
  - refus : salle inconnue, date invalide ou passée, salle déjà occupée (remise en état comprise),
    modification d'une session qui n'est plus « prévue »
  - US-13 terminé : SessionService::create/update commencent par Authorization::denyUnlessCanManagePlanning
Décision importante :
  - pas de suppression : une session sera annulée (état ANNULEE, US-27) pour garder l'historique
  - seule une session PREVUE se modifie : une session PRETE a déjà sa checklist (E10)
  - la jointure du planning (session + salle + GM + réservation) deviendra la vue v_planning_detaille (US-62)
Tests effectués :
  - tests/sessions.php (30 vérifications, SessionService réel sur SQLite en mémoire) vert
  - serveur + MySQL : création 303, conflit 422, date passée 422, sans CSRF 403,
    session PRETE non modifiable 422, id inconnu 404, game master 403, anonyme → /connexion
```

Suite de l'épic 2 :
  - US-20 : bouton « Réserver » sur les créneaux libres de la vitrine
  - US-23 : réservation publique (/sessions/{id}/reserver) : transaction + SELECT ... FOR UPDATE,
    refus 409 si passé / annulé / déjà confirmé / en option, 422 si groupe, contact ou nombre de joueurs invalide
  - US-27 : cycle PREVUE → PRETE → EN_COURS → TERMINEE, ou ANNULEE (fiche /espace/sessions/{id})
  - US-28 : tableau de bord /gestion (état des salles, prochaines sessions, incidents ouverts)
  - US-29 : filtres du planning par salle, date et état, « aucun résultat »
  - US-21 : CRUD des salles · US-25 : équipements N–N · US-24 : fiches game master · US-26 : incidents
```

### Fin de journée

```text
Réalisé :
  - Épic 1 complet (US-10 à US-13) et épic 2 complet (US-20 à US-29)
  - Jalon atteint : le responsable crée une session, un groupe la réserve sans compte,
    elle apparaît au tableau de bord et dans le planning
Non terminé :
  - rien sur les épics 1 et 2
Problème rencontré :
  - un nom accentué envoyé dans un mauvais encodage était tronqué en silence par MySQL
    (« Les Enquêteurs » → « Les Enqu ») : les textes non UTF-8 sont désormais refusés (Field::text)
Décision importante :
  - droits repris du cahier des charges (§ 3) : le game master affecté prépare, démarre et termine
    SA session ; seul le responsable (ou l'admin) annule, gère le planning et résout les incidents
  - annuler une session garde la réservation et la marque « à recontacter »
  - une salle qui a des sessions ou des incidents n'est pas supprimée (historique conservé)
  - vues SQL repérées pour jeudi : v_planning_detaille = SessionRepository::search,
    v_dashboard_exploitation = DashboardRepository::roomStatus
Tests effectués :
  - composer test : 180 vérifications vertes (7 fichiers, dont 4 sur SQLite en mémoire)
  - serveur + MySQL : toutes les pages pour chaque rôle (200 / 403), parcours du jalon,
    refus de réservation (409 / 422 / 403 CSRF), cycle d'états, XSS échappé
Premier objectif de demain : E1 — option de 15 minutes (US-30 à US-34)
```

## Mercredi 25 novembre 2026

### Début de journée

```text
Date : mercredi 25 novembre 2026
Objectif principal : les 3 règles contractuelles avec leurs refus (E1, E5, E10), puis l'API JSON
3 tâches prioritaires :
  1. E1 — option de 15 minutes : pose, blocage, expiration, confirmation hors délai (US-30 à US-34)
  2. E5 — game master unique : affectation, conflit, créneaux jointifs, démarrage sans GM (US-35 à US-38)
  3. E10 — checklist 3/3 avant PRETE, contrôlée côté serveur (US-39 à US-42)
Risque principal : E1 (temps et concurrence) — d'où l'ordre E1, puis E5, puis E10
Question éventuelle :
```

### Point de mi-journée (matin)

```text
Réalisé :
  - E1 (US-30 à US-34) : « Réserver » pose une option EN_ATTENTE, échéance = maintenant + 15 min,
    et redirige vers une page de suivi /reservations/{jeton} (lien secret, sans compte) :
    confirmer dans le délai, ou libérer le créneau
      · pendant l'option : groupe B refusé (409) « Ce créneau est en option jusqu'à 10 h 02 … »
      · à 15 min pile : l'option a expiré, le créneau redevient réservable (aucune tâche planifiée)
      · confirmation hors délai : 409 « Votre option a expiré à … », la ligne reste EN_ATTENTE
      · confirmation refusée aussi si la session a été annulée entre-temps
  - E5 (US-35 à US-38) : game master facultatif dans le formulaire de session et affectation sur la fiche
    (remplacer ou retirer tant que la session est prévue ou prête) ; refus si chevauchement
    (a.début < b.fin ET b.début < a.fin), créneaux jointifs acceptés ; démarrage sans GM refusé
  - E10 (US-39 à US-42) : checklist (salle rangée, matériel testé, sécurité vérifiée) sur la fiche session,
    remplie par le game master affecté ; 2/3 → enregistrée mais « salle non prête » ; 3/3 → PRETE ;
    historique des checklists affiché sur la fiche
Non terminé :
  - API JSON (US-51 à US-55) : cet après-midi
Problème rencontré :
  - le scénario « démarrer sans game master » était impossible à montrer : une session ne devient PRETE
    qu'avec la checklist de son GM. Solution : le responsable peut retirer ou remplacer le GM
    d'une session prête (cas réel : GM absent), et le démarrage est alors refusé
Décision importante :
  - colonne reservation.jeton (CHAR(32), UNIQUE) ajoutée à 001_schema.sql : rien n'est livré,
    la base se reconstruit avec composer db:reset ; jetons fixes dans le seed pour la démo
  - une option expirée n'est pas réécrite en base : son état « expirée » est calculé (ReservationOption)
  - le bouton « Marquer prête » disparaît : on passe à PRETE uniquement par la checklist ;
    un POST forgé etat=PRETE est refusé côté serveur, même pour l'admin
  - seul le game master affecté remplit la checklist (c'est son nom qui est enregistré)
  - chaque checklist envoyée est gardée, complète ou non (traçabilité demandée par le cahier des charges)
  - pas de temps de remise en état pour E5 : il concerne la salle, pas le game master
Tests effectués :
  - composer test : 272 vérifications vertes (9 fichiers ; nouveaux : game-master-conflicts.php, checklist.php,
    reservations.php réécrit pour E1)
  - serveur + MySQL : option 303 → page de suivi, B refusé 409, confirmation 303 puis 409 si répétée,
    option expirée 409, jeton inconnu 404, POST sans CSRF 403 ; conflit Camille 422 avec message,
    créneau jointif David 303, game master sur /gestion 403 ; checklist 2/3 422, POST forgé PRETE 422,
    autre GM 403, 3/3 303 → Prête ; GM retiré puis « Démarrer » 422 « aucun game master affecté »
  - CI : la vérification du formulaire de réservation cherche le nouveau bouton, contrôles E1 ajoutés
```

### Évolution client EV-TEV-01 (issue #8) — « Une checklist ne remplace pas un game master »

```text
Reçu : mercredi 25/11, ticket d'évolution croisée E10 + E5 (affecté par l'enseignant)
Démarche :
  1. analyse d'impact rédigée AVANT le code : starter-kit/docs/evolutions/EV-TEV-01.md
     (besoin reformulé, composants, migration, cas de recette, estimation ≈ 1 h 30, risques)
  2. constat : la faille signalée par la cliente existait bel et bien chez nous depuis ce matin :
     retirer le game master d'une session « Prête » la laissait « Prête » sans animateur
  3. code, tests, recette dans le navigateur, documentation
Réalisé :
  - PRETE exige checklist 3/3 ET game master affecté, admissible (compte GAME_MASTER), sans conflit E5 ;
    un POST forgé etat=PRETE sans game master → 422 « Une checklist ne remplace pas un animateur »
  - retirer l'unique game master d'une session prête la repasse à PREVUE (« personnel manquant ») ;
    les réservations ne sont pas touchées ; le nouveau game master revalide la checklist
  - remplacer un game master par un autre (disponible) : la session reste prête
  - une session EN_COURS n'est jamais modifiée (affectation refusée)
Décision importante (ADR-001 dans la fiche) :
  - état STOCKÉ (session.statut repasse à PREVUE) plutôt que calculé : planning, filtres, tableau de bord,
    API et vues SQL de jeudi lisent le même champ ; le trigger US-60 tracera PRETE → PREVUE
  - game master et état écrits dans un seul UPDATE ... WHERE statut = <état lu> (concurrence avec un démarrage)
  - le contrôle « aucun game master » d'un démarrage passe avant celui du cycle d'états :
    un POST forgé etat=EN_COURS reçoit le motif E5
Non traité (noté dans la fiche) :
  - suppression d'un compte game master (ON DELETE SET NULL) : aucun écran ne le permet aujourd'hui
Tests effectués :
  - tests/evolution-ev-tev-01.php (26 vérifications, les 4 critères d'acceptation + 2 cas limites)
  - composer test : 299 vérifications vertes, E1 / E5 / E10 inchangés
  - serveur + MySQL, session 7 : retrait de David → « Prévue » + bandeau ; PRETE forgé 422 ; EN_COURS forgé 422 ;
    David réaffecté, checklist 3/3 → « Prête »
```

### Améliorations de l'existant (après l'évolution)

```text
Réalisé :
  - MCD : attributs a_recontacter et jeton ajoutés à RESERVATION (SVG + PNG régénéré) ;
    cahier des charges § 7 à jour. Le modèle Looping (.loo, binaire) reste à compléter à la main
  - vitrine et API GET /api/rooms/{id} : « En option jusqu'à 11 h 05 » (champ optionJusqua, docs/API.md)
  - planning et tableau de bord : option en cours visible (groupe + échéance), « personnel manquant »
    sur les sessions prévues ou prêtes sans game master
  - page de suivi /reservations/{jeton} : en-têtes Referrer-Policy: no-referrer et Cache-Control: no-store
    (le jeton est la seule clé du groupe)
  - nettoyage : assignGameMaster renvoie l'état avant/après (plus de double lecture dans le contrôleur) ;
    fonction errorMessage() commune aux tests
Problème rencontré :
  - un remplacement de texte par script a vidé tests/evolution-ev-tev-01.php (fichier pas encore versionné) ;
    fichier réécrit à l'identique, même nombre de vérifications. Leçon : commiter plus souvent
Tests effectués :
  - composer test : 302 vérifications vertes
  - serveur + MySQL : vitrine, API, planning, tableau de bord et en-têtes vérifiés
```

### Ajout d'un game master par le responsable

```text
Demande : le responsable doit pouvoir ajouter des game masters (constituer son équipe) sans passer par la base
Réalisé :
  - /gestion/game-masters/nouveau : prénom, nom, e-mail (identifiant de connexion), mot de passe initial + confirmation
  - GameMasterService::create : droit vérifié dans le service (responsable ou admin), rôle forcé à GAME_MASTER,
    e-mail normalisé en minuscules et unique, mot de passe ≥ 10 caractères avec lettre et chiffre, password_hash
Décision importante :
  - le cahier des charges (§ 3) réservait « gérer les comptes » à l'admin ; on ouvre au responsable la seule création
    de comptes game master. Il ne peut créer ni responsable ni admin (un champ role forgé est ignoré)
  - le mot de passe initial est transmis de vive voix (aucun e-mail envoyé) ; pas encore de changement de mot de passe
    par le game master : piste d'amélioration
Tests effectués :
  - tests/game-master-accounts.php (22 vérifications) ; composer test : 324 vérifications vertes
  - serveur + MySQL : formulaire invalide 422 (mots de passe non réaffichés), création 303, game master 403,
    connexion du nouveau compte 303 puis 403 sur /gestion, nouveau compte proposé à l'affectation des sessions
```

### « Mes réservations » : accès client sans compte

```text
Demande : « une connexion pour un client ». Choix validé : retrouver ses réservations sans créer de compte
(le cahier des charges § 3 et la règle E1 prévoient un public sans compte)
Réalisé :
  - chaque option reçoit un code de suivi de 8 caractères (alphabet sans 0/O, 1/I/L), affiché sur la page de suivi
    et dans le message de confirmation ; colonne reservation.code_suivi (001_schema, base reconstruite) ; MCD mis à jour
  - /mes-reservations : contact (e-mail ou téléphone) + code → liste de toutes les réservations faites avec ce contact,
    avec l'état de l'option calculé et le lien « Confirmer ou libérer »
  - contact comparé sous forme normalisée (e-mail en minuscules, téléphone sans espaces/points/tirets), en PHP et en SQL
Sécurité :
  - même message que le contact ou le code soit faux ; 5 échecs max par quart d'heure et par session (429)
  - accès rangé sous une clé de session distincte du personnel : aucun droit sur /espace ni /gestion ;
    session_regenerate_id à l'entrée et à la sortie ; page en no-store / no-referrer (elle liste les liens secrets)
  - limite connue : le compteur d'échecs est lié à la session (un attaquant peut changer de cookie). Acceptable ici
    vu la taille du code (31^8) ; une limitation par adresse IP serait l'étape suivante
Tests effectués :
  - tests/client-access.php ; composer test : 350 vérifications vertes
  - serveur + MySQL : mauvais code 422, bon code (e-mail en majuscules, code en minuscules avec tiret) 303 → liste,
    téléphone saisi sans espaces 303, client sur /espace → /connexion, « Quitter » 303, 5 échecs 422 puis 429
```

### « Mes réservations » : historique des parties

```text
Réalisé :
  - la page se divise en « À venir » (option en cours ou réservation confirmée, la plus proche d'abord)
    et « Historique des parties » (la plus récente d'abord), avec le résultat de chaque partie :
    jouée, en cours, interrompue, session annulée par les Archives, réservation annulée, option expirée, non jouée
  - compteur « N parties jouées » ; règles pures dans Domain/ClientHistory
Décision : l'état de la session prime sur l'heure (une session terminée n'est jamais « à venir », même si
  son horaire est dans le futur). Découvert sur le jeu de démo : la session 1 est TERMINEE mais datée de 14 h aujourd'hui
Tests effectués : composer test : 358 vérifications vertes ; page vérifiée avec team.enigme@example.test + TEAM-2345
```

# INC-TEV-01 : vérification après correction, sur le vrai MySQL

> Objet : les tests automatiques (`composer test`) passent, mais ils tournent sous **SQLite**. Le chemin propre à MySQL n'avait jamais été exécuté : `ReservationRepository::findByToken(..., lock: true)` (`SELECT ... FOR UPDATE` sur une jointure de 3 tables) et `ReservationRepository::changeStatusIf` (UPDATE conditionnel + `rowCount()`). Ce document le vérifie à travers l'application réelle (HTTP, curl), sur MySQL 8.4.

Essais du **jeudi 26/11/2026, 09 h 57 – 10 h 04** (heure de Paris), sur la base de test locale reconstruite par `composer db:reset`. Données fictives. Dans les preuves, les jetons sont tronqués à 4 caractères (`a893…`), les codes de suivi masqués (`5H..-....`), et les cookies et jetons CSRF ne sont jamais recopiés. Sorties brutes : [apres-correction-sorties.txt](apres-correction-sorties.txt).

## 1. Environnement

| Élément | Valeur relevée |
|---|---|
| Code testé | branche `fix/inc-tev-01`, base `c970029` + correction **non commitée** (copie de travail : `ReservationService`, `ReservationRepository`, `ReservationController`, `ReservationStateException`, `SessionStatus`, `templates/reservations/show.php`). Aucun fichier de code n'a été modifié pendant les essais |
| PHP | 8.5.11 CLI (Windows, ZTS x64) |
| Serveurs | deux serveurs intégrés sur la **même base** : `php -S 127.0.0.1:8083 -t public public/router.php` et le même sur **8084** (le port 8082 était déjà occupé). Le second sert seulement aux essais de concurrence (f), parce que le serveur intégré traite une requête à la fois |
| MySQL | 8.4.13, conteneur Docker `escapeops-db` ; `transaction_isolation = REPEATABLE-READ`, `innodb_lock_wait_timeout = 50` |
| Fuseau | connexion de l'application : `SET time_zone = 'Europe/Paris'` (vu dans le journal général à chaque connexion) ; client `mysql` des preuves : `SET time_zone='Europe/Paris'` avant chaque requête |
| Client mysql | `--default-character-set=utf8mb4` ; noms de groupe **sans accent** (curl sous Windows, voir reproduction.md § 5) |
| Journal général MySQL | activé (`SET GLOBAL general_log = 1`, fichier `/tmp/…` du conteneur) **le temps des essais seulement**, pour voir les requêtes réellement envoyées. Désactivé ensuite (`general_log = 0`, fichier d'origine restauré) |

Méthode reprise de [reproduction.md](reproduction.md) : un « onglet » est simulé par curl avec un fichier de cookies. On fait `GET /reservations/{jeton}` (page avec le formulaire et son `_csrf`), puis le `POST` avec **ce** cookie et **ce** `_csrf`. Pour vieillir une option, on exécute dans le client mysql, après `SET time_zone='Europe/Paris'` :

```sql
UPDATE reservation SET date_creation = NOW() - INTERVAL 16 MINUTE, date_expiration = NOW() - INTERVAL 1 MINUTE
 WHERE jeton = '<jeton>' AND statut = 'EN_ATTENTE';   -- ROW_COUNT() = 1
```

## 2. Données de test

Salle 1, « Le Cabinet des Ombres ». Le compte **responsable** a créé les sessions 9 à 14 (27/11, de 10 h 00 à 20 h 00, toutes les 2 h) par le formulaire `POST /gestion/sessions` (6 × 303). Les options ont été posées par l'application : formulaire public `POST /sessions/{id}/reserver`, ou `POST /api/reservations`.

| Résa | Session | Groupe | Essai |
|---|---|---|---|
| 5 | 9 (10 h 00) | Groupe A (valide) | (a), (e), (d2) |
| 6 | 10 (12 h 00) | Groupe B (expiree) | (b) |
| 7 | 11 (14 h 00) | Groupe C (expiree) | (c) |
| 8 | 11 | Groupe D (autre groupe), par l'API | (c) : reprend le créneau |
| 9 | 12 (16 h 00) | Groupe E (annule) | (d) |
| 10 | 13 (18 h 00) | Groupe F (concurrence) | (f1) |
| 11 | 14 (20 h 00) | Groupe G (concurrence) | (f2) |
| 12 | 12 | Groupe H (concurrence API) | (f3) |

## 3. Essais

| # | Méthode et URL | Contexte | Statut HTTP | Attendu | Obtenu | BDD après |
|---|---|---|---|---|---|---|
| a | `POST /reservations/a893…/confirmer` | onglet, option valide | **303** → `/reservations/a893…`, puis 200 | confirmée | « Réservation confirmée : Le Cabinet des Ombres, le vendredi 27 novembre · 10 h 00. À bientôt ! », État : Confirmée | `EN_ATTENTE` → **`CONFIRMEE`** ✅ |
| e-1 | `POST /reservations/a893…/confirmer` | 2e clic dans le même onglet, `_csrf` valide | **409** | refus | titre « Réservation déjà confirmée », « Cette réservation est déjà confirmée. » | `CONFIRMEE`, inchangé ✅ |
| e-2 | `POST /api/reservations/a893…/confirmation` | 2e confirmation par l'API | **409** | refus | `{"error":"RESERVATION_CONFLICT",…,"etat":"CONFIRMEE"}` | inchangé ✅ |
| b-1 | `POST /reservations/eee9…/confirmer` | **onglet ancien** (bouton « Confirmer » chargé avant le vieillissement), option expirée depuis 1 min | **409**, pas de redirection | 409 + titre « Réservation non enregistrée : option expirée », un seul message avec l'heure, phrase sur le créneau, lien « Poser une nouvelle option » | `<title>Réservation non enregistrée : option expirée`, h1 identique ; **un seul** message « Votre option a expiré à 09 h 58 : la réservation n'a pas été enregistrée. » (1 seule occurrence de « a expiré ») ; « Le créneau est encore libre : vous pouvez poser une nouvelle option. » ; lien `<a class="button" href="/sessions/10/reserver">Poser une nouvelle option</a>` ; aucun formulaire (ni « Confirmer » ni « Libérer ») | `EN_ATTENTE`, expirée, 0 réservation bloquante ✅ |
| b-2 | `POST /api/reservations/eee9…/confirmation` | HTTP direct | **409** | `OPTION_EXPIRED` | `{"error":"OPTION_EXPIRED","message":"Votre option a expiré à 09 h 58 : la réservation n'a pas été enregistrée.","etat":"EXPIREE"}` | inchangé ; `GET /api/sessions` : session 10 `LIBRE` ✅ |
| c-0 | `POST /api/reservations` (groupe D, session 11) | 1er appel : **erreur de ma part** (champs `groupName`/`players` au lieu de `nomGroupe`/`nbJoueurs`) | 422 | — | `VALIDATION_ERROR` : comportement correct de l'application, essai refait juste après | aucune ligne ajoutée |
| c-1 | `POST /api/reservations` (groupe D, session 11) | après l'expiration de C | **201** + `Location: /api/reservations/9151…` | accepté | option D `EN_ATTENTE` jusqu'à 10:14:55 | 1 réservation bloquante ✅ |
| c-2 | `GET /reservations/567e…` | page de C, créneau repris par D | 200 | « repris par un autre groupe… », pas de « Libérer le créneau » | « Votre option a expiré à 09 h 58 sans être confirmée… » puis « Le créneau a été repris par un autre groupe, qui le garde en option jusqu'à 10 h 14 : il sera de nouveau proposé si cette option n'est pas confirmée. » ; bouton « Voir les autres créneaux » (`/salles/1`) ; **0 formulaire**, 0 « Libérer le créneau », 0 « Poser une nouvelle option » | — ✅ |
| c-3 | `POST /reservations/567e…/annuler` | **POST forgé**, `_csrf` valide pris sur l'onglet initial (qui avait encore le bouton) | **409**, pas de redirection | 409, ligne reste `EN_ATTENTE` | titre « Annulation refusée : option expirée », « Votre option a expiré à 09 h 58 : elle ne vous gardait déjà plus le créneau, il n'y avait rien à libérer. » + phrase « repris par un autre groupe… » | C toujours **`EN_ATTENTE`** (pas `ANNULEE`) ; D intacte ; API session 11 `EN_OPTION` jusqu'à 10:14:55 ✅ |
| c-4 | `POST /reservations/567e…/confirmer` | POST forgé, `_csrf` valide | **409** | refus | « Réservation non enregistrée : option expirée » | inchangé ✅ |
| c-bis | `GET` puis `POST …/annuler` sur C | **avant** la prise de D (créneau encore libre) | 200 / **409** | refus | page « Le créneau est encore libre… » sans bouton « Libérer » ; annulation forgée → 409 « Annulation refusée : option expirée » | `EN_ATTENTE` ✅ |
| d | `POST /reservations/ee8a…/annuler` | option valide, clic « Libérer le créneau » | **303**, puis 200 | annulation | « Réservation annulée : le créneau est de nouveau proposé aux autres groupes. », État : Annulée | `EN_ATTENTE` → **`ANNULEE`** ; API session 12 `EN_OPTION` → `LIBRE` ✅ |
| d-bis | `POST /reservations/ee8a…/annuler` | 2e annulation | **409** | refus | « Annulation refusée », « Cette réservation est déjà annulée. » | inchangé ✅ |
| d2 | `POST /reservations/a893…/annuler` | réservation **confirmée** (bouton « Annuler la réservation ») | **303** | annulation | « Réservation annulée… », État : Annulée | `CONFIRMEE` → `ANNULEE` ✅ |
| f1 | 2 × `POST /reservations/d6c3…/confirmer` (8083 et 8084), **même cookie** | ligne verrouillée 6 s par un client mysql externe, puis libérée | **303** / **409** | 1 succès, 1 refus | R1 « Réservation confirmée », R2 « Réservation déjà confirmée » | 1 seule `CONFIRMEE` ✅ (mais voir § 5 : R2 attendait la **session PHP**, pas MySQL) |
| f2 | 2 × `POST /reservations/6414…/confirmer` (8083 et 8084), **deux appareils** (cookies et `_csrf` distincts) | même verrou externe de 6 s : les 2 requêtes attendent **dans InnoDB** | **303** (5,13 s) / **409** (5,13 s) | 1 succès, 1 refus 409 | R2 : titre « Réservation déjà confirmée », « Cette réservation est déjà confirmée. » | **1 seule** `CONFIRMEE` ✅ |
| f3 | 2 × `POST /api/reservations/d998…/confirmation` lancés en même temps (8083 et 8084), sans verrou externe | concurrence « brute » | **200** / **409** | 1 succès, 1 refus | `"statut":"CONFIRMEE"` / `RESERVATION_CONFLICT` « déjà confirmée » | 1 seule `CONFIRMEE` ✅ (les deux transactions ne se sont pas chevauchées dans MySQL : écart de 5 ms) |

**500 et erreurs SQL** : aucune. Pas de réponse 500 dans les essais. Les logs des deux serveurs PHP ne contiennent aucune ligne `fatal|warning|error|exception|[500]` (seulement `Accepted` / `Closing`). Le journal général MySQL compte 0 occurrence de `error|exception|SQLSTATE|deadlock`.

### Ce que montre le verrouillage (f2)

Relevé à t0 + 3 s dans `performance_schema.data_lock_waits` : **3 attentes**. Les deux requêtes de l'application (`THREAD_ID` 860 et 861 de performance_schema, connexions 819 et 820 du journal ci-dessous) attendent le client externe (859, connexion 818), et la seconde attend aussi la première.

```text
| demandeur | bloqueur | table_verrouillee | INDEX_NAME           | LOCK_MODE     | LOCK_STATUS |
|       860 |      859 | reservation       | uq_reservation_jeton | X,REC_NOT_GAP | WAITING     |
|       861 |      859 | reservation       | uq_reservation_jeton | X,REC_NOT_GAP | WAITING     |
|       861 |      860 | reservation       | uq_reservation_jeton | X,REC_NOT_GAP | WAITING     |
```

Journal général MySQL (UTC = Paris − 1 h ; requêtes abrégées, jeton masqué) :

```text
09:01:13.932  818 START TRANSACTION
09:01:13.932  818 SELECT id_reservation FROM reservation WHERE jeton='<jeton>' FOR UPDATE      -- client externe
09:01:14.829  819 START TRANSACTION
09:01:14.830  819 SELECT r.… FROM reservation r JOIN session s … JOIN salle sa … WHERE r.jeton = '<jeton>' FOR UPDATE   -- bloqué
09:01:14.846  820 START TRANSACTION
09:01:14.846  820 SELECT r.… (même requête) … FOR UPDATE                                         -- bloqué
09:01:19.933  818 COMMIT                                                                         -- verrou libéré
09:01:19.935  819 SELECT s.… FROM session s … WHERE s.id_session = '14' FOR UPDATE   (×2)
09:01:19.938  819 UPDATE reservation SET statut = 'CONFIRMEE' WHERE id_reservation = '11'
                  AND statut = 'EN_ATTENTE' AND date_expiration > '2026-11-26 10:01:19'
09:01:19.938  819 COMMIT
09:01:19.943  820 SELECT s.… WHERE s.id_session = '14' FOR UPDATE
09:01:19.945  820 ROLLBACK                                     -- aucun UPDATE : la lecture verrouillante a vu CONFIRMEE
```

C'est le point sensible de la correction. Sous REPEATABLE READ, la requête 820, débloquée après le COMMIT de 819, **relit la dernière version validée** (statut `CONFIRMEE`) : c'est une lecture courante, pas l'instantané de début de transaction. Le service refuse donc avec « déjà confirmée », sans tenter d'UPDATE. Le `FOR UPDATE` placé sur la jointure de 3 tables est accepté par MySQL 8.4 sans erreur et pose bien le verrou X sur l'index `uq_reservation_jeton`.

### `changeStatusIf` et `rowCount()` sur MySQL

UPDATE réellement envoyés (journal général) :

```text
UPDATE reservation SET statut = 'CONFIRMEE' WHERE id_reservation = '5'  AND statut = 'EN_ATTENTE' AND date_expiration > '2026-11-26 09:58:48'  -- (a)  → 303
UPDATE reservation SET statut = 'ANNULEE'   WHERE id_reservation = '9'  AND statut = 'EN_ATTENTE' AND date_expiration > '2026-11-26 10:00:07'  -- (d)  → 303
UPDATE reservation SET statut = 'ANNULEE'   WHERE id_reservation = '5'  AND statut = 'CONFIRMEE'                                                 -- (d2) → 303
UPDATE reservation SET statut = 'CONFIRMEE' WHERE id_reservation = '11' AND statut = 'EN_ATTENTE' AND date_expiration > '2026-11-26 10:01:19'  -- (f2) → 303
```

L'heure passée à `date_expiration > …` est bien l'heure de Paris (10:01:19 quand le journal, en UTC, affiche 09:01:19). Dans les refus (b, c, e, et le perdant de f), le service s'arrête **avant** l'UPDATE : la transaction se termine par `ROLLBACK`, sans écriture.

Le cas `rowCount() = 0` (branche « modifiée par une autre demande ») n'est pas atteignable par HTTP, puisque le verrou l'empêche. Il a été exercé directement : un script jetable (hors dépôt) appelle le vrai `ReservationRepository::changeStatusIf` avec la vraie `DatabaseFactory` sur MySQL, dans une transaction **annulée à la fin** :

```text
changeStatusIf(6 (B, EN_ATTENTE expirée), EN_ATTENTE -> CONFIRMEE, actif à 10:03:23) = false ; statut avant EN_ATTENTE, après EN_ATTENTE
changeStatusIf(11 (G, déjà CONFIRMEE),    EN_ATTENTE -> CONFIRMEE, actif à 10:03:23) = false ; statut avant CONFIRMEE, après CONFIRMEE
changeStatusIf(8 (D, EN_ATTENTE active),  EN_ATTENTE -> CONFIRMEE, actif à 10:03:23) = true  ; statut avant EN_ATTENTE, après CONFIRMEE
changeStatusIf(8 (D) 2e fois,             EN_ATTENTE -> CONFIRMEE, actif à 10:03:23) = false ; statut avant CONFIRMEE, après CONFIRMEE
ROLLBACK ; statut de 8 après rollback : EN_ATTENTE
```

`rowCount()` de PDO MySQL renvoie donc 1 ou 0 comme prévu (lignes réellement modifiées, sans `FOUND_ROWS`). La borne de date (option expirée → 0) et la condition de statut (déjà confirmée → 0) fonctionnent toutes deux.

## 4. Extraits SQL avant / après

```text
(a) réservation 5, session 9
avant  | 5 |  9 | Groupe A (valide)       | EN_ATTENTE | 2026-11-26 10:13:47 | 2026-11-26 09:58:47 | a893… | ACTIVE
après  | 5 |  9 | Groupe A (valide)       | CONFIRMEE  | 2026-11-26 10:13:47 | 2026-11-26 09:58:47 | a893… | CONFIRMEE
(e) après les deux 2es confirmations : identique (CONFIRMEE)

(b) réservation 6, session 10
pose   | 6 | 10 | Groupe B (expiree)      | EN_ATTENTE | 2026-11-26 10:14:16 | 2026-11-26 09:59:16 | eee9… | ACTIVE
vieilli| 6 | 10 | Groupe B (expiree)      | EN_ATTENTE | 2026-11-26 09:58:16 | 2026-11-26 09:43:16 | eee9… | EXPIREE (calculé)
après interface + API : identique ; nb_bloquantes = 0 ; session 10 PREVUE ; API "disponibilite":"LIBRE"

(c) session 11
après D  | 7 | 11 | Groupe C (expiree)      | EN_ATTENTE | 2026-11-26 09:58:35 | 2026-11-26 09:43:35 | 567e… | EXPIREE (calculé)
         | 8 | 11 | Groupe D (autre groupe) | EN_ATTENTE | 2026-11-26 10:14:55 | 2026-11-26 09:59:55 | 9151… | ACTIVE
après annulation et confirmation forgées : identique (C reste EN_ATTENTE, jamais ANNULEE) ; nb_bloquantes = 1 ; API EN_OPTION

(d) réservation 9, session 12
avant  | 9 | 12 | Groupe E (annule)       | EN_ATTENTE | 2026-11-26 10:15:06 | 2026-11-26 10:00:06 | ee8a… | ACTIVE      ; API EN_OPTION
après  | 9 | 12 | Groupe E (annule)       | ANNULEE    | 2026-11-26 10:15:06 | 2026-11-26 10:00:06 | ee8a… | ANNULEE     ; API LIBRE

(f2) réservation 11, session 14
avant  | 11 | 14 | Groupe G (concurrence) | EN_ATTENTE | 2026-11-26 10:16:12 | 2026-11-26 10:01:12 | 6414… | ACTIVE
après  | 11 | 14 | Groupe G (concurrence) | CONFIRMEE  | 2026-11-26 10:16:12 | 2026-11-26 10:01:12 | 6414… | CONFIRMEE   ; nb_confirmees = 1
```

Contrôle global en fin d'essais (10:03:34). La requête qui cherche une session avec plus d'une réservation bloquante (`CONFIRMEE`, ou `EN_ATTENTE` non expirée) renvoie **aucune ligne**. Les sessions 9 à 14 sont restées `PREVUE`.

## 5. Conclusion

**Correction validée sur MySQL 8.4.** Le chemin propre à MySQL, jamais exécuté par `composer test`, fonctionne :

- `findByToken(..., lock: true)` envoie bien `SELECT … JOIN session … JOIN salle … FOR UPDATE`. MySQL l'accepte et pose un verrou X sur la ligne de la réservation. Une requête concurrente attend, puis relit la **dernière version validée** : pas d'instantané périmé sous REPEATABLE READ ;
- `changeStatusIf` envoie l'UPDATE conditionnel attendu, avec l'heure de Paris. `rowCount()` vaut 1 quand la ligne change, et 0 si le statut a changé ou si l'option a expiré ;
- les 6 scénarios demandés donnent le résultat attendu : (a) 303 et `CONFIRMEE` ; (b) 409, titre « Réservation non enregistrée : option expirée », message unique avec l'heure, phrase « Le créneau est encore libre… », lien « Poser une nouvelle option », API 409 `OPTION_EXPIRED`, base inchangée ; (c) « repris par un autre groupe… », pas de bouton « Libérer le créneau », annulation forgée → 409 et ligne `EN_ATTENTE` ; (d) annulation normale → 303 `ANNULEE`, créneau `LIBRE` ; (e) double confirmation → 409 ; (f) deux confirmations simultanées → une 303, une 409, une seule ligne `CONFIRMEE` ;
- aucun 500, aucune erreur SQL, aucun interblocage.

**Limites et remarques**

1. **f1 ne prouve pas la sérialisation par MySQL.** Avec un fichier de cookies partagé, les deux requêtes utilisent la même session PHP. Le verrou de fichier de session PHP fait alors attendre la seconde avant même qu'elle atteigne MySQL : une seule attente InnoDB est visible, et R2 affiche même le message flash de R1. Le résultat reste correct, mais c'est **f2** (deux appareils, sessions distinctes) qui prouve le verrou InnoDB.
2. **f3** (concurrence brute, sans verrou externe) donne le bon résultat. Pourtant, les deux transactions ne se sont pas chevauchées dans MySQL (la 2e a commencé 5 ms après le COMMIT de la 1re). Sans le verrou forcé de f2, ce test ne prouverait rien sur la concurrence.
3. La branche « Cette réservation vient d'être modifiée par une autre demande » (CONFLIT, `rowCount() = 0` après une lecture valide) n'est **pas atteignable en HTTP**, puisque la lecture verrouillante l'empêche. Elle n'a été exercée qu'au niveau du repository (script annulé par ROLLBACK). C'est une protection de défense en profondeur.
4. Les heures du journal général MySQL sont en UTC : la session de l'application, elle, est bien en `Europe/Paris`, comme le montrent les valeurs passées à `date_expiration > …`.
5. Le premier appel API du groupe D (c-0) a échoué en 422 par erreur de ma part (mauvais noms de champs). L'application a répondu correctement, et l'essai a été refait.

## 6. Remise en état

Serveurs 8083 et 8084 arrêtés ; journal général MySQL désactivé, fichier d'origine restauré, fichier temporaire supprimé du conteneur ; `composer db:reset` relancé (base de test propre).

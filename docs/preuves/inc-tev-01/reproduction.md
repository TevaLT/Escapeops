# INC-TEV-01 — Reproduction et preuves (règle E1, option de 15 minutes)

> Signalement de recette : « J'ai pris une option pour Le Cabinet des Ombres, laissé l'onglet ouvert plus d'un quart d'heure puis cliqué sur Confirmer. Une page de confirmation est apparue, mais je ne sais pas si c'était enregistré. Sur mon téléphone, le créneau semblait encore libre. »
>
> Attendu (E1) : une option `EN_ATTENTE` bloque le créneau 15 min ; expirée, elle ne peut pas être confirmée (ni depuis un onglet ancien, ni en HTTP direct) et ne bloque plus un autre groupe ; une option valide se confirme.

Essais du **jeudi 26/11/2026, 09 h 21 – 09 h 39** (heure de Paris), sur la **base de test locale** reconstruite par `composer db:reset`. Toutes les données sont fictives (`*.test@example.test`). Dans les preuves, les jetons sont tronqués à 4 caractères (`7ab4…`), les codes de suivi masqués (`9V..-....`), les cookies et jetons CSRF jamais recopiés.

## 1. Environnement réel

| Élément | Valeur relevée |
|---|---|
| Code testé | commit `241987b`, branche `docs/preuves-ev-tev-01` ; `starter-kit/src`, `templates`, `routes` **non modifiés** pendant les essais (`git status` vide sur ces dossiers) |
| PHP | 8.5.11 CLI (Windows, ZTS x64), serveur intégré `php -S 127.0.0.1:8081 -t public public/router.php` |
| MySQL | 8.4.13 (conteneur Docker `escapeops-db`, image `mysql:8.4`) |
| Fuseau de l'application | `APP_TIMEZONE=Europe/Paris` : appliqué à PHP (`date_default_timezone_set`) et à la connexion MySQL (`SET time_zone = 'Europe/Paris'`, `DatabaseFactory`) |
| Fuseau du client `mysql` | `@@session.time_zone = SYSTEM`, `@@system_time_zone = UTC` → `NOW()` = **08:26** quand l'application est à **09:26** |
| Valeur stockée par l'application | `date_creation = 2026-11-26 09:24:50`, `date_expiration = 09:39:50` (heure de Paris) pour une option posée à 09 h 24 |
| Session PHP | `session.gc_maxlifetime = 1440 s` (24 min) |

Détail et sorties : [environnement-fuseaux.txt](environnement-fuseaux.txt).

**Colonnes réelles** : la table `reservation` n'a pas de colonnes `expires_at` / `created_at` mais `date_expiration` / `date_creation` (`DATETIME`, sans fuseau). Les preuves les affichent sous les alias `expires_at` et `created_at`. L'état « expirée » n'est **jamais écrit** en base : la ligne reste `EN_ATTENTE` et l'expiration est calculée à chaque requête (`maintenant < date_expiration` → active ; à l'heure pile → expirée). La colonne `etat_calcule` des preuves refait ce calcul en SQL.

**Méthode de vieillissement** : on modifie `date_expiration` (et `date_creation`, pour garder 15 min d'écart) **dans un client mysql réglé sur le fuseau de l'application**. Sans le `SET time_zone`, `NOW()` serait en UTC et l'option paraîtrait expirée une heure trop tôt (piège noté dans `docs/journal.md`). Requête exacte de l'essai B :

```sql
SET time_zone='Europe/Paris';
UPDATE reservation
   SET date_creation = NOW() - INTERVAL 16 MINUTE, date_expiration = NOW() - INTERVAL 1 MINUTE
 WHERE id_reservation = 7 AND statut = 'EN_ATTENTE';
-- ROW_COUNT() = 1
```

Essai C1 : `date_expiration = NOW()` (limite pile) ; C3 : `NOW() + INTERVAL 4 SECOND`, puis une attente réelle de 6 s ; C2 : `NOW() + INTERVAL 5 SECOND`, confirmée tout de suite (requêtes complètes dans [essai-C-limite.txt](essai-C-limite.txt)). L'essai R n'a **aucune** modification SQL : on attend vraiment 15 min 20 s.

## 2. Données de test

Salle 1 « Le Cabinet des Ombres » (2 à 6 joueurs). Sessions 9, 10 et 11 créées pour l'essai par le **responsable**, avec le formulaire `POST /gestion/sessions` (303). Options posées par l'application : formulaire public `POST /sessions/{id}/reserver` ou `POST /api/reservations`.

| Option | Session | Groupe | Posée par | Rôle dans l'essai |
|---|---|---|---|---|
| A (id 6) | 9 — 27/11 10 h 00 | Groupe A (valide) | formulaire public | option valide, confirmée |
| B (id 7) | 3 — 26/11 19 h 20 | Groupe B (expiree) | formulaire public | option expirée (vieillie en SQL) |
| D (id 8) | 3 | Groupe D (autre groupe) | API | autre groupe, après l'expiration de B |
| E | 3 | Groupe E | formulaire + API | 3e groupe pendant l'option de D → refusé |
| C1 / C3 / C2 (id 9, 10, 11) | 10 — 27/11 12 h 00 | Groupe C1/C3/C2 (limite) | API | à la limite : pile, quelques secondes après, quelques secondes avant |
| R (id 5) | 11 — 27/11 14 h 00 | Groupe R (onglet ouvert) | formulaire public | attente réelle de 15 min 20 s, onglet ouvert |

« Onglet ouvert » = un navigateur simulé avec `curl` et un fichier de cookies : `GET /reservations/{jeton}` (page avec le bouton « Confirmer » et son jeton CSRF), attente ou vieillissement, puis `POST /reservations/{jeton}/confirmer` avec **ce** cookie et **ce** jeton CSRF, comme un clic dans l'onglet resté ouvert.

## 3. Essais

| # | Méthode et URL | Contexte | Statut HTTP / redirection | Attendu | Obtenu | BDD après |
|---|---|---|---|---|---|---|
| A-a | `POST /reservations/9955…/confirmer` | onglet, option valide (reste 15 min) | **303** → `/reservations/9955…`, puis 200 | confirmée | « Réservation confirmée : Le Cabinet des Ombres, le vendredi 27 novembre · 10 h 00. À bientôt ! », État : Confirmée | `CONFIRMEE` ✅ |
| A-b | `POST /api/reservations/9955…/confirmation` | 2e confirmation | **409** `RESERVATION_CONFLICT` | refus « déjà confirmée » | « Cette réservation est déjà confirmée. » | inchangé ✅ |
| B-a | `POST /reservations/7ab4…/confirmer` | **onglet ancien**, option expirée d'1 min | **409**, pas de redirection | refus | page « Ma réservation » : bandeau rouge « Votre option a expiré à 09 h 23 : la réservation n'est pas confirmée… », État : Option expirée | `EN_ATTENTE`, expirée, 0 réservation bloquante ✅ |
| B-b1 | `POST /api/reservations/7ab4…/confirmation` | HTTP direct | **409** `OPTION_EXPIRED` | refus | message identique, `"etat":"EXPIREE"` | inchangé ✅ |
| B-b2 | `POST /reservations/7ab4…/confirmer` forgé, sans cookie ni `_csrf` | HTTP direct | **403** | refus | « Requête refusée — Le formulaire a expiré ou ne provient pas de ce site. » | inchangé ✅ |
| B-b3 | `POST /reservations/7ab4…/confirmer` forgé, autre navigateur, `_csrf` valide pris sur `/salles/1` | HTTP direct | **409** | refus | « Votre option a expiré à 09 h 23… » | inchangé ✅ |
| B-D | `POST /api/reservations` (groupe D, session 3) | après l'expiration de B | **201** + `Location: /api/reservations/2ace…` | accepté | option D `EN_ATTENTE` jusqu'à 09:39:50 | 1 seule réservation bloquante ✅ |
| B-E | `POST /sessions/3/reserver` (formulaire) et `POST /api/reservations` (groupe E) | pendant l'option de D | **409** / **409** `SLOT_UNAVAILABLE` | refus | « Ce créneau est en option jusqu'à 09 h 39… » | aucune ligne ajoutée ✅ |
| B-re | `POST /api/reservations/7ab4…/confirmation` | B retente après la prise de D | **409** `OPTION_EXPIRED` | refus | idem | inchangé ✅ |
| C1-a / C1-b | `POST /reservations/8a9b…/confirmer` puis API | `date_expiration = NOW()` (limite pile) | **409** / **409** `OPTION_EXPIRED` | refus (expirée à l'heure pile) | « Votre option a expiré à 09 h 25… » | `EN_ATTENTE` expirée ✅ |
| C3-a / C3-b | `POST /reservations/4c73…/confirmer` puis API | onglet rechargé **avant** l'échéance (bouton « Confirmer » présent), attente réelle 6 s, clic 3 s après l'échéance | **409** / **409** `OPTION_EXPIRED` | refus | idem | `EN_ATTENTE` expirée ✅ |
| C2-a | `POST /reservations/38a0…/confirmer` | reste 5 s | **303** → page de suivi | confirmée | « Réservation confirmée : … 12 h 00. À bientôt ! » | `CONFIRMEE` ✅ |
| C2-b | `POST /api/reservations/38a0…/confirmation` | 2e confirmation | **409** `RESERVATION_CONFLICT` | refus | « Cette réservation est déjà confirmée. » | inchangé ✅ |
| R | `POST /reservations/0438…/confirmer` | **onglet ouvert 15 min 20 s, aucune modification SQL** (clic 21 s après l'échéance) | **409**, pas de redirection | refus | même page « Option expirée » que B-a (« Votre option a expiré à 09 h 38 : la réservation n'est pas confirmée… ») | `EN_ATTENTE` expirée, 0 réservation bloquante ; API : session 11 `LIBRE` ✅ |

Vérification globale (après B, D et E) : la requête qui cherche une session avec plus d'une réservation bloquante (confirmée, ou `EN_ATTENTE` non expirée) ne renvoie **aucune ligne**.

### Planning public et `GET /api/sessions` (session 3)

| Moment | `/salles/1` | `GET /api/sessions?roomId=1&date=2026-11-26` |
|---|---|---|
| B expirée, avant D | 19 h 20 « **Disponible** », bouton « Réserver » | `"disponibilite":"LIBRE","optionJusqua":null` |
| après l'option de D | « En option jusqu'à 09 h 39 » | `"disponibilite":"EN_OPTION","optionJusqua":"2026-11-26 09:39:50"` |

Une option expirée et non confirmée **ne bloque donc plus** le créneau : c'est ce qu'a vu la testeuse sur son téléphone, et c'est conforme à E1.

### 3 bis. Essai R — attente réelle, sans SQL

C'est le scénario exact du signalement, sans aucune retouche en base ([essai-R-attente-reelle.txt](essai-R-attente-reelle.txt)) :

| Heure (Paris) | Action | Résultat |
|---|---|---|
| 09:23:02 | `POST /sessions/11/reserver` (formulaire public) | 303 → page de suivi, « Option en cours », gardée « jusqu'à 09 h 38 » ; BDD `EN_ATTENTE`, `date_expiration = 09:38:02` |
| 09:23:03 → 09:38:23 | onglet laissé ouvert (cookie et jeton CSRF conservés) | — |
| 09:38:23 | clic « Confirmer » : `POST /reservations/0438…/confirmer` avec le `_csrf` de l'onglet | **409**, page « Ma réservation », État « Option expirée », bandeau « Votre option a expiré à 09 h 38 : la réservation n'est pas confirmée. Si le créneau est encore libre, vous pouvez poser une nouvelle option. » |
| 09:38:24 | BDD et `GET /api/sessions` | ligne toujours `EN_ATTENTE` (expirée, jamais `CONFIRMEE`) ; session 11 `"disponibilite":"LIBRE"` |

L'onglet n'a été ouvert que 15 min 20 s : la session PHP (24 min) et le jeton CSRF étaient encore valides. Au-delà de 24 min, le ramasse-miettes des sessions PHP peut effacer la session : le clic donnerait alors un **403** « Le formulaire a expiré ou ne provient pas de ce site » (vu dans B-b2), et non une page de confirmation. Ce cas n'a pas été reproduit, car le ramasse-miettes se déclenche au hasard.

## 4. Extraits SQL avant / après

Essai B, ligne de la réservation 7 (sorties complètes dans [essai-B-option-expiree.txt](essai-B-option-expiree.txt)) :

```text
après la pose (09:24:32)
| 7 | 3 | Groupe B (expiree) | EN_ATTENTE | 2026-11-26 09:39:31 | 2026-11-26 09:24:31 | 7ab4… | ACTIVE            |   nb_bloquantes = 1
après le vieillissement (09:24:32)
| 7 | 3 | Groupe B (expiree) | EN_ATTENTE | 2026-11-26 09:23:32 | 2026-11-26 09:08:32 | 7ab4… | EXPIREE (calculé) |   nb_bloquantes = 0
après les 4 tentatives de confirmation (09:24:34) : identique, statut toujours EN_ATTENTE (jamais CONFIRMEE)
après l'option du groupe D (09:24:50)
| 7 | 3 | Groupe B (expiree)      | EN_ATTENTE | 2026-11-26 09:23:32 | ... | EXPIREE (calculé) |
| 8 | 3 | Groupe D (autre groupe) | EN_ATTENTE | 2026-11-26 09:39:50 | ... | ACTIVE            |   nb_bloquantes = 1
```

Session 3 : `PREVUE`, 19:20 – 20:20, inchangée tout au long de l'essai.

Essai A, réservation 6 : `EN_ATTENTE` (expire 09:38:12) → `CONFIRMEE` après le clic de 09:23:13. Essai C2, réservation 11 : `EN_ATTENTE` (expire 09:25:46) → `CONFIRMEE` après le clic de 09:25:41.

## 5. Conclusion

**Défaut fonctionnel E1 non reproduit.** Dans les 16 essais (interface avec onglet ancien, API, formulaires forgés, bornes à la seconde, attente réelle de 15 min 20 s) :

- une option expirée n'est **jamais** confirmée : HTTP 409 (403 sans jeton CSRF), et la ligne reste `EN_ATTENTE` en base ;
- elle ne bloque plus le créneau : planning « Disponible », API `LIBRE`, un autre groupe pose son option (201) ;
- pendant une option active, un 3e groupe est refusé (409) ; il n'y a jamais deux réservations bloquantes sur une même session ;
- une option valide se confirme (303 puis « Réservation confirmée »), y compris 5 s avant l'échéance ; à l'heure pile, l'option est expirée.

Ce que la testeuse a vu s'explique par le comportement **attendu** : son option avait expiré, la confirmation a été refusée (409), donc le créneau était de nouveau libre sur son téléphone. En revanche, **l'affichage du refus prête à confusion** ; c'est la source probable du signalement :

1. **Page de refus qui ressemble à une page de confirmation** (anomalie d'ergonomie, cause probable de l'incident). Après le clic hors délai, l'application ne redirige pas : elle renvoie la **même page** « Ma réservation · Le Cabinet des Ombres », avec le même titre d'onglet, le même titre, le récapitulatif du groupe et le code de suivi. Le refus n'apparaît que dans un bandeau et dans le badge « Option expirée ». Le 409 ne se voit pas à l'écran. Le message est donné **deux fois** avec des mots proches (« Votre option a expiré à 09 h 38 : la réservation n'est pas confirmée… » puis « Votre option a expiré à 09 h 38 sans être confirmée… »). « Si le créneau est encore libre, vous pouvez poser une nouvelle option » ne dit pas si le créneau est libre, alors que l'application le sait. Rien du type « Réservation NON enregistrée » en titre.
2. **Bouton « Libérer le créneau » sur une option expirée, avec un message faux** ([essai-B-affichages-annexes.txt](essai-B-affichages-annexes.txt)). Après son expiration, l'option B garde le bouton (`peut_annuler` est vrai tant que la partie n'a pas commencé). Cliqué alors que le groupe D tient le créneau, il passe B à `ANNULEE` (303) et affiche « Réservation annulée : le créneau est de nouveau proposé aux autres groupes. », alors que le créneau est **en option pour D** (API `EN_OPTION`). Les données restent correctes (l'option de D n'est pas touchée), mais le message est inexact et le bouton n'a pas de sens : l'option ne gardait déjà plus rien.
3. *(observation)* L'option expirée n'est jamais marquée en base (`EN_ATTENTE` éternel, voir § 1). Ce choix de conception est documenté. Mais une requête SQL ou un écran qui filtre sur `statut = 'EN_ATTENTE'` sans comparer `date_expiration` à l'heure de Paris affichera à tort une option « en attente ». « Mes réservations » calcule bien « Option expirée sans confirmation ».

**À quelle condition le symptôme se reproduit-il ?** Il suffit d'une option non confirmée avant `date_creation + 15 min`, puis d'un clic sur « Confirmer » dans l'onglet resté ouvert : la page « Ma réservation » se réaffiche (409) et peut se lire comme une confirmation. La réservation, elle, n'est pas enregistrée, conformément à E1.

**Artefacts d'essai, non imputables à l'application** : (i) sous Windows, `curl` n'envoie pas en UTF-8 un nom de groupe accentué passé en argument (« Groupe B (expirée) » → 422 « Indiquez le nom de votre groupe »). Les noms ont donc été écrits sans accent. (ii) Le formulaire `/sessions/{id}/reserver` d'un créneau en option n'a pas de champ CSRF (normal : pas de formulaire). Un POST qui reprend ce jeton absent reçoit 403. Avec un jeton valide, on obtient bien le 409 métier.

## 6. Fichiers de preuve

| Fichier | Contenu |
|---|---|
| [environnement-fuseaux.txt](environnement-fuseaux.txt) | versions, fuseaux (client mysql et application), valeur stockée, commit testé |
| [essai-A-option-valide.txt](essai-A-option-valide.txt) | option A : pose, onglet, confirmation, 2e confirmation par l'API |
| [essai-B-option-expiree.txt](essai-B-option-expiree.txt) | option B : vieillissement, onglet ancien (409), API (409), formulaires forgés (403, 409), rafraîchissement |
| [essai-B-suite-autre-groupe.txt](essai-B-suite-autre-groupe.txt) | planning et API avant/après, option du groupe D (201), absence de doublon |
| [essai-B-suite-groupe-E-refuse.txt](essai-B-suite-groupe-E-refuse.txt) | 3e groupe pendant l'option de D (409 formulaire et API) |
| [essai-B-affichages-annexes.txt](essai-B-affichages-annexes.txt) | « Mes réservations » de B ; bouton « Libérer le créneau » sur l'option expirée (anomalie 2) |
| [essai-C-limite.txt](essai-C-limite.txt) | C1 (limite pile), C3 (quelques secondes après), C2 (quelques secondes avant) |
| [essai-R-attente-reelle.txt](essai-R-attente-reelle.txt) | onglet ouvert 15 min 20 s, sans modification SQL |

Remise en état : serveur arrêté et `composer db:reset` relancé après les essais (base de test propre).

# Rapport d’incident

**Projet :** EscapeOps (BTS SIO SLAM), application `starter-kit/` (Slim 4 + Medoo, MySQL 8.4)  
**Incident :** INC-TEV-01 (US-72), « Option de réservation peut-être confirmée après 15 minutes » : issue #13 du dépôt de l’enseignant, assignée à TevaLT. Règle concernée : **E1** (une option pendant quinze minutes).  
**Date :** signalé le jeudi 26/11/2026 (observation déclarée à 14 h 41) ; essais de reproduction le 26/11/2026, de 09 h 21 à 09 h 39 (heure de Paris), sur le commit `241987b`  
**Gravité :** signalée comme **majeure**, car une confirmation hors délai permettrait une double réservation. Après diagnostic, elle est ramenée à **mineure** (ergonomie) : le défaut E1 n’est pas reproduit, mais l’affichage du refus induit en erreur.

**Statut final :** non reproduit sur la règle E1 (protections serveur vérifiées) ; défauts d’affichage corrigés ; écriture durcie.

---

## 1. Symptôme

Ticket rédigé par Élodie F. (accueil, testeuse fictive) le 26/11/2026 à 14 h 41 :

> « J’ai pris une option pour Le Cabinet des Ombres, laissé l’onglet ouvert plus d’un quart d’heure puis cliqué sur Confirmer. Une page de confirmation est apparue, mais je ne sais pas si c’était enregistré. Sur mon téléphone, le créneau semblait encore libre. »

**Comportement attendu (E1)** : une option `EN_ATTENTE` immobilise le créneau 15 minutes. Une fois expirée, elle ne peut plus être confirmée, ni depuis un onglet ancien, ni par une requête HTTP directe, et elle ne bloque plus un autre groupe. Une option encore valide se confirme.

### Qualification du ticket

| Élément | Ce que dit le ticket | Ce qui manque ou reste incertain |
|---|---|---|
| Fonction | confirmation d’une option (E1), salle 1 « Le Cabinet des Ombres » | créneau exact (date, heure de la session) |
| Environnement | Windows 11, Chrome, box 4G avec microcoupures, téléphone Android | version de Chrome ; navigateur du téléphone |
| Horloge | horloge du PC en avance de 2 à 3 minutes (« aurait ») | heure réelle de la pose et du clic |
| Données | — | **identifiant** de réservation ou code de suivi, **URL**, capture, **traces** |
| Répétabilité | un seul essai évoqué | — |
| Symptôme réel | « une page de confirmation est apparue » | texte exact de la page (confirmation ou refus ?) |

Le ticket ne permet pas de retrouver la réservation concernée. Le diagnostic repose donc sur une **reproduction contrôlée** du scénario décrit, et non sur la donnée d’origine.

## 2. Impact

- **Si le défaut E1 était réel** : un groupe pourrait confirmer un créneau déjà repris par un autre, d’où une double réservation (impact client et exploitation).
- **Impact constaté** : aucune donnée erronée. L’écran de refus est ambigu : un membre de l’accueil ou un client peut croire sa réservation enregistrée alors qu’elle ne l’est pas, et se présenter sans créneau. Le bouton « Libérer le créneau » d’une option expirée affiche aussi un message faux.
- **Utilisateurs concernés** : groupes qui confirment après 15 minutes ; accueil qui pose des options pour le compte de clients.
- **Hors impact** : règles E5 (game master) et E10 (checklist), espace de gestion.

## 3. Reproduction

Protocole suivi : qualifier → hypothèses → données de test → essais UI et HTTP → preuves BDD → conclusion → correction → réponse au testeur. Détail complet : [reproduction.md](../preuves/inc-tev-01/reproduction.md).

### Environnement de test

| Élément | Valeur |
|---|---|
| Code testé | commit `241987b` ; `src`, `templates`, `routes` non modifiés pendant les essais |
| PHP | 8.5.11, serveur intégré `php -S 127.0.0.1:8081` |
| MySQL | 8.4.13 (Docker `escapeops-db`) |
| Fuseau | `APP_TIMEZONE=Europe/Paris`, appliqué à PHP et à la connexion MySQL |
| Base | base de test locale, `composer db:reset`, données fictives (`*.test@example.test`) |

Preuve : [environnement-fuseaux.txt](../preuves/inc-tev-01/environnement-fuseaux.txt).

### Données de test

Sessions 9, 10 et 11 créées par le responsable via `POST /gestion/sessions`, plus la session 3 de la base. Options posées par l’application elle-même (formulaire public ou `POST /api/reservations`) : A (valide), B (expirée), D (autre groupe après B), E (troisième groupe), C1/C2/C3 (bornes à la seconde), R (attente réelle).

L’« onglet ouvert » est simulé avec `curl` et un fichier de cookies : `GET /reservations/{jeton}` (page avec bouton et jeton CSRF), attente, puis `POST /reservations/{jeton}/confirmer` avec **ce** cookie et **ce** jeton.

### Étapes exactes du scénario du ticket (essai R, sans aucune retouche SQL)

1. 09:23:02 : `POST /sessions/11/reserver` (formulaire public), salle 1. Réponse 303 vers la page de suivi « Option en cours, gardée jusqu’à 09 h 38 ». En base : `EN_ATTENTE`, `date_expiration = 09:38:02`.
2. Onglet laissé ouvert **15 min 20 s**, cookie et jeton CSRF conservés.
3. 09:38:23 : clic « Confirmer » (`POST /reservations/0438…/confirmer`).
4. Résultat : **HTTP 409**, sans redirection. La page « Ma réservation » se réaffiche avec l’état « Option expirée » et le bandeau « Votre option a expiré à 09 h 38 : la réservation n’est pas confirmée… ».
5. 09:38:24 : la ligne est toujours `EN_ATTENTE` (expirée, jamais `CONFIRMEE`) ; `GET /api/sessions` renvoie la session 11 `LIBRE`.

Preuve : [essai-R-attente-reelle.txt](../preuves/inc-tev-01/essai-R-attente-reelle.txt).

### Les 16 essais (résumé)

| # | Requête | Contexte | HTTP | BDD après | Preuve |
|---|---|---|---|---|---|
| A-a | `POST /reservations/…/confirmer` | option valide | 303 puis « Réservation confirmée » | `CONFIRMEE` | [essai A](../preuves/inc-tev-01/essai-A-option-valide.txt) |
| A-b | `POST /api/reservations/…/confirmation` | 2e confirmation | 409 `RESERVATION_CONFLICT` | inchangé | idem |
| B-a | `POST /reservations/…/confirmer` | onglet ancien, expirée d’1 min | 409 | `EN_ATTENTE` expirée | [essai B](../preuves/inc-tev-01/essai-B-option-expiree.txt) |
| B-b1 | `POST /api/reservations/…/confirmation` | HTTP direct | 409 `OPTION_EXPIRED` | inchangé | idem |
| B-b2 | formulaire forgé sans cookie ni `_csrf` | HTTP direct | 403 | inchangé | idem |
| B-b3 | formulaire forgé, `_csrf` valide d’un autre navigateur | HTTP direct | 409 | inchangé | idem |
| B-D | `POST /api/reservations` (groupe D) | après expiration de B | 201 | 1 seule réservation bloquante | [suite autre groupe](../preuves/inc-tev-01/essai-B-suite-autre-groupe.txt) |
| B-E | formulaire et API (groupe E) | pendant l’option de D | 409 / 409 `SLOT_UNAVAILABLE` | aucune ligne ajoutée | [groupe E refusé](../preuves/inc-tev-01/essai-B-suite-groupe-E-refuse.txt) |
| B-re | API, B retente | après la prise de D | 409 `OPTION_EXPIRED` | inchangé | [essai B](../preuves/inc-tev-01/essai-B-option-expiree.txt) |
| C1-a/b | formulaire puis API | `date_expiration = NOW()` (heure pile) | 409 / 409 | `EN_ATTENTE` expirée | [essai C](../preuves/inc-tev-01/essai-C-limite.txt) |
| C3-a/b | formulaire puis API | page chargée avant l’échéance, clic 3 s après | 409 / 409 | `EN_ATTENTE` expirée | idem |
| C2-a | formulaire | reste 5 s | 303 puis « Réservation confirmée » | `CONFIRMEE` | idem |
| C2-b | API | 2e confirmation | 409 | inchangé | idem |
| R | formulaire, onglet ouvert 15 min 20 s | **aucune modification SQL** | 409 | `EN_ATTENTE` expirée ; session `LIBRE` | [essai R](../preuves/inc-tev-01/essai-R-attente-reelle.txt) |

Pour les essais A à C, l’échéance a été avancée en base (`date_creation` et `date_expiration` décalées ensemble, dans un client `mysql` réglé sur `Europe/Paris`) ; les requêtes exactes figurent dans [reproduction.md](../preuves/inc-tev-01/reproduction.md#1-environnement-réel). L’essai R reproduit le scénario sans ce raccourci.

## 4. Diagnostic

### 4.1 Analyse statique du code

- **Une seule horloge** : `App\Support\SystemClock::now()` (`new DateTimeImmutable('now')`), c’est-à-dire l’heure du serveur PHP, en `Europe/Paris`. L’heure du navigateur n’est **jamais lue** : aucun champ de formulaire ni en-tête ne transmet une heure au serveur. L’avance de 2 à 3 minutes du PC ne peut donc influencer ni la pose, ni la confirmation.
- **Comparaison** : `ReservationOption::isActive()` renvoie `date_expiration > now`, calculé en PHP. À l’heure pile, l’option est expirée (borne exclue).
- **Fuseau** : `DatabaseFactory` exécute `SET time_zone = 'Europe/Paris'` à l’ouverture de la connexion MySQL. Les valeurs stockées sont en heure de Paris ; seul un client `mysql` lancé à la main reste en UTC (piège connu, noté dans `docs/journal.md`).
- **Un seul chemin métier** : le formulaire (`ReservationController::confirm`) et l’API (`ReservationApiController::confirm`) appellent tous deux `ReservationService::confirm()`. Ce service travaille sous **transaction**, avec un verrou (`SELECT … FOR UPDATE`) sur la ligne de la session, puis calcule l’état de l’option : refus `EXPIREE`, `CONFIRMEE` ou `ANNULEE`, puis contrôle de la disponibilité de la session.
- **CSRF** : tout `POST` de formulaire sans jeton valide est rejeté en 403 par `CsrfMiddleware`, avant même le service.

### 4.2 Hypothèses → verdict → preuve

| # | Hypothèse | Verdict | Preuve |
|---|---|---|---|
| H1 | Une option expirée peut être confirmée depuis un onglet resté ouvert | **Infirmée** | essais B-a, C1-a, C3-a, R (409, base inchangée) ; test automatisé § 7 |
| H2 | Une option expirée peut être confirmée par une requête HTTP directe (API) | **Infirmée** | B-b1, B-re, C1-b, C3-b (409 `OPTION_EXPIRED`) |
| H3 | Un formulaire forgé contourne le contrôle | **Infirmée** | B-b2 (403 sans CSRF), B-b3 (409 avec un CSRF valide) |
| H4 | L’horloge du PC en avance de 2-3 min fausse le calcul | **Infirmée** | analyse statique § 4.1 (heure serveur seule) ; test automatisé (heure du navigateur ignorée) |
| H5 | Décalage de fuseau entre PHP et MySQL (expiration décalée d’1 h) | **Infirmée pour l’application** | `SET time_zone` dans `DatabaseFactory` ; valeur stockée relevée en heure de Paris ([environnement-fuseaux.txt](../preuves/inc-tev-01/environnement-fuseaux.txt)) |
| H6 | L’option expirée bloque encore le créneau (ou, au contraire, « libre » sur le téléphone est une erreur) | **Infirmée** : le créneau libre est le comportement attendu | planning « Disponible », API `LIBRE`, groupe D accepté en 201 ([suite autre groupe](../preuves/inc-tev-01/essai-B-suite-autre-groupe.txt)) |
| H7 | Double réservation possible sur une même session | **Infirmée dans les essais** | B-E (409), requête « plus d’une réservation bloquante par session » : aucune ligne |
| H8 | Les microcoupures 4G provoquent un double envoi ou une page incomplète | **Non reproduite** (pas de simulation réseau) ; un double envoi est sans effet | A-b et C2-b : 2e confirmation refusée en 409, une seule ligne confirmée |
| H9 | La page affichée après le clic était un refus qui ressemble à une confirmation | **Confirmée** : cause probable du signalement | B-a et R : même titre, même récapitulatif, même code de suivi ; refus visible seulement dans un bandeau ([reproduction.md § 5](../preuves/inc-tev-01/reproduction.md#5-conclusion)) |
| H10 | Le bouton « Libérer le créneau » d’une option expirée affiche un message faux | **Confirmée** (défaut secondaire) | 303 puis « le créneau est de nouveau proposé aux autres groupes » alors que D le tient (API `EN_OPTION`) ([affichages annexes](../preuves/inc-tev-01/essai-B-affichages-annexes.txt)) |
| H11 | Onglet ouvert plus de 24 min : session PHP effacée, comportement imprévu | **Non reproduite** (ramasse-miettes aléatoire) ; effet attendu : 403, pas une confirmation | B-b2 (403 sans session) ; `session.gc_maxlifetime = 1440 s` |
| H12 | Concurrence : deux requêtes simultanées confirment une option à la limite, ou pendant une reprise du créneau | **Théorique, non prouvée** | lecture du code : `updateStatus()` fait un `UPDATE … WHERE id_reservation = ?` non conditionnel ; la relecture de la réservation dans `lockedReservation()` n’est pas verrouillée. Le verrou sur la session sérialise déjà les écritures qui le prennent : aucun essai n’a produit d’incohérence. |

### 4.3 Conclusion du diagnostic

**Défaut E1 non reproduit.** Sur 16 essais (interface avec onglet ancien, API, formulaires forgés, bornes à la seconde, attente réelle de 15 min 20 s), une option expirée n’est **jamais** confirmée : refus 409 (403 sans CSRF), ligne inchangée en base. Elle ne bloque plus le créneau, un autre groupe peut le reprendre (201), et une option valide se confirme, y compris 5 s avant l’échéance.

Ces essais montrent que les protections fonctionnent dans les conditions testées. Ils ne prouvent pas une sécurité absolue : le cas de concurrence (H12) et la session expirée (H11) n’ont pas été reproduits.

## 5. Cause racine

Il n’y a **pas de défaut métier** sur E1. Le symptôme s’explique par le comportement attendu, rendu ambigu par l’interface :

1. **L’option de la testeuse avait expiré.** Plus d’un quart d’heure s’était écoulé entre la pose et le clic : le serveur a refusé la confirmation (409), rien n’a été enregistré.
2. **La page de refus ressemble à une page de confirmation** (cause probable du signalement). En cas de refus, `ReservationController::confirm` ne redirige pas : il réaffiche la page « Ma réservation · Le Cabinet des Ombres », avec le même titre d’onglet, le même titre, le récapitulatif du groupe et le code de suivi. Le refus n’apparaît que dans un bandeau et dans le badge « Option expirée ». Le message est répété deux fois avec des mots voisins. Il ne dit pas si le créneau est encore libre, alors que l’application le sait.
3. **Le créneau était libre sur le téléphone parce que l’option avait expiré** : c’est conforme à E1.
4. **Défaut secondaire** : `peut_annuler` reste vrai pour une option expirée. Le bouton « Libérer le créneau » reste donc affiché, et son message (« le créneau est de nouveau proposé aux autres groupes ») est faux quand un autre groupe tient le créneau. Les données restent correctes : l’option de D n’est pas touchée.

## 6. Correction

Trois points sont retenus :

| # | Point | Nature |
|---|---|---|
| C1 | Page de refus sans ambiguïté : titre « Réservation non enregistrée : option expirée », un seul message, état réel du créneau (libre ou repris) | défaut d’affichage confirmé (H9) |
| C2 | Plus de bouton « Libérer le créneau » sur une option expirée ; message d’annulation exact | défaut d’affichage confirmé (H10) |
| C3 | Durcissement : `UPDATE` de confirmation conditionnel (`statut = 'EN_ATTENTE'` et `date_expiration > maintenant`, contrôle du nombre de lignes modifiées) et relecture de la réservation sous verrou | prévention du risque théorique H12 |

### Diff résumé par fichier (`starter-kit/`, branche `fix/inc-tev-01`)

| Fichier | Méthode | Changement | Point |
|---|---|---|---|
| `src/Controller/ReservationController.php` | `refusalTitle()` (nouvelle) | titre d’onglet et de page selon le refus : « Réservation non enregistrée : option expirée », « Réservation déjà confirmée », « Réservation non enregistrée : réservation annulée », « Annulation refusée : option expirée »… | C1, C2 |
| | `confirm()`, `cancel()`, `renderShow()` | en cas de 409, la page reçoit ce titre (`titreRefus`) ; en-têtes `no-store` / `no-referrer` inchangés | C1, C2 |
| `templates/reservations/show.php` | — | le titre de refus remplace le nom de la salle dans `<title>` et `<h1>` ; le message d’erreur passe **en tête**, avant le récapitulatif ; un seul message (plus de doublon avec le paragraphe « Votre option a expiré ») ; disponibilité réelle du créneau, avec « Poser une nouvelle option » s’il est libre, sinon « Voir les autres créneaux » | C1 |
| `src/Service/ReservationService.php` | `byToken()` | `peut_annuler` faux pour une option **expirée** (plus de bouton « Libérer le créneau ») ; nouveau champ `creneau` pour une option expirée | C2, C1 |
| | `slotAfterExpiry()` (nouvelle) | état du créneau après l’expiration, par le même calcul E1 (`SlotAvailability::of`) : libre, repris en option jusqu’à HH h MM, réservé, ou fermé | C1 |
| | `confirm()` | message unique « Votre option a expiré à HH h MM : la réservation n’a pas été enregistrée. » ; session relue **sous verrou** ; écriture par `changeStatusIf(EN_ATTENTE → CONFIRMEE, maintenant)`, refus `CONFLIT` si 0 ligne | C1, C3 |
| | `cancel()` | option expirée refusée en **409** (« il n’y avait rien à libérer ») au lieu d’être passée à `ANNULEE` ; écriture conditionnelle sur le statut lu | C2, C3 |
| | `lockedReservation()`, `concurrentChange()` (nouvelle) | première lecture de la réservation en `FOR UPDATE` (plus de relecture non verrouillée, qui sous REPEATABLE READ lisait un instantané) ; message de conflit « rien n’a été enregistré » | C3 |
| `src/Repository/ReservationRepository.php` | `findByToken($token, $lock)` | paramètre `$lock` : `SELECT … FOR UPDATE` sous MySQL (SQLite verrouille déjà toute la base) | C3 |
| | `changeStatusIf()` (remplace `updateStatus()`) | `UPDATE reservation SET statut = :vers WHERE id_reservation = :id AND statut = :depuis [AND date_expiration > :maintenant]`, renvoie `rowCount() === 1` | C3 |
| `src/Domain/ReservationStateException.php` | constante `CONFLIT` | état « ligne modifiée entre la lecture et l’écriture » | C3 |
| `composer.json` | `scripts.test` | ajout de `php tests/incident-inc-tev-01.php` | tests |
| `src/Domain/SessionStatus.php` | commentaire | commentaire périmé (« Demain : PRETE exigera… ») remis à jour, sans effet sur le code | — |

Choix retenu pour C2 : une option expirée n’est **pas** annulée en silence. La passer à `ANNULEE` effacerait de l’historique qu’elle a expiré (« Mes réservations » afficherait « réservation annulée ») et laisserait croire au groupe qu’il a libéré un créneau qu’un autre tient peut-être déjà. Le serveur répond donc 409, même à un POST forgé.

### Tests rouge → vert

Les nouveaux tests ont été lancés sur l’ancien code (commit `241987b`, extrait dans un dossier temporaire avec les fichiers de test actuels), puis sur le code corrigé :

| Fichier | Avant correction (`241987b`) | Après correction |
|---|---|---|
| `tests/incident-inc-tev-01.php` | **33 échecs** sur 92 vérifications : `33 échec(s) INC-TEV-01.` | 92 vertes : `Tous les tests INC-TEV-01 passent.` |
| `tests/reservations.php` | **4 échecs** sur 50 : `4 échec(s) de réservation (US-23 et E1).` | 50 vertes : `Tous les tests de réservation (US-23 et E1) passent.` |

Les 33 échecs de l’ancien code, par point :

- **C1** (11) : titre d’onglet et de page, message unique et placé avant le récapitulatif, plus de phrase « Si le créneau est encore libre », disponibilité réelle (libre, « repris par un autre groupe » jusqu’à 14 h 31, « réservé par un autre groupe »), lien « Poser une nouvelle option » ;
- **C2** (7) : bouton « Libérer le créneau » encore affiché, `peut_annuler` vrai, annulation forgée acceptée (303) au lieu de 409, ligne passée à `ANNULEE` ;
- **effets en cascade de C2** (3) : l’option expirée ayant été passée à `ANNULEE`, elle n’est plus `EN_ATTENTE` dans le planning, « Mes réservations » n’affiche plus « Option expirée sans confirmation », et la confirmation suivante est refusée pour « annulée » au lieu d’« expirée » ;
- **C3** (12) : les 3 courses simulées (annulation, échéance dépassée, confirmation entre la lecture et l’écriture) laissent passer l’écriture ; `changeStatusIf()` n’existe pas.

### `composer test` après correction

Lancé depuis `starter-kit/` le 26/11 : **15 suites vertes, 0 échec** (522 vérifications ✔ et 4 scénarios du contrat JSON), code de sortie 0. Dernière ligne :

```text
Tous les tests INC-TEV-01 passent.
```

Vérification sur MySQL 8.4 après correction : conforme sur les 6 scénarios (confirmation valide, option expirée par l'interface et l'API, créneau repris sans bouton « Libérer », annulation forgée refusée, double confirmation, deux confirmations simultanées → une seule `CONFIRMEE`), sans erreur SQL ni interblocage : voir [apres-correction.md](../preuves/inc-tev-01/apres-correction.md).

## 7. Test de non-régression

### Test de l’incident

[`starter-kit/tests/incident-inc-tev-01.php`](../../starter-kit/tests/incident-inc-tev-01.php), lancé par `composer test`. Il utilise la vraie application Slim (routes web et API, middleware CSRF), les vrais services et une base SQLite de test, avec une **horloge figée** (`FixedClock`). Ses 92 vérifications couvrent :

- l’heure de référence : celle du serveur, jamais celle du navigateur ;
- le scénario du ticket : onglet ouvert à 14 h 00, clic à 14 h 16. Refus 409 sans redirection, message « la réservation n’est pas confirmée », badge « Option expirée », aucune mention « Réservation confirmée », plus de bouton « Confirmer », base inchangée, `Cache-Control: no-store` ;
- le refus par le service, par l’API (409 `OPTION_EXPIRED`) et par un HTTP direct sans CSRF (403) ;
- le créneau libéré partout : service, vitrine `/salles/1`, `GET /api/sessions`, planning ; reprise par un autre groupe (201), pas de double réservation ;
- les bornes : 1 s avant (confirmable), heure pile et 1 s après (refusée), fractions de seconde ; double clic refusé ;
- les autres refus : option libérée par le groupe, session annulée pendant l’option.

### Vérifications ajoutées par la correction

| Point | Fichier | Vérifications |
|---|---|---|
| C1 | `tests/incident-inc-tev-01.php` § 1 | titre d’onglet et `<h1>` « Réservation non enregistrée : option expirée » ; message unique avec l’heure, placé avant le récapitulatif, sans répétition ni phrase conditionnelle ; disponibilité réelle calculée par le serveur : libre (lien « Poser une nouvelle option »), « repris par un autre groupe » jusqu’à l’échéance de son option, « réservé par un autre groupe » ; lien consulté sans clic : un seul message |
| C1 | `tests/reservations.php` | `INC-TEV-01 page de suivi : disponibilité réelle du créneau (libre)` (`byToken()['creneau']`) |
| C2 | `tests/incident-inc-tev-01.php` § 1 et § 4 | ni « Confirmer » ni « Libérer le créneau » sur une option expirée ; `peut_annuler = false` ; annulation forgée → 409 sans redirection, titre « Annulation refusée : option expirée », base inchangée ; refus `EXPIREE` par le service ; une réservation **confirmée** reste annulable (303) |
| C2 | `tests/reservations.php` | `INC-TEV-01 option expirée : plus de « Libérer le créneau »` · `INC-TEV-01 option expirée : annulation refusée, ligne inchangée` |
| C3 | `tests/incident-inc-tev-01.php` § 4 | une horloge de test (`beforeNextCall`) modifie la ligne entre la lecture et l’écriture : option annulée, échéance dépassée, ou option confirmée pendant un « Libérer » → refus `CONFLIT`, rien d’écrit ; le cas normal passe ; `changeStatusIf()` : 0 ligne si le statut attendu diffère ou si l’option a expiré, 1 ligne sinon ; lecture verrouillée = même ligne |

Sortie rouge → vert : voir § 6 (33 → 0 échec pour `incident-inc-tev-01.php`, 4 → 0 pour `reservations.php`).

### Non-régression des règles E1, E5 et E10

La correction touche le service de réservation : les trois règles contractuelles doivent rester vertes.

| Règle | Fichiers de test | Résultat |
|---|---|---|
| E1 — option de 15 minutes | `tests/slot-availability.php`, `tests/reservations.php`, `tests/api.php`, `tests/incident-inc-tev-01.php` | ✅ vert |
| E5 — un game master ne se dédouble pas | `tests/game-master-conflicts.php`, `tests/game-master-accounts.php` | ✅ vert |
| E10 — checklist avant ouverture | `tests/checklist.php`, `tests/evolution-ev-tev-01.php` | ✅ vert |

Sortie de `composer test` après correction (dernière ligne de chaque suite, 0 échec) :

```text
Contrat JsonInput : 4 scénarios verts.
Tous les tests de disponibilité passent.
Tous les tests passent.
Tous les tests du planning passent.
Tous les tests de réservation (US-23 et E1) passent.
Tous les tests E5 (game master unique) passent.
Tous les tests E10 (checklist d'ouverture) passent.
Tous les tests de l'évolution EV-TEV-01 passent.
Tous les tests d'ajout de game master passent.
Tous les tests de l'accès client « Mes réservations » passent.
Tous les tests des salles et équipements passent.
Tous les tests des incidents et du tableau de bord passent.
Tous les tests de l'API passent.
Tous les tests du SQL avancé passent.
Tous les tests INC-TEV-01 passent.
```

Vérification sur MySQL 8.4 après correction : conforme sur les 6 scénarios (confirmation valide, option expirée par l'interface et l'API, créneau repris sans bouton « Libérer », annulation forgée refusée, double confirmation, deux confirmations simultanées → une seule `CONFIRMEE`), sans erreur SQL ni interblocage : voir [apres-correction.md](../preuves/inc-tev-01/apres-correction.md).

## Risques résiduels (complément au modèle)

| Risque | Description | Conséquence possible | Suite proposée |
|---|---|---|---|
| Troncature à la seconde | `date_expiration` est un `DATETIME` sans fraction : une pose à 15 h 00 min 00,7 s expire à 15 h 15 min 00 s, soit jusqu’à une seconde plus tôt que 15 minutes exactes | aucune, sinon un écart inférieur à 1 s (couvert par le test) | accepté, documenté |
| Pas de `Cache-Control` sur la vitrine | `/salles/{id}` n’envoie pas `no-store`, contrairement aux pages de suivi et à l’API | un téléphone peut afficher un planning périmé (créneau vu libre ou en option à tort) | ajouter un `Cache-Control` sur la vitrine (nouvelle issue) |
| Expiration jamais écrite en base | une option expirée reste `EN_ATTENTE` pour toujours : l’état est recalculé à chaque requête | une requête ou un écran futur qui filtre sur `statut = 'EN_ATTENTE'` sans comparer `date_expiration` à l’heure de Paris afficherait à tort une option en cours | règle à rappeler dans la documentation SQL ; purge ou marquage éventuel par tâche planifiée |
| Session PHP de 24 min | au-delà de `session.gc_maxlifetime = 1440 s`, la session peut être effacée : le clic donne un 403 « Le formulaire a expiré ou ne provient pas de ce site » | message générique, moins clair que le refus métier ; aucune confirmation possible | message 403 à reformuler pour l’accueil (amélioration) |
| Concurrence (H12) | risque théorique non prouvé | — | traité par C3 : écriture conditionnelle (`changeStatusIf`, contrôle de `rowCount`) et relecture en `FOR UPDATE`, couverts par 12 vérifications (§ 7) |
| Microcoupures réseau | non simulées | page non reçue : l’utilisateur ne sait pas si le clic est parti | la page de suivi (lien secret) donne l’état réel ; à rappeler à l’accueil |

## 8. Commits / issue

- Issue : #13 du dépôt de l’enseignant (ticket INC-TEV-01), et issue de suivi dans `TevaLT/Escapeops` : *À COMPLÉTER : numéro* (texte : [INC-TEV-01-issue-suivi.md](INC-TEV-01-issue-suivi.md))
- Commit : *À COMPLÉTER : hash* (correction C1 à C3, test de non-régression, rapport), branche `fix/inc-tev-01`
- Code testé lors de la reproduction : `241987b`
- Réponse à la testeuse : [INC-TEV-01-reponse-testeur.md](INC-TEV-01-reponse-testeur.md)
- Preuves : [docs/preuves/inc-tev-01/](../preuves/inc-tev-01/reproduction.md)

*Aucun cookie, jeton complet, code de suivi ni donnée personnelle réelle n’est publié : jetons tronqués à 4 caractères, codes masqués, adresses fictives `*.test@example.test`.*

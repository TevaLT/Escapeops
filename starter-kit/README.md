# EscapeOps — application

Outil de réservation et d'exploitation pour **Les Archives Obscures**, un escape game de 3 salles (client fictif).
Stack : PHP 8.2+, Slim 4, Medoo/PDO, MySQL 8.4.

**Règles contractuelles :** E1 (option de 15 min) · E5 (un game master ne peut pas se dédoubler) · E10 (checklist avant ouverture)
**Extensions :** E2, E6, E9 · voir [BACKLOG.md](../BACKLOG.md) et [CAHIER_DES_CHARGES.md](../CAHIER_DES_CHARGES.md)

```text
HTTP → Slim (routes) → Controller → Service (règles métier) → Repository → Medoo/PDO → MySQL
                        ├── HTML : templates/
                        └── JSON : /api (contrat : docs/API.md)
```

## Installation

Prérequis : PHP 8.2+ avec `pdo_mysql` et `mbstring`, Composer, MySQL 8 (ou Docker).

```bash
# 1. Dépendances
composer install

# 2. Configuration
cp .env.example .env          # PowerShell : Copy-Item .env.example .env

# 3. Base MySQL (avec Docker)
docker run -d --name escapeops-db -p 127.0.0.1:3306:3306 \
  -e MYSQL_ROOT_PASSWORD=rootpass -e MYSQL_DATABASE=escapeops \
  -e MYSQL_USER=escapeops -e MYSQL_PASSWORD=escapeops \
  -v escapeops-data:/var/lib/mysql mysql:8.4

# 4. Schéma + données de test (efface et reconstruit la base)
composer db:reset

# 5. Serveur
composer serve
```

Puis ouvrir http://127.0.0.1:8080/.

Sous **PowerShell**, la commande Docker de l'étape 3 s'écrit avec des `` ` `` en fin de ligne au lieu des `\` :

```powershell
docker run -d --name escapeops-db -p 127.0.0.1:3306:3306 `
  -e MYSQL_ROOT_PASSWORD=rootpass -e MYSQL_DATABASE=escapeops `
  -e MYSQL_USER=escapeops -e MYSQL_PASSWORD=escapeops `
  -v escapeops-data:/var/lib/mysql mysql:8.4
```

Si le conteneur existe déjà, il suffit de le relancer : `docker start escapeops-db`.

> ⚠ Ces mots de passe (`rootpass`, `escapeops`) et ceux des comptes de test sont des **identifiants de développement**, fictifs, publiés pour la recette. Ne jamais les réutiliser en production. Les vrais paramètres se trouvent dans `.env`, ignoré par Git. `-p 127.0.0.1:3306:3306` limite l'accès à MySQL à la machine locale (avec `-p 3306:3306`, MySQL serait joignable depuis tout le réseau).

## Base de données

Les scripts sont versionnés et appliqués **dans l'ordre** par `composer db:reset` (`bin/db-reset.php`) :

| Fichier | Contenu |
|---|---|
| `database/migrations/001_schema.sql` | Tables, clés, contraintes `CHECK` (issues du MCD) |
| `database/migrations/002_vues.sql` | *(à venir)* `v_planning_detaille`, `v_dashboard_exploitation` |
| `database/migrations/003_procedures.sql` | *(à venir)* `sp_bilan_salle` |
| `database/migrations/004_triggers.sql` | *(à venir)* `trg_session_history` |
| `database/seed.sql` | Données fictives, avec des dates relatives au jour courant |

Les scripts n'utilisent pas `DELIMITER` : chaque fichier est envoyé en une seule fois au serveur via PDO.

Le fuseau `APP_TIMEZONE` (Europe/Paris) est appliqué à PHP **et** à la connexion MySQL, car les règles E1 et E5 comparent des heures.

## Comptes de test

Mot de passe commun : `EscapeOps2026!`

| Rôle | E-mail |
|---|---|
| Administrateur | admin@escapeops.test |
| Responsable | responsable@escapeops.test |
| Game master | camille@escapeops.test |
| Game master | david@escapeops.test |

Connexion : http://127.0.0.1:8080/connexion. Un game master accède à `/espace` mais reçoit un **403** sur `/gestion`.

**Client (sans compte)** : « Mes réservations » (http://127.0.0.1:8080/mes-reservations) avec, par exemple, `martin@example.test` + code `MART-6789`, ou `06 00 00 00 01` + `CURX-4567` ; `team.enigme@example.test` + `TEAM-2345` montre une partie jouée dans l'historique. Chaque nouvelle option reçoit son propre code, affiché sur sa page de suivi.

## Routes disponibles

**Public (sans compte)**

| Route | Description |
|---|---|
| `GET /` | Vitrine : les 3 salles |
| `GET /salles/{id}` | Fiche salle et créneaux à venir, avec leur disponibilité et un bouton « Réserver » sur les créneaux libres |
| `GET /sessions/{id}/reserver` · `POST` | Poser une option de 15 minutes pour un groupe (US-23, E1) : 409 si le créneau est pris, en option, passé ou annulé ; 422 si les données sont invalides. Succès → redirection vers la page de suivi |
| `GET /reservations/{jeton}` | Page de suivi de la réservation (lien secret du groupe, 32 caractères hexadécimaux) : option en cours jusqu'à HH h MM, expirée, confirmée ou annulée |
| `POST /reservations/{jeton}/confirmer` | Confirmer l'option (E1) : 409 si elle a expiré (US-33), si elle est déjà confirmée ou annulée, ou si la session a été annulée entre-temps |
| `POST /reservations/{jeton}/annuler` | Libérer l'option ou annuler la réservation tant que la partie n'a pas commencé (409 sinon) |
| `GET /mes-reservations` · `POST /mes-reservations/connexion` · `POST /mes-reservations/deconnexion` | « Mes réservations » : le client, **sans compte**, saisit son contact (e-mail ou téléphone) et le **code de suivi** reçu à la pose de l'option, puis voit ses parties à venir et l'historique de ses parties (jouée, annulée, option expirée…) avec le nombre de parties jouées. Même message si le contact ou le code est faux (422), 429 après 5 échecs en 15 min. Aucun droit sur `/espace` ni `/gestion` |
| `GET /connexion` · `POST /connexion` · `POST /deconnexion` | Connexion et déconnexion du personnel |

**Personnel connecté** (`/espace`, tous les rôles)

| Route | Description |
|---|---|
| `GET /espace` | Accueil : mes sessions à animer |
| `GET /espace/sessions/{id}` | Fiche session : détail, réservations (option et son échéance), checklist d'ouverture et son historique, boutons de changement d'état autorisés |
| `POST /espace/sessions/{id}/checklist` | Checklist d'ouverture (E10), game master affecté uniquement (403 sinon) : 3/3 → la session passe à « Prête » ; 2/3 → enregistrée, mais 422 « salle non prête » |
| `POST /espace/sessions/{id}/etat` | Changer l'état (US-27) : le game master affecté démarre, termine ; seul le responsable annule. `PRETE` sans checklist 3/3 (POST forgé) → 422 ; `EN_COURS` sans game master → 422 (E5) |
| `GET /espace/incidents` · `GET /espace/incidents/nouveau` · `POST /espace/incidents` | Incidents : liste, déclaration (US-26) |

**Gestion** (`/gestion`, responsable et admin ; 403 pour un game master)

| Route | Description |
|---|---|
| `GET /gestion` | Tableau de bord (US-28) : état des salles, prochaines sessions, incidents ouverts |
| `GET /gestion/sessions?salle=&date=&etat=` | Planning filtrable (US-22, US-29), « aucun résultat » si rien ne correspond |
| `GET /gestion/sessions/nouvelle` · `POST /gestion/sessions` | Créer une session : salle + début (la fin est calculée), game master facultatif ; 422 si le game master anime déjà une session qui chevauche (E5) |
| `GET /gestion/sessions/{id}/modifier` · `POST /gestion/sessions/{id}` | Modifier une session encore « prévue » (salle, début, game master) |
| `POST /gestion/sessions/{id}/game-master` | Affecter, remplacer ou retirer le game master depuis la fiche, tant que la session est « prévue » ou « prête » (E5). Retirer l'unique game master d'une session prête la repasse à « prévue » (évolution EV-TEV-01) |
| `GET /gestion/salles` · `/nouvelle` · `/{id}` · `/{id}/modifier` · `POST /{id}/supprimer` | Salles (US-21) ; une salle qui a des sessions ou des incidents n'est pas supprimée |
| `POST /gestion/salles/{id}/equipements` · `POST …/equipements/{eq}/retirer` | Affecter ou retirer un équipement (US-25, relation N–N) |
| `GET /gestion/equipements` · `POST` | Catalogue des équipements, avec l'indicateur « critique » |
| `GET /gestion/game-masters` · `/{id}` | Fiches game master et leurs sessions (US-24) |
| `GET /gestion/game-masters/nouveau` · `POST /gestion/game-masters` | Ajouter un game master : prénom, nom, e-mail (identifiant), mot de passe initial (10 caractères minimum, lettre et chiffre, confirmé). Le rôle est toujours `GAME_MASTER` : un champ `role` forgé est ignoré. 422 si e-mail déjà utilisé ou champ invalide, 403 pour un game master |
| `POST /gestion/incidents/{id}/resoudre` | Résoudre un incident (US-26) |

**API JSON** : `GET /api/health`, `GET /api/rooms`, `GET /api/rooms/{id}` (contrat : [docs/API.md](docs/API.md)).

**Qui fait quoi** (cahier des charges, § 3) : le service vérifie chaque droit, même si la route est déjà protégée.

| Action | Game master | Responsable | Admin |
|---|---|---|---|
| Remplir la checklist d'ouverture (E10) | ✅ la sienne | ❌ | ❌ |
| Démarrer, terminer une session | ✅ la sienne | ✅ | ✅ |
| Créer, modifier, annuler une session, affecter un game master | ❌ | ✅ | ✅ |
| Déclarer un incident | ✅ | ✅ | ✅ |
| Résoudre un incident, tableau de bord | ❌ | ✅ | ✅ |
| Gérer les salles et les équipements | ❌ | ✅ | ✅ |
| Ajouter un game master (compte `GAME_MASTER` uniquement) | ❌ | ✅ | ✅ |

## Démontrer les règles contractuelles

Après `composer db:reset` (les dates du jeu de données sont relatives au jour courant) :

| Règle | Succès | Refus |
|---|---|---|
| **E1** option 15 min | `/salles/1` → « Réserver » sur un créneau libre → formulaire → page de suivi « Option en cours jusqu'à … » → « Confirmer » | Dans un autre navigateur, le même créneau affiche « en option jusqu'à … » (409 si on force l'envoi). L'option du seed (`/reservations/e5ca9e0900000000000000000000a003`, session 5) expire 10 min après le reset : la confirmer ensuite donne « Votre option a expiré à … » (409) et le créneau redevient réservable |
| **E5** game master unique | Responsable : nouvelle session demain 15 h 15 en salle 1 avec David (jointive avec sa session de 14 h – 15 h 15) → acceptée | Fiche de la session 6 (salle 3, 18 h 30) : affecter Camille, qui anime la session 2 à 18 h → « Camille Leroy anime déjà … ». Fiche de la session 7 (prête) : retirer David → elle repasse à « Prévue », bandeau « Personnel manquant » ; un POST forgé `etat=EN_COURS` → « aucun game master affecté » (422) |
| **EV-TEV-01** checklist + game master | Réaffecter David à la session 7, qui revalide la checklist 3/3 → « Prête » | Session 7 sans game master, checklist 3/3 déjà enregistrée : POST forgé `etat=PRETE` → « Une checklist ne remplace pas un animateur » (422). Détail et scénario avant/après : [docs/evolutions/EV-TEV-01.md](docs/evolutions/EV-TEV-01.md) |
| **E10** checklist | Camille, fiche de la session 2 : cocher les 3 points → « Prête » | Avec 2 cases sur 3 : « Salle non prête : checklist 2/3 … », la session reste « Prévue ». Un POST forgé `etat=PRETE` est aussi refusé (422) |

Pour E1, le délai est contrôlé à chaque requête (pas de tâche planifiée) : en démonstration, on peut attendre l'expiration de l'option du seed, ou avancer l'heure avec `FixedClock` dans les tests.

## Sécurité (épic 1)

- Mots de passe : `password_hash` / `password_verify` (`AuthService`), rehash automatique si l'algorithme évolue ; même message d'erreur et même temps de réponse qu'un e-mail existe ou non.
- `session_regenerate_id(true)` à chaque connexion (contre la fixation de session) ; cookie `escapeops_session` HttpOnly, SameSite=Lax, Secure sous HTTPS, `use_strict_mode`.
- CSRF : tout `POST`/`PUT`/`PATCH`/`DELETE` HTML exige le champ `_csrf` (ou l'en-tête `X-CSRF-Token`), sinon 403. Dans une vue : `<?= $csrf->field() ?>`. L'API `/api` en est exclue car elle n'accepte que du `application/json` (415 sinon).
- Droits : une route se protège avec `->add(new RequireRole($auth, $renderer, ...Authorization::PLANNING_MANAGERS))`. **Les services revérifient** avant chaque écriture sensible : `Authorization::denyUnlessCanManagePlanning($user)` lève `AccessDeniedException` (→ 403).

## Tests

```bash
composer test
```

- `tests/http-input.php` : contrat d'entrée JSON (415 / 400).
- `tests/slot-availability.php` : disponibilité d'un créneau (session passée, annulée, confirmée, option active ou expirée pour E1). Il utilise `FixedClock` pour simuler l'heure.
- `tests/auth-roles.php` : connexion (régénération de session), middleware de rôle (302 / 403 / 401 API), CSRF (jeton absent, faux, tableau → 403) et invariant US-13 (un game master ne modifie pas le planning). Utilise `ArraySession`, sans navigateur.
- `tests/sessions.php` : planning (US-22) : date invalide ou passée, salle inconnue, fin calculée, salle déjà occupée (remise en état comprise, créneaux jointifs acceptés), modification d'une session non « prévue » refusée, et refus du game master **par le service** (US-13). Le vrai `SessionService` tourne sur une base SQLite en mémoire. Aussi : filtres du planning (US-29) et cycle d'états (US-27 : passages interdits, game master non affecté refusé, annulation réservée au responsable, groupe « à recontacter »).
- `tests/reservations.php` : réservation (US-23) et **E1** (US-30 à US-34) avec `FixedClock` : option enregistrée `EN_ATTENTE` avec `expires_at = maintenant + 15 min` ; groupe B refusé pendant l'option (encore à 14 min 59 s) avec l'heure de fin dans le message ; confirmation dans le délai acceptée ; à 15 min pile l'option a expiré, la confirmation est refusée et reste `EN_ATTENTE` ; B peut alors poser son option ; option libérée par le groupe ; session annulée pendant l'option. Aussi : session passée ou annulée, capacité, contact, texte non UTF-8.
- `tests/game-master-conflicts.php` : **E5** (US-35 à US-38) : chevauchement `a.début < b.fin ET b.début < a.fin`, créneaux jointifs acceptés dans les deux sens, session sans game master, game master inconnu ou qui n'en est pas un, déplacement qui crée un conflit, session annulée qui libère le game master, affectation depuis la fiche, démarrage sans game master refusé.
- `tests/checklist.php` : **E10** (US-39 à US-42) : seul le game master affecté remplit la checklist ; 2/3 enregistrée mais « salle non prête » ; POST forgé `etat=PRETE` refusé (même par l'admin) ; 3/3 → `PRETE`, avec le GM, la date et les 3 valeurs ; historique du plus récent au plus ancien.
- `tests/evolution-ev-tev-01.php` : **évolution EV-TEV-01** (issue #8) : checklist 3/3 sans game master → `PRETE` refusé ; game master affecté sans conflit → accepté, en conflit → refusé ; session prête qui perd son unique game master → `PREVUE`, réservations intactes, puis nouveau GM qui revalide ; remplacement d'un GM → reste `PRETE` ; session `EN_COURS` jamais modifiée.
- `tests/game-master-accounts.php` : ajout d'un game master : refus pour un game master ou un anonyme, champs obligatoires, e-mail invalide ou déjà pris (même en majuscules), mot de passe trop court ou sans chiffre, confirmation différente ; rôle forcé à `GAME_MASTER`, e-mail en minuscules, mot de passe haché ; le nouveau compte se connecte.
- `tests/client-access.php` : « Mes réservations » : code de suivi (format, alphabet sans caractères ambigus), contact normalisé (casse de l'e-mail, espaces et points du téléphone) ; refus si le contact ou le code est faux, avec le même message ; le client ne voit que ses réservations ; accès séparé du personnel ; « Quitter » ; blocage après 5 échecs pendant 15 min ; historique des parties (à venir / passées, résultat de chaque partie, compteur de parties jouées).
- `tests/rooms-equipment.php` : salles (US-21 : champs, capacités, nom unique, suppression refusée si la salle est utilisée) et équipements (US-25 : même équipement dans deux salles, critique, doublon refusé, retrait).
- `tests/incidents-dashboard.php` : incidents (US-26 : déclarant pris dans la session, équipement et session cohérents avec la salle, résolution réservée), fiches game master (US-24), tableau de bord (US-28 : salle bloquée, en partie, disponible).

Les tests métier utilisent `tests/support/bootstrap.php` : une base SQLite en mémoire au schéma équivalent à `001_schema.sql`, recréée pour chaque fichier. Les vrais services et repositories tournent dessus, sans MySQL.

La CI GitHub Actions (`.github/workflows/starter-kit-check.yml`) lance les tests, reconstruit la base et vérifie les routes.

## Organisation du code

| Dossier | Rôle |
|---|---|
| `src/Controller` | Reçoit la requête HTTP, choisit la vue HTML ou la réponse JSON |
| `src/Service` | Règles métier, communes au HTML et à l'API |
| `src/Domain` | Décisions métier pures, testables sans base (`SlotAvailability`, `ReservationOption` (E1), `SessionSchedule` (chevauchement E5), `OpeningChecklist` (E10), `SessionStatus`, `Field`, `Role`, `Authorization`) |
| `src/Security` | Session (`NativeSession`, `ArraySession` pour les tests), utilisateur connecté (`Auth`), jeton `Csrf`, messages `Flash` |
| `src/Middleware` | `SessionMiddleware` (cookies HttpOnly/SameSite), `CsrfMiddleware`, `RequireRole` |
| `src/Repository` | Accès aux données (Medoo, ou PDO pour les requêtes complexes) |
| `src/Support` | Horloge injectable (`Clock`, `SystemClock`, `FixedClock`) et utilitaires d'affichage |
| `templates` | Vues PHP, avec échappement systématique via `e()` |

⚠ Projet pédagogique : ne pas mettre en production tel quel.

# Contrat de l'API JSON — EscapeOps

Base : `http://127.0.0.1:8080/api` · Format : `application/json; charset=utf-8`

L'API utilise **les mêmes services métier que le site HTML** : une règle refusée sur le site l'est aussi par l'API.

## État d'avancement

| Méthode | Route | Rôle | État |
|---|---|---|---|
| GET | `/api/health` | Vérifier que l'application répond | ✅ lundi |
| GET | `/api/rooms` | Liste des salles | ✅ lundi |
| GET | `/api/rooms/{id}` | Détail d'une salle et de ses créneaux à venir | ✅ lundi |
| GET | `/api/sessions?roomId={id}&date={AAAA-MM-JJ}` | Créneaux publics et leur disponibilité, filtrables | ✅ mercredi (US-51) |
| POST | `/api/reservations` | Poser une option de 15 min (E1) | ✅ mercredi (US-52) |
| GET | `/api/reservations/{jeton}` | État de l'option, recalculé à chaque appel (E1) | ✅ mercredi |
| POST | `/api/reservations/{jeton}/confirmation` | Confirmer une option encore valide (E1) | ✅ mercredi |
| POST | `/api/incidents` | Déclarer un incident (personnel connecté) | ✅ mercredi (US-53) |

## Format des erreurs

Toutes les erreurs ont la même forme :

```json
{ "error": "CODE_MACHINE", "message": "Explication lisible en français." }
```

Un **422** ajoute `details`, les erreurs par champ (avec les noms de champs de l'API) :

```json
{ "error": "VALIDATION_ERROR", "message": "Données invalides.", "details": { "nbJoueurs": "Cette salle accueille de 2 à 6 joueurs." } }
```

| Code HTTP | Quand |
|---|---|
| 400 | JSON mal formé, ou corps qui n'est pas un objet |
| 401 | `UNAUTHENTICATED` : authentification requise (aucun membre du personnel connecté) |
| 403 | `FORBIDDEN` : rôle insuffisant |
| 404 | Ressource inconnue |
| 409 | Conflit métier : créneau déjà en option ou confirmé, option expirée (E1) |
| 415 | `Content-Type` différent de `application/json` |
| 422 | Données invalides : champ manquant, nombre de joueurs hors capacité… |

### Authentification

Les routes protégées de l'API utilisent la **même session que le site** : se connecter d'abord via `POST /connexion` (formulaire HTML avec jeton CSRF), puis renvoyer le cookie `escapeops_session`. Le contrôle est fait par le middleware `RequireRole`, qui répond en JSON sous `/api` :

```json
{ "error": "UNAUTHENTICATED", "message": "Authentification requise." }
{ "error": "FORBIDDEN", "message": "Votre rôle ne permet pas cette action." }
```

Les `POST` de l'API ne demandent pas de jeton CSRF : ils exigent `Content-Type: application/json` (415 sinon), qu'un formulaire d'un autre site ne peut pas envoyer.

---

## GET /api/rooms

**200** : tableau, éventuellement vide `[]`.

```json
[
  {
    "id": 1,
    "nom": "Le Cabinet des Ombres",
    "theme": "Enquête victorienne",
    "description": "Le bureau d'un détective disparu en 1888…",
    "dureeMinutes": 60,
    "capaciteMin": 2,
    "capaciteMax": 6,
    "tempsRemiseEtat": 20
  }
]
```

## GET /api/rooms/{id}

**200** : la salle, avec ses créneaux à venir (`PREVUE` ou `PRETE`).

```json
{
  "id": 2,
  "nom": "Le Laboratoire Interdit",
  "…": "…",
  "sessions": [
    { "id": 4, "debut": "2026-11-23 18:00:00", "fin": "2026-11-23 19:00:00", "statut": "PREVUE", "disponibilite": "LIBRE", "optionJusqua": null },
    { "id": 5, "debut": "2026-11-24 10:00:00", "fin": "2026-11-24 11:00:00", "statut": "PREVUE", "disponibilite": "EN_OPTION", "optionJusqua": "2026-11-23 17:12:00" }
  ]
}
```

`disponibilite` vaut :

| Valeur | Signification |
|---|---|
| `LIBRE` | Le créneau est réservable |
| `EN_OPTION` | Une option de moins de 15 minutes le bloque (E1) ; `optionJusqua` donne l'heure à laquelle elle expire (sinon `null`) |
| `COMPLET` | Une réservation est confirmée |
| `FERME` | La session est passée, ou elle n'est plus ouverte |

**404** : `{"error":"ROOM_NOT_FOUND","message":"Salle introuvable."}`

---


## GET /api/sessions (US-51)

Public, comme la vitrine. Paramètres facultatifs :

| Paramètre | Effet |
|---|---|
| `roomId` | Seulement les sessions de cette salle |
| `date` | Seulement les sessions qui commencent ce jour-là (`AAAA-MM-JJ`) ; sans `date` : aujourd'hui et à venir |

**200** : tableau trié par heure de début. Seules des informations publiques sont renvoyées (ni game master, ni nom de groupe) ; `disponibilite` et `optionJusqua` ont le même sens que pour `/api/rooms/{id}`.

```json
[
  { "id": 5, "salleId": 1, "salle": "Le Cabinet des Ombres", "debut": "2026-11-25 18:00:00", "fin": "2026-11-25 19:00:00",
    "statut": "PREVUE", "disponibilite": "EN_OPTION", "optionJusqua": "2026-11-25 14:15:00" }
]
```

- **404** `ROOM_NOT_FOUND` : `roomId` ne correspond à aucune salle.
- **422** `VALIDATION_ERROR` : `roomId` n'est pas un entier positif ou `date` n'est pas au format `AAAA-MM-JJ` (un filtre mal formé n'est jamais ignoré en silence).

```bash
curl "http://127.0.0.1:8080/api/sessions?roomId=1"
curl "http://127.0.0.1:8080/api/sessions?roomId=abc"   # 422
```

---

## POST /api/reservations (US-52, E1)

Pose une **option de 15 minutes**, avec le même `ReservationService::placeOption` que le formulaire du site (transaction et verrou `SELECT … FOR UPDATE` compris).

```bash
curl -i -X POST http://127.0.0.1:8080/api/reservations \
  -H "Content-Type: application/json" \
  -d '{"sessionId": 5, "nomGroupe": "Les Curieux", "contact": "curieux@example.test", "nbJoueurs": 4}'
```

**201** + `Location: /api/reservations/{jeton}` :

```json
{ "jeton": "9f1c…32 caractères…", "codeSuivi": "K7QM-3XTP", "sessionId": 5, "salle": "Le Cabinet des Ombres",
  "debut": "2026-11-25 18:00:00", "statut": "EN_ATTENTE", "expiration": "2026-11-25 14:15:00" }
```

Le **jeton** est la clé secrète de la réservation, comme le lien de suivi du site : un identifiant numérique laisserait deviner (et confirmer) les réservations des autres groupes. Le **code de suivi** sert sur la page « Mes réservations ».

| Code | `error` | Quand |
|---|---|---|
| 409 | `SLOT_UNAVAILABLE` | Le créneau n'est pas `LIBRE` ; `disponibilite` dit pourquoi (`EN_OPTION`, `COMPLET`, `FERME`) et `message` donne l'heure de fin de l'option |
| 422 | `VALIDATION_ERROR` | `sessionId` absent ou invalide, nom de groupe vide, contact qui n'est ni un e-mail ni un téléphone, nombre de joueurs hors capacité |
| 404 | `SESSION_NOT_FOUND` | Session inconnue |
| 415 / 400 | `UNSUPPORTED_MEDIA_TYPE` / `BAD_REQUEST` | Corps qui n'est pas du JSON / JSON mal formé |

```json
{ "error": "SLOT_UNAVAILABLE", "disponibilite": "EN_OPTION",
  "message": "Ce créneau est en option jusqu'à 14 h 15 : réessayez après cette heure, il sera libéré si l'option n'est pas confirmée." }
```

## GET /api/reservations/{jeton}

**200** : l'état est **recalculé à chaque appel** (E1, sans tâche planifiée) : `EN_ATTENTE`, `EXPIREE`, `CONFIRMEE` ou `ANNULEE`. Le contact du groupe n'est jamais renvoyé.

```json
{ "jeton": "9f1c…", "sessionId": 5, "salle": "Le Cabinet des Ombres", "debut": "2026-11-25 18:00:00",
  "nomGroupe": "Les Curieux", "nbJoueurs": 4, "statut": "EN_ATTENTE", "expiration": "2026-11-25 14:15:00" }
```

**404** `RESERVATION_NOT_FOUND`.

## POST /api/reservations/{jeton}/confirmation (E1, US-33)

Pas de corps. **200** : la réservation, `statut` à `CONFIRMEE`.

| Code | `error` | Quand |
|---|---|---|
| 409 | `OPTION_EXPIRED` | Plus de 15 minutes se sont écoulées : l'option n'est pas confirmée et le créneau est libre pour les autres |
| 409 | `RESERVATION_CONFLICT` | Déjà confirmée, annulée, ou session annulée/commencée entre-temps (`etat` précise lequel) |
| 404 | `RESERVATION_NOT_FOUND` | Jeton inconnu |

---

## POST /api/incidents (US-53)

Réservé au **personnel connecté** (game master, responsable, admin), voir « Authentification » plus haut. Le déclarant est toujours l'utilisateur de la session, jamais un champ du corps.

```json
{ "salleId": 1, "equipementId": 1, "sessionId": 2, "description": "La porte secrète ne s'ouvre plus.", "gravite": "CRITIQUE", "estBloquant": true }
```

`equipementId`, `sessionId` et `estBloquant` sont facultatifs ; `gravite` vaut `MINEURE` ou `CRITIQUE`.

- **201** : `{ "id": 3, "statut": "OUVERT" }`
- **401** `UNAUTHENTICATED` : personne de connecté.
- **403** `FORBIDDEN` : rôle insuffisant.
- **422** `VALIDATION_ERROR` : salle inconnue, équipement qui n'est pas installé dans cette salle, session d'une autre salle, description vide, gravité inconnue.
- **415** / **400** : corps qui n'est pas du JSON / JSON mal formé.

---

## Une seule source de règles (US-54)

| Route | Service appelé | Même méthode que la page HTML |
|---|---|---|
| `GET /api/sessions` | `SessionService::publicSlots` | Disponibilité calculée par `SlotAvailability`, comme la vitrine |
| `POST /api/reservations` | `ReservationService::placeOption` | Formulaire `/sessions/{id}/reserver` |
| `POST /api/reservations/{jeton}/confirmation` | `ReservationService::confirm` | Bouton « Confirmer » de `/reservations/{jeton}` |
| `POST /api/incidents` | `IncidentService::report` | Formulaire `/espace/incidents/nouveau` |

Les contrôleurs de `src/Controller/Api/` ne font que lire le JSON, renommer les champs (`nbJoueurs` → `nb_joueurs`) et traduire les exceptions du service en codes HTTP. Tests : `tests/api.php` (vraie application Slim et vraies routes sur une base SQLite).

# Contrat de l'API JSON — EscapeOps

Base : `http://127.0.0.1:8080/api` · Format : `application/json; charset=utf-8`

L'API utilise **les mêmes services métier que le site HTML** : une règle refusée sur le site l'est aussi par l'API.

## État d'avancement

| Méthode | Route | Rôle | État |
|---|---|---|---|
| GET | `/api/health` | Vérifier que l'application répond | ✅ lundi |
| GET | `/api/rooms` | Liste des salles | ✅ lundi |
| GET | `/api/rooms/{id}` | Détail d'une salle et de ses créneaux à venir | ✅ lundi |
| GET | `/api/sessions?roomId={id}` | Sessions, filtrables par salle | ⏳ mercredi |
| POST | `/api/reservations` | Poser une option de 15 min (E1) | ⏳ mercredi |
| POST | `/api/reservations/{id}/confirmation` | Confirmer une option encore valide (E1) | ⏳ mercredi |
| POST | `/api/incidents` | Déclarer un incident (authentifié) | ⏳ mercredi |

## Format des erreurs

Toutes les erreurs ont la même forme :

```json
{ "error": "CODE_MACHINE", "message": "Explication lisible en français." }
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

## Prévu : POST /api/reservations (E1)

Requête :

```json
{ "sessionId": 3, "nomGroupe": "Les Curieux", "contact": "curieux@example.test", "nbJoueurs": 4 }
```

- **201** + `Location: /api/reservations/{id}` : option créée, `statut` à `EN_ATTENTE`, avec une `expiration` 15 minutes plus tard.
- **409** `SLOT_UNAVAILABLE` : le créneau est déjà en option ou confirmé.
- **422** `VALIDATION_ERROR` : champ manquant, ou nombre de joueurs hors capacité.
- **404** `SESSION_NOT_FOUND`.

## Prévu : POST /api/reservations/{id}/confirmation (E1)

- **200** : l'option passe à `CONFIRMEE`.
- **409** `OPTION_EXPIRED` : la confirmation arrive après les 15 minutes.

## Prévu : POST /api/incidents

Requête, avec authentification obligatoire :

```json
{ "salleId": 1, "equipementId": 1, "sessionId": 2, "description": "La porte secrète ne s'ouvre plus.", "gravite": "CRITIQUE", "estBloquant": true }
```

- **201** + `Location`.
- **401** ou **403** sans droit.
- **422** si les données sont invalides.

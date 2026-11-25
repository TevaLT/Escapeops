# EscapeOps

Application de réservation et d'exploitation pour **Les Archives Obscures**, un escape game de 3 salles (client fictif).
Projet BTS SIO SLAM · semaine du 23 au 27 novembre 2026 · PHP 8.2+, Slim 4, Medoo/PDO, MySQL 8.4.

## Ce que fait l'application

- **Public** : consulter les salles et leurs créneaux, poser une option de 15 minutes pour un groupe, puis la confirmer ou l'annuler depuis un lien personnel.
- **Responsable / admin** : gérer le planning et l'affectation des game masters, les salles et les équipements, suivre le tableau de bord d'exploitation.
- **Game master** : animer ses sessions, remplir la checklist d'ouverture, déclarer des incidents.

### Règles métier choisies

| Type | Règles |
|---|---|
| Contractuelles | **E1** Une option pendant quinze minutes · **E5** Un game master ne peut pas se dédoubler · **E10** Checklist avant ouverture |
| Extensions | E2 Équipement critique hors service · E6 Partie interrompue · E9 Maintenance après dix parties |

Détail et cas de refus : [CHOIX_REGLES.md](CHOIX_REGLES.md).

## Lancer l'application

Tout le code est dans [starter-kit/](starter-kit/). Installation, comptes de test et routes : **[starter-kit/README.md](starter-kit/README.md)**.

En résumé, pour un premier lancement :

```powershell
cd starter-kit
composer install
Copy-Item .env.example .env
docker run -d --name escapeops-db -p 127.0.0.1:3306:3306 `
  -e MYSQL_ROOT_PASSWORD=rootpass -e MYSQL_DATABASE=escapeops `
  -e MYSQL_USER=escapeops -e MYSQL_PASSWORD=escapeops `
  -v escapeops-data:/var/lib/mysql mysql:8.4
composer db:reset
composer serve                 # http://127.0.0.1:8080
```

Les fois suivantes, le conteneur existe déjà : `docker start escapeops-db` suffit, puis `composer serve`.

> ⚠ Les mots de passe ci-dessus (`rootpass`, `escapeops`) et ceux des comptes de test sont des **identifiants de développement**, fictifs, publiés pour la recette. Ne jamais les réutiliser en production. Les vrais paramètres se trouvent dans `.env`, ignoré par Git. `127.0.0.1:3306` limite l'accès à MySQL à ta propre machine.

## Documents du projet

| Document | Contenu |
|---|---|
| [CAHIER_DES_CHARGES.md](CAHIER_DES_CHARGES.md) | Besoin du client, acteurs, périmètre, règles, données, critères d'acceptation, risques |
| [CHOIX_REGLES.md](CHOIX_REGLES.md) | Les 3 règles contractuelles et les 3 extensions |
| [MCD_EscapeOps.png](MCD_EscapeOps.png) · [.svg](MCD_EscapeOps.svg) | Modèle conceptuel de données (Merise) · source Looping : `Modele_de_BDD.loo` |
| [Maquette-EscapeOps.pdf](Maquette-EscapeOps.pdf) | Maquette des écrans |
| [BACKLOG.md](BACKLOG.md) | Tickets par épic et par jour, priorités, critères d'acceptation, avancement |
| [backlog-notion.csv](backlog-notion.csv) | Le même backlog, à importer dans Notion |
| [docs/journal.md](docs/journal.md) | Journal de bord quotidien |
| [starter-kit/docs/API.md](starter-kit/docs/API.md) | Contrat de l'API JSON |
| [starter-kit/docs/ARCHITECTURE.md](starter-kit/docs/ARCHITECTURE.md) | Architecture du code et trajet d'une requête |

Ressources du cadre de projet : [docs/](docs/) (guides de la semaine), [sujets/](sujets/), [templates/](templates/) (recette, rapport d'incident, démonstration…).

## Avancement

| Jour | Épic | État |
|---|---|---|
| Lundi 23 | 0 — Socle technique (BDD, vitrine, API salles) | ✅ |
| Mardi 24 | 1 — Authentification et rôles | ✅ |
| Mardi 24 | 2 — Socle métier (sessions, réservations, tableau de bord) | ✅ |
| Mercredi 25 (matin) | 3 — Règles E1, E5, E10, avec leurs refus et leurs tests | ✅ |
| Mercredi 25 | 6 — Évolution client EV-TEV-01 « checklist et game master obligatoires » (issue #8) : analyse, code, tests | ✅ |
| Mercredi 25 (après-midi) | 4 — API JSON (US-51 à US-55) | à faire |
| Jeudi 26 | 5 — SQL avancé (vues, procédure, trigger) · 6 — Évolution et incident | à faire |
| Vendredi 27 | 7 — Livraison et démonstration | à faire |

Détail ticket par ticket : [BACKLOG.md](BACKLOG.md).

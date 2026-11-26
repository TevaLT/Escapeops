# Issue de suivi à créer dans TevaLT/Escapeops

**Titre :**

```text
INC-TEV-01 (US-72) : option expirée, page de refus trompeuse et durcissement de la confirmation
```

**Étiquettes proposées :** `incident`, `E1`

**Corps :**

---

Suivi du ticket **#13** du dépôt de l’enseignant (AstrowareConception/BTS2-projets-web#13), « Option de réservation peut-être confirmée après 15 minutes », signalé par Élodie F. (accueil) le 26/11/2026 à 14 h 41. Règle concernée : **E1**.

### Constat

- **Défaut E1 non reproduit.** Sur 16 essais (MySQL 8.4, commit `241987b`), dont un essai avec l’onglet réellement ouvert 15 min 20 s sans retouche SQL, une option expirée est toujours refusée : 409 par l’interface, 409 `OPTION_EXPIRED` par l’API, 403 sans jeton CSRF. La base reste inchangée et le créneau est repris par un autre groupe (201).
- **Cause probable du signalement** : la page de refus ressemble à une page de confirmation (même titre, récapitulatif, code de suivi).
- **Défaut secondaire** : le bouton « Libérer le créneau » reste affiché sur une option expirée, avec un message faux.
- **Risque théorique** (non prouvé) : `UPDATE` de confirmation non conditionnel et relecture non verrouillée.

**Statut :** non reproduit sur la règle E1 (protections serveur vérifiées) ; défauts d’affichage corrigés ; écriture durcie.

Rapport : `docs/incidents/INC-TEV-01.md`. Preuves : `docs/preuves/inc-tev-01/`.

### Critères d’acceptation

Diagnostic
- [x] Ticket qualifié (environnement, informations manquantes, un seul essai)
- [x] Hypothèses listées avec verdict et preuve (horloge du PC, fuseau PHP/MySQL, onglet ancien, HTTP direct, CSRF, double réservation, affichage)
- [x] Données de test fictives créées par l’application (`composer db:reset`)
- [x] Essais UI et HTTP : option expirée refusée par l’interface (409) et par l’API (409 `OPTION_EXPIRED`), 403 sans CSRF
- [x] Essai réel sans SQL : onglet ouvert 15 min 20 s, refus 409
- [x] Preuves BDD avant/après : ligne inchangée, jamais deux réservations bloquantes sur une session
- [x] Option expirée : créneau libre, repris par un autre groupe (201) ; troisième groupe refusé (409)
- [x] Option valide confirmée, y compris 5 s avant l’échéance ; refus à l’heure pile
- [x] Test automatisé `starter-kit/tests/incident-inc-tev-01.php` ajouté à `composer test`
- [x] Rapport d’incident rédigé selon `templates/RAPPORT_INCIDENT.md`
- [x] Réponse à la testeuse rédigée

Correction
- [x] Page de refus sans ambiguïté : titre « Réservation non enregistrée : option expirée », un seul message, état réel du créneau calculé par le service (`ReservationController::refusalTitle`, `ReservationService::slotAfterExpiry`, `templates/reservations/show.php`)
- [x] Bouton « Libérer le créneau » masqué sur une option expirée ; annulation d’une option expirée refusée en 409 côté serveur (`ReservationService::byToken` et `::cancel`)
- [x] `UPDATE` de confirmation conditionnel (statut et échéance, contrôle de `rowCount`) et relecture sous verrou (`ReservationRepository::changeStatusIf`, `findByToken(…, lock: true)`)
- [x] Tests rouge → vert pour les trois points ci-dessus : `tests/incident-inc-tev-01.php` 33 échecs sur l’ancien code → 92 vérifications vertes ; `tests/reservations.php` 4 échecs → 50 vertes
- [x] `composer test` vert (15 suites, 0 échec), non-régression E1, E5 et E10
- [x] Rapport complété (sections 6 et 7) et réponse à la testeuse rédigée ; vérification sur MySQL 8.4 après correction conforme (6 scénarios, concurrence comprise) : `docs/preuves/inc-tev-01/apres-correction.md`
- [ ] Commit identifiable référençant cette issue : *À COMPLÉTER : hash*
- [ ] Push de la branche `fix/inc-tev-01` (pull request)

### Hors périmètre (à suivre séparément si retenu)

- `Cache-Control` absent sur la vitrine `/salles/{id}` (planning possiblement périmé sur téléphone)
- Message 403 générique quand la session PHP a expiré (au-delà de 24 min)

---

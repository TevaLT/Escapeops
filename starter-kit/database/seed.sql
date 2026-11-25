-- EscapeOps — jeu de données fictif
-- Les dates sont relatives au jour courant (CURDATE) : la démo reste valable quel que soit le jour.
-- Mot de passe de tous les comptes : EscapeOps2026!

INSERT INTO utilisateur (id_utilisateur, nom, prenom, email, mot_de_passe, role) VALUES
(1, 'Admin', 'Alice', 'admin@escapeops.test', '$2y$12$AEPGUJZB3cwKhdsUgWUfIeQqIUZCAN6yTGSO4MRI0I8xOAvAuQYmq', 'ADMIN'),
(2, 'Moreau', 'Bastien', 'responsable@escapeops.test', '$2y$12$AEPGUJZB3cwKhdsUgWUfIeQqIUZCAN6yTGSO4MRI0I8xOAvAuQYmq', 'RESPONSABLE'),
(3, 'Leroy', 'Camille', 'camille@escapeops.test', '$2y$12$AEPGUJZB3cwKhdsUgWUfIeQqIUZCAN6yTGSO4MRI0I8xOAvAuQYmq', 'GAME_MASTER'),
(4, 'Nguyen', 'David', 'david@escapeops.test', '$2y$12$AEPGUJZB3cwKhdsUgWUfIeQqIUZCAN6yTGSO4MRI0I8xOAvAuQYmq', 'GAME_MASTER');

INSERT INTO salle (id_salle, nom, theme, description, duree_minutes, capacite_min, capacite_max, temps_remise_etat) VALUES
(1, 'Le Cabinet des Ombres', 'Enquête victorienne', 'Le bureau d''un détective disparu en 1888. Fouillez ses archives avant que la lampe ne s''éteigne.', 60, 2, 6, 20),
(2, 'Le Laboratoire Interdit', 'Science-fiction', 'Une expérience a mal tourné. Vous avez une heure pour stopper la réaction en chaîne.', 60, 3, 6, 25),
(3, 'La Crypte du Scribe', 'Fantastique', 'Un manuscrit maudit vous a enfermés. Déchiffrez ses runes pour retrouver la lumière.', 75, 2, 5, 20);

INSERT INTO equipement (id_equipement, nom, est_critique, statut) VALUES
(1, 'Mécanisme de la porte secrète', TRUE, 'DISPONIBLE'),
(2, 'Coffre à code', FALSE, 'DISPONIBLE'),
(3, 'Projecteur d''ambiance', FALSE, 'HORS_SERVICE'),
(4, 'Système audio', FALSE, 'DISPONIBLE'),
(5, 'Cadenas à runes', FALSE, 'DISPONIBLE');

-- Le système audio est partagé par deux salles (relation N-N)
INSERT INTO salle_equipement (id_salle, id_equipement, date_affectation) VALUES
(1, 1, '2026-01-15'),
(1, 2, '2026-01-15'),
(2, 3, '2026-02-01'),
(2, 4, '2026-02-01'),
(3, 4, '2026-03-10'),
(3, 5, '2026-03-10');

-- 8 sessions. Cas utiles aux règles :
--  - sessions 2 et 3 : même salle, 20 min d'écart (remise en état)
--  - sessions 2, 4 et 6 : chevauchement à 18 h dans trois salles (E5 : Camille ne peut pas animer la 6)
--  - session 5 : option EN_ATTENTE en cours (E1)
--  - session 7 : PRETE avec checklist 3/3 (E10)
INSERT INTO session (id_session, id_salle, id_game_master, date_debut, date_fin, statut) VALUES
(1, 1, 3, CURDATE() + INTERVAL 14 HOUR, CURDATE() + INTERVAL 15 HOUR, 'TERMINEE'),
(2, 1, 3, CURDATE() + INTERVAL 18 HOUR, CURDATE() + INTERVAL 19 HOUR, 'PREVUE'),
(3, 1, NULL, CURDATE() + INTERVAL 1160 MINUTE, CURDATE() + INTERVAL 1220 MINUTE, 'PREVUE'),
(4, 2, 4, CURDATE() + INTERVAL 18 HOUR, CURDATE() + INTERVAL 19 HOUR, 'PREVUE'),
(5, 2, NULL, CURDATE() + INTERVAL 1 DAY + INTERVAL 10 HOUR, CURDATE() + INTERVAL 1 DAY + INTERVAL 11 HOUR, 'PREVUE'),
(6, 3, NULL, CURDATE() + INTERVAL 1110 MINUTE, CURDATE() + INTERVAL 1185 MINUTE, 'PREVUE'),
(7, 3, 4, CURDATE() + INTERVAL 1 DAY + INTERVAL 14 HOUR, CURDATE() + INTERVAL 1 DAY + INTERVAL 915 MINUTE, 'PRETE'),
(8, 2, NULL, CURDATE() + INTERVAL 16 HOUR, CURDATE() + INTERVAL 17 HOUR, 'ANNULEE');

-- E1 : chaque groupe reçoit un lien secret /reservations/{jeton} pour confirmer ou annuler.
-- Jetons fixes pour la démo : l'option de la famille Martin se consulte sur /reservations/e5ca9e0900000000000000000000a003
-- « Mes réservations » (/mes-reservations) : contact + code de suivi, ex. martin@example.test + MART-6789.
INSERT INTO reservation (id_reservation, id_session, nom_groupe, contact, nb_joueurs, statut, date_creation, date_expiration, jeton, code_suivi) VALUES
(1, 1, 'Team Énigme', 'team.enigme@example.test', 5, 'CONFIRMEE', NOW() - INTERVAL 3 DAY, NOW() - INTERVAL 3 DAY + INTERVAL 15 MINUTE, 'e5ca9e0900000000000000000000a001', 'TEAM2345'),
(2, 2, 'Les Curieux', '06 00 00 00 01', 6, 'CONFIRMEE', NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 2 DAY + INTERVAL 15 MINUTE, 'e5ca9e0900000000000000000000a002', 'CURX4567'),
(3, 5, 'Famille Martin', 'martin@example.test', 4, 'EN_ATTENTE', NOW() - INTERVAL 5 MINUTE, NOW() + INTERVAL 10 MINUTE, 'e5ca9e0900000000000000000000a003', 'MART6789'),
(4, 8, 'Groupe Dupont', '06 00 00 00 02', 3, 'ANNULEE', NOW() - INTERVAL 4 DAY, NOW() - INTERVAL 4 DAY + INTERVAL 15 MINUTE, 'e5ca9e0900000000000000000000a004', 'DUPT2345');

INSERT INTO incident (id_incident, id_salle, id_equipement, id_session, id_declarant, description, gravite, est_bloquant, statut, date_declaration, date_resolution) VALUES
(1, 2, 3, NULL, 4, 'Le projecteur d''ambiance ne s''allume plus.', 'MINEURE', FALSE, 'OUVERT', NOW() - INTERVAL 2 HOUR, NULL),
(2, 1, 1, 1, 3, 'La porte secrète est restée bloquée en fin de partie.', 'CRITIQUE', TRUE, 'RESOLU', CURDATE() + INTERVAL 15 HOUR, CURDATE() + INTERVAL 16 HOUR);

INSERT INTO checklist (id_session, id_game_master, salle_rangee, materiel_teste, securite_verifiee, date_validation) VALUES
(1, 3, TRUE, TRUE, TRUE, CURDATE() + INTERVAL 830 MINUTE),
(7, 4, TRUE, TRUE, TRUE, NOW());

INSERT INTO historique_session (id_session, ancien_statut, nouveau_statut, date_changement) VALUES
(1, 'PREVUE', 'PRETE', CURDATE() + INTERVAL 830 MINUTE),
(1, 'PRETE', 'EN_COURS', CURDATE() + INTERVAL 14 HOUR),
(1, 'EN_COURS', 'TERMINEE', CURDATE() + INTERVAL 15 HOUR),
(7, 'PREVUE', 'PRETE', NOW()),
(8, 'PREVUE', 'ANNULEE', NOW() - INTERVAL 4 DAY);

INSERT INTO controle_salle (id_salle, id_responsable, date_controle, commentaire) VALUES
(1, 2, CURDATE() - INTERVAL 7 DAY, 'Contrôle complet des mécanismes, RAS.');

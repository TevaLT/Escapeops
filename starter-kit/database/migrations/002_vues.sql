-- EscapeOps — 002 : vues (US-62, US-63)
-- MySQL 8.4. Pas d'ORDER BY dans une vue : le tri reste à la requête qui la lit.

-- US-62 : planning détaillé, une ligne par session.
-- Session + salle + game master (NULL si non affecté) + groupe confirmé + option EN_ATTENTE la plus récente.
-- L'option n'est pas filtrée sur l'heure : « l'option est-elle encore valable ? » dépend de l'horloge
-- de l'application (E1), SessionService le calcule à partir de option_expire_le.
-- Utilisée par SessionRepository::search() : planning de gestion (/gestion/sessions), tableau de bord
-- (« Prochaines sessions »), fiches game master, espace game master et GET /api/sessions.
CREATE VIEW v_planning_detaille AS
SELECT s.id_session,
       s.id_salle,
       s.id_game_master,
       s.date_debut,
       s.date_fin,
       s.statut,
       sa.nom AS salle,
       gm.prenom AS gm_prenom,
       gm.nom AS gm_nom,
       (SELECT r.nom_groupe FROM reservation r
         WHERE r.id_session = s.id_session AND r.statut = 'CONFIRMEE'
         LIMIT 1) AS groupe,
       (SELECT r.nom_groupe FROM reservation r
         WHERE r.id_session = s.id_session AND r.statut = 'EN_ATTENTE'
         ORDER BY r.date_expiration DESC LIMIT 1) AS option_groupe,
       (SELECT MAX(r.date_expiration) FROM reservation r
         WHERE r.id_session = s.id_session AND r.statut = 'EN_ATTENTE') AS option_expire_le
  FROM session s
  JOIN salle sa ON sa.id_salle = s.id_salle
  LEFT JOIN utilisateur gm ON gm.id_utilisateur = s.id_game_master;

-- US-63 : synthèse d'exploitation, une ligne par salle.
-- Incidents ouverts, incidents bloquants ouverts, partie en cours et salle bloquée (incident bloquant ouvert).
-- La « prochaine session » dépend de l'heure courante : elle est ajoutée par DashboardRepository avec
-- l'horloge de l'application (une vue ne prend pas de paramètre, et NOW() ignorerait l'horloge des tests).
-- Utilisée par DashboardRepository::roomStatus() : tableau de bord du responsable (/gestion).
CREATE VIEW v_dashboard_exploitation AS
SELECT sa.id_salle,
       sa.nom,
       (SELECT COUNT(*) FROM incident i
         WHERE i.id_salle = sa.id_salle AND i.statut = 'OUVERT') AS incidents_ouverts,
       (SELECT COUNT(*) FROM incident i
         WHERE i.id_salle = sa.id_salle AND i.statut = 'OUVERT' AND i.est_bloquant = 1) AS incidents_bloquants,
       (SELECT COUNT(*) FROM session s
         WHERE s.id_salle = sa.id_salle AND s.statut = 'EN_COURS') AS parties_en_cours,
       CASE WHEN EXISTS (SELECT 1 FROM incident i
                          WHERE i.id_salle = sa.id_salle AND i.statut = 'OUVERT' AND i.est_bloquant = 1)
            THEN 1 ELSE 0 END AS est_bloquee
  FROM salle sa;

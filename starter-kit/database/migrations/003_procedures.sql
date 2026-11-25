-- EscapeOps — 003 : procédures stockées (US-61)
-- MySQL 8.4. Envoyé tel quel par bin/db-reset.php (PDO) : pas de DELIMITER, c'est une commande
-- du client mysql. Depuis le client mysql, entourer le CREATE de DELIMITER // ... // DELIMITER ;

-- US-61 : bilan d'exploitation d'une salle, une ligne :
--  nb_terminees, nb_annulees, nb_interrompues : sessions de la salle dans chacun de ces états ;
--  dernier_controle : date du dernier contrôle de la salle (controle_salle), NULL s'il n'y en a jamais eu ;
--  terminees_depuis_controle : sessions TERMINEE finies après ce contrôle (toutes s'il n'y en a pas).
--    Les sessions annulées ne comptent pas : c'est le compteur du badge CONTROLE_A_PREVOIR (E9, US-96).
-- Appelée par RoomRepository::bilan() (CALL sp_bilan_salle(?)) et affichée sur la fiche salle de gestion.
CREATE PROCEDURE sp_bilan_salle(IN p_id_salle INT)
    READS SQL DATA
BEGIN
    DECLARE v_dernier_controle DATETIME DEFAULT NULL;

    SELECT MAX(c.date_controle) INTO v_dernier_controle
      FROM controle_salle c
     WHERE c.id_salle = p_id_salle;

    SELECT p_id_salle AS id_salle,
           COALESCE(SUM(s.statut = 'TERMINEE'), 0) AS nb_terminees,
           COALESCE(SUM(s.statut = 'ANNULEE'), 0) AS nb_annulees,
           COALESCE(SUM(s.statut = 'INTERROMPUE'), 0) AS nb_interrompues,
           v_dernier_controle AS dernier_controle,
           COALESCE(SUM(s.statut = 'TERMINEE'
                        AND (v_dernier_controle IS NULL OR s.date_fin > v_dernier_controle)), 0) AS terminees_depuis_controle
      FROM session s
     WHERE s.id_salle = p_id_salle;
END;

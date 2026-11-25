-- EscapeOps — 004 : triggers (US-60)
-- MySQL 8.4. Envoyé tel quel par bin/db-reset.php (PDO) : pas de DELIMITER.

-- US-60 : chaque changement de statut d'une session est tracé dans historique_session
-- (ancien statut, nouveau statut, date). Un UPDATE qui ne touche pas au statut (horaires,
-- game master...) ou qui réécrit le même statut n'ajoute rien.
-- Se déclenche depuis l'application : SessionRepository::updateStatus() / assignGameMaster()
-- (démarrer, terminer, interrompre, reprendre, annuler une session...). Sert aussi de trace pour E6 (US-95).
CREATE TRIGGER trg_session_history
AFTER UPDATE ON session
FOR EACH ROW
BEGIN
    IF NOT (OLD.statut <=> NEW.statut) THEN
        INSERT INTO historique_session (id_session, ancien_statut, nouveau_statut, date_changement)
        VALUES (NEW.id_session, OLD.statut, NEW.statut, NOW());
    END IF;
END;

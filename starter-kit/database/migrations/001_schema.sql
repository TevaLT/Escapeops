-- EscapeOps — 001 : schéma relationnel (issu du MCD)
-- MySQL 8.4 / InnoDB / utf8mb4

CREATE TABLE utilisateur (
    id_utilisateur INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    mot_de_passe VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL,
    CONSTRAINT uq_utilisateur_email UNIQUE (email),
    CONSTRAINT ck_utilisateur_role CHECK (role IN ('ADMIN', 'RESPONSABLE', 'GAME_MASTER'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE salle (
    id_salle INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(100) NOT NULL,
    theme VARCHAR(100) NOT NULL,
    description TEXT NULL,
    duree_minutes SMALLINT UNSIGNED NOT NULL,
    capacite_min TINYINT UNSIGNED NOT NULL,
    capacite_max TINYINT UNSIGNED NOT NULL,
    temps_remise_etat SMALLINT UNSIGNED NOT NULL DEFAULT 20,
    CONSTRAINT uq_salle_nom UNIQUE (nom),
    CONSTRAINT ck_salle_duree CHECK (duree_minutes > 0),
    CONSTRAINT ck_salle_capacite CHECK (capacite_min >= 1 AND capacite_max >= capacite_min)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE equipement (
    id_equipement INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(100) NOT NULL,
    est_critique BOOLEAN NOT NULL DEFAULT FALSE,
    statut VARCHAR(20) NOT NULL DEFAULT 'DISPONIBLE',
    CONSTRAINT ck_equipement_statut CHECK (statut IN ('DISPONIBLE', 'HORS_SERVICE'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Association N-N « Équiper » (salle <-> équipement)
CREATE TABLE salle_equipement (
    id_salle INT UNSIGNED NOT NULL,
    id_equipement INT UNSIGNED NOT NULL,
    date_affectation DATE NULL,
    PRIMARY KEY (id_salle, id_equipement),
    CONSTRAINT fk_se_salle FOREIGN KEY (id_salle) REFERENCES salle (id_salle) ON DELETE CASCADE,
    CONSTRAINT fk_se_equipement FOREIGN KEY (id_equipement) REFERENCES equipement (id_equipement) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- « Programmer » (salle 0,n — session 1,1) et « Animer » (game master 0,n — session 0,1)
CREATE TABLE session (
    id_session INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_salle INT UNSIGNED NOT NULL,
    id_game_master INT UNSIGNED NULL,
    date_debut DATETIME NOT NULL,
    date_fin DATETIME NOT NULL,
    statut VARCHAR(20) NOT NULL DEFAULT 'PREVUE',
    CONSTRAINT fk_session_salle FOREIGN KEY (id_salle) REFERENCES salle (id_salle),
    CONSTRAINT fk_session_game_master FOREIGN KEY (id_game_master) REFERENCES utilisateur (id_utilisateur) ON DELETE SET NULL,
    CONSTRAINT ck_session_dates CHECK (date_fin > date_debut),
    CONSTRAINT ck_session_statut CHECK (statut IN ('PREVUE', 'PRETE', 'EN_COURS', 'INTERROMPUE', 'TERMINEE', 'ANNULEE')),
    INDEX idx_session_salle_debut (id_salle, date_debut),
    INDEX idx_session_gm_debut (id_game_master, date_debut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- « Concerner » (session 0,n — réservation 1,1)
-- E1 : une option EN_ATTENTE expire à date_expiration (pose + 15 min).
-- jeton : lien secret remis au groupe (sans compte) pour confirmer ou annuler sa réservation.
-- code_suivi : code court (8 caractères) qui, avec le contact, ouvre la page « Mes réservations ».
-- RG2 (une seule réservation CONFIRMEE par session) est contrôlée dans le service.
CREATE TABLE reservation (
    id_reservation INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_session INT UNSIGNED NOT NULL,
    nom_groupe VARCHAR(100) NOT NULL,
    contact VARCHAR(150) NOT NULL,
    nb_joueurs TINYINT UNSIGNED NOT NULL,
    statut VARCHAR(20) NOT NULL DEFAULT 'EN_ATTENTE',
    a_recontacter BOOLEAN NOT NULL DEFAULT FALSE,
    date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_expiration DATETIME NULL,
    jeton CHAR(32) NULL,
    code_suivi CHAR(8) NULL,
    CONSTRAINT uq_reservation_jeton UNIQUE (jeton),
    CONSTRAINT fk_reservation_session FOREIGN KEY (id_session) REFERENCES session (id_session),
    CONSTRAINT ck_reservation_joueurs CHECK (nb_joueurs > 0),
    CONSTRAINT ck_reservation_statut CHECK (statut IN ('EN_ATTENTE', 'CONFIRMEE', 'ANNULEE')),
    INDEX idx_reservation_session_statut (id_session, statut),
    INDEX idx_reservation_code_suivi (code_suivi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- « Survenir dans », « Toucher », « Perturber », « Déclarer »
CREATE TABLE incident (
    id_incident INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_salle INT UNSIGNED NOT NULL,
    id_equipement INT UNSIGNED NULL,
    id_session INT UNSIGNED NULL,
    id_declarant INT UNSIGNED NOT NULL,
    description TEXT NOT NULL,
    gravite VARCHAR(20) NOT NULL DEFAULT 'MINEURE',
    est_bloquant BOOLEAN NOT NULL DEFAULT FALSE,
    statut VARCHAR(20) NOT NULL DEFAULT 'OUVERT',
    date_declaration DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_resolution DATETIME NULL,
    CONSTRAINT fk_incident_salle FOREIGN KEY (id_salle) REFERENCES salle (id_salle),
    CONSTRAINT fk_incident_equipement FOREIGN KEY (id_equipement) REFERENCES equipement (id_equipement) ON DELETE SET NULL,
    CONSTRAINT fk_incident_session FOREIGN KEY (id_session) REFERENCES session (id_session) ON DELETE SET NULL,
    CONSTRAINT fk_incident_declarant FOREIGN KEY (id_declarant) REFERENCES utilisateur (id_utilisateur),
    CONSTRAINT ck_incident_gravite CHECK (gravite IN ('MINEURE', 'CRITIQUE')),
    CONSTRAINT ck_incident_statut CHECK (statut IN ('OUVERT', 'RESOLU')),
    CONSTRAINT ck_incident_dates CHECK (date_resolution IS NULL OR date_resolution >= date_declaration),
    INDEX idx_incident_statut (statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- « Vérifier » et « Remplir » (E10)
CREATE TABLE checklist (
    id_checklist INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_session INT UNSIGNED NOT NULL,
    id_game_master INT UNSIGNED NOT NULL,
    salle_rangee BOOLEAN NOT NULL DEFAULT FALSE,
    materiel_teste BOOLEAN NOT NULL DEFAULT FALSE,
    securite_verifiee BOOLEAN NOT NULL DEFAULT FALSE,
    date_validation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_checklist_session FOREIGN KEY (id_session) REFERENCES session (id_session) ON DELETE CASCADE,
    CONSTRAINT fk_checklist_game_master FOREIGN KEY (id_game_master) REFERENCES utilisateur (id_utilisateur)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- « Tracer » : alimentée par le trigger de la migration 004
CREATE TABLE historique_session (
    id_historique INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_session INT UNSIGNED NOT NULL,
    ancien_statut VARCHAR(20) NULL,
    nouveau_statut VARCHAR(20) NOT NULL,
    date_changement DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_historique_session FOREIGN KEY (id_session) REFERENCES session (id_session) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- « Contrôler » et « Réaliser » (E9)
CREATE TABLE controle_salle (
    id_controle INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_salle INT UNSIGNED NOT NULL,
    id_responsable INT UNSIGNED NOT NULL,
    date_controle DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    commentaire TEXT NULL,
    CONSTRAINT fk_controle_salle FOREIGN KEY (id_salle) REFERENCES salle (id_salle) ON DELETE CASCADE,
    CONSTRAINT fk_controle_responsable FOREIGN KEY (id_responsable) REFERENCES utilisateur (id_utilisateur)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

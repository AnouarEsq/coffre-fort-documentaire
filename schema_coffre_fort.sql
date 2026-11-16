-- ============================================================
-- Coffre-fort documentaire sécurisé - Schéma PostgreSQL
-- Projet stage - Cybersécurité (AES-GCM, RBAC, audit trail)
-- ============================================================

-- 1) Types enum : garantir que seules des valeurs valides entrent en base
CREATE TYPE role_name AS ENUM ('admin', 'medecin', 'agent', 'auditeur');
CREATE TYPE action_type AS ENUM ('LOGIN', 'UPLOAD', 'VIEW', 'DOWNLOAD', 'DELETE', 'LOGIN_FAILED', 'ACCESS_DENIED');

-- 2) ROLES
CREATE TABLE roles (
    id          SERIAL PRIMARY KEY,
    nom         role_name UNIQUE NOT NULL
);

-- 3) PERMISSIONS (une action precise = une permission)
CREATE TABLE permissions (
    id          SERIAL PRIMARY KEY,
    code        VARCHAR(50) UNIQUE NOT NULL,   -- ex: 'document.upload'
    description TEXT
);

-- 4) Matrice RBAC : quel role possede quelle permission
CREATE TABLE role_permissions (
    role_id       INT REFERENCES roles(id)       ON DELETE CASCADE,
    permission_id INT REFERENCES permissions(id) ON DELETE CASCADE,
    PRIMARY KEY (role_id, permission_id)
);

-- 5) UTILISATEURS
CREATE TABLE users (
    id            SERIAL PRIMARY KEY,
    nom           VARCHAR(100) NOT NULL,
    email         VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,   -- bcrypt/Argon2id JAMAIS en clair
    role_id       INT NOT NULL REFERENCES roles(id),
    created_at    TIMESTAMPTZ DEFAULT now()
);

-- 6) DOCUMENTS : metadonnees UNIQUEMENT, jamais le contenu
CREATE TABLE documents (
    id            SERIAL PRIMARY KEY,
    nom_original  VARCHAR(255) NOT NULL,
    chemin_chiffre VARCHAR(255) NOT NULL UNIQUE,  -- fichier AES-GCM sur disque
    taille        BIGINT NOT NULL,
    mime_type     VARCHAR(100),
    sha256        CHAR(64) NOT NULL,               -- integrite (point 7)
    nonce         BYTEA NOT NULL,                  -- 12 octets, unique par chiffrement
    tag_auth      BYTEA NOT NULL,                  -- 16 octets, tag GCM
    uploade_par   INT NOT NULL REFERENCES users(id),
    created_at    TIMESTAMPTZ DEFAULT now()
);

-- 7) Audit log : APPEND-ONLY = le cerveau de la tracabilite
CREATE TABLE audit_log (
    id          BIGSERIAL PRIMARY KEY,
    user_id     INT REFERENCES users(id),          -- NULL si anonyme
    action      action_type NOT NULL,
    document_id INT REFERENCES documents(id),
    ip_address  INET,
    details     JSONB,                             -- contexte flexible
    created_at  TIMESTAMPTZ DEFAULT now()
);

-- Index pour les requetes frequentes de l'auditeur
CREATE INDEX idx_audit_user  ON audit_log(user_id);
CREATE INDEX idx_audit_doc   ON audit_log(document_id);
CREATE INDEX idx_audit_date  ON audit_log(created_at);

-- 8) Grain de sel initial : les 4 roles
INSERT INTO roles (nom) VALUES ('admin'), ('medecin'), ('agent'), ('auditeur');

-- 9) Les permissions du sujet
INSERT INTO permissions (code, description) VALUES
 ('document.upload',   'Deposer un document'),
 ('document.view',     'Consulter un document'),
 ('document.download', 'Telecharger un document'),
 ('document.delete',   'Supprimer un document'),
 ('audit.read',        'Consulter le journal'),
 ('user.manage',       'Gerer les utilisateurs');

-- 10) La MATRICE DES ACCES (ton livrable, deja codee)
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE (r.nom='admin'    AND p.code IN ('document.upload','document.view','document.download','document.delete','audit.read','user.manage'))
   OR (r.nom='medecin'  AND p.code IN ('document.upload','document.view','document.download'))
   OR (r.nom='agent'    AND p.code IN ('document.upload'))
   OR (r.nom='auditeur' AND p.code IN ('audit.read'));

-- ============================================================
-- LRDMS — Legislative Records & Document Management System
-- Database schema (MySQL / MariaDB, InnoDB)
--
-- Import this file FIRST via phpMyAdmin (or `mysql -u root < schema.sql`).
-- It creates the database, all tables, and static lookup data
-- (roles, permissions, role_permissions, committees).
--
-- It does NOT create user accounts or sample documents — those need
-- PHP's password_hash(), so they're created by database/seed.php
-- (run once in your browser) instead of hardcoded here.
-- ============================================================

CREATE DATABASE IF NOT EXISTS lrdms_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE lrdms_db;

-- ------------------------------------------------------------
-- ROLES  (the "role-based" half of the two-layer RBAC model)
-- ------------------------------------------------------------
CREATE TABLE roles (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(60) NOT NULL UNIQUE,
  description VARCHAR(255)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- COMMITTEES
-- Local mirror only. In the full Legislative Services platform this
-- would sync from the Committee Management & Assignment System rather
-- than being maintained here — see README.md, integration notes.
-- ------------------------------------------------------------
CREATE TABLE committees (
  id   INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- USERS
-- ------------------------------------------------------------
CREATE TABLE users (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  full_name            VARCHAR(150) NOT NULL,
  username             VARCHAR(60) NOT NULL UNIQUE,
  email                VARCHAR(150) UNIQUE,
  password_hash        VARCHAR(255) NOT NULL,
  role_id              INT NOT NULL,
  committee_id         INT NULL,
  is_active            TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,  -- force a password change on next login
  failed_attempts      INT NOT NULL DEFAULT 0,          -- consecutive failed logins (lockout)
  locked_until         DATETIME NULL,                   -- NULL = not locked
  last_login_at        DATETIME NULL,
  totp_secret          VARCHAR(64) NULL,                -- base32 TOTP secret (2FA)
  totp_enabled         TINYINT(1) NOT NULL DEFAULT 0,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (role_id) REFERENCES roles(id),
  FOREIGN KEY (committee_id) REFERENCES committees(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- USER SESSIONS
-- One row per active login. Each login creates a row; logout (and
-- "sign out all devices" / admin revoke) sets is_active = 0. The
-- session_token is stored in the PHP session and verified on every
-- request by includes/auth.php current_user().
-- ------------------------------------------------------------
CREATE TABLE user_sessions (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  user_id       INT NOT NULL,
  session_token VARCHAR(64) NOT NULL UNIQUE,
  ip_address    VARCHAR(45),
  user_agent    VARCHAR(255),
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- PERMISSIONS  (module + action pairs)
-- ------------------------------------------------------------
CREATE TABLE permissions (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  module      VARCHAR(60) NOT NULL,
  action      VARCHAR(60) NOT NULL,
  description VARCHAR(255),
  UNIQUE KEY module_action (module, action)
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
  role_id       INT NOT NULL,
  permission_id INT NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- DOCUMENTS
-- status + is_public together drive the "status/ownership-based"
-- half of RBAC — see includes/rbac.php, document_visibility_clause().
-- previous_version_id / next_version_id implement version control:
-- amending a document never overwrites it, it links to a new row.
-- ------------------------------------------------------------
CREATE TABLE documents (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  doc_number          VARCHAR(60) NOT NULL,
  title               VARCHAR(500) NOT NULL,
  doc_type            ENUM('Ordinance','Resolution','Committee Report','Minutes','Other') NOT NULL DEFAULT 'Other',
  sponsor             VARCHAR(150),
  committee_id        INT NULL,
  owner_id            INT NOT NULL,
  status              ENUM('Draft','Submitted','Under Review','Enacted','Amended','Superseded','Withdrawn') NOT NULL DEFAULT 'Draft',
  is_public           TINYINT(1) NOT NULL DEFAULT 0,
  source_system       VARCHAR(100) NOT NULL DEFAULT 'Manual Encoding',
  enactment_date      DATE NULL,
  file_path           VARCHAR(500),
  ocr_text            MEDIUMTEXT,
  body                LONGTEXT NULL,   -- composed type-specific content (sections, minutes, etc.)
  previous_version_id INT NULL,
  next_version_id     INT NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (owner_id) REFERENCES users(id),
  FOREIGN KEY (committee_id) REFERENCES committees(id),
  FULLTEXT KEY ft_search (title, ocr_text, body),
  KEY idx_next_version (next_version_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- CHANGE ANNOTATIONS  ("why was this revised")
-- ------------------------------------------------------------
CREATE TABLE document_change_notes (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  document_id INT NOT NULL,
  note        TEXT NOT NULL,
  created_by  INT NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- DOCUMENT RELATIONSHIPS
-- Legislative relationships between documents (Amends, Repeals,
-- Substitutes, Consolidates, or simply Related). Recorded once from
-- whichever version was current at the time; the Version Control /
-- "Related Legislation" panel resolves both ends to their current head
-- version, so a relationship never goes stale when either side is
-- later amended. See includes/legislative.php.
-- ------------------------------------------------------------
CREATE TABLE document_relationships (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  document_id        INT NOT NULL,
  related_id         INT NOT NULL,
  relationship_type  ENUM('amends','repeals','substitutes','consolidates','related') NOT NULL,
  created_by         INT NOT NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_rel (document_id, related_id, relationship_type),
  KEY idx_document (document_id),
  KEY idx_related (related_id),
  FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
  FOREIGN KEY (related_id) REFERENCES documents(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- AUDIT LOG
-- Cross-cutting and passive — every module writes here, nothing reads
-- from it except the Audit Trail module itself. Nobody, including
-- Administrators, gets a DELETE permission on this table by design.
-- ------------------------------------------------------------
CREATE TABLE audit_log (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  user_id           INT NULL,
  username_snapshot VARCHAR(60),
  module            VARCHAR(60) NOT NULL,
  action            VARCHAR(100) NOT NULL,
  detail            VARCHAR(500),
  ip_address        VARCHAR(45),
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- SEARCH LOG  (also feeds the audit trail, per the Search History sub-module)
-- ------------------------------------------------------------
CREATE TABLE search_log (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  user_id        INT NULL,
  query          VARCHAR(255) NOT NULL,
  search_type    ENUM('keyword','semantic') NOT NULL DEFAULT 'keyword',
  results_count  INT NOT NULL DEFAULT 0,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- SAVED SEARCHES
-- Lets a user store a search.php query configuration (keyword, mode,
-- filters) under a name and re-run it later. Only the configuration is
-- stored, never results — see includes/saved_searches.php.
-- ------------------------------------------------------------
CREATE TABLE saved_searches (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  user_id          INT NOT NULL,
  name             VARCHAR(150) NOT NULL,
  search_criteria  TEXT NOT NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_user (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- PASSWORD RESET TOKENS
-- ------------------------------------------------------------
CREATE TABLE password_reset_tokens (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  user_id         INT NOT NULL,
  token           VARCHAR(255) NOT NULL UNIQUE,
  expires_at      DATETIME NOT NULL,
  used_at         DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- PASSWORD RESET CODES
-- Used for code-based forgot-password flow sent via email.
-- ------------------------------------------------------------
CREATE TABLE password_reset_codes (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  user_id         INT NOT NULL,
  code            VARCHAR(10) NOT NULL,
  expires_at      DATETIME NOT NULL,
  used_at         DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- SEED: static lookup data
-- ============================================================

INSERT INTO roles (name, description) VALUES
('Super Admin',         'Full system access including role/permission management, user administration, and all system settings'),
('Administrator',       'Manages user accounts, roles, and system-wide settings (day-to-day admin)'),
('Records Officer',     'Encodes, digitizes, and manages the legislative repository day-to-day'),
('Legislative Staff',   'Drafts and submits documents for their office; views and searches the repository'),
('Committee Secretary', 'Reviews and endorses documents prior to enactment');

INSERT INTO committees (name) VALUES
('Committee on Transportation'),
('Committee on Environment'),
('Committee on Ways and Means'),
('Committee on Rules');

INSERT INTO permissions (module, action, description) VALUES
('encoding', 'create',         'Encode/upload new documents'),
('repository', 'view_all',     'View all documents regardless of ownership or status'),
('repository', 'view_committee','View documents assigned to the user''s committee (review workflow)'),
('repository', 'view_own',     'View own submitted/drafted documents'),
('repository', 'view_public',  'View enacted, public documents'),
('repository', 'edit_metadata','Edit document metadata and change status'),
('version', 'amend',           'Create a new version of a document'),
('version', 'rollback',        'Roll back to a previous version'),
('search', 'run',              'Run keyword or semantic search'),
('access', 'manage_users',     'Create/edit/deactivate user accounts'),
('access', 'manage_roles',     'Create/edit roles and assign permissions'),
('access', 'reset_password',   'Reset another user''s password'),
('access', 'force_logout',     'Revoke a user''s sessions / sign out devices'),
('access', 'view_user_activity','View another user''s audit activity'),
('audit', 'view',              'View the audit trail');

-- The repository.view_* permissions drive the row-visibility layer in
-- includes/rbac.php (document_visibility_clause / can_view_document):
-- view_all → every document, view_committee → own committee's pending +
-- own + public, view_own → own + public, view_public → public only.

-- Super Admin: full system access including ALL permissions
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'Super Admin';

-- Administrator: day-to-day system administration (manages users, resets passwords,
-- views audit trail, forces logout) but CANNOT create/edit roles or assign permissions
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON
  (p.module = 'access' AND p.action IN ('manage_users','reset_password','force_logout','view_user_activity')) OR
  (p.module = 'audit' AND p.action = 'view') OR
  (p.module = 'search' AND p.action = 'run') OR
  (p.module = 'repository' AND p.action = 'view_all')
WHERE r.name = 'Administrator';

-- Records Officer: the core power user for encoding, repository, versioning,
-- and audit visibility
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON
  (p.module = 'encoding' AND p.action = 'create') OR
  (p.module = 'repository') OR
  (p.module = 'version') OR
  (p.module = 'search' AND p.action = 'run') OR
  (p.module = 'audit' AND p.action = 'view')
WHERE r.name = 'Records Officer';

-- Legislative Staff: draft/submit their own documents, browse what they're allowed to see
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON
  (p.module = 'encoding' AND p.action = 'create') OR
  (p.module = 'repository' AND p.action IN ('view_own','view_public')) OR
  (p.module = 'search' AND p.action = 'run')
WHERE r.name = 'Legislative Staff';

-- Committee Secretary: committee-scoped review plus drafting committee documents
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON
  (p.module = 'repository' AND p.action = 'view_committee') OR
  (p.module = 'encoding' AND p.action = 'create') OR
  (p.module = 'search' AND p.action = 'run')
WHERE r.name = 'Committee Secretary';

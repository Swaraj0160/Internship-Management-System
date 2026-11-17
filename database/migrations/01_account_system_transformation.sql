-- =============================================================================
-- INTERNHUB — DATABASE MIGRATION 01: ACCOUNT SYSTEM & SCALABLE ARCHITECTURE
-- MIT-ADT University Internship & Professional Platform
-- Target Database: u570725762_internship
-- Safe, non-destructive migration preserving all legacy data
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- 1. Extend `users` table for account lifecycle & verification
-- -----------------------------------------------------------------------------
SET @dbname = DATABASE();

-- Add account_status if not exists
SET @query = (
    SELECT IF(
        count(*) = 0,
        "ALTER TABLE users ADD COLUMN account_status ENUM('pending_verification', 'pending_approval', 'active', 'rejected', 'suspended', 'deactivated') NOT NULL DEFAULT 'active' AFTER role",
        "SELECT 'Column account_status already exists'"
    )
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'users' AND COLUMN_NAME = 'account_status'
);
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add email_verified if not exists
SET @query = (
    SELECT IF(
        count(*) = 0,
        "ALTER TABLE users ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 1 AFTER account_status",
        "SELECT 'Column email_verified already exists'"
    )
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_verified'
);
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add designation if not exists (for faculty)
SET @query = (
    SELECT IF(
        count(*) = 0,
        "ALTER TABLE users ADD COLUMN designation VARCHAR(100) NULL AFTER department",
        "SELECT 'Column designation already exists'"
    )
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'users' AND COLUMN_NAME = 'designation'
);
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add employee_id if not exists (for faculty)
SET @query = (
    SELECT IF(
        count(*) = 0,
        "ALTER TABLE users ADD COLUMN employee_id VARCHAR(50) NULL AFTER enrollment_no",
        "SELECT 'Column employee_id already exists'"
    )
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'users' AND COLUMN_NAME = 'employee_id'
);
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add approval_notes if not exists
SET @query = (
    SELECT IF(
        count(*) = 0,
        "ALTER TABLE users ADD COLUMN approval_notes TEXT NULL AFTER auth_enabled",
        "SELECT 'Column approval_notes already exists'"
    )
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'users' AND COLUMN_NAME = 'approval_notes'
);
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Ensure all legacy users have active status and email_verified=1
UPDATE users SET account_status = 'active', email_verified = 1 WHERE account_status IS NULL OR account_status = '';

-- -----------------------------------------------------------------------------
-- 2. Student Profiles
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS student_profiles (
    user_id INT NOT NULL,
    headline VARCHAR(255) NULL,
    bio TEXT NULL,
    github_url VARCHAR(255) NULL,
    linkedin_url VARCHAR(255) NULL,
    portfolio_url VARCHAR(255) NULL,
    current_semester VARCHAR(20) NULL,
    academic_batch VARCHAR(20) NULL,
    cgpa DECIMAL(3,2) NULL,
    resume_file VARCHAR(255) NULL,
    is_public TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_student_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. Faculty Profiles
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS faculty_profiles (
    user_id INT NOT NULL,
    designation VARCHAR(100) NULL,
    employee_id VARCHAR(50) NULL,
    department VARCHAR(100) NULL,
    cabin_room VARCHAR(50) NULL,
    office_hours VARCHAR(100) NULL,
    research_areas TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_faculty_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. Faculty Approval Requests
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS faculty_approval_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    department VARCHAR(100) NOT NULL,
    designation VARCHAR(100) NOT NULL,
    employee_id VARCHAR(50) NULL,
    phone VARCHAR(20) NULL,
    status ENUM('pending', 'approved', 'rejected', 'suspended') NOT NULL DEFAULT 'pending',
    reviewed_by INT NULL,
    admin_remarks TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    KEY idx_status (status),
    KEY idx_user_id (user_id),
    CONSTRAINT fk_faculty_requests_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_faculty_requests_admin FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5. Email Verification Tokens & Password Reset Tokens
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_verification_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    token_type ENUM('email_verify', 'password_reset') NOT NULL DEFAULT 'email_verify',
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_token_hash (token_hash),
    KEY idx_user_type (user_id, token_type),
    CONSTRAINT fk_email_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_token_hash (token_hash),
    CONSTRAINT fk_pw_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 6. Scalable Storage Architecture (Objects & Quotas)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS storage_objects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    category ENUM('profile', 'cover', 'projects', 'achievements', 'certificates', 'resumes', 'community', 'internship', 'weekly-reports') NOT NULL,
    storage_driver ENUM('r2', 'local') NOT NULL DEFAULT 'local',
    bucket_name VARCHAR(100) NULL,
    object_key VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes BIGINT NOT NULL,
    visibility ENUM('private', 'public') NOT NULL DEFAULT 'private',
    metadata_json TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    KEY idx_user_category (user_id, category),
    KEY idx_object_key (object_key),
    CONSTRAINT fk_storage_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS storage_quotas (
    user_id INT NOT NULL PRIMARY KEY,
    used_bytes BIGINT NOT NULL DEFAULT 0,
    max_quota_bytes BIGINT NOT NULL DEFAULT 26214400, -- 25 MB in bytes
    file_count INT NOT NULL DEFAULT 0,
    is_restricted TINYINT(1) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_storage_quota_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 7. Audit Logging
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    actor_id INT NULL,
    actor_role VARCHAR(50) NULL,
    action VARCHAR(100) NOT NULL,
    target_id INT NULL,
    target_type VARCHAR(50) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    details TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_actor (actor_id),
    KEY idx_action (action),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 8. Student Portfolio & Professional Profile Entities
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS skills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    category VARCHAR(100) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_skills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    skill_id INT NOT NULL,
    proficiency_level ENUM('beginner', 'intermediate', 'advanced', 'expert') DEFAULT 'intermediate',
    UNIQUE KEY uq_student_skill (student_id, skill_id),
    CONSTRAINT fk_student_skills_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_student_skills_skill FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    github_url VARCHAR(255) NULL,
    live_url VARCHAR(255) NULL,
    thumbnail_file VARCHAR(255) NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    is_featured TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_student_proj (student_id),
    CONSTRAINT fk_projects_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_technologies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    technology_name VARCHAR(100) NOT NULL,
    KEY idx_project_tech (project_id),
    CONSTRAINT fk_proj_tech_proj FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS achievements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    issuer VARCHAR(255) NULL,
    issue_date DATE NULL,
    evidence_file VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_student_ach (student_id),
    CONSTRAINT fk_achievements_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS certifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    issuing_organization VARCHAR(255) NOT NULL,
    issue_date DATE NULL,
    expiration_date DATE NULL,
    credential_id VARCHAR(100) NULL,
    credential_url VARCHAR(255) NULL,
    certificate_file VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_student_cert (student_id),
    CONSTRAINT fk_certifications_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 9. Community Posts & Interactions
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS community_posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    author_id INT NOT NULL,
    author_role ENUM('student', 'faculty', 'admin') NOT NULL,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    post_type ENUM('general', 'internship_experience', 'question', 'showcase') NOT NULL DEFAULT 'general',
    likes_count INT NOT NULL DEFAULT 0,
    comments_count INT NOT NULL DEFAULT 0,
    is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    is_moderated TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_author (author_id),
    KEY idx_post_type (post_type),
    CONSTRAINT fk_community_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS post_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT NOT NULL,
    user_id INT NOT NULL,
    comment TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_post (post_id),
    CONSTRAINT fk_post_comment_post FOREIGN KEY (post_id) REFERENCES community_posts(id) ON DELETE CASCADE,
    CONSTRAINT fk_post_comment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS post_reactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT NOT NULL,
    user_id INT NOT NULL,
    reaction_type ENUM('like', 'insightful', 'celebrate') NOT NULL DEFAULT 'like',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_post_user_reaction (post_id, user_id),
    CONSTRAINT fk_reaction_post FOREIGN KEY (post_id) REFERENCES community_posts(id) ON DELETE CASCADE,
    CONSTRAINT fk_reaction_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 10. Notifications
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    link_url VARCHAR(255) NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user_unread (user_id, is_read),
    CONSTRAINT fk_user_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

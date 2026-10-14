-- ============================================================================
-- InternHub — MIT-ADT University Internship Management Portal
-- Database Schema & Synthetic Seed Data
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- ----------------------------------------------------------------------------
-- Table: users
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(150) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','faculty','student') NOT NULL,
  `department` varchar(100) DEFAULT 'Information Technology',
  `enrollment_no` varchar(100) DEFAULT NULL,
  `division` varchar(50) DEFAULT NULL,
  `academic_year` varchar(10) DEFAULT '2026-27',
  `year_of_study` varchar(5) DEFAULT 'TY',
  `semester` varchar(10) DEFAULT 'SEM I',
  `class_division` varchar(20) DEFAULT NULL,
  `specialization` varchar(120) DEFAULT 'BTech CSE - Artificial Intelligence and Analytics',
  `batch` varchar(5) DEFAULT 'A',
  `ty_roll_no` varchar(20) DEFAULT NULL,
  `roll_no` varchar(20) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `faculty_division` varchar(50) DEFAULT NULL,
  `profile_photo` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `auth_secret` varchar(255) DEFAULT NULL,
  `auth_enabled` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `enrollment_no` (`enrollment_no`),
  KEY `idx_year_of_study` (`year_of_study`),
  KEY `idx_class_division` (`class_division`),
  KEY `idx_specialization` (`specialization`),
  KEY `idx_batch` (`batch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: divisions
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `divisions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `display_order` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: faculty_divisions
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `faculty_divisions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `faculty_id` int(11) NOT NULL,
  `division` varchar(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_faculty_division` (`faculty_id`,`division`),
  CONSTRAINT `fk_faculty_divisions_user` FOREIGN KEY (`faculty_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: companies
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `companies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_name` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_name` (`company_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: internships
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `internships` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `company_name` varchar(255) NOT NULL,
  `company_contact` varchar(30) DEFAULT NULL,
  `internship_role` varchar(255) DEFAULT NULL,
  `mini_project_title` varchar(255) DEFAULT NULL,
  `internship_type` varchar(255) NOT NULL DEFAULT 'Industry Internship',
  `mentor_name` varchar(255) DEFAULT NULL,
  `stipend` varchar(100) DEFAULT NULL,
  `mode` enum('Online','Offline','Hybrid') DEFAULT 'Offline',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `offer_letter` varchar(255) DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected') DEFAULT 'Pending',
  `rejection_reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `offer_letter_drive_id` varchar(255) DEFAULT NULL,
  `offer_letter_drive_link` text DEFAULT NULL,
  `company_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_id_unique` (`student_id`),
  KEY `student_id` (`student_id`),
  CONSTRAINT `fk_internships_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: weekly_deadlines
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `weekly_deadlines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `division` varchar(50) NOT NULL,
  `week_no` int(11) NOT NULL,
  `deadline_date` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_deadline` (`division`,`week_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: weekly_updates
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `weekly_updates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `internship_id` int(11) NOT NULL,
  `week_no` int(11) NOT NULL,
  `work_done` text NOT NULL,
  `update_file` varchar(255) DEFAULT NULL,
  `faculty_feedback` text DEFAULT NULL,
  `submission_status` varchar(50) DEFAULT 'Submitted',
  `submitted_at` datetime DEFAULT current_timestamp(),
  `review_status` varchar(20) DEFAULT 'Pending',
  `rejected_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_week` (`internship_id`,`week_no`),
  CONSTRAINT `fk_weekly_updates_internship` FOREIGN KEY (`internship_id`) REFERENCES `internships` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: student_evaluation
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `student_evaluation` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `week1_marks` int(11) DEFAULT 0,
  `week2_marks` int(11) DEFAULT 0,
  `week3_marks` int(11) DEFAULT 0,
  `week4_marks` int(11) DEFAULT 0,
  `mentor_evaluation` int(11) DEFAULT 0,
  `weekly_total` int(11) DEFAULT 0,
  `final_evaluation` int(11) DEFAULT 0,
  `total_marks` int(11) DEFAULT 0,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `presentation_marks` int(11) DEFAULT NULL,
  `documentation_marks` int(11) DEFAULT NULL,
  `viva_marks` int(11) DEFAULT NULL,
  `internship_eval_total` int(11) DEFAULT NULL,
  `eval_remark` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `student_id` (`student_id`),
  CONSTRAINT `fk_student_evaluation_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: final_reports
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `final_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `internship_id` int(11) NOT NULL,
  `report_file` varchar(255) DEFAULT NULL,
  `certificate_file` varchar(255) DEFAULT NULL,
  `ppt_file` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `internship_id` (`internship_id`),
  CONSTRAINT `fk_final_reports_internship` FOREIGN KEY (`internship_id`) REFERENCES `internships` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: feedback
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `feedback` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `internship_id` int(11) NOT NULL,
  `week_no` int(11) NOT NULL,
  `remarks` text NOT NULL,
  `marks` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `internship_id` (`internship_id`),
  CONSTRAINT `fk_feedback_internship` FOREIGN KEY (`internship_id`) REFERENCES `internships` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: evaluations
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `evaluations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `internship_id` int(11) NOT NULL,
  `ta1_marks` int(11) DEFAULT 0,
  `ta2_marks` int(11) DEFAULT 0,
  `attendance_marks` int(11) DEFAULT 0,
  `final_evaluation_marks` int(11) DEFAULT 0,
  `total_marks` int(11) DEFAULT 0,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `internship_id` (`internship_id`),
  CONSTRAINT `fk_evaluations_internship` FOREIGN KEY (`internship_id`) REFERENCES `internships` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: student_work_showcase
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `student_work_showcase` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `internship_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `category` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `url` varchar(512) DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `tech_used` varchar(255) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'Submitted',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `student_id` (`student_id`),
  KEY `internship_id` (`internship_id`),
  KEY `reviewed_by` (`reviewed_by`),
  CONSTRAINT `fk_showcase_internship` FOREIGN KEY (`internship_id`) REFERENCES `internships` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_showcase_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_showcase_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: admin_notifications
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `target_role` enum('all','student','faculty') DEFAULT 'all',
  `attachment` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: faculty_notifications
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `faculty_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `faculty_id` int(11) NOT NULL,
  `division` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `attachment` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `faculty_id` (`faculty_id`),
  CONSTRAINT `fk_faculty_notifications_user` FOREIGN KEY (`faculty_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: faculty_notification_reads
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `faculty_notification_reads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `faculty_id` int(11) NOT NULL,
  `notification_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `faculty_id` (`faculty_id`),
  KEY `notification_id` (`notification_id`),
  CONSTRAINT `fk_fac_notif_reads_user` FOREIGN KEY (`faculty_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fac_notif_reads_notif` FOREIGN KEY (`notification_id`) REFERENCES `admin_notifications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: student_notification_reads
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `student_notification_reads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `notification_id` int(11) NOT NULL,
  `notification_type` enum('admin','faculty') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `student_id` (`student_id`),
  CONSTRAINT `fk_stu_notif_reads_user` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: login_attempts
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) DEFAULT NULL,
  `attempts` int(11) DEFAULT 1,
  `last_attempt` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: login_logs
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `ip_address` varchar(50) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `login_time` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: login_otp
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_otp` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `otp` varchar(6) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `expires_at` datetime DEFAULT NULL,
  `attempts` int(11) DEFAULT 0,
  `is_used` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: otp_logs
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `otp_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: password_resets
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: trusted_devices
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `trusted_devices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: domain_classifications
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `domain_classifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `enrollment_no` varchar(100) NOT NULL,
  `extracted_evidence` text DEFAULT NULL,
  `assigned_domain` varchar(255) NOT NULL,
  `classification_reason` text DEFAULT NULL,
  `confidence` varchar(30) DEFAULT NULL,
  `extraction_method` varchar(50) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `enrollment_no` (`enrollment_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- SYNTHETIC DEMONSTRATION SEED DATA
-- (Non-production demo identities for setup, evaluation & development)
-- ============================================================================

-- Insert Academic Divisions
INSERT INTO `divisions` (`code`, `display_order`) VALUES
('SY-1', 1),
('SY-2', 2),
('SY-3', 3),
('TY-AIA-1', 4),
('TY-AIA-2', 5)
ON DUPLICATE KEY UPDATE `display_order` = VALUES(`display_order`);

-- Insert Demonstration Users
-- Passwords:
-- Admin:   Admin@123   (or hashed equivalent)
-- Faculty: Faculty@123 (or hashed equivalent)
-- Student: Student@123 (or hashed equivalent)
INSERT INTO `users` (`id`, `full_name`, `email`, `password`, `role`, `department`, `enrollment_no`, `division`, `academic_year`, `year_of_study`, `semester`, `class_division`, `specialization`, `batch`, `ty_roll_no`, `roll_no`, `phone`, `faculty_division`, `auth_enabled`) VALUES
(1, 'System Administrator', 'admin@mitadt.edu.in', 'Admin@123', 'admin', 'Information Technology', NULL, NULL, '2026-27', 'TY', 'SEM I', NULL, 'CSE-IT', 'A', NULL, NULL, '9876543210', NULL, 0),
(2, 'Prof. Rajesh Sharma', 'faculty@mitadt.edu.in', 'Faculty@123', 'faculty', 'Information Technology', NULL, 'SY-1', '2026-27', 'TY', 'SEM I', 'TY-AIA-1', 'CSE-IT', 'A', NULL, NULL, '9876543211', 'SY-1', 0),
(3, 'Aadarsh Chandurao', 'student@mitadt.edu.in', 'Student@123', 'student', 'Information Technology', 'MIT2026STU001', 'SY-1', '2026-27', 'TY', 'SEM I', 'TY-AIA-1', 'BTech CSE - Artificial Intelligence and Analytics', 'A', '101', '101', '9876543212', NULL, 0)
ON DUPLICATE KEY UPDATE `full_name` = VALUES(`full_name`);

-- Insert Faculty Division Assignment
INSERT INTO `faculty_divisions` (`faculty_id`, `division`) VALUES
(2, 'SY-1')
ON DUPLICATE KEY UPDATE `division` = VALUES(`division`);

-- Insert Weekly Deadlines for Division SY-1
INSERT INTO `weekly_deadlines` (`division`, `week_no`, `deadline_date`) VALUES
('SY-1', 1, DATE_ADD(NOW(), INTERVAL 7 DAY)),
('SY-1', 2, DATE_ADD(NOW(), INTERVAL 14 DAY)),
('SY-1', 3, DATE_ADD(NOW(), INTERVAL 21 DAY)),
('SY-1', 4, DATE_ADD(NOW(), INTERVAL 28 DAY))
ON DUPLICATE KEY UPDATE `deadline_date` = VALUES(`deadline_date`);

-- Insert Demo Company
INSERT INTO `companies` (`id`, `company_name`) VALUES
(1, 'Persistent Systems Ltd.')
ON DUPLICATE KEY UPDATE `company_name` = VALUES(`company_name`);

-- Insert Synthetic Internship for Student
INSERT INTO `internships` (`id`, `student_id`, `company_name`, `company_contact`, `internship_role`, `mini_project_title`, `internship_type`, `mentor_name`, `stipend`, `mode`, `start_date`, `end_date`, `offer_letter`, `status`, `company_id`) VALUES
(1, 3, 'Persistent Systems Ltd.', 'hr@persistent.com', 'Software Engineering Intern', 'Automated Healthcare Diagnostic Assistant', 'Industry Internship', 'Dr. Anand Kulkarni', '15000', 'Hybrid', CURDATE() - INTERVAL 14 DAY, CURDATE() + INTERVAL 45 DAY, 'offer_demo.pdf', 'Approved', 1)
ON DUPLICATE KEY UPDATE `company_name` = VALUES(`company_name`);

-- Insert Synthetic Weekly Progress Submissions
INSERT INTO `weekly_updates` (`id`, `internship_id`, `week_no`, `work_done`, `update_file`, `faculty_feedback`, `submission_status`, `submitted_at`, `review_status`) VALUES
(1, 1, 1, 'Configured development environment, set up Docker containers, and completed initial sprint planning with industry mentors.', 'report_week1.pdf', 'Great start. Ensure unit tests are documented in the repository.', 'Submitted', NOW() - INTERVAL 5 DAY, 'Accepted'),
(2, 1, 2, 'Implemented RESTful APIs for user verification and integrated Redis caching for sub-millisecond query responses.', 'report_week2.pdf', NULL, 'Submitted', NOW() - INTERVAL 1 DAY, 'Pending')
ON DUPLICATE KEY UPDATE `work_done` = VALUES(`work_done`);

-- Insert Synthetic Evaluation Record
INSERT INTO `student_evaluation` (`student_id`, `week1_marks`, `week2_marks`, `week3_marks`, `week4_marks`, `mentor_evaluation`, `weekly_total`, `final_evaluation`, `total_marks`) VALUES
(3, 10, 0, 0, 0, 18, 10, 0, 28)
ON DUPLICATE KEY UPDATE `weekly_total` = VALUES(`weekly_total`);

-- Insert Sample Broadcast Notification
INSERT INTO `admin_notifications` (`title`, `message`, `target_role`) VALUES
('Mid-Term Internship Progress Review', 'All students must complete their Week 1 and Week 2 report submissions before the upcoming deadline.', 'all');

SET FOREIGN_KEY_CHECKS = 1;
COMMIT;

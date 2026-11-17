<?php
/**
 * InternHub — Account & Security Configuration
 * MIT-ADT University Internship & Professional Platform
 */

if (!defined('INTERNHUB_SECURE')) {
    define('INTERNHUB_SECURE', true);
}

return [
    // Institutional Domain Controls
    // null = open registration (validates standard RFC email syntax; matches historical student records)
    // ['mitadt.edu.in', 'mituniversity.edu.in'] = restrict registration to official university domains
    'allowed_student_domains' => null,
    'allowed_faculty_domains' => null,

    // Storage Quotas (Phase 8 & 9)
    'student_quota_bytes' => 25 * 1024 * 1024, // 25 MB per student
    'global_storage_budget_bytes' => 10 * 1024 * 1024 * 1024, // 10 GB free tier budget
    'storage_warning_thresholds' => [
        'warning_tier1' => 0.70, // 70% capacity
        'warning_tier2' => 0.85, // 85% capacity
        'critical_tier3' => 0.95 // 95% capacity: non-essential uploads restricted
    ],

    // Security & Authentication Limits
    'max_login_attempts' => 5,
    'lockout_minutes' => 10,
    'verification_token_lifetime_hours' => 24,
    'password_reset_lifetime_hours' => 2,

    // Supported Academic Departments (MIT-ADT)
    'departments' => [
        'SOC' => 'School of Computing (SOC)',
        'IT' => 'Information Technology (IT)',
        'SOE' => 'School of Engineering (SOE)',
        'SOD' => 'School of Design (SOD)',
        'MANET' => 'Maharashtra Academy of Naval Education & Training (MANET)'
    ],

    // Standard Specializations / Programmes
    'specializations' => [
        'BTech CSE - Core',
        'BTech CSE - Artificial Intelligence and Analytics',
        'BTech CSE - Cyber Security',
        'BTech IT - Data Analytics',
        'BTech IT - Software and Mobile Application Development',
        'BTech IT - Cloud Computing'
    ],

    // Standard Faculty Designations
    'faculty_designations' => [
        'Assistant Professor',
        'Associate Professor',
        'Professor',
        'Head of Department (HOD)',
        'Adjunct Professor',
        'Visiting Faculty'
    ],

    // Academic Years & Semesters
    'academic_years' => ['2026-27', '2025-26', '2024-25'],
    'years_of_study' => ['FY', 'SY', 'TY', 'Final Year'],
    'semesters' => ['SEM I', 'SEM II', 'SEM III', 'SEM IV', 'SEM V', 'SEM VI', 'SEM VII', 'SEM VIII']
];

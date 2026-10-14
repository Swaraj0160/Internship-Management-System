<?php

/*
 * ReportDataProvider — read-only data layer for admin/reports.php (the
 * "central report generation" module).
 *
 * WHY A NEW FILE INSTEAD OF EDITING EvaluationDataProvider.php:
 * EvaluationDataProvider.php is already relied on by the existing
 * Student Evaluations Excel/PDF exports. This Reports module needs a
 * larger filter set than that class exposes (academic year, semester,
 * marks-range buckets, sorting) plus dependent-dropdown option lists.
 * Extending EvaluationDataProvider's signature would risk changing
 * behavior for pages that already depend on it. This file is additive
 * only — it reuses the exact same join, the exact same SY/TY
 * detection rule, and the exact same Total (50) formula that
 * EvaluationDataProvider.php and TYEvaluationDataProvider.php already
 * use, so results here can never silently diverge from what those
 * pages already show.
 *
 * SY vs TY detection (matches EvaluationDataProvider.php exactly):
 *   - SY: users.year_of_study IS NULL OR '' OR UPPER(TRIM(...)) = 'SECOND'
 *   - TY: UPPER(TRIM(users.year_of_study)) IN ('THIRD','TY')
 *
 * This file only ever SELECTs. No INSERT/UPDATE/DELETE/ALTER/DROP.
 */

class ReportDataProvider
{
    /** Bucket definitions for the marks-range filters (Section 1.H). */
    const BUCKETS = [
        'final_total' => [
            ['0-10', 0, 10], ['10-20', 10, 20], ['20-30', 20, 30],
            ['30-40', 30, 40], ['40-50', 40, 50],
        ],
        'final_eval' => [
            ['0-5', 0, 5], ['5-10', 5, 10], ['10-15', 10, 15], ['15-20', 15, 20],
        ],
        'weekly_total' => [
            ['0-10', 0, 10], ['10-20', 10, 20], ['20-30', 20, 30], ['30-40', 30, 40],
        ],
        'mentor_eval' => [
            ['0-10', 0, 10], ['10-20', 10, 20], ['20-30', 20, 30], ['30-40', 30, 40],
        ],
    ];

    const SORT_EXPRESSIONS = [
        'roll'         => "CAST(COALESCE(NULLIF(users.ty_roll_no,''), NULLIF(users.roll_no,''), '0') AS UNSIGNED)",
        'name'         => "users.full_name",
        'enrollment'   => "users.enrollment_no",
        'weekly_total' => "student_evaluation.weekly_total",
        'mentor'       => "student_evaluation.mentor_evaluation",
        'final'        => "student_evaluation.final_evaluation",
        'total'        => "((COALESCE(student_evaluation.weekly_total,0)+COALESCE(student_evaluation.mentor_evaluation,0)+COALESCE(student_evaluation.final_evaluation,0))/2)",
    ];

    const SORT_LABELS = [
        'roll'         => 'Roll Number',
        'name'         => 'Student Name',
        'enrollment'   => 'Enrollment Number',
        'weekly_total' => 'Weekly Total',
        'mentor'       => 'Mentor Evaluation',
        'final'        => 'Final Evaluation',
        'total'        => 'Final Total',
    ];

    /* =========================================================
       Parse GET params -> a normalized, validated filter set.
       Used identically by reports.php and every export endpoint,
       so "what's on screen" and "what's exported" can never drift.
       ========================================================= */
    public static function parseFilters(array $get): array
    {
        $level = strtolower(trim($get['level'] ?? 'all'));
        if (!in_array($level, ['all', 'sy', 'ty'], true)) {
            $level = 'all';
        }

        $sortBy = trim($get['sort_by'] ?? 'roll');
        if (!array_key_exists($sortBy, self::SORT_EXPRESSIONS)) {
            $sortBy = 'roll';
        }

        $order = strtoupper(trim($get['order'] ?? 'ASC'));
        if (!in_array($order, ['ASC', 'DESC'], true)) {
            $order = 'ASC';
        }

        $bucketOf = function (string $key, string $raw) {
            foreach (self::BUCKETS[$key] as [$val, $min, $max]) {
                if ($raw === $val) {
                    return [$min, $max];
                }
            }
            return null;
        };

        return [
            'level'          => $level,
            'academic_year'  => trim($get['academic_year'] ?? ''),
            'semester'       => trim($get['semester'] ?? ''),
            'specialization' => trim($get['specialization'] ?? ''),
            'class'          => trim($get['class'] ?? ''),
            'batch'          => trim($get['batch'] ?? ''),
            'search'         => trim($get['search'] ?? ''),
            'weekly_total_bucket' => trim($get['weekly_total_bucket'] ?? ''),
            'mentor_eval_bucket'  => trim($get['mentor_eval_bucket'] ?? ''),
            'final_eval_bucket'   => trim($get['final_eval_bucket'] ?? ''),
            'final_total_bucket'  => trim($get['final_total_bucket'] ?? ''),
            'weekly_total_range'  => $bucketOf('weekly_total', trim($get['weekly_total_bucket'] ?? '')),
            'mentor_eval_range'   => $bucketOf('mentor_eval', trim($get['mentor_eval_bucket'] ?? '')),
            'final_eval_range'    => $bucketOf('final_eval', trim($get['final_eval_bucket'] ?? '')),
            'final_total_range'   => $bucketOf('final_total', trim($get['final_total_bucket'] ?? '')),
            'sort_by'        => $sortBy,
            'order'          => $order,
            'applied'        => isset($get['applied']) && $get['applied'] === '1',
        ];
    }

    /* =========================================================
       WHERE-clause builder shared by getRows() and the dropdown
       option queries (each dropdown is scoped by every filter that
       logically comes BEFORE it, per the required filter sequence).
       ========================================================= */
    private static function buildWhere(array $filters, array $upTo = []): array
    {
        $where = ["users.role='student'"];
        $params = [];
        $types = '';

        $level = $upTo['level'] ?? $filters['level'];
        if ($level === 'sy') {
            $where[] = "(users.year_of_study IS NULL OR users.year_of_study = '' OR UPPER(TRIM(users.year_of_study)) = 'SECOND')";
        } elseif ($level === 'ty') {
            $where[] = "(UPPER(TRIM(users.year_of_study)) = 'THIRD' OR UPPER(TRIM(users.year_of_study)) = 'TY')";
        }

        if (array_key_exists('academic_year', $upTo) ? $upTo['academic_year'] !== '' : $filters['academic_year'] !== '') {
            $where[] = "users.academic_year=?";
            $params[] = $upTo['academic_year'] ?? $filters['academic_year'];
            $types .= 's';
        }

        if (array_key_exists('semester', $upTo) ? $upTo['semester'] !== '' : $filters['semester'] !== '') {
            $where[] = "users.semester=?";
            $params[] = $upTo['semester'] ?? $filters['semester'];
            $types .= 's';
        }

        if (array_key_exists('specialization', $upTo) ? $upTo['specialization'] !== '' : $filters['specialization'] !== '') {
            $where[] = "users.specialization=?";
            $params[] = $upTo['specialization'] ?? $filters['specialization'];
            $types .= 's';
        }

        if (array_key_exists('class', $upTo) ? $upTo['class'] !== '' : $filters['class'] !== '') {
            $classVal = $upTo['class'] ?? $filters['class'];
            $where[] = "(users.class_division=? OR users.division=?)";
            $params[] = $classVal;
            $params[] = $classVal;
            $types .= 'ss';
        }

        if (empty($upTo['stopBeforeBatch'])) {
            if ($filters['batch'] !== '') {
                $where[] = "users.batch=?";
                $params[] = $filters['batch'];
                $types .= 's';
            }

            if ($filters['search'] !== '') {
                $like = '%' . $filters['search'] . '%';
                $where[] = "(users.full_name LIKE ? OR users.enrollment_no LIKE ? OR users.roll_no LIKE ? OR users.ty_roll_no LIKE ?)";
                $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
                $types .= 'ssss';
            }

            if ($filters['weekly_total_range']) {
                [$min, $max] = $filters['weekly_total_range'];
                $where[] = "(student_evaluation.weekly_total IS NOT NULL AND student_evaluation.weekly_total >= ? AND student_evaluation.weekly_total <= ?)";
                $params[] = $min; $params[] = $max;
                $types .= 'dd';
            }
            if ($filters['mentor_eval_range']) {
                [$min, $max] = $filters['mentor_eval_range'];
                $where[] = "(student_evaluation.mentor_evaluation IS NOT NULL AND student_evaluation.mentor_evaluation >= ? AND student_evaluation.mentor_evaluation <= ?)";
                $params[] = $min; $params[] = $max;
                $types .= 'dd';
            }
            if ($filters['final_eval_range']) {
                [$min, $max] = $filters['final_eval_range'];
                $where[] = "(student_evaluation.final_evaluation IS NOT NULL AND student_evaluation.final_evaluation >= ? AND student_evaluation.final_evaluation <= ?)";
                $params[] = $min; $params[] = $max;
                $types .= 'dd';
            }
            if ($filters['final_total_range']) {
                [$min, $max] = $filters['final_total_range'];
                $where[] = "((COALESCE(student_evaluation.weekly_total,0)+COALESCE(student_evaluation.mentor_evaluation,0)+COALESCE(student_evaluation.final_evaluation,0))/2 >= ? AND (COALESCE(student_evaluation.weekly_total,0)+COALESCE(student_evaluation.mentor_evaluation,0)+COALESCE(student_evaluation.final_evaluation,0))/2 <= ?)";
                $params[] = $min; $params[] = $max;
                $types .= 'dd';
            }
        }

        return [implode(' AND ', $where), $params, $types];
    }

    /* =========================================================
       Main report query — SELECT only.
       ========================================================= */
    public static function getRows(mysqli $conn, array $filters): array
    {
        [$whereSql, $params, $types] = self::buildWhere($filters);

        $sortExpr = self::SORT_EXPRESSIONS[$filters['sort_by']];
        $order = $filters['order'];

        $sql = "
            SELECT
                users.id,
                users.full_name,
                users.enrollment_no,
                users.division,
                users.roll_no,
                users.year_of_study,
                users.academic_year,
                users.semester,
                users.class_division,
                users.specialization,
                users.batch,
                users.ty_roll_no,
                student_evaluation.week1_marks,
                student_evaluation.week2_marks,
                student_evaluation.week3_marks,
                student_evaluation.week4_marks,
                student_evaluation.weekly_total,
                student_evaluation.mentor_evaluation,
                student_evaluation.final_evaluation

            FROM users

            LEFT JOIN student_evaluation
                ON student_evaluation.student_id = users.id

            WHERE $whereSql

            ORDER BY $sortExpr $order,
                CAST(COALESCE(NULLIF(users.ty_roll_no,''), NULLIF(users.roll_no,''), '0') AS UNSIGNED) ASC,
                users.full_name ASC
        ";

        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            throw new RuntimeException('Report query failed to prepare: ' . $conn->error);
        }
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = self::mapRow($row);
        }
        $stmt->close();

        return $rows;
    }

    /**
     * Transform one raw joined users+student_evaluation row into the
     * normalized shape the report page / exports consume. Pulled out
     * as its own method (rather than inline in getRows()'s while loop)
     * so it can be exercised directly with fabricated row arrays in
     * tests, without needing a live database connection.
     */
    public static function mapRow(array $row): array
    {
        $isTY = self::isTY($row['year_of_study'] ?? null);

        $weekly = self::numOrNull($row['weekly_total'] ?? null);
        $mentor = self::numOrNull($row['mentor_evaluation'] ?? null);
        $final  = self::numOrNull($row['final_evaluation'] ?? null);

        // Existing, unchanged formula: (Weekly + Mentor + Final) / 2,
        // treating a genuinely missing component as 0 in the sum only
        // — exactly what EvaluationDataProvider.php / TYEvaluationDataProvider.php
        // already do for Total (50).
        $total50 = ((float)($weekly ?? 0) + (float)($mentor ?? 0) + (float)($final ?? 0)) / 2;

        $week1 = self::numOrNull($row['week1_marks'] ?? null);
        $week2 = self::numOrNull($row['week2_marks'] ?? null);
        $week3 = self::numOrNull($row['week3_marks'] ?? null);
        $week4 = self::numOrNull($row['week4_marks'] ?? null);

        $hasAnyEvaluation = ($weekly !== null || $mentor !== null || $final !== null
            || $week1 !== null || $week2 !== null || $week3 !== null || $week4 !== null);

        return [
            'level'          => $isTY ? 'TY' : 'SY',
            'level_label'    => $isTY ? 'Third Year' : 'Second Year',
            'academic_year'  => $row['academic_year'] ?: '',
            'semester'       => $row['semester'] ?: '',
            'specialization' => $isTY ? ($row['specialization'] ?: '') : '',
            'class_label'    => $isTY ? ($row['class_division'] ?: '') : ($row['division'] ?: ''),
            'batch'          => $row['batch'] ?: '',
            'enrollment_no'  => $row['enrollment_no'] ?: '',
            'roll_label'     => $isTY ? ($row['ty_roll_no'] ?: '') : ($row['roll_no'] ?: ''),
            'full_name'      => $row['full_name'] ?: '',
            'week1'          => $week1,
            'week2'          => $week2,
            'week3'          => $week3,
            'week4'          => $week4,
            'weekly_total'   => $weekly,
            'mentor_evaluation' => $mentor,
            'final_evaluation'  => $final,
            'total_50'       => $total50,
            'has_evaluation' => $hasAnyEvaluation,
        ];
    }

    private static function isTY($yearOfStudy): bool
    {
        $v = strtoupper(trim((string)$yearOfStudy));
        return ($v === 'THIRD' || $v === 'TY');
    }

    /** NULL/'' stays NULL (a genuinely missing mark); numeric strings become floats/ints. */
    private static function numOrNull($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null; // malformed value — treated as missing, never guessed
        }
        $f = (float)$value;
        return ($f == (int)$f) ? (int)$f : $f;
    }

    /* =========================================================
       Dependent dropdown option lists.
       Each list is scoped by every filter that precedes it in the
       required visual sequence: Level -> Academic Year -> Semester
       -> Specialization -> Class -> Batch.
       ========================================================= */
    public static function getFilterOptionSets(mysqli $conn, array $filters): array
    {
        $academicYears = self::distinctColumn($conn, 'academic_year', [
            'level' => $filters['level'],
        ], 'academic_year DESC');

        $semesters = self::distinctColumn($conn, 'semester', [
            'level' => $filters['level'],
            'academic_year' => $filters['academic_year'],
        ], 'semester ASC');

        $specializations = ($filters['level'] === 'sy') ? [] : self::distinctColumn($conn, 'specialization', [
            'level' => ($filters['level'] === 'all') ? 'ty' : $filters['level'],
            'academic_year' => $filters['academic_year'],
            'semester' => $filters['semester'],
        ], 'specialization ASC');

        $classes = self::getClassOptions($conn, $filters);

        $batches = self::distinctColumn($conn, 'batch', [
            'level' => $filters['level'],
            'academic_year' => $filters['academic_year'],
            'semester' => $filters['semester'],
            'specialization' => $filters['specialization'],
            'class' => $filters['class'],
            'stopBeforeBatch' => false,
        ], 'batch ASC');

        return compact('academicYears', 'semesters', 'specializations', 'classes', 'batches');
    }

    private static function distinctColumn(mysqli $conn, string $column, array $upTo, string $orderBy): array
    {
        $upTo['stopBeforeBatch'] = true; // never apply search/marks/batch itself when scoping a dropdown
        [$whereSql, $params, $types] = self::buildWhere(self::emptyFilters(), $upTo);

        $sql = "SELECT DISTINCT users.$column AS v FROM users WHERE $whereSql
                AND users.$column IS NOT NULL AND TRIM(users.$column) <> ''
                ORDER BY $orderBy";

        $stmt = $conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($r = $res->fetch_assoc()) {
            $out[] = $r['v'];
        }
        $stmt->close();
        return $out;
    }

    /**
     * Class/Division options: TY rows contribute class_division values,
     * SY rows contribute division values — queried and merged with the
     * same IT-DA/IT-SMAD-first, then numeric-suffix ordering already
     * used elsewhere in the portal for SY, and the CORE/CC/CSF/AIEC/
     * BDCE/AIA/DA/SMAD-then-numeric ordering already used for TY.
     */
    private static function getClassOptions(mysqli $conn, array $filters): array
    {
        $out = [];

        if ($filters['level'] !== 'ty') {
            $upTo = ['level' => 'sy', 'stopBeforeBatch' => true];
            [$whereSql, $params, $types] = self::buildWhere(self::emptyFilters(), $upTo);
            $sql = "SELECT DISTINCT users.division AS v FROM users WHERE $whereSql
                    AND users.division IS NOT NULL AND TRIM(users.division) <> ''
                    ORDER BY CASE users.division WHEN 'IT-DA' THEN 0 WHEN 'IT-SMAD' THEN 1 ELSE 2 END ASC,
                             CAST(SUBSTRING_INDEX(users.division, '-', -1) AS UNSIGNED) ASC,
                             users.division ASC";
            $stmt = $conn->prepare($sql);
            if (!empty($params)) { $stmt->bind_param($types, ...$params); }
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) { $out[] = $r['v']; }
            $stmt->close();
        }

        if ($filters['level'] !== 'sy') {
            $upTo = [
                'level' => 'ty',
                'academic_year' => $filters['academic_year'],
                'semester' => $filters['semester'],
                'specialization' => $filters['specialization'],
                'stopBeforeBatch' => true,
            ];
            [$whereSql, $params, $types] = self::buildWhere(self::emptyFilters(), $upTo);
            $sql = "SELECT DISTINCT users.class_division AS v FROM users WHERE $whereSql
                    AND users.class_division IS NOT NULL AND TRIM(users.class_division) <> ''
                    ORDER BY
                        CASE SUBSTRING_INDEX(SUBSTRING_INDEX(users.class_division, '-', 2), '-', -1)
                            WHEN 'CORE' THEN 1 WHEN 'CC' THEN 2 WHEN 'CSF' THEN 3 WHEN 'AIEC' THEN 4
                            WHEN 'BDCE' THEN 5 WHEN 'AIA' THEN 6 WHEN 'DA' THEN 7 WHEN 'SMAD' THEN 8 ELSE 9
                        END ASC,
                        CAST(SUBSTRING_INDEX(users.class_division, '-', -1) AS UNSIGNED) ASC,
                        users.class_division ASC";
            $stmt = $conn->prepare($sql);
            if (!empty($params)) { $stmt->bind_param($types, ...$params); }
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) { $out[] = $r['v']; }
            $stmt->close();
        }

        return $out;
    }

    private static function emptyFilters(): array
    {
        return [
            'level' => 'all', 'academic_year' => '', 'semester' => '', 'specialization' => '',
            'class' => '', 'batch' => '', 'search' => '',
            'weekly_total_range' => null, 'mentor_eval_range' => null,
            'final_eval_range' => null, 'final_total_range' => null,
        ];
    }

    /* =========================================================
       Summary (Section 4)
       ========================================================= */
    public static function summarize(array $rows): array
    {
        $total = count($rows);
        $withEval = 0;
        $weeklySum = 0; $weeklyCnt = 0;
        $mentorSum = 0; $mentorCnt = 0;
        $finalSum = 0; $finalCnt = 0;
        $totalSum = 0;
        $highest = null; $lowest = null;

        foreach ($rows as $r) {
            if ($r['has_evaluation']) $withEval++;
            if ($r['weekly_total'] !== null) { $weeklySum += $r['weekly_total']; $weeklyCnt++; }
            if ($r['mentor_evaluation'] !== null) { $mentorSum += $r['mentor_evaluation']; $mentorCnt++; }
            if ($r['final_evaluation'] !== null) { $finalSum += $r['final_evaluation']; $finalCnt++; }
            $totalSum += $r['total_50'];
            if ($highest === null || $r['total_50'] > $highest) $highest = $r['total_50'];
            if ($lowest === null || $r['total_50'] < $lowest) $lowest = $r['total_50'];
        }

        return [
            'total_students' => $total,
            'with_evaluation' => $withEval,
            'without_evaluation' => $total - $withEval,
            'avg_weekly' => $weeklyCnt ? round($weeklySum / $weeklyCnt, 1) : null,
            'avg_mentor' => $mentorCnt ? round($mentorSum / $mentorCnt, 1) : null,
            'avg_final' => $finalCnt ? round($finalSum / $finalCnt, 1) : null,
            'avg_total' => $total ? round($totalSum / $total, 1) : null,
            'highest_total' => $highest,
            'lowest_total' => $lowest,
        ];
    }

    /* =========================================================
       Applied-filters description, in the required fixed order,
       reused identically on-screen, in Excel, CSV, and PDF.
       ========================================================= */
    public static function describeFilters(array $filters): array
    {
        $levelLabels = ['all' => 'All Students', 'sy' => 'Second Year (SY)', 'ty' => 'Third Year (TY)'];

        $lines = [];
        $lines[] = ['Student Level', $levelLabels[$filters['level']] ?? 'All Students'];
        $lines[] = ['Academic Year', $filters['academic_year'] !== '' ? $filters['academic_year'] : 'All Years'];
        $lines[] = ['Semester', $filters['semester'] !== '' ? $filters['semester'] : 'All Semesters'];
        $lines[] = ['Specialization', $filters['specialization'] !== '' ? $filters['specialization'] : 'All Specializations'];
        $lines[] = ['Class / Division', $filters['class'] !== '' ? $filters['class'] : 'All Classes'];
        $lines[] = ['Batch', $filters['batch'] !== '' ? $filters['batch'] : 'All Batches'];

        if ($filters['search'] !== '') {
            $lines[] = ['Search', $filters['search']];
        }
        if ($filters['weekly_total_bucket'] !== '') {
            $lines[] = ['Weekly Total', $filters['weekly_total_bucket']];
        }
        if ($filters['mentor_eval_bucket'] !== '') {
            $lines[] = ['Mentor Evaluation', $filters['mentor_eval_bucket']];
        }
        if ($filters['final_eval_bucket'] !== '') {
            $lines[] = ['Final Evaluation', $filters['final_eval_bucket']];
        }
        if ($filters['final_total_bucket'] !== '') {
            $lines[] = ['Final Total', $filters['final_total_bucket']];
        }
        $lines[] = ['Sort By', (self::SORT_LABELS[$filters['sort_by']] ?? 'Roll Number') . ' (' . ($filters['order'] === 'DESC' ? 'Descending' : 'Ascending') . ')'];

        return $lines;
    }

    public static function display($value): string
    {
        return ($value === null || $value === '') ? '-' : (string)$value;
    }
}
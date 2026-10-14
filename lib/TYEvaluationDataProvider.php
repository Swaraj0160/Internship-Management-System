<?php

class TYEvaluationDataProvider
{
    /**
     * Get TY evaluation data with filters
     */
    public static function getRows(mysqli $conn, $academic_year = '', $semester = '', $specialization = '', $class_division = '', $batch = '', $search = ''): array
    {
        $where = ["users.role='student'", "users.year_of_study='TY'"];
        $params = [];
        $types = "";

        if($academic_year !== '' && $academic_year !== null)
        {
            $where[] = "users.academic_year=?";
            $params[] = $academic_year;
            $types .= "s";
        }

        if($semester !== '' && $semester !== null)
        {
            $where[] = "users.semester=?";
            $params[] = $semester;
            $types .= "s";
        }

        if($specialization !== '' && $specialization !== null)
        {
            $where[] = "users.specialization=?";
            $params[] = $specialization;
            $types .= "s";
        }

        if($class_division !== '' && $class_division !== null)
        {
            $where[] = "users.class_division=?";
            $params[] = $class_division;
            $types .= "s";
        }

        if($batch !== '' && $batch !== null)
        {
            $where[] = "users.batch=?";
            $params[] = $batch;
            $types .= "s";
        }

        if($search !== '' && $search !== null)
        {
            $where[] = "(users.enrollment_no LIKE ? OR users.full_name LIKE ? OR users.ty_roll_no LIKE ?)";
            $like = "%" . $search . "%";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $types .= "sss";
        }

        $whereSql = implode(" AND ", $where);

        $query = "
            SELECT
                users.id,
                users.enrollment_no,
                users.ty_roll_no,
                users.full_name,
                users.academic_year,
                users.semester,
                users.class_division,
                users.specialization,
                users.batch,
                student_evaluation.week1_marks,
                student_evaluation.week2_marks,
                student_evaluation.week3_marks,
                student_evaluation.week4_marks,
                student_evaluation.weekly_total,
                student_evaluation.mentor_evaluation,
                student_evaluation.final_evaluation

            FROM users

            LEFT JOIN student_evaluation ON users.id = student_evaluation.student_id

            WHERE $whereSql

            ORDER BY
                CASE SUBSTRING_INDEX(SUBSTRING_INDEX(users.class_division, '-', 2), '-', -1)
                    WHEN 'CORE' THEN 1
                    WHEN 'CC' THEN 2
                    WHEN 'CSF' THEN 3
                    WHEN 'AIEC' THEN 4
                    WHEN 'BDCE' THEN 5
                    WHEN 'AIA' THEN 6
                    WHEN 'DA' THEN 7
                    WHEN 'SMAD' THEN 8
                    ELSE 9
                END ASC,
                CAST(SUBSTRING_INDEX(users.class_division, '-', -1) AS UNSIGNED) ASC,
                users.batch ASC,
                CAST(users.ty_roll_no AS UNSIGNED) ASC
        ";

        $stmt = $conn->prepare($query);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];

        while ($row = $result->fetch_assoc()) {
            $week1 = self::formatValue($row['week1_marks']);
            $week2 = self::formatValue($row['week2_marks']);
            $week3 = self::formatValue($row['week3_marks']);
            $week4 = self::formatValue($row['week4_marks']);
            $weeklyTotal = self::formatValue($row['weekly_total']);
            $mentor = self::formatValue($row['mentor_evaluation']);
            $final = self::formatValue($row['final_evaluation']);

            $total = ($weeklyTotal + $mentor + $final) / 2;

            $rows[] = [
                'enrollment_no' => $row['enrollment_no'],
                'ty_roll_no' => $row['ty_roll_no'],
                'full_name' => $row['full_name'],
                'academic_year' => $row['academic_year'],
                'semester' => $row['semester'],
                'class_division' => $row['class_division'],
                'specialization' => $row['specialization'],
                'batch' => $row['batch'],
                'week1' => $week1,
                'week2' => $week2,
                'week3' => $week3,
                'week4' => $week4,
                'weekly_total' => $weeklyTotal,
                'mentor' => $mentor,
                'final' => $final,
                'total' => $total
            ];
        }

        $stmt->close();

        return ['rows' => $rows];
    }

    /**
     * Get data quality warnings for TY students
     */
    public static function getWarnings(mysqli $conn, $academic_year = '', $semester = '', $specialization = '', $class_division = '', $batch = '', $search = ''): array
    {
        $warnings = [];

        $where = ["users.role='student'", "users.year_of_study='TY'"];
        $params = [];
        $types = "";

        if($academic_year !== '') { $where[] = "users.academic_year=?"; $params[] = $academic_year; $types .= "s"; }
        if($semester !== '') { $where[] = "users.semester=?"; $params[] = $semester; $types .= "s"; }
        if($specialization !== '') { $where[] = "users.specialization=?"; $params[] = $specialization; $types .= "s"; }
        if($class_division !== '') { $where[] = "users.class_division=?"; $params[] = $class_division; $types .= "s"; }
        if($batch !== '') { $where[] = "users.batch=?"; $params[] = $batch; $types .= "s"; }
        if($search !== '') { $where[] = "(users.enrollment_no LIKE ? OR users.full_name LIKE ? OR users.ty_roll_no LIKE ?)"; $like = "%$search%"; $params[] = $like; $params[] = $like; $params[] = $like; $types .= "sss"; }

        $whereSql = implode(" AND ", $where);

        $query = "
            SELECT
                users.full_name,
                users.enrollment_no,
                student_evaluation.week1_marks,
                student_evaluation.week2_marks,
                student_evaluation.week3_marks,
                student_evaluation.week4_marks,
                student_evaluation.weekly_total,
                student_evaluation.mentor_evaluation,
                student_evaluation.final_evaluation

            FROM users

            LEFT JOIN student_evaluation ON users.id = student_evaluation.student_id

            WHERE $whereSql
        ";

        $stmt = $conn->prepare($query);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $markFields = ['week1_marks' => 'Week 1', 'week2_marks' => 'Week 2', 'week3_marks' => 'Week 3', 'week4_marks' => 'Week 4', 'weekly_total' => 'Weekly Total', 'mentor_evaluation' => 'Mentor Evaluation', 'final_evaluation' => 'Final Evaluation'];

        while ($row = $result->fetch_assoc()) {
            foreach ($markFields as $field => $label) {
                if ($row[$field] !== null && $row[$field] !== '') {
                    if (!is_numeric($row[$field])) {
                        $warnings[] = [
                            'student' => $row['full_name'],
                            'enrollment_no' => $row['enrollment_no'],
                            'field' => $label,
                            'raw_value' => $row[$field]
                        ];
                    }
                }
            }
        }

        $stmt->close();

        return $warnings;
    }

    private static function formatValue($value)
    {
        if ($value === null || $value === '') {
            return 0;
        }

        $numValue = (float)$value;

        if ($numValue == (int)$numValue) {
            return (int)$numValue;
        }

        return round($numValue, 1);
    }
}
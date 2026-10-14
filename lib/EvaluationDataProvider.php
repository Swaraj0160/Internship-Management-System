<?php

class EvaluationDataProvider
{
    /**
     * Get evaluation rows filtered by year_level and optional filters
     * 
     * @param mysqli $conn Database connection
     * @param array $filters Filter parameters:
     *   - year_level: 'second' | 'third' | 'all' (default)
     *   - division: SY division (e.g., 'IT-DA') - used only for year_level='second'
     *   - specialization: TY specialization - used only for year_level='third'
     *   - class_division: TY class division - used only for year_level='third'
     *   - batch: TY batch - used only for year_level='third'
     *   - search: Search by name/enrollment/roll - used only for year_level='third'
     * 
     * @return array ['rows' => [...], 'warnings' => [...]]
     */
    public static function getRows($conn, $filters = [])
    {
        // Normalize filter parameters
        $year_level = $filters['year_level'] ?? 'all';
        if(!in_array($year_level, ['all', 'second', 'third']))
        {
            $year_level = 'all';
        }

        $division = trim($filters['division'] ?? '');
        $specialization = trim($filters['specialization'] ?? '');
        $class_division = trim($filters['class_division'] ?? '');
        $batch = trim($filters['batch'] ?? '');
        $search = trim($filters['search'] ?? '');

        $where = ["users.role='student'"];
        $params = [];
        $types = "";

        // Year level filter
        if($year_level === 'second')
        {
            $where[] = "(users.year_of_study IS NULL OR users.year_of_study = '' OR UPPER(TRIM(users.year_of_study)) = 'SECOND')";
        }
        elseif($year_level === 'third')
        {
            $where[] = "(UPPER(TRIM(users.year_of_study)) = 'THIRD' OR UPPER(TRIM(users.year_of_study)) = 'TY')";
        }
        // For 'all', no year_level restriction

        // SY division filter (only for second year or all)
        if($year_level !== 'third' && $division !== '')
        {
            $where[] = "users.division=?";
            $params[] = $division;
            $types .= "s";
        }

        // TY specialization filter (only for third year)
        if($year_level !== 'second' && $specialization !== '')
        {
            $where[] = "users.specialization=?";
            $params[] = $specialization;
            $types .= "s";
        }

        // TY class_division filter (only for third year)
        if($year_level !== 'second' && $class_division !== '')
        {
            $where[] = "users.class_division=?";
            $params[] = $class_division;
            $types .= "s";
        }

        // TY batch filter (only for third year)
        if($year_level !== 'second' && $batch !== '')
        {
            $where[] = "users.batch=?";
            $params[] = $batch;
            $types .= "s";
        }

        // Search filter (only for third year)
        if($year_level !== 'second' && $search !== '')
        {
            $searchTerm = '%' . $search . '%';
            $where[] = "(users.full_name LIKE ? OR users.enrollment_no LIKE ? OR users.ty_roll_no LIKE ?)";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $types .= "sss";
        }

        $whereSql = implode(" AND ", $where);

        $query = "
            SELECT
                users.id,
                users.division,
                users.enrollment_no,
                users.roll_no,
                users.full_name,
                users.academic_year,
                users.year_of_study,
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
                student_evaluation.final_evaluation,
                student_evaluation.total_marks

            FROM users

            LEFT JOIN student_evaluation
                ON users.id = student_evaluation.student_id

            WHERE $whereSql

            ORDER BY
                CASE WHEN users.year_of_study IS NULL OR users.year_of_study = '' OR UPPER(TRIM(users.year_of_study)) = 'SECOND'
                    THEN 0
                    ELSE 1
                END ASC,
                CASE users.division
                    WHEN 'IT-DA'   THEN 0
                    WHEN 'IT-SMAD' THEN 1
                    ELSE 2
                END ASC,
                users.division ASC,
                CAST(COALESCE(users.roll_no, '0') AS UNSIGNED) ASC,
                users.class_division ASC,
                CAST(COALESCE(users.ty_roll_no, '0') AS UNSIGNED) ASC,
                users.full_name ASC
        ";

        $stmt = $conn->prepare($query);

        if(count($params) > 0)
        {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        $warnings = [];

        while($row = mysqli_fetch_assoc($result))
        {
            // Calculate total (out of 50)
            $weekly = ($row['weekly_total'] !== null && $row['weekly_total'] !== '') ? (float)$row['weekly_total'] : 0;
            $mentor = ($row['mentor_evaluation'] !== null && $row['mentor_evaluation'] !== '') ? (float)$row['mentor_evaluation'] : 0;
            $final = ($row['final_evaluation'] !== null && $row['final_evaluation'] !== '') ? (float)$row['final_evaluation'] : 0;

            $row['total'] = ($weekly + $mentor + $final) / 2;

            // Detect malformed marks values for warnings
            $fieldsToCheck = [
                'week1_marks', 'week2_marks', 'week3_marks', 'week4_marks',
                'weekly_total', 'mentor_evaluation', 'final_evaluation'
            ];

            foreach($fieldsToCheck as $field)
            {
                if($row[$field] !== null && $row[$field] !== '')
                {
                    if(!is_numeric($row[$field]) || ((float)$row[$field] != (int)$row[$field] && !is_numeric(str_replace('.', '', $row[$field]))))
                    {
                        // This looks like a malformed value like "Oct-50"
                        $warnings[] = [
                            'student' => $row['full_name'],
                            'enrollment_no' => $row['enrollment_no'],
                            'division' => $row['division'] ?? '',
                            'class_division' => $row['class_division'] ?? '',
                            'field' => $field,
                            'raw_value' => $row[$field]
                        ];
                    }
                }
            }

            $rows[] = $row;
        }

        $stmt->close();

        return [
            'rows' => $rows,
            'warnings' => $warnings
        ];
    }
}

class EvaluationDataProviderException extends Exception {}
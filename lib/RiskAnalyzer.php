<?php

/*
 * RiskAnalyzer — pure-code (no AI/LLM call) student risk detection.
 *
 * This is deliberately NOT an "AI" feature in the LLM sense — it is a
 * transparent, deterministic rule engine over your existing data. You
 * approved starting Risk Detection this way specifically because it
 * needs no API key and nothing here can hallucinate: every flag is a
 * concrete, explainable condition traced straight to a row in
 * internships / weekly_updates / weekly_deadlines / student_evaluation.
 *
 * -------------------------------------------------------------------
 * BUG FIX (post-implementation audit): the previous version of this
 * file's "missing weekly reports" check ran:
 *
 *   SELECT week_no, submitted_at, deadline_date FROM weekly_updates ...
 *
 * `deadline_date` does NOT exist on the weekly_updates table — it
 * only exists on weekly_deadlines. Every other page in this project
 * that queries weekly_updates confirms this (student/add_update.php,
 * student/my_updates.php, faculty/view_updates.php never reference
 * weekly_updates.deadline_date). Since PHP 8.1+, mysqli defaults to
 * throwing an exception on any SQL error instead of returning false,
 * so this single bad column reference caused an UNCAUGHT
 * mysqli_sql_exception ("Unknown column 'deadline_date' in
 * 'field list'") on every single request to this page — which is
 * exactly the HTTP 500 you reported. The column was never actually
 * used from that query (only $sub['submitted_at'] is read later); it
 * has simply been removed here, which is the correct, complete fix,
 * not a workaround.
 *
 * On top of that root-cause fix, this file now also defends against
 * any FUTURE query error surfacing as a raw, unexplained HTTP 500:
 * every mysqli call in analyze() is wrapped so that a database error
 * is caught and turned into a clear RiskAnalyzerException instead of
 * an uncaught fatal error — callers (admin/risk_insights.php,
 * faculty/risk_insights.php) catch this and show a readable error
 * message instead of a blank/broken page.
 * -------------------------------------------------------------------
 *
 * If you later want an LLM-written natural-language summary layered
 * on top of these flags that's a small additive step once an API key
 * is available — this file's output would simply become the input to
 * that summary call, unchanged.
 *
 * Classification thresholds below (e.g. "total_marks < 25 out of 50 =
 * low") are reasonable defaults, not something specified in your
 * request — treat them as adjustable constants at the top of this
 * file, not a fixed academic policy.
 */

class RiskAnalyzerException extends Exception
{
}

class RiskAnalyzer
{
    /* ---- Adjustable thresholds ---- */
    const LOW_TOTAL_MARKS_THRESHOLD = 25;      // out of 50 (the existing scheme's max)
    const LOW_WEEKLY_TOTAL_THRESHOLD = 20;     // out of 40
    const REPEATED_DELAY_COUNT = 2;            // 2+ late submissions flags "repeated delays"

    /**
     * Run a query and throw a RiskAnalyzerException with a clear
     * message instead of letting mysqli's own exception (or a raw
     * `false` result under older PHP/mysqli error-reporting modes)
     * turn into an unexplained HTTP 500 further up the stack.
     */
    private static function safeQuery(mysqli $conn, string $sql)
    {
        try {
            $result = mysqli_query($conn, $sql);
        } catch (mysqli_sql_exception $e) {
            throw new RiskAnalyzerException('Risk Insights database query failed: ' . $e->getMessage());
        }

        if ($result === false) {
            throw new RiskAnalyzerException('Risk Insights database query failed: ' . mysqli_error($conn));
        }

        return $result;
    }

    /**
     * @param mysqli   $conn
     * @param string[] $divisions  Divisions to include. Empty array = no restriction (admin/all).
     * @return array[] One row per student: name, enrollment_no, roll_no, division,
     *                  level ('green'|'yellow'|'red'), reasons (string[])
     * @throws RiskAnalyzerException on any underlying database error
     */
    public static function analyze(mysqli $conn, array $divisions = []): array
    {
        $where = ["users.role='student'"];
        if (!empty($divisions)) {
            $div_values = array_map(
                fn($d) => "'" . mysqli_real_escape_string($conn, $d) . "'",
                $divisions
            );
            $where[] = "users.division IN (" . implode(',', $div_values) . ")";
        }
        $whereSql = implode(" AND ", $where);

        $sql = "
            SELECT
                users.id AS student_id,
                users.full_name,
                users.enrollment_no,
                users.roll_no,
                users.division,

                internships.id AS internship_id,
                internships.status AS internship_status,
                internships.start_date,
                internships.end_date,
                internships.mentor_name,
                internships.company_contact,

                student_evaluation.week1_marks,
                student_evaluation.week2_marks,
                student_evaluation.week3_marks,
                student_evaluation.week4_marks,
                student_evaluation.weekly_total,
                student_evaluation.total_marks

            FROM users

            LEFT JOIN internships
                ON internships.id = (
                    SELECT MAX(i.id) FROM internships i WHERE i.student_id = users.id
                )

            LEFT JOIN student_evaluation
                ON student_evaluation.student_id = users.id

            WHERE $whereSql

            ORDER BY
                CASE users.division
                    WHEN 'IT-DA'   THEN 0
                    WHEN 'IT-SMAD' THEN 1
                    ELSE 2
                END ASC,
                users.division ASC,
                CAST(users.roll_no AS UNSIGNED) ASC
        ";

        $result = self::safeQuery($conn, $sql);
        $rows = [];

        while ($student = mysqli_fetch_assoc($result)) {
            $reasons = [];

            /* Signal 1: no internship at all */
            if (empty($student['internship_id'])) {
                $reasons[] = "No internship application on record";
            } else {
                /* Signal 2: internship record missing key fields */
                $missing = [];
                if (empty($student['start_date'])) $missing[] = "start date";
                if (empty($student['end_date'])) $missing[] = "end date";
                if (empty($student['mentor_name'])) $missing[] = "mentor name";
                if (!empty($missing)) {
                    $reasons[] = "Internship record incomplete: missing " . implode(", ", $missing);
                }

                if ($student['internship_status'] === 'Rejected') {
                    $reasons[] = "Internship application was rejected";
                }

                /* Signal 3: missing weekly reports past their deadline */
                $deadline_res = self::safeQuery(
                    $conn,
                    "SELECT week_no, deadline_date FROM weekly_deadlines
                     WHERE division='" . mysqli_real_escape_string($conn, $student['division']) . "'
                       AND deadline_date < NOW()"
                );

                /* BUG FIX: this SELECT previously also asked for a
                   weekly_updates.deadline_date column that doesn't
                   exist on this table — see file header. Only the
                   columns that actually exist on weekly_updates are
                   requested now. */
                $submitted_weeks = [];
                $submission_res = self::safeQuery(
                    $conn,
                    "SELECT week_no, submitted_at FROM weekly_updates
                     WHERE internship_id='" . (int)$student['internship_id'] . "'"
                );

                $late_count = 0;
                $submissions_by_week = [];
                while ($sub = mysqli_fetch_assoc($submission_res)) {
                    $submitted_weeks[(int)$sub['week_no']] = true;
                    $submissions_by_week[(int)$sub['week_no']] = $sub;
                }

                $missing_weeks = [];
                while ($dl = mysqli_fetch_assoc($deadline_res)) {
                    $week = (int)$dl['week_no'];
                    if (!isset($submitted_weeks[$week])) {
                        $missing_weeks[] = $week;
                    } elseif (
                        !empty($submissions_by_week[$week]['submitted_at']) &&
                        strtotime($submissions_by_week[$week]['submitted_at']) > strtotime($dl['deadline_date'])
                    ) {
                        $late_count++;
                    }
                }
                if (!empty($missing_weeks)) {
                    $reasons[] = "Missing weekly report(s) for Week " . implode(", ", $missing_weeks) . " (deadline already passed)";
                }

                if ($late_count >= self::REPEATED_DELAY_COUNT) {
                    $reasons[] = "Repeated late submissions ($late_count weeks submitted after deadline)";
                }

                /* Signal 4: low marks */
                if ($student['total_marks'] !== null && (float)$student['total_marks'] < self::LOW_TOTAL_MARKS_THRESHOLD) {
                    $reasons[] = "Total marks below threshold (" . $student['total_marks'] . " / 50)";
                } elseif ($student['weekly_total'] !== null && (float)$student['weekly_total'] < self::LOW_WEEKLY_TOTAL_THRESHOLD) {
                    $reasons[] = "Weekly total marks below threshold (" . $student['weekly_total'] . " / 40)";
                }
            }

            /* Classification: 0 signals = green, 1-2 = yellow, 3+ = red */
            $count = count($reasons);
            if ($count === 0) {
                $level = 'green';
            } elseif ($count <= 2) {
                $level = 'yellow';
            } else {
                $level = 'red';
            }

            $rows[] = [
                'student_id'     => $student['student_id'],
                'full_name'      => $student['full_name'],
                'enrollment_no'  => $student['enrollment_no'],
                'roll_no'        => $student['roll_no'],
                'division'       => $student['division'],
                'level'          => $level,
                'reasons'        => $reasons,
            ];
        }

        return $rows;
    }
}

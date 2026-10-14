<?php
class AcademicOrdering {
    public static array $ty_classes = ["TY-CORE-1", "TY-CORE-2", "TY-CORE-3", "TY-CORE-4", "TY-CC-1", "TY-CC-2", "TY-CC-3", "TY-CSF-1", "TY-CSF-2", "TY-AIEC-1", "TY-BDCE-1", "TY-AIA-1", "TY-AIA-2", "TY-AIA-3", "TY-AIA-4", "TY-AIA-5", "TY-AIA-6", "TY-AIA-7", "TY-AIA-8", "TY-AIA-9", "TY-AIA-10", "TY-AIA-11", "TY-AIA-12"];
    public static array $ty_specializations = ["CORE", "CC", "CSF", "AIEC", "BDCE", "AIA"];
    public static array $sy_divisions = ["IT-DA", "IT-SMAD", "SY-1", "SY-2", "SY-3", "SY-4", "SY-5", "SY-6", "SY-7", "SY-8", "SY-9", "SY-10", "SY-11", "SY-12", "SY-13", "SY-14", "SY-15", "SY-16", "SY-17", "SY-18", "SY-19", "SY-20", "SY-21", "SY-22"];
    public static array $domains = ["Artificial Intelligence", "Machine Learning", "Data Science / Data Analytics", "Software Development", "Web Development", "App Development", "Cyber Security", "Cloud Computing", "DevOps", "IoT", "Computer Vision", "Robotics", "Blockchain", "UI/UX", "Networking", "Research", "Needs Verification"];

    public static function getClassSortIndex(string $class): int {
        $idx = array_search($class, self::$ty_classes);
        return $idx !== false ? $idx : 9999;
    }

    public static function getSpecSortIndex(string $spec): int {
        $idx = array_search($spec, self::$ty_specializations);
        return $idx !== false ? $idx : 9999;
    }

    public static function getDivSortIndex(string $div): int {
        $idx = array_search($div, self::$sy_divisions);
        return $idx !== false ? $idx : 9999;
    }

    public static function getDomainSortIndex(string $domain): int {
        $idx = array_search($domain, self::$domains);
        return $idx !== false ? $idx : 9999;
    }

    public static function getSqlCase(string $column, array $values): string {
        $sql = "CASE $column ";
        foreach ($values as $index => $val) {
            $sql .= "WHEN '" . addslashes($val) . "' THEN $index ";
        }
        $sql .= "ELSE 9999 END";
        return $sql;
    }
    
    public static function sortArray(array &$array, array $reference): void {
        usort($array, function($a, $b) use ($reference) {
            $idxA = array_search($a, $reference);
            $idxB = array_search($b, $reference);
            if ($idxA === false) $idxA = 9999;
            if ($idxB === false) $idxB = 9999;
            return $idxA <=> $idxB;
        });
    }
}

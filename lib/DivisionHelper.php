<?php
/* -----------------------------------------------------------------
   NEW FILE — application-level division normalization.

   Per the Phase 2 requirement: normalize "DA" -> "IT-DA" and
   "SMAD" -> "IT-SMAD" (plus a few other loose variants that show up
   in real data — case differences, stray spaces, "IT DA" without the
   hyphen) at the APPLICATION layer only. This file never touches the
   database directly — no UPDATE statements live here. It is meant to
   be called:

     - When accepting user input that sets/changes a division value
       (admin "Manage Students"/"Manage Faculty" add/edit forms).
     - When displaying a division value that might be stored in an
       old/inconsistent format, so the UI always shows the canonical
       form even before any data cleanup has happened.

   Any actual bulk cleanup of existing rows (e.g. a one-time UPDATE to
   fix historical "DA"/"SMAD" values already sitting in the users
   table) is a DATA change, not an application change, and is
   deliberately NOT done here — see DATABASE_CHANGES.sql for an
   optional, commented-out, admin-reviewed statement for that instead.
   ----------------------------------------------------------------- */

class DivisionHelper
{
    /**
     * Normalize a single division string to its canonical form.
     * Returns the trimmed, uppercased input unchanged if it isn't one
     * of the known IT-DA / IT-SMAD variants (e.g. "SY-7" passes
     * through untouched other than trim/case-uppering of the prefix).
     */
    public static function normalize(?string $division): string
    {
        $value = trim((string)$division);

        if ($value === '') {
            return '';
        }

        // Collapse repeated whitespace, uppercase for comparison only.
        $compact = strtoupper(preg_replace('/\s+/', ' ', $value));
        $compact = str_replace(' ', '', $compact); // "IT DA" -> "ITDA", "IT-DA" -> "IT-DA" (hyphen kept)

        $knownDA = ['DA', 'IT-DA', 'ITDA', 'IT_DA'];
        $knownSMAD = ['SMAD', 'IT-SMAD', 'ITSMAD', 'IT_SMAD'];

        if (in_array($compact, $knownDA, true)) {
            return 'IT-DA';
        }

        if (in_array($compact, $knownSMAD, true)) {
            return 'IT-SMAD';
        }

        // SY-* divisions: normalize "SY 7", "sy-7", "SY7" -> "SY-7"
        if (preg_match('/^SY[\s\-_]?(\d{1,2})$/i', $value, $m)) {
            return 'SY-' . (int)$m[1];
        }

        // Unknown format — return trimmed original, unchanged, rather
        // than guessing. Never silently invent a division value.
        return $value;
    }

    /**
     * Normalize every division value in an array of division strings
     * (e.g. a faculty member's multiple assigned divisions), preserving
     * order and removing duplicates that only differed by formatting.
     */
    public static function normalizeList(array $divisions): array
    {
        $seen = [];
        $out = [];

        foreach ($divisions as $d) {
            $n = self::normalize($d);
            if ($n === '' || isset($seen[$n])) {
                continue;
            }
            $seen[$n] = true;
            $out[] = $n;
        }

        return $out;
    }

    /**
     * True if $division looks like a raw/legacy value that normalize()
     * would change — useful for the AI Data Quality Checker /
     * admin-facing "needs cleanup" flags, WITHOUT changing any data.
     */
    public static function needsNormalization(?string $division): bool
    {
        $original = trim((string)$division);
        if ($original === '') {
            return false;
        }
        return self::normalize($original) !== $original;
    }
}

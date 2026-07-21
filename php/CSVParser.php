<?php

/**
 * CSVParser - Parses Pickledraw registration CSV exports
 *
 * Handles multiple real-world column naming conventions including
 * Eventbrite exports, custom Google Form exports, and manual spreadsheets.
 *
 * Supports up to TWO nominated partners per player.
 */
class CSVParser
{
    // -------------------------------------------------------------------------
    // Column matching rules
    //
    // Three-pass resolution per column:
    //   Pass 1: exact match (after sanitise + lowercase)
    //   Pass 2: exact match after stripping trailing punctuation
    //   Pass 3: substring / keyword match (most specific patterns listed first)
    // -------------------------------------------------------------------------

    /** Exact lowercase header → internal key */
    private array $exactMap = [
        // Names
        'attendee first name'                                       => 'first_name',
        'first name'                                                => 'first_name',
        'firstname'                                                 => 'first_name',
        'attendee last name'                                        => 'last_name',
        'last name'                                                 => 'last_name',
        'lastname'                                                  => 'last_name',
        'full name'                                                 => 'full_name',
        'name'                                                      => 'full_name',
        'attendee name'                                             => 'full_name',

        // IDs
        'attendee id'                                               => 'attendee_id',
        'attendee #'                                                => 'attendee_id',
        'order id'                                                  => 'order_id',
        'order #'                                                   => 'order_id',
        'purchaser user id'                                         => 'purchaser_id',

        // Booking meta
        'date created'                                              => 'date_created',
        'status'                                                    => 'status',
        'ticket class'                                              => 'ticket_class',
        'ticket type'                                               => 'ticket_class',
        'ticket class price'                                        => 'ticket_price',
        'is guest'                                                  => 'is_guest',
        'checked in'                                                => 'checked_in',
        'checked in date'                                           => 'checked_in_date',

        // Partner — common exact variants
        'name of partner(s)'                                        => 'partner',
        "name of partner(s)"                                        => 'partner',
        'name of partners'                                          => 'partner',
        'partner'                                                   => 'partner',
        'partner name'                                              => 'partner',
        'partner names'                                             => 'partner',
        'doubles partner'                                           => 'partner',
        'doubles partner name'                                      => 'partner',

        // Division / skill — common exact variants
        'i am registered to play in a tournament at this level.'   => 'skill_level',
        'i am registered to play in a tournament at this level'    => 'skill_level',
        'i am registered to play in a tournament at this level:'   => 'skill_level',
        'tournament level'                                          => 'skill_level',
        'tournament division'                                       => 'skill_level',
        'division'                                                  => 'skill_level',
        'skill level'                                               => 'skill_level',
        'level'                                                     => 'skill_level',
        'rating'                                                    => 'skill_level',
        'registered level'                                          => 'skill_level',

        // DUPR — common exact variants
        'dupr'                                                      => 'dupr',
        'dupr rating'                                               => 'dupr',
        'dupr score'                                                => 'dupr',
        'my dupr'                                                   => 'dupr',
    ];

    /**
     * Substring keyword patterns → internal key.
     * Listed most-specific first. Checked only when exact match fails.
     * Pattern is tested with str_contains() against the full sanitised lowercase header.
     */
    private array $substringMap = [
        // Partner fields (check before generic "name" patterns)
        'name of partner'                           => 'partner',
        'partner/partners'                          => 'partner',
        'partner / partners'                        => 'partner',
        'please name your partner'                  => 'partner',
        'name your partner'                         => 'partner',
        'partner(s)'                                => 'partner',
        'partners (2 maximum)'                      => 'partner',
        'partners (2'                               => 'partner',
        'your partner'                              => 'partner',
        'doubles partner'                           => 'partner',

        // Division / tournament level (check before generic "level"/"rating")
        'registered to play in a tournament'        => 'skill_level',
        'tournament at this level'                  => 'skill_level',
        'at this level'                             => 'skill_level',
        'select the dupr that you are registered'   => 'skill_level',
        'dupr that you are registered'              => 'skill_level',
        'registered in for a tournament'            => 'skill_level',
        'plan to register in for a tournament'      => 'skill_level',
        'register in for a tournament'              => 'skill_level',
        'tournament you are registered'             => 'skill_level',
        'what division'                             => 'skill_level',
        'which division'                            => 'skill_level',
        'tournament division'                       => 'skill_level',

        // DUPR (check before generic "rating")
        'please enter your dupr'                    => 'dupr',
        'enter your dupr'                           => 'dupr',
        'your dupr'                                 => 'dupr',
        'best guess'                                => 'dupr',   // "or best guess if you don't have..."
        "don't have a dupr"                         => 'dupr',
        'dupr rating'                               => 'dupr',
        'dupr score'                                => 'dupr',
        'dupr ('                                    => 'dupr',

        // Name fields
        'attendee first'                            => 'first_name',
        'first name'                                => 'first_name',
        'attendee last'                             => 'last_name',
        'last name'                                 => 'last_name',

        // IDs / meta
        'attendee id'                               => 'attendee_id',
        'order id'                                  => 'order_id',
        'ticket class'                              => 'ticket_class',
        'checked in'                                => 'checked_in',
    ];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    private array $lastColumnMap = [];

    public function getLastColumnMap(): array
    {
        return $this->lastColumnMap;
    }

    public function parse(string $rawData, string $delimiter = 'auto'): array
    {
        // Strip UTF-8 BOM
        if (str_starts_with($rawData, "\xEF\xBB\xBF")) {
            $rawData = substr($rawData, 3);
        }

        $rawData = $this->normalizeLineEndings($rawData);
        $lines   = array_values(array_filter(
            explode("\n", $rawData),
            fn($l) => trim($l) !== ''
        ));

        if (empty($lines)) {
            throw new Exception('No data found in the input.');
        }

        $delimiter = $this->detectDelimiter($rawData, $delimiter);
        $rows      = $this->parseRows($lines, $delimiter);

        if (count($rows) < 2) {
            throw new Exception('File must have a header row and at least one data row.');
        }

        $colIndex             = $this->buildColumnIndex($rows[0]);
        $this->lastColumnMap  = $colIndex;
        $dataRows             = array_slice($rows, 1);

        $players = [];
        foreach ($dataRows as $lineNum => $row) {
            if (count(array_filter($row, fn($c) => trim($c) !== '')) === 0) continue;
            $player = $this->extractPlayer($row, $colIndex, $lineNum + 2);
            if ($player !== null) {
                $players[] = $player;
            }
        }

        if (empty($players)) {
            $mapped = implode(', ', array_keys($colIndex));
            throw new Exception(
                'No valid attendee records found. ' .
                'Columns detected: [' . ($mapped ?: 'none') . ']. ' .
                'Ensure the CSV contains player name columns and at least one of: ' .
                '"Name of partner(s)" and "I am registered to play in a tournament at this level."'
            );
        }

        return $this->resolvePartners($players);
    }

    // -------------------------------------------------------------------------
    // Parsing helpers
    // -------------------------------------------------------------------------

    private function normalizeLineEndings(string $data): string
    {
        return str_replace(["\r\n", "\r"], "\n", $data);
    }

    private function detectDelimiter(string $data, string $hint): string
    {
        if ($hint !== 'auto' && $hint !== '') {
            return $hint === '\t' ? "\t" : $hint;
        }
        $sample = implode("\n", array_slice(explode("\n", $data), 0, 10));
        $counts = [
            ','  => substr_count($sample, ','),
            ';'  => substr_count($sample, ';'),
            "\t" => substr_count($sample, "\t"),
            '|'  => substr_count($sample, '|'),
        ];
        arsort($counts);
        $best = array_key_first($counts);
        return ($counts[$best] > 0) ? $best : ',';
    }

    private function parseRows(array $lines, string $delimiter): array
    {
        $rows = [];
        foreach ($lines as $line) {
            $cells = ($delimiter === ',')
                ? str_getcsv($line, ',', '"', '\\')
                : explode($delimiter, $line);
            $rows[] = array_map(fn($c) => $this->sanitiseCell($c), $cells);
        }
        return $rows;
    }

    /**
     * Sanitise a single cell value: remove BOM/nbsp/zero-width chars,
     * collapse whitespace, strip residual surrounding quotes.
     */
    private function sanitiseCell(string $cell): string
    {
        // Strip BOM and various invisible spaces
        $cell = preg_replace('/[\x{00A0}\x{200B}\x{FEFF}]/u', ' ', $cell);
        $cell = preg_replace('/\s+/', ' ', $cell);
        $cell = trim($cell);
        // Strip surrounding double-quotes not handled by str_getcsv
        if (strlen($cell) >= 2 && $cell[0] === '"' && $cell[-1] === '"') {
            $cell = substr($cell, 1, -1);
        }
        return $cell;
    }

    /**
     * Build internal_key → column_index map from the header row.
     *
     * Three passes:
     *  1. Exact match on sanitised lowercase
     *  2. Exact match after stripping trailing punctuation  . : ! ?
     *  3. Substring keyword match (most-specific patterns first)
     *
     * First match wins per internal key; later columns never overwrite.
     * Exception: 'dupr' can be detected alongside 'skill_level' independently.
     */
    private function buildColumnIndex(array $headerRow): array
    {
        $index = [];

        // Pass 1 + 2: exact matches
        foreach ($headerRow as $i => $col) {
            $lower    = strtolower($this->sanitiseCell($col));
            $stripped = rtrim($lower, '.:!? ');

            foreach ([$lower, $stripped] as $candidate) {
                if (isset($this->exactMap[$candidate])) {
                    $key = $this->exactMap[$candidate];
                    if (!isset($index[$key])) {
                        $index[$key] = $i;
                    }
                }
            }
        }

        // Pass 3: substring keyword match for anything still unmapped
        foreach ($headerRow as $i => $col) {
            $lower = strtolower($this->sanitiseCell($col));

            foreach ($this->substringMap as $needle => $key) {
                if (isset($index[$key])) continue; // already resolved
                if (str_contains($lower, $needle)) {
                    $index[$key] = $i;
                    break; // stop checking needles for this column
                }
            }
        }

        return $index;
    }

    // -------------------------------------------------------------------------
    // Player extraction
    // -------------------------------------------------------------------------

    private function extractPlayer(array $row, array $idx, int $lineNum): ?array
    {
        $get = function (string $key) use ($row, $idx): string {
            if (!isset($idx[$key])) return '';
            $ci = $idx[$key];
            if ($ci < 0 || $ci >= count($row)) return '';
            return $this->sanitiseCell($row[$ci]);
        };

        // Support both split first/last OR a combined full-name column
        $firstName = $get('first_name');
        $lastName  = $get('last_name');

        if ($firstName === '' && $lastName === '') {
            $full = $get('full_name');
            if ($full !== '') {
                // Split on first space
                $parts     = explode(' ', $full, 2);
                $firstName = $parts[0];
                $lastName  = $parts[1] ?? '';
            }
        }

        $fullName = trim("$firstName $lastName");
        if ($fullName === '') return null;

        // Skip cancelled / refunded rows
        $status = strtolower($get('status'));
        if (in_array($status, ['not attending', 'cancelled', 'refunded', 'deleted', 'void'])) {
            return null;
        }

        $skillRaw    = $get('skill_level');
        $skillFloat  = $this->parseSkillLevel($skillRaw);

        // DUPR: prefer a dedicated DUPR column; fall back to blank
        $duprRaw  = $get('dupr');
        $duprVal  = ($duprRaw !== '' && is_numeric($duprRaw)) ? (float)$duprRaw : null;

        // Partner: read the raw cell — may contain 1 or 2 names (comma/semicolon separated)
        $partnerRaw = $get('partner');

        // Split into up to 2 partner names
        [$partner1, $partner2] = $this->parsePartnerNames($partnerRaw);

        return [
            'name'              => $fullName,
            'first_name'        => $firstName,
            'last_name'         => $lastName,
            'skill'             => $skillFloat,
            'skill_raw'         => $skillRaw !== '' ? $skillRaw : 'Not specified',
            'skill_band'        => $this->skillBandLabel($skillFloat),
            // Primary partner
            'partner'           => $partner1,
            'partner_matched'   => false,
            'partner_resolved'  => null,
            'manual_partner'    => null,
            // Secondary partner (optional)
            'partner2'          => $partner2,
            'partner2_matched'  => false,
            'partner2_resolved' => null,
            'manual_partner2'   => null,
            // Other fields
            'division_manual'   => false,
            'dupr'              => $duprVal,
            'team_id'           => null,
            'attendee_id'       => $get('attendee_id'),
            'order_id'          => $get('order_id'),
            'ticket_class'      => $get('ticket_class'),
            'checked_in'        => in_array(strtolower($get('checked_in')), ['yes', 'true', '1']),
            'status'            => $get('status'),
        ];
    }

    /**
     * Split a raw partner field into up to two trimmed names.
     * Returns [partner1|null, partner2|null].
     */
    private function parsePartnerNames(string $raw): array
    {
        if ($raw === '') return [null, null];

        // Split on comma, semicolon, " and ", " & "
        $parts = preg_split('/[,;]|\s+(?:and|&)\s+/i', $raw);
        $parts = array_values(array_filter(array_map('trim', $parts), fn($s) => $s !== ''));

        $p1 = $parts[0] ?? null;
        $p2 = $parts[1] ?? null;

        return [$p1, $p2];
    }

    /**
     * Convert a skill level / division string to a pickleball float rating.
     *
     * Handles:
     *   Bare numbers:          "3.5", "4.0"
     *   Number + text:         "3.5 - Intermediate", "4.0 Open"
     *   Range strings:         "3.5-3.99", "3.5 to 3.99"
     *   "Under X" / "< X":    "Under 2.5", "below 3.0"
     *   Text-only labels:      "Beginner", "Intermediate", "Advanced"
     *   Empty:                 defaults to 3.0
     */
    private function parseSkillLevel(string $raw): float
    {
        if ($raw === '' || strtolower(trim($raw)) === 'not specified') return 3.0;

        // "Under X" / "Below X" / "< X"
        if (preg_match('/(?:under|below|<)\s*(\d+(?:\.\d+)?)/i', $raw, $m)) {
            return max(0.0, (float)$m[1] - 0.01);
        }

        // Extract leading number
        if (preg_match('/(\d+(?:\.\d+)?)/', $raw, $m)) {
            return (float)$m[1];
        }

        // Text-only fallback
        $lower = strtolower($raw);
        return match (true) {
            str_contains($lower, 'open')         => 4.5,
            str_contains($lower, 'advanced')     => 4.0,
            str_contains($lower, 'intermediate') => 3.5,
            str_contains($lower, 'beginner')     => 2.5,
            str_contains($lower, 'novice')       => 2.0,
            default                               => 3.0,
        };
    }

    private function skillBandLabel(float $skill): string
    {
        if ($skill >= 4.0) return 'band-4p';
        if ($skill >= 3.5) return 'band-35';
        if ($skill >= 3.0) return 'band-30';
        if ($skill >= 2.5) return 'band-25';
        return 'band-u25';
    }

    // -------------------------------------------------------------------------
    // Partner matching — supports two partners per player
    // -------------------------------------------------------------------------

    /**
     * Resolve partner1 and partner2 for every player.
     *
     * Rules:
     * - One-way declaration is sufficient: if A names B, both are linked.
     * - Each player's partner1 and partner2 nominations are processed
     *   independently — being already matched on partner1 does NOT prevent
     *   partner2 from being resolved, and does NOT prevent other players from
     *   being resolved against this player.
     * - Three-player groups (A names B, B names A+C, C names B):
     *   A↔B as primary, B↔C as secondary, C↔B as primary — all three resolved.
     * - A player can appear in both a slot-1 and slot-2 team (DrawEngine handles this).
     */
    public function resolvePartners(array $players): array
    {
        $lookup = $this->buildNameLookup($players);

        // First pass: resolve all primary partner1 nominations
        foreach ($players as $i => $p) {
            if (!$p['partner']) continue;

            $j = $this->findMatch($p['partner'], $i, $lookup, $players);
            if ($j === null) continue;

            // Always record our own resolution
            if (!$players[$i]['partner_matched']) {
                $players[$i]['partner_matched']  = true;
                $players[$i]['partner_resolved'] = $players[$j]['name'];
            }

            // Mirror onto the other player into their best available slot
            if (!$players[$j]['partner_matched']) {
                // Their primary slot is free — fill it
                $players[$j]['partner_matched']  = true;
                $players[$j]['partner_resolved'] = $players[$i]['name'];
            } elseif (!$players[$j]['partner2_matched']
                      && $players[$j]['partner_resolved'] !== $players[$i]['name']) {
                // Their primary is taken by someone else — use their secondary slot
                $players[$j]['partner2_matched']  = true;
                $players[$j]['partner2_resolved'] = $players[$i]['name'];
            }
            // else: already linked to $i — nothing to do
        }

        // Second pass: resolve all secondary partner2 nominations
        foreach ($players as $i => $p) {
            if (!($p['partner2'] ?? null)) continue;

            $j = $this->findMatch($p['partner2'], $i, $lookup, $players);
            if ($j === null) continue;

            // Record our own partner2 resolution if not yet set
            if (!$players[$i]['partner2_matched']) {
                $players[$i]['partner2_matched']  = true;
                $players[$i]['partner2_resolved'] = $players[$j]['name'];
            }

            // Mirror onto the other player into their best available slot
            if (!$players[$j]['partner_matched']) {
                $players[$j]['partner_matched']  = true;
                $players[$j]['partner_resolved'] = $players[$i]['name'];
            } elseif (!$players[$j]['partner2_matched']
                      && $players[$j]['partner_resolved'] !== $players[$i]['name']
                      && $players[$j]['partner2_resolved'] !== $players[$i]['name']) {
                $players[$j]['partner2_matched']  = true;
                $players[$j]['partner2_resolved'] = $players[$i]['name'];
            }
        }

        return $players;
    }

    private function buildNameLookup(array $players): array
    {
        $lookup = [];
        foreach ($players as $i => $p) {
            $lookup[$this->normName($p['name'])] = $i;
            if ($p['first_name'] && $p['last_name']) {
                $rev = $this->normName($p['last_name'] . ' ' . $p['first_name']);
                $lookup[$rev] ??= $i;
            }
        }
        return $lookup;
    }

    private function findMatch(string $raw, int $selfIdx, array $lookup, array $players): ?int
    {
        $norm = $this->normName($raw);
        if ($norm === '') return null;

        // Exact
        if (isset($lookup[$norm]) && $lookup[$norm] !== $selfIdx) {
            return $lookup[$norm];
        }

        // Reverse first/last
        $parts = explode(' ', $norm, 2);
        if (count($parts) === 2) {
            $rev = $parts[1] . ' ' . $parts[0];
            if (isset($lookup[$rev]) && $lookup[$rev] !== $selfIdx) {
                return $lookup[$rev];
            }
        }

        // Substring (min 4 chars to avoid false positives)
        if (strlen($norm) >= 4) {
            foreach ($lookup as $key => $j) {
                if ($j === $selfIdx) continue;
                if (str_contains($key, $norm) || str_contains($norm, $key)) {
                    return $j;
                }
            }
        }

        return null;
    }

    private function normName(string $name): string
    {
        $n = strtolower(trim($name));
        $n = preg_replace('/[^a-z0-9 ]/', '', $n); // strip apostrophes, hyphens etc.
        return preg_replace('/\s+/', ' ', $n);
    }

    private function splitPartnerField(string $field): array
    {
        $parts = preg_split('/[,;]|\s+(?:and|&)\s+/i', $field);
        return array_values(array_filter(array_map('trim', $parts), fn($s) => $s !== ''));
    }
}

<?php

/**
 * CSVParser - Parses Pickledraw registration CSV exports
 *
 * Expected columns (Eventbrite-style):
 *   Date Created, Order ID, Purchaser User ID, Attendee ID,
 *   Attendee First Name, Attendee Last Name, Status, Ticket Class,
 *   Ticket Class Price, Is Guest, Checked in, Checked in Date,
 *   Name of partner(s), I am registered to play in a tournament at this level.
 */
class CSVParser
{
    /**
     * Exact-match header map: sanitised lowercase header → internal key.
     * We also do substring/partial matching as a fallback (see buildColumnIndex).
     */
    private array $exactMap = [
        'date created'                                              => 'date_created',
        'order id'                                                  => 'order_id',
        'order #'                                                   => 'order_id',
        'purchaser user id'                                         => 'purchaser_id',
        'attendee id'                                               => 'attendee_id',
        'attendee #'                                                => 'attendee_id',
        'attendee first name'                                       => 'first_name',
        'first name'                                                => 'first_name',
        'attendee last name'                                        => 'last_name',
        'last name'                                                 => 'last_name',
        'status'                                                    => 'status',
        'ticket class'                                              => 'ticket_class',
        'ticket type'                                               => 'ticket_class',
        'ticket class price'                                        => 'ticket_price',
        'is guest'                                                  => 'is_guest',
        'checked in'                                                => 'checked_in',
        'checked in date'                                           => 'checked_in_date',
        // Partner field — various phrasings
        'name of partner(s)'                                        => 'partner',
        'name of partners'                                          => 'partner',
        "name of partner(s)"                                        => 'partner',
        'partner'                                                   => 'partner',
        'partner name'                                              => 'partner',
        'doubles partner'                                           => 'partner',
        // Skill/division field — exact and common variants
        'i am registered to play in a tournament at this level.'   => 'skill_level',
        'i am registered to play in a tournament at this level'    => 'skill_level',
        'tournament level'                                          => 'skill_level',
        'tournament division'                                       => 'skill_level',
        'skill level'                                               => 'skill_level',
        'division'                                                  => 'skill_level',
        'level'                                                     => 'skill_level',
        'rating'                                                    => 'skill_level',
        'dupr'                                                      => 'skill_level',
    ];

    /**
     * Substring patterns for fuzzy header matching (checked when exact fails).
     * Order matters — more specific patterns first.
     */
    private array $substringMap = [
        'registered to play'     => 'skill_level',
        'tournament at this level' => 'skill_level',
        'at this level'          => 'skill_level',
        'name of partner'        => 'partner',
        'partner(s)'             => 'partner',
        'attendee first'         => 'first_name',
        'first name'             => 'first_name',
        'attendee last'          => 'last_name',
        'last name'              => 'last_name',
        'attendee id'            => 'attendee_id',
        'order id'               => 'order_id',
        'ticket class'           => 'ticket_class',
        'checked in'             => 'checked_in',
    ];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    public function parse(string $rawData, string $delimiter = 'auto'): array
    {
        $rawData = $this->normalizeLineEndings($rawData);
        // Strip UTF-8 BOM if present
        if (str_starts_with($rawData, "\xEF\xBB\xBF")) {
            $rawData = substr($rawData, 3);
        }

        $lines = array_values(array_filter(
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

        $colIndex = $this->buildColumnIndex($rows[0]);
        $dataRows = array_slice($rows, 1);

        // Debug: store which headers were mapped (available via getLastColumnMap())
        $this->lastColumnMap = $colIndex;

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
                'Ensure the CSV contains "Attendee First Name", "Attendee Last Name", ' .
                '"Name of partner(s)", and "I am registered to play in a tournament at this level."'
            );
        }

        return $this->resolvePartners($players);
    }

    private array $lastColumnMap = [];

    public function getLastColumnMap(): array
    {
        return $this->lastColumnMap;
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

        // Sample the first 10 lines
        $sample = implode("\n", array_slice(explode("\n", $data), 0, 10));

        $counts = [
            ','  => substr_count($sample, ','),
            ';'  => substr_count($sample, ';'),
            "\t" => substr_count($sample, "\t"),
            '|'  => substr_count($sample, '|'),
        ];

        arsort($counts);
        $best = array_key_first($counts);

        // Sanity check: comma count must be > 0 to be valid
        return ($counts[$best] > 0) ? $best : ',';
    }

    private function parseRows(array $lines, string $delimiter): array
    {
        $rows = [];
        foreach ($lines as $line) {
            // Always use str_getcsv for commas — handles quoted fields with commas inside
            if ($delimiter === ',') {
                $cells = str_getcsv($line, ',', '"', '\\');
            } else {
                $cells = explode($delimiter, $line);
            }
            $rows[] = array_map(fn($c) => $this->sanitiseCell($c), $cells);
        }
        return $rows;
    }

    /**
     * Sanitise a single cell: trim whitespace, strip surrounding quotes left by
     * some exporters, remove zero-width and non-breaking spaces.
     */
    private function sanitiseCell(string $cell): string
    {
        // Remove zero-width space, non-breaking space, BOM remnants
        $cell = preg_replace('/[\x{00A0}\x{200B}\x{FEFF}]/u', ' ', $cell);
        // Collapse multiple spaces
        $cell = preg_replace('/\s+/', ' ', $cell);
        // Strip surrounding whitespace
        $cell = trim($cell);
        // Strip surrounding quotes that weren't handled by str_getcsv
        if (strlen($cell) >= 2 && $cell[0] === '"' && $cell[-1] === '"') {
            $cell = substr($cell, 1, -1);
        }
        return $cell;
    }

    /**
     * Build internal_key => column_index map from the header row.
     *
     * Three-pass approach:
     *   1. Exact match against $exactMap
     *   2. Exact match after stripping trailing punctuation (. ! ?)
     *   3. Substring match against $substringMap
     */
    private function buildColumnIndex(array $headerRow): array
    {
        $index = [];

        foreach ($headerRow as $i => $col) {
            $raw     = $this->sanitiseCell($col);
            $lower   = strtolower($raw);
            $stripped = rtrim($lower, '.!? ');   // version without trailing punctuation

            // Pass 1: exact match
            if (isset($this->exactMap[$lower]) && !isset($index[$this->exactMap[$lower]])) {
                $index[$this->exactMap[$lower]] = $i;
                continue;
            }

            // Pass 2: exact match on stripped version
            if (isset($this->exactMap[$stripped]) && !isset($index[$this->exactMap[$stripped]])) {
                $index[$this->exactMap[$stripped]] = $i;
                continue;
            }
        }

        // Pass 3: substring match for anything not yet resolved
        $needed = ['first_name', 'last_name', 'partner', 'skill_level',
                   'attendee_id', 'order_id', 'ticket_class', 'checked_in'];

        foreach ($headerRow as $i => $col) {
            $lower = strtolower($this->sanitiseCell($col));

            foreach ($this->substringMap as $needle => $internalKey) {
                if (isset($index[$internalKey])) continue; // already mapped
                if (!in_array($internalKey, $needed)) continue;

                if (str_contains($lower, $needle)) {
                    $index[$internalKey] = $i;
                    break;
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
        // Safe getter — returns '' if column not mapped or row too short
        $get = function (string $key) use ($row, $idx): string {
            if (!isset($idx[$key])) return '';
            $colIdx = $idx[$key];
            if ($colIdx < 0 || $colIdx >= count($row)) return '';
            return $this->sanitiseCell($row[$colIdx]);
        };

        $firstName = $get('first_name');
        $lastName  = $get('last_name');
        $fullName  = trim("$firstName $lastName");

        if ($fullName === '') return null;

        // Skip explicit cancellations/refunds
        $status = strtolower($get('status'));
        if (in_array($status, ['not attending', 'cancelled', 'refunded', 'deleted', 'void'])) {
            return null;
        }

        $skillRaw   = $get('skill_level');
        $skillFloat = $this->parseSkillLevel($skillRaw);
        $partner    = $get('partner');

        return [
            'name'             => $fullName,
            'first_name'       => $firstName,
            'last_name'        => $lastName,
            'skill'            => $skillFloat,
            'skill_raw'        => $skillRaw !== '' ? $skillRaw : 'Not specified',
            'skill_band'       => $this->skillBandLabel($skillFloat),
            'partner'          => $partner !== '' ? $partner : null,
            'partner_matched'  => false,
            'partner_resolved' => null,
            'manual_partner'   => null,
            'division_manual'  => false,
            'dupr'             => null,
            'team_id'          => null,
            'attendee_id'      => $get('attendee_id'),
            'order_id'         => $get('order_id'),
            'ticket_class'     => $get('ticket_class'),
            'checked_in'       => in_array(strtolower($get('checked_in')), ['yes', 'true', '1']),
            'status'           => $get('status'),
        ];
    }

    /**
     * Convert a skill level value from the CSV to a pickleball rating float.
     *
     * Handles:
     *  - Bare numbers:             "3.5", "4.0", "2.5"
     *  - Number + text:            "3.5 - Intermediate", "4.0 Open"
     *  - Text-only labels:         "Beginner", "Intermediate", "Advanced"
     *  - Band range strings:       "3.5-3.99", "3.5 to 3.99"
     *  - "Under X" phrasing:       "Under 2.5", "Below 2.5"
     *  - Empty/missing:            defaults to 3.0
     */
    private function parseSkillLevel(string $raw): float
    {
        if ($raw === '' || strtolower($raw) === 'not specified') return 3.0;

        $lower = strtolower(trim($raw));

        // "Under X" / "Below X" / "< X"
        if (preg_match('/(?:under|below|<)\s*(\d+(?:\.\d+)?)/i', $raw, $m)) {
            return (float)$m[1] - 0.1; // just below the threshold
        }

        // Extract the FIRST numeric value (handles "3.5 - Intermediate", "Level 3.0" etc.)
        if (preg_match('/(\d+(?:\.\d+)?)/', $raw, $m)) {
            return (float)$m[1];
        }

        // Pure text labels
        return match (true) {
            str_contains($lower, '4.0')          => 4.0,
            str_contains($lower, 'open')         => 4.5,
            str_contains($lower, 'advanced')     => 4.0,
            str_contains($lower, 'intermediate') => 3.5,
            str_contains($lower, 'beginner')     => 2.5,
            str_contains($lower, 'novice')       => 2.0,
            default                               => 3.0,
        };
    }

    /**
     * Map a pickleball rating to one of the 5 fixed display band keys.
     */
    private function skillBandLabel(float $skill): string
    {
        if ($skill >= 4.0) return 'band-4p';
        if ($skill >= 3.5) return 'band-35';
        if ($skill >= 3.0) return 'band-30';
        if ($skill >= 2.5) return 'band-25';
        return 'band-u25';
    }

    // -------------------------------------------------------------------------
    // Partner matching
    // -------------------------------------------------------------------------

    /**
     * Match players to their nominated partners.
     *
     * Rules:
     *  - One-way declaration is SUFFICIENT: if A names B, both are marked matched.
     *  - Two-way declarations are handled correctly (no double-marking).
     *  - Fuzzy matching: tries "Last First" reversal and substring containment.
     *  - Partner field may contain comma/semicolon-separated names (takes first).
     */
    private function resolvePartners(array $players): array
    {
        // Build lookup table: normalised_name → player index
        // Include both "First Last" and "Last First" variants
        $lookup = [];
        foreach ($players as $i => $p) {
            $lookup[$this->normaliseName($p['name'])] = $i;
            if ($p['first_name'] && $p['last_name']) {
                $reversed = $this->normaliseName($p['last_name'] . ' ' . $p['first_name']);
                if (!isset($lookup[$reversed])) {
                    $lookup[$reversed] = $i;
                }
            }
        }

        foreach ($players as $i => $p) {
            // Skip if already matched (could have been matched as the partner of someone earlier)
            if ($players[$i]['partner_matched']) continue;
            if (!$p['partner'])                  continue;

            // Partner field can have multiple names; try each
            $candidates = $this->splitPartnerField($p['partner']);

            foreach ($candidates as $partnerRaw) {
                $partnerNorm = $this->normaliseName($partnerRaw);
                if ($partnerNorm === '') continue;

                // --- Exact lookup ---
                $j = $lookup[$partnerNorm] ?? null;

                // --- Fuzzy fallback: try reversing first/last in the candidate ---
                if ($j === null) {
                    $parts = explode(' ', $partnerNorm, 2);
                    if (count($parts) === 2) {
                        $reversed = $parts[1] . ' ' . $parts[0];
                        $j = $lookup[$reversed] ?? null;
                    }
                }

                // --- Fuzzy fallback: substring containment (min 4 chars) ---
                if ($j === null && strlen($partnerNorm) >= 4) {
                    foreach ($lookup as $norm => $k) {
                        if ($k === $i) continue;
                        if (str_contains($norm, $partnerNorm) || str_contains($partnerNorm, $norm)) {
                            $j = $k;
                            break; // stop after first match to avoid wrong assignment
                        }
                    }
                }

                // Found a match
                if ($j !== null && $j !== $i) {
                    // Mark both players as matched (one-way declaration is sufficient)
                    $players[$i]['partner_matched']  = true;
                    $players[$i]['partner_resolved'] = $players[$j]['name'];

                    // Only overwrite partner B's resolved name if they haven't already been matched
                    if (!$players[$j]['partner_matched']) {
                        $players[$j]['partner_matched']  = true;
                        $players[$j]['partner_resolved'] = $players[$i]['name'];
                    }

                    break; // stop trying other candidates for player $i
                }
            }
        }

        return $players;
    }

    private function normaliseName(string $name): string
    {
        // Lowercase, collapse whitespace, strip punctuation that might differ between entries
        $name = strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9 ]/', '', $name); // strip apostrophes, hyphens etc.
        return preg_replace('/\s+/', ' ', $name);
    }

    private function splitPartnerField(string $field): array
    {
        // Split on comma, semicolon, or " and " — take all non-empty parts
        $parts = preg_split('/[,;]|\band\b/i', $field);
        return array_values(array_filter(
            array_map('trim', $parts),
            fn($s) => $s !== ''
        ));
    }
}

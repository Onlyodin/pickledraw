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
    // Canonical lowercase header names mapped to our internal keys
    private array $columnMap = [
        'date created'                                                   => 'date_created',
        'order id'                                                       => 'order_id',
        'purchaser user id'                                              => 'purchaser_id',
        'attendee id'                                                    => 'attendee_id',
        'attendee first name'                                            => 'first_name',
        'first name'                                                     => 'first_name',
        'attendee last name'                                             => 'last_name',
        'last name'                                                      => 'last_name',
        'status'                                                         => 'status',
        'ticket class'                                                   => 'ticket_class',
        'ticket class price'                                             => 'ticket_price',
        'is guest'                                                       => 'is_guest',
        'checked in'                                                     => 'checked_in',
        'checked in date'                                                => 'checked_in_date',
        'name of partner(s)'                                             => 'partner',
        'name of partners'                                               => 'partner',
        'partner'                                                        => 'partner',
        'partner name'                                                   => 'partner',
        'i am registered to play in a tournament at this level.'        => 'skill_level',
        'i am registered to play in a tournament at this level'         => 'skill_level',
        'tournament level'                                               => 'skill_level',
        'skill level'                                                    => 'skill_level',
        'level'                                                          => 'skill_level',
    ];

    /**
     * Parse raw CSV text into player records.
     * Returns array of player arrays ready for DrawEngine.
     */
    public function parse(string $rawData, string $delimiter = 'auto'): array
    {
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

        $colIndex = $this->buildColumnIndex($rows[0]);
        $dataRows = array_slice($rows, 1);

        $players = [];
        foreach ($dataRows as $lineNum => $row) {
            if (count(array_filter($row, fn($c) => trim($c) !== '')) === 0) continue;
            $player = $this->extractPlayer($row, $colIndex, $lineNum + 2);
            if ($player !== null) {
                $players[] = $player;
            }
        }

        if (empty($players)) {
            throw new Exception(
                'No valid attendee records found. Ensure the CSV contains: ' .
                '"Attendee First Name", "Attendee Last Name", "Name of partner(s)", and ' .
                '"I am registered to play in a tournament at this level."'
            );
        }

        return $this->resolvePartners($players);
    }

    // -----------------------------------------------------------------------
    // Parsing helpers
    // -----------------------------------------------------------------------

    private function normalizeLineEndings(string $data): string
    {
        return str_replace(["\r\n", "\r"], "\n", $data);
    }

    private function detectDelimiter(string $data, string $hint): string
    {
        if ($hint !== 'auto' && $hint !== '') {
            return $hint === '\t' ? "\t" : $hint;
        }
        $sample = implode("\n", array_slice(explode("\n", $data), 0, 5));
        $counts = [
            ','  => substr_count($sample, ','),
            ';'  => substr_count($sample, ';'),
            "\t" => substr_count($sample, "\t"),
            '|'  => substr_count($sample, '|'),
        ];
        arsort($counts);
        return array_key_first($counts);
    }

    private function parseRows(array $lines, string $delimiter): array
    {
        $rows = [];
        foreach ($lines as $line) {
            if ($delimiter === ',') {
                $rows[] = array_map('trim', str_getcsv($line, ','));
            } else {
                $rows[] = array_map('trim', explode($delimiter, $line));
            }
        }
        return $rows;
    }

    private function buildColumnIndex(array $headerRow): array
    {
        $index = [];
        foreach ($headerRow as $i => $col) {
            $key = strtolower(trim($col));
            if (isset($this->columnMap[$key]) && !isset($index[$this->columnMap[$key]])) {
                $index[$this->columnMap[$key]] = $i;
            }
        }
        return $index;
    }

    // -----------------------------------------------------------------------
    // Player extraction
    // -----------------------------------------------------------------------

    private function extractPlayer(array $row, array $idx, int $lineNum): ?array
    {
        $get = fn(string $key) => trim($row[$idx[$key] ?? -1] ?? '');

        $firstName = $get('first_name');
        $lastName  = $get('last_name');
        $fullName  = trim("$firstName $lastName");

        if (empty($fullName)) return null;

        // Skip explicit cancellations/refunds
        $status = strtolower($get('status'));
        if (in_array($status, ['not attending', 'cancelled', 'refunded', 'deleted'])) {
            return null;
        }

        $skillRaw   = $get('skill_level');
        $skillFloat = $this->parseSkillLevel($skillRaw);
        $partner    = $get('partner');

        return [
            'name'            => $fullName,
            'first_name'      => $firstName,
            'last_name'       => $lastName,
            'skill'           => $skillFloat,
            'skill_raw'       => $skillRaw ?: 'Not specified',
            'skill_band'      => $this->skillBandLabel($skillFloat),
            'partner'         => $partner ?: null,
            'partner_matched' => false,
            'partner_resolved'=> null,
            'team_id'         => null,
            'attendee_id'     => $get('attendee_id'),
            'order_id'        => $get('order_id'),
            'ticket_class'    => $get('ticket_class'),
            'checked_in'      => in_array(strtolower($get('checked_in')), ['yes', 'true', '1']),
            'status'          => $get('status'),
        ];
    }

    /**
     * Convert a skill level label to a pickleball rating float.
     *
     * Skill bands used throughout:
     *   under-2.5  : < 2.5
     *   2.5        : 2.5 – 2.99
     *   3.0        : 3.0 – 3.49
     *   3.5        : 3.5 – 3.99
     *   4.0+       : 4.0 and above
     */
    private function parseSkillLevel(string $raw): float
    {
        if ($raw === '') return 3.0;

        // Extract the first number found (handles "3.5", "3.5 - Intermediate", "Level 4.0" etc.)
        if (preg_match('/(\d+(?:\.\d+)?)/', $raw, $m)) {
            return (float)$m[1];
        }

        // Pure text labels — map to representative pickleball rating
        $label = strtolower($raw);
        return match (true) {
            str_contains($label, 'open')         => 5.0,
            str_contains($label, 'advanced')     => 4.5,
            str_contains($label, 'intermediate') => 3.5,
            str_contains($label, 'beginner')     => 2.5,
            str_contains($label, 'novice')       => 2.0,
            default                               => 3.0,
        };
    }

    /**
     * Map a pickleball skill rating to a display band label.
     * Bands: under-2.5 | 2.5 | 3.0 | 3.5 | 4.0+
     */
    private function skillBandLabel(float $skill): string
    {
        if ($skill >= 4.0) return 'band-4p';
        if ($skill >= 3.5) return 'band-35';
        if ($skill >= 3.0) return 'band-30';
        if ($skill >= 2.5) return 'band-25';
        return 'band-u25';
    }

    // -----------------------------------------------------------------------
    // Partner matching
    // -----------------------------------------------------------------------

    private function resolvePartners(array $players): array
    {
        $lookup = [];
        foreach ($players as $i => $p) {
            $lookup[$this->normaliseName($p['name'])] = $i;
            if ($p['first_name'] && $p['last_name']) {
                $lookup[$this->normaliseName($p['last_name'] . ' ' . $p['first_name'])] = $i;
            }
        }

        foreach ($players as $i => $p) {
            if (!$p['partner'] || $players[$i]['partner_matched']) continue;

            $candidates = $this->splitPartnerField($p['partner']);

            foreach ($candidates as $partnerRaw) {
                $partnerNorm = $this->normaliseName($partnerRaw);

                if (!isset($lookup[$partnerNorm])) {
                    foreach ($lookup as $norm => $j) {
                        if ($i === $j) continue;
                        if (strlen($partnerNorm) >= 3 &&
                            (str_contains($norm, $partnerNorm) || str_contains($partnerNorm, $norm))) {
                            $lookup[$partnerNorm] = $j;
                            break;
                        }
                    }
                }

                if (isset($lookup[$partnerNorm]) && $lookup[$partnerNorm] !== $i) {
                    $j = $lookup[$partnerNorm];
                    $players[$i]['partner_matched']  = true;
                    $players[$i]['partner_resolved'] = $players[$j]['name'];
                    $players[$j]['partner_matched']  = true;
                    $players[$j]['partner_resolved'] = $players[$i]['name'];
                    break;
                }
            }
        }

        return $players;
    }

    private function normaliseName(string $name): string
    {
        return strtolower(preg_replace('/\s+/', ' ', trim($name)));
    }

    private function splitPartnerField(string $field): array
    {
        return array_filter(
            array_map('trim', preg_split('/[,;]+/', $field)),
            fn($s) => $s !== ''
        );
    }
}

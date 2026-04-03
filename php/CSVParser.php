<?php

/**
 * CSVParser - Parses delimited player data
 * Expected format: PlayerName, SkillLevel, PartnerName
 */
class CSVParser
{
    private array $knownHeaders = ['name', 'player', 'player name', 'playername'];
    private array $skillHeaders = ['skill', 'level', 'skill level', 'rating', 'grade'];
    private array $partnerHeaders = ['partner', 'partner name', 'partnername', 'doubles partner', 'pair'];

    /**
     * Parse raw text data into player records.
     */
    public function parse(string $rawData, string $delimiter = 'auto'): array
    {
        $rawData = $this->normalizeLineEndings($rawData);
        $lines   = array_filter(explode("\n", $rawData), fn($l) => trim($l) !== '');

        if (empty($lines)) {
            throw new Exception('No data found in input.');
        }

        $delimiter  = $this->detectDelimiter($rawData, $delimiter);
        $rows       = $this->parseRows(array_values($lines), $delimiter);
        $hasHeader  = $this->detectHeader($rows[0]);
        $colMap     = $this->mapColumns($rows[0], $hasHeader);
        $dataRows   = $hasHeader ? array_slice($rows, 1) : $rows;

        if (empty($dataRows)) {
            throw new Exception('No data rows found after header detection.');
        }

        $players = [];
        foreach ($dataRows as $row) {
            if (count(array_filter($row)) === 0) continue; // skip blank rows

            $player = $this->extractPlayer($row, $colMap);
            if ($player) {
                $players[] = $player;
            }
        }

        if (empty($players)) {
            throw new Exception('Could not extract any valid player records. Check your format: Name, Skill(1-10), Partner');
        }

        return $this->matchPartners($players);
    }

    private function normalizeLineEndings(string $data): string
    {
        return str_replace(["\r\n", "\r"], "\n", $data);
    }

    private function detectDelimiter(string $data, string $hint): string
    {
        if ($hint !== 'auto' && $hint !== '') {
            return $hint === '\t' ? "\t" : $hint;
        }

        // Sample first few lines
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
                $rows[] = str_getcsv($line, ',');
            } else {
                $rows[] = array_map('trim', explode($delimiter, $line));
            }
        }
        return $rows;
    }

    private function detectHeader(array $firstRow): bool
    {
        foreach ($firstRow as $cell) {
            $cell = strtolower(trim($cell));
            if (in_array($cell, array_merge($this->knownHeaders, $this->skillHeaders, $this->partnerHeaders))) {
                return true;
            }
        }

        // Also detect if first row has no numeric-ish skill value
        foreach ($firstRow as $cell) {
            if (is_numeric(trim($cell)) && (float)trim($cell) >= 1 && (float)trim($cell) <= 10) {
                return false;
            }
        }

        // If no numbers found in first row, likely a header
        $hasNumbers = false;
        foreach ($firstRow as $cell) {
            if (is_numeric(trim($cell))) {
                $hasNumbers = true;
                break;
            }
        }
        return !$hasNumbers;
    }

    private function mapColumns(array $headerRow, bool $hasHeader): array
    {
        if (!$hasHeader) {
            // Default: col 0 = name, col 1 = skill, col 2 = partner
            return ['name' => 0, 'skill' => 1, 'partner' => 2];
        }

        $map = ['name' => 0, 'skill' => 1, 'partner' => 2]; // fallback

        foreach ($headerRow as $i => $col) {
            $col = strtolower(trim($col));
            if (in_array($col, $this->knownHeaders)) {
                $map['name'] = $i;
            } elseif (in_array($col, $this->skillHeaders)) {
                $map['skill'] = $i;
            } elseif (in_array($col, $this->partnerHeaders)) {
                $map['partner'] = $i;
            }
        }

        return $map;
    }

    private function extractPlayer(array $row, array $colMap): ?array
    {
        $name  = trim($row[$colMap['name']] ?? '');
        $skill = trim($row[$colMap['skill']] ?? '');
        $partner = trim($row[$colMap['partner']] ?? '');

        if (empty($name)) return null;

        // Clamp skill to 1-10
        $skill = is_numeric($skill) ? max(1, min(10, (float)$skill)) : 5;

        return [
            'name'           => $name,
            'skill'          => (float)$skill,
            'partner'        => $partner ?: null,
            'partner_matched'=> false,
            'team_id'        => null,
            'skill_band'     => $this->skillBandLabel($skill),
        ];
    }

    private function skillBandLabel(float $skill): string
    {
        if ($skill >= 8) return 'high';
        if ($skill >= 5) return 'mid';
        return 'low';
    }

    /**
     * Verify two-way partner references and flag matched pairs.
     */
    private function matchPartners(array $players): array
    {
        $nameIndex = [];
        foreach ($players as $i => $p) {
            $nameIndex[strtolower($p['name'])] = $i;
        }

        foreach ($players as $i => $p) {
            if (!$p['partner']) continue;

            $partnerKey = strtolower($p['partner']);
            if (isset($nameIndex[$partnerKey])) {
                $j = $nameIndex[$partnerKey];
                // Mutual match?
                $partnerOfJ = strtolower($players[$j]['partner'] ?? '');
                if ($partnerOfJ === strtolower($p['name'])) {
                    $players[$i]['partner_matched'] = true;
                    $players[$j]['partner_matched'] = true;
                }
            }
        }

        return $players;
    }
}

<?php

/**
 * DrawEngine - Matches partners into teams, groups by skill, creates match draws
 */
class DrawEngine
{
    private array $players;
    private array $teams = [];
    private int   $teamCounter = 1;

    public function __construct(array $players)
    {
        $this->players = $players;
    }

    /**
     * Main entry point. Returns ['teams' => [...], 'draws' => [...]]
     */
    public function generateDraw(array $options): array
    {
        $rounds      = $options['rounds']      ?? 3;
        $skillBands  = $options['skill_bands'] ?? 3;
        $format      = $options['format']      ?? 'round_robin';
        $courts      = $options['courts']      ?? 4;

        $this->teams = $this->buildTeams();

        if (empty($this->teams)) {
            throw new Exception('Not enough players to form any teams. Need at least 2 players.');
        }

        $divisions = $this->groupBySkill($skillBands);
        $draws     = $this->buildDraw($divisions, $rounds, $format, $courts);

        return [
            'teams' => $this->teams,
            'draws' => $draws,
        ];
    }

    // -----------------------------------------------------------------------

    // -----------------------------------------------------------------------
    // TEAM BUILDING
    //
    // Two-partner logic:
    //   - A player with two resolved partners gets TWO team entries:
    //       team slot 1 = player + partner1
    //       team slot 2 = player + partner2
    //   - In round-robin draw, odd rounds use slot-1 teams,
    //     even rounds use slot-2 teams (swapped for affected players).
    //   - If a slot-2 team is not available for a given round, slot-1 is used.
    //   - Players with NO resolved partners are auto-paired by skill.
    // -----------------------------------------------------------------------

    private function buildTeams(): array
    {
        $teams  = [];
        $paired = []; // player indices placed in at least one primary (slot-1) team

        // --- Pass 1: confirmed primary pairs ---
        foreach ($this->players as $i => $player) {
            if (in_array($i, $paired)) continue;
            if (!$player['partner_matched']) continue;

            $partnerIdx = $player['manual_partner']
                ?? $this->findPlayerIndex($player['partner_resolved'] ?? $player['partner'] ?? '');

            if ($partnerIdx === null || $partnerIdx === $i || in_array($partnerIdx, $paired)) continue;

            $teams[]  = $this->makeTeam($player, $this->players[$partnerIdx], true, 1);
            $paired[] = $i;
            $paired[] = $partnerIdx;
        }

        // --- Pass 2: secondary partner teams (slot 2) ---
        // A player can appear in both a slot-1 and a slot-2 team.
        $paired2 = [];
        foreach ($this->players as $i => $player) {
            if (!($player['partner2_matched'] ?? false)) continue;
            if (in_array($i, $paired2)) continue;

            $p2Idx = $player['manual_partner2']
                ?? $this->findPlayerIndex($player['partner2_resolved'] ?? $player['partner2'] ?? '');

            if ($p2Idx === null || $p2Idx === $i || in_array($p2Idx, $paired2)) continue;

            $teams[]   = $this->makeTeam($player, $this->players[$p2Idx], true, 2);
            $paired2[] = $i;
            $paired2[] = $p2Idx;
        }

        // --- Pass 3: auto-pair remaining unpaired players by closest skill ---
        $remaining = [];
        foreach ($this->players as $i => $p) {
            if (!in_array($i, $paired)) $remaining[$i] = $p;
        }
        uasort($remaining, fn($a, $b) => $b['skill'] <=> $a['skill']);
        $remaining = array_values($remaining);

        while (count($remaining) >= 2) {
            $p1 = array_shift($remaining);
            $bestIdx = 0; $bestDiff = PHP_FLOAT_MAX;
            foreach ($remaining as $j => $p) {
                $d = abs($p1['skill'] - $p['skill']);
                if ($d < $bestDiff) { $bestDiff = $d; $bestIdx = $j; }
            }
            $p2 = array_splice($remaining, $bestIdx, 1)[0];
            $teams[] = $this->makeTeam($p1, $p2, false, 1);
        }

        // Odd player out → bye
        if (!empty($remaining)) {
            $solo = $remaining[0];
            if (!empty($teams)) {
                $teams[count($teams) - 1]['note'] = 'Bye: ' . $solo['name'];
            } else {
                $teams[] = [
                    'id'             => $this->teamCounter++,
                    'player1'        => $solo['name'],
                    'player2'        => '— BYE —',
                    'skill1'         => $solo['skill'],
                    'skill2'         => 0,
                    'combined_skill' => $solo['skill'],
                    'avg_skill'      => $solo['skill'],
                    'combined_dupr'  => null,
                    'explicit_pair'  => false,
                    'partner_slot'   => 1,
                    'note'           => 'Solo player awaiting partner',
                ];
            }
        }

        return $teams;
    }

    private function makeTeam(array $p1, array $p2, bool $explicit = true, int $partnerSlot = 1): array
    {
        $avg  = round(($p1['skill'] + $p2['skill']) / 2, 2);
        $d1   = isset($p1['dupr'])  && $p1['dupr']  !== null ? (float)$p1['dupr']  : null;
        $d2   = isset($p2['dupr'])  && $p2['dupr']  !== null ? (float)$p2['dupr']  : null;
        $cDupr = ($d1 !== null && $d2 !== null) ? round($d1 + $d2, 2) : null;

        return [
            'id'             => $this->teamCounter++,
            'player1'        => $p1['name'],
            'player2'        => $p2['name'],
            'skill1'         => $p1['skill'],
            'skill2'         => $p2['skill'],
            'combined_skill' => round($p1['skill'] + $p2['skill'], 2),
            'avg_skill'      => $avg,
            'skill_raw1'     => $p1['skill_raw'] ?? (string)$p1['skill'],
            'skill_raw2'     => $p2['skill_raw'] ?? (string)$p2['skill'],
            'dupr1'          => $d1,
            'dupr2'          => $d2,
            'combined_dupr'  => $cDupr,
            'explicit_pair'  => $explicit,
            'partner_slot'   => $partnerSlot,
            'note'           => '',
        ];
    }

    private function findPlayerIndex(string $name): ?int
    {
        if ($name === '') return null;
        $nl = strtolower($name);
        foreach ($this->players as $i => $p) {
            if (strtolower($p['name']) === $nl) return $i;
        }
        return null;
    }

    // -----------------------------------------------------------------------
    // SKILL DIVISION GROUPING
    // Fixed pickleball bands: Under 2.5 | 2.5–2.99 | 3.0–3.49 | 3.5–3.99 | 4.0+
    // When fewer bands requested, bands are merged from the bottom up.
    // -----------------------------------------------------------------------

    private function groupBySkill(int $numBands): array
    {
        if (empty($this->teams)) return [];

        // The 5 fixed pickleball skill bands (highest first = Division A)
        $fixedBands = [
            'Division A (4.0+)'       => fn(float $s) => $s >= 4.0,
            'Division B (3.5–3.99)'   => fn(float $s) => $s >= 3.5 && $s < 4.0,
            'Division C (3.0–3.49)'   => fn(float $s) => $s >= 3.0 && $s < 3.5,
            'Division D (2.5–2.99)'   => fn(float $s) => $s >= 2.5 && $s < 3.0,
            'Division E (Under 2.5)'  => fn(float $s) => $s < 2.5,
        ];

        if ($numBands >= 5) {
            // Use all 5 fixed bands
            $divisions = [];
            foreach ($fixedBands as $label => $test) {
                foreach ($this->teams as $team) {
                    if ($test($team['avg_skill'])) {
                        $divisions[$label][] = $team;
                    }
                }
            }
            // Remove empty divisions
            return array_filter($divisions);
        }

        // Fewer bands requested — fall back to dynamic equal-range splitting
        $skills = array_column($this->teams, 'avg_skill');
        $min    = min($skills);
        $max    = max($skills);

        if ($max === $min) {
            return ['Division A' => $this->teams];
        }

        $bandSize   = ($max - $min) / $numBands;
        $divLetters = range('A', 'Z');
        $divisions  = [];

        foreach ($this->teams as $team) {
            $bandIdx = (int) floor(($team['avg_skill'] - $min) / $bandSize);
            $bandIdx = min($bandIdx, $numBands - 1);
            $divIdx  = ($numBands - 1) - $bandIdx; // highest skill = A
            $divName = 'Division ' . ($divLetters[$divIdx] ?? ($divIdx + 1));
            $divisions[$divName][] = $team;
        }

        ksort($divisions);
        return $divisions;
    }

    // -----------------------------------------------------------------------
    // DRAW GENERATION
    // -----------------------------------------------------------------------

    private function buildDraw(array $divisions, int $rounds, string $format, int $courts): array
    {
        $draws = [];
        $courtCounter = 1;

        foreach ($divisions as $divName => $teams) {
            // Sort teams within division: combined DUPR descending (if available),
            // then avg_skill descending as fallback. This ensures similarly-skilled
            // DUPR-rated teams are seeded close together.
            usort($teams, function (array $a, array $b) {
                $aDupr = $a['combined_dupr'] ?? null;
                $bDupr = $b['combined_dupr'] ?? null;

                if ($aDupr !== null && $bDupr !== null) {
                    return $bDupr <=> $aDupr; // both have DUPR — sort by it
                }
                if ($aDupr !== null) return -1; // a has DUPR, b doesn't — a first
                if ($bDupr !== null) return 1;  // b has DUPR, a doesn't — b first
                return $b['avg_skill'] <=> $a['avg_skill']; // neither — fall back
            });

            // Compute DUPR stats for the division
            $duprValues  = array_filter(array_column($teams, 'combined_dupr'), fn($v) => $v !== null);
            $duprAvg     = count($duprValues) > 0 ? round(array_sum($duprValues) / count($duprValues), 2) : null;

            $draws[$divName] = [
                'teams'     => $teams,
                'avg_skill' => round(array_sum(array_column($teams, 'avg_skill')) / count($teams), 2),
                'dupr_avg'  => $duprAvg,
                'rounds'    => [],
            ];

            if (count($teams) < 2) {
                $draws[$divName]['rounds'][1] = [['note' => 'Only one team in this division — awaiting more players.']];
                continue;
            }

            switch ($format) {
                case 'elimination':
                    $draws[$divName]['rounds'] = $this->buildElimination($teams, $courts, $courtCounter);
                    break;
                case 'pools':
                    $draws[$divName]['rounds'] = $this->buildPools($teams, $rounds, $courts, $courtCounter);
                    break;
                default:
                    $draws[$divName]['rounds'] = $this->buildRoundRobin($teams, $rounds, $courts, $courtCounter);
            }

            $courtCounter += $courts;
        }

        return $draws;
    }

    /**
     * Round robin using circle method algorithm.
     * Ensures every team plays every other team, minimising repeat matchups.

    /**
     * Round robin using circle method.
     *
     * Two-partner alternation:
     *   - Slot-1 teams form the base draw schedule.
     *   - On even rounds, any player who has a slot-2 team plays with partner2 instead.
     *   - The slot-2 team substitutes in; the slot-1 team sits that round.
     *   - Match display notes which rounds use alternate partners.
     */
    private function buildRoundRobin(array $teams, int $requestedRounds, int $courts, int $courtOffset): array
    {
        // Separate slot-1 (base) and slot-2 (alternate) teams
        $baseTeams = array_values(array_filter($teams, fn($t) => ($t['partner_slot'] ?? 1) === 1));
        $altTeams  = array_values(array_filter($teams, fn($t) => ($t['partner_slot'] ?? 1) === 2));

        // Build full lookup for all teams
        $lookup = [];
        foreach ($teams as $t) { $lookup[$t['id']] = $t; }

        // Build player → slot2 team id map (keyed by player1 name)
        $slot2ByPlayer = [];
        foreach ($altTeams as $t) {
            $slot2ByPlayer[$t['player1']] = $t['id'];
            $slot2ByPlayer[$t['player2']] = $t['id'];
        }

        $n = count($baseTeams);
        if ($n === 0) return [];

        // Add bye if odd
        if ($n % 2 !== 0) {
            $baseTeams[] = ['id' => 0, 'player1' => 'BYE', 'player2' => '', 'avg_skill' => 0, 'partner_slot' => 1, 'combined_dupr' => null];
            $n++;
        }

        $totalRounds = $n - 1;
        $rounds      = min($requestedRounds, $totalRounds);
        $ids         = array_column($baseTeams, 'id');
        $circle      = array_slice($ids, 1);
        $roundsOut   = [];

        for ($r = 0; $r < $rounds; $r++) {
            $roundNum  = $r + 1;
            $useAlt    = ($roundNum % 2 === 0) && !empty($altTeams);
            $matches   = [];
            $courtNum  = $courtOffset;

            $pairs = [[$ids[0], $circle[0]]];
            for ($i = 1; $i <= ($n / 2) - 1; $i++) {
                $pairs[] = [$circle[$i], $circle[$n - 1 - $i]];
            }

            foreach ($pairs as [$id1, $id2]) {
                if ($id1 === 0 || $id2 === 0) continue;

                $t1 = $lookup[$id1] ?? null;
                $t2 = $lookup[$id2] ?? null;
                if (!$t1 || !$t2) continue;

                $altLabel = '';

                // On even rounds, try to swap to slot-2 team for each team
                if ($useAlt) {
                    $alt1 = $this->swapToAlt($t1, $slot2ByPlayer, $lookup);
                    $alt2 = $this->swapToAlt($t2, $slot2ByPlayer, $lookup);
                    if ($alt1 !== null) { $t1 = $alt1; $altLabel = '2nd partner'; }
                    if ($alt2 !== null) { $t2 = $alt2; $altLabel = $altLabel ? 'Alt partners' : '2nd partner'; }
                }

                $matches[] = [
                    'team1_id'    => $t1['id'],
                    'team1'       => $t1['player1'] . ' / ' . $t1['player2'],
                    'team2_id'    => $t2['id'],
                    'team2'       => $t2['player1'] . ' / ' . $t2['player2'],
                    'court'       => (($courtNum - $courtOffset) % $courts) + $courtOffset,
                    'alt_partner' => $altLabel,
                ];
                $courtNum++;
            }

            $roundsOut[$roundNum] = $matches;
            array_unshift($circle, array_pop($circle));
        }

        return $roundsOut;
    }

    /**
     * If any player in $team has a slot-2 alternate, return the alternate team.
     */
    private function swapToAlt(array $team, array $slot2ByPlayer, array $lookup): ?array
    {
        foreach (['player1', 'player2'] as $key) {
            $name = $team[$key] ?? '';
            if (isset($slot2ByPlayer[$name])) {
                $altId = $slot2ByPlayer[$name];
                if (isset($lookup[$altId])) return $lookup[$altId];
            }
        }
        return null;
    }

    /**
     * Single elimination bracket.
     */
    private function buildElimination(array $teams, int $courts, int $courtOffset): array
    {
        // Seed teams by skill descending
        usort($teams, fn($a, $b) => $b['avg_skill'] <=> $a['avg_skill']);

        $rounds  = [];
        $bracket = $teams;
        $round   = 1;

        while (count($bracket) > 1) {
            $matches = [];
            $next    = [];
            $court   = $courtOffset;

            for ($i = 0; $i < count($bracket); $i += 2) {
                $t1 = $bracket[$i];
                $t2 = $bracket[$i + 1] ?? null;

                if (!$t2) {
                    // Bye
                    $next[] = $t1;
                    continue;
                }

                $matches[] = [
                    'team1_id' => $t1['id'],
                    'team1'    => $t1['player1'] . ' / ' . $t1['player2'],
                    'team2_id' => $t2['id'],
                    'team2'    => $t2['player1'] . ' / ' . $t2['player2'],
                    'court'    => (($court - 1) % $courts) + $courtOffset,
                ];
                $court++;

                // Placeholder winner for next round
                $next[] = ['id' => "W{$round}_{$i}", 'player1' => "Winner R{$round} Match " . (intdiv($i, 2) + 1), 'player2' => '', 'avg_skill' => 0];
            }

            $rounds[$round] = $matches;
            $bracket = $next;
            $round++;
        }

        return $rounds;
    }

    /**
     * Pool play: split into pools, play within pools, then finals.
     */
    private function buildPools(array $teams, int $rounds, int $courts, int $courtOffset): array
    {
        // Split into 2 pools using snake seeding
        usort($teams, fn($a, $b) => $b['avg_skill'] <=> $a['avg_skill']);

        $poolA = [];
        $poolB = [];
        foreach ($teams as $i => $team) {
            if ($i % 4 < 2) {
                $poolA[] = $team;
            } else {
                $poolB[] = $team;
            }
        }

        $roundsOut = [];

        // Pool A matches
        $poolARounds = $this->buildRoundRobin($poolA, $rounds, intdiv($courts, 2), $courtOffset);
        foreach ($poolARounds as $r => $matches) {
            foreach ($matches as &$m) {
                $m['pool'] = 'Pool A';
            }
            $roundsOut[$r] = array_merge($roundsOut[$r] ?? [], $matches);
        }

        // Pool B matches (offset courts)
        $poolBRounds = $this->buildRoundRobin($poolB, $rounds, intdiv($courts, 2), $courtOffset + intdiv($courts, 2));
        foreach ($poolBRounds as $r => $matches) {
            foreach ($matches as &$m) {
                $m['pool'] = 'Pool B';
            }
            $roundsOut[$r] = array_merge($roundsOut[$r] ?? [], $matches);
        }

        // Finals round
        $finalRound = ($rounds + 1);
        $roundsOut[$finalRound] = [
            [
                'team1_id' => 'A1',
                'team1'    => '1st Pool A',
                'team2_id' => 'B2',
                'team2'    => '2nd Pool B',
                'court'    => $courtOffset,
                'pool'     => 'Semi-Final 1',
            ],
            [
                'team1_id' => 'B1',
                'team1'    => '1st Pool B',
                'team2_id' => 'A2',
                'team2'    => '2nd Pool A',
                'court'    => $courtOffset + 1,
                'pool'     => 'Semi-Final 2',
            ],
        ];

        $roundsOut[$finalRound + 1] = [
            [
                'team1_id' => 'SF1W',
                'team1'    => 'Winner Semi 1',
                'team2_id' => 'SF2W',
                'team2'    => 'Winner Semi 2',
                'court'    => $courtOffset,
                'pool'     => 'Final',
            ],
        ];

        return $roundsOut;
    }
}

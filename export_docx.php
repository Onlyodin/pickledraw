<?php
/**
 * export_docx.php — Pickledraw Word Document Export
 *
 * Generates a .docx file using pure PHP + ZipArchive.
 * No Node.js, no external binaries, no Composer packages required.
 * A .docx is simply a ZIP archive containing Office Open XML files.
 *
 * Requirements: PHP with ZipArchive extension (standard on all major hosts).
 */
session_start();

$teams = $_SESSION['teams'] ?? [];
$draws = $_SESSION['draws'] ?? [];

if (empty($draws)) {
    header('Location: index.php');
    exit;
}

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    echo 'Error: PHP ZipArchive extension is not available. Please enable it in php.ini.';
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// XML helpers
// ─────────────────────────────────────────────────────────────────────────────

function x(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

// Wrap text in a run with optional properties
function wr(string $text, array $rpr = []): string
{
    $props = '';
    if (!empty($rpr['bold']))    $props .= '<w:b/><w:bCs/>';
    if (!empty($rpr['italic']))  $props .= '<w:i/><w:iCs/>';
    if (!empty($rpr['size']))    $props .= '<w:sz w:val="' . ($rpr['size'] * 2) . '"/><w:szCs w:val="' . ($rpr['size'] * 2) . '"/>';
    if (!empty($rpr['color']))   $props .= '<w:color w:val="' . x($rpr['color']) . '"/>';
    if (!empty($rpr['font']))    $props .= '<w:rFonts w:ascii="' . x($rpr['font']) . '" w:hAnsi="' . x($rpr['font']) . '"/>';

    $rprXml = $props ? "<w:rPr>$props</w:rPr>" : '';
    return "<w:r>$rprXml<w:t xml:space=\"preserve\">" . x($text) . '</w:t></w:r>';
}

// A paragraph containing one or more runs
function para(string|array $runs, array $ppr = []): string
{
    $pStyle   = !empty($ppr['style'])  ? '<w:pStyle w:val="' . x($ppr['style']) . '"/>' : '';
    $jc       = !empty($ppr['align'])  ? '<w:jc w:val="'    . x($ppr['align']) . '"/>' : '';
    $spBefore = isset($ppr['before']) ? '<w:spacing w:before="' . (int)$ppr['before'] . '" w:after="' . (int)($ppr['after'] ?? 0) . '"/>' : '';
    $shd      = !empty($ppr['shade'])  ? '<w:shd w:val="clear" w:color="auto" w:fill="' . x($ppr['shade']) . '"/>' : '';

    $pprXml = ($pStyle || $jc || $spBefore || $shd) ? "<w:pPr>$pStyle$jc$spBefore$shd</w:pPr>" : '';

    if (is_string($runs)) {
        $runsXml = $runs; // already assembled XML
    } else {
        $runsXml = implode('', $runs);
    }

    return "<w:p>$pprXml$runsXml</w:p>";
}

// A table cell
function tc(string $content, int $widthTwips, string $fill = 'FFFFFF', string $vAlign = 'center', bool $header = false): string
{
    $shd  = '<w:shd w:val="clear" w:color="auto" w:fill="' . x($fill) . '"/>';
    $va   = '<w:vAlign w:val="' . x($vAlign) . '"/>';
    $w    = '<w:tcW w:w="' . $widthTwips . '" w:type="dxa"/>';
    $bdr  = '<w:tcBorders>
               <w:top    w:val="single" w:sz="4" w:color="C8D8CC"/>
               <w:bottom w:val="single" w:sz="4" w:color="C8D8CC"/>
               <w:left   w:val="single" w:sz="4" w:color="C8D8CC"/>
               <w:right  w:val="single" w:sz="4" w:color="C8D8CC"/>
             </w:tcBorders>';
    $mar  = '<w:tcMar><w:top w:w="60" w:type="dxa"/><w:bottom w:w="60" w:type="dxa"/><w:left w:w="120" w:type="dxa"/><w:right w:w="120" w:type="dxa"/></w:tcMar>';

    return "<w:tc><w:tcPr>$w$bdr$shd$va$mar</w:tcPr>$content</w:tc>";
}

// A table row
function tr(array $cells, bool $isHeader = false): string
{
    $hdrProp = $isHeader ? '<w:trPr><w:tblHeader/></w:trPr>' : '';
    return '<w:tr>' . $hdrProp . implode('', $cells) . '</w:tr>';
}

// A full table
function tbl(array $rows, array $colWidths): string
{
    $totalW = array_sum($colWidths);
    $grid   = implode('', array_map(fn($w) => '<w:gridCol w:w="' . $w . '"/>', $colWidths));

    return '<w:tbl>
      <w:tblPr>
        <w:tblW w:w="' . $totalW . '" w:type="dxa"/>
        <w:tblBorders>
          <w:insideH w:val="single" w:sz="4" w:color="C8D8CC"/>
          <w:insideV w:val="single" w:sz="4" w:color="C8D8CC"/>
        </w:tblBorders>
        <w:tblLook w:val="04A0"/>
      </w:tblPr>
      <w:tblGrid>' . $grid . '</w:tblGrid>'
        . implode('', $rows) .
    '</w:tbl>';
}

// A horizontal rule (paragraph with bottom border)
function hrule(string $color = '3DAC5A'): string
{
    return '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="' . x($color) . '"/></w:pBdr><w:spacing w:before="0" w:after="120"/></w:pPr></w:p>';
}

// A page break
function pageBreak(): string
{
    return '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
}

// Spacer paragraph
function spacer(int $twips = 80): string
{
    return '<w:p><w:pPr><w:spacing w:before="' . $twips . '" w:after="' . $twips . '"/></w:pPr></w:p>';
}

// ─────────────────────────────────────────────────────────────────────────────
// Colour constants
// ─────────────────────────────────────────────────────────────────────────────
const COL_GREEN       = '3DAC5A';
const COL_GREEN_LIGHT = 'E8F5EC';
const COL_BLUE_LIGHT  = 'DCE8F7';
const COL_BLUE        = '2E6DBF';
const COL_DARK        = '1A2620';
const COL_MID         = '3A5045';
const COL_MUTED       = '6A8A72';
const COL_ALT_ROW     = 'F4F9F5';
const COL_WHITE       = 'FFFFFF';

// ─────────────────────────────────────────────────────────────────────────────
// Build document body XML
// ─────────────────────────────────────────────────────────────────────────────

$dateStr = date('d F Y');
$bodyParts = [];

// ── Cover ────────────────────────────────────────────────────────────────────
$bodyParts[] = para(
    wr('PICKLEDRAW', ['bold' => true, 'size' => 26, 'color' => COL_GREEN, 'font' => 'Arial']),
    ['before' => 480, 'after' => 80]
);
$bodyParts[] = para(
    wr('Tournament Draw', ['bold' => true, 'size' => 18, 'color' => COL_DARK, 'font' => 'Arial']),
    ['before' => 0, 'after' => 80]
);
$bodyParts[] = para(
    wr("Generated {$dateStr}  ·  " . count($teams) . " teams  ·  " . count($draws) . " division(s)",
        ['size' => 9, 'color' => COL_MUTED, 'font' => 'Arial']),
    ['before' => 0, 'after' => 320]
);
$bodyParts[] = hrule(COL_GREEN);

// ── Teams Table ───────────────────────────────────────────────────────────────
$bodyParts[] = para(
    wr('Registered Teams', ['bold' => true, 'size' => 16, 'color' => COL_GREEN, 'font' => 'Arial']),
    ['style' => 'Heading1', 'before' => 240, 'after' => 120]
);

if (!empty($teams)) {
    // Full content width: Letter with 0.75" margins = 12240 - 2160 = 10080 twips
    $cw = [2200, 2200, 1200, 1200, 1280, 2000]; // sum = 10080 — Player1, Player2, AvgSkill, CombSkill, DUPR, Pairing

    $teamRows = [];

    $hFill = COL_GREEN;
    $hRpr  = ['bold' => true, 'size' => 8, 'color' => COL_WHITE, 'font' => 'Arial'];
    $teamRows[] = tr([
        tc(para(wr('Player 1',       $hRpr)),                        $cw[0], $hFill),
        tc(para(wr('Player 2',       $hRpr)),                        $cw[1], $hFill),
        tc(para(wr('Avg Skill',      $hRpr), ['align' => 'center']), $cw[2], $hFill),
        tc(para(wr('Comb Skill',     $hRpr), ['align' => 'center']), $cw[3], $hFill),
        tc(para(wr('Comb DUPR',      $hRpr), ['align' => 'center']), $cw[4], $hFill),
        tc(para(wr('Pairing',        $hRpr)),                        $cw[5], $hFill),
    ], true);

    foreach (array_values($teams) as $idx => $t) {
        $fill = ($idx % 2 === 1) ? COL_ALT_ROW : COL_WHITE;
        $rpr  = ['size' => 9, 'font' => 'Arial'];

        $pairLabel = $t['explicit_pair']
            ? (($t['partner_slot'] ?? 1) === 2 ? '2nd partner' : 'Named pair')
            : 'Auto-paired';

        $teamRows[] = tr([
            tc(para(wr($t['player1'] ?? '', $rpr)),                                                                $cw[0], $fill),
            tc(para(wr($t['player2'] ?? '', $rpr)),                                                                $cw[1], $fill),
            tc(para(wr(isset($t['avg_skill'])      ? (string)$t['avg_skill']      : '', $rpr), ['align' => 'center']), $cw[2], $fill),
            tc(para(wr(isset($t['combined_skill']) ? (string)$t['combined_skill'] : '', $rpr), ['align' => 'center']), $cw[3], $fill),
            tc(para(wr($t['combined_dupr'] !== null ? (string)$t['combined_dupr'] : '—', $rpr), ['align' => 'center']), $cw[4], $fill),
            tc(para(wr($pairLabel, $rpr)),                                                                         $cw[5], $fill),
        ]);
    }

    $bodyParts[] = tbl($teamRows, $cw);
    $bodyParts[] = spacer(120);
}

// ── Per-division draws ────────────────────────────────────────────────────────
$bodyParts[] = pageBreak();
$bodyParts[] = para(
    wr('Match Draw', ['bold' => true, 'size' => 16, 'color' => COL_GREEN, 'font' => 'Arial']),
    ['style' => 'Heading1', 'before' => 240, 'after' => 120]
);

$divNames = array_keys($draws);
foreach ($divNames as $divIdx => $divName) {
    $divData = $draws[$divName];

    if ($divIdx > 0) {
        $bodyParts[] = pageBreak();
    }

    // Division heading
    $divInfo = count($divData['teams'] ?? []) . ' teams · Avg skill ' . ($divData['avg_skill'] ?? '—');
    if (!empty($divData['dupr_avg'])) {
        $divInfo .= ' · Avg DUPR ' . $divData['dupr_avg'];
    }

    $bodyParts[] = para([
        wr($divName, ['bold' => true, 'size' => 14, 'color' => COL_GREEN, 'font' => 'Arial']),
        wr('  ·  ' . $divInfo, ['size' => 9, 'color' => COL_MUTED, 'font' => 'Arial']),
    ], ['before' => 240, 'after' => 80]);
    $bodyParts[] = hrule(COL_GREEN);

    // Teams in this division (compact chips — 3 per row)
    if (!empty($divData['teams'])) {
        $bodyParts[] = para(
            wr('Teams', ['bold' => true, 'size' => 10, 'color' => COL_MID, 'font' => 'Arial']),
            ['before' => 80, 'after' => 60]
        );

        $chipW   = 3360; // 3 columns × 3360 = 10080 twips (full content width)
        $chipRpr = ['size' => 8, 'font' => 'Arial'];
        $chipRows = [];
        $divTeams = array_values($divData['teams']);

        for ($i = 0; $i < count($divTeams); $i += 3) {
            $rowCells = [];
            for ($k = 0; $k < 3; $k++) {
                if (isset($divTeams[$i + $k])) {
                    $t = $divTeams[$i + $k];
                    $duprPart = ($t['combined_dupr'] !== null)
                        ? '  DUPR ' . $t['combined_dupr']
                        : '';
                    $cellContent = para([
                        wr(($t['player1'] ?? '') . ' & ' . ($t['player2'] ?? ''), $chipRpr),
                        wr($duprPart, ['size' => 7, 'color' => COL_BLUE, 'font' => 'Arial']),
                    ]);
                    $rowCells[] = tc($cellContent, $chipW, COL_ALT_ROW);
                } else {
                    // empty filler cell — no border
                    $emptyCell = para(wr(''));
                    $rowCells[] = '<w:tc><w:tcPr><w:tcW w:w="' . $chipW . '" w:type="dxa"/>'
                        . '<w:tcBorders><w:top w:val="none" w:sz="0" w:color="FFFFFF"/>'
                        . '<w:bottom w:val="none" w:sz="0" w:color="FFFFFF"/>'
                        . '<w:left w:val="none" w:sz="0" w:color="FFFFFF"/>'
                        . '<w:right w:val="none" w:sz="0" w:color="FFFFFF"/></w:tcBorders>'
                        . '</w:tcPr>' . $emptyCell . '</w:tc>';
                }
            }
            $chipRows[] = tr($rowCells);
        }

        $bodyParts[] = tbl($chipRows, [$chipW, $chipW, $chipW]);
        $bodyParts[] = spacer(100);
    }

    // Rounds
    $rounds = $divData['rounds'] ?? [];
    ksort($rounds, SORT_NUMERIC);

    foreach ($rounds as $roundNum => $matches) {
        if (empty($matches)) continue;

        $bodyParts[] = para(
            wr('Round ' . $roundNum, ['bold' => true, 'size' => 11, 'color' => COL_MID, 'font' => 'Arial']),
            ['before' => 160, 'after' => 60]
        );

        // Match table: Court | Team 1 | vs | Team 2 | Score — full content width
        $mw  = [1000, 3880, 440, 3880, 880]; // sum = 10080 twips
        $hRpr = ['bold' => true, 'size' => 8, 'color' => COL_DARK, 'font' => 'Arial'];
        $mRows = [];

        $mRows[] = tr([
            tc(para(wr('Court',  $hRpr), ['align' => 'center']), $mw[0], COL_BLUE_LIGHT),
            tc(para(wr('Team 1', $hRpr)),                        $mw[1], COL_BLUE_LIGHT),
            tc(para(wr('vs',     $hRpr), ['align' => 'center']), $mw[2], COL_BLUE_LIGHT),
            tc(para(wr('Team 2', $hRpr)),                        $mw[3], COL_BLUE_LIGHT),
            tc(para(wr('Score',  $hRpr), ['align' => 'center']), $mw[4], COL_BLUE_LIGHT),
        ], true);

        foreach (array_values($matches) as $mIdx => $m) {
            $fill = ($mIdx % 2 === 1) ? COL_ALT_ROW : COL_WHITE;

            if (!empty($m['note'])) {
                $totalW   = array_sum($mw);
                $noteCell = '<w:tc>'
                    . '<w:tcPr><w:tcW w:w="' . $totalW . '" w:type="dxa"/>'
                    . '<w:gridSpan w:val="5"/>'
                    . '<w:shd w:val="clear" w:color="auto" w:fill="' . COL_WHITE . '"/></w:tcPr>'
                    . para(wr($m['note'], ['size' => 8, 'italic' => true, 'color' => COL_MUTED, 'font' => 'Arial']))
                    . '</w:tc>';
                $mRows[] = '<w:tr>' . $noteCell . '</w:tr>';
                continue;
            }

            $courtLabel = !empty($m['court']) ? 'Court ' . $m['court'] : '';
            $altLabel   = !empty($m['alt_partner']) ? ' (' . $m['alt_partner'] . ')' : '';

            if (!empty($m['is_bye'])) {
                // Bye or Singles row — span team2 and score cells
                $t1Rpr      = ['size' => 9, 'font' => 'Arial', 'italic' => true, 'color' => COL_MUTED];
                $byeSpanW   = $mw[2] + $mw[3] + $mw[4];
                $byeCell    = '<w:tc>'
                    . '<w:tcPr><w:tcW w:w="' . $byeSpanW . '" w:type="dxa"/>'
                    . '<w:gridSpan w:val="3"/>'
                    . '<w:shd w:val="clear" w:color="auto" w:fill="' . $fill . '"/></w:tcPr>'
                    . para(wr('Bye or Singles', ['size' => 8, 'italic' => true, 'color' => COL_MUTED, 'font' => 'Arial']), ['align' => 'center'])
                    . '</w:tc>';
                $mRows[] = '<w:tr>'
                    . tc(para(wr($courtLabel, ['size' => 9, 'font' => 'Arial']), ['align' => 'center']), $mw[0], $fill)
                    . tc(para(wr($m['team1'] ?? '', $t1Rpr)),                                            $mw[1], $fill)
                    . $byeCell
                    . '</w:tr>';
                continue;
            }

            $t1Rpr = ['size' => 9, 'font' => 'Arial'];
            $t2Rpr = $altLabel
                ? ['size' => 9, 'font' => 'Arial', 'color' => COL_BLUE]
                : ['size' => 9, 'font' => 'Arial'];

            $mRows[] = tr([
                tc(para(wr($courtLabel,                          ['size' => 9, 'font' => 'Arial']), ['align' => 'center']), $mw[0], $fill),
                tc(para(wr($m['team1'] ?? '',                    $t1Rpr)),                                                  $mw[1], $fill),
                tc(para(wr('vs',                                 ['size' => 9, 'font' => 'Arial']), ['align' => 'center']), $mw[2], $fill),
                tc(para(wr(($m['team2'] ?? '') . $altLabel,      $t2Rpr)),                                                  $mw[3], $fill),
                tc(para(wr('',                                   ['size' => 9, 'font' => 'Arial'])),                        $mw[4], $fill),
            ]);
        }

        $bodyParts[] = tbl($mRows, $mw);
        $bodyParts[] = spacer(80);
    }
}

$bodyXml = implode("\n", $bodyParts);

// ─────────────────────────────────────────────────────────────────────────────
// Assemble DOCX (ZIP + Open XML)
// ─────────────────────────────────────────────────────────────────────────────

// [Content_Types].xml
$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml"  ContentType="application/xml"/>
  <Override PartName="/word/document.xml"
    ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
  <Override PartName="/word/styles.xml"
    ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>
  <Override PartName="/word/settings.xml"
    ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>
  <Override PartName="/word/header1.xml"
    ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>
  <Override PartName="/word/footer1.xml"
    ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>
</Types>';

// _rels/.rels
$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"
    Target="word/document.xml"/>
</Relationships>';

// word/_rels/document.xml.rels
$docRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"    Target="styles.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings"  Target="settings.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header"    Target="header1.xml"/>
  <Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer"    Target="footer1.xml"/>
</Relationships>';

// word/settings.xml
$settings = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:defaultTabStop w:val="720"/>
  <w:compat><w:compatSetting w:name="compatibilityMode" w:uri="http://schemas.microsoft.com/office/word" w:val="15"/></w:compat>
</w:settings>';

// word/styles.xml — minimal styles for heading, normal text
$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"
          xmlns:w14="http://schemas.microsoft.com/office/word/2010/wordml">
  <w:docDefaults>
    <w:rPrDefault><w:rPr>
      <w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>
      <w:sz w:val="20"/><w:szCs w:val="20"/>
      <w:color w:val="1A2620"/>
    </w:rPr></w:rPrDefault>
    <w:pPrDefault><w:pPr><w:spacing w:after="80"/></w:pPr></w:pPrDefault>
  </w:docDefaults>
  <w:style w:type="paragraph" w:styleId="Normal">
    <w:name w:val="Normal"/>
  </w:style>
  <w:style w:type="paragraph" w:styleId="Heading1">
    <w:name w:val="heading 1"/>
    <w:basedOn w:val="Normal"/>
    <w:pPr><w:spacing w:before="240" w:after="120"/><w:outlineLvl w:val="0"/></w:pPr>
    <w:rPr>
      <w:rFonts w:ascii="Arial" w:hAnsi="Arial"/>
      <w:b/><w:bCs/>
      <w:sz w:val="32"/><w:szCs w:val="32"/>
      <w:color w:val="3DAC5A"/>
    </w:rPr>
  </w:style>
  <w:style w:type="paragraph" w:styleId="Heading2">
    <w:name w:val="heading 2"/>
    <w:basedOn w:val="Normal"/>
    <w:pPr><w:spacing w:before="180" w:after="80"/><w:outlineLvl w:val="1"/></w:pPr>
    <w:rPr>
      <w:rFonts w:ascii="Arial" w:hAnsi="Arial"/>
      <w:b/><w:bCs/>
      <w:sz w:val="26"/><w:szCs w:val="26"/>
      <w:color w:val="3A5045"/>
    </w:rPr>
  </w:style>
  <w:style w:type="character" w:styleId="HeaderChar">
    <w:name w:val="Header Char"/>
    <w:rPr>
      <w:rFonts w:ascii="Arial" w:hAnsi="Arial"/>
      <w:sz w:val="16"/><w:szCs w:val="16"/>
      <w:color w:val="6A8A72"/>
    </w:rPr>
  </w:style>
</w:styles>';

// word/header1.xml
$header1 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:hdr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:p>
    <w:pPr>
      <w:pBdr><w:bottom w:val="single" w:sz="4" w:space="1" w:color="C8D8CC"/></w:pBdr>
      <w:spacing w:after="80"/>
      <w:tabs><w:tab w:val="right" w:pos="9360"/></w:tabs>
    </w:pPr>
    <w:r><w:rPr><w:rStyle w:val="HeaderChar"/></w:rPr>
      <w:t xml:space="preserve">PICKLEDRAW  ·  Tournament Draw</w:t>
    </w:r>
    <w:r><w:rPr><w:rStyle w:val="HeaderChar"/></w:rPr><w:tab/></w:r>
    <w:r><w:rPr><w:rStyle w:val="HeaderChar"/></w:rPr>
      <w:t>' . x($dateStr) . '</w:t>
    </w:r>
  </w:p>
</w:hdr>';

// word/footer1.xml — page number
$footer1 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:p>
    <w:pPr>
      <w:pBdr><w:top w:val="single" w:sz="4" w:space="1" w:color="C8D8CC"/></w:pBdr>
      <w:jc w:val="right"/>
      <w:spacing w:before="80"/>
    </w:pPr>
    <w:r><w:rPr>
      <w:rFonts w:ascii="Arial" w:hAnsi="Arial"/>
      <w:sz w:val="16"/><w:szCs w:val="16"/>
      <w:color w:val="6A8A72"/>
    </w:rPr>
      <w:t xml:space="preserve">Page </w:t>
    </w:r>
    <w:fldSimple w:instr=" PAGE ">
      <w:r><w:rPr>
        <w:rFonts w:ascii="Arial" w:hAnsi="Arial"/>
        <w:sz w:val="16"/><w:szCs w:val="16"/>
        <w:color w:val="6A8A72"/>
      </w:rPr><w:t>1</w:t></w:r>
    </w:fldSimple>
  </w:p>
</w:ftr>';

// word/document.xml — main body
$document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"
            xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <w:body>
    ' . $bodyXml . '
    <w:sectPr>
      <w:headerReference w:type="default" r:id="rId3"/>
      <w:footerReference w:type="default" r:id="rId4"/>
      <w:pgSz w:w="12240" w:h="15840"/>
      <w:pgMar w:top="1080" w:right="1080" w:bottom="1080" w:left="1080"
               w:header="360" w:footer="360" w:gutter="0"/>
    </w:sectPr>
  </w:body>
</w:document>';

// ─────────────────────────────────────────────────────────────────────────────
// Write to a temp file then stream to browser
// ─────────────────────────────────────────────────────────────────────────────
$tmpFile = tempnam(sys_get_temp_dir(), 'pickledraw_') . '.docx';

$zip = new ZipArchive();
if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    echo 'Error: Could not create temporary file for Word export.';
    exit;
}

$zip->addFromString('[Content_Types].xml',           $contentTypes);
$zip->addFromString('_rels/.rels',                   $rels);
$zip->addFromString('word/_rels/document.xml.rels',  $docRels);
$zip->addFromString('word/document.xml',             $document);
$zip->addFromString('word/styles.xml',               $styles);
$zip->addFromString('word/settings.xml',             $settings);
$zip->addFromString('word/header1.xml',              $header1);
$zip->addFromString('word/footer1.xml',              $footer1);
$zip->close();

// Stream to browser
$filename = 'pickledraw_draw_' . date('Ymd_His') . '.docx';

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpFile));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

readfile($tmpFile);
unlink($tmpFile);
exit;

#!/usr/bin/env node
/**
 * generate_docx.js
 * Pickledraw — Tournament Draw Word Document Generator
 *
 * Reads JSON from stdin: { teams, draws, generatedAt, title }
 * Writes .docx binary to stdout
 *
 * Usage: echo '{"teams":[...],"draws":{...}}' | node generate_docx.js > out.docx
 */

'use strict';

const {
    Document, Packer, Paragraph, TextRun, Table, TableRow, TableCell,
    Header, Footer, AlignmentType, HeadingLevel, BorderStyle,
    WidthType, ShadingType, VerticalAlign, PageNumber, SimpleField,
    TabStopType, TabStopPosition, PageOrientation, PageBreak,
} = require('docx');

// ─── Read stdin ────────────────────────────────────────────────────────────────
let raw = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', chunk => { raw += chunk; });
process.stdin.on('end', async () => {
    try {
        const data = JSON.parse(raw);
        const buffer = await buildDocument(data);
        process.stdout.write(buffer);
    } catch (err) {
        process.stderr.write('ERROR: ' + err.message + '\n' + err.stack + '\n');
        process.exit(1);
    }
});

// ─── Colour palette (Word hex, no #) ──────────────────────────────────────────
const C = {
    green:      '3dac5a',   // Pickledraw accent
    greenLight: 'e8f5ec',
    blue:       '2e6dbf',
    blueLight:  'dce8f7',
    amber:      'c47f00',
    amberLight: 'fef7e6',
    dark:       '1a2620',
    mid:        '3a5045',
    muted:      '6a8a72',
    border:     'c8d8cc',
    white:      'FFFFFF',
    altRow:     'f4f9f5',
};

// ─── Shared border style ───────────────────────────────────────────────────────
function cellBorder(color = C.border) {
    const b = { style: BorderStyle.SINGLE, size: 4, color };
    return { top: b, bottom: b, left: b, right: b };
}

function noBorder() {
    const b = { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' };
    return { top: b, bottom: b, left: b, right: b };
}

// ─── Cell helper ──────────────────────────────────────────────────────────────
function cell(children, { width = 1000, bold = false, shade = null, align = AlignmentType.LEFT, vAlign = VerticalAlign.CENTER, borders = null, size = 18 } = {}) {
    const runs = typeof children === 'string'
        ? [new TextRun({ text: children, bold, font: 'Arial', size })]
        : children;

    return new TableCell({
        width: { size: width, type: WidthType.DXA },
        borders: borders ?? cellBorder(),
        shading: shade ? { fill: shade, type: ShadingType.CLEAR } : undefined,
        verticalAlign: vAlign,
        margins: { top: 60, bottom: 60, left: 120, right: 120 },
        children: [new Paragraph({ alignment: align, children: runs })],
    });
}

// ─── Heading helper ───────────────────────────────────────────────────────────
function heading(text, level = HeadingLevel.HEADING_1, opts = {}) {
    return new Paragraph({
        heading: level,
        spacing: { before: opts.before ?? 240, after: opts.after ?? 120 },
        children: [new TextRun({
            text,
            bold: true,
            font: 'Arial',
            size: opts.size ?? (level === HeadingLevel.HEADING_1 ? 32 : level === HeadingLevel.HEADING_2 ? 26 : 22),
            color: opts.color ?? C.green,
        })],
    });
}

// ─── Spacer paragraph ─────────────────────────────────────────────────────────
function spacer(pts = 60) {
    return new Paragraph({ spacing: { before: pts, after: pts }, children: [] });
}

// ─── HR-style paragraph border ────────────────────────────────────────────────
function rule(color = C.border) {
    return new Paragraph({
        border: { bottom: { style: BorderStyle.SINGLE, size: 6, color, space: 1 } },
        spacing: { before: 0, after: 120 },
        children: [],
    });
}

// ─── Main document builder ────────────────────────────────────────────────────
async function buildDocument({ teams = [], draws = {}, generatedAt = '', title = 'Tournament Draw' }) {
    const dateStr = generatedAt
        ? new Date(generatedAt).toLocaleDateString('en-AU', { day: '2-digit', month: 'long', year: 'numeric' })
        : new Date().toLocaleDateString('en-AU', { day: '2-digit', month: 'long', year: 'numeric' });

    const children = [];

    // ── Cover / Title ──────────────────────────────────────────────────────────
    children.push(
        new Paragraph({
            spacing: { before: 480, after: 120 },
            children: [new TextRun({
                text: '🥒 PICKLEDRAW',
                bold: true, font: 'Arial', size: 52, color: C.green,
            })],
        }),
        new Paragraph({
            spacing: { before: 0, after: 80 },
            children: [new TextRun({
                text: title || 'Tournament Draw',
                bold: true, font: 'Arial', size: 36, color: C.dark,
            })],
        }),
        new Paragraph({
            spacing: { before: 0, after: 480 },
            children: [new TextRun({
                text: `Generated ${dateStr}  ·  ${teams.length} teams  ·  ${Object.keys(draws).length} division(s)`,
                font: 'Arial', size: 18, color: C.muted,
            })],
        }),
        rule(C.green),
    );

    // ── Teams Summary Table ────────────────────────────────────────────────────
    const PAGE_WIDTH = 9360; // US Letter, 1" margins

    children.push(
        heading('Registered Teams', HeadingLevel.HEADING_1),
    );

    if (teams.length > 0) {
        // Header row
        const colW = [400, 1800, 1800, 1200, 1200, 1200, 1560]; // sum = 9160 (leave a little flex)
        const teamRows = [
            new TableRow({
                tableHeader: true,
                children: [
                    cell('#',          { width: colW[0], bold: true, shade: C.green, size: 16 }),
                    cell('Player 1',   { width: colW[1], bold: true, shade: C.green, size: 16 }),
                    cell('Player 2',   { width: colW[2], bold: true, shade: C.green, size: 16 }),
                    cell('Division',   { width: colW[3], bold: true, shade: C.green, size: 16 }),
                    cell('Avg Skill',  { width: colW[4], bold: true, shade: C.green, size: 16 }),
                    cell('Comb DUPR',  { width: colW[5], bold: true, shade: C.green, size: 16 }),
                    cell('Pair Type',  { width: colW[6], bold: true, shade: C.green, size: 16 }),
                ],
            }),
        ];

        teams.forEach((t, idx) => {
            const isAlt = idx % 2 === 1;
            const shade = isAlt ? C.altRow : C.white;
            teamRows.push(new TableRow({
                children: [
                    cell(String(t.id),                                        { width: colW[0], shade }),
                    cell(t.player1 || '',                                     { width: colW[1], shade }),
                    cell(t.player2 || '',                                     { width: colW[2], shade }),
                    cell(t.avg_skill != null ? String(t.avg_skill) : '',      { width: colW[3], shade, align: AlignmentType.CENTER }),
                    cell(t.avg_skill != null ? String(t.avg_skill) : '',      { width: colW[4], shade, align: AlignmentType.CENTER }),
                    cell(t.combined_dupr != null ? String(t.combined_dupr) : '—', { width: colW[5], shade, align: AlignmentType.CENTER }),
                    cell(t.explicit_pair ? (t.partner_slot === 2 ? '2nd partner' : '✓ Named') : 'Auto-paired', { width: colW[6], shade }),
                ],
            }));
        });

        children.push(
            new Table({
                width: { size: PAGE_WIDTH, type: WidthType.DXA },
                columnWidths: colW,
                rows: teamRows,
            }),
            spacer(120),
        );
    }

    // ── Per-division draw ──────────────────────────────────────────────────────
    children.push(
        new Paragraph({ children: [new PageBreak()] }),
        heading('Match Draw', HeadingLevel.HEADING_1),
    );

    const divNames = Object.keys(draws);

    for (const [divIdx, divName] of divNames.entries()) {
        const divData = draws[divName];
        if (divIdx > 0) {
            children.push(new Paragraph({ children: [new PageBreak()] }));
        }

        // Division heading
        children.push(
            new Paragraph({
                spacing: { before: 240, after: 60 },
                children: [
                    new TextRun({ text: divName, bold: true, font: 'Arial', size: 28, color: C.green }),
                    new TextRun({
                        text: `  ·  ${divData.teams?.length ?? 0} teams  ·  Avg skill ${divData.avg_skill ?? '—'}${divData.dupr_avg ? `  ·  Avg DUPR ${divData.dupr_avg}` : ''}`,
                        font: 'Arial', size: 18, color: C.muted,
                    }),
                ],
            }),
            rule(C.green),
        );

        // Teams in this division
        if (divData.teams?.length > 0) {
            children.push(
                new Paragraph({
                    spacing: { before: 80, after: 60 },
                    children: [new TextRun({ text: 'Teams', bold: true, font: 'Arial', size: 20, color: C.mid })],
                }),
            );

            const teamChipW = Math.floor(PAGE_WIDTH / Math.min(divData.teams.length, 3));
            const rows = [];
            for (let i = 0; i < divData.teams.length; i += 3) {
                const chunk = divData.teams.slice(i, i + 3);
                while (chunk.length < 3) chunk.push(null); // pad to 3 per row
                rows.push(new TableRow({
                    children: chunk.map(t => t
                        ? cell([
                            new TextRun({ text: `#${t.id}  `, bold: true, font: 'Arial', size: 16, color: C.green }),
                            new TextRun({ text: `${t.player1} & ${t.player2}`, font: 'Arial', size: 16 }),
                            ...(t.combined_dupr ? [new TextRun({ text: `  DUPR ${t.combined_dupr}`, font: 'Arial', size: 14, color: C.blue })] : []),
                          ], { width: teamChipW, shade: C.altRow, borders: cellBorder(C.border) })
                        : cell('', { width: teamChipW, borders: noBorder() })
                    ),
                }));
            }

            children.push(
                new Table({
                    width: { size: PAGE_WIDTH, type: WidthType.DXA },
                    columnWidths: Array(3).fill(teamChipW),
                    rows,
                }),
                spacer(100),
            );
        }

        // Rounds
        const rounds = divData.rounds ?? {};
        for (const roundNum of Object.keys(rounds).sort((a, b) => Number(a) - Number(b))) {
            const matches = rounds[roundNum];
            if (!matches?.length) continue;

            children.push(
                new Paragraph({
                    spacing: { before: 160, after: 60 },
                    children: [new TextRun({ text: `Round ${roundNum}`, bold: true, font: 'Arial', size: 22, color: C.mid })],
                }),
            );

            // Match table: Court | Team 1 | VS | Team 2 | Score
            const mColW = [800, 3100, 460, 3100, 900]; // sum = 8360 (narrower to leave room)
            const matchRows = [
                new TableRow({
                    tableHeader: true,
                    children: [
                        cell('Court',  { width: mColW[0], bold: true, shade: C.blueLight, size: 16 }),
                        cell('Team 1', { width: mColW[1], bold: true, shade: C.blueLight, size: 16 }),
                        cell('vs',     { width: mColW[2], bold: true, shade: C.blueLight, size: 16, align: AlignmentType.CENTER }),
                        cell('Team 2', { width: mColW[3], bold: true, shade: C.blueLight, size: 16 }),
                        cell('Score',  { width: mColW[4], bold: true, shade: C.blueLight, size: 16, align: AlignmentType.CENTER }),
                    ],
                }),
            ];

            matches.forEach((m, idx) => {
                if (m.note) {
                    matchRows.push(new TableRow({
                        children: [
                            new TableCell({
                                columnSpan: 5,
                                width: { size: mColW.reduce((a,b)=>a+b,0), type: WidthType.DXA },
                                borders: cellBorder(),
                                margins: { top: 60, bottom: 60, left: 120, right: 120 },
                                children: [new Paragraph({
                                    children: [new TextRun({ text: m.note, font: 'Arial', size: 16, italics: true, color: C.muted })],
                                })],
                            }),
                        ],
                    }));
                    return;
                }

                const shade = idx % 2 === 1 ? C.altRow : C.white;
                const courtLabel = m.court ? `Court ${m.court}` : '';
                const altTag = m.alt_partner ? ` (${m.alt_partner})` : '';

                matchRows.push(new TableRow({
                    children: [
                        cell(courtLabel, { width: mColW[0], shade }),
                        cell([
                            new TextRun({ text: `#${m.team1_id}  `, font: 'Arial', size: 17, color: C.green, bold: true }),
                            new TextRun({ text: m.team1 || '', font: 'Arial', size: 17 }),
                        ], { width: mColW[1], shade }),
                        cell('vs', { width: mColW[2], shade, align: AlignmentType.CENTER }),
                        cell([
                            new TextRun({ text: `#${m.team2_id}  `, font: 'Arial', size: 17, color: C.green, bold: true }),
                            new TextRun({ text: (m.team2 || '') + altTag, font: 'Arial', size: 17 }),
                        ], { width: mColW[3], shade }),
                        cell('', { width: mColW[4], shade }),
                    ],
                }));
            });

            children.push(
                new Table({
                    width: { size: mColW.reduce((a, b) => a + b, 0), type: WidthType.DXA },
                    columnWidths: mColW,
                    rows: matchRows,
                }),
                spacer(80),
            );
        }
    }

    // ── Document assembly ──────────────────────────────────────────────────────
    const doc = new Document({
        styles: {
            default: {
                document: { run: { font: 'Arial', size: 18, color: C.dark } },
            },
            paragraphStyles: [
                {
                    id: 'Heading1', name: 'Heading 1', basedOn: 'Normal', next: 'Normal', quickFormat: true,
                    run: { size: 32, bold: true, font: 'Arial', color: C.green },
                    paragraph: { spacing: { before: 240, after: 120 }, outlineLevel: 0 },
                },
                {
                    id: 'Heading2', name: 'Heading 2', basedOn: 'Normal', next: 'Normal', quickFormat: true,
                    run: { size: 26, bold: true, font: 'Arial', color: C.mid },
                    paragraph: { spacing: { before: 180, after: 80 }, outlineLevel: 1 },
                },
            ],
        },
        sections: [{
            properties: {
                page: {
                    size: { width: 12240, height: 15840 },
                    margin: { top: 1080, right: 1080, bottom: 1080, left: 1080 },
                },
            },
            headers: {
                default: new Header({
                    children: [new Paragraph({
                        border: { bottom: { style: BorderStyle.SINGLE, size: 4, color: C.border, space: 1 } },
                        children: [
                            new TextRun({ text: '🥒 PICKLEDRAW  ·  Tournament Draw', font: 'Arial', size: 16, color: C.muted }),
                        ],
                        tabStops: [{ type: TabStopType.RIGHT, position: TabStopPosition.MAX }],
                    })],
                }),
            },
            footers: {
                default: new Footer({
                    children: [new Paragraph({
                        border: { top: { style: BorderStyle.SINGLE, size: 4, color: C.border, space: 1 } },
                        children: [
                            new TextRun({ text: `Generated ${dateStr}`, font: 'Arial', size: 14, color: C.muted }),
                            new TextRun({ text: '\t', font: 'Arial', size: 14 }),
                            new SimpleField('PAGE', undefined, [new TextRun({ font: 'Arial', size: 14, color: C.muted })]),
                        ],
                        tabStops: [{ type: TabStopType.RIGHT, position: TabStopPosition.MAX }],
                    })],
                }),
            },
            children,
        }],
    });

    return await Packer.toBuffer(doc);
}

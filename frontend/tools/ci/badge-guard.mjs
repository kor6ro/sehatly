#!/usr/bin/env node
/**
 * CI guard: detect status badge anti-patterns that bypass StatusBadge.
 *
 * Usage: node tools/ci/badge-guard.mjs
 * Exit code: 0 = clean, 1 = violations found
 *
 * Only flags patterns that look like STATUS BADGES (chips showing entity state):
 * - Badge component with status color classes
 * - div/span with status bg + text classes that function as badges
 * - Missing dataStatus on StatusBadge usage
 *
 * Does NOT flag:
 * - Error message text (text-destructive for errors)
 * - Success/warning icons (text-success for checkmarks)
 * - Alert banners (bg-warning/10 for info banners)
 * - Regular Badge for non-status (counts, "New" labels, etc.)
 */

import { readdirSync, readFileSync, statSync } from 'fs';
import { resolve, extname } from 'path';

const ROOT = resolve(process.cwd(), 'src');

function walk(dir, files = []) {
    for (const entry of readdirSync(dir)) {
        const full = resolve(dir, entry);
        if (statSync(full).isDirectory()) {
            walk(full, files);
        } else if (['.tsx', '.ts'].includes(extname(entry))) {
            files.push(full);
        }
    }
    return files;
}

const FILES = walk(ROOT);

// Patterns that indicate a STATUS BADGE (not just any status color usage)
// These are: Badge component with status color classes that represent entity state
const STATUS_BADGE_PATTERNS = [
    // Badge component with status bg/10 + text-status (classic status badge pattern)
    // Match <Badge (with space or >) but not <BadgeCheck
    {
        name: 'badge-component-status-colors',
        regex: /<Badge(?:\s|>)[^>]*className=[^>]*(bg-(success|warning|destructive|info)\/10|text-(success|warning|destructive|info)(?![-\w]*subtle))[^>]*>/,
        message: 'Badge with status colors appears to be a status badge - use StatusBadge instead',
    },
    // div/span with badge-like classes: bg-status/10 + text-status + rounded + px/py
    {
        name: 'inline-badge-status-colors',
        regex: /<(div|span)[^>]*className=[^>]*(bg-(success|warning|destructive|info)\/10)[^>]*(text-(success|warning|destructive|info)(?![-\w]*subtle))[^>]*>/,
        message: 'Inline element with status badge styling - use StatusBadge instead',
    },
];

// Check that StatusBadge usages have dataStatus attribute
let violations = 0;

for (const fullPath of FILES) {
    const relPath = fullPath.replace(resolve(process.cwd(), 'src') + '/', '');
    const content = readFileSync(fullPath, 'utf-8');

    // Skip test files and the StatusBadge component itself
    if (relPath.includes('.test.') || relPath.includes('status-badge.tsx')) continue;

    // Check StatusBadge usages have dataStatus
    if (content.includes('<StatusBadge') && !content.includes('dataStatus=')) {
        console.error(`⚠️  ${relPath}: StatusBadge usage missing dataStatus prop`);
        violations++;
    }

    // Check for status badge anti-patterns
    const lines = content.split('\n');
    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        for (const { name, regex, message } of STATUS_BADGE_PATTERNS) {
            if (regex.test(line)) {
                console.error(`❌ ${relPath}:${i + 1} [${name}] ${message}`);
                console.error(`   ${line.trim()}`);
                violations++;
            }
        }
    }
}

if (violations > 0) {
    console.error(`\n${violations} violation(s) found. Fix or add to allowlist if intentional.`);
    process.exit(1);
} else {
    console.log('✅ Badge guard: no status badge violations');
    process.exit(0);
}
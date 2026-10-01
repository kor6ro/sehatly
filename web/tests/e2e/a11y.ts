import AxeBuilder from '@axe-core/playwright';
import { expect, type Page } from '@playwright/test';

/**
 * Assert that a page has no automatically-detectable accessibility violations.
 *
 * ## Why this exists, and what it does NOT cover
 *
 * `web/AGENTS.md` requires >= 44 px touch targets, >= 4.5:1 text contrast, visible
 * keyboard focus and a label on every input, and the UX benchmark found **no** external
 * candidate whose accessibility could be evidenced (`web/ux/app-css-audit.md`,
 * `web/ux/reports`). Because there is no benchmark to copy, the guarantee has to be
 * enforced by a tool rather than by a reference app.
 *
 * axe-core is a *subset* of WCAG: it catches missing labels, colour-contrast failures,
 * duplicate ids, invalid ARIA, and target-size/landmark issues. It cannot judge whether
 * a flow makes sense, whether a status is understandable, or whether a screen reader
 * announcement is well written. Use it in every acceptance criterion that touches a
 * rendered screen, and keep the manual keyboard/screen-reader pass alongside it.
 *
 * ## Usage
 *
 * ```ts
 * test('AC-4: /booking has no detectable a11y violations', async ({ page }) => {
 *     await page.goto('/dokter/5');
 *     await expectNoA11yViolations(page);
 * });
 * ```
 */
export async function expectNoA11yViolations(page: Page): Promise<void> {
    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
        .analyze();

    const summary = results.violations.map((violation) => ({
        id: violation.id,
        impact: violation.impact,
        nodes: violation.nodes.length,
        help: violation.help,
        targets: violation.nodes.map((node) => node.target.join(' ')),
    }));

    // The message is the JSON summary rather than a bare length, so a failure names the
    // rule and the count instead of "expected 0, received 3".
    expect(summary, JSON.stringify(summary, null, 2)).toEqual([]);
}

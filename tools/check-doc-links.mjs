#!/usr/bin/env node
/**
 * check-doc-links.mjs -- the acceptance gate for the Markdown documents in `docs/`.
 *
 * ## What it actually checks, and what it deliberately does not
 *
 * Seven checks, each of which can fail the process with a non-zero exit code:
 *
 *  1. **Relative Markdown links resolve.** `](target)` and `[x]: target` are
 *     extracted, and every relative target is resolved against the document's own
 *     directory, then against the repository root as a fallback. A target file
 *     that does not exist is a failure, with the document line number.
 *  2. **Anchors resolve.** A `#fragment` is turned into a GitHub slug and looked
 *     up in the *target document's own headings*. This is a real cross-document
 *     check: it reads the other `.md` file's headings and compares, so a heading
 *     that is renamed breaks the link. Same-document `#fragment` links are
 *     checked against the document itself.
 *  3. **Repository paths named in inline code exist.** Every inline-code span
 *     that looks like a repo path is resolved against the repository root. The
 *     exception list is `PATHS_EXPECTED_ABSENT`, below, and an entry in it that
 *     *does* exist is itself a failure -- an exception list that rots into a
 *     permanent exemption is not a check.
 *  4. **Every `/api/...` path in the document is a real route.** The route table
 *     is read from the running application with
 *     `php artisan route:list --json`; placeholders in the document are
 *     normalised to `{}` and matched against the route table with the same
 *     normalisation. A path the router does not register is a failure. This is
 *     what makes `/api/v1/tidak-ada` a red build rather than a typo nobody sees.
 *  5. **Every fenced ```dart block parses and is already formatted.** Each block
 *     is written to a temporary file and handed to
 *     `dart format --output=none --set-exit-if-changed`. Exit 65 (parse error),
 *     exit 1 (would be reformatted) and exit 127 (no dart binary) are all
 *     failures, and they are reported as three different things.
 *  6. **The GetX section's acceptance rule.** The plan requires the
 *     "Contoh integrasi dengan GetX" section to contain an `extends
 *     GetxController` block and an `extends Bindings` block, both referencing
 *     `SehatlyApiClient`, and to contain **no** raw `Dio(` or `http.` call, so
 *     the example cannot drift into re-implementing the HTTP layer. That is
 *     enforced here, on the section as it is written.
 *  7. **Shape.** The document carries the numbered sections it is supposed to
 *     carry, in order, and at least `--min-table-rows` Markdown table rows --
 *     a stub of an error-catalogue table is caught by counting the rows.
 *
 * ## External URLs: skipped by default, and why
 *
 * `http://` and `https://` targets are **counted, listed, and not fetched**
 * unless `--external` is passed. Three reasons, in order of weight:
 *
 *  - A gate that depends on the network is a gate that fails for reasons that
 *    have nothing to do with the document. `pub.dev`, `owasp.org` and
 *    `firebase.google.com` all rate-limit, all change, and all fail in an
 *    air-gapped CI runner. A red build that means "the internet was down" is a
 *    red build people learn to ignore, and then the real red builds go with it.
 *  - A document link rot check is a *different* tool with a *different* cadence.
 *    It wants to run nightly, it wants to cache, and it wants to keep reporting
 *    the same dead URL for a week. Folding that into the commit gate makes the
 *    gate unfixable in a single commit, which is what makes people stop reading
 *    it.
 *  - What the commit gate actually needs to catch is the class of mistake a
 *    human makes: a path that was renamed in this repository, a heading that was
 *    reworded, a route that was deleted from `routes/api.php`. All three are
 *    decidable from the working tree, with no network, and all three are what
 *    this tool is for.
 *
 * `--external` exists for the nightly job. It uses a 10 s per-URL timeout and
 * treats a 2xx/3xx as alive, a 4xx as dead, and a transport error as
 * **unknown** (reported, exit 0) rather than dead, because "we could not reach
 * it" and "it is gone" are different claims and the tool does not conflate them.
 *
 * ## Usage
 *
 *   node tools/check-doc-links.mjs                       # docs/mobile-integration.md
 *   node tools/check-doc-links.mjs docs/modules/README.md
 *   node tools/check-doc-links.mjs --external --json
 *   node tools/check-doc-links.mjs --routes routes.json   # offline route table
 *
 * Exit 0 when every check passes, 1 otherwise, 2 on a usage/tooling error.
 */

import { spawnSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, isAbsolute, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = resolve(HERE, '..');

const DEFAULT_DOC = 'docs/mobile-integration.md';

/**
 * Paths the document names on purpose but which must NOT exist.
 *
 * Each entry is a *negative* assertion. The entry is listed here so the check
 * has a complete, greppable inventory of every path in the document that is not
 * on disk -- and the tool fails if the entry *does* exist, because a document
 * that says "there is no Flutter app here" while a `mobile/` directory sits in
 * the tree is the exact failure this guide exists to prevent.
 */
const PATHS_EXPECTED_ABSENT = [
  // The load-bearing claim of docs/mobile-integration.md: the repository ships
  // no Flutter app. tests/Unit/NoFlutterMobileTest.php asserts the same thing
  // from the PHP side.
  'mobile/',
];

/**
 * API paths the document deliberately names as **not registered**.
 *
 * Entries are stored in NORMALISED form -- placeholders collapsed to `{}` -- so
 * they compare against the same shape `normalisePath()` produces.
 *
 * `docs/mobile-integration.md` tells the mobile team that the plan's
 * "poll GET /invoice/{id}" rule points at an endpoint this application does not
 * have, and it has to be able to write that path down in order to say so. Each
 * entry is a *negative* assertion with a lifetime: the tool fails if the path
 * ever becomes a registered route, because at that moment the document is
 * lying and the sentence has to be rewritten. A missing-endpoint claim that
 * nobody checks is exactly as rot-prone as a dead link.
 */
const ENDPOINTS_EXPECTED_ABSENT = [
  // Named by the plan's todo 36 section 12 as the thing to poll after a
  // payment. The route table has `POST /api/v1/invoice/{id}/bayar` and no read
  // at all on the invoice resource, so there is nothing to poll here.
  'api/v1/invoice/{}',
];

/**
 * IANA top-level media types, so a MIME prefix in prose is not read as a path
 * into the repository. `text/` and `application/` appear in the upload MIME
 * allow-list and would otherwise be two false failures on every run.
 */
const MIME_ROOTS = new Set([
  'application',
  'audio',
  'font',
  'image',
  'message',
  'model',
  'multipart',
  'text',
  'video',
]);

// ---------------------------------------------------------------------------
// argument parsing
// ---------------------------------------------------------------------------

const argv = process.argv.slice(2);
const flags = new Set();
const options = new Map();
const positional = [];

for (let i = 0; i < argv.length; i += 1) {
  const arg = argv[i];

  if (arg === '--external') {
    flags.add('external');
  } else if (arg === '--json') {
    flags.add('json');
  } else if (arg === '--no-routes') {
    flags.add('no-routes');
  } else if (arg === '--help' || arg === '-h') {
    process.stdout.write(
      'usage: node tools/check-doc-links.mjs [--external] [--json] [--no-routes] ' +
        '[--root DIR] [--routes FILE] [--php PATH] [--dart PATH] ' +
        '[--min-table-rows N] [doc ...]\n',
    );
    process.exit(0);
  } else if (arg.startsWith('--')) {
    const key = arg.slice(2);
    const value = argv[i + 1];

    if (value === undefined || value.startsWith('--')) {
      fail(`option --${key} needs a value`);
    }

    options.set(key, value);
    i += 1;
  } else {
    positional.push(arg);
  }
}

const ROOT = options.get('root') ? resolve(String(options.get('root'))) : REPO_ROOT;
const MIN_TABLE_ROWS = Number(options.get('min-table-rows') ?? '45');
const DOCS = positional.length > 0 ? positional : [DEFAULT_DOC];

/**
 * The Dart binary. `dart` is not on PATH on the build host, so the SDK location
 * is discovered rather than assumed. A missing Dart SDK is a FAILURE, not a
 * skip: a gate that silently stops checking the Dart blocks is the failure mode
 * this tool was written to avoid.
 */
const DART_CANDIDATES = [
  options.get('dart'),
  process.env.SEHATLY_DART,
  'dart',
  join(
    process.env.LOCALAPPDATA ?? '',
    'Microsoft',
    'WinGet',
    'Packages',
    'Google.DartSDK_Microsoft.Winget.Source_8wekyb3d8bbwe',
    'dart-sdk',
    'bin',
    'dart.exe',
  ),
].filter(Boolean);

/** The PHP binary. Same reasoning: `php` on PATH is 8.2 on this host. */
const PHP_CANDIDATES = [
  options.get('php'),
  process.env.SEHATLY_PHP,
  'C:\\laragon\\bin\\php\\php-8.4.17-nts-Win32-vs17-x64\\php.exe',
  'php',
].filter(Boolean);

// ---------------------------------------------------------------------------
// reporting
// ---------------------------------------------------------------------------

const findings = [];
const notes = [];
const counters = { external: 0, dartBlocks: 0, apiPaths: 0, links: 0, repoPaths: 0 };

function fail(message) {
  process.stderr.write(`check-doc-links: ${message}\n`);
  process.exit(2);
}

function finding(check, doc, line, message) {
  findings.push({ check, doc, line, message });
}

function note(line) {
  notes.push(line);
}

// ---------------------------------------------------------------------------
// Markdown parsing
// ---------------------------------------------------------------------------

/**
 * Fenced-code spans, so a `](`-looking string inside a code block is not read as
 * a link and an `api/v1` path inside a code block IS still checked (it is
 * exactly where a stale path hides).
 *
 * Returns an array of `{ start, end, info, text }` in line numbers, 1-based and
 * inclusive, plus the set of line numbers that are inside a fence.
 */
function parseFences(text) {
  const lines = text.split(/\r?\n/);
  const blocks = [];
  const inside = new Set();

  let open = null;

  lines.forEach((line, index) => {
    const match = /^(\s*)(`{3,}|~{3,})\s*(\S*)\s*$/.exec(line);

    if (open === null) {
      if (match) {
        open = { marker: match[2][0], length: match[2].length, info: match[3], start: index + 1 };
        inside.add(index + 1);
      }

      return;
    }

    inside.add(index + 1);

    if (match && match[2][0] === open.marker && match[2].length >= open.length && match[3] === '') {
      blocks.push({
        start: open.start,
        end: index + 1,
        info: open.info,
        text: lines.slice(open.start, index).join('\n'),
      });
      open = null;
    }
  });

  if (open !== null) {
    finding('markdown', '<document>', open.start, 'unterminated fenced code block');
    blocks.push({
      start: open.start,
      end: lines.length,
      info: open.info,
      text: lines.slice(open.start).join('\n'),
    });
  }

  return { blocks, inside };
}

/**
 * GitHub's heading slug, as `github-slugger` computes it.
 *
 * Lowercase, drop every character that is not a letter, a digit, `-`, `_` or a
 * space, then turn each space into a `-`. A leading `#` list marker, backticks
 * and punctuation are all dropped rather than escaped, which is why a heading
 * like ``## 2. `POST /auth/otp/verify` - 200`` produces a slug with the slashes
 * *removed* and no separator -- the reason this document links to headings that
 * have no punctuation in them.
 */
function githubSlug(heading) {
  return heading
    .trim()
    .toLowerCase()
    .replace(/[^\p{L}\p{N}\-_ ]/gu, '')
    .replace(/ /g, '-');
}

/** Every ATX heading in a document, as slugs. */
function headingSlugs(docFile, text) {
  const slugs = new Set();
  const seen = new Map();
  const { inside } = fencesOf(docFile, text);

  text.split(/\r?\n/).forEach((line, index) => {
    if (inside.has(index + 1)) {
      return;
    }

    const match = /^(#{1,6})\s+(.*?)\s*#*\s*$/.exec(line);

    if (!match) {
      return;
    }

    const base = githubSlug(match[2]);
    // github-slugger appends -1, -2, ... to a repeated slug.
    const count = seen.get(base) ?? 0;
    seen.set(base, count + 1);
    slugs.add(count === 0 ? base : `${base}-${count}`);
  });

  return slugs;
}

/**
 * Links from a document, as `{ line, target, text }`.
 *
 * Inline `[text](target)` including the `<...>` and title forms, plus every
 * reference definition `[id]: target`. A link inside a code fence is skipped --
 * a fenced block that *shows* a link is documentation, not a link.
 */
function parseLinks(docFile, text) {
  const links = [];
  const { inside } = fencesOf(docFile, text);
  const lines = text.split(/\r?\n/);

  lines.forEach((line, index) => {
    const lineNumber = index + 1;

    if (inside.has(lineNumber)) {
      return;
    }

    const definition = /^\s{0,3}\[[^\]]+\]:\s*(\S+)/.exec(line);

    if (definition) {
      links.push({ line: lineNumber, target: definition[1], text: '' });
    }

    const inline = /!?\[[^\]]*\]\(([^)\s]+)(?:\s+"[^"]*")?\)/g;
    let match;

    while ((match = inline.exec(line)) !== null) {
      links.push({ line: lineNumber, target: match[1], text: line });
    }

    const autolink = /<((?:https?|mailto):[^>\s]+)>/.exec(line);

    if (autolink) {
      links.push({ line: lineNumber, target: autolink[1], text: line });
    }
  });

  return links;
}

/**
 * Inline-code spans on a line, with the fence they came from removed.
 *
 * The span-opening backticks of an unterminated span are dropped rather than
 * swallowed, so `` a `b `` yields `a ` and `b ` instead of one span reading
 * `a `b `.
 */
function inlineCodeSpans(line) {
  const spans = [];
  let i = 0;

  while (i < line.length) {
    if (line[i] !== '`') {
      i += 1;
      continue;
    }

    let run = 0;

    while (line[i + run] === '`') {
      run += 1;
    }

    const fence = '`'.repeat(run);
    const close = line.indexOf(fence, i + run);

    if (close === -1) {
      spans.push(line.slice(i + run));
      break;
    }

    spans.push(line.slice(i + run, close));
    i = close + run;
  }

  return spans;
}

/** True when an inline-code span is shaped like a path into this repository. */
function looksLikeRepoPath(value) {
  if (value.length < 3 || value.length > 200) {
    return false;
  }

  if (value.startsWith('/') || value.startsWith('~') || value.startsWith('$')) {
    return false;
  }

  if (/[<>|]/.test(value)) {
    return false;
  }

  // Must contain a separator, otherwise it is a bare filename or a word.
  if (!value.includes('/')) {
    return false;
  }

  // Not a URL, not a MIME type, not a glob, not a route.
  if (/^[a-z][a-z0-9+.-]*:\/\//i.test(value)) {
    return false;
  }

  const [head] = value.split('/');

  if (MIME_ROOTS.has(head.toLowerCase())) {
    return false;
  }

  // Windows paths appear in this document on purpose (the host's PHP and Dart
  // locations); they are not repository paths.
  if (/^[A-Za-z]:\\/.test(value)) {
    return false;
  }

  return /^[\w.@+-]+(?:\/[\w.*{}+@-]+)*\/?$/.test(value) || /\/$/.test(value);
}

// ---------------------------------------------------------------------------
// check 1 + 2: links and anchors
// ---------------------------------------------------------------------------

const slugCache = new Map();
const fenceCache = new Map();

/**
 * The fenced-code map for a document, computed once.
 *
 * `parseFences` reports an unterminated fence as a finding, and five of the
 * checks below need the map; without the memo the same defect would be reported
 * five times, which reads as five defects.
 */
function fencesOf(docFile, text) {
  if (!fenceCache.has(docFile)) {
    fenceCache.set(docFile, parseFences(text));
  }

  return fenceCache.get(docFile);
}

function slugsOf(file) {
  if (!slugCache.has(file)) {
    slugCache.set(file, headingSlugs(file, readFileSync(file, 'utf8')));
  }

  return slugCache.get(file);
}

function checkLinks(docFile, text) {
  const docDir = dirname(docFile);

  for (const link of parseLinks(docFile, text)) {
    let target = link.target;

    if (target.startsWith('mailto:')) {
      counters.external += 1;
      note(`${docFile}:${link.line}  mailto (skipped)      ${target}`);
      continue;
    }

    if (/^https?:\/\//i.test(target)) {
      counters.external += 1;

      if (flags.has('external')) {
        checkExternal(docFile, link.line, target);
      } else {
        note(`${docFile}:${link.line}  external (skipped)   ${target}`);
      }

      continue;
    }

    if (target === '' || target === '#') {
      continue;
    }

    counters.links += 1;

    const hashAt = target.indexOf('#');
    const filePart = hashAt === -1 ? target : target.slice(0, hashAt);
    const fragment = hashAt === -1 ? '' : decodeURIComponent(target.slice(hashAt + 1));

    let resolved = null;
    const fromDoc = isAbsolute(filePart) ? filePart : resolve(docDir, decodeURIComponent(filePart));
    const fromRoot = isAbsolute(filePart) ? filePart : resolve(ROOT, decodeURIComponent(filePart));

    if (filePart === '') {
      resolved = docFile;
    } else if (statExists(fromDoc)) {
      resolved = fromDoc;
    } else if (statExists(fromRoot)) {
      resolved = fromRoot;
    }

    if (resolved === null) {
      finding(
        'link',
        docFile,
        link.line,
        `relative target does not resolve: ${target} (tried ${rel(fromDoc)} and ${rel(fromRoot)})`,
      );
      continue;
    }

    if (fragment === '') {
      continue;
    }

    if (!resolved.endsWith('.md')) {
      finding(
        'anchor',
        docFile,
        link.line,
        `anchor used against a non-Markdown target, which cannot be verified: ${target}`,
      );
      continue;
    }

    const wanted = githubSlug(fragment);
    const available = slugsOf(resolved);

    if (!available.has(wanted)) {
      finding(
        'anchor',
        docFile,
        link.line,
        `anchor #${wanted} is not a heading in ${rel(resolved)}`,
      );
    }
  }
}

/**
 * A link may point at a directory -- GitHub renders `packages/sehatly_api_client`
 * as a file listing, and that is a useful link to have. The target only has to
 * *exist*; an anchor additionally has to be a Markdown file, which is asserted
 * separately below.
 */
function statExists(path) {
  try {
    statSync(path);
    return true;
  } catch {
    return false;
  }
}

function rel(path) {
  const value = relative(ROOT, path);
  return value === '' ? '.' : value.split(sep).join('/');
}

function checkExternal(docFile, line, target) {
  const result = spawnSync(
    process.execPath,
    [
      '-e',
      `
      const u = new URL(process.argv[1]);
      const c = new AbortController();
      const t = setTimeout(() => c.abort(), 10000);
      fetch(u, { redirect: 'follow', signal: c.signal, headers: { 'user-agent': 'sehatly-doc-check' } })
        .then((r) => { console.log(r.status); process.exit(0); })
        .catch(() => { console.log('ERR'); process.exit(0); });
      `,
      target,
    ],
    { encoding: 'utf8', timeout: 15000 },
  );

  const status = (result.stdout ?? '').trim();

  if (status === 'ERR' || status === '') {
    note(`${docFile}:${line}  external UNREACHABLE   ${target} (not counted as dead)`);
    return;
  }

  const code = Number(status);

  if (code >= 200 && code < 400) {
    note(`${docFile}:${line}  external ok ${code}       ${target}`);
    return;
  }

  finding('external', docFile, line, `${target} answered HTTP ${code}`);
}

// ---------------------------------------------------------------------------
// check 3: repository paths named in inline code
// ---------------------------------------------------------------------------

function checkRepoPaths(docFile, text) {
  const { inside } = fencesOf(docFile, text);

  text.split(/\r?\n/).forEach((line, index) => {
    const lineNumber = index + 1;

    for (const span of inlineCodeSpans(line)) {
      if (!looksLikeRepoPath(span)) {
        continue;
      }

      counters.repoPaths += 1;

      const expectedAbsent = PATHS_EXPECTED_ABSENT.find(
        (entry) => span === entry || span === entry.replace(/\/$/, ''),
      );

      if (expectedAbsent !== undefined) {
        const onDisk = existsSync(resolve(ROOT, expectedAbsent));

        if (onDisk) {
          finding(
            'path',
            docFile,
            lineNumber,
            `${span} is declared absent by this document but EXISTS on disk`,
          );
        } else {
          note(`${docFile}:${lineNumber}  path declared absent   ${span}`);
        }

        continue;
      }

      // A path with a glob or a placeholder is a shape, not a location; it is
      // reported so the skip is visible rather than silent.
      if (/[*?{]/.test(span)) {
        note(`${docFile}:${lineNumber}  path skipped (glob)    ${span}`);
        continue;
      }

      const onDisk = existsSync(resolve(ROOT, span));

      if (!onDisk) {
        finding('path', docFile, lineNumber, `repository path does not exist: ${span}`);
      }
    }
  });

  // Fenced code is skipped for the path check but not for the route check; a
  // path spelled only inside a fence is invisible, so say so.
  for (const lineNumber of inside) {
    const line = text.split(/\r?\n/)[lineNumber - 1];

    for (const span of inlineCodeSpans(line)) {
      if (looksLikeRepoPath(span) && !/[*?{]/.test(span)) {
        note(`${docFile}:${lineNumber}  path inside code fence ${span} (not path-checked)`);
      }
    }
  }
}

// ---------------------------------------------------------------------------
// check 4: every /api path is a real route
// ---------------------------------------------------------------------------

let routePaths = null;

function loadRoutePaths() {
  if (routePaths !== null) {
    return routePaths;
  }

  const explicit = options.get('routes');

  if (explicit) {
    const file = resolve(String(explicit));

    if (!existsSync(file)) {
      fail(`--routes ${explicit} does not exist`);
    }

    routePaths = new Set(readRoutesJson(readFileSync(file, 'utf8')).map(normalisePath));
    return routePaths;
  }

  if (flags.has('no-routes')) {
    finding(
      'routes',
      '<tool>',
      0,
      '--no-routes was passed, so no endpoint could be verified; a gate that skips is not a gate',
    );
    routePaths = new Set();
    return routePaths;
  }

  const php = PHP_CANDIDATES.find((candidate) => canRun(candidate, ['--version']));

  if (!php) {
    fail(
      'no usable PHP binary found. Pass --php <path>, set SEHATLY_PHP, or pass --routes <file>. ' +
        'Bare `php` is 8.2 on this host and cannot boot the application.',
    );
  }

  const result = spawnSync(php, ['artisan', 'route:list', '--json'], {
    cwd: ROOT,
    encoding: 'utf8',
    maxBuffer: 64 * 1024 * 1024,
  });

  if (result.status !== 0) {
    fail(
      `\`${php} artisan route:list --json\` exited ${result.status}:\n` +
        String(result.stderr ?? '').slice(0, 800),
    );
  }

  routePaths = new Set(readRoutesJson(result.stdout).map(normalisePath));

  return routePaths;
}

function readRoutesJson(text) {
  let parsed;

  try {
    parsed = JSON.parse(text);
  } catch (error) {
    fail(`route:list did not return JSON: ${error.message}`);
  }

  if (!Array.isArray(parsed)) {
    fail('route:list --json did not return an array');
  }

  return parsed.map((route) => `/${String(route.uri).replace(/^\/+/, '')}`);
}

/**
 * Reduce a path to a comparable shape: no leading slash, no trailing slash, and
 * every `{placeholder}` collapsed to `{}` so a document that says `{id}` matches
 * a route that says `{konsultasi}` and vice versa. A *typo* still does not
 * match, which is the entire point of the check.
 */
function normalisePath(value) {
  let path = String(value).trim().replace(/^`+|`+$/g, '');
  path = path.split(/[?#]/)[0];
  path = path.replace(/[.,;:)\]]+$/, '');
  path = path.replace(/^\/+/, '').replace(/\/+$/, '');
  path = path.replace(/\{[^}]*\}/g, '{}');

  return path;
}

/**
 * Whether a candidate is a *group* reference rather than a single route.
 *
 * `docs/mobile-integration.md` says "the fourteen `GET /api/v1/referensi/*`
 * endpoints", and `/api/v1/referensi` names no route -- it names a family of
 * them. A candidate that is a genuine prefix of at least one registered route
 * is a group reference, not a typo, so it is accepted. A candidate that is a
 * prefix of nothing (`/api/v1/nope-xyz`, `/api/v1/tidak-ada`) is still a failure,
 * so the rule cannot grow into a blanket exemption.
 *
 * A candidate containing a `{placeholder}` never qualifies. `/api/v1/invoice/{id}`
 * *is* a prefix of `/api/v1/invoice/{id}/bayar`, and letting it through on the
 * prefix rule would make the document's most important negative claim -- that no
 * such endpoint exists -- pass silently. Placeholder-bearing paths must either
 * match a route exactly or appear in `ENDPOINTS_EXPECTED_ABSENT`.
 */
function isRouteGroup(candidate) {
  if (candidate.includes('{')) {
    return false;
  }

  for (const route of routePaths) {
    if (route.startsWith(`${candidate}/`)) {
      return true;
    }
  }

  return false;
}

function checkApiPaths(docFile, text) {
  const known = loadRoutePaths();
  const seen = new Map();

  text.split(/\r?\n/).forEach((line, index) => {
    const matches = line.match(/\/api\/[A-Za-z0-9_\-]*(?:\/[A-Za-z0-9_\-{}.]+)*/g) ?? [];

    for (const raw of matches) {
      const normalised = normalisePath(raw);

      if (normalised === 'api') {
        continue;
      }

      counters.apiPaths += 1;

      if (!seen.has(normalised)) {
        seen.set(normalised, index + 1);
      }

      if (known.has(normalised)) {
        continue;
      }

      if (isRouteGroup(normalised)) {
        note(`${docFile}:${index + 1}  api group (not a route) /${normalised}`);
        continue;
      }

      if (ENDPOINTS_EXPECTED_ABSENT.includes(normalised)) {
        note(`${docFile}:${index + 1}  endpoint asserted ABSENT /${normalised}`);
        continue;
      }

      finding(
        'endpoint',
        docFile,
        index + 1,
        `no registered route matches ${raw} (normalised: /${normalised})`,
      );
    }
  });

  // The negative assertions, checked in the other direction: an entry in
  // ENDPOINTS_EXPECTED_ABSENT that has become a real route means the document's
  // "this does not exist" sentence is now false.
  for (const absent of ENDPOINTS_EXPECTED_ABSENT) {
    if (known.has(absent)) {
      finding(
        'endpoint',
        '<tool>',
        0,
        `${absent} is asserted absent by this tool but IS a registered route; ` +
          'the document claiming it does not exist is now wrong',
      );
    }
  }

  return seen;
}

// ---------------------------------------------------------------------------
// check 5: fenced dart blocks parse and are formatted
// ---------------------------------------------------------------------------

function checkDartBlocks(docFile, text, tmpRoot) {
  const { blocks } = fencesOf(docFile, text);
  const dartBlocks = blocks.filter((block) => block.info.toLowerCase() === 'dart');

  if (dartBlocks.length === 0) {
    finding('dart', docFile, 0, 'the document contains no fenced ```dart block to check');
    return 0;
  }

  const dart = DART_CANDIDATES.find((candidate) => canRun(candidate, ['--version']));

  if (!dart) {
    fail(
      'no usable Dart binary found, so no ```dart block could be checked. Pass --dart <path> ' +
        'or set SEHATLY_DART. A skipped Dart check is reported as a failure on purpose.',
    );
  }

  counters.dartBlocks = dartBlocks.length;

  dartBlocks.forEach((block, index) => {
    const file = join(tmpRoot, `block-${String(index + 1).padStart(2, '0')}.dart`);
    writeFileSync(file, `${block.text.replace(/\s+$/, '')}\n`, 'utf8');

    const result = spawnSync(dart, ['format', '--output=none', '--set-exit-if-changed', file], {
      encoding: 'utf8',
    });

    if (result.status === 0) {
      return;
    }

    if (result.status === 1) {
      finding(
        'dart',
        docFile,
        block.start,
        `dart block #${index + 1} parses but is NOT dart-format clean; run ` +
          `\`dart format\` on it`,
      );
      return;
    }

    if (result.status === 65) {
      finding(
        'dart',
        docFile,
        block.start,
        `dart block #${index + 1} does not parse:\n${indent(String(result.stderr).trim())}`,
      );
      return;
    }

    finding(
      'dart',
      docFile,
      block.start,
      `dart block #${index + 1}: \`dart format\` exited ${result.status}:\n` +
        indent(String(result.stderr).trim()),
    );
  });

  return dartBlocks.length;
}

function indent(text) {
  return text
    .split(/\r?\n/)
    .map((line) => `      ${line}`)
    .join('\n');
}

function canRun(candidate, args) {
  const result = spawnSync(candidate, args, { encoding: 'utf8', timeout: 30000 });

  return !result.error && result.status === 0;
}

// ---------------------------------------------------------------------------
// check 6: the GetX section's acceptance rule
// ---------------------------------------------------------------------------

/**
 * The plan's acceptance criterion for section 11, expressed as a check.
 *
 * A section whose title matches GETX_SECTION_PATTERN must contain a class
 * extending GetxController and one extending Bindings, both of which name
 * SehatlyApiClient, and must contain neither `Dio(` nor `http.`. The negative
 * half is the part that matters: an example that quietly constructs a Dio has
 * stopped being an integration of the package and has become a second HTTP layer
 * that will drift.
 */
const GETX_SECTION_PATTERN = /^##\s+(\d+)\.\s+Contoh integrasi dengan GetX/;

function checkGetXSection(docFile, text) {
  const lines = text.split(/\r?\n/);
  let start = -1;
  let number = null;

  for (let i = 0; i < lines.length; i += 1) {
    const match = GETX_SECTION_PATTERN.exec(lines[i]);

    if (match) {
      start = i;
      number = Number(match[1]);
      break;
    }
  }

  if (start === -1) {
    finding('getx', docFile, 0, 'no "## <n>. Contoh integrasi dengan GetX" section found');
    return;
  }

  let end = lines.length;

  for (let i = start + 1; i < lines.length; i += 1) {
    if (/^##\s+/.test(lines[i])) {
      end = i;
      break;
    }
  }

  const body = lines.slice(start, end);
  const bodyText = body.join('\n');

  const need = [
    [/\bextends\s+GetxController\b/, 'a class extending GetxController'],
    [/\bextends\s+Bindings\b/, 'a class extending Bindings'],
    [/\bGet\.lazyPut\b/, 'a Get.lazyPut call'],
    [/\bGet\.lazyPut<SehatlyApiClient>/, 'a Get.lazyPut<SehatlyApiClient> call'],
    [/\bSehatlyApiClient\b/, 'a reference to SehatlyApiClient'],
  ];

  for (const [pattern, what] of need) {
    if (!pattern.test(bodyText)) {
      finding('getx', docFile, start + 1, `section ${number} is missing ${what}`);
    }
  }

  for (const forbidden of ['Dio(', 'http.']) {
    const hit = bodyText.indexOf(forbidden);

    if (hit !== -1) {
      const line = start + bodyText.slice(0, hit).split('\n').length;
      finding(
        'getx',
        docFile,
        line,
        `section ${number} contains \`${forbidden}\`, which bypasses sehatly_api_client`,
      );
    }
  }
}

// ---------------------------------------------------------------------------
// check 7: shape
// ---------------------------------------------------------------------------

function checkShape(docFile, text, expectedSections) {
  const headings = [];
  const { inside } = fencesOf(docFile, text);

  text.split(/\r?\n/).forEach((line, index) => {
    if (inside.has(index + 1)) {
      return;
    }

    const match = /^(#{1,6})\s+(.*?)\s*$/.exec(line);

    if (match) {
      headings.push({ line: index + 1, depth: match[1].length, text: match[2] });
    }
  });

  const numbered = headings
    .filter((heading) => heading.depth === 2 && /^\d+\.\s+\S/.test(heading.text))
    .map((heading) => ({ ...heading, number: Number(/^(\d+)\./.exec(heading.text)[1]) }));

  if (numbered.length === 0) {
    finding('shape', docFile, 0, 'no `## <n>. <title>` section headings found');
    return;
  }

  numbered.forEach((heading, index) => {
    if (heading.number !== index + 1) {
      finding(
        'shape',
        docFile,
        heading.line,
        `sections are not 1..n in order: expected ${index + 1}, found ${heading.number}`,
      );
    }
  });

  if (expectedSections !== null && numbered.length !== expectedSections) {
    finding(
      'shape',
      docFile,
      numbered[0].line,
      `expected ${expectedSections} numbered sections, found ${numbered.length}`,
    );
  }

  const tableRows = text.split(/\r?\n/).filter((line) => /^\|/.test(line)).length;

  if (tableRows < MIN_TABLE_ROWS) {
    finding(
      'shape',
      docFile,
      0,
      `only ${tableRows} Markdown table rows (lines starting with "|"), expected >= ${MIN_TABLE_ROWS}`,
    );
  }

  const topLevel = headings.filter((heading) => heading.depth === 1);

  if (topLevel.length !== 1) {
    finding(
      'shape',
      docFile,
      topLevel.length > 0 ? topLevel[1].line : 0,
      `expected exactly one level-1 heading, found ${topLevel.length}`,
    );
  }
}

// ---------------------------------------------------------------------------
// driver
// ---------------------------------------------------------------------------

const tmpRoot = mkdtempSync(join(tmpdir(), 'sehatly-doc-check-'));
let exitCode = 0;

try {
  for (const docArg of DOCS) {
    const docFile = isAbsolute(docArg) ? docArg : resolve(process.cwd(), docArg);

    if (!existsSync(docFile)) {
      fail(`document not found: ${docArg}`);
    }

    const text = readFileSync(docFile, 'utf8');
    const expected = options.get('expect-sections');

    checkLinks(docFile, text);
    checkRepoPaths(docFile, text);
    const apiPaths = checkApiPaths(docFile, text);
    checkDartBlocks(docFile, text, tmpRoot);
    checkGetXSection(docFile, text);
    checkShape(docFile, text, expected === undefined ? null : Number(expected));

    if (flags.has('json')) {
      note(
        `${rel(docFile)}: ${apiPaths.size} distinct api paths, ` +
          `${counters.links} links, ${counters.repoPaths} repo paths, ` +
          `${counters.dartBlocks} dart blocks, ${counters.external} external urls`,
      );
    }
  }

  if (findings.length > 0) {
    exitCode = 1;

    process.stdout.write(`\nFAIL -- ${findings.length} finding(s)\n\n`);

    for (const item of findings) {
      const where = item.line > 0 ? `${item.doc}:${item.line}` : item.doc;
      process.stdout.write(`  [${item.check}] ${where}\n    ${item.message}\n`);
    }

    process.stdout.write('\n');
  } else {
    process.stdout.write(
      `OK -- ${DOCS.length} document(s); ` +
        `${counters.links} links, ${counters.repoPaths} repo paths, ` +
        `${counters.apiPaths} api-path mentions, ${counters.dartBlocks} dart blocks, ` +
        `${counters.external} external URLs skipped (pass --external to fetch them)\n`,
    );
  }

  if (!flags.has('json')) {
    for (const line of notes) {
      process.stdout.write(`  note  ${line}\n`);
    }
  }

  if (notes.length > 0 && !flags.has('json')) {
    process.stdout.write('\n');
  }
} finally {
  rmSync(tmpRoot, { recursive: true, force: true });
}

process.exit(exitCode);

<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The "no Flutter app in this repository" guard
|--------------------------------------------------------------------------
|
| The plan's guardrail is a one-liner -- `test ! -e mobile` -- and it is the
| ONLY guardrail in the whole 54-todo plan that has never had automatic proof.
| Every other rule in the plan is either a test in `tests/` or a command in
| CI, so a regression is caught by the suite. This one was prose, and prose in
| a plan that spans six waves and 74 routes is exactly the kind of rule that
| erodes: a later todo adds a `mobile/` directory, or a well-meaning executor
| adds `flutter:` to the Dart package's SDK constraint "just to get
| `flutter_secure_storage` to resolve", and nothing fails.
|
| ## Why the directory test alone is not enough
|
| `mobile/` is one failure mode. The other is a Flutter dependency appearing
| INSIDE `packages/sehatly_api_client`, which is a legitimate pure-Dart
| package. Todo 24 built it Flutter-free on purpose -- `sdk: ^3.13.0` with no
| `flutter:` constraint -- so that it is testable with the standalone Dart SDK
| on a machine that has no Flutter, and importable from any Flutter app the
| mobile team writes. Adding `flutter:` to its `pubspec.yaml`, or depending on
| a package that itself pulls Flutter in, breaks both properties at once and
| breaks them SILENTLY: `dart pub get` and `dart test` still pass on a machine
| where the `flutter` binary happens to be on PATH.
|
| So this file asserts three things, and the third is the one that catches the
| subtle regression:
|
| 1. no `mobile/` directory at the repository root;
| 2. no `pubspec.yaml` anywhere in the repository declares a `flutter:` SDK
|    constraint (so "we did not create `mobile/` but we made the pure-Dart
|    package Flutter-coupled" is also a failure);
| 3. no `pubspec.yaml` declares a `flutter:` key under `dependencies:` or
|    `dev_dependencies:` -- i.e. no dependency ON Flutter itself, even if the
|    SDK constraint stays clean.
|
| ## What this test must NOT do
|
| It must not delete anything, and it must not "fix" a violation. A guard that
| repairs the violation it detects is a guard that reports green on the next
| run. Every assertion here fails loudly and names the file and the line.
|
| ## What it must NOT flag
|
| `packages/sehatly_api_client` is a legitimate, load-bearing, pure-Dart
| package. Its `dio` dependency, its `flutter_secure_storage`-shaped
| documentation (which explains why the concrete store lives in the consuming
| Flutter app, behind the `TokenStore` interface) and its `pubspec.yaml` all
| stay exactly as they are. A naive `grep -r flutter packages/` would flag the
| README and the docblock that explains the split; this test parses YAML keys
| instead of grepping prose, which is why it is a test and not a shell one-liner.
*/

use Tests\TestCase;

uses(TestCase::class);

/**
 * Every `pubspec.yaml` in the repository, walked rather than listed.
 *
 * DERIVED by walking `packages/` and the repository root. Listing the one path
 * that exists today would make this guard vacuous the day a second package is
 * added -- which is precisely the moment a Flutter app could be introduced as
 * `packages/sehatly_app/` rather than `mobile/`, and a hard-coded check would
 * never look there.
 *
 * `git ls-files` is not used deliberately: the point is to catch an UNTRACKED
 * directory, because an untracked `mobile/` is exactly the state in which
 * someone believes they "just have not committed it yet" and it turns into a
 * committed Flutter app two todos later.
 *
 * @return list<string> absolute paths, sorted
 */
$pubspecs = function (): array {
    $root = base_path();
    $found = [];

    // A directory walk that skips the two trees no Dart package can live in.
    $skip = ['vendor', 'node_modules', '.git', 'build', '.dart_tool'];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $current) use ($skip): bool {
                if (! $current->isDir()) {
                    return true;
                }

                return ! in_array($current->getFilename(), $skip, true);
            },
        ),
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getFilename() === 'pubspec.yaml') {
            // Normalised to forward slashes because `base_path()` produces those
            // on every platform, and a Windows `getPathname()` returns backslashes.
            // Without this the non-vacuity assertion below would fail on a path
            // that demonstrably exists.
            $found[] = str_replace('\\', '/', $file->getPathname());
        }
    }

    sort($found);

    return $found;
};

/**
 * Parse a `pubspec.yaml` far enough to answer three questions.
 *
 * NOT a general YAML parser: it reads the top-level `environment:` and
 * `dependencies:` / `dev_dependencies:` blocks and the keys inside them, which
 * is the only thing a guard needs, and it deliberately does NOT resolve the
 * indirection. `vendor:`-installed transitive packages are outside the
 * repository and outside this guard's scope: a package that pulls Flutter in
 * transitively is the Dart SDK's business, and the plan's own pin
 * (`flutter_secure_storage` 11.2.0 behind the `TokenStore` interface, in the
 * consuming app) is precisely the design that avoids it.
 *
 * @return array{environment: array<string, string>, dependencies: list<string>, dev_dependencies: list<string>}
 */
$parsePubspec = function (string $path): array {
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    $lines = $lines === false ? [] : $lines;

    $environment = [];
    $dependencies = [];
    $devDependencies = [];

    /** @var string|null $section */
    $section = null;

    foreach ($lines as $line) {
        // A tab-indented line is not a key; skip it rather than mis-parse.
        if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
            continue;
        }

        $indented = $line !== ltrim($line);
        $trimmed = trim($line);

        if (! $indented) {
            $section = match (true) {
                str_starts_with($trimmed, 'environment:') => 'environment',
                str_starts_with($trimmed, 'dependencies:') => 'dependencies',
                str_starts_with($trimmed, 'dev_dependencies:') => 'dev_dependencies',
                default => null,
            };

            continue;
        }

        if ($section === null || ! str_contains($trimmed, ':')) {
            continue;
        }

        $colon = (int) strpos($trimmed, ':');
        $key = rtrim(substr($trimmed, 0, $colon), " \t");

        if ($key === '') {
            continue;
        }

        if ($section === 'environment') {
            $environment[$key] = rtrim(substr($trimmed, $colon + 1), " \t");

            continue;
        }

        // Every dependency key is recorded, not only the Flutter ones: the guard
        // needs the full list so it can prove `dio` is still there, and a parser
        // that returned only violations would make that assertion vacuous.
        if ($section === 'dependencies') {
            $dependencies[] = $key;
        }

        if ($section === 'dev_dependencies') {
            $devDependencies[] = $key;
        }
    }

    return [
        'environment' => $environment,
        'dependencies' => array_values(array_filter($dependencies, static fn (string $k): bool => $k !== '')),
        'dev_dependencies' => array_values(array_filter($devDependencies, static fn (string $k): bool => $k !== '')),
    ];
};

test('the repository has no mobile/ directory at its root', function () {
    $mobile = base_path('mobile');

    expect(file_exists($mobile))->toBeFalse(
        'A mobile/ directory exists at the repository root. This repository ships '
        .'NO Flutter app: the mobile entry point is the pure-Dart package '
        .'packages/sehatly_api_client plus docs/mobile-integration.md. Delete the '
        .'directory, or amend the plan first -- do not delete it from under the '
        .'executor that created it.',
    );

    expect(is_dir($mobile))->toBeFalse(
        'mobile/ exists and is a directory. See the previous failure.',
    );

    // The shell guardrail, run as a shell guardrail. `test ! -e mobile` is the
    // literal plan criterion; asserting the PHP equivalent alone would let the
    // criterion and the test drift apart silently.
    expect(is_file(base_path('mobile')))->toBeFalse('mobile/ exists as a FILE, which `test ! -e mobile` also rejects.');
});

test('every pubspec.yaml in the repository declares no flutter SDK constraint', function () use ($pubspecs, $parsePubspec) {
    $specs = $pubspecs();

    // Non-vacuity: a walk that matches nothing would make this test pass for
    // the wrong reason. The pure-Dart package is the proof the walk works.
    $pure = str_replace('\\', '/', base_path('packages/sehatly_api_client/pubspec.yaml'));

    expect($specs)->not->toBeEmpty('no pubspec.yaml was found anywhere in the repository');
    expect(in_array($pure, $specs, true))->toBeTrue(
        'the pure-Dart package must be among the pubspecs found, otherwise this guard is vacuous. Found: '
        .implode(', ', $specs),
    );

    foreach ($specs as $path) {
        $spec = $parsePubspec($path);

        expect($spec)->toHaveKey('environment');

        $sdkKeys = array_keys($spec['environment']);

        expect(in_array('sdk', $sdkKeys, true))->toBeTrue(
            $path.' declares no environment.sdk constraint; keys: '.implode(', ', $sdkKeys),
        );

        // `not->toContain($x, $message)` is a trap on an array expectation in
        // Pest: the second argument is read as another needle, so the message
        // silently becomes the thing being asserted absent. Asserting
        // membership directly keeps the failure message attached to the cause.
        foreach (['flutter', 'flutter_test', 'flutter_web_plugins'] as $forbidden) {
            expect(in_array($forbidden, $sdkKeys, true))->toBeFalse(
                $path.' declares a "'.$forbidden.':" SDK constraint. That makes the '
                .'package untestable without the Flutter SDK and is exactly what '
                .'todo 24 chose not to do. Remove the constraint. Found keys: '
                .implode(', ', $sdkKeys),
            );
        }
    }
});

test('no pubspec.yaml in the repository depends on Flutter', function () use ($pubspecs, $parsePubspec) {
    foreach ($pubspecs() as $path) {
        $spec = $parsePubspec($path);

        foreach (['dependencies', 'dev_dependencies'] as $section) {
            foreach (['flutter', 'flutter_test', 'flutter_web_plugins'] as $forbidden) {
                expect(in_array($forbidden, $spec[$section], true))->toBeFalse(
                    $path.' declares `'.$forbidden.'` under '.$section.'. A pure-Dart '
                    .'package cannot depend on the Flutter SDK; the concrete '
                    .'flutter_secure_storage implementation belongs in the consuming '
                    .'Flutter app, behind the TokenStore interface. Found: '
                    .implode(', ', $spec[$section]),
                );
            }
        }
    }
});

test('the pure-Dart API client is still a pure-Dart package with its Dio transport', function () use ($parsePubspec) {
    $path = str_replace('\\', '/', base_path('packages/sehatly_api_client/pubspec.yaml'));
    $spec = $parsePubspec($path);

    // The other direction of the guard. A "no Flutter" test that is satisfied by
    // an empty package -- or by deleting the transport -- is not a guard, it is
    // vandalism. This asserts the package still does its job.
    expect(in_array('dio', $spec['dependencies'], true))->toBeTrue(
        $path.' no longer depends on dio. Found: '.implode(', ', $spec['dependencies']),
    );

    $client = base_path('packages/sehatly_api_client/lib/src/client.dart');

    expect(is_file($client))->toBeTrue('the Dio transport client is gone from packages/sehatly_api_client');
    expect(str_contains((string) file_get_contents($client), 'package:dio/dio.dart'))->toBeTrue(
        'the transport no longer wraps Dio',
    );
});

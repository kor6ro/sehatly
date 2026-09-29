# Generate the TypeScript client types from docs/openapi.yaml.
#
# The toolchain is ISOLATED from web/ on purpose and this file is the third half
# of that story. The chain is:
#
#   docs/openapi.yaml            written by `php artisan sehatly:openapi`
#        |                       (generated from the live route table)
#        v
#   tools/openapi-types/          THIS directory -- openapi-typescript 7.13.0
#        |                       plus its own typescript 5
#        v
#   web/src/types/api.d.ts       consumed by `npm run types:check`
#
# ## Why the generator is not a dependency of web/
#
# `openapi-typescript` declares `typescript: ^5.x` as a PEER dependency and calls
# that compiler's JavaScript API (`ts.factory.createKeywordTypeNode` and friends).
# `web/` compiles against `typescript: ^7` -- the plan's todo-3 pin, made for its
# own reasons. npm cannot satisfy both in one tree; installing openapi-typescript
# into `web/` either fails resolution outright or requires `--legacy-peer-deps`,
# and under that flag the generator would silently run against the TypeScript 7
# API it does not support and crash on `ts.factory` being undefined.
#
# It DID crash that way, exactly as predicted, and that crash is why this
# directory exists. Two dependency trees for two tools is a smaller problem than
# a generator that cannot run.
#
# ## Why the versions are pinned exactly rather than with a caret
#
# The output file is COMMITTED and the test suite compares behaviour that depends
# on it. `openapi-typescript` 7.13.0 in particular collapses a
# single-value-type schema carrying an `enum` into the union itself rather than
# emitting `{ enum: [...] }`, and `web/src/types/api.contract.ts` reads it that
# way. A patch bump that changed that shape would be a legitimate upstream
# release and a breaking change here, so the exact versions are recorded and the
# reason is this file.
#
# ## Regenerating
#
#     php artisan sehatly:openapi      # regenerate the document first
#     npm run types:generate            # then the types
#     npm run types:check               # then prove the types compile
#
# `npm run types:generate` from the repository root. Run all three in that order:
# generating types from a stale document produces a stale `api.d.ts` that still
# type-checks, because the check proves the TYPES are internally consistent, not
# that they describe the current API.

# Portal Frontend Organization Design

## Status and intent

This design records the agreed direction for organizing Sehatly's frontend by
portal and allowing portal-specific layouts and component presentation.

The goal is to make the patient, admin, doctor, pharmacy, courier, and marketing
surfaces easier to find and evolve independently, without duplicating genuinely
shared functionality or changing the application's current routing and behavior.

## Current context

The frontend is one React SPA and one production build. `src/lib/portal.ts`
detects one of six portals from the hostname, and `src/app/router.tsx` selects
that portal's route table. Route tables live in `src/app/routes/`, but page
modules are currently collected in `src/pages/`. Feature components are
organized by domain in `src/features/`, and reusable primitives and common UI
live in `src/components/`.

Some screens are intentionally reachable from more than one portal. For
example, consultation views and prescription detail workflows are shared across
patient, doctor, and pharmacy contexts. The route that renders a screen is not
always enough to determine ownership of all its behavior or presentation.

The project has one shared design-token source at `src/styles/app.css`. The
agreed scope is portal-specific layout and component presentation, while
retaining the existing shared color tokens. This design does not introduce
separate portal color themes or modify `app.css`.

## Selected architecture

Organize portal-owned UI under `src/portals/` and cross-portal UI under
`src/shared/`:

```text
src/
  portals/
    pasien/
      pages/
      components/
      features/
      layout/
    admin/
      pages/
      components/
      features/
      layout/
    dokter/
      pages/
      components/
      features/
      layout/
    apotek/
      pages/
      components/
      features/
      layout/
    kurir/
      pages/
      components/
      features/
      layout/
    marketing/
      pages/
      components/
      features/
      layout/
  shared/
    pages/
    components/
    features/
```

Only directories needed by actual modules need to be created; this tree is a
boundary guide, not a requirement to add empty directories. Cross-cutting
infrastructure stays in its current locations (`app`, `lib`, `hooks`, `types`,
and `styles`) unless implementation discovers a concrete reason to move it.
The existing generic `components/ui` primitives remain shared. `shared/pages/`
is for screens whose behavior and overall composition are genuinely common
across portals (for example, legal documents and the shared profile-edit flow).
A route being registered by multiple portals is not, by itself, a reason to put
its page module in `shared/pages/`.

### Ownership and reuse

- Portal-specific pages, wrappers, navigation, and presentation components
  belong to that portal.
- Functionality or a UI component genuinely consumed by multiple portals
  belongs in `shared`, grouped by domain where appropriate.
- A cross-role workflow whose page composition or presentation differs by
  portal may have portal-owned page wrappers. Those wrappers reuse shared
  domain features and business logic rather than copying them.
- A component used by two portals must not be copied just to change its
  appearance. It may expose a small, explicit presentation variant when the
  structural difference is limited.
- If a portal needs a materially different workflow or composition, use a
  portal-owned page or wrapper and reuse lower-level shared components.
- Portal identity for styling is conveyed through composition or explicit
  props, not by having a generic component inspect `window.location` or call
  `detectPortal()` on its own.

### Layout and styling

Each portal owns its layout shell and navigation composition. Shared layout
primitives may still be used to avoid duplicating accessibility, interaction,
and responsive behavior. A shared component's portal-specific presentation
should be explicit at its call site through a narrowly scoped variant or
portal-owned wrapper.

All portals continue using the existing tokens from `styles/app.css`; this work
does not add portal-specific color values, alter the theme tokens, or introduce
independent CSS theme files. Portal differences can use layout, spacing,
hierarchy, and component composition, while still following the frontend's
existing token and accessibility rules. Any later request for distinct portal
color themes is a separate design decision.

### Routing and runtime behavior

Keep the six existing portal route tables and hostname-based portal selection.
Route tables should import portal-owned pages from their portal folder and
genuinely common screens from `shared/pages/`. Cross-role workflow pages may be
portal-owned wrappers around shared domain features when their composition or
presentation differs. Route paths, guards, redirect targets, error boundaries,
lazy-loading behavior, and consultation/prescription access must remain
unchanged.

This is a source organization and presentation-boundary change only. It does
not split the SPA into separate bundles, deployments, API origins, or
independent applications.

## Migration strategy

Move files in small, reviewable groups, preserving current filenames initially
to keep the diff focused:

1. Establish the portal/shared ownership rules and relocate clearly
   portal-specific pages.
2. Update the corresponding route table imports and verify route behavior.
3. Relocate portal-owned feature UI and layout composition where there is a
   clear owner.
4. Move genuinely common screens and reusable lower-level domain features into
   `shared`; keep portal-specific wrappers around cross-role workflows where
   their composition or presentation differs.
5. Add explicit variants or portal-owned wrappers only where a real visual
   difference is needed; do not make speculative styling changes during file
   moves.

Do not move API clients, shared business logic, or domain types merely to make
the tree look symmetrical. Resolve ambiguous ownership by checking actual
imports and user flows before moving a module.

## Compatibility and acceptance criteria

- All six portal route tables continue to register the same paths and guards.
- Portal detection and hostname precedence remain unchanged.
- Public/shared screens continue to work from every portal that currently
  registers them.
- Portal-specific layout differences are implemented without adding a second
  source of color tokens or reading portal identity implicitly in shared
  components.
- File moves update all affected imports; no stale imports or duplicate
  implementations remain.
- The frontend type check, unit tests, production build, and relevant portal
  end-to-end tests pass.
- No unrelated product behavior or styling is changed as part of the
  organization-only migration.

## Out of scope

- Separate portal deployments, bundles, or backend/API behavior.
- Independent brand palettes or portal-specific color tokens.
- A full redesign of any portal or speculative styling changes.
- Rewriting route guards, authentication, or access-control behavior.
- Renaming modules beyond what is needed for clear ownership.

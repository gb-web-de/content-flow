# Working Agenda

How work on this extension gets done — commits, code style, and the lessons this
project already paid for once. Written down so they are not paid for twice.

## Git commits

- **New commit, never amend**, unless explicitly asked. If a hook fails, fix and
  commit again.
- **One logical change per commit.** When a single turn covers several unrelated
  requests, split into one commit per request rather than one commit that touches
  everything — see `7caca88` (two unrelated bugs, one commit each) vs. `a42dca3`
  (three requests, documented as three numbered sections in one body because they
  touched overlapping files).
- **The body explains why, not what** — the diff already shows what. State:
  - the symptom or request that triggered the change,
  - what was tried and rejected, and why (a rejected approach is worth recording
    so nobody re-proposes it — see the Wizard-API investigation in
    `ARCHITECTURE.md` and its commit),
  - how it was verified (test names, "confirmed by reverting the fix and watching
    the test fail", core source lines read).
- **Correct mistakes out loud.** `f0343ac` corrected an earlier design's claim
  that publishing loses the `sys_history` trail — the commit message states
  plainly what was wrong, why, and what the actual constraint turned out to be
  (30-day garbage collection, not publishing). Don't quietly rewrite — the next
  reader should be able to see that the wrong conclusion was found and fixed,
  not just that the code changed again.
- **Never commit generated instance state.** `config/system/settings.php` carries
  the encryption key; see `.gitignore` and the commit that added it after this
  was nearly shipped once.

## Code style: measured, not asserted

When told to apply a style guide, measure the code against it mechanically first
— grep for missing `final`, count method lengths, count parameters, count
repeated string literals — then fix what the measurement finds. "I read it and
it looks clean" is not a review; see the `a377d00` commit for the concrete
numbers this produced (8 non-final classes, a 93-line method, 6 bare table-name
literals in a controller).

Rules actually enforced here, from `skills/typo3-cgl-testing` and the
[Clean Code summary](https://gist.github.com/wojteklu/73c6914cc446146b8b533c0988cf8d29):

- `declare(strict_types=1)` in every file.
- `final class` unless something is genuinely designed to be extended — check
  first (`grep -r "extends X"`) rather than assuming.
- Constructor property promotion, `readonly` properties.
- Small functions that do one thing. A validate-then-act method in the
  40–50 line range is usually fine; once it's doing three distinct jobs (build a
  command, run it, mirror the result), split it — see `askCoreToSetStage()` /
  `recordStageChange()` in `TaskAjaxController`.
- Replace magic numbers/strings with named constructs. `TaskPriority` is an enum
  specifically because `max(1, min(3, $priority))` had three unexplained numbers
  in it.
- No needless repetition. A write pattern used in two places (the comment
  insert + counter update) belongs in one repository method, not two copies.
  When a class stops needing a dependency because everything using it moved out
  (PHPStan will say so — "property is never read, only written"), remove the
  dependency too.
- Comments explain **why**, not what the next line already says. Every
  non-obvious decision in this codebase has a comment naming the alternative
  that was rejected and why — that habit is deliberate, keep it.
- Errors are named for developers, worded for editors: a stable `code`, a
  specific `message`, logged with context server-side, never a bare "an error
  occurred". See `TaskActionError` / `TaskAjaxController::error()`.

## TYPO3-specific: verify before you build

The single most expensive lesson of this project. Confirmed wrong assumptions,
each caught only by reading core source or by running the code:

- `DataHandler` rewrites `$id` to the **version** uid before calling
  `processDatamap_afterDatabaseOperations` — a hook that assumes `$id` is live
  silently does nothing, forever, with no error.
- `WorkspaceRepository::findByUid()` **throws** on a missing workspace; it does
  not return `null`.
- Publishing does **not** lose the `sys_history` trail (core migrates it to the
  live uid) — but `sys_history` itself is garbage-collected after 30 days by
  default. The real constraint was one core source file, not the one first
  assumed.
- `Configuration/JavaScriptModules.php` has exactly two meaningful keys
  (`imports`, `dependencies`). An `includeInModules` key looked like
  configuration and did nothing — nothing in `ImportMap::loadConfiguration()`
  reads it. There is no config-driven "always load this module"; the sanctioned
  mechanism is an `AfterBackendPageRenderEvent` listener (copy EXT:workspaces'
  own `AfterBackendPageRenderEventListener` — it does the same thing for
  `workspace-state.js`).
- `BackendUserAuthentication::recordEditAccessInternals()` is deprecated since
  v14; its replacement `checkRecordEditAccess()` is `@internal` but not
  deprecated. Prefer the non-deprecated internal method over the deprecated
  public-looking one when the extension targets a single major version — check
  `composer.json`'s core constraint before deciding which risk is smaller.
- New-looking core APIs can be `@internal` even when they look like the obvious
  extension point (`TYPO3\CMS\Backend\Wizard\WizardProviderInterface` — built
  for core's own page-creation dialog, not a general wizard framework). Grep the
  class docblock before adopting anything unfamiliar.

**The rule this produces:** before relying on a return type, a nullability
contract, an event name, a deprecation, or "this must load automatically because
I configured it" — grep the actual core source in `.Build/vendor/typo3/`. A
plausible-sounding API is not a verified one.

## Tests: prove they catch what they claim to

A green suite is not proof. Twice in this project a test passed against
deliberately-reintroduced buggy code, for reasons that had nothing to do with
the fix being tested (page aggregation front-loaded a membership claim the test
meant to catch as missing; a template assertion matched an always-rendered
container instead of the specific element it meant to check).

**Standing practice: after writing a regression test, temporarily reintroduce
the bug and confirm the test goes red before considering the test trustworthy.**
Then revert. This is not optional polish — every "fixed and verified" claim in
this project's commit history is backed by exactly this cycle, and the two
counterexamples above are what happens when it's skipped.

Other testing conventions in force:

- Functional tests drive real `DataHandler`/`BackendUserAuthentication` calls,
  not simulations of their effects — `setWorkspace()`, not `->workspace = `.
- One test, several assertions, is fine when they check facets of one behaviour.
  One test asserting several *different* behaviours is not.
- A class only privately injected anywhere (never a public service) is
  constructed directly in the test (`new CommentRepository($this->get(ConnectionPool::class))`)
  rather than made public just to satisfy the test — production wiring doesn't
  bend to accommodate a test.
- Render every Fluid template at least once in a test. A parse error in an
  unrendered template leaves the whole suite green while the module is
  unopenable — this happened once, see `a4938c1`.

## Before calling anything done

1. `ddev exec .Build/bin/phpunit -c Build/phpunit/FunctionalTests.xml`
2. `ddev exec .Build/bin/phpunit -c Build/phpunit/UnitTests.xml`
3. `ddev exec .Build/bin/phpstan analyse -c Build/phpstan/phpstan.neon --no-progress`
4. `ddev exec .Build/bin/php-cs-fixer fix --config Build/php-cs-fixer/php-cs-rules.php`
5. If the change touches anything rendered or triggered by user action, actually
   exercise it (render the template, run the DataHandler call, hit the ajax
   route) — reading the code is not running it.

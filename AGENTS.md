# AGENTS.md

Working agreement for anyone (human or agent) changing this repository. It records rules and the reasons behind them;
the reasons matter more than the rules, because most of them were paid for with a production bug or a lost hour.
When a rule and a situation disagree, follow the reason, then fix the rule.

## 1. What this repository is

A **Composer monorepo of three Magento 2 modules** that make a store observable with OpenTelemetry:

| Package | Module | Role |
|---|---|---|
| `packages/tracing` (`bode/module-tracing`) | `Bode_Tracing` | Traces and the **shared contracts** (`Bode\Tracing\Api`, `Environment`, `Privacy`, `RouteNormalizer`, trace-context provider) |
| `packages/metrics` (`bode/module-metrics`) | `Bode_Metrics` | Metrics with a hard low-cardinality contract |
| `packages/logs` (`bode/module-logs`) | `Bode_Logs` | Every log record and exception as one OTel-shaped JSON line |

- **Dependency direction is one way:** `metrics` and `logs` depend on `tracing` (`"bode/module-tracing": "self.version"`), never
  the reverse and never on each other. Anything two packages need goes into `tracing\Api` as an interface.
- **Lockstep versions.** All packages release together; `self.version` is what makes that true. Never write a `version`
  in a `composer.json` (a test fails if you do).
- **General purpose, vendor neutral.** Nothing in `packages/` may name a shop, a host, a CDN or a customer. Shop specifics
  are environment variables set by the consumer. `tests/Unit/PackagesTest.php` greps for it.
- **Allowed dependencies** of a package: `php`, `ext-*`, `magento/*`, `monolog/*`, `open-telemetry/*`, `psr/*`, `bode/*`.
  Adding anything else needs a reason in the PR and a change to that test.
- **Licence:** proprietary (`LICENSE` in each package), repository public, packages on Packagist through split
  repositories. Do not change licence text or add third-party code without a `THIRD_PARTY_NOTICES.md` entry.
- **Minimum PHP is 8.5**; Magento 2.4.9 (`magento/framework ^103`).

## 2. How a change reaches users

- Each package is mirrored to a read-only split repository (`ofabric-io/bode-module-{tracing,metrics,logs}`) by
  `.github/workflows/split.yml`; Packagist reads the split repos. **Never commit to a split repository.**
- Releases are automatic: merging a PR to `main` runs semantic-release, tags `vX.Y.Z`, and the split job pushes the tag.
  **Nobody tags by hand.** Details and one-time setup: `docs/releasing.md`.
- **The PR title is the release.** PRs are squash-merged and the title becomes the commit message, so write it as a
  Conventional Commit deliberately: `fix:`/`perf:` patch, `feat:` minor, `feat!:`/`BREAKING CHANGE:` major,
  `docs|refactor|test|build|ci|chore` no release. CI rejects a bad title.
- **Breaking means:** a change to a `Bode\Tracing\Api` interface, the name or labels of an emitted span/metric/log
  attribute, an environment variable, or the minimum PHP/Magento/OTel version. Dashboards and alert rules downstream
  depend on those names, so renaming one is a major release even when the code diff is one line.
- Open one PR per concern. A bug fix and a refactor are two PRs.

## 3. Daily commands

Only Docker is required on the host (no PHP, no make). `bin/dev` runs a command in an image with PHP 8.5, the
extensions Magento needs and `ext-opentelemetry`; `vendor/` lives in named volumes (bind-mounted vendor is very slow on
Windows).

```bash
bin/install                   # composer install: root + tools/quality + tools/phpstan
bin/dev composer lint         # composer validate (root + packages) + phpcs (Magento2 standard, PHPCompatibility)
bin/dev composer analyse      # PHPStan level 5 with the Magento extension
bin/dev composer test         # PHPUnit: every package + tests/Unit conventions
bin/dev composer verify       # all three; this is what CI runs
bin/dev bin/verify-install    # a throwaway project requires the packages the way a store does
harness/run.sh up && harness/run.sh scenarios && harness/run.sh logs   # real extension, real Tempo
harness/run.sh down
```

`LGTM_PUBLISH="" harness/run.sh up` publishes no host ports (use it when 3000/3200/4318 are taken).

## 4. Definition of done: the verification ladder

Green unit tests are the **floor**, not proof. They build classes by hand, not through compiled DI; they don't exercise
`app/etc/di.xml`'s merge-vs-replace behaviour; they don't run against a real `PageCache\Kernel`; and they don't run
inside a real process's exception handler chain. Climbing the ladder below is how those gaps get closed, before a
regression reaches a production shop.

Rules that follow:

1. **Climb the ladder to the lowest rung that can fail the way production fails.** Unit tests, then PHPStan, then
   `bin/verify-install`, then the harness (real extension, real SDK, Tempo), then a real Magento 2.4.9 in production mode
   with compiled DI (the shop repository's `make telemetry` stack). Anything that touches `di.xml`, plugins, constructor
   signatures, `registration.php` or a hook target **must** reach the last rung before you call it done.
2. **Every bug fix ships a regression test that fails on the old code.** Run it against the unfixed code once to prove it.
3. **Test the configuration that broke, not only the code.** `etc/di.xml` is code: parse it in a test (`DiConfigTest`).
4. **Say what you did not verify.** PR bodies end with "Not covered:". "Probably works" is a finding, not a result.
5. Never claim a harness or CI result you did not run. Report failures with the output.

## 5. Rules for telemetry code

These exist because this code runs inside every request of someone else's shop.

- **Telemetry must never break the host.** Hooks, plugins, formatters and processors catch `Throwable`, fall back to a
  minimal valid result, and (where there is a counter) count the swallowed error. A lost log line is acceptable; a failed
  checkout is not. `JsonLineFormatter` and `FatalErrorReporter` are the reference.
- **No-op without the runtime.** Without `ext-opentelemetry` or the SDK the packages must do nothing, throw nothing and
  cost nothing measurable. `Bode_Logs` still writes JSON, just without trace ids.
- **Configuration is environment variables only**, through `Bode\Tracing\Model\Environment` (injectable, so tests never
  call `putenv`). Prefix `MAGENTO_OTEL_`, per-module infix (`..._LOGS_`, `..._METRICS_`), booleans are the string
  `false`, lists are comma separated. Every variable is documented in the package README table in the same PR.
- **Privacy by default, and the same everywhere.** Never record query strings, request/response bodies, cookies, header
  values (except the configured correlation headers), SQL literals, e-mail addresses, or stack-frame arguments. Client
  address and user id go through `PrivacyInterface`. A new field that could carry personal data needs an explicit
  decision in the PR.
- **Low cardinality is a contract** for metric labels and span names: use route templates and action names, never ids,
  SKUs, URLs or client-chosen strings (`RouteNormalizerInterface`). Bound everything that comes from outside.
- **Bound everything:** depth, width, string length, line size, frames. Truncate visibly (say how much was dropped).
- **One semantic vocabulary.** Use OpenTelemetry semantic-convention names (`http.request.method`, `exception.type`,
  `code.function`, `url.path`, `cron.job_code`...). Invent a name only under `bode.` or an existing package prefix.
- Shared values (route names, `page_cache_hit`) live as constants in `tracing\Api`, so spans, metrics and logs agree.
- Decisions that look like omissions are written down in a comment ("uses its own key so exception.log routing is
  unchanged"). Do the same.

## 6. Magento facts that cost us a bug (verified against 2.4.9 source)

- **`di.xml` arguments are replaced, not merged, against the primary `app/etc/di.xml`.** Arrays merge only between
  modules. If you declare `handlers` on `Magento\Framework\Logger\Monolog`, you must repeat Magento's defaults.
  `processors` is safe because only modules declare it.
- **Never type a constructor parameter as `object`, `mixed` or an untyped array when DI injects it.** The compiled object
  manager hands such parameters the raw configuration. Type with the real class/interface and inject a `\Proxy` in
  `di.xml` for anything heavy or stateful (sessions, state, request, repositories): the logger is built before the app.
- **Plugins only work on public, real methods of non-final classes.** They cannot reach `private`/`protected` methods or
  magic setters (`Schedule::setStatus` is `__call`; `ProcessCronQueueObserver::tryRunJob` is private). For those, use an
  `ext-opentelemetry` hook (tracing) or a public neighbour (`Schedule::tryLockJob`, `save`).
- `Handler\Base` hard-codes `new LineFormatter(...)` in its constructor; the only seam is a plugin on `getFormatter`.
  `System` routes records with `context['exception']` to `exception.log`; do not change that key's meaning.
- `$logger->critical($e)` stringifies the Throwable into the message and loses the object; convert it before Monolog.
- `Magento\Framework\App\ErrorHandler` turns warnings/notices into exceptions; only fatals bypass the logger.
- The built-in full page cache answers **before routing**: such requests have no action name.
- `registration.php` runs at Composer autoload time on every PHP process, before Magento. Keep it tiny and exception
  free, and never touch the object manager from it.
- Long-running processes (consumers) reuse objects across units of work: per-request state implements
  `ResetAfterRequestInterface`.
- Hook targets are Magento internals and can be renamed by an upgrade; a hook on a missing method silently does nothing.
  Re-check every hook after a Magento upgrade, and keep the target list in the package README.
- `monolog/monolog` 3 passes `LogRecord` objects, not arrays. PHP 8.5 warns when `NAN`/`INF` are coerced to string.
- php-fpm's `catch_workers_output` cuts lines at `log_limit` (default 1024): relevant whenever JSON goes to stdout.

## 7. Code and test conventions

- `declare(strict_types=1)` everywhere; constructor promotion with `readonly`; interfaces for shared contracts.
- **phpcs warnings fail the build** (Magento2 standard). Fix them; do not suppress. A `phpcs:ignore` needs the exact sniff
  and a reason (`-- called before the container exists`). Rules that bite: 120 columns, docblock on every method and
  property, no `final`, no static methods (ignore with reason where unavoidable), no `ObjectManager`.
- PHPStan level 5, no baseline. Fix the finding; `@phpstan-ignore` needs an identifier and a reason.
- **Unit tests use no Magento classes** and no process environment: plain arrays, injected `Environment`, hand-written
  fakes (`FakeSpan`, `InMemorySink`, `FakeTraceProvider`), real Monolog 3 `LogRecord`s. Prefer a fake class to a mock.
- Name tests as sentences (`testNeverWritesTheSameFailureTwice`). Assert behaviour, including the failure paths:
  invalid UTF-8, huge values, recursion, throwing collaborators.
- Output contracts are tested against their schema (`packages/logs/schema/log-record.schema.json`); change the schema and
  the tests together.
- Test classes never go into the autoload class map (`exclude-from-classmap`, `.gitattributes export-ignore`).

## 8. Adding a package (checklist)

1. `packages/<name>/` with `composer.json` (type `magento2-module`, `bode/module-<name>`, `bode/module-tracing: self.version`,
   `exclude-from-classmap: ["/Test/"]`, no `version`), `registration.php`, `etc/module.xml` (sequence on its real
   dependencies), `README.md`, `LICENSE`, `THIRD_PARTY_NOTICES.md`, `.gitattributes`.
2. `tests/Unit/PackagesTest.php` (PACKAGES map, lockstep list), `phpunit.xml.dist` (suite + source include/exclude), root
   `composer.json` (`require`, `lint:packages`), `bin/verify-install` and `bin/verify-install-check.php`.
3. `.github/workflows/split.yml` matrix entry, the new split repository, and the repository list of `SPLIT_TOKEN`.
4. Root `README.md` table and `docs/releasing.md` table.
5. A harness scenario if the package does anything a stub Magento can show.

## 9. Git, PRs and shared state

- Branch names: `feat/…`, `fix/…`, `docs/…`, `ci/…`. Commits and PR titles are Conventional Commits; add the
  `Co-Authored-By` trailer for agent-written commits.
- **Check `git branch --show-current` before every commit.** Another session shares the working copy and switches
  branches under you; this has already landed a commit on the wrong branch. Prefer your own `git worktree add` per task.
- **Stage files explicitly; never `git add -A` or `commit -a`.** Never commit someone else's uncommitted work.
- Never `reset --hard`, force-push or delete a branch you did not create. If you must undo your own mistake on a shared
  branch, say exactly what you did to it.
- PR body: **Problem**, **Fix**, **Verified** (what you ran, on which rung of section 4), **Not covered**. State the
  release impact ("`fix:`, patch").
- Creating repositories, pushing, opening PRs and anything public is outward-facing: do it when asked, report the URL, and
  when a tool is blocked (public repo creation has been) give the user the exact command instead of working around it.
- **Credentials are the user's.** Never create, paste or store tokens (`SPLIT_TOKEN`, Packagist, registry keys). Tell the
  user the steps and the `gh secret set` line; let `gh` prompt for the value.

## 10. Working environment (Windows, Git Bash, Docker)

- No PHP, Composer or `make` on the host: everything through `bin/dev` or `docker run`. Set `MSYS_NO_PATHCONV=1` for
  `docker run` with paths, or Git Bash rewrites `/repo` to `C:/Program Files/Git/repo`.
- **The Bash tool eats backslashes** in heredocs and `node -e`. Anything with PHP namespaces or regexes: write it with the
  Write tool and run the file. Check PHP namespaces after any shell-generated edit.
- `Edit` and `Write` require a prior `Read` of that file in the session; re-read if it changed on disk.
- Keep temporary scripts and message files in the session scratchpad, never in the repository.
- Heavy installs (Magento framework) go through the named volumes of `bin/dev`; do not install into a bind mount.
- Host ports 3000/3200/4318 are often taken: use `LGTM_PUBLISH=""` and talk over the Docker network.

## 11. Reporting

Lead with the outcome and what you verified; list what is unverified and what the user must do (create a repo, add a
repository to a token, merge). Do not narrate the effort. Do not say "done" for something you only built.

## 12. Where things are

`README.md` (overview, develop) · `docs/releasing.md` (versions, split repos, Packagist, local path repository for a
consuming project) · `packages/*/README.md` (behaviour and the environment variable tables) ·
`packages/logs/schema/` (log contract) · `harness/` (end to end) · `tests/Unit/PackagesTest.php` (repository rules).

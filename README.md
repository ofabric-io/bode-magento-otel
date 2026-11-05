# bode-magento-otel

OpenTelemetry for Magento 2, as three Composer packages developed in this monorepo:

| Package | Magento module | What it does |
|---|---|---|
| [`bode/module-tracing`](packages/tracing) | `Bode_Tracing` | Traces: HTTP, cron, CLI, indexers, cache, queues, outbound HTTP, plus `trace_id` on log lines |
| [`bode/module-metrics`](packages/metrics) | `Bode_Metrics` | Metrics with a hard low-cardinality contract, plus a state collector |
| [`bode/module-logs`](packages/logs) | `Bode_Logs` | Every Magento log record and exception as one OpenTelemetry-shaped JSON line, with trace and span ids and context |

All are vendor-neutral and, apart from the logs package (which then simply has no trace ids), a no-op without
`ext-opentelemetry`. They are released together; installing the metrics or the logs package installs the tracing package
at the same version.

```bash
composer require bode/module-metrics bode/module-logs   # or bode/module-tracing alone
bin/magento module:enable Bode_Metrics Bode_Logs Bode_Tracing
bin/magento setup:upgrade
```

## Repository layout

```
packages/tracing, metrics, logs      the packages (each is a complete Magento module with its own composer.json)
composer.json                        development root: a path repository on packages/* with symlinks
tools/quality, tools/phpstan         isolated lint toolchains (their dependency trees conflict)
tests/Unit                           conventions of the packages (no shop references, allowed dependencies, manifests)
harness/                             end-to-end scenarios with the real extension against a local Grafana LGTM stack
e2e/                                 a real Magento 2.4.9, compiled DI, production mode (in progress, see bin/e2e)
bin/                                 dev wrapper, installer, install check, e2e entry point
docs/releasing.md                    how a release reaches Packagist
```

The root `composer.json` is what makes local development work: `packages/*` is a path repository with symlinks, so the
root installs the packages from the working tree and edits show up at once.

## Develop

Only Docker is needed. `bin/dev` runs a command in an image with PHP 8.5, the extensions Magento's framework needs,
`ext-opentelemetry` and Composer; the `vendor/` directories live in named volumes.

```bash
bin/install                    # composer install for the root and both tool trees
bin/dev composer lint          # composer manifests + phpcs (Magento2 standard, PHPCompatibility 8.5)
bin/dev composer analyse       # PHPStan level 5 with the Magento extension
bin/dev composer test          # unit tests of all packages + repository conventions
bin/dev composer verify        # all of the above
bin/dev bin/verify-install     # a throwaway project requires the packages like a Magento store would
harness/run.sh up && harness/run.sh scenarios   # real telemetry into a local Grafana (Docker)
bin/e2e up && bin/e2e install && bin/e2e web-up # a real Magento 2.4.9, compiled DI, production mode (Docker)
```

Magento's framework comes from the public [Mage-OS mirror](https://mirror.mage-os.org/), so no Magento licence keys are
needed anywhere. CI (`.github/workflows/ci.yml`) runs the same commands, on PHP 8.5.

Unit tests of the packages use no Magento classes. What needs a full Magento (compiled DI, a real request,
`app/etc/di.xml` merge behaviour, the full page cache) belongs to `e2e/`, driven by `bin/e2e`: a real
`magento/project-community-edition` installed from the Mage-OS mirror, the packages required from a path repository,
`setup:di:compile`'d in production mode. See `e2e/run.sh` for every subcommand. A CI workflow running the same thing
is in progress.

Releasing: [docs/releasing.md](docs/releasing.md).

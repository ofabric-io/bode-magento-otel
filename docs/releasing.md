# Releasing

Both packages are versioned together (`bode/module-metrics` requires `self.version` of `bode/module-tracing`). A release
is a `vX.Y.Z` tag on this repository, and **it is created automatically when a pull request is merged**: nobody tags by
hand and no `version` is written in any `composer.json`.

## Semantic versioning

The version follows [semver](https://semver.org) and is computed from the commit that lands on `main`. Pull requests are
squash-merged and the **PR title becomes the commit message**, so the title is what decides the release. CI rejects a
title that is not a [Conventional Commit](https://www.conventionalcommits.org).

| PR title | Release |
|---|---|
| `fix: ...`, `perf: ...`, `revert: ...` | patch (1.2.3 to 1.2.4) |
| `feat: ...` | minor (1.2.3 to 1.3.0) |
| `feat!: ...`, `fix(scope)!: ...` or a `BREAKING CHANGE:` footer | major (1.2.3 to 2.0.0) |
| `docs`, `refactor`, `test`, `build`, `ci`, `chore` | none |

What counts as breaking for these packages: a change to a public contract that a store may depend on, namely the
`Bode\Tracing\Api` interfaces, the names and labels of emitted metrics and spans, the environment variables, and the
minimum PHP, Magento or OpenTelemetry versions.

The first release is `1.0.0`. To start at `0.x` instead, tag `v0.1.0` by hand before merging the first releasable PR.

## What happens on merge

`.github/workflows/ci.yml` runs lint, analysis, tests and the install check; only when all pass does its `release` job run
[semantic-release](https://semantic-release.gitbook.io) (`.releaserc.json`). It tags `vX.Y.Z`, creates the GitHub release
with generated notes (the changelog lives in the GitHub releases) and starts `split.yml` for the tag, which pushes the tag
to every split repository, where Packagist picks it up.

## How a package reaches Composer

Packagist needs a `composer.json` at the root of a repository, and this repository holds three packages under `packages/`.
So each package is mirrored to a read-only **split repository** by `.github/workflows/split.yml`, and Packagist reads the
split repositories:

| Package | Split repository | Packagist name |
|---|---|---|
| `packages/tracing` | `ofabric-io/bode-module-tracing` | `bode/module-tracing` |
| `packages/metrics` | `ofabric-io/bode-module-metrics` | `bode/module-metrics` |
| `packages/logs` | `ofabric-io/bode-module-logs` | `bode/module-logs` |

Pushes to `main` update `main` of each split repository; a `vX.Y.Z` tag here becomes the same tag there. Nothing is ever
committed to a split repository by hand.

## One-time setup

1. Create the three empty repositories above on GitHub (no README, no licence file).
2. Create a fine-grained token with **Contents: read and write** on exactly those three repositories and store it as the
   Actions secret `SPLIT_TOKEN` of this repository.
3. Set the Actions variable `SPLIT_ENABLED` to `true` (the workflow does nothing until then).
4. Submit the split repositories at https://packagist.org/packages/submit and enable the GitHub webhook Packagist
   offers, so new tags appear at once. The `bode` vendor prefix belongs to whoever submits first.

## After a merge

Check that the release exists, that every split repository has the tag, and that Packagist lists it. A merge with only
`docs`, `ci` or `chore` commits makes no release, which is intended. If a release is wrong, fix it forward with a new
`fix:`; never move or delete a published tag.

## Using the packages in a project

Once published: `composer require bode/module-metrics`.

Before the first tag, or to try an unreleased change, point the project at a checkout of this repository:

```json
{
  "repositories": [
    {"type": "path", "url": "../bode-magento-otel/packages/*", "options": {"symlink": true}}
  ]
}
```

then `composer require bode/module-metrics:dev-main` (the project needs `"minimum-stability": "dev"` with
`"prefer-stable": true`, or the explicit `dev-main` constraint for all packages). Remove the entry before committing.

## Licence

The packages are proprietary (`LICENSE` in each). A proprietary licence on a public repository and a public Packagist
listing means anyone can install the code but nobody is licensed to use it beyond what the copyright holder grants in
writing. Decide that deliberately before the first submission to Packagist.

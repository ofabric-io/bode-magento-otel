# Releasing

Both packages are versioned together (`bode/module-metrics` requires `self.version` of `bode/module-tracing`). A release
is a `vX.Y.Z` tag on this repository.

## How a package reaches Composer

Packagist needs a `composer.json` at the root of a repository, and this repository holds two packages under `packages/`.
So each package is mirrored to a read-only **split repository** by `.github/workflows/split.yml`, and Packagist reads the
split repositories:

| Package | Split repository | Packagist name |
|---|---|---|
| `packages/tracing` | `ofabric-io/bode-module-tracing` | `bode/module-tracing` |
| `packages/metrics` | `ofabric-io/bode-module-metrics` | `bode/module-metrics` |

Pushes to `main` update `main` of each split repository; a `vX.Y.Z` tag here becomes the same tag there. Nothing is ever
committed to a split repository by hand.

## One-time setup

1. Create the two empty repositories above on GitHub (no README, no licence file).
2. Create a fine-grained token with **Contents: read and write** on exactly those two repositories and store it as the
   Actions secret `SPLIT_TOKEN` of this repository.
3. Set the Actions variable `SPLIT_ENABLED` to `true` (the workflow does nothing until then).
4. Submit both split repositories at https://packagist.org/packages/submit and enable the GitHub webhook Packagist
   offers, so new tags appear at once. The `bode` vendor prefix belongs to whoever submits first.

## Cutting a release

1. `bin/dev composer verify` and `bin/dev bin/verify-install` are green on `main` (CI runs both).
2. Update `CHANGELOG.md`.
3. Tag and push: `git tag v0.1.0 && git push origin v0.1.0`.
4. Check that both split repositories have the tag and that Packagist lists it.

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
`"prefer-stable": true`, or the explicit `dev-main` constraint for both packages). Remove the entry before committing.

## Licence

The packages are proprietary (`LICENSE` in each). A proprietary licence on a public repository and a public Packagist
listing means anyone can install the code but nobody is licensed to use it beyond what the copyright holder grants in
writing. Decide that deliberately before the first submission to Packagist.

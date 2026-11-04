# Third-party notices

## magento2-opentelemetry (MIT)

Parts of this module started as a copy of
https://github.com/mumzworld-tech/magento2-opentelemetry, release v1.0.1,
commit bd28492741bea7c851dccdb379feeb141f71da48, copyright (c) 2025 mumzworld.
The unmodified copy is in the git history of the repository this module was
published from (commit "verbatim copy of magento2-opentelemetry v1.0.1");
`git diff -M <that commit> <later commit>` shows every change.

What changed: namespace and instrumentation names rebranded (`bode.magento.*`),
shop-specific headers removed, storefront and all areas traced, request URLs
and SQL no longer recorded verbatim, per-trace span budgets, group toggles,
guarded hooks, privacy modes, cron/queue/audit instrumentation, log
correlation, tests. Upstream changes are not merged automatically; to follow
upstream, diff its new release against the pinned commit above and port by hand.

The upstream licence text is kept in `LICENSE-UPSTREAM-MIT`. The MIT licence covers what was derived from upstream;
the rest of the module is proprietary (see `LICENSE`).

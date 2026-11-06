<?php

declare(strict_types=1);

/**
 * Expected trace trees of the tree scenarios in run.php, checked by verify.php.
 *
 * Path syntax: span names joined with " > ", each segment an fnmatch() pattern, the first matched against the root of
 * a trace (the span whose parent is not in the trace), every next one against a direct child of the previous.
 *
 * Every check can carry an EXPECT_AFTER marker: the rail whose change makes it pass. A marked check that fails is
 * reported as "expected red" and does not fail the run; once the rail has merged, set HARNESS_STRICT_EXPECT=1 (or
 * delete the marker) and it gates like any other check. A marked check that passes is reported so the marker can go.
 *   A = trace integrity (roots only HTTP/cron/queue/cli, child-only spans without a root are dropped)
 *   B = entity spans (the "entities" group)
 *   C = per-group caps and collapse
 *
 * Keys per scenario:
 *   traces   [int count, ?marker]       exact number of traces the scenario produced
 *   orphans  ?marker                    no non-root span without a parent in its trace (key absent = not checked,
                                       null = checked with no marker)
 *   paths    list of [path, ?marker]    each must exist
 *   absent   list of [fnmatch, ?marker] no span name may match
 *   max      [int spans per trace, ?marker]
 */

return [
    'entity-product' => [
        'traces' => [1, null],
        'orphans' => null,
        'paths' => [
            ['GET *', null],
            ['GET * > event catalog_controller_product_view', null],
            ['GET * > redis GET', null],
            ['GET * > entity load catalog_product > SELECT catalog_product_entity', null],
            ['GET * > entity load cms_block', null],
        ],
        'absent' => [],
    ],
    'events-bootstrap' => [
        'traces' => [1, null],
        'orphans' => null,
        'paths' => [
            ['GET * > event during_bootstrap_event', null],
        ],
        'absent' => [
            ['event before_bootstrap_event', null],
            ['event after_bootstrap_event', null],
        ],
    ],
    'event-excluded-path' => [
        'traces' => [0, null],
        'orphans' => null,
        'paths' => [],
        'absent' => [['event static_asset_event', null]],
    ],
    'cron-in-event' => [
        'traces' => [1, null],
        'orphans' => null,
        'paths' => [
            ['cron cron_from_event > SELECT cron_schedule', null],
        ],
        'absent' => [['event cron_from_event', null]],
    ],
    'collection-volume' => [
        'traces' => [1, null],
        'orphans' => null,
        'paths' => [
            ['GET *', null],
            ['GET * > collection load*', null],
        ],
        'absent' => [],
        // 400 rows x (entity + query) = 800 candidate spans once rail B emits entity spans; the trace must stay bounded
        // (span cap 500 today; rail C's per-group caps and collapse only make it smaller). Slack of 60 for the root and
        // any collapse summary spans. Passes today because no entity spans exist yet: it guards B and C from here on.
        'max' => [560, null],
    ],
];

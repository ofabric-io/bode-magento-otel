<?php

declare(strict_types=1);

namespace Bode\Tracing\Model;

use Bode\Tracing\Api\InstrumentationGuardInterface;

/**
 * Module configuration, read from environment variables only (no database, no putenv).
 *
 * MAGENTO_OTEL_TRACING_GROUPS  comma separated groups to switch on (default: DEFAULT_GROUPS). Items starting with "-"
 *                              subtract from the defaults (-cache); "all" switches every group on.
 * MAGENTO_OTEL_EXCLUDED_PATHS  comma separated path fragments never traced (default: DEFAULT_EXCLUDED)
 * MAGENTO_OTEL_SPAN_CAP        maximum spans per trace, cron job or queue message (default 500)
 * MAGENTO_OTEL_SPAN_CAP_GROUPS  per-group caps, "entities:150,sql:200"; invalid entries ignored; none by default
 * MAGENTO_OTEL_SPAN_COLLAPSE_AFTER  0 (default) = off; N > 0 stops starting repeated sibling spans after N
 * MAGENTO_OTEL_ENABLED         "false" disables everything
 */
class Config implements InstrumentationGuardInterface
{
    public const DEFAULT_GROUPS = ['http', 'cron', 'cli', 'indexer', 'cache', 'queue', 'outbound'];
    public const OPT_IN_GROUPS = ['events', 'sql', 'eav', 'redis', 'business'];
    public const DEFAULT_EXCLUDED = ['health_check.php', '/health', '/static/', '/media/', 'cron.php', 'get.php'];
    public const DEFAULT_SPAN_CAP = 500;

    /**
     * @var string[]
     */
    private array $groups;

    /**
     * @param Environment $env
     * @param bool|null $runtimeAvailable null detects ext-opentelemetry and the SDK
     */
    public function __construct(private readonly Environment $env, private readonly ?bool $runtimeAvailable = null)
    {
        $this->groups = $this->resolveGroups($env->list('MAGENTO_OTEL_TRACING_GROUPS'));
    }

    /**
     * @inheritdoc
     */
    public function isEnabled(): bool
    {
        if (strtolower($this->env->get('MAGENTO_OTEL_ENABLED', 'true')) === 'false') {
            return false;
        }
        if ($this->runtimeAvailable !== null) {
            return $this->runtimeAvailable;
        }

        return extension_loaded('opentelemetry')
            && class_exists(\OpenTelemetry\SDK\Sdk::class)
            && !\OpenTelemetry\SDK\Sdk::isDisabled();
    }

    /**
     * @inheritdoc
     */
    public function isGroupEnabled(string $group): bool
    {
        return in_array($group, $this->groups, true);
    }

    /**
     * @inheritdoc
     */
    public function isPathExcluded(string $path): bool
    {
        $fragments = $this->env->list('MAGENTO_OTEL_EXCLUDED_PATHS') ?: self::DEFAULT_EXCLUDED;
        foreach ($fragments as $fragment) {
            if (str_contains($path, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Maximum spans per unit of work.
     *
     * @return int
     */
    public function spanCap(): int
    {
        return max(1, $this->env->int('MAGENTO_OTEL_SPAN_CAP', self::DEFAULT_SPAN_CAP));
    }

    /**
     * Per-group span caps.
     *
     * @return array<string,int>
     */
    public function spanGroupCaps(): array
    {
        $caps = [];
        foreach ($this->env->list('MAGENTO_OTEL_SPAN_CAP_GROUPS') as $item) {
            $parts = explode(':', $item);
            if (count($parts) !== 2 || !preg_match('/^[a-z_]+$/', $parts[0]) || !ctype_digit($parts[1])) {
                continue;
            }
            $cap = (int) $parts[1];
            if ($cap > 0) {
                $caps[$parts[0]] = $cap;
            }
        }

        return $caps;
    }

    /**
     * Repeat collapse threshold, 0 = off.
     *
     * @return int
     */
    public function spanCollapseAfter(): int
    {
        return max(0, $this->env->int('MAGENTO_OTEL_SPAN_COLLAPSE_AFTER', 0));
    }

    /**
     * Enabled feature groups.
     *
     * @return string[] enabled groups
     */
    public function groups(): array
    {
        return $this->groups;
    }

    /**
     * Resolves the requested groups against the defaults.
     *
     * @param string[] $requested
     * @return string[]
     */
    private function resolveGroups(array $requested): array
    {
        if ($requested === []) {
            return self::DEFAULT_GROUPS;
        }
        $known = array_merge(self::DEFAULT_GROUPS, self::OPT_IN_GROUPS);
        $groups = [];
        $subtract = [];
        foreach ($requested as $item) {
            if ($item === 'all') {
                array_push($groups, ...$known);
            } elseif (str_starts_with($item, '-')) {
                $subtract[] = substr($item, 1);
            } else {
                $groups[] = $item;
            }
        }
        // Only "-x" items given: they subtract from the defaults.
        if ($groups === []) {
            $groups = self::DEFAULT_GROUPS;
        }

        return array_values(array_intersect(array_diff(array_unique($groups), $subtract), $known));
    }
}

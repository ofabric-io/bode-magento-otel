<?php

declare(strict_types=1);

namespace Bode\Tracing\Hook;

use Bode\Tracing\Hook\Otel\SpanStarter;
use Bode\Tracing\Instrumentation\InstrumentationInterface;
use Bode\Tracing\Model\Config;
use Bode\Tracing\Model\Environment;
use Bode\Tracing\Model\ErrorCounter;
use Bode\Tracing\Model\Privacy;
use Bode\Tracing\Model\ResolvedAction;
use Bode\Tracing\Model\RouteNormalizer;
use Bode\Tracing\Model\SpanBudget;
use Bode\Tracing\Model\SqlObfuscator;
use Throwable;

/**
 * Process-wide holder of the objects the static hook callbacks need. Hooks are registered at autoload time, before
 * Magento's dependency injection exists, so they cannot receive injected services; everything they share lives here
 * and nowhere else. Code that is created by DI reaches it through TelemetryStateInterface.
 */
// The hook callbacks are static and registered before dependency injection exists, so this holder is static by
// necessity; nothing else in the module is.
// phpcs:disable Magento2.Functions.StaticFunction
class Runtime
{
    /**
     * @var Runtime|null
     */
    private static ?Runtime $instance = null;

    /**
     * @var SpanStack
     */
    public readonly SpanStack $stack;

    /**
     * @var SpanBudget
     */
    public readonly SpanBudget $budget;

    /**
     * @var ErrorCounter
     */
    public readonly ErrorCounter $errors;

    /**
     * @var Instrumenter
     */
    public readonly Instrumenter $instrumenter;

    /**
     * @var SpanStarter
     */
    public readonly SpanStarter $spans;

    /**
     * @param Config $config
     * @param Environment $env
     * @param HookRegistrarInterface $registrar
     */
    public function __construct(
        public readonly Config $config,
        public readonly Environment $env,
        HookRegistrarInterface $registrar
    ) {
        $this->stack = new SpanStack();
        $this->budget = new SpanBudget($config->spanCap());
        $this->errors = new ErrorCounter(strtolower($env->get('MAGENTO_OTEL_DEBUG')) === 'true');
        $this->instrumenter = new Instrumenter($registrar, $config, $this->budget, $this->stack, $this->errors);
        $this->spans = new SpanStarter();
        $this->privacy = new Privacy($env);
        $this->routes = new RouteNormalizer();
        $this->sql = new SqlObfuscator();
        $this->action = new ResolvedAction();
    }

    /**
     * @var Privacy
     */
    public readonly Privacy $privacy;

    /**
     * @var RouteNormalizer
     */
    public readonly RouteNormalizer $routes;

    /**
     * @var SqlObfuscator
     */
    public readonly SqlObfuscator $sql;

    /**
     * The real controller/service class and action resolved for the request in progress. Reset at the start of
     * every request (HttpInstrumentation::begin()).
     *
     * @var ResolvedAction
     */
    public readonly ResolvedAction $action;

    /**
     * Registers all instrumentations once per process (called from registration.php).
     *
     * Does nothing, and costs one class_exists and one extension_loaded call, when telemetry is unavailable or
     * disabled.
     *
     * @param InstrumentationInterface[]|null $instrumentations null uses the module's own list
     * @return void
     */
    public static function boot(?array $instrumentations = null): void
    {
        if (self::$instance !== null) {
            return;
        }

        try {
            $env = new Environment();
            $config = new Config($env);
            if (!$config->isEnabled()) {
                return;
            }
            self::$instance = new self($config, $env, new OtelHookRegistrar());
            foreach ($instrumentations ?? self::defaultInstrumentations() as $instrumentation) {
                try {
                    $instrumentation->register(self::$instance);
                } catch (Throwable $e) {
                    self::$instance->errors->record('boot', $e);
                }
            }
        } catch (Throwable) {
            // Never break the application because telemetry could not start.
            self::$instance = null;
        }
    }

    /**
     * Value of an environment variable.
     *
     * @return Runtime|null null when telemetry is not running in this process
     */
    public static function get(): ?Runtime
    {
        return self::$instance;
    }

    /**
     * Forgets the instance. For tests only.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * The module's own instrumentations.
     *
     * @return InstrumentationInterface[]
     */
    private static function defaultInstrumentations(): array
    {
        $classes = [
            \Bode\Tracing\Instrumentation\HttpInstrumentation::class,
            \Bode\Tracing\Instrumentation\CronInstrumentation::class,
            \Bode\Tracing\Instrumentation\CliInstrumentation::class,
            \Bode\Tracing\Instrumentation\IndexerInstrumentation::class,
            \Bode\Tracing\Instrumentation\CacheInstrumentation::class,
            \Bode\Tracing\Instrumentation\QueueInstrumentation::class,
            \Bode\Tracing\Instrumentation\OutboundHttpInstrumentation::class,
            \Bode\Tracing\Instrumentation\OptInInstrumentation::class,
        ];

        return array_map(static fn (string $class): InstrumentationInterface => new $class(), $classes);
    }
}
// phpcs:enable Magento2.Functions.StaticFunction

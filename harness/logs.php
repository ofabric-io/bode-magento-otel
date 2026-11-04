<?php

declare(strict_types=1);

/**
 * Scenarios of Bode_Logs, included by run.php (it provides $scenario, $web and the stub Magento classes):
 *
 *   log-json       CLI shape: lines to stdout, inside a web request span, between jobs, in a cron job
 *   log-json-web   the same with the web request attributes (fpm-fcgi)
 *   log-uncaught   an uncaught exception ends the process; the reporter writes the JSON line to stderr
 *
 * Wires the real classes by hand the way Magento's di.xml does (processor on the logger, formatter on the handler, the
 * Throwable plugin in front of the logger methods), so what comes out is what a store writes.
 */

use Bode\Logs\Model\CallerResolver;
use Bode\Logs\Model\Config;
use Bode\Logs\Model\ExceptionNormalizer;
use Bode\Logs\Model\ExecutionContext;
use Bode\Logs\Model\FatalErrorReporter;
use Bode\Logs\Model\Formatter\JsonLineFormatter;
use Bode\Logs\Model\Processor\StructuredContextProcessor;
use Bode\Logs\Model\RecordAssembler;
use Bode\Logs\Model\RequestProviderInterface;
use Bode\Logs\Model\ResourceAttributes;
use Bode\Logs\Model\ValueNormalizer;
use Bode\Logs\Plugin\ThrowableAsMessage;
use Bode\Tracing\Model\ClientAddressResolver;
use Bode\Tracing\Model\Environment;
use Bode\Tracing\Model\Privacy;
use Bode\Tracing\Model\RouteNormalizer;
use Bode\Tracing\Model\TraceContextProvider;

$env = new Environment();
$config = new Config($env);
$exceptions = new ExceptionNormalizer($config);
$values = new ValueNormalizer($config, $exceptions);
$resource = new ResourceAttributes($env);
$trace = new TraceContextProvider();

if ($scenario === 'log-uncaught') {
    (new FatalErrorReporter($config, $resource, $exceptions, $trace))->install();
    throw new RuntimeException('uncaught in the harness', 3, new LogicException('the root cause'));
}

$execution = new ExecutionContext(
    $env,
    new RouteNormalizer(),
    new Privacy($env),
    new ClientAddressResolver($env),
    new class implements RequestProviderInterface {
        /**
         * @inheritdoc
         */
        public function area(): ?string
        {
            return 'frontend';
        }

        /**
         * @inheritdoc
         */
        public function route(): ?string
        {
            return 'checkout_index_index';
        }
    },
    null,
    null,
    $scenario === 'log-json-web' ? 'fpm-fcgi' : null
);
$assembler = new RecordAssembler($config, $trace, new CallerResolver(), $execution, $exceptions, $values, $resource);
$handler = new Monolog\Handler\StreamHandler('php://stdout');
$handler->setFormatter(new JsonLineFormatter($config, $assembler, $values));
$logger = new Monolog\Logger('main', [$handler], [new StructuredContextProcessor($config, $assembler)]);
$plugin = new ThrowableAsMessage($config);

/** Magento's $logger->critical($e), as the plugin in front of the logger rewrites it. */
$logThrowable = static function (Throwable $e) use ($logger, $plugin): void {
    [$message, $context] = $plugin->beforeCritical($logger, $e);
    $logger->critical($message, $context);
};

$web('frontend', '/checkout/onepage/?token=abc&email=jane@example.com', static function ($r) use ($logger, $logThrowable): void {
    $logger->info('checkout rendered', ['sku' => 'GOLD-1OZ', 'items' => 2]);
    $order = static function (int $id): void {
        throw new RuntimeException('Cannot place order', 3, new LogicException('Quote 5 has no items'));
    };
    try {
        $order(1001);
    } catch (Throwable $e) {
        $logger->error('payment declined', ['exception' => $e, 'order_id' => 1001]);
        $logThrowable($e);
    }
});

$logger->info('after the request, no span is current');

$execution->setCronJob('catalog_product_alert', '42');
$logger->warning('alert job is slow', ['seconds' => 31]);
$execution->setCronJob(null);
$logger->notice('between jobs');

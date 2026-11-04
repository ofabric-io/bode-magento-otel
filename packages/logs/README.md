# Bode_Logs

Structured logs for Magento 2. Every record Magento's logger writes, and every exception it is given, becomes **one JSON
object per line**, shaped after the [OpenTelemetry log data model](https://opentelemetry.io/docs/specs/otel/logs/data-model/),
with the `trace_id` and `span_id` of the current span and context a person debugging at 3 a.m. needs: the class that
logged, the cron job or CLI command it belongs to, the request, and the exception as data instead of a wall of text.
Vendor-neutral: configuration is environment variables. Works without `ext-opentelemetry` (the trace fields are then
simply absent).

## Installation

```bash
composer require bode/module-logs      # also installs bode/module-tracing, the same version
bin/magento module:enable Bode_Logs Bode_Tracing
bin/magento setup:upgrade
```

Nothing else to configure: the files in `var/log` turn into JSON lines.

## What a line looks like

```json
{"timestamp":"2026-11-04T20:03:41.123456Z","severity_text":"ERROR","severity_number":17,
 "body":"RuntimeException: Cannot reserve stock",
 "trace_id":"4bf92f3577b34da6a3ce929d0e0e4736","span_id":"00f067aa0ba902b7","trace_flags":"01",
 "resource":{"service.name":"shop-php","service.version":"1.4.2","deployment.environment.name":"production",
             "host.name":"web-1","process.pid":412,"process.runtime.name":"php","process.runtime.version":"8.5.0"},
 "attributes":{"log.channel":"main",
   "code.namespace":"Acme\\Stock\\Model\\Reservation","code.function":"reserve",
   "code.file.path":"/var/www/html/app/code/Acme/Stock/Model/Reservation.php","code.line.number":88,
   "magento.area":"crontab","cron.job_code":"acme_stock_sync","cron.schedule_id":"90210","correlation.id":"9f1c2a7b3d4e5f60",
   "exception.type":"RuntimeException","exception.message":"Cannot reserve stock",
   "exception.stacktrace":"RuntimeException: Cannot reserve stock in /var/www/...:88\n#0 ...","exception.frames":[{"class":"...","function":"reserve","file":"...","line":88}],
   "exception.cause":[{"type":"LogicException","message":"SKU 123 is not stocked","file":"...","line":31}],
   "error.fingerprint":"3b1f9e0a52c4d871",
   "context":{"order_id":1001}}}
```

The contract is [`schema/log-record.schema.json`](schema/log-record.schema.json); the unit tests validate real output
against it.

| Field | Meaning |
|---|---|
| `timestamp`, `severity_text`, `severity_number`, `body` | The record. Severity numbers are OpenTelemetry's (DEBUG 5, INFO 9, NOTICE 10, WARNING 13, ERROR 17, CRITICAL 21, ALERT 22, EMERGENCY 24). |
| `trace_id`, `span_id`, `trace_flags` | The current span (Bode_Tracing). Absent outside a trace. |
| `resource` | Who is logging: `OTEL_SERVICE_NAME` and `OTEL_RESOURCE_ATTRIBUTES` (`service.version`, `deployment.environment.name`, ...), host, process. The same identity your traces and metrics carry. |
| `code.*` | The emitting class and method and the call site (file, line), resolved past Monolog, Magento's logger and generated Interceptors/Proxies. |
| `magento.area`, `http.*`, `url.path`, `client.address` | The web request: method, path **without query string**, the route (Magento's action name, or the REST route template), the client address as `MAGENTO_OTEL_IP_MODE` allows. |
| `cli.command`, `messaging.consumer` | The `bin/magento` command, and the consumer name for `queue:consumers:start`. |
| `cron.job_code`, `cron.schedule_id` | The cron job that is running, only while it runs. |
| `correlation.id` | Ties the lines of one request or run together: the first header of `MAGENTO_OTEL_CORRELATION_HEADERS` (a CDN request id, for example), else a random id per request or process. |
| `exception.*`, `error.fingerprint` | The exception: type, message, code, location, stack trace text, structured frames **without arguments**, the chain of previous exceptions, and a fingerprint of the root cause for grouping the same failure. |
| `context`, `extra` | The PSR-3 context and the Monolog extra of the call, normalised: bounded in depth, width and size, valid UTF-8, objects named rather than dumped. |

Never recorded by this module: query strings, request bodies, cookies, header values other than the configured
correlation headers, stack-frame arguments. **The context you pass to the logger is written as you passed it**: this
module does not redact it, so do not log secrets.

## How it covers everything

- **All file handlers.** Magento's handlers set a text formatter in their constructor and offer no hook, so a plugin on
  `Handler\Base` hands out the JSON formatter. `system.log`, `debug.log`, `exception.log` and the handlers of other
  modules (anything built on `Base`) all become JSON, with no per-handler configuration.
- **Exceptions Magento logs as the message.** `$logger->critical($e)` makes Monolog turn the exception into a multi-line
  string and drop the object. A plugin on Magento's logger moves the Throwable into the context first, so the line carries
  it as structured data and the body is one line. It uses its own context key, so Magento's routing of exceptions to
  `exception.log` is unchanged.
- **Failures the logger never sees.** An uncaught exception or a fatal error (out of memory, parse error, an uncaught
  `TypeError`) ends the process before any handler runs. A reporter registered at autoload writes the same JSON record
  to stderr, with no container and no Monolog, and then lets PHP end the process as it would have.
- **Cron.** The running job is noted when its schedule row is locked and forgotten when it reaches a final status, so a
  line logged inside a job carries `cron.job_code` and a line logged between jobs does not. No extension needed.

## Configuration (environment variables)

| Variable | Default | |
|---|---|---|
| `MAGENTO_OTEL_LOGS_ENABLED` | `true` | `false` leaves Magento's logging exactly as it is |
| `MAGENTO_OTEL_LOGS_OUTPUT` | `file` | Comma separated: `file` (Magento's `var/log` files), `stdout`, `stderr`. Without `file` the file handlers are silent, so a container shipping stdout does not also fill its disk |
| `MAGENTO_OTEL_LOGS_LEVEL` | `info` | Lowest level written to stdout/stderr (the files keep Magento's own thresholds) |
| `MAGENTO_OTEL_LOGS_CALLER` | `true` | `false` skips resolving the emitting class (one backtrace per record) |
| `MAGENTO_OTEL_LOGS_STACK_FRAMES` | `50` | Frames kept per exception; the number dropped is recorded |
| `MAGENTO_OTEL_LOGS_EXCEPTION_DEPTH` | `5` | Previous exceptions followed |
| `MAGENTO_OTEL_LOGS_CONTEXT_DEPTH` | `6` | Nesting depth kept in `context` |
| `MAGENTO_OTEL_LOGS_MAX_VALUE_BYTES` | `8192` | Longest single string |
| `MAGENTO_OTEL_LOGS_MAX_LINE_BYTES` | `65536` | Longest line. Over it, frames, then the stack text, then context, then the body are shed, in that order, and `log.truncated` says what went |
| `MAGENTO_OTEL_LOGS_FATAL` | `true` | `false` does not register the fatal error reporter |

It also reads `OTEL_SERVICE_NAME`, `OTEL_RESOURCE_ATTRIBUTES`, `MAGENTO_OTEL_IP_MODE`,
`MAGENTO_OTEL_FORWARDED_HEADER` and `MAGENTO_OTEL_CORRELATION_HEADERS` (see Bode_Tracing).

## Shipping the lines

One object per line means no multiline joining and no regular expressions. With Grafana Alloy:

```alloy
loki.process "magento" {
  stage.json {
    expressions = { ts = "timestamp", level = "severity_text", trace_id = "trace_id" }
  }
  stage.timestamp { source = "ts"  format = "RFC3339Nano" }
  stage.labels { values = { level = "" } }
  stage.structured_metadata { values = { trace_id = "" } }
  forward_to = [loki.write.default.receiver]
}
```

## Limits

- `Bootstrap` logs one failure path as `->error($e->getMessage())` without the exception. That line has no stack to
  structure; it still carries the context, the trace ids and the emitting class.
- PHP's own `error_log` text (warnings written by PHP itself, php-fpm notices) is not JSON. Magento turns warnings and
  notices into exceptions, which do reach the logger; fatal errors are covered by the reporter above.
- With `php-fpm`, stdout/stderr of workers pass through `catch_workers_output`, whose line limit (`log_limit`, 1024 by
  default) cuts long lines. Raise `log_limit` in the pool when shipping stdout, or keep `file`.
- Hooks that a plugin cannot reach (Magento keeps the cron job private) are noted from the schedule row; a job that
  never locks its row never gets a `cron.job_code`.

## How it works (for contributors)

- `Model\Processor\StructuredContextProcessor` runs once per record, inside the logger call (so the backtrace and the
  current span are the caller's) and leaves what `Model\RecordAssembler` collected in the record's `extra`.
  `Model\Formatter\JsonLineFormatter` serialises it; it never throws and falls back to a minimal valid line.
- `Model\ExecutionContext` holds the per-request facts and the running cron job (`ResetAfterRequestInterface`, so a
  long-running consumer does not carry one request's facts into the next).
- `Model\ValueNormalizer` and `Model\ExceptionNormalizer` bound and clean what goes into a line.
- Plugins are in `Plugin/`, wiring in `etc/di.xml`.

## Tests

`composer test` in the monorepo root (also run in CI: the unit tests need no Magento). `harness/` in the monorepo
runs the logging scenarios with the real extension and checks the lines against Tempo.

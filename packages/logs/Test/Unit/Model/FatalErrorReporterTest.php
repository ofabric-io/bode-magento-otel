<?php

declare(strict_types=1);

namespace Bode\Logs\Test\Unit\Model;

use Bode\Logs\Model\ExceptionNormalizer;
use Bode\Logs\Model\FatalErrorReporter;
use Bode\Logs\Model\ResourceAttributes;
use Bode\Logs\Test\Unit\Support\FakeTraceProvider;
use Bode\Logs\Test\Unit\Support\Fixture;
use Bode\Tracing\Model\Environment;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FatalErrorReporterTest extends TestCase
{
    /**
     * @var string
     */
    private string $file = '';

    protected function setUp(): void
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'bode-logs-');
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    /**
     * @param array<string,string>|null $trace
     * @return FatalErrorReporter
     */
    private function reporter(?array $trace = Fixture::TRACE): FatalErrorReporter
    {
        $config = Fixture::config();

        return new FatalErrorReporter(
            $config,
            new ResourceAttributes(new Environment(['OTEL_SERVICE_NAME' => 'shop-test'])),
            new ExceptionNormalizer($config),
            new FakeTraceProvider($trace),
            $this->file
        );
    }

    public function testBuildsAStructuredRecordForAnUncaughtException(): void
    {
        $record = $this->reporter()->exceptionRecord(new RuntimeException('Out of cheese'));

        self::assertSame('CRITICAL', $record['severity_text']);
        self::assertSame(21, $record['severity_number']);
        self::assertSame('Uncaught RuntimeException: Out of cheese', $record['body']);
        self::assertSame('php.uncaught', $record['attributes']['log.channel']);
        self::assertSame(RuntimeException::class, $record['attributes']['exception.type']);
        self::assertSame(Fixture::TRACE['trace_id'], $record['trace_id']);
        self::assertSame('shop-test', $record['resource']['service.name']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $record['timestamp']);
    }

    public function testBuildsARecordForAFatalError(): void
    {
        $record = $this->reporter(null)->errorRecord([
            'type' => E_ERROR,
            'message' => 'Allowed memory size of 134217728 bytes exhausted',
            'file' => '/app/code/Foo.php',
            'line' => 12,
        ]);

        self::assertSame('Allowed memory size of 134217728 bytes exhausted', $record['body']);
        self::assertSame('php.fatal', $record['attributes']['log.channel']);
        self::assertSame('E_ERROR', $record['attributes']['error.type']);
        self::assertSame('/app/code/Foo.php', $record['attributes']['code.file.path']);
        self::assertSame(12, $record['attributes']['code.line.number']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $record['attributes']['error.fingerprint']);
        self::assertArrayNotHasKey('trace_id', $record);
    }

    public function testWritesTheLineAndStillLetsPhpEndTheProcessWithItsOwnHandler(): void
    {
        $seen = [];
        $previous = static function (\Throwable $e) use (&$seen): void {
            $seen[] = $e->getMessage();
        };
        set_exception_handler($previous);
        $reporter = $this->reporter();
        $reporter->install();

        try {
            $reporter->onException(new RuntimeException('kaboom'));
        } finally {
            restore_exception_handler();
            restore_exception_handler();
        }

        self::assertSame(['kaboom'], $seen, 'the handler that was there before is still called');
        $line = json_decode((string) file_get_contents($this->file), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Uncaught RuntimeException: kaboom', $line['body']);
        self::assertSame(1, substr_count((string) file_get_contents($this->file), "\n"));
    }

    public function testRethrowsForPhpWhenNothingWasInstalledBeforeAndClearsItsOwnHandler(): void
    {
        set_exception_handler(null);
        $reporter = $this->reporter();
        $reporter->install();
        $e = new RuntimeException('end the process');

        try {
            $reporter->onException($e);
            self::fail('the exception has to go out again so PHP ends the process');
        } catch (RuntimeException $rethrown) {
            self::assertSame($e, $rethrown);
        } finally {
            // Handlers pushed by this test: the null above, install(), and the clearing inside onException().
            restore_exception_handler();
            restore_exception_handler();
            restore_exception_handler();
        }

        self::assertSame(1, substr_count((string) file_get_contents($this->file), "\n"));
    }

    public function testNeverWritesTheSameFailureTwice(): void
    {
        $previous = static function (\Throwable $e): void {
        };
        set_exception_handler($previous);
        $reporter = $this->reporter();
        $reporter->install();

        try {
            $reporter->onException(new RuntimeException('again'));
            $reporter->onException(new RuntimeException('again'));
        } finally {
            restore_exception_handler();
            restore_exception_handler();
        }

        self::assertSame(1, substr_count((string) file_get_contents($this->file), "\n"));
    }
}

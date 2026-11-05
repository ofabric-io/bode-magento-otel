<?php

declare(strict_types=1);

namespace Bode\Logs\Test\Unit;

use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * The module's DI wiring of Magento's logger.
 *
 * An argument declared in a module's di.xml replaces the argument of the same name in Magento's primary app/etc/di.xml
 * (arrays are merged between modules only, never with the primary file). The logger's "handlers" argument is defined
 * there with system, debug and syslog; declaring it here with only our own items once removed Magento's file
 * handlers, so nothing reached system.log or debug.log and the application logged into nothing, while every test
 * still passed.
 */
class DiConfigTest extends TestCase
{
    private const LOGGER = 'Magento\Framework\Logger\Monolog';
    /** Items of Magento's primary di.xml for the logger's "handlers" argument (Magento 2.4.x). */
    private const MAGENTO_DEFAULT_HANDLERS = ['system', 'debug', 'syslog'];

    public function testLoggerHandlersKeepMagentosDefaultsNextToOurs(): void
    {
        $items = $this->handlerItems();

        foreach (self::MAGENTO_DEFAULT_HANDLERS as $default) {
            self::assertContains(
                $default,
                $items,
                "Declaring the logger's handlers replaces Magento's list: repeat the default '$default' handler"
            );
        }
        foreach (['bode_stdout', 'bode_stderr'] as $ours) {
            self::assertContains($ours, $items);
        }
    }

    public function testDefaultsAreTheSameClassesMagentoUses(): void
    {
        $classes = $this->handlerClasses();

        self::assertSame('Magento\Framework\Logger\Handler\System', $classes['system'] ?? null);
        self::assertSame('Magento\Framework\Logger\Handler\Debug', $classes['debug'] ?? null);
        self::assertSame('Magento\Framework\Logger\Handler\Syslog', $classes['syslog'] ?? null);
    }

    /**
     * @return string[]
     */
    private function handlerItems(): array
    {
        return array_keys($this->handlerClasses());
    }

    /**
     * @return array<string,string> item name => class
     */
    private function handlerClasses(): array
    {
        $xml = new SimpleXMLElement((string) file_get_contents(dirname(__DIR__, 2) . '/etc/di.xml'));
        $nodes = $xml->xpath(
            sprintf("//type[@name='%s']/arguments/argument[@name='handlers']/item", self::LOGGER)
        ) ?: [];
        $out = [];
        foreach ($nodes as $node) {
            $out[(string) $node['name']] = trim((string) $node);
        }

        return $out;
    }
}

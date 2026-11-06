<?php

declare(strict_types=1);

namespace Bode\Tracing\Test\Unit;

use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * The module's DI wiring (etc/di.xml, and etc/adminhtml/events.xml for the observers it names), parsed rather than
 * trusted: unit tests construct classes by hand, so a wrong di.xml passes every one of them and only fails in a
 * compiled production Magento (see AGENTS.md section 4 and section 6).
 *
 * What each test guards, and why it is a test:
 *  - preferences: a typo in an interface name or class silently leaves the interface unbound, and every consumer of the
 *    shared contracts (Bode_Metrics, Bode_Logs) then fails at compile time in a store, not here.
 *  - null arguments: the compiled object manager hands a constructor parameter with no usable type the raw
 *    configuration; "values" and "runtimeAvailable" are nullable scalars that must be explicitly null.
 *  - proxies: sessions, repositories and other stateful services are built lazily, because the logger is created
 *    before the application is bootstrapped.
 *  - logger arguments: an argument declared here replaces the primary app/etc/di.xml one (only "processors" is safe,
 *    because only modules declare it); declaring "handlers" here would delete Magento's file handlers.
 */
class DiConfigTest extends TestCase
{
    private const LOGGER = 'Magento\Framework\Logger\Monolog';
    private const NAMESPACE_ROOT = 'Bode\Tracing\\';

    /** The shared contracts and the classes that implement them. */
    private const PREFERENCES = [
        'Bode\Tracing\Api\InstrumentationGuardInterface' => 'Bode\Tracing\Model\Config',
        'Bode\Tracing\Api\RouteNormalizerInterface' => 'Bode\Tracing\Model\RouteNormalizer',
        'Bode\Tracing\Api\PrivacyInterface' => 'Bode\Tracing\Model\Privacy',
        'Bode\Tracing\Api\TelemetryStateInterface' => 'Bode\Tracing\Model\TelemetryState',
        'Bode\Tracing\Api\TraceContextProviderInterface' => 'Bode\Tracing\Model\TraceContextProvider',
    ];

    public function testEveryApiInterfaceIsBoundToItsImplementation(): void
    {
        $preferences = [];
        foreach ($this->xpath('/config/preference') as $node) {
            $preferences[(string) $node['for']] = (string) $node['type'];
        }

        self::assertSame(self::PREFERENCES, array_intersect_key($preferences, self::PREFERENCES));
    }

    public function testEveryInterfaceOfTheApiNamespaceHasAPreference(): void
    {
        $declared = [];
        foreach (glob(dirname(__DIR__, 2) . '/Api/*Interface.php') ?: [] as $file) {
            $declared[] = self::NAMESPACE_ROOT . 'Api\\' . basename($file, '.php');
        }
        $bound = array_keys(self::PREFERENCES);
        sort($declared);
        sort($bound);

        self::assertSame(
            $bound,
            $declared,
            'A new Api interface needs a <preference> in etc/di.xml (and an entry in PREFERENCES here)'
        );
    }

    public function testPreferredAndInjectedBodeClassesExistInThePackage(): void
    {
        $classes = [];
        foreach ($this->xpath('//preference/@type | //type/@name | //plugin/@type') as $attribute) {
            $classes[] = (string) $attribute;
        }
        foreach ($this->xpath('//argument[@xsi:type="object"] | //item[@xsi:type="object"]') as $node) {
            $classes[] = trim((string) $node);
        }
        $classes = array_merge($classes, array_values($this->adminEventObservers()));

        $checked = 0;
        foreach (array_unique($classes) as $class) {
            if (!str_starts_with($class, self::NAMESPACE_ROOT)) {
                continue;
            }
            $checked++;
            self::assertFileExists($this->classFile($class), "etc/di.xml names $class, which is not in the package");
        }
        self::assertGreaterThan(5, $checked);
    }

    public function testConfiguredArgumentsExistInTheConstructor(): void
    {
        $checked = 0;
        foreach ($this->xpath('//type[arguments/argument]') as $type) {
            $class = (string) $type['name'];
            if (!str_starts_with($class, self::NAMESPACE_ROOT)) {
                continue;
            }
            $constructor = $this->constructorSource($class);
            foreach ($type->arguments->argument as $argument) {
                $checked++;
                self::assertStringContainsString(
                    '$' . $argument['name'],
                    $constructor,
                    "di.xml configures argument \${$argument['name']} of $class, which its constructor does not have"
                );
            }
        }
        self::assertGreaterThan(3, $checked);
    }

    public function testNullableScalarsAreExplicitlyNull(): void
    {
        $nullable = [
            'Bode\Tracing\Model\Environment' => 'values',
            'Bode\Tracing\Model\Config' => 'runtimeAvailable',
        ];
        foreach ($nullable as $class => $name) {
            $nodes = $this->xpath(sprintf("//type[@name='%s']/arguments/argument[@name='%s']", $class, $name));

            self::assertCount(1, $nodes, "$class::\$$name must be configured");
            self::assertSame(
                'null',
                (string) $nodes[0]->attributes('xsi', true)['type'],
                "$class::\$$name must be xsi:type=null"
            );
        }
    }

    public function testSessionsAndRepositoriesAreInjectedAsProxies(): void
    {
        $expected = [
            'Bode\Tracing\Plugin\EnrichRequestSpan' => ['customerSession', 'adminSession', 'groupRepository'],
            'Bode\Tracing\Observer\AdminAudit' => ['adminSession'],
        ];
        foreach ($expected as $class => $arguments) {
            foreach ($arguments as $name) {
                $nodes = $this->xpath(sprintf("//type[@name='%s']/arguments/argument[@name='%s']", $class, $name));

                self::assertCount(1, $nodes, "$class::\$$name must be injected");
                self::assertSame('object', (string) $nodes[0]->attributes('xsi', true)['type']);
                self::assertStringEndsWith('\Proxy', trim((string) $nodes[0]), "$class::\$$name must be a \\Proxy");
            }
        }
    }

    public function testTheLoggerGetsOnlyAProcessorAndKeepsMagentosOwnHandlers(): void
    {
        $arguments = array_map(
            static fn (SimpleXMLElement $node): string => (string) $node['name'],
            $this->xpath(sprintf("//type[@name='%s']/arguments/argument", self::LOGGER))
        );
        $items = array_map(
            static fn (SimpleXMLElement $node): string => (string) $node['name'],
            $this->xpath(sprintf("//type[@name='%s']/arguments/argument[@name='processors']/item", self::LOGGER))
        );

        self::assertSame(
            ['processors'],
            $arguments,
            'Declaring "handlers" (or any argument but "processors") replaces Magento\'s primary di.xml value'
        );
        self::assertSame(['bode_trace_context'], $items);
        self::assertSame(
            'Bode\Tracing\Model\Log\TraceContextProcessor',
            trim((string) $this->xpath(
                sprintf("//type[@name='%s']//item[@name='bode_trace_context']", self::LOGGER)
            )[0])
        );
    }

    public function testTheFrontControllerPluginIsDeclaredOnceAndEnabled(): void
    {
        $plugins = $this->xpath("//type[@name='Magento\\Framework\\App\\FrontControllerInterface']/plugin");

        self::assertCount(1, $plugins);
        self::assertSame('bode_tracing_enrich_request_span', (string) $plugins[0]['name']);
        self::assertSame('Bode\Tracing\Plugin\EnrichRequestSpan', (string) $plugins[0]['type']);
        self::assertNotSame('true', (string) $plugins[0]['disabled']);
    }

    public function testAdminObserversNameExistingClassesAndAreUniqueByName(): void
    {
        $observers = $this->adminEventObservers();

        self::assertNotSame([], $observers);
        self::assertSame(['Bode\Tracing\Observer\AdminAudit'], array_values(array_unique($observers)));
        self::assertCount(count($observers), array_unique(array_keys($observers)));
    }

    /**
     * @return array<string,string> observer name => class, from etc/adminhtml/events.xml
     */
    private function adminEventObservers(): array
    {
        $xml = new SimpleXMLElement((string) file_get_contents(dirname(__DIR__, 2) . '/etc/adminhtml/events.xml'));
        $out = [];
        foreach ($xml->xpath('//observer') ?: [] as $node) {
            $out[(string) $node['name']] = (string) $node['instance'];
        }

        return $out;
    }

    /**
     * @return SimpleXMLElement[]
     */
    private function xpath(string $expression): array
    {
        $xml = new SimpleXMLElement((string) file_get_contents(dirname(__DIR__, 2) . '/etc/di.xml'));
        $xml->registerXPathNamespace('xsi', 'http://www.w3.org/2001/XMLSchema-instance');

        return $xml->xpath($expression) ?: [];
    }

    private function classFile(string $class): string
    {
        $relative = substr($class, strlen(self::NAMESPACE_ROOT));

        return dirname(__DIR__, 2) . '/' . str_replace('\\', '/', $relative) . '.php';
    }

    /** Source of the constructor signature of a package class (the text from "function __construct" to ") {"). */
    private function constructorSource(string $class): string
    {
        $source = (string) file_get_contents($this->classFile($class));
        $found = preg_match('/function __construct\((.*?)\)\s*\{/s', $source, $m);
        self::assertSame(1, $found, "$class has no constructor");

        return $m[1];
    }
}

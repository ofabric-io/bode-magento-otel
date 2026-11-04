<?php

declare(strict_types=1);

namespace Bode\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The packages are general-purpose infrastructure published on their own. Nothing shop-specific may leak into
 * them, they may depend only on Magento, OpenTelemetry, PSR and each other, and each must carry everything
 * Composer and Magento need to install it.
 */
class PackagesTest extends TestCase
{
    /** Words that would tie a package to one shop, in fragments so this file does not trip itself. */
    private const FORBIDDEN = [
        'European' . 'Mint',
        'European ' . 'Mint',
        'best' . 'bullion',
        'Best ' . 'Bullion',
    ];

    /** package directory => [composer name, Magento module name, namespace] */
    private const PACKAGES = [
        'tracing' => ['bode/module-tracing', 'Bode_Tracing', 'Bode\\Tracing\\'],
        'metrics' => ['bode/module-metrics', 'Bode_Metrics', 'Bode\\Metrics\\'],
        'logs' => ['bode/module-logs', 'Bode_Logs', 'Bode\\Logs\\'],
    ];

    public function testPackagesNeverMentionAShop(): void
    {
        $violations = [];
        foreach ($this->files() as $file) {
            $content = (string) file_get_contents($file);
            foreach (self::FORBIDDEN as $word) {
                if (stripos($content, $word) !== false) {
                    $violations[] = substr($file, strlen($this->root()) + 1) . " contains \"$word\"";
                }
            }
        }

        self::assertSame([], $violations, 'Packages must stay vendor-neutral: configure through the environment');
    }

    public function testPackagesDependOnlyOnMagentoOpenTelemetryPsrAndEachOther(): void
    {
        $allowed = '/^(php|ext-[a-z]+|magento\/[a-z0-9-]+|monolog\/[a-z-]+|open-telemetry\/[a-z-]+'
            . '|psr\/[a-z-]+|bode\/[a-z-]+)$/';
        $violations = [];
        foreach (array_keys(self::PACKAGES) as $dir) {
            $composer = $this->composer($dir);
            foreach (array_keys(($composer['require'] ?? []) + ($composer['require-dev'] ?? [])) as $package) {
                if (!preg_match($allowed, (string) $package)) {
                    $violations[] = "$dir requires $package";
                }
            }
        }

        self::assertSame([], $violations);
    }

    public function testPackagesShipTheFilesNeededToInstallThem(): void
    {
        $missing = [];
        foreach (array_keys(self::PACKAGES) as $dir) {
            $required = [
                'composer.json',
                'README.md',
                'LICENSE',
                'THIRD_PARTY_NOTICES.md',
                'registration.php',
                'etc/module.xml',
            ];
            foreach ($required as $file) {
                if (!is_file($this->root() . "/packages/$dir/$file")) {
                    $missing[] = "$dir/$file";
                }
            }
        }

        self::assertSame([], $missing);
    }

    public function testManifestsAreConsistentWithTheModules(): void
    {
        $problems = [];
        foreach (self::PACKAGES as $dir => [$name, $module, $namespace]) {
            $composer = $this->composer($dir);
            $moduleXml = (string) file_get_contents($this->root() . "/packages/$dir/etc/module.xml");
            $registration = (string) file_get_contents($this->root() . "/packages/$dir/registration.php");

            $checks = [
                "$dir: composer name is not $name" => ($composer['name'] ?? '') === $name,
                "$dir: type is not magento2-module" => ($composer['type'] ?? '') === 'magento2-module',
                "$dir: no PSR-4 mapping for $namespace" => isset($composer['autoload']['psr-4'][$namespace]),
                "$dir: registration.php is not autoloaded"
                    => in_array('registration.php', $composer['autoload']['files'] ?? [], true),
                "$dir: module.xml does not declare $module" => str_contains($moduleXml, "name=\"$module\""),
                "$dir: registration.php does not register $module" => str_contains($registration, "'$module'"),
                "$dir: composer.json must not set a version (git tags do)" => !isset($composer['version']),
            ];
            foreach ($checks as $message => $ok) {
                $problems[] = $ok ? null : $message;
            }
        }

        self::assertSame([], array_values(array_filter($problems)));
    }

    public function testDependentPackagesFollowTracingInLockstep(): void
    {
        foreach (['metrics', 'logs'] as $dir) {
            $require = $this->composer($dir)['require'];

            self::assertSame(
                'self.version',
                $require['bode/module-tracing'] ?? null,
                "$dir: packages are released together"
            );
        }
    }

    private function root(): string
    {
        return str_replace(DIRECTORY_SEPARATOR, '/', dirname(__DIR__, 2));
    }

    /**
     * @return array<string, mixed>
     */
    private function composer(string $dir): array
    {
        $file = $this->root() . "/packages/$dir/composer.json";

        return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return string[]
     */
    private function files(): array
    {
        $dir = $this->root() . '/packages';
        $found = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            $found[] = str_replace(DIRECTORY_SEPARATOR, '/', (string) $file);
        }
        sort($found);

        return $found;
    }
}

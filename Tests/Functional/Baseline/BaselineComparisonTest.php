<?php

declare(strict_types=1);

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\PhpcrMigrationBundle\Tests\Functional\Baseline;

use Doctrine\DBAL\Connection;
use SebastianBergmann\Comparator\ComparisonFailure;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\UserInterface\Command\MigratePhpcrCommand;
use Sulu\Bundle\PhpcrMigrationBundle\Tests\Application\Kernel;
use Sulu\Bundle\PhpcrMigrationBundle\Tests\Functional\Helper\JsonBaselineExporter;
use Sulu\Bundle\PhpcrMigrationBundle\Tests\Functional\TestConnectionFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class BaselineComparisonTest extends KernelTestCase
{
    private const BASELINE_DIR = __DIR__ . '/../../Resources/baselines';

    private static ?string $tempDir = null;
    private static bool $baselinesGenerated = false;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');

        TestConnectionFactory::loadFixture($connection);

        self::runMigrationAndExport($connection);

        $baselineFiles = \glob(self::BASELINE_DIR . '/*.json');
        if (false === $baselineFiles || [] === $baselineFiles) {
            self::generateBaselines($connection);
            self::$baselinesGenerated = true;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
    }

    public static function tearDownAfterClass(): void
    {
        if (null !== self::$tempDir && \is_dir(self::$tempDir)) {
            $files = \glob(self::$tempDir . '/*');
            if (false !== $files) {
                \array_map('unlink', $files);
            }
            \rmdir(self::$tempDir);
        }

        self::$tempDir = null;
        self::$baselinesGenerated = false;

        parent::tearDownAfterClass();
    }

    private static function runMigrationAndExport(Connection $connection): void
    {
        $command = self::getContainer()->get(MigratePhpcrCommand::class);
        \assert($command instanceof MigratePhpcrCommand);

        $input = new ArrayInput([
            'documentTypes' => 'page,article,snippet,custom_url,snippet_area',
        ]);
        $output = new BufferedOutput();

        $exitCode = $command->run($input, $output);

        if (0 !== $exitCode) {
            throw new \RuntimeException('Migration command failed: ' . $output->fetch());
        }

        self::$tempDir = \sys_get_temp_dir() . '/baseline_test_' . \uniqid();
        \mkdir(self::$tempDir, 0755, true);
        $exporter = new JsonBaselineExporter($connection, self::$tempDir);
        $exporter->export();
    }

    private static function generateBaselines(Connection $connection): void
    {
        if (!\is_dir(self::BASELINE_DIR)) {
            \mkdir(self::BASELINE_DIR, 0755, true);
        }

        $exporter = new JsonBaselineExporter($connection, self::BASELINE_DIR);
        $exporter->export();
    }

    /**
     * @return \Generator<string, array{string}>
     */
    public static function tableProvider(): \Generator
    {
        $baselineFiles = \glob(self::BASELINE_DIR . '/*.json');
        if (false === $baselineFiles || [] === $baselineFiles) {
            // If no baselines exist, yield a dummy test case that will be skipped
            // This prevents "empty data set" error when baselines are being generated
            yield 'regenerate_baselines' => ['regenerate_baselines'];

            return;
        }

        $tables = [];
        foreach ($baselineFiles as $file) {
            $tables[] = \pathinfo($file, \PATHINFO_FILENAME);
        }

        \sort($tables);

        foreach ($tables as $table) {
            yield $table => [$table];
        }
    }

    /**
     * @dataProvider tableProvider
     */
    public function testTableMatchesBaseline(string $table): void
    {
        if ('regenerate_baselines' === $table) {
            if (self::$baselinesGenerated) {
                $baselineFiles = \glob(self::BASELINE_DIR . '/*.json');
                $count = false !== $baselineFiles ? \count($baselineFiles) : 0;

                \fwrite(\STDERR, \sprintf("\nTest baselines created (%d files)\n", $count));
                $this->assertTrue(true);

                return;
            }

            $this->markTestSkipped('No baseline files found. Baselines will be generated.');
        }

        if (self::$baselinesGenerated) {
            $this->markTestSkipped('Baselines were generated. Re-run tests to validate against baselines.');
        }

        $this->assertTableMatchesBaseline($table);
    }

    /**
     * @return \Generator<string, array{string, string, string}>
     */
    public static function routableResourceProvider(): \Generator
    {
        yield 'pages' => ['pa_page_dimension_contents', 'pageUuid', 'pages'];
        yield 'articles' => ['ar_article_dimension_contents', 'articleUuid', 'articles'];
    }

    /**
     * Baseline comparison excludes every `*_id` column, so it cannot see route<->dimension linkage.
     * These invariants resolve the foreign keys and assert the relationship, catching corruption
     * (e.g. a shadow linked to the source locale's route) that the snapshot blesses.
     *
     * @dataProvider routableResourceProvider
     */
    public function testDimensionContentsLinkToOwnLocaleRoute(string $dimensionTable, string $entityIdColumn, string $resourceKey): void
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');

        $crossLinked = $this->fetchCount(
            $connection,
            "SELECT COUNT(*)
             FROM {$dimensionTable} dc
             INNER JOIN ro_routes r ON r.id = dc.route_id
             WHERE dc.locale IS NOT NULL
               AND (r.locale <> dc.locale OR r.resource_id <> dc.{$entityIdColumn} OR r.resource_key <> :resourceKey)",
            ['resourceKey' => $resourceKey]
        );
        $this->assertSame(0, $crossLinked, \sprintf(
            '%d %s dimension content row(s) are linked to a route of a different locale/resource.',
            $crossLinked,
            $resourceKey
        ));

        // crossLinked uses an inner join on route_id, so a row with a NULL route_id is invisible to it.
        // Assert the reverse: whenever an own-locale route exists, the version-0 row must be linked to it,
        // catching a regression where the route is created but the dimension content link is dropped.
        $missingLinks = $this->fetchCount(
            $connection,
            "SELECT COUNT(*)
             FROM {$dimensionTable} dc
             INNER JOIN ro_routes expected
                 ON expected.resource_id = dc.{$entityIdColumn} AND expected.locale = dc.locale AND expected.resource_key = :resourceKey
             WHERE dc.locale IS NOT NULL
               AND dc.version = 0
               AND dc.route_id IS NULL",
            ['resourceKey' => $resourceKey]
        );
        $this->assertSame(0, $missingLinks, \sprintf(
            '%d %s dimension content row(s) have an own-locale route but are not linked to it.',
            $missingLinks,
            $resourceKey
        ));

        // A shadow whose source locale is routed must be linked to its own route.
        $unlinkedShadows = $this->fetchCount(
            $connection,
            "SELECT COUNT(*)
             FROM {$dimensionTable} dc
             INNER JOIN ro_routes src
                 ON src.resource_id = dc.{$entityIdColumn} AND src.locale = dc.shadowLocale AND src.resource_key = :resourceKey
             LEFT JOIN ro_routes own ON own.id = dc.route_id
             WHERE dc.locale IS NOT NULL
               AND dc.version = 0
               AND dc.shadowLocale IS NOT NULL AND dc.shadowLocale <> ''
               AND (own.id IS NULL OR own.locale <> dc.locale)",
            ['resourceKey' => $resourceKey]
        );
        $this->assertSame(0, $unlinkedShadows, \sprintf(
            '%d shadow %s dimension content row(s) with a routed source are not linked to their own route.',
            $unlinkedShadows,
            $resourceKey
        ));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function fetchCount(Connection $connection, string $sql, array $params): int
    {
        $value = $connection->fetchOne($sql, $params);
        \assert(\is_int($value) || \is_string($value));

        return (int) $value;
    }

    private const EXCLUDED_FIELDS = ['_id', 'id', 'uuid', 'changed'];

    private function assertTableMatchesBaseline(string $table): void
    {
        if (null === self::$tempDir) {
            $this->fail('Temp directory not initialized. Migration may have failed.');
        }

        $baselineFile = self::BASELINE_DIR . '/' . $table . '.json';
        $actualFile = self::$tempDir . '/' . $table . '.json';

        if (!\file_exists($baselineFile)) {
            $this->fail("Baseline not found for table '{$table}'. Re-run tests to generate baselines.");
        }

        if (!\file_exists($actualFile)) {
            $this->fail("Table '{$table}' was not exported. Check if the table exists in the database.");
        }

        $expected = $this->loadJson($baselineFile);
        $actual = $this->loadJson($actualFile);

        $expectedJson = \json_encode($expected, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $actualJson = \json_encode($actual, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        if (false === $expectedJson || false === $actualJson) {
            $this->fail('Failed to encode baseline or actual data as JSON');
        }

        if ($expectedJson === $actualJson) {
            $this->assertTrue(true);

            return;
        }

        // We do not use assertSame here to have better control over the diff output
        $comparisonFailure = new ComparisonFailure(
            $expected,
            $actual,
            $expectedJson,
            $actualJson,
            'Failed asserting that two json values are equal.'
        );

        $this->fail(
            \sprintf(
                "Table '%s' does not match baseline.\n\n%s\n\nTo update baselines, delete Tests/Resources/baselines/*.json and re-run tests.",
                $table,
                $comparisonFailure->getDiff()
            )
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadJson(string $path): array
    {
        $content = \file_get_contents($path);
        if (false === $content) {
            return [];
        }

        $data = \json_decode($content, true);
        if (!\is_array($data)) {
            return [];
        }

        $rows = \array_map(
            fn (array $row): array => $this->normalizeRow($this->removeExcludedFields($row)),
            $data
        );

        \usort($rows, fn (array $a, array $b): int => \serialize($a) <=> \serialize($b));

        return $rows;
    }

    /**
     * Normalize row data for cross-database comparison.
     * - Convert column names to lowercase (PostgreSQL returns lowercase)
     * - Normalize timestamps (remove timezone suffix)
     * - Convert booleans to integers (PostgreSQL returns true/false, MySQL returns 0/1)
     * - Trim strings (PostgreSQL may have trailing spaces)
     * - Normalize JSON strings (consistent spacing)
     * - Sort array keys for consistent JSON output.
     *
     * @param array<string|int, mixed> $row
     *
     * @return array<string|int, mixed>
     */
    private function normalizeRow(array $row): array
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            // Keep integer keys as-is (for numerically indexed arrays)
            $normalizedKey = \is_int($key) ? $key : \strtolower((string) $key);

            if (\is_bool($value)) {
                // Normalize booleans to integers for consistent comparison
                $normalized[$normalizedKey] = $value ? 1 : 0;
            } elseif (\is_array($value)) {
                $normalized[$normalizedKey] = $this->normalizeRow($value);
            } elseif (\is_string($value)) {
                $normalizedValue = \trim($value);
                // Normalize timestamps: remove timezone suffix like +00
                $normalizedValue = \preg_replace('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\+\d{2}/', '$1', $normalizedValue) ?? $normalizedValue;
                // Normalize JSON arrays (remove spaces after commas for consistent format)
                if (\str_starts_with($normalizedValue, '[') && \str_ends_with($normalizedValue, ']')) {
                    $decoded = \json_decode($normalizedValue, true);
                    if (\is_array($decoded)) {
                        $normalizedValue = \json_encode($decoded, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
                        if (false === $normalizedValue) {
                            $normalizedValue = \trim($value);
                        }
                    }
                }
                $normalized[$normalizedKey] = $normalizedValue;
            } else {
                $normalized[$normalizedKey] = $value;
            }
        }

        // Sort by keys for consistent ordering (handles JSON key order differences)
        \ksort($normalized);

        return $normalized;
    }

    /**
     * @param array<string|int, mixed> $data
     *
     * @return array<string|int, mixed>
     */
    private function removeExcludedFields(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $keyStr = (string) $key;
            // Exclude specific fields and foreign key columns (ending with _id)
            if (\in_array($keyStr, self::EXCLUDED_FIELDS, true) || \str_ends_with($keyStr, '_id')) {
                continue;
            }
            if (\is_array($value)) {
                $result[$key] = $this->removeExcludedFields($value);
            } elseif (\is_string($value) && ('{' === ($value[0] ?? '') || '[' === ($value[0] ?? ''))) {
                $decoded = \json_decode($value, true);
                $result[$key] = \is_array($decoded) ? $this->removeExcludedFields($decoded) : $value;
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}

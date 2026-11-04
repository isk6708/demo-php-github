<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AnalyticsTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        initDB($this->pdo);
    }

    private function rows(array $overrides): array
    {
        return array_map(
            static fn(array $row): array => array_merge([
                'id'          => 1,
                'name'        => 'Item',
                'description' => '',
                'price'       => 10.0,
                'quantity'    => 10,
                'category'    => 'General',
                'reorder_level' => 10,
                'created_at'  => '2026-01-01 00:00:00',
            ], $row),
            $overrides
        );
    }

    public function testSchemaIncludesInventoryColumns(): void
    {
        $columns = [];
        foreach ($this->pdo->query("PRAGMA table_info(products)") as $row) {
            $columns[] = $row['name'];
        }

        $this->assertContains('category', $columns);
        $this->assertContains('reorder_level', $columns);
    }

    public function testSeedDataStillCountsTenProducts(): void
    {
        $count = (int)$this->pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
        $this->assertSame(10, $count);
    }

    public function testSeedDataHasCategoriesAndReorderLevels(): void
    {
        $stmt = $this->pdo->query(
            "SELECT COUNT(*) FROM products WHERE category IS NOT NULL AND category <> ''
             AND reorder_level IS NOT NULL AND reorder_level > 0"
        );

        $this->assertSame(10, (int)$stmt->fetchColumn());
    }

    public function testMigrateDBAddsMissingColumnsToLegacySchema(): void
    {
        $legacy = new PDO('sqlite::memory:');
        $legacy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $legacy->exec("
            CREATE TABLE products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                description TEXT,
                price REAL NOT NULL,
                quantity INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $legacy->exec("INSERT INTO products (name, price, quantity) VALUES ('Legacy', 5.0, 3)");

        migrateDB($legacy);

        $columns = [];
        foreach ($legacy->query("PRAGMA table_info(products)") as $row) {
            $columns[] = $row['name'];
        }

        $this->assertContains('category', $columns);
        $this->assertContains('reorder_level', $columns);

        $row = $legacy->query("SELECT * FROM products WHERE name = 'Legacy'")->fetch();
        $this->assertSame('General', $row['category']);
        $this->assertSame(10, (int)$row['reorder_level']);
    }

    public function testMigrateDBIsIdempotent(): void
    {
        migrateDB($this->pdo);
        migrateDB($this->pdo);

        $count = (int)$this->pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
        $this->assertSame(10, $count);
    }

    public function testStockStatusOutOfStock(): void
    {
        $this->assertSame('out_of_stock', stockStatus(0, 10));
    }

    public function testStockStatusLowWhenAtOrBelowReorderLevel(): void
    {
        $this->assertSame('low_stock', stockStatus(5, 10));
        $this->assertSame('low_stock', stockStatus(10, 10));
    }

    public function testStockStatusHealthyBetweenLevels(): void
    {
        $this->assertSame('healthy', stockStatus(11, 10));
        $this->assertSame('healthy', stockStatus(29, 10));
    }

    public function testStockStatusOverstockAtTripleReorderLevel(): void
    {
        $this->assertSame('overstock', stockStatus(30, 10));
        $this->assertSame('overstock', stockStatus(100, 10));
    }

    public function testStockStatusTreatsZeroReorderLevelAsHealthy(): void
    {
        $this->assertSame('healthy', stockStatus(5, 0));
    }

    public function testStockStatusMetaCoversEveryBucket(): void
    {
        foreach (STOCK_STATUSES as $status) {
            $meta = stockStatusMeta($status);
            $this->assertNotSame('', $meta['label']);
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $meta['color']);
        }
    }

    public function testBuildInventorySummaryAggregatesTotals(): void
    {
        $rows = $this->rows([
            ['price' => 10.0, 'quantity' => 5],
            ['price' => 20.0, 'quantity' => 3],
        ]);

        $summary = buildInventorySummary($rows);

        $this->assertSame(2, $summary['sku_count']);
        $this->assertSame(8, $summary['total_units']);
        $this->assertEquals(110.0, $summary['inventory_value']);
        $this->assertEquals(15.0, $summary['avg_unit_price']);
    }

    public function testBuildInventorySummaryBucketsStock(): void
    {
        $rows = $this->rows([
            ['quantity' => 0,  'reorder_level' => 10],
            ['quantity' => 4,  'reorder_level' => 10],
            ['quantity' => 20, 'reorder_level' => 10],
            ['quantity' => 80, 'reorder_level' => 10],
        ]);

        $summary = buildInventorySummary($rows);

        $this->assertSame(1, $summary['out_count']);
        $this->assertSame(1, $summary['low_count']);
        $this->assertSame(1, $summary['healthy_count']);
        $this->assertSame(1, $summary['overstock_count']);
    }

    public function testBuildInventorySummaryHandlesEmptyInput(): void
    {
        $summary = buildInventorySummary([]);

        $this->assertSame(0, $summary['sku_count']);
        $this->assertSame(0, $summary['total_units']);
        $this->assertEquals(0.0, $summary['inventory_value']);
        $this->assertEquals(0.0, $summary['avg_unit_price']);
    }

    public function testCountByStockStatusReturnsEveryBucket(): void
    {
        $counts = countByStockStatus([]);

        $this->assertSame(
            ['out_of_stock', 'low_stock', 'healthy', 'overstock'],
            array_keys($counts)
        );
        $this->assertSame(0, $counts['healthy']);
    }

    public function testCountByStockStatusCountsRows(): void
    {
        $rows = $this->rows([
            ['quantity' => 0,  'reorder_level' => 10],
            ['quantity' => 4,  'reorder_level' => 10],
            ['quantity' => 20, 'reorder_level' => 10],
        ]);

        $counts = countByStockStatus($rows);

        $this->assertSame(1, $counts['out_of_stock']);
        $this->assertSame(1, $counts['low_stock']);
        $this->assertSame(1, $counts['healthy']);
        $this->assertSame(0, $counts['overstock']);
    }

    public function testValueByCategoryAggregatesAndSorts(): void
    {
        $rows = $this->rows([
            ['category' => 'Audio',    'price' => 10.0, 'quantity' => 2],
            ['category' => 'Audio',    'price' => 10.0, 'quantity' => 3],
            ['category' => 'Storage',  'price' => 50.0, 'quantity' => 1],
        ]);

        $result = valueByCategory($rows);

        $this->assertCount(2, $result);
        $this->assertSame('Audio', $result[0]['name']);
        $this->assertEquals(50.0, $result[0]['value']);
        $this->assertSame(5, $result[0]['units']);
        $this->assertSame('Storage', $result[1]['name']);
        $this->assertEquals(50.0, $result[1]['value']);
    }

    public function testValueByCategoryLabelsMissingCategory(): void
    {
        $result = valueByCategory($this->rows([['category' => '  ']]));

        $this->assertSame('Uncategorised', $result[0]['name']);
    }

    public function testBuildDailySeriesProducesConsecutiveDays(): void
    {
        $series = buildDailySeries($this->rows([]), 7);

        $this->assertCount(7, $series);
        $this->assertSame(
            date('Y-m-d', strtotime($series[0]['date'] . ' +1 day')),
            $series[1]['date']
        );
    }

    public function testBuildDailySeriesBucketsUnitsByCreationDate(): void
    {
        $today = date('Y-m-d');
        $rows  = $this->rows([
            ['created_at' => $today . ' 09:00:00', 'quantity' => 5],
            ['created_at' => $today . ' 14:00:00', 'quantity' => 3],
        ]);

        $series = buildDailySeries($rows, 3);
        $last   = $series[count($series) - 1];

        $this->assertSame($today, $last['date']);
        $this->assertSame(8, $last['units']);
    }

    public function testBuildDailySeriesFillsEmptyDaysWithZero(): void
    {
        $series = buildDailySeries($this->rows([]), 5);

        foreach ($series as $point) {
            $this->assertSame(0, $point['units']);
        }
    }

    public function testBuildDailySeriesAlwaysContainsAtLeastOneDay(): void
    {
        $series = buildDailySeries([], 0);

        $this->assertGreaterThanOrEqual(1, count($series));
    }

    public function testBuildRestockListPrioritisesOutOfStock(): void
    {
        $rows = $this->rows([
            ['name' => 'Low item',    'quantity' => 2, 'reorder_level' => 10],
            ['name' => 'Empty item',  'quantity' => 0, 'reorder_level' => 10],
            ['name' => 'Healthy item','quantity' => 50, 'reorder_level' => 10],
        ]);

        $list = buildRestockList($rows);

        $this->assertCount(2, $list);
        $this->assertSame('Empty item', $list[0]['name']);
        $this->assertSame('out_of_stock', $list[0]['stock_status']);
        $this->assertSame('Low item', $list[1]['name']);
    }

    public function testBuildRestockListComputesShortfallAndSuggestion(): void
    {
        $rows = $this->rows([['quantity' => 4, 'reorder_level' => 10]]);

        $list = buildRestockList($rows);

        $this->assertSame(6, $list[0]['shortfall']);
        $this->assertSame(6, $list[0]['suggested_qty']);
    }

    public function testBuildRestockListExcludesHealthyItems(): void
    {
        $list = buildRestockList($this->rows([['quantity' => 100, 'reorder_level' => 10]]));

        $this->assertSame([], $list);
    }

    public function testBuildRestockListRespectsLimit(): void
    {
        $rows = [];
        for ($i = 0; $i < 12; $i++) {
            $rows[] = ['name' => 'Item ' . $i, 'quantity' => 0, 'reorder_level' => 10];
        }

        $this->assertCount(8, buildRestockList($this->rows($rows), 8));
    }

    public function testBuildDonutSlicesProducesPathsForEveryBucket(): void
    {
        $slices = buildDonutSlices([
            'out_of_stock' => 1,
            'low_stock'    => 2,
            'healthy'      => 5,
            'overstock'    => 2,
        ]);

        $this->assertCount(4, $slices);

        foreach ($slices as $slice) {
            $this->assertNotSame('', $slice['path']);
            $this->assertStringStartsWith('M ', $slice['path']);
            $this->assertStringEndsWith('Z', $slice['path']);
        }
    }

    public function testBuildDonutSlicesPercentagesSumToOneHundred(): void
    {
        $slices = buildDonutSlices(['healthy' => 3, 'low_stock' => 1]);

        $total = 0.0;
        foreach ($slices as $slice) {
            $total += $slice['pct'];
        }

        $this->assertEqualsWithDelta(100.0, $total, 0.2);
    }

    public function testBuildDonutSlicesReturnsEmptyForNoData(): void
    {
        $this->assertSame([], buildDonutSlices(['healthy' => 0, 'low_stock' => 0]));
    }

    public function testDonutSlicePathHandlesFullCircle(): void
    {
        $path = donutSlicePath(120, 120, 100, 62, -90, 270);

        $this->assertNotSame('', $path);
        $this->assertStringStartsWith('M ', $path);
    }

    public function testDonutSlicePathReturnsEmptyForZeroSweep(): void
    {
        $this->assertSame('', donutSlicePath(120, 120, 100, 62, 10, 10));
    }

    public function testBuildLineChartProducesPathAndPoints(): void
    {
        $series = [
            ['label' => 'Day 1', 'units' => 10],
            ['label' => 'Day 2', 'units' => 20],
            ['label' => 'Day 3', 'units' => 5],
        ];

        $chart = buildLineChart($series);

        $this->assertCount(3, $chart['points']);
        $this->assertNotSame('', $chart['path']);
        $this->assertStringContainsString('L ', $chart['path']);
        $this->assertGreaterThan(0, $chart['max']);
    }

    public function testBuildLineChartHandlesEmptySeries(): void
    {
        $chart = buildLineChart([]);

        $this->assertSame([], $chart['points']);
        $this->assertSame('', $chart['path']);
        $this->assertSame('', $chart['area']);
    }

    public function testBuildLineChartClampsZeroMax(): void
    {
        $chart = buildLineChart([['label' => 'Day 1', 'units' => 0]]);

        $this->assertGreaterThan(0, $chart['max']);
        $this->assertNotSame('', $chart['path']);
    }

    public function testBuildStockBarsSortsByUnitsDescending(): void
    {
        $rows = $this->rows([
            ['name' => 'Small', 'quantity' => 2,  'reorder_level' => 10],
            ['name' => 'Large', 'quantity' => 40, 'reorder_level' => 10],
            ['name' => 'Mid',   'quantity' => 15, 'reorder_level' => 10],
        ]);

        $bars = buildStockBars($rows);

        $this->assertSame('Large', $bars[0]['name']);
        $this->assertSame('Small', $bars[2]['name']);
        $this->assertEquals(100.0, $bars[0]['pct']);
        $this->assertEquals(5.0, $bars[2]['pct']);
    }

    public function testBuildStockBarsColoursByStatus(): void
    {
        $rows = $this->rows([
            ['name' => 'Empty', 'quantity' => 0, 'reorder_level' => 10],
            ['name' => 'Fine',  'quantity' => 20, 'reorder_level' => 10],
        ]);

        $bars = buildStockBars($rows);

        $byName = [];
        foreach ($bars as $bar) {
            $byName[$bar['name']] = $bar['color'];
        }

        $this->assertSame('#e11d48', $byName['Empty']);
        $this->assertSame('#10b981', $byName['Fine']);
    }

    public function testBuildStockBarsRespectsLimit(): void
    {
        $rows = [];
        for ($i = 0; $i < 15; $i++) {
            $rows[] = ['name' => 'Item ' . $i, 'quantity' => $i];
        }

        $this->assertCount(8, buildStockBars($this->rows($rows), 8));
    }
}

<?php
declare(strict_types=1);

const STOCK_STATUSES = ['out_of_stock', 'low_stock', 'healthy', 'overstock'];

function stockStatusMeta(string $status): array
{
    return match ($status) {
        'out_of_stock' => ['label' => 'Out of Stock', 'color' => '#e11d48', 'tone' => 'danger'],
        'low_stock'    => ['label' => 'Low Stock',    'color' => '#f59e0b', 'tone' => 'warning'],
        'overstock'    => ['label' => 'Overstock',    'color' => '#6366f1', 'tone' => 'info'],
        default        => ['label' => 'Healthy',      'color' => '#10b981', 'tone' => 'success'],
    };
}

function stockStatus(int $quantity, int $reorderLevel): string
{
    if ($quantity <= 0) {
        return 'out_of_stock';
    }
    if ($reorderLevel > 0 && $quantity <= $reorderLevel) {
        return 'low_stock';
    }
    if ($reorderLevel > 0 && $quantity >= $reorderLevel * 3) {
        return 'overstock';
    }
    return 'healthy';
}

function buildInventorySummary(array $products): array
{
    $summary = [
        'sku_count'       => count($products),
        'total_units'     => 0,
        'inventory_value' => 0.0,
        'avg_unit_price'  => 0.0,
        'low_count'       => 0,
        'out_count'       => 0,
        'overstock_count' => 0,
        'healthy_count'   => 0,
    ];

    $priceSum = 0.0;

    foreach ($products as $product) {
        $quantity = (int)($product['quantity'] ?? 0);
        $price    = (float)($product['price'] ?? 0);
        $reorder  = (int)($product['reorder_level'] ?? 0);

        $summary['total_units']     += $quantity;
        $summary['inventory_value'] += $price * $quantity;
        $priceSum                  += $price;

        match (stockStatus($quantity, $reorder)) {
            'out_of_stock' => $summary['out_count']++,
            'low_stock'    => $summary['low_count']++,
            'overstock'    => $summary['overstock_count']++,
            default        => $summary['healthy_count']++,
        };
    }

    if ($summary['sku_count'] > 0) {
        $summary['avg_unit_price'] = $priceSum / $summary['sku_count'];
    }

    return $summary;
}

function countByStockStatus(array $products): array
{
    $counts = array_fill_keys(STOCK_STATUSES, 0);

    foreach ($products as $product) {
        $status = stockStatus(
            (int)($product['quantity'] ?? 0),
            (int)($product['reorder_level'] ?? 0)
        );
        $counts[$status]++;
    }

    return $counts;
}

function valueByCategory(array $products): array
{
    $buckets = [];

    foreach ($products as $product) {
        $category = trim((string)($product['category'] ?? ''));
        if ($category === '') {
            $category = 'Uncategorised';
        }

        $quantity = (int)($product['quantity'] ?? 0);
        $value    = (float)($product['price'] ?? 0) * $quantity;

        if (!isset($buckets[$category])) {
            $buckets[$category] = ['name' => $category, 'value' => 0.0, 'units' => 0];
        }

        $buckets[$category]['value'] += $value;
        $buckets[$category]['units'] += $quantity;
    }

    uasort(
        $buckets,
        static fn(array $a, array $b): int => $b['value'] <=> $a['value']
            ?: strcmp($a['name'], $b['name'])
    );

    return array_values($buckets);
}

function buildDailySeries(array $products, int $days = 30): array
{
    $days = max(1, $days);
    $end  = time();

    $oldest = $end;
    foreach ($products as $product) {
        $timestamp = strtotime((string)($product['created_at'] ?? '')) ?: $end;
        $oldest = min($oldest, $timestamp);
    }

    $span = (int)ceil(($end - $oldest) / 86400) + 1;
    $days = min(max($days, $span), 180);

    $start = $end - (($days - 1) * 86400);
    $start = (int)strtotime(date('Y-m-d', $start) . ' 00:00:00');

    $series = [];
    for ($i = 0; $i < $days; $i++) {
        $dayStart = $start + ($i * 86400);
        $series[$dayStart] = [
            'date'  => date('Y-m-d', $dayStart),
            'label' => date('M j', $dayStart),
            'units' => 0,
            'value' => 0.0,
        ];
    }

    foreach ($products as $product) {
        $timestamp = strtotime((string)($product['created_at'] ?? ''));
        if ($timestamp === false) {
            continue;
        }
        $dayStart = (int)strtotime(date('Y-m-d', $timestamp) . ' 00:00:00');

        if (!isset($series[$dayStart])) {
            continue;
        }

        $quantity = (int)($product['quantity'] ?? 0);
        $series[$dayStart]['units'] += $quantity;
        $series[$dayStart]['value'] += (float)($product['price'] ?? 0) * $quantity;
    }

    return array_values($series);
}

function buildRestockList(array $products, int $limit = 8): array
{
    $ranked = [];

    foreach ($products as $product) {
        $quantity = (int)($product['quantity'] ?? 0);
        $reorder  = (int)($product['reorder_level'] ?? 0);
        $status   = stockStatus($quantity, $reorder);

        if ($status !== 'out_of_stock' && $status !== 'low_stock') {
            continue;
        }

        $product['stock_status']  = $status;
        $product['shortfall']     = max(0, $reorder - $quantity);
        $product['suggested_qty'] = max($reorder - $quantity, (int)ceil($reorder * 0.5));
        $ranked[] = $product;
    }

    usort(
        $ranked,
        static function (array $a, array $b): int {
            $priority = ['out_of_stock' => 0, 'low_stock' => 1];
            return ($priority[$a['stock_status']] ?? 2) <=> ($priority[$b['stock_status']] ?? 2)
                ?: $b['shortfall'] <=> $a['shortfall']
                ?: strcmp((string)$a['name'], (string)$b['name']);
        }
    );

    return array_slice($ranked, 0, max(0, $limit));
}

function buildDonutSlices(array $counts, int $center = 120, int $centerY = 120, int $radius = 100): array
{
    $total = array_sum($counts);
    $slices = [];

    if ($total <= 0) {
        return $slices;
    }

    $inner = (int)round($radius * 0.62);
    $angle = -90.0;

    foreach (STOCK_STATUSES as $status) {
        $value = (int)($counts[$status] ?? 0);
        if ($value <= 0) {
            continue;
        }

        $sweep = ($value / $total) * 360.0;
        $end   = $angle + $sweep;
        $meta  = stockStatusMeta($status);

        $slices[] = [
            'status' => $status,
            'label'  => $meta['label'],
            'value'  => $value,
            'color'  => $meta['color'],
            'path'   => donutSlicePath($center, $centerY, $radius, $inner, $angle, $end),
            'pct'    => round(($value / $total) * 100, 1),
        ];

        $angle = $end;
    }

    return $slices;
}

function donutSlicePath(int $cx, int $cy, int $outer, int $inner, float $start, float $end): string
{
    $sweep = $end - $start;
    if ($sweep <= 0) {
        return '';
    }
    if ($sweep >= 359.999) {
        $sweep = 359.999;
    }

    $largeArc = $sweep > 180.0 ? 1 : 0;

    $x1 = $cx + ($outer * cos(deg2rad($start)));
    $y1 = $cy + ($outer * sin(deg2rad($start)));
    $x2 = $cx + ($outer * cos(deg2rad($start + $sweep)));
    $y2 = $cy + ($outer * sin(deg2rad($start + $sweep)));
    $x3 = $cx + ($inner * cos(deg2rad($start + $sweep)));
    $y3 = $cy + ($inner * sin(deg2rad($start + $sweep)));
    $x4 = $cx + ($inner * cos(deg2rad($start)));
    $y4 = $cy + ($inner * sin(deg2rad($start)));

    return sprintf(
        'M %.2f %.2f A %d %d 0 %d 1 %.2f %.2f L %.2f %.2f A %d %d 0 %d 0 %.2f %.2f Z',
        $x1,
        $y1,
        $outer,
        $outer,
        $largeArc,
        $x2,
        $y2,
        $x3,
        $y3,
        $inner,
        $inner,
        $largeArc,
        $x4,
        $y4
    );
}

function buildLineChart(array $series, int $width = 700, int $height = 240, int $padding = 28): array
{
    $count = count($series);
    if ($count === 0) {
        return ['points' => [], 'max' => 0, 'path' => '', 'area' => ''];
    }

    $max = 0;
    foreach ($series as $point) {
        $max = max($max, (int)$point['units']);
    }
    if ($max <= 0) {
        $max = 1;
    }

    $max = (int)(ceil($max / 4) * 4);

    $innerWidth  = $width - ($padding * 2);
    $innerHeight = $height - ($padding * 2);
    $step        = $count > 1 ? $innerWidth / ($count - 1) : 0.0;

    $points = [];
    foreach ($series as $index => $point) {
        $x = $count > 1 ? $padding + ($index * $step) : ($padding + ($innerWidth / 2));
        $y = $padding + $innerHeight - ((((int)$point['units']) / $max) * $innerHeight);
        $points[] = [
            'x'     => round($x, 2),
            'y'     => round($y, 2),
            'label' => (string)$point['label'],
            'value' => (int)$point['units'],
        ];
    }

    $line = '';
    foreach ($points as $index => $point) {
        $line .= ($index === 0 ? 'M ' : 'L ') . $point['x'] . ' ' . $point['y'] . ' ';
    }

    $baseline = $padding + $innerHeight;
    $first    = $points[0];
    $last     = $points[count($points) - 1];
    $area     = $line
        . 'L ' . $last['x'] . ' ' . $baseline . ' '
        . 'L ' . $first['x'] . ' ' . $baseline . ' Z';

    return [
        'points' => $points,
        'max'    => $max,
        'path'   => trim($line),
        'area'   => trim($area),
    ];
}

function buildStockBars(array $products, int $limit = 8): array
{
    $entries = [];
    $max     = 0;

    foreach ($products as $product) {
        $units   = (int)($product['quantity'] ?? 0);
        $reorder = (int)($product['reorder_level'] ?? 0);
        $max     = max($max, $units);
        $entries[] = [
            'name'  => (string)($product['name'] ?? ''),
            'units' => $units,
            'pct'   => 0.0,
            'color' => stockStatusMeta(stockStatus($units, $reorder))['color'],
        ];
    }

    usort(
        $entries,
        static fn(array $a, array $b): int => $b['units'] <=> $a['units']
            ?: strcmp($a['name'], $b['name'])
    );

    $entries = array_slice($entries, 0, max(0, $limit));

    if ($max > 0) {
        foreach ($entries as $index => $entry) {
            $entries[$index]['pct'] = round(($entry['units'] / $max) * 100, 1);
        }
    }

    return $entries;
}

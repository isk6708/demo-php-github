<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/analytics.php';

session_start();

$pdo = getDB();
$message = '';
$messageType = 'success';

$adminPassword = getenv('ADMIN_PASSWORD') ?: '';

if (($_GET['action'] ?? '') === 'logout') {
    unset($_SESSION['admin_authenticated']);
    session_regenerate_id(true);
    header('Location: admin.php');
    exit;
}

if ($adminPassword !== '') {
    if (empty($_SESSION['admin_authenticated'])) {
        $loginError = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['op'] ?? '') === 'login') {
            if (hash_equals($adminPassword, (string)($_POST['password'] ?? ''))) {
                session_regenerate_id(true);
                $_SESSION['admin_authenticated'] = true;
                header('Location: admin.php');
                exit;
            }
            $loginError = 'Incorrect password.';
        }

        renderLogin($loginError);
        exit;
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['csrf_token'];

function csrfField(string $token): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
}

function csrfIsValid(?string $token, string $expected): bool
{
    return is_string($token) && $token !== '' && hash_equals($expected, $token);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $op = $_POST['op'] ?? '';

        if (!csrfIsValid($_POST['csrf_token'] ?? null, $csrfToken)) {
            throw new RuntimeException('Invalid or expired form token. Please try again.');
        }

        if ($op === 'adjust_stock') {
            $id       = (int)($_POST['id'] ?? 0);
            $delta    = (int)($_POST['delta'] ?? 0);
            $reorder  = max(0, (int)($_POST['reorder_level'] ?? 0));

            if ($id <= 0) {
                throw new RuntimeException('Invalid product id.');
            }
            if ($delta === 0) {
                throw new RuntimeException('Adjustment quantity must not be zero.');
            }

            $stmt = $pdo->prepare(
                "UPDATE products SET quantity = MAX(0, quantity + ?), reorder_level = ? WHERE id = ?"
            );
            $stmt->execute([$delta, $reorder, $id]);

            $message = $delta > 0
                ? "Stock increased by {$delta} units."
                : "Stock reduced by " . abs($delta) . " units.";

            $_SESSION['flash_message'] = $message;
            $_SESSION['flash_type']    = 'success';
            header('Location: admin.php');
            exit;
        }

        if ($op === 'update_reorder') {
            $id      = (int)($_POST['id'] ?? 0);
            $reorder = max(0, (int)($_POST['reorder_level'] ?? 0));

            $stmt = $pdo->prepare("UPDATE products SET reorder_level = ? WHERE id = ?");
            $stmt->execute([$reorder, $id]);

            $_SESSION['flash_message'] = 'Reorder level updated.';
            $_SESSION['flash_type']    = 'success';
            header('Location: admin.php');
            exit;
        }
    } catch (Throwable $e) {
        $message     = 'Error: ' . $e->getMessage();
        $messageType = 'error';
    }
}

if (isset($_SESSION['flash_message'])) {
    $message     = (string)$_SESSION['flash_message'];
    $messageType = (string)($_SESSION['flash_type'] ?? 'success');
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

$search   = trim((string)($_GET['q'] ?? ''));
$category = trim((string)($_GET['category'] ?? ''));
$filter   = (string)($_GET['status'] ?? '');

$sql = "SELECT * FROM products WHERE 1=1";
$params = [];

if ($search !== '') {
    $sql .= " AND (name LIKE :search OR description LIKE :search OR category LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if ($category !== '') {
    $sql .= " AND category = :category";
    $params[':category'] = $category;
}

$sql .= " ORDER BY name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$allProducts = $pdo->query("SELECT * FROM products")->fetchAll();
$categories  = $pdo->query("SELECT DISTINCT category FROM products ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN);

if ($filter !== '') {
    $products = array_values(array_filter(
        $products,
        static fn(array $p): bool => stockStatus(
            (int)$p['quantity'],
            (int)($p['reorder_level'] ?? 0)
        ) === $filter
    ));
}

$summary    = buildInventorySummary($allProducts);
$statusCounts = countByStockStatus($allProducts);
$donut      = buildDonutSlices($statusCounts);
$series     = buildDailySeries($allProducts, 30);
$lineChart  = buildLineChart($series);
$categoriesValue = valueByCategory($allProducts);
$stockBars  = buildStockBars($allProducts, 8);
$restock    = buildRestockList($allProducts, 8);

$maxCategoryValue = 0.0;
foreach ($categoriesValue as $row) {
    $maxCategoryValue = max($maxCategoryValue, $row['value']);
}

function renderLogin(string $error): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Login - Product Manager</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
               background: #0f172a; margin: 0; min-height: 100vh; display: flex;
               align-items: center; justify-content: center; color: #e2e8f0; }
        .login-card { background: #1e293b; padding: 32px; border-radius: 12px;
                      width: 340px; box-shadow: 0 20px 50px rgba(0,0,0,0.45); }
        h1 { margin: 0 0 6px; font-size: 20px; }
        p.sub { color: #94a3b8; font-size: 13px; margin: 0 0 20px; }
        label { font-weight: 600; display: block; margin-bottom: 6px; font-size: 13px; }
        input { width: 100%; padding: 10px; border: 1px solid #334155; border-radius: 6px;
                background: #0f172a; color: #e2e8f0; font-size: 14px; margin-bottom: 16px; }
        button { width: 100%; background: #3b82f6; color: #fff; border: none; padding: 11px;
                 border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600; }
        button:hover { background: #2563eb; }
        .error { background: #7f1d1d; color: #fecaca; padding: 9px 12px;
                 border-radius: 6px; margin-bottom: 16px; font-size: 13px; }
    </style>
</head>
<body>
    <div class="login-card">
        <h1>Admin Dashboard</h1>
        <p class="sub">Sign in to continue.</p>
        <?php if ($error !== ''): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="op" value="login">
            <label for="password">Passphrase</label>
            <input type="password" id="password" name="password" required autofocus>
            <button type="submit">Sign in</button>
        </form>
    </div>
</body>
</html>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Dashboard - Product Manager</title>
    <style>
        :root {
            --bg: #0f172a;
            --surface: #1e293b;
            --surface-2: #273449;
            --border: #334155;
            --text: #e2e8f0;
            --muted: #94a3b8;
            --accent: #3b82f6;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #e11d48;
            --info: #6366f1;
        }
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
               background: var(--bg); margin: 0; padding: 0; color: var(--text); }
        a { color: inherit; }
        .topbar { background: var(--surface); border-bottom: 1px solid var(--border);
                  padding: 14px 24px; display: flex; align-items: center;
                  justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        .topbar h1 { margin: 0; font-size: 18px; letter-spacing: -0.01em; }
        .topbar .sub { color: var(--muted); font-size: 12px; margin-top: 2px; }
        .topbar-actions { display: flex; gap: 10px; align-items: center; }
        .btn { display: inline-flex; align-items: center; gap: 6px; background: var(--surface-2);
               color: var(--text); border: 1px solid var(--border); padding: 8px 14px;
               border-radius: 8px; cursor: pointer; text-decoration: none; font-size: 13px;
               font-weight: 500; transition: background .15s ease, border-color .15s ease; }
        .btn:hover { background: var(--border); }
        .btn-primary { background: var(--accent); border-color: var(--accent); color: #fff; }
        .btn-primary:hover { background: #2563eb; border-color: #2563eb; }
        .container { max-width: 1400px; margin: 0 auto; padding: 24px; }

        .message { padding: 11px 15px; border-radius: 8px; margin-bottom: 18px; font-size: 14px; }
        .message.success { background: rgba(16,185,129,.14); color: #6ee7b7; border: 1px solid rgba(16,185,129,.35); }
        .message.error   { background: rgba(225,29,72,.14);  color: #fda4af; border: 1px solid rgba(225,29,72,.35); }

        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
                    gap: 16px; margin-bottom: 22px; }
        .kpi { background: var(--surface); border: 1px solid var(--border); border-radius: 12px;
               padding: 18px 20px; position: relative; overflow: hidden; }
        .kpi::after { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px;
                      background: var(--accent); }
        .kpi.success::after { background: var(--success); }
        .kpi.warning::after { background: var(--warning); }
        .kpi.danger::after  { background: var(--danger); }
        .kpi.info::after    { background: var(--info); }
        .kpi .label { color: var(--muted); font-size: 12px; text-transform: uppercase;
                      letter-spacing: .06em; font-weight: 600; }
        .kpi .value { font-size: 27px; font-weight: 700; margin-top: 8px; letter-spacing: -0.02em; }
        .kpi .hint  { color: var(--muted); font-size: 12px; margin-top: 4px; }

        .grid-2 { display: grid; grid-template-columns: 1.35fr 1fr; gap: 16px; margin-bottom: 16px; }
        .grid-2.equal { grid-template-columns: 1fr 1fr; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px;
                padding: 20px; }
        .card h2 { margin: 0 0 4px; font-size: 15px; font-weight: 650; }
        .card .card-sub { color: var(--muted); font-size: 12.5px; margin-bottom: 16px; }
        .legend { display: flex; flex-direction: column; gap: 10px; }
        .legend-item { display: flex; align-items: center; gap: 10px; font-size: 13px; }
        .legend-dot { width: 11px; height: 11px; border-radius: 3px; flex: none; }
        .legend-label { flex: 1; color: var(--muted); }
        .legend-value { font-weight: 650; }

        .donut-wrap { display: flex; align-items: center; gap: 24px; flex-wrap: wrap; }
        .donut-center { text-align: center; }
        .donut-center .big { font-size: 26px; font-weight: 700; }
        .donut-center .small { color: var(--muted); font-size: 12px; }

        .chart-svg { width: 100%; height: auto; display: block; overflow: visible; }
        .grid-line { stroke: var(--border); stroke-width: 1; }
        .axis-label { fill: var(--muted); font-size: 10px; }

        .bars { display: flex; flex-direction: column; gap: 12px; }
        .bar-row { display: grid; grid-template-columns: 150px 1fr 52px; align-items: center; gap: 12px; }
        .bar-name { font-size: 12.5px; color: var(--text); overflow: hidden;
                    text-overflow: ellipsis; white-space: nowrap; }
        .bar-track { background: var(--surface-2); border-radius: 5px; height: 22px; overflow: hidden; }
        .bar-fill { height: 100%; border-radius: 5px; transition: width .3s ease; }
        .bar-value { font-size: 12.5px; color: var(--muted); text-align: right; font-variant-numeric: tabular-nums; }

        .toolbar { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 16px; }
        .toolbar input[type=text], .toolbar select { padding: 8px 11px; border: 1px solid var(--border);
            border-radius: 8px; background: var(--surface-2); color: var(--text); font-size: 13px; }
        .toolbar input[type=text] { min-width: 220px; }
        .toolbar form { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }

        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 11px 12px; text-align: left; border-bottom: 1px solid var(--border);
                 font-size: 13px; }
        th { color: var(--muted); font-size: 11px; text-transform: uppercase; letter-spacing: .06em;
             font-weight: 650; white-space: nowrap; }
        tbody tr:hover { background: rgba(255,255,255,.025); }
        tbody tr.row-out { background: rgba(225,29,72,.09); }
        tbody tr.row-low { background: rgba(245,158,11,.07); }
        .num { font-variant-numeric: tabular-nums; }
        .badge { display: inline-block; padding: 3px 9px; border-radius: 999px; font-size: 11px;
                 font-weight: 650; white-space: nowrap; }
        .badge.danger  { background: rgba(225,29,72,.16);  color: #fda4af; }
        .badge.warning { background: rgba(245,158,11,.16); color: #fcd34d; }
        .badge.success { background: rgba(16,185,129,.16); color: #6ee7b7; }
        .badge.info    { background: rgba(99,102,241,.16); color: #a5b4fc; }
        .cat-pill { background: var(--surface-2); color: var(--muted); padding: 3px 9px;
                    border-radius: 6px; font-size: 11.5px; white-space: nowrap; }
        .stock-cell { display: flex; align-items: center; gap: 9px; }
        .stock-bar { width: 58px; height: 6px; background: var(--surface-2);
                     border-radius: 3px; overflow: hidden; flex: none; }
        .stock-bar span { display: block; height: 100%; border-radius: 3px; }
        .adjust { display: inline-flex; gap: 4px; align-items: center; }
        .adjust input[type=number] { width: 62px; padding: 5px 7px; border: 1px solid var(--border);
            border-radius: 6px; background: var(--surface-2); color: var(--text); font-size: 12px; }
        .adjust button { padding: 5px 10px; font-size: 12px; }
        .empty { text-align: center; color: var(--muted); padding: 36px 12px; font-size: 14px; }
        .restock-row td { vertical-align: middle; }
        .priority { font-weight: 700; font-variant-numeric: tabular-nums; }
        footer { color: var(--muted); font-size: 12px; text-align: center; padding: 24px; }
        @media (max-width: 1000px) {
            .grid-2, .grid-2.equal { grid-template-columns: 1fr; }
            .bar-row { grid-template-columns: 110px 1fr 46px; }
        }
        @media (max-width: 640px) {
            .container { padding: 16px; }
            .kpi .value { font-size: 23px; }
        }
    </style>
</head>
<body>
<div class="topbar">
    <div>
        <h1>Admin Dashboard</h1>
        <div class="sub">Inventory analytics &amp; stock control</div>
    </div>
    <div class="topbar-actions">
        <a href="index.php" class="btn">&larr; Product Manager</a>
        <?php if ($adminPassword !== ''): ?>
            <a href="admin.php?action=logout" class="btn">Sign out</a>
        <?php endif; ?>
    </div>
</div>

<div class="container">
    <?php if ($message !== ''): ?>
        <div class="message <?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="kpi-grid">
        <div class="kpi">
            <div class="label">Inventory Value</div>
            <div class="value">$<?= number_format($summary['inventory_value'], 2) ?></div>
            <div class="hint"><?= number_format($summary['total_units']) ?> units on hand</div>
        </div>
        <div class="kpi info">
            <div class="label">Active SKUs</div>
            <div class="value"><?= number_format($summary['sku_count']) ?></div>
            <div class="hint">Avg $<?= number_format($summary['avg_unit_price'], 2) ?> per unit</div>
        </div>
        <div class="kpi danger">
            <div class="label">Out of Stock</div>
            <div class="value"><?= number_format($summary['out_count']) ?></div>
            <div class="hint">Need immediate restock</div>
        </div>
        <div class="kpi warning">
            <div class="label">Low Stock</div>
            <div class="value"><?= number_format($summary['low_count']) ?></div>
            <div class="hint">At or below reorder level</div>
        </div>
        <div class="kpi success">
            <div class="label">Healthy</div>
            <div class="value"><?= number_format($summary['healthy_count']) ?></div>
            <div class="hint">Within target range</div>
        </div>
    </div>

    <div class="grid-2">
        <div class="card">
            <h2>Stock On Hand Trend</h2>
            <div class="card-sub">Units added per day across the trailing 30-day window</div>
            <?php if ($lineChart['path'] !== ''): ?>
                <svg class="chart-svg" viewBox="0 0 700 240" role="img"
                     aria-label="Line chart of units on hand per day" preserveAspectRatio="none">
                    <?php
                    $chartHeight = 240;
                    $chartPad    = 28;
                    $innerH      = $chartHeight - ($chartPad * 2);
                    for ($g = 0; $g <= 4; $g++) {
                        $gy = $chartPad + ($innerH * ($g / 4));
                        $gv = (int)round($lineChart['max'] * (1 - ($g / 4)));
                        ?>
                        <line class="grid-line" x1="<?= $chartPad ?>" y1="<?= round($gy, 2) ?>"
                              x2="<?= 700 - $chartPad ?>" y2="<?= round($gy, 2) ?>"></line>
                        <text class="axis-label" x="<?= $chartPad - 6 ?>" y="<?= round($gy + 3, 2) ?>"
                              text-anchor="end"><?= $gv ?></text>
                        <?php
                    }
                    ?>
                    <defs>
                        <linearGradient id="areaFill" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%"   stop-color="#3b82f6" stop-opacity="0.38"></stop>
                            <stop offset="100%" stop-color="#3b82f6" stop-opacity="0"></stop>
                        </linearGradient>
                    </defs>
                    <path d="<?= htmlspecialchars($lineChart['area']) ?>" fill="url(#areaFill)"></path>
                    <path d="<?= htmlspecialchars($lineChart['path']) ?>" fill="none"
                          stroke="#3b82f6" stroke-width="2.5" stroke-linejoin="round"
                          stroke-linecap="round"></path>
                    <?php
                    foreach ($lineChart['points'] as $index => $point) {
                        $labelEvery = max(1, (int)ceil(count($lineChart['points']) / 8));
                        ?>
                        <?php if ($index % $labelEvery === 0 || $index === count($lineChart['points']) - 1): ?>
                            <circle cx="<?= $point['x'] ?>" cy="<?= $point['y'] ?>" r="3"
                                    fill="#0f172a" stroke="#3b82f6" stroke-width="2">
                                <title><?= htmlspecialchars($point['label'] . ': ' . $point['value'] . ' units') ?></title>
                            </circle>
                            <text class="axis-label" x="<?= $point['x'] ?>" y="<?= $chartHeight - 8 ?>"
                                  text-anchor="middle"><?= htmlspecialchars($point['label']) ?></text>
                        <?php endif; ?>
                    <?php
                    } ?>
                </svg>
            <?php else: ?>
                <div class="empty">No stock movement recorded yet.</div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Stock Health</h2>
            <div class="card-sub">Distribution of SKUs by stock status</div>
            <div class="donut-wrap">
                <div class="donut-center">
                    <svg class="chart-svg" viewBox="0 0 240 240" style="max-width:190px"
                         role="img" aria-label="Donut chart of stock health">
                        <?php if ($donut === []): ?>
                            <circle cx="120" cy="120" r="80" fill="none" stroke="#273449" stroke-width="38"></circle>
                        <?php else: ?>
                            <?php foreach ($donut as $slice): ?>
                                <path d="<?= htmlspecialchars($slice['path']) ?>"
                                      fill="<?= htmlspecialchars($slice['color']) ?>"
                                      stroke="#1e293b" stroke-width="2">
                                    <title><?= htmlspecialchars($slice['label'] . ': ' . $slice['value'] . ' (' . $slice['pct'] . '%)') ?></title>
                                </path>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </svg>
                </div>
                <div class="legend">
                    <?php foreach (STOCK_STATUSES as $status): ?>
                        <?php $meta = stockStatusMeta($status); ?>
                        <div class="legend-item">
                            <span class="legend-dot" style="background:<?= $meta['color'] ?>"></span>
                            <span class="legend-label"><?= htmlspecialchars($meta['label']) ?></span>
                            <span class="legend-value"><?= number_format($statusCounts[$status]) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="grid-2 equal">
        <div class="card">
            <h2>Inventory Value by Category</h2>
            <div class="card-sub">Total stock value grouped by product category</div>
            <?php if ($categoriesValue === []): ?>
                <div class="empty">No categories to display.</div>
            <?php else: ?>
                <div class="bars">
                    <?php foreach ($categoriesValue as $row): ?>
                        <?php
                        $pct = $maxCategoryValue > 0
                            ? round(($row['value'] / $maxCategoryValue) * 100, 1)
                            : 0;
                        ?>
                        <div class="bar-row">
                            <div class="bar-name" title="<?= htmlspecialchars($row['name']) ?>">
                                <?= htmlspecialchars($row['name']) ?>
                            </div>
                            <div class="bar-track">
                                <div class="bar-fill" style="width:<?= $pct ?>%;background:#6366f1"></div>
                            </div>
                            <div class="bar-value">$<?= number_format($row['value'], 0) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Top Stock Levels</h2>
            <div class="card-sub">Highest unit counts, coloured by stock status</div>
            <?php if ($stockBars === []): ?>
                <div class="empty">No products to display.</div>
            <?php else: ?>
                <div class="bars">
                    <?php foreach ($stockBars as $bar): ?>
                        <div class="bar-row">
                            <div class="bar-name" title="<?= htmlspecialchars($bar['name']) ?>">
                                <?= htmlspecialchars($bar['name']) ?>
                            </div>
                            <div class="bar-track">
                                <div class="bar-fill"
                                     style="width:<?= $bar['pct'] ?>%;background:<?= htmlspecialchars($bar['color']) ?>"></div>
                            </div>
                            <div class="bar-value"><?= number_format($bar['units']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card" style="margin-bottom:16px;">
        <h2>Restock Recommendations</h2>
        <div class="card-sub">Items at or below their reorder level, ordered by urgency</div>
        <?php if ($restock === []): ?>
            <div class="empty">All stock levels are healthy. Nothing needs restocking.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Category</th>
                        <th class="num">On Hand</th>
                        <th class="num">Reorder Level</th>
                        <th class="num">Shortfall</th>
                        <th class="num">Suggested Order</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($restock as $item): ?>
                        <?php $meta = stockStatusMeta($item['stock_status']); ?>
                        <tr class="restock-row">
                            <td><?= htmlspecialchars($item['name']) ?></td>
                            <td><span class="cat-pill"><?= htmlspecialchars($item['category'] ?? 'Uncategorised') ?></span></td>
                            <td class="num priority" style="color:<?= $meta['color'] ?>">
                                <?= (int)$item['quantity'] ?>
                            </td>
                            <td class="num"><?= (int)$item['reorder_level'] ?></td>
                            <td class="num"><?= (int)$item['shortfall'] ?></td>
                            <td class="num"><?= (int)$item['suggested_qty'] ?></td>
                            <td><span class="badge <?= $meta['tone'] ?>"><?= htmlspecialchars($meta['label']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Inventory</h2>
        <div class="card-sub"><?= number_format(count($products)) ?> product(s) shown</div>

        <div class="toolbar">
            <form method="get" action="admin.php">
                <input type="text" name="q" placeholder="Search name, description or category..."
                       value="<?= htmlspecialchars($search) ?>">
                <select name="category">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat) ?>"
                            <?= $category === $cat ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="status">
                    <option value="">All statuses</option>
                    <?php foreach (STOCK_STATUSES as $status): ?>
                        <?php $meta = stockStatusMeta($status); ?>
                        <option value="<?= $status ?>" <?= $filter === $status ? 'selected' : '' ?>>
                            <?= htmlspecialchars($meta['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary">Apply filters</button>
                <?php if ($search !== '' || $category !== '' || $filter !== ''): ?>
                    <a href="admin.php" class="btn">Reset</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if ($products === []): ?>
            <div class="empty">No products match the current filters.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Category</th>
                        <th class="num">Unit Price</th>
                        <th class="num">Stock Value</th>
                        <th>Stock Level</th>
                        <th class="num">Reorder</th>
                        <th>Status</th>
                        <th>Adjust Stock</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $p): ?>
                        <?php
                        $quantity = (int)$p['quantity'];
                        $reorder  = (int)($p['reorder_level'] ?? 0);
                        $status   = stockStatus($quantity, $reorder);
                        $meta     = stockStatusMeta($status);
                        $pct      = $reorder > 0
                            ? min(100, round(($quantity / max($reorder, 1)) * 50, 1))
                            : ($quantity > 0 ? 100 : 0);
                        $rowClass = $status === 'out_of_stock'
                            ? 'row-out'
                            : ($status === 'low_stock' ? 'row-low' : '');
                        ?>
                        <tr class="<?= $rowClass ?>">
                            <td>
                                <strong><?= htmlspecialchars($p['name']) ?></strong>
                                <?php if (!empty($p['description'])): ?>
                                    <div style="color:var(--muted);font-size:12px;">
                                        <?= htmlspecialchars($p['description']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><span class="cat-pill"><?= htmlspecialchars($p['category'] ?? 'Uncategorised') ?></span></td>
                            <td class="num">$<?= number_format((float)$p['price'], 2) ?></td>
                            <td class="num">$<?= number_format((float)$p['price'] * $quantity, 2) ?></td>
                            <td>
                                <div class="stock-cell">
                                    <span class="num" style="min-width:28px"><?= $quantity ?></span>
                                    <span class="stock-bar">
                                        <span style="width:<?= $pct ?>%;background:<?= $meta['color'] ?>"></span>
                                    </span>
                                </div>
                            </td>
                            <td class="num"><?= $reorder ?></td>
                            <td><span class="badge <?= $meta['tone'] ?>"><?= htmlspecialchars($meta['label']) ?></span></td>
                            <td>
                                <form class="adjust" method="post" action="admin.php">
                                    <?= csrfField($csrfToken) ?>
                                    <input type="hidden" name="op" value="adjust_stock">
                                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                    <input type="hidden" name="reorder_level" value="<?= $reorder ?>">
                                    <input type="number" name="delta" placeholder="+/-"
                                           aria-label="Stock adjustment for <?= htmlspecialchars($p['name']) ?>">
                                    <button type="submit" class="btn">Apply</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <footer>
        Product Manager &middot; Admin Dashboard &middot;
        <?= htmlspecialchars(date('F j, Y')) ?>
    </footer>
</div>
</body>
</html>

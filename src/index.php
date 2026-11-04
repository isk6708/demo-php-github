<?php
require_once __DIR__ . '/db.php';

$pdo = getDB();
$action = $_GET['action'] ?? 'list';
$message = '';

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $op = $_POST['op'] ?? '';

        if ($op === 'create') {
            $stmt = $pdo->prepare("INSERT INTO products (name, description, price, quantity) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                trim($_POST['name']),
                trim($_POST['description']),
                (float)$_POST['price'],
                (int)$_POST['quantity'],
            ]);
            $message = 'Product created successfully!';
        } elseif ($op === 'update') {
            $stmt = $pdo->prepare("UPDATE products SET name=?, description=?, price=?, quantity=? WHERE id=?");
            $stmt->execute([
                trim($_POST['name']),
                trim($_POST['description']),
                (float)$_POST['price'],
                (int)$_POST['quantity'],
                (int)$_POST['id'],
            ]);
            $message = 'Product updated successfully!';
        } elseif ($op === 'delete') {
            $stmt = $pdo->prepare("DELETE FROM products WHERE id=?");
            $stmt->execute([(int)$_POST['id']]);
            $message = 'Product deleted successfully!';
        }
    } catch (Throwable $e) {
        $message = 'Error: ' . $e->getMessage();
    }
}

$editProduct = null;
if (($action === 'edit') && isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id=?");
    $stmt->execute([(int)$_GET['id']]);
    $editProduct = $stmt->fetch();
}

$products = $pdo->query("SELECT * FROM products ORDER BY id DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Product Manager - CRUD App</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
               background: #f4f6f9; margin: 0; padding: 20px; color: #222; }
        h1 { color: #2c3e50; }
        .container { max-width: 1100px; margin: 0 auto; }
        .card { background: #fff; border-radius: 8px; padding: 20px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #2c3e50; color: #fff; }
        tr:hover { background: #f9f9f9; }
        input, textarea { width: 100%; padding: 8px; border: 1px solid #ccc;
                          border-radius: 4px; margin-bottom: 10px; font-size: 14px; }
        label { font-weight: 600; display: block; margin-bottom: 4px; font-size: 13px; }
        button, .btn { background: #3498db; color: #fff; border: none;
                       padding: 8px 14px; border-radius: 4px; cursor: pointer;
                       text-decoration: none; display: inline-block; font-size: 13px; }
        button:hover, .btn:hover { background: #2980b9; }
        .btn-danger { background: #e74c3c; }
        .btn-danger:hover { background: #c0392b; }
        .btn-warning { background: #f39c12; }
        .btn-warning:hover { background: #e67e22; }
        .message { background: #d4edda; color: #155724; padding: 10px 14px;
                   border-radius: 4px; margin-bottom: 15px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .actions { display: flex; gap: 6px; }
        @media (max-width: 700px) { .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="container">
    <h1>📦 Product Manager - CRUD App Saya Using Git Pull</h1>

    <?php if ($message): ?>
        <div class="message"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="card">
        <h2><?= $editProduct ? 'Edit Product' : 'Add New Product' ?></h2>
        <form method="post">
            <input type="hidden" name="op" value="<?= $editProduct ? 'update' : 'create' ?>">
            <?php if ($editProduct): ?>
                <input type="hidden" name="id" value="<?= (int)$editProduct['id'] ?>">
            <?php endif; ?>
            <div class="grid">
                <div>
                    <label>Name</label>
                    <input type="text" name="name" required
                           value="<?= htmlspecialchars($editProduct['name'] ?? '') ?>">
                </div>
                <div>
                    <label>Price</label>
                    <input type="number" name="price" step="0.01" min="0" required
                           value="<?= htmlspecialchars($editProduct['price'] ?? '') ?>">
                </div>
            </div>
            <label>Description</label>
            <textarea name="description" rows="2"><?= htmlspecialchars($editProduct['description'] ?? '') ?></textarea>
            <label>Quantity</label>
            <input type="number" name="quantity" min="0" required
                   value="<?= htmlspecialchars($editProduct['quantity'] ?? 0) ?>">
            <button type="submit"><?= $editProduct ? 'Update Product' : 'Create Product' ?></button>
            <?php if ($editProduct): ?>
                <a href="index.php" class="btn" style="background:#95a5a6;">Cancel</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <h2>Products (<?= count($products) ?>)</h2>
        <table>
            <thead>
                <tr>
                    <th>ID</th><th>Name</th><th>Description</th>
                    <th>Price</th><th>Qty</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($products as $p): ?>
                <tr>
                    <td><?= (int)$p['id'] ?></td>
                    <td><?= htmlspecialchars($p['name']) ?></td>
                    <td><?= htmlspecialchars($p['description']) ?></td>
                    <td>$<?= number_format($p['price'], 2) ?></td>
                    <td><?= (int)$p['quantity'] ?></td>
                    <td class="actions">
                        <a class="btn btn-warning" href="index.php?action=edit&id=<?= (int)$p['id'] ?>">Edit</a>
                        <form method="post" style="display:inline"
                              onsubmit="return confirm('Delete this product?');">
                            <input type="hidden" name="op" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <button type="submit" class="btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
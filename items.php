<?php
require_once __DIR__ . '/includes/functions.php';
require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add') {
            $itemName = trim($_POST['item_name'] ?? '');
            $moq = (int) ($_POST['moq'] ?? 0);

            if ($itemName === '' || $moq < 0) {
                throw new RuntimeException('Please enter a valid item name and MOQ.');
            }

            $pdo->beginTransaction();
            $temporaryCode = 'TMP-' . bin2hex(random_bytes(8));
            $stmt = $pdo->prepare('INSERT INTO master_item (item_code, item_name, moq) VALUES (?, ?, ?)');
            $stmt->execute([$temporaryCode, $itemName, $moq]);
            $itemId = (int) $pdo->lastInsertId();
            $itemCode = 'ITM-' . str_pad((string) $itemId, 3, '0', STR_PAD_LEFT);

            $stmt = $pdo->prepare('UPDATE master_item SET item_code = ? WHERE item_id = ?');
            $stmt->execute([$itemCode, $itemId]);

            // Compatible with both fresh v3 and upgraded v2 databases.
            $columns = $pdo->query("SHOW COLUMNS FROM master_inventory LIKE 'unit_price'")->fetch();
            if ($columns) {
                $stmt = $pdo->prepare('INSERT INTO master_inventory (item_id, qty, unit_price) VALUES (?, 0, 0)');
            } else {
                $stmt = $pdo->prepare('INSERT INTO master_inventory (item_id, qty) VALUES (?, 0)');
            }
            $stmt->execute([$itemId]);
            $pdo->commit();
            flash('success', 'Item added successfully as ' . $itemCode . '.');
        } elseif ($action === 'update') {
            $itemId = (int) ($_POST['item_id'] ?? 0);
            $itemName = trim($_POST['item_name'] ?? '');
            $moq = (int) ($_POST['moq'] ?? 0);

            if (!$itemId || $itemName === '' || $moq < 0) {
                throw new RuntimeException('Invalid item data.');
            }

            $stmt = $pdo->prepare('UPDATE master_item SET item_name = ?, moq = ? WHERE item_id = ?');
            $stmt->execute([$itemName, $moq, $itemId]);
            flash('success', 'Item updated successfully.');
        } elseif ($action === 'delete') {
            $itemId = (int) ($_POST['item_id'] ?? 0);
            $stmt = $pdo->prepare('UPDATE master_item SET is_active = 0 WHERE item_id = ?');
            $stmt->execute([$itemId]);
            flash('success', 'Item removed from the active item list. Historical transactions are preserved.');
        } elseif ($action === 'restore') {
            $itemId = (int) ($_POST['item_id'] ?? 0);
            $stmt = $pdo->prepare('UPDATE master_item SET is_active = 1 WHERE item_id = ?');
            $stmt->execute([$itemId]);
            flash('success', 'Item restored.');
        }
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ((int) ($ex->errorInfo[1] ?? 0) === 1062) {
            flash('danger', 'Item code already exists.');
        } else {
            flash('danger', 'Database error: ' . $ex->getMessage());
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('danger', $ex->getMessage());
    }
    redirect('items.php');
}

$editItem = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT item_id, item_code, item_name, moq, is_active FROM master_item WHERE item_id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $editItem = $stmt->fetch() ?: null;
}

$nextId = (int) $pdo->query('SELECT COALESCE(MAX(item_id), 0) + 1 FROM master_item')->fetchColumn();
$nextItemCode = 'ITM-' . str_pad((string) $nextId, 3, '0', STR_PAD_LEFT);

$items = $pdo->query(
    'SELECT mi.item_id, mi.item_code, mi.item_name, mi.moq, mi.is_active, inv.qty,
            COUNT(CASE WHEN ib.qty_remaining > 0 THEN 1 END) AS active_batches,
            MIN(CASE WHEN ib.qty_remaining > 0 THEN ib.sale_price END) AS min_sale_price,
            MAX(CASE WHEN ib.qty_remaining > 0 THEN ib.sale_price END) AS max_sale_price
     FROM master_item mi
     JOIN master_inventory inv ON inv.item_id = mi.item_id
     LEFT JOIN inventory_batch ib ON ib.item_id = mi.item_id
     GROUP BY mi.item_id, mi.item_code, mi.item_name, mi.moq, mi.is_active, inv.qty
     ORDER BY mi.is_active DESC, mi.item_name'
)->fetchAll();

$batches = $pdo->query(
    'SELECT ib.batch_id, ib.receipt_id, ib.qty_received, ib.qty_remaining, ib.unit_cost, ib.sale_price,
            ib.received_date, mi.item_code, mi.item_name
     FROM inventory_batch ib
     JOIN master_item mi ON mi.item_id = ib.item_id
     WHERE ib.qty_remaining > 0
     ORDER BY mi.item_name, ib.received_date, ib.batch_id'
)->fetchAll();

$pageTitle = 'Items';
require __DIR__ . '/includes/header.php';
?>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-white"><strong><?= $editItem ? 'Update Item' : 'Add Item' ?></strong></div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="<?= $editItem ? 'update' : 'add' ?>">
                    <?php if ($editItem): ?><input type="hidden" name="item_id" value="<?= (int) $editItem['item_id'] ?>"><?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label">Item Code</label>
                        <input class="form-control" value="<?= e($editItem['item_code'] ?? $nextItemCode) ?>" readonly>
                        <div class="form-text">Generated automatically by the system.</div>
                    </div>
                    <div class="mb-3"><label class="form-label">Item Name</label><input class="form-control" name="item_name" value="<?= e($editItem['item_name'] ?? '') ?>" required></div>
                    <div class="mb-3"><label class="form-label">MOQ / Reorder Level</label><input type="number" min="0" class="form-control" name="moq" value="<?= e((string) ($editItem['moq'] ?? 0)) ?>" required></div>
                    <div class="alert alert-info py-2 small">Purchase cost and selling price are entered per stock batch under <strong>Item Receivables</strong>.</div>
                    <button class="btn btn-primary" type="submit"><?= $editItem ? 'Update' : 'Add Item' ?></button>
                    <?php if ($editItem): ?><a class="btn btn-outline-secondary" href="items.php">Cancel</a><?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card">
            <div class="card-header bg-white"><strong>Item Master</strong></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light"><tr><th>Code</th><th>Item</th><th class="text-end">MOQ</th><th class="text-end">Total Qty</th><th class="text-end">Batches</th><th>Sale Price Range</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($items as $row): ?>
                            <tr>
                                <td><?= e($row['item_code']) ?></td>
                                <td><?= e($row['item_name']) ?></td>
                                <td class="text-end"><?= (int) $row['moq'] ?></td>
                                <td class="text-end"><?= (int) $row['qty'] ?></td>
                                <td class="text-end"><?= (int) $row['active_batches'] ?></td>
                                <td>
                                    <?php if ($row['min_sale_price'] === null): ?>
                                        <span class="text-muted">No stock</span>
                                    <?php elseif ((float) $row['min_sale_price'] === (float) $row['max_sale_price']): ?>
                                        <?= e(money($row['min_sale_price'])) ?>
                                    <?php else: ?>
                                        <?= e(money($row['min_sale_price'])) ?> – <?= e(money($row['max_sale_price'])) ?>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge text-bg-<?= (int) $row['is_active'] === 1 ? 'success' : 'secondary' ?>"><?= (int) $row['is_active'] === 1 ? 'Active' : 'Deleted' ?></span></td>
                                <td class="text-end text-nowrap">
                                    <a class="btn btn-sm btn-outline-primary" href="items.php?edit=<?= (int) $row['item_id'] ?>">Edit</a>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="<?= (int) $row['is_active'] === 1 ? 'delete' : 'restore' ?>">
                                        <input type="hidden" name="item_id" value="<?= (int) $row['item_id'] ?>">
                                        <button class="btn btn-sm btn-outline-<?= (int) $row['is_active'] === 1 ? 'danger' : 'success' ?>" data-confirm="<?= (int) $row['is_active'] === 1 ? 'Delete this item from the active list?' : 'Restore this item?' ?>">
                                            <?= (int) $row['is_active'] === 1 ? 'Delete' : 'Restore' ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$items): ?><tr><td colspan="8" class="text-center text-muted py-4">No items found.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header bg-white"><strong>Current Inventory by Batch</strong></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light"><tr><th>Batch</th><th>Item</th><th>Received</th><th class="text-end">Received Qty</th><th class="text-end">Remaining</th><th class="text-end">Unit Cost</th><th class="text-end">Selling Price</th><th class="text-end">Margin / Unit</th></tr></thead>
                        <tbody>
                        <?php foreach ($batches as $batch): ?>
                            <tr>
                                <td>#<?= (int) $batch['batch_id'] ?><?= $batch['receipt_id'] ? '' : ' <span class="badge text-bg-secondary">Migrated</span>' ?></td>
                                <td><?= e($batch['item_code'] . ' - ' . $batch['item_name']) ?></td>
                                <td><?= e(date('d M Y H:i', strtotime($batch['received_date']))) ?></td>
                                <td class="text-end"><?= (int) $batch['qty_received'] ?></td>
                                <td class="text-end fw-semibold"><?= (int) $batch['qty_remaining'] ?></td>
                                <td class="text-end"><?= e(money($batch['unit_cost'])) ?></td>
                                <td class="text-end"><?= e(money($batch['sale_price'])) ?></td>
                                <td class="text-end"><?= e(money((float) $batch['sale_price'] - (float) $batch['unit_cost'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$batches): ?><tr><td colspan="8" class="text-center text-muted py-4">No stock batches available.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>

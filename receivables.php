<?php
require_once __DIR__ . '/includes/functions.php';
require_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add') {
            $itemId = (int) ($_POST['item_id'] ?? 0);
            $qty = (int) ($_POST['received_quantity'] ?? 0);
            $unitCost = (float) ($_POST['unit_price'] ?? 0);
            $salePrice = (float) ($_POST['sale_price'] ?? 0);
            $receivedDate = $_POST['received_date'] ?? date('Y-m-d\TH:i');

            if ($itemId <= 0 || $qty <= 0 || $unitCost < 0 || $salePrice < 0) {
                throw new RuntimeException('Select an item and enter valid quantity, cost and selling price.');
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT item_id FROM master_item WHERE item_id = ? AND is_active = 1 FOR UPDATE');
            $stmt->execute([$itemId]);
            if (!$stmt->fetch()) throw new RuntimeException('Item not found or inactive.');

            $dateSql = date('Y-m-d H:i:s', strtotime($receivedDate));
            $stmt = $pdo->prepare('INSERT INTO transaction_order (item_id, received_quantity, unit_price, sale_price, received_date, received_by) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$itemId, $qty, $unitCost, $salePrice, $dateSql, current_user()['user_id']]);
            $receiptId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare('INSERT INTO inventory_batch (receipt_id, item_id, qty_received, qty_remaining, unit_cost, sale_price, received_date) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$receiptId, $itemId, $qty, $qty, $unitCost, $salePrice, $dateSql]);

            // On upgraded v2 DBs unit_price remains as a legacy column. Keep it as latest cost.
            $hasLegacyUnitPrice = (bool) $pdo->query("SHOW COLUMNS FROM master_inventory LIKE 'unit_price'")->fetch();
            if ($hasLegacyUnitPrice) {
                $stmt = $pdo->prepare('UPDATE master_inventory SET qty = qty + ?, unit_price = ?, updated_at = NOW() WHERE item_id = ?');
                $stmt->execute([$qty, $unitCost, $itemId]);
            } else {
                $stmt = $pdo->prepare('UPDATE master_inventory SET qty = qty + ?, updated_at = NOW() WHERE item_id = ?');
                $stmt->execute([$qty, $itemId]);
            }

            $pdo->commit();
            flash('success', 'Stock received as a new price batch.');
        } elseif ($action === 'update') {
            if (!is_admin()) throw new RuntimeException('Only an administrator can update receipts.');

            $receiptId = (int) ($_POST['receipt_id'] ?? 0);
            $qty = (int) ($_POST['received_quantity'] ?? 0);
            $unitCost = (float) ($_POST['unit_price'] ?? 0);
            $salePrice = (float) ($_POST['sale_price'] ?? 0);
            $receivedDate = $_POST['received_date'] ?? '';
            if ($receiptId <= 0 || $qty <= 0 || $unitCost < 0 || $salePrice < 0 || $receivedDate === '') {
                throw new RuntimeException('Invalid receipt data.');
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'SELECT tr.*, ib.batch_id, ib.qty_received, ib.qty_remaining
                 FROM transaction_order tr
                 JOIN inventory_batch ib ON ib.receipt_id = tr.receipt_id
                 WHERE tr.receipt_id = ? FOR UPDATE'
            );
            $stmt->execute([$receiptId]);
            $old = $stmt->fetch();
            if (!$old) throw new RuntimeException('Receipt/batch not found. Migrated opening batches cannot be edited here.');

            $soldQty = (int) $old['qty_received'] - (int) $old['qty_remaining'];
            if ($qty < $soldQty) {
                throw new RuntimeException('Cannot reduce received quantity below ' . $soldQty . ' because those units have already been sold.');
            }

            $newRemaining = $qty - $soldQty;
            $remainingDelta = $newRemaining - (int) $old['qty_remaining'];
            $dateSql = date('Y-m-d H:i:s', strtotime($receivedDate));

            $stmt = $pdo->prepare('UPDATE transaction_order SET received_quantity = ?, unit_price = ?, sale_price = ?, received_date = ? WHERE receipt_id = ?');
            $stmt->execute([$qty, $unitCost, $salePrice, $dateSql, $receiptId]);

            $stmt = $pdo->prepare('UPDATE inventory_batch SET qty_received = ?, qty_remaining = ?, unit_cost = ?, sale_price = ?, received_date = ? WHERE batch_id = ?');
            $stmt->execute([$qty, $newRemaining, $unitCost, $salePrice, $dateSql, $old['batch_id']]);

            if ($remainingDelta >= 0) {
                $stmt = $pdo->prepare('UPDATE master_inventory SET qty = qty + ?, updated_at = NOW() WHERE item_id = ?');
                $stmt->execute([$remainingDelta, $old['item_id']]);
            } else {
                $reduceBy = abs($remainingDelta);
                $stmt = $pdo->prepare('UPDATE master_inventory SET qty = qty - ?, updated_at = NOW() WHERE item_id = ? AND qty >= ?');
                $stmt->execute([$reduceBy, $old['item_id'], $reduceBy]);
                if ($stmt->rowCount() !== 1) throw new RuntimeException('Inventory quantity changed. Please try the update again.');
            }

            $pdo->commit();
            flash('success', 'Receipt and remaining batch stock updated. Historical sold units keep their original recorded cost and selling price.');
        } elseif ($action === 'delete') {
            if (!is_admin()) throw new RuntimeException('Only an administrator can delete receipts.');

            $receiptId = (int) ($_POST['receipt_id'] ?? 0);
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'SELECT tr.item_id, ib.batch_id, ib.qty_received, ib.qty_remaining
                 FROM transaction_order tr
                 JOIN inventory_batch ib ON ib.receipt_id = tr.receipt_id
                 WHERE tr.receipt_id = ? FOR UPDATE'
            );
            $stmt->execute([$receiptId]);
            $receipt = $stmt->fetch();
            if (!$receipt) throw new RuntimeException('Receipt/batch not found.');

            if ((int) $receipt['qty_remaining'] !== (int) $receipt['qty_received']) {
                throw new RuntimeException('This receipt cannot be deleted because some units from this batch have already been sold.');
            }

            $reverseQty = (int) $receipt['qty_remaining'];
            $stmt = $pdo->prepare('UPDATE master_inventory SET qty = qty - ?, updated_at = NOW() WHERE item_id = ? AND qty >= ?');
            $stmt->execute([$reverseQty, $receipt['item_id'], $reverseQty]);
            if ($stmt->rowCount() !== 1) throw new RuntimeException('Inventory quantity changed. Please try the delete again.');

            // inventory_batch is deleted automatically through ON DELETE CASCADE.
            $stmt = $pdo->prepare('DELETE FROM transaction_order WHERE receipt_id = ?');
            $stmt->execute([$receiptId]);
            $pdo->commit();
            flash('success', 'Receipt deleted and its unsold batch stock reversed.');
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('danger', $ex->getMessage());
    }
    redirect('receivables.php');
}

$editReceipt = null;
if (is_admin() && isset($_GET['edit'])) {
    $stmt = $pdo->prepare(
        'SELECT tr.*, ib.batch_id, ib.qty_remaining
         FROM transaction_order tr
         JOIN inventory_batch ib ON ib.receipt_id = tr.receipt_id
         WHERE tr.receipt_id = ?'
    );
    $stmt->execute([(int) $_GET['edit']]);
    $editReceipt = $stmt->fetch() ?: null;
}

$items = $pdo->query('SELECT item_id, item_code, item_name FROM master_item WHERE is_active = 1 ORDER BY item_name')->fetchAll();
$receipts = $pdo->query(
    'SELECT tr.receipt_id, tr.item_id, tr.received_quantity, tr.unit_price, tr.sale_price, tr.received_date,
            mi.item_code, mi.item_name, u.name AS received_by_name,
            ib.batch_id, ib.qty_remaining
     FROM transaction_order tr
     JOIN master_item mi ON mi.item_id = tr.item_id
     JOIN user_master u ON u.user_id = tr.received_by
     LEFT JOIN inventory_batch ib ON ib.receipt_id = tr.receipt_id
     ORDER BY tr.received_date DESC, tr.receipt_id DESC
     LIMIT 200'
)->fetchAll();

$pageTitle = 'Item Receivables';
require __DIR__ . '/includes/header.php';
?>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-white"><strong><?= $editReceipt ? 'Update Receipt / Batch' : 'Receive Stock' ?></strong></div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="<?= $editReceipt ? 'update' : 'add' ?>">
                    <?php if ($editReceipt): ?><input type="hidden" name="receipt_id" value="<?= (int) $editReceipt['receipt_id'] ?>"><?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label">Item</label>
                        <?php if ($editReceipt): ?>
                            <?php $selected = array_values(array_filter($items, fn($x) => (int) $x['item_id'] === (int) $editReceipt['item_id'])); ?>
                            <input class="form-control" value="<?= e(($selected[0]['item_code'] ?? '') . ' - ' . ($selected[0]['item_name'] ?? '')) ?>" disabled>
                        <?php else: ?>
                            <select class="form-select" name="item_id" required><option value="">Select item</option><?php foreach ($items as $item): ?><option value="<?= (int) $item['item_id'] ?>"><?= e($item['item_code'] . ' - ' . $item['item_name']) ?></option><?php endforeach; ?></select>
                        <?php endif; ?>
                    </div>
                    <div class="mb-3"><label class="form-label">Received Quantity</label><input type="number" min="1" class="form-control" name="received_quantity" value="<?= e((string) ($editReceipt['received_quantity'] ?? '')) ?>" required></div>
                    <div class="mb-3"><label class="form-label">Purchase Unit Cost (LKR)</label><input type="number" min="0" step="0.01" class="form-control" name="unit_price" id="receipt_cost" value="<?= e((string) ($editReceipt['unit_price'] ?? '')) ?>" required></div>
                    <div class="mb-3"><label class="form-label">Selling Price for This Batch (LKR)</label><input type="number" min="0" step="0.01" class="form-control" name="sale_price" id="receipt_sale_price" value="<?= e((string) ($editReceipt['sale_price'] ?? '')) ?>" required><div class="form-text">This price remains attached to this stock batch.</div></div>
                    <div class="mb-3"><label class="form-label">Received Date</label><input type="datetime-local" class="form-control" name="received_date" value="<?= e($editReceipt ? date('Y-m-d\TH:i', strtotime($editReceipt['received_date'])) : date('Y-m-d\TH:i')) ?>" required></div>
                    <?php if ($editReceipt): ?><div class="alert alert-secondary py-2 small">Batch #<?= (int) $editReceipt['batch_id'] ?> currently has <?= (int) $editReceipt['qty_remaining'] ?> unit(s) remaining.</div><?php endif; ?>
                    <button class="btn btn-primary" type="submit"><?= $editReceipt ? 'Update Receipt' : 'Add Receipt / Batch' ?></button>
                    <?php if ($editReceipt): ?><a class="btn btn-outline-secondary" href="receivables.php">Cancel</a><?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card">
            <div class="card-header bg-white"><strong>Recent Receipts / Price Batches</strong></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light"><tr><th>Date</th><th>Batch</th><th>Item</th><th class="text-end">Received</th><th class="text-end">Remaining</th><th class="text-end">Cost</th><th class="text-end">Sell</th><th>Received By</th><?php if (is_admin()): ?><th></th><?php endif; ?></tr></thead>
                        <tbody>
                        <?php foreach ($receipts as $row): ?>
                            <tr>
                                <td><?= e(date('d M Y H:i', strtotime($row['received_date']))) ?></td>
                                <td><?= $row['batch_id'] ? '#' . (int) $row['batch_id'] : '—' ?></td>
                                <td><?= e($row['item_code'] . ' - ' . $row['item_name']) ?></td>
                                <td class="text-end"><?= (int) $row['received_quantity'] ?></td>
                                <td class="text-end"><?= $row['qty_remaining'] !== null ? (int) $row['qty_remaining'] : '—' ?></td>
                                <td class="text-end"><?= e(money($row['unit_price'])) ?></td>
                                <td class="text-end"><?= e(money($row['sale_price'])) ?></td>
                                <td><?= e($row['received_by_name']) ?></td>
                                <?php if (is_admin()): ?>
                                    <td class="text-end text-nowrap">
                                        <?php if ($row['batch_id']): ?>
                                        <a class="btn btn-sm btn-outline-primary" href="receivables.php?edit=<?= (int) $row['receipt_id'] ?>">Edit</a>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="receipt_id" value="<?= (int) $row['receipt_id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger" data-confirm="Delete this receipt and reverse its unsold batch stock?">Delete</button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$receipts): ?><tr><td colspan="9" class="text-center text-muted py-4">No receipts found.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>

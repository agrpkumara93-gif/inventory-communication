<?php
require_once __DIR__ . '/includes/functions.php';
require_login();
$pdo = db();

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}
// v2 carts were keyed by item_id and had no batch_id. Discard them after upgrade.
foreach ($_SESSION['cart'] as $oldLine) {
    if (!isset($oldLine['batch_id'])) {
        $_SESSION['cart'] = [];
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add_to_cart') {
            $batchId = (int) ($_POST['batch_id'] ?? 0);
            $qty = (int) ($_POST['qty'] ?? 0);
            if ($batchId <= 0 || $qty <= 0) {
                throw new RuntimeException('Select an item batch and enter a valid quantity.');
            }

            // Price and cost always come from the database; browser values are display-only.
            $stmt = $pdo->prepare(
                'SELECT ib.batch_id, ib.item_id, ib.qty_remaining, ib.unit_cost, ib.sale_price,
                        mi.item_code, mi.item_name
                 FROM inventory_batch ib
                 JOIN master_item mi ON mi.item_id = ib.item_id
                 WHERE ib.batch_id = ? AND ib.qty_remaining > 0 AND mi.is_active = 1'
            );
            $stmt->execute([$batchId]);
            $batch = $stmt->fetch();
            if (!$batch) throw new RuntimeException('Selected stock batch is not available.');

            $existingQty = (int) ($_SESSION['cart'][$batchId]['qty'] ?? 0);
            if ($existingQty + $qty > (int) $batch['qty_remaining']) {
                throw new RuntimeException('Not enough stock in this price batch. Available: ' . (int) $batch['qty_remaining'] . '.');
            }

            $_SESSION['cart'][$batchId] = [
                'batch_id' => $batchId,
                'item_id' => (int) $batch['item_id'],
                'item_code' => $batch['item_code'],
                'item_name' => $batch['item_name'],
                'qty' => $existingQty + $qty,
                'unit_price' => (float) $batch['sale_price'],
                'cost_unit_price' => (float) $batch['unit_cost'],
            ];
            flash('success', 'Item added to bill at the batch selling price.');
        } elseif ($action === 'remove') {
            $batchId = (int) ($_POST['batch_id'] ?? 0);
            unset($_SESSION['cart'][$batchId]);
            flash('success', 'Item removed from bill.');
        } elseif ($action === 'clear') {
            $_SESSION['cart'] = [];
            flash('success', 'Bill cleared.');
        } elseif ($action === 'checkout') {
            if (!$_SESSION['cart']) throw new RuntimeException('The bill is empty.');
            $customerName = trim($_POST['customer_name'] ?? '');

            $pdo->beginTransaction();
            $validatedLines = [];
            $total = 0.0;

            // Lock and re-read every batch. This prevents overselling and price tampering.
            $batchStmt = $pdo->prepare(
                'SELECT ib.batch_id, ib.item_id, ib.qty_remaining, ib.unit_cost, ib.sale_price,
                        mi.item_code, mi.item_name
                 FROM inventory_batch ib
                 JOIN master_item mi ON mi.item_id = ib.item_id
                 WHERE ib.batch_id = ? AND mi.is_active = 1
                 FOR UPDATE'
            );

            foreach ($_SESSION['cart'] as $line) {
                $batchStmt->execute([(int) $line['batch_id']]);
                $batch = $batchStmt->fetch();
                if (!$batch || (int) $batch['qty_remaining'] < (int) $line['qty']) {
                    throw new RuntimeException('Insufficient stock for ' . $line['item_name'] . ' in batch #' . (int) $line['batch_id'] . '.');
                }

                $qty = (int) $line['qty'];
                $sellPrice = (float) $batch['sale_price'];
                $unitCost = (float) $batch['unit_cost'];
                $lineTotal = $qty * $sellPrice;
                $costTotal = $qty * $unitCost;
                $total += $lineTotal;

                $validatedLines[] = [
                    'batch_id' => (int) $batch['batch_id'],
                    'item_id' => (int) $batch['item_id'],
                    'item_code' => $batch['item_code'],
                    'item_name' => $batch['item_name'],
                    'qty' => $qty,
                    'unit_price' => $sellPrice,
                    'cost_unit_price' => $unitCost,
                    'line_total' => $lineTotal,
                    'cost_total' => $costTotal,
                ];
            }

            $stmt = $pdo->prepare('INSERT INTO sales_master (invoice_no, sale_date, sold_by, customer_name, total_amount) VALUES (NULL, NOW(), ?, ?, ?)');
            $stmt->execute([current_user()['user_id'], $customerName !== '' ? $customerName : null, $total]);
            $saleId = (int) $pdo->lastInsertId();
            $invoiceNo = generate_invoice_no($saleId);
            $stmt = $pdo->prepare('UPDATE sales_master SET invoice_no = ? WHERE sale_id = ?');
            $stmt->execute([$invoiceNo, $saleId]);

            $insertLine = $pdo->prepare(
                'INSERT INTO tst_sales (sale_id, item_id, batch_id, qty, unit_price, cost_unit_price, line_total, cost_total)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $deductBatch = $pdo->prepare('UPDATE inventory_batch SET qty_remaining = qty_remaining - ? WHERE batch_id = ? AND qty_remaining >= ?');
            $deductInventory = $pdo->prepare('UPDATE master_inventory SET qty = qty - ?, updated_at = NOW() WHERE item_id = ? AND qty >= ?');

            foreach ($validatedLines as $line) {
                $insertLine->execute([
                    $saleId,
                    $line['item_id'],
                    $line['batch_id'],
                    $line['qty'],
                    $line['unit_price'],
                    $line['cost_unit_price'],
                    $line['line_total'],
                    $line['cost_total'],
                ]);

                $deductBatch->execute([$line['qty'], $line['batch_id'], $line['qty']]);
                if ($deductBatch->rowCount() !== 1) throw new RuntimeException('Batch stock changed during checkout. Please try again.');

                $deductInventory->execute([$line['qty'], $line['item_id'], $line['qty']]);
                if ($deductInventory->rowCount() !== 1) throw new RuntimeException('Inventory stock changed during checkout. Please try again.');
            }

            $pdo->commit();
            $_SESSION['cart'] = [];
            redirect('invoice.php?id=' . $saleId);
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('danger', $ex->getMessage());
    }

    redirect('sales.php');
}

$batches = $pdo->query(
    'SELECT ib.batch_id, ib.item_id, ib.qty_remaining, ib.unit_cost, ib.sale_price, ib.received_date,
            mi.item_code, mi.item_name
     FROM inventory_batch ib
     JOIN master_item mi ON mi.item_id = ib.item_id
     WHERE mi.is_active = 1 AND ib.qty_remaining > 0
     ORDER BY mi.item_name, ib.received_date, ib.batch_id'
)->fetchAll();

$cart = $_SESSION['cart'];
$cartTotal = 0.0;
foreach ($cart as $line) $cartTotal += (int) $line['qty'] * (float) $line['unit_price'];

$recentSales = $pdo->query(
    'SELECT sm.sale_id, sm.invoice_no, sm.sale_date, sm.customer_name, sm.total_amount, u.name AS sold_by_name
     FROM sales_master sm JOIN user_master u ON u.user_id = sm.sold_by
     ORDER BY sm.sale_date DESC LIMIT 20'
)->fetchAll();

$pageTitle = 'Sales & Billing';
require __DIR__ . '/includes/header.php';
?>
<div class="row g-4">
    <div class="col-xl-4">
        <div class="card mb-4">
            <div class="card-header bg-white"><strong>Add Item to Bill</strong></div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="add_to_cart">
                    <div class="mb-3">
                        <label class="form-label">Item / Price Batch</label>
                        <select class="form-select" name="batch_id" id="sale_batch_id" onchange="loadBatchPrice()" required>
                            <option value="">Select item</option>
                            <?php foreach ($batches as $batch): ?>
                                <option
                                    value="<?= (int) $batch['batch_id'] ?>"
                                    data-price="<?= e(number_format((float) $batch['sale_price'], 2, '.', '')) ?>"
                                    data-stock="<?= (int) $batch['qty_remaining'] ?>"
                                ><?= e($batch['item_code'] . ' - ' . $batch['item_name'] . ' | Batch #' . $batch['batch_id'] . ' | Stock: ' . $batch['qty_remaining'] . ' | ' . money($batch['sale_price'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">The same item may appear more than once when stock exists at different selling prices.</div>
                    </div>
                    <div class="row g-3">
                        <div class="col-5">
                            <label class="form-label">Qty</label>
                            <input type="number" name="qty" id="sale_qty" min="1" value="1" class="form-control" required>
                            <div class="form-text" id="sale_stock_hint">Select a batch.</div>
                        </div>
                        <div class="col-7">
                            <label class="form-label">Sale Price (LKR)</label>
                            <input type="text" id="sale_unit_price" class="form-control" value="" readonly>
                            <div class="form-text">Loaded automatically from the selected stock batch.</div>
                        </div>
                    </div>
                    <button class="btn btn-primary mt-3" type="submit">Add to Bill</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-white"><strong>Recent Bills</strong></div>
            <div class="list-group list-group-flush">
                <?php foreach ($recentSales as $sale): ?>
                    <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="invoice.php?id=<?= (int) $sale['sale_id'] ?>">
                        <span><strong><?= e($sale['invoice_no']) ?></strong><br><small class="text-muted"><?= e(date('d M Y H:i', strtotime($sale['sale_date']))) ?></small></span>
                        <span><?= e(money($sale['total_amount'])) ?></span>
                    </a>
                <?php endforeach; ?>
                <?php if (!$recentSales): ?><div class="list-group-item text-muted">No bills yet.</div><?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-xl-8">
        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <strong>Current Bill</strong>
                <?php if ($cart): ?>
                <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="clear"><button class="btn btn-sm btn-outline-danger" data-confirm="Clear the current bill?">Clear</button></form>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light"><tr><th>Item</th><th>Batch</th><th class="text-end">Qty</th><th class="text-end">Unit Price</th><th class="text-end">Total</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($cart as $line): $lineTotal = (int) $line['qty'] * (float) $line['unit_price']; ?>
                            <tr>
                                <td><strong><?= e($line['item_name']) ?></strong><br><small class="text-muted"><?= e($line['item_code']) ?></small></td>
                                <td>#<?= (int) $line['batch_id'] ?></td>
                                <td class="text-end"><?= (int) $line['qty'] ?></td>
                                <td class="text-end"><?= e(money($line['unit_price'])) ?></td>
                                <td class="text-end"><?= e(money($lineTotal)) ?></td>
                                <td class="text-end"><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="remove"><input type="hidden" name="batch_id" value="<?= (int) $line['batch_id'] ?>"><button class="btn btn-sm btn-outline-danger">Remove</button></form></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$cart): ?><tr><td colspan="6" class="text-center text-muted py-5">Add items to start a bill.</td></tr><?php endif; ?>
                        </tbody>
                        <?php if ($cart): ?><tfoot><tr class="table-light"><th colspan="4" class="text-end">Grand Total</th><th class="text-end fs-5"><?= e(money($cartTotal)) ?></th><th></th></tr></tfoot><?php endif; ?>
                    </table>
                </div>
            </div>
            <?php if ($cart): ?>
            <div class="card-footer bg-white">
                <form method="post" class="row g-3 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="checkout">
                    <div class="col-md-8"><label class="form-label">Customer Name (optional)</label><input name="customer_name" class="form-control" placeholder="Walk-in customer"></div>
                    <div class="col-md-4 d-grid"><button class="btn btn-success btn-lg" type="submit">Complete Sale & Print</button></div>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
function loadBatchPrice() {
    const select = document.getElementById('sale_batch_id');
    const price = document.getElementById('sale_unit_price');
    const qty = document.getElementById('sale_qty');
    const hint = document.getElementById('sale_stock_hint');
    if (!select || !price || !qty || !hint) return;

    const option = select.options[select.selectedIndex];
    if (!option || !option.value) {
        price.value = '';
        qty.removeAttribute('max');
        hint.textContent = 'Select a batch.';
        return;
    }

    const batchPrice = option.getAttribute('data-price') || '';
    const stock = option.getAttribute('data-stock') || '0';
    price.value = batchPrice;
    qty.max = stock;
    if (parseInt(qty.value || '1', 10) > parseInt(stock, 10)) qty.value = stock;
    hint.textContent = 'Available in this batch: ' + stock;
}

document.addEventListener('DOMContentLoaded', loadBatchPrice);
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>

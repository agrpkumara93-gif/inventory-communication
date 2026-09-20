<?php
require_once __DIR__ . '/includes/functions.php';
require_login();
$pdo = db();

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}
// Older carts selected a physical batch. V5 carts select an item + selling-price group.
foreach ($_SESSION['cart'] as $oldLine) {
    if (!isset($oldLine['price_group_key'])) {
        $_SESSION['cart'] = [];
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add_to_cart') {
            $priceGroup = trim($_POST['price_group'] ?? '');
            $qty = (int) ($_POST['qty'] ?? 0);
            [$itemIdRaw, $salePriceRaw] = array_pad(explode('|', $priceGroup, 2), 2, '');
            $itemId = (int) $itemIdRaw;
            $salePrice = number_format((float) $salePriceRaw, 2, '.', '');

            if ($itemId <= 0 || $qty <= 0 || $salePriceRaw === '' || (float) $salePrice < 0) {
                throw new RuntimeException('Select an item / selling price and enter a valid quantity.');
            }

            // Treat all open batches for the same item + selling price as one sellable price group.
            $stmt = $pdo->prepare(
                'SELECT ib.item_id, ib.sale_price, SUM(ib.qty_remaining) AS total_qty,
                        mi.item_code, mi.item_name
                 FROM inventory_batch ib
                 JOIN master_item mi ON mi.item_id = ib.item_id
                 WHERE ib.item_id = ? AND ib.sale_price = ?
                   AND ib.qty_remaining > 0 AND mi.is_active = 1
                 GROUP BY ib.item_id, ib.sale_price, mi.item_code, mi.item_name'
            );
            $stmt->execute([$itemId, $salePrice]);
            $group = $stmt->fetch();
            if (!$group) throw new RuntimeException('Selected item / selling price is not available.');

            $groupKey = $itemId . '|' . number_format((float) $group['sale_price'], 2, '.', '');
            $existingQty = (int) ($_SESSION['cart'][$groupKey]['qty'] ?? 0);
            $availableQty = (int) $group['total_qty'];
            if ($existingQty + $qty > $availableQty) {
                throw new RuntimeException('Not enough stock at this selling price. Available: ' . $availableQty . '.');
            }

            $_SESSION['cart'][$groupKey] = [
                'price_group_key' => $groupKey,
                'item_id' => (int) $group['item_id'],
                'item_code' => $group['item_code'],
                'item_name' => $group['item_name'],
                'qty' => $existingQty + $qty,
                'unit_price' => (float) $group['sale_price'],
            ];
            flash('success', 'Item added to bill at the selected selling price.');
        } elseif ($action === 'remove') {
            $groupKey = trim($_POST['price_group_key'] ?? '');
            unset($_SESSION['cart'][$groupKey]);
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

            // Lock all matching physical batches. FIFO allocation keeps the real batch cost for profit reporting.
            $batchStmt = $pdo->prepare(
                'SELECT ib.batch_id, ib.item_id, ib.qty_remaining, ib.unit_cost, ib.sale_price,
                        ib.received_date, mi.item_code, mi.item_name
                 FROM inventory_batch ib
                 JOIN master_item mi ON mi.item_id = ib.item_id
                 WHERE ib.item_id = ? AND ib.sale_price = ?
                   AND ib.qty_remaining > 0 AND mi.is_active = 1
                 ORDER BY ib.received_date ASC, ib.batch_id ASC
                 FOR UPDATE'
            );

            foreach ($_SESSION['cart'] as $line) {
                $itemId = (int) $line['item_id'];
                $requestedQty = (int) $line['qty'];
                $sellPrice = number_format((float) $line['unit_price'], 2, '.', '');

                $batchStmt->execute([$itemId, $sellPrice]);
                $matchingBatches = $batchStmt->fetchAll();
                $availableQty = array_sum(array_map(static fn($b) => (int) $b['qty_remaining'], $matchingBatches));
                if ($availableQty < $requestedQty) {
                    throw new RuntimeException('Insufficient stock for ' . $line['item_name'] . ' at ' . money($sellPrice) . '. Available: ' . $availableQty . '.');
                }

                $remainingToAllocate = $requestedQty;
                foreach ($matchingBatches as $batch) {
                    if ($remainingToAllocate <= 0) break;
                    $takeQty = min($remainingToAllocate, (int) $batch['qty_remaining']);
                    if ($takeQty <= 0) continue;

                    $actualSellPrice = (float) $batch['sale_price'];
                    $unitCost = (float) $batch['unit_cost'];
                    $lineTotal = $takeQty * $actualSellPrice;
                    $costTotal = $takeQty * $unitCost;
                    $total += $lineTotal;

                    $validatedLines[] = [
                        'batch_id' => (int) $batch['batch_id'],
                        'item_id' => (int) $batch['item_id'],
                        'item_code' => $batch['item_code'],
                        'item_name' => $batch['item_name'],
                        'qty' => $takeQty,
                        'unit_price' => $actualSellPrice,
                        'cost_unit_price' => $unitCost,
                        'line_total' => $lineTotal,
                        'cost_total' => $costTotal,
                    ];
                    $remainingToAllocate -= $takeQty;
                }
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

$priceGroups = $pdo->query(
    'SELECT ib.item_id, ib.sale_price,
            SUM(ib.qty_remaining) AS total_qty,
            COUNT(*) AS batch_count,
            MIN(ib.received_date) AS oldest_received_date,
            mi.item_code, mi.item_name
     FROM inventory_batch ib
     JOIN master_item mi ON mi.item_id = ib.item_id
     WHERE mi.is_active = 1 AND ib.qty_remaining > 0
     GROUP BY ib.item_id, ib.sale_price, mi.item_code, mi.item_name
     ORDER BY mi.item_name, ib.sale_price'
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
                        <label class="form-label">Item / Selling Price</label>
                        <div
                            class="live-search"
                            id="sale_price_search"
                            data-price-target="sale_unit_price"
                            data-qty-target="sale_qty"
                            data-stock-hint-target="sale_stock_hint"
                            data-stock-label="Available at this price"
                        >
                            <input
                                type="text"
                                class="form-control live-search-input"
                                placeholder="Type item code or item name..."
                                autocomplete="off"
                                aria-label="Search item or selling price"
                                required
                            >
                            <input type="hidden" class="live-search-value" name="price_group" value="">
                            <div class="live-search-menu" role="listbox">
                                <?php foreach ($priceGroups as $group): ?>
                                    <?php
                                        $priceValue = number_format((float) $group['sale_price'], 2, '.', '');
                                        $groupValue = (int) $group['item_id'] . '|' . $priceValue;
                                        $groupLabel = $group['item_code'] . ' - ' . $group['item_name'] . ' | ' . money($group['sale_price']);
                                    ?>
                                    <button
                                        type="button"
                                        class="live-search-option"
                                        data-value="<?= e($groupValue) ?>"
                                        data-label="<?= e($groupLabel) ?>"
                                        data-search="<?= e($group['item_code'] . ' ' . $group['item_name'] . ' ' . $priceValue) ?>"
                                        data-price="<?= e($priceValue) ?>"
                                        data-stock="<?= (int) $group['total_qty'] ?>"
                                    >
                                        <div class="d-flex justify-content-between gap-2">
                                            <span><strong><?= e($group['item_code']) ?></strong> - <?= e($group['item_name']) ?></span>
                                            <strong><?= e(money($group['sale_price'])) ?></strong>
                                        </div>
                                        <small class="text-muted">Total stock: <?= (int) $group['total_qty'] ?><?php if ((int) $group['batch_count'] > 1): ?> · <?= (int) $group['batch_count'] ?> batches combined<?php endif; ?></small>
                                    </button>
                                <?php endforeach; ?>
                                <div class="live-search-empty d-none">No matching item / selling price found.</div>
                            </div>
                        </div>
                        <div class="form-text">Same item batches with the same selling price are combined into one option.</div>
                    </div>
                    <div class="row g-3">
                        <div class="col-5">
                            <label class="form-label">Qty</label>
                            <input type="number" name="qty" id="sale_qty" min="1" value="1" class="form-control" required>
                            <div class="form-text" id="sale_stock_hint">Select an item / selling price.</div>
                        </div>
                        <div class="col-7">
                            <label class="form-label">Sale Price (LKR)</label>
                            <input type="text" id="sale_unit_price" class="form-control" value="" readonly>
                            <div class="form-text">Loaded automatically from the selected selling-price group.</div>
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
                        <thead class="table-light"><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Unit Price</th><th class="text-end">Total</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($cart as $line): $lineTotal = (int) $line['qty'] * (float) $line['unit_price']; ?>
                            <tr>
                                <td><strong><?= e($line['item_name']) ?></strong><br><small class="text-muted"><?= e($line['item_code']) ?></small></td>
                                <td class="text-end"><?= (int) $line['qty'] ?></td>
                                <td class="text-end"><?= e(money($line['unit_price'])) ?></td>
                                <td class="text-end"><?= e(money($lineTotal)) ?></td>
                                <td class="text-end"><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="remove"><input type="hidden" name="price_group_key" value="<?= e($line['price_group_key']) ?>"><button class="btn btn-sm btn-outline-danger">Remove</button></form></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$cart): ?><tr><td colspan="5" class="text-center text-muted py-5">Add items to start a bill.</td></tr><?php endif; ?>
                        </tbody>
                        <?php if ($cart): ?><tfoot><tr class="table-light"><th colspan="3" class="text-end">Grand Total</th><th class="text-end fs-5"><?= e(money($cartTotal)) ?></th><th></th></tr></tfoot><?php endif; ?>
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

<?php require __DIR__ . '/includes/footer.php'; ?>

<?php
require_once __DIR__ . '/includes/functions.php';
require_login();
$pdo = db();
$saleId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT sm.*, u.name AS sold_by_name
     FROM sales_master sm JOIN user_master u ON u.user_id = sm.sold_by
     WHERE sm.sale_id = ?'
);
$stmt->execute([$saleId]);
$sale = $stmt->fetch();
if (!$sale) {
    http_response_code(404);
    exit('Invoice not found.');
}

$stmt = $pdo->prepare(
    'SELECT ts.qty, ts.unit_price, ts.line_total, mi.item_code, mi.item_name
     FROM tst_sales ts JOIN master_item mi ON mi.item_id = ts.item_id
     WHERE ts.sale_id = ? ORDER BY ts.sale_item_id'
);
$stmt->execute([$saleId]);
$lines = $stmt->fetchAll();

$pageTitle = 'Invoice ' . $sale['invoice_no'];
require __DIR__ . '/includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-xl-8">
        <div class="d-flex justify-content-between mb-3 no-print">
            <a class="btn btn-outline-secondary" href="sales.php">Back to Sales</a>
            <button class="btn btn-primary" onclick="window.print()">Print Bill</button>
        </div>
        <div class="card invoice-card">
            <div class="card-body p-4 p-md-5">
                <div class="d-flex justify-content-between align-items-start mb-4">
                    <div><h2 class="mb-1">Stationery Shop</h2><div class="text-muted">Sales Invoice</div></div>
                    <div class="text-end"><strong><?= e($sale['invoice_no']) ?></strong><br><small><?= e(date('d M Y H:i', strtotime($sale['sale_date']))) ?></small></div>
                </div>
                <div class="row mb-4">
                    <div class="col-md-6"><strong>Customer:</strong><br><?= e($sale['customer_name'] ?: 'Walk-in Customer') ?></div>
                    <div class="col-md-6 text-md-end"><strong>Cashier:</strong><br><?= e($sale['sold_by_name']) ?></div>
                </div>
                <div class="table-responsive">
                    <table class="table">
                        <thead class="table-light"><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Unit Price</th><th class="text-end">Amount</th></tr></thead>
                        <tbody>
                        <?php foreach ($lines as $line): ?>
                            <tr><td><?= e($line['item_code'] . ' - ' . $line['item_name']) ?></td><td class="text-end"><?= (int) $line['qty'] ?></td><td class="text-end"><?= e(money($line['unit_price'])) ?></td><td class="text-end"><?= e(money($line['line_total'])) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot><tr><th colspan="3" class="text-end">Total</th><th class="text-end fs-5"><?= e(money($sale['total_amount'])) ?></th></tr></tfoot>
                    </table>
                </div>
                <p class="text-center text-muted mt-5 mb-0">Thank you for your purchase.</p>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>

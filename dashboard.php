<?php
require_once __DIR__ . '/includes/functions.php';
require_admin();

$pdo = db();

$todaySales = (float) $pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM sales_master WHERE DATE(sale_date) = CURDATE()")->fetchColumn();
$todayBills = (int) $pdo->query("SELECT COUNT(*) FROM sales_master WHERE DATE(sale_date) = CURDATE()")->fetchColumn();
$totalItems = (int) $pdo->query("SELECT COUNT(*) FROM master_item WHERE is_active = 1")->fetchColumn();
$lowStockCount = (int) $pdo->query("SELECT COUNT(*) FROM master_item mi JOIN master_inventory inv ON inv.item_id = mi.item_id WHERE mi.is_active = 1 AND inv.qty <= mi.moq")->fetchColumn();

// Gross profit/loss for the current calendar month = actual selling revenue - recorded batch cost of goods sold.
$monthlyProfit = (float) $pdo->query(
    "SELECT COALESCE(SUM(ts.line_total - ts.cost_total), 0)
     FROM tst_sales ts
     JOIN sales_master sm ON sm.sale_id = ts.sale_id
     WHERE YEAR(sm.sale_date) = YEAR(CURDATE())
       AND MONTH(sm.sale_date) = MONTH(CURDATE())"
)->fetchColumn();

$popularItems = $pdo->query(
    "SELECT mi.item_code, mi.item_name, SUM(ts.qty) AS sold_qty, SUM(ts.line_total) AS revenue
     FROM tst_sales ts
     JOIN master_item mi ON mi.item_id = ts.item_id
     JOIN sales_master sm ON sm.sale_id = ts.sale_id
     WHERE sm.sale_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
     GROUP BY mi.item_id, mi.item_code, mi.item_name
     ORDER BY sold_qty DESC
     LIMIT 10"
)->fetchAll();

$lowStockItems = $pdo->query(
    "SELECT mi.item_code, mi.item_name, mi.moq, inv.qty
     FROM master_item mi
     JOIN master_inventory inv ON inv.item_id = mi.item_id
     WHERE mi.is_active = 1 AND inv.qty <= mi.moq
     ORDER BY (mi.moq - inv.qty) DESC, mi.item_name"
)->fetchAll();

$dailySales = $pdo->query(
    "SELECT DATE(sale_date) AS sale_day, COUNT(*) AS bill_count, SUM(total_amount) AS total
     FROM sales_master
     WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
     GROUP BY DATE(sale_date)
     ORDER BY sale_day DESC"
)->fetchAll();

$pageTitle = 'Admin Dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">Admin Dashboard</h1>
        <p class="text-muted mb-0">Overview for <?= e(date('d M Y')) ?></p>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-6 col-xl">
        <div class="card stat-card h-100"><div class="card-body"><div class="text-muted">Today's Sales</div><div class="stat-value"><?= e(money($todaySales)) ?></div></div></div>
    </div>
    <div class="col-md-6 col-xl">
        <div class="card stat-card h-100"><div class="card-body"><div class="text-muted">Monthly Profit / Loss</div><div class="stat-value <?= $monthlyProfit < 0 ? 'text-danger' : 'text-success' ?>"><?= e(money($monthlyProfit)) ?></div><small class="text-muted">Sales − batch cost</small></div></div>
    </div>
    <div class="col-md-6 col-xl">
        <div class="card stat-card h-100"><div class="card-body"><div class="text-muted">Today's Bills</div><div class="stat-value"><?= $todayBills ?></div></div></div>
    </div>
    <div class="col-md-6 col-xl">
        <div class="card stat-card h-100"><div class="card-body"><div class="text-muted">Active Items</div><div class="stat-value"><?= $totalItems ?></div></div></div>
    </div>
    <div class="col-md-6 col-xl">
        <div class="card stat-card h-100"><div class="card-body"><div class="text-muted">At / Below MOQ</div><div class="stat-value"><?= $lowStockCount ?></div></div></div>
    </div>
</div>

<div class="row g-4">
    <div class="col-xl-7">
        <div class="card h-100">
            <div class="card-header bg-white"><strong>Most Popular Items - Last 30 Days</strong></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light"><tr><th>Code</th><th>Item</th><th class="text-end">Qty Sold</th><th class="text-end">Revenue</th></tr></thead>
                        <tbody>
                        <?php if (!$popularItems): ?><tr><td colspan="4" class="text-center text-muted py-4">No sales yet.</td></tr><?php endif; ?>
                        <?php foreach ($popularItems as $row): ?>
                            <tr><td><?= e($row['item_code']) ?></td><td><?= e($row['item_name']) ?></td><td class="text-end"><?= (int) $row['sold_qty'] ?></td><td class="text-end"><?= e(money($row['revenue'])) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-5">
        <div class="card h-100">
            <div class="card-header bg-white"><strong>Items at / Below MOQ</strong></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light"><tr><th>Item</th><th class="text-end">Stock</th><th class="text-end">MOQ</th></tr></thead>
                        <tbody>
                        <?php if (!$lowStockItems): ?><tr><td colspan="3" class="text-center text-muted py-4">No low-stock items.</td></tr><?php endif; ?>
                        <?php foreach ($lowStockItems as $row): ?>
                            <tr><td><span class="fw-semibold"><?= e($row['item_name']) ?></span><br><small class="text-muted"><?= e($row['item_code']) ?></small></td><td class="text-end text-danger fw-semibold"><?= (int) $row['qty'] ?></td><td class="text-end"><?= (int) $row['moq'] ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header bg-white"><strong>Daily Sales - Last 7 Days</strong></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead class="table-light"><tr><th>Date</th><th class="text-end">Bills</th><th class="text-end">Sales</th></tr></thead>
                        <tbody>
                        <?php foreach ($dailySales as $row): ?>
                            <tr><td><?= e(date('d M Y', strtotime($row['sale_day']))) ?></td><td class="text-end"><?= (int) $row['bill_count'] ?></td><td class="text-end"><?= e(money($row['total'])) ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$dailySales): ?><tr><td colspan="3" class="text-center text-muted py-4">No sales data.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>

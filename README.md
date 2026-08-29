# Stationery Inventory System v3

Simple PHP/MySQL inventory and billing application for a stationery shop.

## v3 changes

- Item codes are generated automatically: `ITM-001`, `ITM-002`, ...
- Selling price is no longer stored as one value in Item Master.
- Each stock receipt creates its own **inventory batch** with:
  - received quantity
  - remaining quantity
  - purchase unit cost
  - selling price
  - received date
- The same item can exist in multiple batches at different costs and selling prices.
- `sales.php` lists available price batches. Selecting one automatically loads its selling price.
- The server reads the selling price from MySQL again when adding/checking out, so browser-side price changes cannot alter the sale price.
- A sale records the actual batch cost (`cost_unit_price` / `cost_total`).
- Admin Dashboard includes **Monthly Profit / Loss = Sales Revenue - Cost of Goods Sold** for the current month.

## Example

You can have both of these at the same time:

- ITM-001 / Batch #10 / 20 units / cost LKR 100 / sell LKR 130
- ITM-001 / Batch #14 / 40 units / cost LKR 120 / sell LKR 150

On the Sales page the item appears as two selectable price batches. Choosing Batch #10 loads LKR 130; choosing Batch #14 loads LKR 150.

## Existing v2 installation - upgrade

1. **Back up your MySQL database first.**
2. Replace your PHP project files with the files from v3.
3. Open phpMyAdmin and select the `stationery_inventory` database.
4. Import/run `upgrade_v3.sql` **once**.
5. Log out and log in again (or clear the current sale cart if it was open during the upgrade).
6. Test by receiving two batches of the same item with different costs/selling prices.

### Important migration note

v2 only stored one aggregate cost and one item-level selling price. It did not retain the remaining quantity of every historical receipt. Therefore `upgrade_v3.sql` creates one **migration batch** for each item's current stock using the v2 current cost/selling price.

Historical v2 sales also did not store their exact batch cost. The upgrade fills historical cost using the v2 current item cost as an approximation. **Sales created after the v3 upgrade have exact batch-level profit calculations.**

Old v2 receipt rows remain as history. New v3 receipts are fully batch-aware and can be edited/deleted according to the normal rules.

## Fresh installation

1. Copy the project folder to XAMPP, for example:
   `C:\xampp\htdocs\inv-mgt`
2. Start Apache and MySQL.
3. Import `setup.sql` in phpMyAdmin.
4. Check `config/database.php` and adjust MySQL credentials if needed.
5. Open:
   `http://localhost/inv-mgt/`

Default administrator:

- Username: `admin`
- Password: `Admin@123`

Change the password for a real deployment.

## Main tables

- `user_master` - application users and roles
- `master_item` - item code/name/MOQ only
- `master_inventory` - aggregate quantity per item
- `transaction_order` - stock receipt history
- `inventory_batch` - current cost/price/remaining quantity by receipt batch
- `sales_master` - invoice header
- `tst_sales` - invoice lines including batch, selling price and actual cost

## Profit definition

The dashboard number is **gross merchandise profit/loss**, not accounting net profit:

`SUM(selling amount - cost of goods sold)` for the current calendar month.

It does not subtract rent, salaries, electricity, tax, transport, bank charges or other operating expenses.

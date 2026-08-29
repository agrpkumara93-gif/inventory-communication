<?php
require_once __DIR__ . '/includes/functions.php';
require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add') {
            $name = trim($_POST['name'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = $_POST['role'] ?? 'user';

            if ($name === '' || $username === '' || strlen($password) < 6 || !in_array($role, ['admin', 'user'], true)) {
                throw new RuntimeException('Enter a name, username, valid role and a password of at least 6 characters.');
            }

            $stmt = $pdo->prepare('INSERT INTO user_master (name, username, password_hash, role) VALUES (?, ?, ?, ?)');
            $stmt->execute([$name, $username, password_hash($password, PASSWORD_DEFAULT), $role]);
            flash('success', 'User added successfully.');
        } elseif ($action === 'toggle') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            if ($userId === (int) current_user()['user_id']) {
                throw new RuntimeException('You cannot disable your own account.');
            }
            $stmt = $pdo->prepare('UPDATE user_master SET is_active = IF(is_active = 1, 0, 1) WHERE user_id = ?');
            $stmt->execute([$userId]);
            flash('success', 'User status updated.');
        }
    } catch (PDOException $ex) {
        if ((int) ($ex->errorInfo[1] ?? 0) === 1062) {
            flash('danger', 'That username already exists.');
        } else {
            flash('danger', 'Database error: ' . $ex->getMessage());
        }
    } catch (Throwable $ex) {
        flash('danger', $ex->getMessage());
    }

    redirect('users.php');
}

$users = $pdo->query('SELECT user_id, name, username, role, is_active, created_at FROM user_master ORDER BY created_at DESC')->fetchAll();
$pageTitle = 'Users';
require __DIR__ . '/includes/header.php';
?>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-white"><strong>Add User</strong></div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="add">
                    <div class="mb-3"><label class="form-label">Name</label><input class="form-control" name="name" required></div>
                    <div class="mb-3"><label class="form-label">Username</label><input class="form-control" name="username" required></div>
                    <div class="mb-3"><label class="form-label">Password</label><input type="password" class="form-control" name="password" minlength="6" required></div>
                    <div class="mb-3"><label class="form-label">Role</label><select class="form-select" name="role"><option value="user">User</option><option value="admin">Admin</option></select></div>
                    <button class="btn btn-primary" type="submit">Add User</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header bg-white"><strong>User List</strong></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light"><tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($users as $row): ?>
                            <tr>
                                <td><?= e($row['name']) ?></td>
                                <td><?= e($row['username']) ?></td>
                                <td><?= e(ucfirst($row['role'])) ?></td>
                                <td><span class="badge text-bg-<?= (int) $row['is_active'] === 1 ? 'success' : 'secondary' ?>"><?= (int) $row['is_active'] === 1 ? 'Active' : 'Disabled' ?></span></td>
                                <td class="text-end">
                                    <?php if ((int) $row['user_id'] !== (int) current_user()['user_id']): ?>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="user_id" value="<?= (int) $row['user_id'] ?>">
                                        <button class="btn btn-sm btn-outline-secondary" data-confirm="Change this user's active status?">
                                            <?= (int) $row['is_active'] === 1 ? 'Disable' : 'Enable' ?>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>

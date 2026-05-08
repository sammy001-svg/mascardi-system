<?php
require_once __DIR__ . '/../../includes/functions.php';
requireRole('admin');
$pageTitle = 'Users';
$db = getDB();
$users = $db->query("SELECT u.*,
    CASE u.linked_type WHEN 'driver' THEN d.name WHEN 'mechanic' THEN m.name END AS linked_name
    FROM users u
    LEFT JOIN drivers d ON d.id = u.linked_id AND u.linked_type = 'driver'
    LEFT JOIN mechanics m ON m.id = u.linked_id AND u.linked_type = 'mechanic'
    ORDER BY u.role, u.name")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-users-gear me-2 text-primary"></i>System Users</h5>
    <a href="add.php" class="btn btn-primary btn-sm"><i class="fa fa-plus me-1"></i>Add User</a>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0 datatable">
            <thead>
                <tr>
                    <th class="ps-3">Name</th>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Linked To</th>
                    <th>Status</th>
                    <th>Last Login</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td class="ps-3 fw-medium"><?= e($u['name']) ?></td>
                    <td><code><?= e($u['username']) ?></code></td>
                    <td>
                        <?php
                        $roleColors = ['admin'=>'danger','manager'=>'primary','mechanic'=>'info','driver'=>'warning'];
                        $rc = $roleColors[$u['role']] ?? 'secondary';
                        ?>
                        <span class="badge bg-<?= $rc ?>"><?= ucfirst(e($u['role'])) ?></span>
                    </td>
                    <td>
                        <?php if ($u['linked_name']): ?>
                        <span class="text-muted small">
                            <i class="fa fa-link me-1"></i><?= e($u['linked_name']) ?>
                            <span class="badge bg-light text-dark border"><?= e($u['linked_type']) ?></span>
                        </span>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= statusBadge($u['status']) ?></td>
                    <td class="text-muted small"><?= $u['last_login'] ? fmtDate($u['last_login'], 'd M Y H:i') : 'Never' ?></td>
                    <td>
                        <div class="d-flex gap-1">
                            <a href="edit.php?id=<?= $u['id'] ?>" class="btn btn-xs btn-outline-primary">
                                <i class="fa fa-pen"></i>
                            </a>
                            <?php if ($u['id'] !== authUser()['id']): ?>
                            <a href="delete.php?id=<?= $u['id'] ?>" class="btn btn-xs btn-outline-danger confirm-delete">
                                <i class="fa fa-trash"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

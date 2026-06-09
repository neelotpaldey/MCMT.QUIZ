<?php
// developed by @neelotpal.dey
require_once __DIR__ . '/../includes/auth.php';
requireAdminLogin();
$db = getDB();

$pageTitle = 'Manage Admins';
$pageKey   = 'admins';
$currentAdminId = (int) ($_SESSION['admin_id'] ?? 0);

$msg = '';
$msgType = 'info';
$action = $_GET['action'] ?? 'list';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'add' || $postAction === 'edit') {
        $username = sanitize($db, $_POST['username'] ?? '');
        $fullName = sanitize($db, $_POST['full_name'] ?? '');
        $email    = sanitize($db, $_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $adminId  = (int) ($_POST['admin_id'] ?? 0);

        if (!$username || !$fullName) {
            $msg = 'Username and full name are required.';
            $msgType = 'error';
        } elseif (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $username)) {
            $msg = 'Username must be 3–50 characters (letters, numbers, . _ - only).';
            $msgType = 'error';
        } elseif ($postAction === 'add' && strlen($password) < 6) {
            $msg = 'Password must be at least 6 characters.';
            $msgType = 'error';
        } elseif ($postAction === 'edit' && $password !== '' && strlen($password) < 6) {
            $msg = 'New password must be at least 6 characters (or leave blank to keep current).';
            $msgType = 'error';
        } else {
            try {
                if ($postAction === 'add') {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $db->prepare(
                        'INSERT INTO admin_users (username, password, full_name, email) VALUES (?, ?, ?, ?)'
                    );
                    $stmt->bind_param('ssss', $username, $hash, $fullName, $email);
                    $stmt->execute();
                    $stmt->close();
                    $msg = 'Admin account created successfully.';
                    $msgType = 'success';
                } else {
                    if ($password !== '') {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $db->prepare(
                            'UPDATE admin_users SET username=?, full_name=?, email=?, password=? WHERE id=?'
                        );
                        $stmt->bind_param('ssssi', $username, $fullName, $email, $hash, $adminId);
                    } else {
                        $stmt = $db->prepare(
                            'UPDATE admin_users SET username=?, full_name=?, email=? WHERE id=?'
                        );
                        $stmt->bind_param('sssi', $username, $fullName, $email, $adminId);
                    }
                    $stmt->execute();
                    $stmt->close();

                    if ($adminId === $currentAdminId) {
                        $_SESSION['admin_name'] = $fullName;
                    }

                    $msg = 'Admin account updated.';
                    $msgType = 'success';
                }
            } catch (mysqli_sql_exception $e) {
                logDbError($e, 'admin_admins');
                if (isDuplicateKeyException($e)) {
                    $msg = 'That username is already taken.';
                } else {
                    $msg = 'Could not save admin account. Please try again.';
                }
                $msgType = 'error';
            }
        }
    } elseif ($postAction === 'delete') {
        $adminId = (int) ($_POST['admin_id'] ?? 0);
        $total   = (int) $db->query('SELECT COUNT(*) FROM admin_users')->fetch_row()[0];

        if ($adminId === $currentAdminId) {
            $msg = 'You cannot delete your own account while logged in.';
            $msgType = 'error';
        } elseif ($total <= 1) {
            $msg = 'Cannot delete the only admin account.';
            $msgType = 'error';
        } elseif ($adminId > 0) {
            $stmt = $db->prepare('DELETE FROM admin_users WHERE id=?');
            $stmt->bind_param('i', $adminId);
            $stmt->execute();
            $stmt->close();
            $msg = 'Admin account deleted.';
            $msgType = 'info';
        }
    }

    $action = 'list';
}

$editAdmin = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $aid = (int) $_GET['id'];
    $stmt = $db->prepare('SELECT id, username, full_name, email, created_at FROM admin_users WHERE id=?');
    $stmt->bind_param('i', $aid);
    $stmt->execute();
    $editAdmin = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$editAdmin) {
        header('Location: ' . BASE_URL . '/admin/admins.php');
        exit;
    }
}

$admins = $db->query('SELECT id, username, full_name, email, created_at FROM admin_users ORDER BY created_at ASC');

include __DIR__ . '/layout_head.php';
?>
<div class="content">
  <div class="topbar">
    <div class="page-title">🛡️ Manage Admins</div>
    <button class="btn btn-primary btn-sm" onclick="showAddForm()">➕ Add Admin</button>
  </div>
  <div class="page-body">
    <?php if ($msg): ?>
    <div class="alert alert-<?= $msgType ?>">
      <?= $msgType === 'success' ? '✅' : ($msgType === 'error' ? '❌' : 'ℹ️') ?>
      <?= htmlspecialchars($msg) ?>
    </div>
    <?php endif; ?>

    <div id="adminForm" class="card" style="margin-bottom:1.2rem;<?= ($action === 'add' || $editAdmin) ? '' : 'display:none' ?>">
      <div class="card-header">
        <div class="card-title"><?= $editAdmin ? '✏️ Edit Admin' : '➕ Add Admin' ?></div>
        <button class="btn btn-outline btn-sm" type="button" onclick="hideForm()">✕ Cancel</button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="<?= $editAdmin ? 'edit' : 'add' ?>">
        <?php if ($editAdmin): ?>
        <input type="hidden" name="admin_id" value="<?= (int) $editAdmin['id'] ?>">
        <?php endif; ?>
        <div class="form-row">
          <div class="form-group">
            <label>Username *</label>
            <input class="form-control" type="text" name="username" required
                   pattern="[a-zA-Z0-9._-]{3,50}" maxlength="50"
                   value="<?= htmlspecialchars($editAdmin['username'] ?? '') ?>"
                   autocomplete="off">
          </div>
          <div class="form-group">
            <label>Full Name *</label>
            <input class="form-control" type="text" name="full_name" required
                   value="<?= htmlspecialchars($editAdmin['full_name'] ?? '') ?>">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Email</label>
            <input class="form-control" type="email" name="email"
                   value="<?= htmlspecialchars($editAdmin['email'] ?? '') ?>"
                   placeholder="admin@school.com">
          </div>
          <div class="form-group">
            <label><?= $editAdmin ? 'New Password' : 'Password *' ?></label>
            <input class="form-control" type="password" name="password"
                   <?= $editAdmin ? '' : 'required minlength="6"' ?>
                   placeholder="<?= $editAdmin ? 'Leave blank to keep current password' : 'Min. 6 characters' ?>"
                   autocomplete="new-password">
          </div>
        </div>
        <button type="submit" class="btn btn-primary">
          <?= $editAdmin ? '💾 Update Admin' : '➕ Create Admin' ?>
        </button>
      </form>
    </div>

    <div class="card">
      <div class="card-header">
        <div class="card-title">Administrator Accounts</div>
      </div>
      <p style="font-size:.82rem;color:var(--muted);margin-bottom:1rem;padding:0 0 0 0">
        Each admin can log in at the admin panel with their own username and password.
        You are logged in as <strong><?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?></strong>.
      </p>
      <table class="tbl">
        <thead>
          <tr>
            <th>#</th>
            <th>Username</th>
            <th>Full Name</th>
            <th>Email</th>
            <th>Created</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php $i = 1; while ($a = $admins->fetch_assoc()): ?>
          <tr>
            <td style="color:var(--muted)"><?= $i++ ?></td>
            <td style="font-family:'DM Mono',monospace;font-size:.85rem">
              <?= htmlspecialchars($a['username']) ?>
              <?php if ((int) $a['id'] === $currentAdminId): ?>
              <span class="badge badge-active" style="margin-left:6px;font-size:.7rem">You</span>
              <?php endif; ?>
            </td>
            <td><strong><?= htmlspecialchars($a['full_name']) ?></strong></td>
            <td style="font-size:.82rem">
              <?= $a['email'] ? htmlspecialchars($a['email']) : '<span style="color:var(--muted)">—</span>' ?>
            </td>
            <td style="font-size:.78rem;color:var(--muted)"><?= date('d M Y', strtotime($a['created_at'])) ?></td>
            <td>
              <div style="display:flex;gap:.4rem">
                <a href="?action=edit&id=<?= (int) $a['id'] ?>">
                  <button class="btn btn-outline btn-sm" type="button">✏️</button>
                </a>
                <?php if ((int) $a['id'] !== $currentAdminId): ?>
                <form method="POST" onsubmit="return confirm('Delete admin account &quot;<?= htmlspecialchars($a['username'], ENT_QUOTES) ?>&quot;?')">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="admin_id" value="<?= (int) $a['id'] ?>">
                  <button class="btn btn-danger btn-sm" type="submit">🗑️</button>
                </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
</div>
<script>
function showAddForm() {
  document.getElementById('adminForm').style.display = 'block';
  document.getElementById('adminForm').scrollIntoView({ behavior: 'smooth' });
}
function hideForm() {
  window.location.href = '<?= BASE_URL ?>/admin/admins.php';
}
</script>
</body></html>

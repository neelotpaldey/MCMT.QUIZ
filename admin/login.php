<?php
// developed by @neelotpal.dey
require_once __DIR__ . '/../includes/auth.php';
startAdminSession();
if (isAdminLoggedIn()) { header('Location: ' . BASE_URL . '/admin/dashboard.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db   = getDB();
    $user = sanitize($db, $_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';

    try {
        $stmt = $db->prepare('SELECT * FROM admin_users WHERE username=?');
        $stmt->bind_param('s', $user);
        $stmt->execute();
        $admin = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($admin && password_verify($pass, $admin['password'])) {
            loginAdmin($admin['id'], $admin['full_name']);
            header('Location: ' . BASE_URL . '/admin/dashboard.php');
            exit;
        }
        $error = 'Invalid username or password.';
    } catch (mysqli_sql_exception $e) {
        logDbError($e, 'admin_login');
        $error = 'Unable to sign in right now. Please try again in a moment.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Login – ExamPortal</title>
<?php themeInitScript(); themeStylesheet(); themeScript(); ?>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root,[data-theme="dark"]{--dark:#0a0f1a;--card:rgba(255,255,255,.04);--sky:#1e88e5;--accent:#ffb703;--border:rgba(255,255,255,.1);--radius:14px;--white:#f8f9fa}
body.admin-login{min-height:100vh;background:var(--dark);background-image:radial-gradient(ellipse 60% 50% at 80% 20%,rgba(30,136,229,.3) 0%,transparent 60%);
display:flex;align-items:center;justify-content:center;font-family:'DM Sans',sans-serif;color:var(--white)}
.card{background:var(--card);border:1px solid var(--border);backdrop-filter:blur(20px);border-radius:24px;
padding:3rem 3.5rem;width:100%;max-width:420px;box-shadow:0 32px 64px rgba(0,0,0,.5);
animation:up .4s cubic-bezier(.22,.68,0,1.2)}
@keyframes up{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}
.top{display:flex;align-items:center;gap:12px;margin-bottom:2rem}
.logo-icon{width:42px;height:42px;background:linear-gradient(135deg,#c0392b,#922b21);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px}
.logo-text{font-family:'Playfair Display',serif;font-size:1.4rem}
.logo-text span{color:var(--accent)}
h2{font-size:1.5rem;margin-bottom:.3rem}
.sub{font-size:.85rem;color:rgba(255,255,255,.45);margin-bottom:1.8rem}
label{display:block;font-size:.78rem;font-weight:500;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px}
.iw{position:relative;margin-bottom:1.2rem}
.iw .ic{position:absolute;left:14px;top:50%;transform:translateY(-50%);opacity:.4;pointer-events:none}
input{width:100%;background:rgba(255,255,255,.06);border:1px solid var(--border);border-radius:var(--radius);
padding:12px 14px 12px 42px;color:#f8f9fa;font-family:'DM Sans',sans-serif;font-size:.95rem;outline:none;transition:.2s}
input:focus{border-color:var(--sky);background:rgba(30,136,229,.08)}
.err{background:rgba(255,82,82,.1);border:1px solid rgba(255,82,82,.3);border-radius:10px;padding:10px 14px;font-size:.85rem;color:#ff8a80;margin-bottom:1.2rem}
.btn{width:100%;padding:13px;background:linear-gradient(135deg,#c0392b,#922b21);border:none;border-radius:var(--radius);
color:#fff;font-family:'DM Sans',sans-serif;font-size:.95rem;font-weight:600;cursor:pointer;letter-spacing:.02em;
transition:.15s;box-shadow:0 6px 20px rgba(192,57,43,.4)}
.btn:hover{transform:translateY(-1px);box-shadow:0 10px 28px rgba(192,57,43,.5)}
.note{text-align:center;margin-top:1.2rem;font-size:.78rem;color:rgba(255,255,255,.3)}
</style>
</head>
<body class="admin-login">
<?php themeToggleButton('theme-toggle-fixed'); ?>
<div class="card">
  <div class="top">
    <div class="logo-icon">⚙️</div>
    <div class="logo-text">Admin<span>Portal</span></div>
  </div>
  <h2>Administrator Login</h2>
  <p class="sub">Restricted access — authorized personnel only</p>
  <?php if($error): ?><div class="err">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="POST">
    <div class="iw"><label>Username</label><span class="ic">👤</span><input type="text" name="username" required autofocus></div>
    <div class="iw"><label>Password</label><span class="ic">🔑</span><input type="password" name="password" required autocomplete="current-password"></div>
    <button type="submit" class="btn">Login to Admin Panel →</button>
  </form>
</div>
<?php include __DIR__ . '/password_toggle.php'; ?>
</body>
</html>

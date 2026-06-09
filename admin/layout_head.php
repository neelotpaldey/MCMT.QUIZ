<?php
// developed by @neelotpal.dey
// Include at the top of every admin page after requireAdminLogin()
// Usage: $pageTitle = 'Dashboard'; include __DIR__.'/layout_head.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($pageTitle ?? 'Admin') ?> – ExamPortal Admin</title>
<?php themeInitScript(); themeStylesheet(); themeScript(); ?>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Playfair+Display:wght@700&family=DM+Mono&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root,[data-theme="dark"]{
  --dark:#080e18; --sidebar:#0d1520; --card:#111927;
  --border:rgba(255,255,255,.08); --sky:#1e88e5; --accent:#ffb703;
  --red:#c0392b; --green:#27ae60; --purple:#8e44ad;
  --white:#f0f4f8; --muted:rgba(255,255,255,.45);
  --radius:12px; --sidebar-w:240px;
}
html,body{height:100%;background:var(--dark);color:var(--white);font-family:'DM Sans',sans-serif;overflow:hidden}
a{color:inherit;text-decoration:none}

/* Layout */
.layout{display:flex;height:100vh}

/* Sidebar */
.sidebar{width:var(--sidebar-w);background:var(--sidebar);border-right:1px solid var(--border);
  display:flex;flex-direction:column;flex-shrink:0;overflow-y:auto}
.sb-brand{padding:1.5rem 1.2rem;border-bottom:1px solid var(--border)}
.sb-brand .logo{font-family:'Playfair Display',serif;font-size:1.3rem}
.sb-brand .logo span{color:var(--accent)}
.sb-brand .role{font-size:.72rem;color:var(--muted);margin-top:2px;text-transform:uppercase;letter-spacing:.06em}
.sb-nav{flex:1;padding:.8rem 0}
.sb-section{font-size:.68rem;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;
  padding:.8rem 1.2rem .4rem}
.sb-link{display:flex;align-items:center;gap:10px;padding:.65rem 1.2rem;
  font-size:.88rem;transition:.15s;border-left:3px solid transparent;color:rgba(255,255,255,.65)}
.sb-link:hover{background:rgba(255,255,255,.04);color:var(--white)}
.sb-link.active{background:rgba(30,136,229,.1);border-left-color:var(--sky);color:var(--white)}
.sb-icon{font-size:1rem;width:20px;text-align:center}
.sb-bottom{padding:1rem 1.2rem;border-top:1px solid var(--border)}
.admin-pill{display:flex;align-items:center;gap:8px;font-size:.82rem;color:var(--muted)}
.admin-avatar{width:30px;height:30px;background:var(--red);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px}
.logout-link{display:block;text-align:center;margin-top:.7rem;font-size:.78rem;color:var(--muted);
  padding:6px;border:1px solid var(--border);border-radius:8px;transition:.15s}
.logout-link:hover{background:rgba(255,255,255,.05);color:var(--white)}

/* Content area */
.content{flex:1;display:flex;flex-direction:column;overflow:hidden}
.topbar{display:flex;align-items:center;justify-content:space-between;
  padding:.9rem 1.8rem;background:rgba(13,21,32,.8);border-bottom:1px solid var(--border);
  backdrop-filter:blur(12px);flex-shrink:0}
.page-title{font-size:1.1rem;font-weight:600}
.topbar-right{display:flex;gap:.8rem;align-items:center}
.page-body{flex:1;overflow-y:auto;padding:1.8rem}

/* Cards */
.card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:1.5rem}
.card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.2rem}
.card-title{font-size:1rem;font-weight:600}

/* Stat cards */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;margin-bottom:1.5rem}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:1.2rem;position:relative;overflow:hidden}
.stat-card::before{content:'';position:absolute;inset:0;background:linear-gradient(135deg,var(--accent-color,rgba(30,136,229,.1)) 0%,transparent 60%)}
.stat-icon{font-size:1.6rem;margin-bottom:.6rem}
.stat-val{font-size:2rem;font-weight:700}
.stat-lbl{font-size:.75rem;color:var(--muted);margin-top:3px}

/* Table */
.tbl{width:100%;border-collapse:collapse}
.tbl th{text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);padding:.6rem .8rem;border-bottom:1px solid var(--border)}
.tbl td{padding:.7rem .8rem;border-bottom:1px solid rgba(255,255,255,.04);font-size:.88rem}
.tbl tr:hover td{background:rgba(255,255,255,.02)}
.badge{display:inline-flex;align-items:center;gap:4px;padding:2px 10px;border-radius:50px;font-size:.72rem;font-weight:600}
.badge-active{background:rgba(39,174,96,.2);border:1px solid rgba(39,174,96,.3);color:#81c784}
.badge-inactive{background:rgba(255,255,255,.06);border:1px solid var(--border);color:var(--muted)}
.badge-started{background:rgba(30,136,229,.2);border:1px solid rgba(30,136,229,.3);color:#64b5f6}
.badge-bank{background:rgba(155,89,182,.2);border:1px solid rgba(155,89,182,.3);color:#ce93d8}
.badge-gemini{background:rgba(255,183,3,.15);border:1px solid rgba(255,183,3,.3);color:#ffcc02}
.badge-groq{background:rgba(39,174,96,.15);border:1px solid rgba(39,174,96,.3);color:#81c784}
.badge-pass{background:rgba(39,174,96,.2);border:1px solid rgba(39,174,96,.3);color:#81c784}
.badge-fail{background:rgba(192,57,43,.2);border:1px solid rgba(192,57,43,.3);color:#ef9a9a}

/* Buttons */
.btn{padding:8px 18px;border-radius:var(--radius);font-family:'DM Sans',sans-serif;font-size:.85rem;font-weight:600;cursor:pointer;border:none;transition:.15s}
.btn-primary{background:linear-gradient(135deg,var(--sky),#1565c0);color:#fff;box-shadow:0 4px 16px rgba(30,136,229,.3)}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(30,136,229,.4)}
.btn-danger{background:linear-gradient(135deg,var(--red),#922b21);color:#fff}
.btn-danger:hover{transform:translateY(-1px)}
.btn-success{background:linear-gradient(135deg,var(--green),#1e8449);color:#fff}
.btn-success:hover{transform:translateY(-1px)}
.btn-sm{padding:5px 12px;font-size:.78rem}
.btn-outline{background:transparent;border:1px solid var(--border);color:rgba(255,255,255,.6)}
.btn-outline:hover{border-color:rgba(255,255,255,.25);color:var(--white)}
.btn:disabled{opacity:.4;cursor:not-allowed;transform:none!important}

/* Form elements */
.form-group{margin-bottom:1.2rem}
.form-group label{display:block;font-size:.78rem;font-weight:500;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px}
.form-control{width:100%;background:rgba(255,255,255,.06);border:1px solid var(--border);border-radius:var(--radius);
  padding:10px 14px;color:var(--white);font-family:'DM Sans',sans-serif;font-size:.9rem;outline:none;transition:.18s}
.form-control:focus{border-color:var(--sky);background:rgba(30,136,229,.07)}
.form-control option{background:#0d1520}
select.form-control{cursor:pointer}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
.form-row.three{grid-template-columns:1fr 1fr 1fr}

/* Alerts */
.alert{padding:10px 16px;border-radius:10px;font-size:.85rem;margin-bottom:1rem}
.alert-success{background:rgba(39,174,96,.12);border:1px solid rgba(39,174,96,.3);color:#81c784}
.alert-error{background:rgba(192,57,43,.12);border:1px solid rgba(192,57,43,.3);color:#ef9a9a}
.alert-info{background:rgba(30,136,229,.1);border:1px solid rgba(30,136,229,.3);color:#64b5f6}

/* Toggle switch */
.switch{position:relative;display:inline-block;width:42px;height:22px}
.switch input{opacity:0;width:0;height:0}
.slider{position:absolute;cursor:pointer;inset:0;background:rgba(255,255,255,.15);border-radius:22px;transition:.3s}
.slider:before{content:'';position:absolute;height:16px;width:16px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.3s}
input:checked+.slider{background:var(--sky)}
input:checked+.slider:before{transform:translateX(20px)}

/* Spinner */
.spinner{border:3px solid rgba(255,255,255,.15);border-top-color:var(--sky);border-radius:50%;
  width:24px;height:24px;animation:spin .8s linear infinite;display:inline-block;vertical-align:middle}
@keyframes spin{to{transform:rotate(360deg)}}

/* Tabs */
.tabs{display:flex;gap:4px;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:12px;padding:4px;margin-bottom:1.5rem}
.tab{flex:1;padding:8px;text-align:center;border-radius:9px;font-size:.85rem;font-weight:500;cursor:pointer;transition:.15s;color:var(--muted)}
.tab.active{background:var(--sky);color:#fff}

/* Pagination */
.pagination{display:flex;gap:6px;justify-content:flex-end;margin-top:1rem}
.page-btn{padding:5px 12px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--muted);cursor:pointer;font-size:.82rem;transition:.15s}
.page-btn.active,.page-btn:hover{background:var(--sky);color:#fff;border-color:var(--sky)}
</style>
<?php include __DIR__ . '/password_toggle.php'; ?>
</head>
<body>
<div class="layout">
<!-- Sidebar -->
<div class="sidebar">
  <div class="sb-brand">
    <div class="logo">Exam<span>Portal</span></div>
    <div class="role">Administration</div>
  </div>
  <nav class="sb-nav">
    <div class="sb-section">Main</div>
    <a class="sb-link <?= ($pageKey??'')==='dashboard'?'active':'' ?>" href="<?= BASE_URL ?>/admin/dashboard.php">
      <span class="sb-icon">📊</span> Dashboard
    </a>
    <div class="sb-section">Exams</div>
    <a class="sb-link <?= ($pageKey??'')==='create_exam'?'active':'' ?>" href="<?= BASE_URL ?>/admin/create_exam.php">
      <span class="sb-icon">➕</span> Create Exam
    </a>
    <a class="sb-link <?= ($pageKey??'')==='manage_exams'?'active':'' ?>" href="<?= BASE_URL ?>/admin/manage_exams.php">
      <span class="sb-icon">📋</span> Manage Exams
    </a>
    <div class="sb-section">Questions</div>
    <a class="sb-link <?= ($pageKey??'')==='question_bank'?'active':'' ?>" href="<?= BASE_URL ?>/admin/question_bank.php">
      <span class="sb-icon">🗃️</span> Question Bank
    </a>
    <div class="sb-section">Students</div>
    <a class="sb-link <?= ($pageKey??'')==='students'?'active':'' ?>" href="<?= BASE_URL ?>/admin/students.php">
      <span class="sb-icon">👥</span> Manage Students
    </a>
    <div class="sb-section">Results</div>
    <a class="sb-link <?= ($pageKey??'')==='results'?'active':'' ?>" href="<?= BASE_URL ?>/admin/results.php">
      <span class="sb-icon">📈</span> View Results
    </a>
    <div class="sb-section">System</div>
    <a class="sb-link <?= ($pageKey??'')==='admins'?'active':'' ?>" href="<?= BASE_URL ?>/admin/admins.php">
      <span class="sb-icon">🛡️</span> Manage Admins
    </a>
    <a class="sb-link <?= ($pageKey??'')==='settings'?'active':'' ?>" href="<?= BASE_URL ?>/admin/settings.php">
      <span class="sb-icon">⚙️</span> Settings
    </a>
  </nav>
  <div class="sb-bottom">
    <?php themeToggleButton('theme-toggle-sidebar'); ?>
    <div class="admin-pill">
      <div class="admin-avatar">⚙️</div>
      <div><?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?></div>
    </div>
    <a class="logout-link" href="<?= BASE_URL ?>/admin/logout.php">← Logout</a>
  </div>
</div>
<!-- Content area starts here; each page adds .content div -->

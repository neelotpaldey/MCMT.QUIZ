<!-- developed by @neelotpal.dey -->
# 🎓 Online Examination System – PHP

A full-featured, production-ready online examination system built in PHP + MySQL.
Designed and Built by @neelotpaldey

<p align="center">
  <a href="https://in.linkedin.com/in/neelotpaldey">
    <img src="https://img.shields.io/badge/LinkedIn-Connect-0A66C2?style=for-the-badge&logo=linkedin&logoColor=white" />
  </a>

  <a href="https://orcid.org/0009-0008-4759-7664">
    <img src="https://img.shields.io/badge/ORCID-Researcher-A6CE39?style=for-the-badge&logo=orcid&logoColor=white" />
  </a>

  <a href="mailto:mcmt.neelotpal@gmail.com">
    <img src="https://img.shields.io/badge/Email-Contact-EA4335?style=for-the-badge&logo=gmail&logoColor=white" />
  </a>
</p>

---

## ✨ Features

### Student Portal
- **Login via Mobile Number + DOB** (no passwords to remember)
- **Two-step instruction screen** (General Instructions → Rules & Agreement)
- **Full-screen enforcement** with violation warnings (3 strikes → auto-submit)
- **Keyboard lock** (no copy/paste, arrow keys navigate questions)
- **Right-click disabled** throughout the exam
- **Tab-switching detection** (visibility change → counts as violation)
- **Live countdown timer** with danger mode (red + blinking) under 5 minutes
- **Question palette** (right panel): Not Visited / Not Answered / Answered / Marked / Answered+Marked
- **Mark for Review** feature (with visual state on palette)
- **Save & Next** saves answer via AJAX without page reload
- **Auto-submit** when timer hits zero
- **Result page** with score, pass/fail, correct/wrong/skipped breakdown

### Admin Portal
- **Dashboard** with live stats and quick actions
- **Create Exam** with:
  - Question source: Question Bank / Google Gemini AI / Groq AI
  - Category-wise question count (GK + English + Logical = Total)
  - Auto-calculated total marks
  - API key entry + "Test & Preview" (see 3 sample AI-generated questions before saving)
- **Manage Exams**: Activate → Start → Stop (auto-submits all students)
- **Question Bank**: Add/Edit/Delete questions, Bulk JSON import, filter by category/difficulty
- **Manage Students**: Add/Edit/Delete, Bulk CSV import, toggle active status
- **Results**: Filter by exam, search student, export CSV, stats summary

### Security
- Each student gets a **unique randomized question set** (bank or AI)
- Sessions tracked with IP address
- Server-side timer (not client-controlled)
- SQL injection prevention via prepared statements
- XSS prevention via `htmlspecialchars()`

---

## 🚀 Quick Start

### 1. Requirements
- PHP 7.4+ with `curl`, `mysqli` extensions enabled
- MySQL 5.7+ or MariaDB 10.3+
- Apache/Nginx with `mod_rewrite` (or equivalent)

### 2. Database Setup
```sql
-- Run this in phpMyAdmin or MySQL CLI:
source /path/to/exam_system/schema.sql
```

### 3. Configuration
Edit `includes/db.php`:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_NAME', 'exam_system');
```

### 4. Web Server
Place the `exam_system/` folder in your web root (e.g., `htdocs/` or `www/`).

**Apache** – Add to `.htaccess` or virtual host:
```apache
<Directory /var/www/html/exam_system>
    AllowOverride All
    Options -Indexes
</Directory>
```

**Nginx** – PHP-FPM config example:
```nginx
location /exam_system/ {
    try_files $uri $uri/ /exam_system/index.php?$query_string;
}
```

### 5. Access
| URL | Purpose |
|-----|---------|
| `/exam_system/` | Auto-redirects |
| `/exam_system/student/login.php` | Student login |
| `/exam_system/admin/login.php` | Admin login |

**Default Admin Credentials:**
- Username: `admin`
- Password: `admin123`

> ⚠️ Change this immediately after first login via phpMyAdmin:
> `UPDATE admin_users SET password = '$2y$10$...' WHERE username = 'admin';`

---

## 🔑 API Keys

### Google Gemini
1. Go to [Google AI Studio](https://aistudio.google.com/)
2. Create API key (free tier available)
3. Enter in Create Exam → Question Source → Gemini

### Groq
1. Go to [console.groq.com](https://console.groq.com)
2. Create API key (free tier: very fast inference)
3. Enter in Create Exam → Question Source → Groq

---

## 📁 File Structure

```
exam_system/
├── index.php                    # Root redirect
├── schema.sql                   # DB schema + seed data
├── includes/
│   ├── db.php                   # Database connection
│   ├── auth.php                 # Session & auth helpers
│   └── questions.php            # Question generation (bank/Gemini/Groq)
├── student/
│   ├── login.php                # Mobile + DOB login
│   ├── instructions.php         # 2-step instruction page
│   ├── start_exam.php           # Creates session + unique question set
│   ├── exam.php                 # Main exam UI
│   ├── api_save.php             # AJAX: save answer
│   ├── submit.php               # Submit + show result
│   └── logout.php
└── admin/
    ├── login.php                # Admin login
    ├── layout_head.php          # Shared sidebar + styles
    ├── dashboard.php            # Overview stats
    ├── create_exam.php          # Create exam with AI/bank options
    ├── manage_exams.php         # Start/stop/delete exams
    ├── question_bank.php        # CRUD question bank
    ├── students.php             # Manage students
    ├── results.php              # View + export results
    ├── api_test_questions.php   # AJAX: test AI question generation
    └── logout.php
```

---

## 🎯 Workflow

### Admin Flow
1. Login at `/admin/login.php`
2. **Add Students** → Manage Students → Add or Bulk Import CSV
3. **Add Questions** (for bank mode) → Question Bank → Add/Import
4. **Create Exam** → Choose source, set question counts, configure marks
5. **Activate Exam** → Manage Exams → click Activate
6. **Start Exam** → click Start Exam (students can now begin)
7. **Monitor** → Dashboard shows live count
8. **Stop Exam** → auto-submits all active students
9. **View Results** → Results page with export

### Student Flow
1. Login at `/student/login.php` with mobile + DOB
2. Read Step 1 instructions → click Next
3. Read Step 2 rules → check agreement → click "I am ready to begin"
4. Exam auto-enters full-screen
5. Answer questions, use palette to navigate
6. Mark for review if unsure
7. Submit when done (or timer auto-submits)
8. View result immediately

---

## 🛠 Customization

### Change Exam Categories
Edit `includes/questions.php` → `buildQuestionPrompt()` to customize AI prompt topics.

### Add More Question Categories
Add new `ENUM` values to `question_bank.category` and `ai_generated_questions.category` columns, then update the category labels in `exam.php`.

### Password Reset (Admin)
```php
echo password_hash('new_password', PASSWORD_DEFAULT);
// Paste hash into DB: UPDATE admin_users SET password='...' WHERE id=1;
```

### Disable Negative Marking
Set `negative_marks = 0` when creating the exam.

---

## ⚙️ Production Checklist
- [ ] Change default admin password
- [ ] Set `display_errors = Off` in `php.ini`
- [ ] Enable HTTPS (update `secure` flag in session cookie settings in `auth.php`)
- [ ] Set up regular DB backups
- [ ] Configure proper file permissions (755 directories, 644 files)
- [ ] Add rate limiting to `student/login.php` (e.g., via Nginx)

<?php
// feedback.php — single-file feedback form with auto DB/table setup + JS niceties
declare(strict_types=1);
session_start();

/* === CONFIG (edit if needed) === */
$DB_HOST = 'localhost';
$DB_NAME = 'feedback';   // will be auto-created if missing
$DB_USER = 'root';    // change if your MySQL user differs
$DB_PASS = '@1Diomaster20';        // set if your root has a password

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* === CONNECT & AUTO-CREATE DB/TABLE === */
try {
    // Connect to server (no DB), ensure DB exists
    $server = new mysqli($DB_HOST, $DB_USER, $DB_PASS);
    $server->set_charset('utf8mb4');
    $server->query("CREATE DATABASE IF NOT EXISTS `{$DB_NAME}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    // Connect to DB
    $db = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    $db->set_charset('utf8mb4');

    // Ensure table exists
    $db->query("
        CREATE TABLE IF NOT EXISTS `feedback` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL,
            `email` VARCHAR(255) NOT NULL,
            `rating` TINYINT UNSIGNED NOT NULL,
            `message` TEXT NOT NULL,
            `user_agent` VARCHAR(255) DEFAULT NULL,
            `ip` VARCHAR(45) DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    die('Database setup failed. Open this file and check the DB credentials at the top.');
}

/* === CSRF TOKEN === */
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$errors = [];
$success = false;

function posted(string $key, string $default = ''): string {
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

/* === HANDLE POST === */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Honeypot: bots tend to fill this
    if (!empty($_POST['website'])) {
        $success = true; // silently accept but do nothing
    } else {
        // CSRF
        if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], (string)$_POST['csrf'])) {
            $errors[] = 'Invalid form token. Please refresh and try again.';
        }

        $name    = posted('name');
        $email   = posted('email');
        $rating  = posted('rating');
        $message = posted('message');

        // Validate
        if ($name === '' || mb_strlen($name) > 100) {
            $errors[] = 'Name is required (max 100 chars).';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
            $errors[] = 'Valid email is required (max 255 chars).';
        }
        if ($rating === '' || !ctype_digit($rating) || (int)$rating < 1 || (int)$rating > 5) {
            $errors[] = 'Rating must be a number 1–5.';
        }
        if ($message === '' || mb_strlen($message) > 2000) {
            $errors[] = 'Message is required (max 2000 chars).';
        }

        if (!$errors) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $r  = (int)$rating;

            $stmt = $db->prepare("INSERT INTO `feedback` (`name`,`email`,`rating`,`message`,`user_agent`,`ip`) VALUES (?,?,?,?,?,?)");
            $stmt->bind_param('ssisss', $name, $email, $r, $message, $ua, $ip);
            $stmt->execute();

            $success = true;

            // Reset CSRF and fields (so the form clears)
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            $name = $email = $message = '';
            $rating = '';
        }
    }

    // AJAX mode? Return JSON
    $isAjax = (
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'fetch') ||
        (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    );
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => ($success && !$errors), 'errors' => $errors]);
        exit;
    }
}

/* === STICKY VALUES === */
$nameVal    = htmlspecialchars($name ?? posted('name'), ENT_QUOTES, 'UTF-8');
$emailVal   = htmlspecialchars($email ?? posted('email'), ENT_QUOTES, 'UTF-8');
$ratingVal  = htmlspecialchars($rating ?? posted('rating'), ENT_QUOTES, 'UTF-8');
$messageVal = htmlspecialchars($message ?? posted('message'), ENT_QUOTES, 'UTF-8');
$csrf       = $_SESSION['csrf'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Feedback</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<style>
  body { background:#f7f7f7; }
  .card { border-radius: 12px; }
  .hp {position:absolute; left:-9999px; top:auto; width:1px; height:1px; overflow:hidden;}
  .star { cursor: pointer; font-size: 1.4rem; opacity: .35; transition: opacity .15s ease; }
  .star.active { opacity: 1; }
</style>
</head>
<body class="py-5">
  <div class="container" style="max-width:720px;">
    <div class="card shadow-sm">
      <div class="card-body">
        <h1 class="h3 mb-3">Feedback</h1>

        <?php if ($success && !$errors): ?>
          <div class="alert alert-success">Thanks! Your feedback was submitted.</div>
        <?php endif; ?>

        <?php if ($errors): ?>
          <div class="alert alert-danger">
            <strong>Please fix the following:</strong>
            <ul class="mb-0">
              <?php foreach ($errors as $e): ?>
                <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <form method="post" novalidate>
          <input type="hidden" name="csrf" value="<?= $csrf ?>">

          <!-- Honeypot -->
          <div class="hp">
            <label>Website (leave blank)</label>
            <input type="text" name="website" autocomplete="off">
          </div>

          <div class="form-group">
            <label for="name">Name <span class="text-danger">*</span></label>
            <input id="name" name="name" type="text" class="form-control" maxlength="100" required value="<?= $nameVal ?>">
          </div>

          <div class="form-group">
            <label for="email">Email <span class="text-danger">*</span></label>
            <input id="email" name="email" type="email" class="form-control" maxlength="255" required value="<?= $emailVal ?>">
          </div>

          <div class="form-group">
            <label for="rating">Rating (1–5) <span class="text-danger">*</span></label>
            <select id="rating" name="rating" class="form-control" required>
              <option value="">Choose…</option>
              <?php for ($i=1; $i<=5; $i++): ?>
                <option value="<?= $i ?>" <?= ($ratingVal === (string)$i ? 'selected' : '') ?>><?= $i ?></option>
              <?php endfor; ?>
            </select>
            <div class="mt-2" id="ratingStars" aria-label="Choose rating with stars">
              <span data-val="1" class="star" role="button" aria-label="1 star">★</span>
              <span data-val="2" class="star" role="button" aria-label="2 stars">★</span>
              <span data-val="3" class="star" role="button" aria-label="3 stars">★</span>
              <span data-val="4" class="star" role="button" aria-label="4 stars">★</span>
              <span data-val="5" class="star" role="button" aria-label="5 stars">★</span>
            </div>
          </div>

          <div class="form-group">
            <label for="message">
              Message <span class="text-danger">*</span>
              <small class="text-muted ml-2" id="msgCount">0/2000</small>
            </label>
            <textarea id="message" name="message" rows="5" class="form-control" maxlength="2000" required><?= $messageVal ?></textarea>
          </div>

          <button type="submit" class="btn btn-primary">Send Feedback</button>
        </form>
      </div>
    </div>

    <p class="text-muted small mt-3 mb-0">
      Tip: If this page says “Database setup failed”, open this file and fix the DB credentials at the top.
    </p>
  </div>

<script>
(function() {
  const form = document.querySelector('form');
  const email = document.getElementById('email');
  const nameEl = document.getElementById('name');
  const ratingSel = document.getElementById('rating');
  const message = document.getElementById('message');
  const msgCount = document.getElementById('msgCount');
  const starsWrap = document.getElementById('ratingStars');

  // Live char counter
  function updateCount() { msgCount.textContent = `${message.value.length}/2000`; }
  message.addEventListener('input', updateCount);
  updateCount();

  // Star UI sync
  if (starsWrap) {
    const stars = [...starsWrap.querySelectorAll('.star')];
    function paint(val) { stars.forEach(s => s.classList.toggle('active', Number(s.dataset.val) <= Number(val))); }
    paint(ratingSel.value || 0);
    starsWrap.addEventListener('click', e => {
      const s = e.target.closest('.star'); if (!s) return;
      ratingSel.value = s.dataset.val; paint(s.dataset.val);
    });
    ratingSel.addEventListener('change', () => paint(ratingSel.value));
  }

  // Simple client validation
  function isEmail(v){ return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v); }
  function show(type, html) {
    let box = document.querySelector('.alert-' + type);
    if (!box) {
      box = document.createElement('div');
      box.className = 'alert alert-' + type;
      form.parentNode.insertBefore(box, form);
    }
    box.innerHTML = html;
    window.scrollTo({top: box.getBoundingClientRect().top + window.scrollY - 20, behavior: 'smooth'});
  }
  function clearAlerts(){ document.querySelectorAll('.alert').forEach(a => a.remove()); }

  // AJAX submit (no page reload)
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearAlerts();

    const errs = [];
    if (!nameEl.value.trim() || nameEl.value.trim().length > 100) errs.push('Name is required (max 100 chars).');
    if (!isEmail(email.value) || email.value.length > 255) errs.push('Valid email is required (max 255 chars).');
    const r = Number(ratingSel.value || 0);
    if (!(r >= 1 && r <= 5)) errs.push('Rating must be a number 1–5.');
    if (!message.value.trim() || message.value.length > 2000) errs.push('Message is required (max 2000 chars).');

    if (errs.length) {
      show('danger', '<strong>Please fix the following:</strong><ul class="mb-0">' + errs.map(e=>`<li>${e}</li>`).join('') + '</ul>');
      return;
    }

    const btn = form.querySelector('button[type="submit"]');
    const original = btn.textContent; btn.disabled = true; btn.textContent = 'Sending…';

    try {
      const fd = new FormData(form);
      const res = await fetch(window.location.href, {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
      });
      const data = await res.json();
      if (data.ok) {
        show('success', 'Thanks! Your feedback was submitted.');
        form.reset(); updateCount();
        if (starsWrap) [...starsWrap.querySelectorAll('.star')].forEach(s=>s.classList.remove('active'));
      } else {
        show('danger', '<strong>Please fix the following:</strong><ul class="mb-0">' + (data.errors||['Could not save feedback.']).map(e=>`<li>${e}</li>`).join('') + '</ul>');
      }
    } catch (err) {
      show('danger', 'Network error. Please try again.');
    } finally {
      btn.disabled = false; btn.textContent = original;
    }
  });
})();
</script>
</body>
</html>

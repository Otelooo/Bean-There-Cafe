<?php
$plain = '';
$hash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $plain = trim($_POST['plain'] ?? '');
    if ($plain !== '') {
        $hash = password_hash($plain, PASSWORD_DEFAULT);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Password Hash Generator</title>
    <style>
        body { font-family: sans-serif; max-width: 480px; margin: 60px auto; color: #2C2C2C; }
        input, textarea { width: 100%; padding: 10px; font-size: 14px; box-sizing: border-box; }
        button { margin-top: 10px; padding: 10px 18px; cursor: pointer; }
        textarea { margin-top: 12px; height: 70px; font-family: monospace; }
        p.hint { color: #666; font-size: 13px; }
    </style>
</head>
<body>
    <h2>Password Hash Generator</h2>
    <p class="hint">Type the plain-text password you want to log in with. Copy the generated hash into the <code>password</code> column of your row in the <code>users</code> table (via phpMyAdmin), replacing whatever is there now.</p>
    <form method="POST" action="generate_hash.php">
        <input type="text" name="plain" placeholder="plain password" value="<?= htmlspecialchars($plain) ?>">
        <button type="submit">Generate hash</button>
    </form>
    <?php if ($hash): ?>
        <p><strong>Hash for "<?= htmlspecialchars($plain) ?>":</strong></p>
        <textarea readonly onclick="this.select()"><?= htmlspecialchars($hash) ?></textarea>
        <p class="hint">Paste this exact value into the <code>password</code> column for that user's row. Then log in with the plain password above (not the hash).</p>
    <?php endif; ?>
    <p class="hint">Delete this file once you're done — it's a one-time setup tool, not something that should stay reachable in the browser.</p>
</body>
</html>

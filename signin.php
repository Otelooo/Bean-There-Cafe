<?php
session_start();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/settings_helper.php';

$settings = get_system_settings($conn);
$error = '';
$username = '';
$successMessage = '';

if (isset($_GET['recovered']) && $_GET['recovered'] === '1') {
    $successMessage = 'Password reset successfully. You can now sign in with your new password.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        $stmt = $conn->prepare('SELECT user_id, username, password, role, status FROM users WHERE username = ?');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        // TEMPORARY: accepts both hashed and plain-text passwords while testing.
        // Later, remove the "|| $password === $user['password']" part to require hashed passwords only.
        if ($user && (password_verify($password, $user['password']) || $password === $user['password'])) {
            if ($user['status'] === 'inactive') {
                $error = 'This account has been deactivated. Contact the café owner.';
            } else {
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];

                header('Location: ' . ($user['role'] === 'cafe owner' ? 'owner/dashboard.php' : 'staff/staffdashboard.php'));
                exit;
            }
        } else {
            $error = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Sign In | SmartStock — Bean There Café</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link
        href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap"
        rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet" />
    <style>
        :root {
            --mocha: #4A2C2A;
            --mocha-deep: #2E1A18;
            --mocha-mid: #6B3D3A;
            --cream: #F5ECD7;
            --cream-light: #FBF6EE;
            --cream-dark: #E8D8BA;
            --charcoal: #2C2C2C;
            --charcoal-mid: #444444;
            --gold: #C9943A;
            --gold-light: #E8B860;
            --sage: #7A9E7E;
            --red-soft: #C0392B;
            --shadow-sm: 0 1px 2px rgba(74, 44, 42, .06), 0 3px 10px rgba(74, 44, 42, .08);
            --shadow-md: 0 2px 6px rgba(74, 44, 42, .08), 0 10px 28px rgba(74, 44, 42, .16);
            --shadow-lg: 0 4px 14px rgba(74, 44, 42, .12), 0 22px 50px rgba(74, 44, 42, .24);
            --radius: 12px;
            --radius-lg: 18px;
            --font-display: 'Playfair Display', serif;
            --font-body: 'DM Sans', sans-serif;
            --font-mono: 'DM Mono', monospace;
        }

        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: var(--font-body);
            background: linear-gradient(135deg, var(--cream-light) 0%, var(--cream) 50%, #f0e8d5 100%);
            color: var(--charcoal);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            overflow-x: hidden;
        }

        .signin-container {
            background: var(--cream-light);
            border: 1.5px solid var(--cream-dark);
            border-radius: var(--radius-lg);
            padding: 40px 36px;
            max-width: 420px;
            width: 100%;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }

        .signin-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--gold), var(--gold-light), var(--gold));
        }

        .brand-section {
            text-align: center;
            margin-bottom: 32px;
        }

        .brand-logo {
            width: 64px;
            height: 64px;
            border-radius: 16px;
            background: var(--gold);
            color: var(--mocha-deep);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            box-shadow: 0 6px 20px rgba(201, 148, 58, .4);
            margin: 0 auto 16px;
        }

        .brand-name {
            font-family: var(--font-display);
            font-size: 24px;
            font-weight: 800;
            color: var(--mocha-deep);
            margin-bottom: 4px;
        }

        .brand-sub {
            font-size: 13px;
            color: var(--mocha-mid);
            font-weight: 500;
            letter-spacing: 1px;
        }

        .signin-title {
            font-family: var(--font-display);
            font-size: 22px;
            font-weight: 700;
            color: var(--mocha-deep);
            text-align: center;
            margin-bottom: 8px;
        }

        .signin-subtitle {
            font-size: 14px;
            color: var(--charcoal-mid);
            text-align: center;
            margin-bottom: 28px;
        }

        .form-group {
            margin-bottom: 20px;
            position: relative;
        }

        .form-label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            color: var(--charcoal-mid);
            margin-bottom: 6px;
        }

        .form-input {
            width: 100%;
            padding: 14px 18px;
            border: 1.5px solid var(--cream-dark);
            border-radius: var(--radius);
            background: var(--cream);
            font-family: var(--font-body);
            font-size: 15px;
            color: var(--charcoal);
            outline: none;
            transition: all 0.2s;
        }

        .form-input:focus {
            border-color: var(--mocha);
            box-shadow: 0 0 0 3px rgba(74, 44, 42, .08);
        }

        .form-input::placeholder {
            color: #aaa;
        }

        .remember-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
        }

        .remember-checkbox {
            width: 18px;
            height: 18px;
            accent-color: var(--mocha);
            cursor: pointer;
        }

        .remember-label {
            font-size: 14px;
            color: var(--charcoal);
            cursor: pointer;
            user-select: none;
        }

        .signin-btn {
            width: 100%;
            padding: 16px;
            background: var(--mocha);
            color: var(--cream);
            border: none;
            border-radius: var(--radius);
            font-family: var(--font-body);
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            position: relative;
            overflow: hidden;
        }

        .signin-btn:hover {
            background: var(--mocha-mid);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .signin-btn:active {
            transform: translateY(0);
        }

        .links-row {
            display: flex;
            justify-content: space-between;
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid var(--cream-dark);
        }

        .link {
            font-size: 13px;
            color: var(--mocha-mid);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s;
        }

        .link:hover {
            color: var(--mocha);
        }

        .divider {
            text-align: center;
            margin: 28px 0;
            position: relative;
            color: var(--charcoal-mid);
        }

        .divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: var(--cream-dark);
        }

        .divider span {
            background: var(--cream-light);
            padding: 0 16px;
            font-size: 13px;
        }

        .modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 9999;
            background: rgba(20, 10, 8, .58);
            display: none;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(6px);
            padding: 20px;
        }

        .modal-overlay.show {
            display: flex;
        }

        .modal-box {
            background: var(--cream-light);
            border-radius: var(--radius-lg);
            padding: 28px 30px;
            max-width: 400px;
            width: 100%;
            box-shadow: var(--shadow-lg);
            animation: popIn .25s cubic-bezier(.34, 1.56, .64, 1);
        }

        @keyframes popIn {
            from { opacity: 0; transform: scale(.88); }
            to { opacity: 1; transform: scale(1); }
        }

        .modal-title {
            font-family: var(--font-display);
            font-size: 19px;
            font-weight: 700;
            color: var(--mocha-deep);
            margin-bottom: 10px;
        }

        @media (max-width: 480px) {
            .signin-container {
                padding: 32px 24px;
                margin: 10px;
            }

            .brand-logo {
                width: 52px;
                height: 52px;
                font-size: 22px;
            }

            .brand-name {
                font-size: 20px;
            }
        }
    </style>
</head>

<body>
    <main class="signin-container">
        <div class="brand-section">
            <div class="brand-logo">
                <i class="fas fa-mug-hot"></i>
            </div>
            <h1 class="brand-name">SmartStock</h1>
            <p class="brand-sub">Bean There Café</p>
        </div>

        <div class="signin-title">
            Welcome 
        </div>
        

        <?php if ($error): ?>
        <div style="background:#fdecea;color:#C0392B;padding:10px 14px;border-radius:8px;margin-bottom:20px;font-size:14px;text-align:center;">
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <?php if ($successMessage): ?>
        <div style="background:#eaf7ee;color:#2e7d32;padding:10px 14px;border-radius:8px;margin-bottom:20px;font-size:14px;text-align:center;">
            <?= htmlspecialchars($successMessage) ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="signin.php">
            <div class="form-group">
                <label class="form-label" for="username">Username</label>
                <input type="text" name="username" id="username" class="form-input" placeholder="Enter your username" required value="<?= htmlspecialchars($username) ?>">
            </div>

            <div class="form-group">
                <label class="form-label" for="password">Password</label>
                <input type="password" name="password" id="password" class="form-input" placeholder="Enter your password" required>
            </div>

            <div class="remember-row">
                <input type="checkbox" id="remember" class="remember-checkbox">
                <label for="remember" class="remember-label">Remember me</label>
            </div>

            <button type="submit" class="signin-btn">
                <i class="fas fa-arrow-right"></i>
                Sign In
            </button>
        </form>

        <div class="links-row">
            <a href="#" class="link" onclick="openHelpModal('forgot'); return false;">Forgot Password?</a>
            <a href="#" class="link" onclick="openHelpModal('help'); return false;">Need Help?</a>
        </div>
    </main>

    <div class="modal-overlay" id="help-modal">
        <div class="modal-box">
            <h2 class="modal-title" id="help-modal-title"></h2>
            <p id="help-modal-message" style="font-size:14px;color:var(--charcoal-mid);line-height:1.6;margin-bottom:16px;"></p>
            <?php if ($settings['cafe_contact'] !== '' || $settings['cafe_address'] !== ''): ?>
            <div style="background:var(--cream);border:1px dashed var(--cream-dark);border-radius:var(--radius);padding:12px 16px;margin-bottom:8px;">
                <?php if ($settings['cafe_contact'] !== ''): ?>
                <div style="font-size:13.5px;color:var(--mocha-deep);font-weight:600;"><i class="fas fa-phone" style="margin-right:8px;color:var(--gold);"></i><?= htmlspecialchars($settings['cafe_contact']) ?></div>
                <?php endif; ?>
                <?php if ($settings['cafe_address'] !== ''): ?>
                <div style="font-size:13.5px;color:var(--mocha-deep);font-weight:600;margin-top:<?= $settings['cafe_contact'] !== '' ? '6px' : '0' ?>;"><i class="fas fa-location-dot" style="margin-right:8px;color:var(--gold);"></i><?= htmlspecialchars($settings['cafe_address']) ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <a href="recover_account.php" class="link" id="help-modal-recover-link" style="display:none;text-align:center;font-weight:600;margin-top:4px;">
                <i class="fas fa-user-shield" style="margin-right:6px;"></i>Are you the owner? Recover your account →
            </a>
            <button type="button" class="signin-btn" style="margin-top:16px;" onclick="closeHelpModal()">Close</button>
        </div>
    </div>

    <script>
        // Password resets and account help are both handled by the café owner/admin directly
        // (no email/SMTP setup exists in this app, and the owner can already reset any user's
        // password from User Management) — so both links open the same modal with different copy.
        function openHelpModal(type) {
            const title = document.getElementById('help-modal-title');
            const message = document.getElementById('help-modal-message');
            const recoverLink = document.getElementById('help-modal-recover-link');
            if (type === 'forgot') {
                title.textContent = 'Forgot Your Password?';
                message.textContent = 'Staff: the café owner/administrator resets your password directly from User Management — please reach out to them using the details below. Owner: if you set up security questions in Settings, you can recover this account yourself.';
                recoverLink.style.display = 'block';
            } else {
                title.textContent = 'Need Help?';
                message.textContent = 'Having trouble signing in or using SmartStock? Contact the café owner/administrator using the details below.';
                recoverLink.style.display = 'none';
            }
            document.getElementById('help-modal').classList.add('show');
        }
        function closeHelpModal() {
            document.getElementById('help-modal').classList.remove('show');
        }
        document.getElementById('help-modal').addEventListener('click', e => {
            if (e.target.id === 'help-modal') closeHelpModal();
        });
    </script>
</body>

</html>
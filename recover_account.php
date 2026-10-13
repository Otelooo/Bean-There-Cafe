<?php
session_start();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/password_reset_helper.php';

const OTP_LIFETIME_MINUTES = 10;
const OTP_MAX_ATTEMPTS = 5;
const OTP_MAX_REQUESTS_PER_HOUR = 5;
const OTP_RESEND_COOLDOWN_SECONDS = 60;
const PASSWORD_RESET_SESSION_LIFETIME = 600;

function clear_recovery_session(): void
{
    unset(
        $_SESSION['recovery_user_id'],
        $_SESSION['recovery_email'],
        $_SESSION['recovery_stage'],
        $_SESSION['recovery_verified_at']
    );
}

function recovery_csrf_token(): string
{
    if (empty($_SESSION['recovery_csrf_token'])) {
        $_SESSION['recovery_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['recovery_csrf_token'];
}

function issue_recovery_code(mysqli $conn, int $userId, string $email, string $username): bool
{
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('INSERT IGNORE INTO password_reset_otps (user_id) VALUES (?)');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare('SELECT request_count, rate_window_started_at, last_requested_at FROM password_reset_otps WHERE user_id = ? FOR UPDATE');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $now = time();
        $windowStartedAt = !empty($row['rate_window_started_at']) ? strtotime($row['rate_window_started_at']) : false;
        $lastRequestedAt = !empty($row['last_requested_at']) ? strtotime($row['last_requested_at']) : false;
        $requestCount = (int)$row['request_count'];

        if ($windowStartedAt === false || $now - $windowStartedAt >= 3600) {
            $requestCount = 0;
            $windowStartedAt = $now;
        }

        if ($requestCount >= OTP_MAX_REQUESTS_PER_HOUR
            || ($lastRequestedAt !== false && $now - $lastRequestedAt < OTP_RESEND_COOLDOWN_SECONDS)) {
            $conn->commit();
            return false;
        }

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $codeHash = password_hash($code, PASSWORD_DEFAULT);
        $requestCount++;
        $windowStarted = date('Y-m-d H:i:s', $windowStartedAt);
        $stmt = $conn->prepare('UPDATE password_reset_otps SET otp_hash = ?, expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE), attempts = 0, request_count = ?, rate_window_started_at = ?, last_requested_at = NOW() WHERE user_id = ?');
        $stmt->bind_param('sisi', $codeHash, $requestCount, $windowStarted, $userId);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    try {
        send_password_reset_code($email, $username, $code);
        return true;
    } catch (PHPMailer\PHPMailer\Exception | RuntimeException $e) {
        error_log('Password reset email delivery failed for user ID ' . $userId . '.');
        $stmt = $conn->prepare('UPDATE password_reset_otps SET otp_hash = NULL, expires_at = NULL WHERE user_id = ? AND otp_hash = ?');
        $stmt->bind_param('is', $userId, $codeHash);
        $stmt->execute();
        $stmt->close();
        return false;
    }
}

$error = '';
$notice = '';
$stage = $_SESSION['recovery_stage'] ?? 'email';
$token = recovery_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($token, (string)($_POST['csrf_token'] ?? ''))) {
        $error = 'This recovery request expired. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'request_code' || $action === 'resend_code') {
            $email = strtolower(trim((string)($_POST['email'] ?? $_SESSION['recovery_email'] ?? '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Enter a valid email address.';
            } else {
                $stmt = $conn->prepare("SELECT user_id, username, email FROM users WHERE email = ? AND status = 'active' LIMIT 1");
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $account = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                $_SESSION['recovery_email'] = $email;
                $_SESSION['recovery_user_id'] = $account ? (int)$account['user_id'] : 0;
                $_SESSION['recovery_stage'] = 'code';

                if ($account) {
                    try {
                        issue_recovery_code($conn, (int)$account['user_id'], $account['email'], $account['username']);
                    } catch (Throwable $e) {
                        error_log('Password reset code could not be issued for user ID ' . (int)$account['user_id'] . '.');
                    }
                }
                $notice = 'If this email belongs to an active account and the request is permitted, a 6-digit code has been sent. Check your inbox and spam folder.';
                header('Location: recover_account.php?requested=1');
                exit;
            }
        } elseif ($action === 'verify_code' && $stage === 'code') {
            $userId = (int)($_SESSION['recovery_user_id'] ?? 0);
            $code = trim((string)($_POST['code'] ?? ''));
            $verified = false;

            if ($userId > 0 && preg_match('/^\d{6}$/', $code) === 1) {
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare('SELECT otp_hash, expires_at, attempts FROM password_reset_otps WHERE user_id = ? FOR UPDATE');
                    $stmt->bind_param('i', $userId);
                    $stmt->execute();
                    $otp = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if ($otp && !empty($otp['otp_hash']) && strtotime($otp['expires_at']) > time()
                        && (int)$otp['attempts'] < OTP_MAX_ATTEMPTS
                        && password_verify($code, $otp['otp_hash'])) {
                        $stmt = $conn->prepare('UPDATE password_reset_otps SET otp_hash = NULL, expires_at = NULL WHERE user_id = ?');
                        $stmt->bind_param('i', $userId);
                        $stmt->execute();
                        $stmt->close();
                        $verified = true;
                    } elseif ($otp && !empty($otp['otp_hash'])) {
                        $stmt = $conn->prepare('UPDATE password_reset_otps SET attempts = attempts + 1, otp_hash = IF(attempts + 1 >= ?, NULL, otp_hash), expires_at = IF(attempts + 1 >= ?, NULL, expires_at) WHERE user_id = ?');
                        $maxAttempts = OTP_MAX_ATTEMPTS;
                        $stmt->bind_param('iii', $maxAttempts, $maxAttempts, $userId);
                        $stmt->execute();
                        $stmt->close();
                    }
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    throw $e;
                }
            }

            if ($verified) {
                session_regenerate_id(true);
                $_SESSION['recovery_stage'] = 'reset';
                $_SESSION['recovery_verified_at'] = time();
                header('Location: recover_account.php');
                exit;
            }
            $error = 'That code is invalid, expired, or no longer available. Request a new code and try again.';
        } elseif ($action === 'reset_password' && $stage === 'reset') {
            $userId = (int)($_SESSION['recovery_user_id'] ?? 0);
            $verifiedAt = (int)($_SESSION['recovery_verified_at'] ?? 0);
            $newPassword = (string)($_POST['password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');

            if ($userId <= 0 || $verifiedAt <= 0 || time() - $verifiedAt > PASSWORD_RESET_SESSION_LIFETIME) {
                clear_recovery_session();
                $stage = 'email';
                $error = 'Your verification has expired. Please request a new code.';
            } elseif ($newPassword === '') {
                $error = 'Password cannot be empty.';
            } elseif (strlen($newPassword) > 72) {
                $error = 'Password must be no more than 72 bytes long.';
            } elseif ($newPassword !== $confirmPassword) {
                $error = 'Passwords do not match.';
            } else {
                $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ? AND status = 'active'");
                $stmt->bind_param('si', $hashed, $userId);
                $stmt->execute();
                $updated = $stmt->affected_rows === 1;
                $stmt->close();

                if ($updated) {
                    $stmt = $conn->prepare('DELETE FROM password_reset_otps WHERE user_id = ?');
                    $stmt->bind_param('i', $userId);
                    $stmt->execute();
                    $stmt->close();
                    clear_recovery_session();
                    header('Location: signin.php?recovered=1');
                    exit;
                }
                clear_recovery_session();
                $stage = 'email';
                $error = 'This account can no longer be reset. Please contact the café owner.';
            }
        } elseif ($action === 'start_over') {
            clear_recovery_session();
            header('Location: recover_account.php');
            exit;
        } else {
            $error = 'This recovery step is no longer available. Please start over.';
        }
    }
}

if (isset($_GET['requested']) && $_GET['requested'] === '1') {
    $stage = 'code';
    $notice = 'If this email belongs to an active account and the request is permitted, a 6-digit code has been sent. Check your inbox and spam folder.';
}

if (($_SESSION['recovery_stage'] ?? '') === 'reset'
    && time() - (int)($_SESSION['recovery_verified_at'] ?? 0) > PASSWORD_RESET_SESSION_LIFETIME) {
    clear_recovery_session();
}
$stage = $_SESSION['recovery_stage'] ?? 'email';
$emailValue = (string)($_SESSION['recovery_email'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Recover Account | SmartStock — Bean There Café</title>
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
            --shadow-lg: 0 12px 40px rgba(74, 44, 42, .22);
            --radius: 12px;
            --radius-lg: 18px;
            --font-display: 'Playfair Display', serif;
            --font-body: 'DM Sans', sans-serif;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: var(--font-body);
            background: linear-gradient(135deg, var(--cream-light) 0%, var(--cream) 50%, #f0e8d5 100%);
            color: var(--charcoal);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .signin-container {
            background: var(--cream-light);
            border: 1.5px solid var(--cream-dark);
            border-radius: var(--radius-lg);
            padding: 40px 36px;
            max-width: 440px;
            width: 100%;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }

        .signin-container::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--gold), var(--gold-light), var(--gold));
        }

        .brand-section { text-align: center; margin-bottom: 28px; }
        .brand-logo {
            width: 60px; height: 60px; border-radius: 16px;
            background: var(--gold); color: var(--mocha-deep);
            display: flex; align-items: center; justify-content: center;
            font-size: 26px; box-shadow: 0 6px 20px rgba(201, 148, 58, .4);
            margin: 0 auto 14px;
        }
        .brand-name { font-family: var(--font-display); font-size: 22px; font-weight: 800; color: var(--mocha-deep); margin-bottom: 4px; }
        .brand-sub { font-size: 12.5px; color: var(--mocha-mid); font-weight: 500; letter-spacing: 1px; }

        .signin-title { font-family: var(--font-display); font-size: 21px; font-weight: 700; color: var(--mocha-deep); text-align: center; margin-bottom: 6px; }
        .signin-subtitle { font-size: 13.5px; color: var(--charcoal-mid); text-align: center; margin-bottom: 26px; line-height: 1.5; }

        .step-indicator { display: flex; justify-content: center; gap: 8px; margin-bottom: 26px; }
        .step-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--cream-dark); }
        .step-dot.active { background: var(--gold); }
        .step-dot.done { background: var(--sage); }

        .form-group { margin-bottom: 18px; }
        .form-label { display: block; font-size: 11px; font-weight: 700; letter-spacing: 0.8px; text-transform: uppercase; color: var(--charcoal-mid); margin-bottom: 6px; }
        .question-label { display: block; font-size: 14px; font-weight: 600; color: var(--mocha-deep); margin-bottom: 8px; }
        .form-input {
            width: 100%; padding: 13px 16px; border: 1.5px solid var(--cream-dark); border-radius: var(--radius);
            background: var(--cream); font-family: var(--font-body); font-size: 14.5px; color: var(--charcoal);
            outline: none; transition: all 0.2s;
        }
        .form-input:focus { border-color: var(--mocha); box-shadow: 0 0 0 3px rgba(74, 44, 42, .08); }

        .signin-btn {
            width: 100%; padding: 15px; background: var(--mocha); color: var(--cream); border: none;
            border-radius: var(--radius); font-family: var(--font-body); font-size: 15.5px; font-weight: 700;
            cursor: pointer; transition: all 0.2s; display: flex; align-items: center; justify-content: center; gap: 8px;
        }
        .signin-btn:hover { background: var(--mocha-mid); }

        .links-row { text-align: center; margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--cream-dark); }
        .link { font-size: 13px; color: var(--mocha-mid); text-decoration: none; font-weight: 500; }
        .link:hover { color: var(--mocha); }

        @media (max-width: 640px) {
            body {
                align-items: flex-start;
                padding: 24px 16px;
            }

            .signin-container {
                padding: 32px 26px;
                overflow-wrap: anywhere;
            }

            .brand-section { margin-bottom: 24px; }
            .brand-logo { width: 54px; height: 54px; font-size: 23px; }
            .brand-name { font-size: 20px; }
            .signin-title, .signin-subtitle, .links-row { overflow-wrap: anywhere; }
            .form-input, .signin-btn { min-width: 0; }
        }

        @media (max-width: 380px) {
            body { padding: 14px 10px; }
            .signin-container { padding: 26px 18px; }
            .signin-subtitle { font-size: 13px; }
            .signin-btn { padding: 13px 10px; font-size: 14px; }
        }
    </style>
</head>

<body>
    <main class="signin-container">
        <div class="brand-section">
            <div class="brand-logo"><i class="fas fa-user-shield"></i></div>
            <h1 class="brand-name">SmartStock</h1>
            <p class="brand-sub">Bean There Café</p>
        </div>

        <div class="step-indicator">
            <div class="step-dot <?= $stage === 'email' ? 'active' : 'done' ?>"></div>
            <div class="step-dot <?= $stage === 'code' ? 'active' : ($stage === 'reset' ? 'done' : '') ?>"></div>
            <div class="step-dot <?= $stage === 'reset' ? 'active' : '' ?>"></div>
        </div>

        <?php if ($error): ?>
        <div style="background:#fdecea;color:#C0392B;padding:10px 14px;border-radius:8px;margin-bottom:20px;font-size:14px;text-align:center;">
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <?php if ($notice): ?>
        <div style="background:#eaf7ee;color:#2e7d32;padding:10px 14px;border-radius:8px;margin-bottom:20px;font-size:14px;text-align:center;">
            <?= htmlspecialchars($notice) ?>
        </div>
        <?php endif; ?>

        <?php if ($stage === 'email'): ?>
            <div class="signin-title">Reset Your Password</div>
            <p class="signin-subtitle">Enter the email address registered to your account. We will send a 6-digit verification code.</p>
            <form method="POST" action="recover_account.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="request_code">
                <div class="form-group">
                    <label class="form-label" for="email">Registered Email</label>
                    <input type="email" name="email" id="email" class="form-input" placeholder="you@example.com" required autofocus autocomplete="email" value="<?= htmlspecialchars($emailValue) ?>">
                </div>
                <button type="submit" class="signin-btn"><i class="fas fa-paper-plane"></i> Send Verification Code</button>
            </form>

        <?php elseif ($stage === 'code'): ?>
            <div class="signin-title">Enter Verification Code</div>
            <p class="signin-subtitle">Enter the 6-digit code sent to <?= htmlspecialchars($emailValue) ?>. The code expires in 10 minutes.</p>
            <form method="POST" action="recover_account.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="verify_code">
                <div class="form-group">
                    <label class="form-label" for="code">Verification Code</label>
                    <input type="text" name="code" id="code" class="form-input" placeholder="000000" required autofocus autocomplete="one-time-code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6">
                </div>
                <button type="submit" class="signin-btn"><i class="fas fa-check"></i> Verify Code</button>
            </form>
            <form method="POST" action="recover_account.php" style="margin-top:10px;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="resend_code">
                <button type="submit" class="link" style="background:none;border:none;cursor:pointer;width:100%;">Send a new code</button>
            </form>

        <?php elseif ($stage === 'reset'): ?>
            <div class="signin-title">Set a New Password</div>
            <p class="signin-subtitle">Email verified. Choose a new password for your account.</p>
            <form method="POST" action="recover_account.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="reset_password">
                <div class="form-group">
                    <label class="form-label" for="password">New Password</label>
                    <input type="password" name="password" id="password" class="form-input" placeholder="Enter a new password" required maxlength="72" autofocus autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label class="form-label" for="confirm_password">Confirm Password</label>
                    <input type="password" name="confirm_password" id="confirm_password" class="form-input" placeholder="Re-enter password" required maxlength="72" autocomplete="new-password">
                </div>
                <button type="submit" class="signin-btn"><i class="fas fa-lock"></i> Reset Password</button>
            </form>
        <?php endif; ?>

        <div class="links-row">
            <?php if ($stage !== 'email'): ?>
                <form method="POST" action="recover_account.php" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                    <input type="hidden" name="action" value="start_over">
                    <button type="submit" class="link" style="background:none;border:none;cursor:pointer;padding:0;">Start Over</button>
                </form>
                &nbsp;·&nbsp;
            <?php endif; ?>
            <a href="signin.php" class="link"><i class="fas fa-arrow-left" style="margin-right:4px;"></i>Back to Sign In</a>
        </div>
    </main>
</body>

</html>

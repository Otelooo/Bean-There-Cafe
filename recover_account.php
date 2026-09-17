<?php
session_start();
require_once __DIR__ . '/db_connect.php';

// Three-stage, session-driven wizard (PRG pattern — every POST redirects back to itself so a page
// refresh never resubmits a step): 'username' -> 'answer' -> 'reset'. Only an owner-role account
// with both security questions already configured (set up in Settings while still logged in) can
// be recovered this way; staff accounts are reset by the owner instead, not through this page.
const MAX_ANSWER_ATTEMPTS = 5;

function normalize_security_answer(string $answer): string
{
    return mb_strtolower(trim($answer));
}

function reset_recovery_session(): void
{
    unset($_SESSION['recovery_user_id'], $_SESSION['recovery_stage'], $_SESSION['recovery_attempts']);
}

$error = '';
$stage = $_SESSION['recovery_stage'] ?? 'username';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'lookup') {
        $lookupUsername = trim($_POST['username'] ?? '');
        if ($lookupUsername === '') {
            $error = 'Please enter the owner account\'s username.';
        } else {
            $stmt = $conn->prepare("SELECT user_id, security_question_1, security_question_2 FROM users WHERE username = ? AND role = 'cafe owner'");
            $stmt->bind_param('s', $lookupUsername);
            $stmt->execute();
            $owner = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($owner && !empty($owner['security_question_1']) && !empty($owner['security_question_2'])) {
                $_SESSION['recovery_user_id'] = (int)$owner['user_id'];
                $_SESSION['recovery_stage'] = 'answer';
                $_SESSION['recovery_attempts'] = 0;
                header('Location: recover_account.php');
                exit;
            }
            // Same message whether the username doesn't exist, isn't an owner, or has no
            // questions set up — avoids confirming which of those is true to an outside visitor.
            $error = "We couldn't find a recoverable owner account with that username. Security questions may not be set up yet — see Settings → Account Recovery once signed in.";
        }
    } elseif ($action === 'verify' && $stage === 'answer') {
        $userId = (int)($_SESSION['recovery_user_id'] ?? 0);
        $stmt = $conn->prepare("SELECT security_answer_1_hash, security_answer_2_hash FROM users WHERE user_id = ? AND role = 'cafe owner'");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $owner = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $a1 = normalize_security_answer($_POST['answer_1'] ?? '');
        $a2 = normalize_security_answer($_POST['answer_2'] ?? '');

        if ($owner && password_verify($a1, $owner['security_answer_1_hash']) && password_verify($a2, $owner['security_answer_2_hash'])) {
            $_SESSION['recovery_stage'] = 'reset';
            header('Location: recover_account.php');
            exit;
        }

        $_SESSION['recovery_attempts'] = ($_SESSION['recovery_attempts'] ?? 0) + 1;
        if ($_SESSION['recovery_attempts'] >= MAX_ANSWER_ATTEMPTS) {
            reset_recovery_session();
            $error = 'Too many incorrect attempts. Please start over.';
            $stage = 'username';
        } else {
            $error = 'One or both answers were incorrect. Please try again.';
        }
    } elseif ($action === 'reset' && $stage === 'reset') {
        $userId = (int)($_SESSION['recovery_user_id'] ?? 0);
        $newPassword = trim($_POST['password'] ?? '');
        $confirmPassword = trim($_POST['confirm_password'] ?? '');

        if (strlen($newPassword) < 8) {
            $error = 'Password must be at least 8 characters long.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } else {
            $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ? AND role = 'cafe owner'");
            $stmt->bind_param('si', $hashed, $userId);
            $stmt->execute();
            $stmt->close();

            reset_recovery_session();
            header('Location: signin.php?recovered=1');
            exit;
        }
    } elseif ($action === 'start_over') {
        reset_recovery_session();
        header('Location: recover_account.php');
        exit;
    }
}

// Re-derive the stage from session after any POST handling above (may have changed it).
$stage = $_SESSION['recovery_stage'] ?? 'username';

$questions = ['', ''];
if ($stage === 'answer') {
    $userId = (int)($_SESSION['recovery_user_id'] ?? 0);
    $stmt = $conn->prepare('SELECT security_question_1, security_question_2 FROM users WHERE user_id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        // Session pointed at a user row that's gone — restart cleanly rather than show a broken form.
        reset_recovery_session();
        $stage = 'username';
    } else {
        $questions = [$row['security_question_1'], $row['security_question_2']];
    }
}
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
            --shadow-lg: 0 4px 14px rgba(74, 44, 42, .12), 0 22px 50px rgba(74, 44, 42, .24);
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

        @media (max-width: 480px) {
            .signin-container { padding: 32px 22px; }
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
            <div class="step-dot <?= $stage === 'username' ? 'active' : 'done' ?>"></div>
            <div class="step-dot <?= $stage === 'answer' ? 'active' : ($stage === 'reset' ? 'done' : '') ?>"></div>
            <div class="step-dot <?= $stage === 'reset' ? 'active' : '' ?>"></div>
        </div>

        <?php if ($error): ?>
        <div style="background:#fdecea;color:#C0392B;padding:10px 14px;border-radius:8px;margin-bottom:20px;font-size:14px;text-align:center;">
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <?php if ($stage === 'username'): ?>
            <div class="signin-title">Recover Owner Account</div>
            <p class="signin-subtitle">Enter the owner account's username to answer its security questions.</p>
            <form method="POST" action="recover_account.php">
                <input type="hidden" name="action" value="lookup">
                <div class="form-group">
                    <label class="form-label" for="username">Owner Username</label>
                    <input type="text" name="username" id="username" class="form-input" placeholder="Enter the owner's username" required autofocus>
                </div>
                <button type="submit" class="signin-btn"><i class="fas fa-arrow-right"></i> Continue</button>
            </form>

        <?php elseif ($stage === 'answer'): ?>
            <div class="signin-title">Answer Security Questions</div>
            <p class="signin-subtitle">Both answers must be correct to continue. Not case-sensitive.</p>
            <form method="POST" action="recover_account.php">
                <input type="hidden" name="action" value="verify">
                <div class="form-group">
                    <label class="question-label"><?= htmlspecialchars($questions[0]) ?></label>
                    <input type="text" name="answer_1" class="form-input" placeholder="Your answer" required autofocus autocomplete="off">
                </div>
                <div class="form-group">
                    <label class="question-label"><?= htmlspecialchars($questions[1]) ?></label>
                    <input type="text" name="answer_2" class="form-input" placeholder="Your answer" required autocomplete="off">
                </div>
                <button type="submit" class="signin-btn"><i class="fas fa-check"></i> Verify Answers</button>
            </form>

        <?php elseif ($stage === 'reset'): ?>
            <div class="signin-title">Set a New Password</div>
            <p class="signin-subtitle">Answers verified. Choose a new password for this account.</p>
            <form method="POST" action="recover_account.php">
                <input type="hidden" name="action" value="reset">
                <div class="form-group">
                    <label class="form-label" for="password">New Password</label>
                    <input type="password" name="password" id="password" class="form-input" placeholder="Min. 8 characters" required minlength="8" autofocus>
                </div>
                <div class="form-group">
                    <label class="form-label" for="confirm_password">Confirm Password</label>
                    <input type="password" name="confirm_password" id="confirm_password" class="form-input" placeholder="Re-enter password" required minlength="8">
                </div>
                <button type="submit" class="signin-btn"><i class="fas fa-lock"></i> Reset Password</button>
            </form>
        <?php endif; ?>

        <div class="links-row">
            <?php if ($stage !== 'username'): ?>
                <form method="POST" action="recover_account.php" style="display:inline;">
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

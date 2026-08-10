<?php
session_start();
require_once __DIR__ . '/db_connect.php';

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirmPassword = trim($_POST['confirm_password'] ?? '');

    if ($username === '' || $password === '' || $confirmPassword === '') {
        $error = 'Please fill in all fields.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        $check = $conn->prepare('SELECT user_id FROM users WHERE username = ?');
        $check->bind_param('s', $username);
        $check->execute();
        $exists = $check->get_result()->fetch_assoc();
        $check->close();

        if ($exists) {
            $error = 'That username is already taken.';
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $insert = $conn->prepare("INSERT INTO users (username, password, role, status) VALUES (?, ?, 'cafe owner', 'active')");
            $insert->bind_param('ss', $username, $hashed);
            $insert->execute();
            $insert->close();

            header('Location: signin.php?created=1');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Create Admin Account | SmartStock — Bean There Café</title>
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
            --shadow-sm: 0 2px 8px rgba(74, 44, 42, .10);
            --shadow-md: 0 6px 24px rgba(74, 44, 42, .15);
            --shadow-lg: 0 12px 40px rgba(74, 44, 42, .22);
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

        .signup-container {
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

        .signup-container::before {
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

        .signup-title {
            font-family: var(--font-display);
            font-size: 22px;
            font-weight: 700;
            color: var(--mocha-deep);
            text-align: center;
            margin-bottom: 8px;
        }

        .signup-subtitle {
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

        .signup-btn {
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
            margin-top: 8px;
        }

        .signup-btn:hover {
            background: var(--mocha-mid);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .signup-btn:active {
            transform: translateY(0);
        }

        .back-account {
            text-align: center;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid var(--cream-dark);
        }

        .back-link {
            font-size: 14px;
            font-weight: 600;
            color: var(--gold);
            text-decoration: none;
        }

        .back-link:hover {
            color: var(--gold-light);
            text-decoration: underline;
        }

        @media (max-width: 480px) {
            .signup-container {
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
    <main class="signup-container">
        <div class="brand-section">
            <div class="brand-logo">
                <i class="fas fa-mug-hot"></i>
            </div>
            <h1 class="brand-name">SmartStock</h1>
            <p class="brand-sub">Bean There Café</p>
        </div>

        <div class="signup-title">
            Create Admin Account
        </div>
        <p class="signup-subtitle">Set up a new cafe owner account</p>

        <?php if ($error): ?>
        <div style="background:#fdecea;color:#C0392B;padding:10px 14px;border-radius:8px;margin-bottom:20px;font-size:14px;text-align:center;">
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="signup.php">
            <div class="form-group">
                <label class="form-label" for="username">Username</label>
                <input type="text" name="username" id="username" class="form-input" placeholder="Choose a username" required value="<?= htmlspecialchars($username) ?>">
            </div>

            <div class="form-group">
                <label class="form-label" for="password">Password</label>
                <input type="password" name="password" id="password" class="form-input" placeholder="Min. 8 characters" required minlength="8">
            </div>

            <div class="form-group">
                <label class="form-label" for="confirm_password">Confirm Password</label>
                <input type="password" name="confirm_password" id="confirm_password" class="form-input" placeholder="Re-enter password" required minlength="8">
            </div>

            <button type="submit" class="signup-btn">
                <i class="fas fa-user-plus"></i>
                Create Account
            </button>
        </form>

        <div class="back-account">
            <a href="signin.php" class="back-link">
                <i class="fas fa-arrow-left" style="margin-right:6px;"></i>Back to Sign In
            </a>
        </div>
    </main>
</body>

</html>

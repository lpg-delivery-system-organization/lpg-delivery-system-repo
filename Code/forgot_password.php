<?php
require_once "config.php";

$error = "";
$success = "";
$step = $_SESSION["reset_step"] ?? 1;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    if ($action === "send_code") {
        $email = trim($_POST["email"] ?? "");

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } else {
            $stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user) {
                $error = "No account was found with that email address.";
            } else {
                $code = (string) random_int(100000, 999999);
                $fullName = $user["full_name"] ?? "Customer";

                $_SESSION["reset_email"] = $email;
                $_SESSION["reset_code"] = password_hash($code, PASSWORD_DEFAULT);
                $_SESSION["reset_code_expires"] = time() + 600;
                $_SESSION["reset_step"] = 2;

                if (!sendPasswordResetEmail($email, $fullName, $code)) {
                    unset(
                        $_SESSION["reset_email"],
                        $_SESSION["reset_code"],
                        $_SESSION["reset_code_expires"],
                        $_SESSION["reset_step"]
                    );

                    $error = "We could not send the verification code. Check the SMTP settings in config.php and try again.";
                    $step = 1;
                } else {
                    $success = "A verification code has been sent to your email.";
                    $step = 2;
                }
            }
        }
    }

    if ($action === "verify_code") {
        $code = trim($_POST["code"] ?? "");

        if (
            empty($_SESSION["reset_code"]) ||
            empty($_SESSION["reset_code_expires"]) ||
            time() > $_SESSION["reset_code_expires"]
        ) {
            $error = "The verification code has expired. Request a new one.";
            $_SESSION["reset_step"] = 1;
            $step = 1;
        } elseif (!password_verify($code, $_SESSION["reset_code"])) {
            $error = "Invalid verification code.";
            $step = 2;
        } else {
            $_SESSION["reset_step"] = 3;
            $_SESSION["reset_verified"] = true;
            $success = "Code verified. Enter your new password.";
            $step = 3;
        }
    }

    if ($action === "reset_password") {
        if (empty($_SESSION["reset_verified"]) || empty($_SESSION["reset_email"])) {
            $error = "Your reset session is invalid. Please start again.";
            $step = 1;
        } else {
            $password = $_POST["new_password"] ?? "";
            $confirm = $_POST["confirm_password"] ?? "";

            if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).{8,}$/', $password)) {
                $error = "New password must be at least 8 characters and include uppercase, lowercase, a number, and a special character.";
                $step = 3;
            } elseif ($password !== $confirm) {
                $error = "Passwords do not match.";
                $step = 3;
            } else {
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
                $stmt->execute([
                    password_hash($password, PASSWORD_DEFAULT),
                    $_SESSION["reset_email"]
                ]);

                unset(
                    $_SESSION["reset_email"],
                    $_SESSION["reset_code"],
                    $_SESSION["reset_code_expires"],
                    $_SESSION["reset_step"],
                    $_SESSION["reset_verified"]
                );

                $_SESSION["password_reset_success"] = "Password changed successfully. You can now log in.";
                header("Location: index.php");
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LPG Delivery - Forgot Password</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<div class="screen active">
    <div class="auth-back"><a href="index.php">← Back to Login</a></div>

    <div class="auth-body">
        <div class="role-logo">🔐</div>
        <div class="auth-title">Reset Password</div>
        <div class="auth-sub">Recover your LPG Delivery account</div>

        <?php if ($error): ?>
            <div class="auth-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success-message"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
            <form class="auth-form" method="POST">
                <input type="hidden" name="action" value="send_code">

                <div class="field">
                    <label>Email</label>
                    <div class="input-wrap">
                        <input type="email" name="email" placeholder="your@email.com" required>
                    </div>
                </div>

                <button class="btn btn-primary" type="submit">Send Verification Code</button>
            </form>

        <?php elseif ($step === 2): ?>
            <form class="auth-form" method="POST">
                <input type="hidden" name="action" value="verify_code">

                <div class="field">
                    <label>Verification Code</label>
                    <div class="input-wrap">
                        <input type="text" name="code" maxlength="6"
                               placeholder="6-digit code from your email" required>
                    </div>
                    <div class="field-help">The verification code is valid for 10 minutes.</div>
                </div>

                <button class="btn btn-primary" type="submit">Verify Code</button>
            </form>

        <?php else: ?>
            <form class="auth-form" method="POST">
                <input type="hidden" name="action" value="reset_password">

                <div class="field">
                    <label>New Password</label>
                    <div class="input-wrap">
                        <input type="password" id="reset_password" name="new_password"
                               placeholder="At least 8 characters, uppercase, lowercase, digit, and special char" required>
                    </div>
                </div>

                <div class="field">
                    <label>Confirm New Password</label>
                    <div class="input-wrap">
                        <input type="password" id="reset_confirm_password" name="confirm_password"
                               placeholder="Re-enter your new password" required>
                    </div>
                </div>

                <div class="pw-checklist">
                    <div class="pw-checklist-title">Password must contain:</div>
                    <div class="pw-req" id="pw-req-len"><span class="pw-check">○</span> At least 8 characters</div>
                    <div class="pw-req" id="pw-req-upper"><span class="pw-check">○</span> One uppercase letter (A-Z)</div>
                    <div class="pw-req" id="pw-req-lower"><span class="pw-check">○</span> One lowercase letter (a-z)</div>
                    <div class="pw-req" id="pw-req-num"><span class="pw-check">○</span> One number (0-9)</div>
                    <div class="pw-req" id="pw-req-special"><span class="pw-check">○</span> One special character (!@#$%^&*)</div>
                </div>

                <button class="btn btn-primary" type="submit">Reset Password</button>
            </form>
        <?php endif; ?>

        <div class="auth-link">
            <a href="index.php">Return to Login</a>
        </div>
    </div>
</div>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const passwordInput = document.getElementById('reset_password');

            function validatePassword(value) {
                return {
                    len: value.length >= 8,
                    upper: /[A-Z]/.test(value),
                    lower: /[a-z]/.test(value),
                    num: /\d/.test(value),
                    special: /[^A-Za-z0-9]/.test(value)
                };
            }

            function updatePwChecklist() {
                const pw = passwordInput ? passwordInput.value : '';
                const rules = validatePassword(pw);
                const checks = {
                    'pw-req-len': rules.len,
                    'pw-req-upper': rules.upper,
                    'pw-req-lower': rules.lower,
                    'pw-req-num': rules.num,
                    'pw-req-special': rules.special
                };

                Object.entries(checks).forEach(([id, met]) => {
                    const item = document.getElementById(id);
                    if (!item) return;

                    item.classList.toggle('met', met);
                    const dot = item.querySelector('.pw-check');
                    if (dot) {
                        dot.textContent = met ? '✓' : '○';
                    }
                });
            }

            if (passwordInput) {
                passwordInput.addEventListener('input', updatePwChecklist);
                updatePwChecklist();
            }
        });
    </script>
</body>
</html>

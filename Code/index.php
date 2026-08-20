<?php
require_once "config.php";

if (isset($_SESSION["user_id"])) {
    redirectByRole($_SESSION["role"]);
}

$error = "";
$email = "";
$successMessage = $_SESSION["password_reset_success"] ?? "";
unset($_SESSION["password_reset_success"]);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === "") {
        $error = "Please enter your email and password.";
    } else {

        // Find the account by email.
        // The role is automatically retrieved from the database.
        $stmt = $pdo->prepare(
            "SELECT id, full_name, email, password, phone, address, role, status
             FROM users
             WHERE email = ?
             LIMIT 1"
        );

        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            $error = "Invalid email or password.";
        } elseif (!password_verify($password, $user["password"])) {
            $error = "Invalid email or password.";
        } elseif (($user["status"] ?? "active") !== "active") {
            $error = "Your account is not active.";
        } else {

            // Login successful
            session_regenerate_id(true);

            $_SESSION["user_id"] = $user["id"];
            $_SESSION["full_name"] = $user["full_name"];
            $_SESSION["email"] = $user["email"];
            $_SESSION["role"] = $user["role"];

            // Automatically redirect according to database role
            redirectByRole($user["role"]);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>LPG Delivery - Login</title>

    <link rel="stylesheet" href="styles.css">

    <style>
        .login-role-info {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .role-info-card {
            flex: 1;
            min-width: 100px;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 10px;
            text-align: center;
            background: #f8fafc;
        }

        .role-info-card .icon {
            font-size: 24px;
            display: block;
            margin-bottom: 5px;
        }

        .role-info-card span {
            font-size: 13px;
            color: var(--text-muted);
            font-weight: 600;
        }
    </style>
</head>

<body>

    <div class="screen active">
        <div class="login-shell">
            <div class="login-illustration" aria-hidden="true">
                <div class="blob blob-1"></div>
                <div class="blob blob-2"></div>
                <div class="blob blob-3"></div>
                <div class="blob blob-4"></div>
                <div class="blob blob-5"></div>
                <div class="blob blob-6"></div>
            </div>

            <div class="login-panel">
                <div class="login-card">
                    <div class="role-logo" style="font-size: 54px; margin-bottom: 18px;">🔥</div>
                    <h1 class="login-title">Get started!</h1>

                    <?php if ($error): ?>
                        <div class="auth-error">
                            <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($successMessage): ?>
                        <div class="success-message">
                            <?= htmlspecialchars($successMessage) ?>
                        </div>
                    <?php endif; ?>

                    <form class="auth-form" method="POST" action="">
                        <div class="login-field">
                            <label for="login-email">Email</label>
                            <div class="login-input-wrap">
                                <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path d="M4 7.5A2.5 2.5 0 0 1 6.5 5h11A2.5 2.5 0 0 1 20 7.5v9A2.5 2.5 0 0 1 17.5 19h-11A2.5 2.5 0 0 1 4 16.5v-9Z"/>
                                    <path d="m5 7 7 5 7-5"/>
                                </svg>
                                <input id="login-email" type="email" name="email" value="<?= htmlspecialchars($email) ?>" placeholder="Enter your email" required>
                            </div>
                        </div>

                        <div class="login-field">
                            <label for="login-password">Password</label>
                            <div class="login-input-wrap">
                                <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <rect x="5" y="11" width="14" height="9" rx="2"/>
                                    <path d="M8 11V8a4 4 0 1 1 8 0v3"/>
                                </svg>
                                <input id="login-password" type="password" name="password" placeholder="Enter your password" required>
                            </div>
                        </div>

                        <div class="login-actions">
                            <label class="remember">
                                <input type="checkbox" name="remember" value="1">
                                Remember me
                            </label>
                            <a class="login-link" href="forgot_password.php">Forgot password?</a>
                        </div>

                        <button class="login-btn" type="submit">Login</button>

                        <div class="login-signup">
                            <a href="register.php">Sign Up</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

</body>

</html>
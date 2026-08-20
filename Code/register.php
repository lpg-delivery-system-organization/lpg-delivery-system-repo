<?php
require_once "config.php";

if (isset($_SESSION["user_id"])) {
    redirectByRole($_SESSION["role"]);
}

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $name = trim($_POST["full_name"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $address = trim($_POST["address"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirm = $_POST["confirm_password"] ?? "";

    /*
     * IMPORTANT:
     * Registration is CUSTOMER ONLY.
     * Do not accept the role from the form.
     */
    $role = "customer";

    if (
        $name === "" ||
        $phone === "" ||
        $address === "" ||
        !filter_var($email, FILTER_VALIDATE_EMAIL)
    ) {
        $error = "Please complete all required fields.";

    } elseif (
        !preg_match(
            '/^\S*(?=\S{8,})(?=\S*[A-Z])(?=\S*[a-z])(?=\S*\d)(?=\S*[^A-Za-z0-9])\S*$/',
            $password
        )
    ) {
        $error = "Password must be at least 8 characters and include uppercase, lowercase, number, and special character.";

    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";

    } else {

        // Check if email already exists
        $stmt = $pdo->prepare(
            "SELECT id FROM users WHERE email = ? LIMIT 1"
        );

        $stmt->execute([$email]);

        if ($stmt->fetch()) {

            $error = "An account with that email already exists.";

        } else {

            $idPath = null;

            /*
             * VALID ID UPLOAD
             */
            if (
                isset($_FILES["valid_id"]) &&
                $_FILES["valid_id"]["error"] !== UPLOAD_ERR_NO_FILE
            ) {

                if ($_FILES["valid_id"]["error"] !== UPLOAD_ERR_OK) {

                    $error = "The valid ID upload failed.";

                } else {

                    $allowed = [
                        "image/jpeg",
                        "image/png",
                        "image/webp",
                        "application/pdf"
                    ];

                    $mime = mime_content_type(
                        $_FILES["valid_id"]["tmp_name"]
                    );

                    if (!in_array($mime, $allowed, true)) {

                        $error = "Valid ID must be JPG, PNG, WEBP, or PDF.";

                    } elseif (
                        $_FILES["valid_id"]["size"] > 5 * 1024 * 1024
                    ) {

                        $error = "Valid ID must be 5 MB or smaller.";

                    } else {

                        $uploadDir = __DIR__ . "/uploads/ids/";

                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0755, true);
                        }

                        $ext = strtolower(
                            pathinfo(
                                $_FILES["valid_id"]["name"],
                                PATHINFO_EXTENSION
                            )
                        );

                        $filename =
                            bin2hex(random_bytes(16)) .
                            "." .
                            $ext;

                        $destination =
                            $uploadDir .
                            $filename;

                        if (
                            move_uploaded_file(
                                $_FILES["valid_id"]["tmp_name"],
                                $destination
                            )
                        ) {

                            $idPath =
                                "uploads/ids/" .
                                $filename;

                        } else {

                            $error =
                                "Could not save the valid ID.";
                        }
                    }
                }
            }

            /*
             * CREATE CUSTOMER ACCOUNT
             */
            if ($error === "") {

                $stmt = $pdo->prepare(
                    "INSERT INTO users
                    (
                        full_name,
                        phone,
                        address,
                        email,
                        password,
                        role,
                        valid_id_path,
                        status
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'active')"
                );

                $stmt->execute([
                    $name,
                    $phone,
                    $address,
                    $email,
                    password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    ),
                    $role, // ALWAYS customer
                    $idPath
                ]);

                $success =
                    "Registration successful! You can now log in.";
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

    <title>LPG Delivery - Customer Registration</title>

    <link rel="stylesheet" href="styles.css">

</head>

<body>

    <div class="screen active">
        <div class="register-shell">
            <div class="register-card">
                <div class="register-side">
                    <div class="register-badge">👤</div>
                    <h2>Create account</h2>
                    <p>Register to order LPG for delivery and keep track of every order in one place.</p>

                    <ul class="register-points">
                        <li>Fast LPG ordering</li>
                        <li>Track delivery status</li>
                        <li>Secure account access</li>
                    </ul>
                </div>

                <div class="register-panel">
                    <h1>Create Customer Account</h1>
                    <div class="auth-sub">Register to order LPG for delivery</div>

                    <?php if ($error): ?>
                        <div class="auth-error"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="success-message"><?= htmlspecialchars($success) ?></div>
                    <?php endif; ?>

                    <form class="register-form" method="POST" enctype="multipart/form-data">
                        <div class="register-fields">
                            <div class="field full-width">
                                <label>Full Name</label>
                                <div class="input-wrap">
                                    <input type="text" name="full_name" placeholder="Your full name" required>
                                </div>
                            </div>

                            <div class="field">
                                <label>Phone Number</label>
                                <div class="input-wrap">
                                    <input type="text" name="phone" placeholder="e.g. 09171234567" required>
                                </div>
                            </div>

                            <div class="field">
                                <label>Email</label>
                                <div class="input-wrap">
                                    <input type="email" name="email" placeholder="your@email.com" required>
                                </div>
                            </div>

                            <div class="field full-width">
                                <label>Delivery Address</label>
                                <div class="input-wrap">
                                    <input type="text" name="address" placeholder="Your delivery address" required>
                                </div>
                            </div>

                            <div class="field full-width">
                                <label>Upload Valid ID</label>
                                <div class="input-wrap">
                                    <input type="file" name="valid_id" accept="image/*,.pdf">
                                </div>
                                <small>Accepted: JPG, PNG, WEBP, or PDF. Maximum 5 MB.</small>
                            </div>

                            <div class="field">
                                <label>Password</label>
                                <div class="input-wrap">
                                    <input type="password" id="password" name="password" placeholder="••••••••" required>
                                </div>
                            </div>

                            <div class="field">
                                <label>Confirm Password</label>
                                <div class="input-wrap">
                                    <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter your password" required>
                                </div>
                            </div>

                            <div class="field full-width">
                                <div class="pw-checklist">
                                    <div class="pw-checklist-title">Password must contain:</div>
                                    <div class="pw-req" id="pw-req-len"><span class="pw-check">○</span> At least 8 characters</div>
                                    <div class="pw-req" id="pw-req-upper"><span class="pw-check">○</span> One uppercase letter (A-Z)</div>
                                    <div class="pw-req" id="pw-req-lower"><span class="pw-check">○</span> One lowercase letter (a-z)</div>
                                    <div class="pw-req" id="pw-req-num"><span class="pw-check">○</span> One number (0-9)</div>
                                    <div class="pw-req" id="pw-req-special"><span class="pw-check">○</span> One special character (!@#$%^&*)</div>
                                </div>
                            </div>
                        </div>

                        <button class="btn btn-primary" type="submit">Create Customer Account</button>

                        <div class="auth-link">
                            Already have an account? <a href="index.php">Login</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const passwordInput = document.getElementById('password');

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
<?php

session_start();
if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
$error = '';
if (isset($_GET['error'])) {
    $error = htmlspecialchars($_GET['error']);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Expense Tracker</title>
</head>
<body>
    <div class="card">
        <h2 style="margin-top:0">Sign in</h2>
        <?php if ($error): ?>
            <div class="error"><?php echo $error === '1' ? 'Invalid credentials' : $error; ?></div>
        <?php endif; ?>
        <form method="post" action="authenticate.php">
            <label for="username">Username or Email</label>
            <input id="username" name="username" type="text" required autofocus>

            <label for="password">Password</label>
            <input id="password" name="password" type="password" required>

            <button type="submit">Login</button>
        </form>
        
    </div>
</body>
</html>

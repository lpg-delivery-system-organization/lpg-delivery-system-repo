<?php
session_start();
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

include 'connection.php';
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Expense Tracker - Users</title>
    <style>body{font-family:Arial,Helvetica,sans-serif;padding:20px}table{border-collapse:collapse;width:100%}td,th{border:1px solid #ddd;padding:8px}</style>
    </head>
<body>
    <div style="display:flex;justify-content:space-between;align-items:center">
        <h1>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?></h1>
        <div><a href="logout.php">Logout</a></div>
    </div>

    <h2>Users</h2>
    <table>
        <thead><tr><th>Username</th></tr></thead>
        <tbody>
<?php
$result = $connection->query("SELECT * FROM tbl_users");

            if ($result && $result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    echo "<tr>\n            <td>" . htmlspecialchars($row['username']) . "</td>\n        </tr>";
                }
            } else {
                echo "<tr><td colspan='1'>No records found</td></tr>";
            }

?>
        </tbody>
    </table>
</body>
</html>
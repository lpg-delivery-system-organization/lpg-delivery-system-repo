<?php
include 'connection.php';

function gradeRate($score)
{
    if ($score >= 99 && $score <= 100) return "1.0";
    elseif ($score >= 96) return "1.25";
    elseif ($score >= 93) return "1.5";
    elseif ($score >= 90) return "1.75";
    elseif ($score >= 87) return "2.0";
    elseif ($score >= 84) return "2.25";
    elseif ($score >= 81) return "2.5";
    elseif ($score >= 78) return "2.75";
    elseif ($score >= 75.5) return "3.0";
    elseif ($score >= 0) return "5.0";
    else return "Invalid";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stud_id = $_POST['stud_id'];
    $lname = ucwords(strtolower(trim($_POST['lname'])));
    $fname = ucwords(strtolower(trim($_POST['fname'])));
    $mname = ucwords(strtolower(trim($_POST['mname']))) ?: 'N/A';
    $prog = strtoupper(trim($_POST['prog']));
    $sec = strtoupper(trim($_POST['sec']));
    $grade1 = $_POST['grade1'];
    $grade2 = $_POST['grade2'];
    $grade3 = $_POST['grade3'];
    $grade4 = $_POST['grade4'];

    $sem1_avg = ($grade1 + $grade2) / 2;
    $sem2_avg = ($grade3 + $grade4) / 2;
    $overall_avg = ($sem1_avg + $sem2_avg) / 2;
    $remark = gradeRate($overall_avg);

    $query = "INSERT INTO students 
        (id, last_name, first_name, middle_name, course, section,
         first_grading, second_grading, third_grading, fourth_grading,
         first_sem_avg, second_sem_avg, final_avg, remarks)
        VALUES 
        ('$stud_id', '$lname', '$fname', '$mname', '$prog', '$sec',
         '$grade1', '$grade2', '$grade3', '$grade4',
         '$sem1_avg', '$sem2_avg', '$overall_avg', '$remark')";

    mysqli_query($conn, $query);
    echo "<script>alert('Student Record Added'); window.location='index.php';</script>";
}

if (isset($_GET['del'])) {
    $stud_id = $_GET['del'];
    if (mysqli_query($conn, "DELETE FROM students WHERE id = '$stud_id'")) {
        echo "<script>alert('Record Deleted'); window.location='index.php';</script>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Student Grading System</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <h2>Student Grading Form</h2>

    <form method="POST">
        <label>Student ID:</label>
        <input type="text" name="stud_id" required>

        <label>Last Name:</label>
        <input type="text" name="lname" required>

        <label>First Name:</label>
        <input type="text" name="fname" required>

        <label>Middle Name:</label>
        <input type="text" name="mname">

        <label>Program:</label>
        <input type="text" name="prog" required>

        <label>Section:</label>
        <input type="text" name="sec" required>

        <label>1st Grading:</label>
        <input type="number" name="grade1" required>

        <label>2nd Grading:</label>
        <input type="number" name="grade2" required>

        <label>3rd Grading:</label>
        <input type="number" name="grade3" required>

        <label>4th Grading:</label>
        <input type="number" name="grade4" required>

        <button type="submit">Save Record</button>
    </form>

    <h2>Student Records</h2>

    <table>
        <tr>
            <th>ID</th>
            <th>First</th>
            <th>Last</th>
            <th>Middle</th>
            <th>Program</th>
            <th>Section</th>
            <th>1st</th>
            <th>2nd</th>
            <th>1st Avg</th>
            <th>3rd</th>
            <th>4th</th>
            <th>2nd Avg</th>
            <th>Final</th>
            <th>Remarks</th>
            <th>Action</th>
        </tr>
        <?php
        $res = mysqli_query($conn, "SELECT * FROM students");
        while ($r = mysqli_fetch_assoc($res)) {
            echo "<tr>
                <td>{$r['id']}</td>
                <td>{$r['first_name']}</td>
                <td>{$r['last_name']}</td>
                <td>{$r['middle_name']}</td>
                <td>{$r['course']}</td>
                <td>{$r['section']}</td>
                <td>{$r['first_grading']}</td>
                <td>{$r['second_grading']}</td>
                <td>{$r['first_sem_avg']}</td>
                <td>{$r['third_grading']}</td>
                <td>{$r['fourth_grading']}</td>
                <td>{$r['second_sem_avg']}</td>
                <td>{$r['final_avg']}</td>
                <td>{$r['remarks']}</td>
                <td><a href='index.php?del={$r['id']}'><button class='delete-btn'>Delete</button></a></td>
            </tr>";
        }
        ?>
    </table>
</body>
</html>

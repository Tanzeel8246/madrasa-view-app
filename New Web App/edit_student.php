<?php require_once 'db.php'; include 'header.php'; 

// ID چیک کریں
if (!isset($_GET['id'])) { echo "<script>window.location='students.php';</script>"; exit; }
$id = $_GET['id'];

// اپڈیٹ کا کوڈ
if (isset($_POST['update'])) {
    $stmt = $pdo->prepare("UPDATE students SET name=?, father_name=?, admission_number=? WHERE id=?");
    $stmt->execute([$_POST['name'], $_POST['fname'], $_POST['adm'], $id]);
    echo "<div class='alert alert-success'>ریکارڈ اپڈیٹ ہو گیا! <a href='students.php'>واپس جائیں</a></div>";
}

// پرانا ڈیٹا لائیں
$row = $pdo->prepare("SELECT * FROM students WHERE id=?");
$row->execute([$id]);
$std = $row->fetch();
?>

<div class="container mt-5">
    <h3>ریکارڈ میں ترمیم کریں</h3>
    <div class="card p-4">
        <form method="POST">
            <div class="mb-3">
                <label>نام</label>
                <input type="text" name="name" class="form-control" value="<?php echo $std['name']; ?>" required>
            </div>
            <div class="mb-3">
                <label>ولدیت</label>
                <input type="text" name="fname" class="form-control" value="<?php echo $std['father_name']; ?>" required>
            </div>
            <div class="mb-3">
                <label>داخلہ نمبر</label>
                <input type="text" name="adm" class="form-control" value="<?php echo $std['admission_number']; ?>">
            </div>
            <button type="submit" name="update" class="btn btn-success">اپڈیٹ کریں</button>
            <a href="students.php" class="btn btn-secondary">کینسل</a>
        </form>
    </div>
</div>
<?php include 'footer.php'; ?>
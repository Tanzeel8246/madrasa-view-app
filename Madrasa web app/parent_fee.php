<?php require_once 'db.php'; include 'header.php'; 

if ($_SESSION['role'] != 'parent' && $_SESSION['role'] != 'admin') { 
    echo "<script>window.location='index.php';</script>"; exit; 
}
$std_id = ($_SESSION['role'] == 'admin' && isset($_GET['student_id'])) ? $_GET['student_id'] : $_SESSION['user_id'];

// فیس جمع کرنے کا ہینڈلر
if (isset($_POST['submit_fee'])) {
    $amount = $_POST['amount'];
    $date = $_POST['date'];
    $head_id = $_POST['head_id'];
    $description = $_POST['description'];
    $screenshot = "";

    if (!empty($_FILES['screenshot']['name'])) {
        $target_dir = "uploads/fees/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
        $screenshot = $target_dir . time() . "_" . basename($_FILES["screenshot"]["name"]);
        move_uploaded_file($_FILES["screenshot"]["tmp_name"], $screenshot);
    }

    $stmt = $pdo->prepare("INSERT INTO fee_submissions (student_id, amount, submission_date, screenshot, status, head_id, description) VALUES (?, ?, ?, ?, 'Pending', ?, ?)");
    $stmt->execute([$std_id, $amount, $date, $screenshot, $head_id, $description]);
    echo "<div class='alert alert-success fw-bold py-3 shadow-sm'><i class='fas fa-check-circle me-2'></i> تعاون کی تفصیلات جمع ہو گئی ہیں، تصدیق کا انتظار کریں۔</div>";
}

// مدات لائیں
$heads = $pdo->query("SELECT * FROM heads WHERE type='Income' ORDER BY name ASC")->fetchAll();

// پرانی ہسٹری
$history = $pdo->prepare("SELECT * FROM fee_submissions WHERE student_id = ? ORDER BY id DESC");
$history->execute([$std_id]);
?>

<div class="row">
    <div class="col-md-5">
        <div class="card shadow-sm mb-4 border-0" style="border-radius:15px;">
            <div class="card-header bg-success text-white fw-bold py-3"><i class="fas fa-hand-holding-usd me-2"></i> مدرسہ کے ساتھ تعاون کریں</div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label fw-bold">تاریخ</label>
                        <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">تعاون کی مد (Category)</label>
                        <select name="head_id" class="form-select" required>
                            <option value="">مد منتخب کریں</option>
                            <?php foreach($heads as $h): ?>
                                <option value="<?php echo $h['id']; ?>"><?php echo $h['name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">رقم (Amount)</label>
                        <input type="number" name="amount" class="form-control" placeholder="رقم درج کریں" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">تفصیل (Description)</label>
                        <input type="text" name="description" class="form-control" placeholder="مختصر تفصیل (اختیاری)">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">سکرین شاٹ (ثبوت)</label>
                        <input type="file" name="screenshot" class="form-control" accept="image/*" required>
                    </div>
                    <button type="submit" name="submit_fee" class="btn btn-success w-100 fw-bold py-2 shadow-sm">ارسال کریں</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-7">
        <div class="card shadow-sm h-100 border-0 overflow-hidden" style="border-radius:15px;">
            <div class="card-header bg-dark text-white fw-bold py-3"><i class="fas fa-history me-2"></i> سابقہ ریکارڈ</div>
            <div class="table-responsive">
                <table class="table table-sm text-center align-middle">
                    <thead class="bg-light"><tr><th>تاریخ</th><th>رقم</th><th>سٹیٹس</th><th>رسید/ثبوت</th></tr></thead>
                    <tbody>
                        <?php while($row = $history->fetch()): 
                            $bg = ($row['status']=='Approved')?'bg-success':(($row['status']=='Rejected')?'bg-danger':'bg-secondary');
                        ?>
                        <tr>
                            <td><?php echo date('d-M', strtotime($row['submission_date'])); ?></td>
                            <td class="fw-bold">Rs. <?php echo number_format($row['amount']); ?></td>
                            <td><span class="badge <?php echo $bg; ?>"><?php echo $row['status']; ?></span></td>
                            <td>
                                <?php if($row['finance_id']): ?>
                                    <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#receiptModal<?php echo $row['id']; ?>">
                                        <i class="fas fa-receipt"></i> رسید دیکھیں
                                    </button>

                                    <!-- Receipt Modal -->
                                    <div class="modal fade text-start" id="receiptModal<?php echo $row['id']; ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content" style="border-radius:15px;">
                                                <div class="modal-header bg-success text-white">
                                                    <h5 class="modal-title"><i class="fas fa-receipt me-2"></i> رسید</h5>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>
                                                <div class="modal-body fs-5 text-center py-4">
                                                    <p class="mb-2">رسید نمبر: <strong><?php echo $row['finance_id']; ?></strong></p>
                                                    <p class="mb-3">رقم: <strong>Rs. <?php echo number_format($row['amount']); ?></strong></p>
                                                    <p class="text-success fw-bold mb-0">یہ تعاون مدرسہ میں وصول پا چکا ہے۔ جزاک اللہ خیر!</p>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">بند کریں</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php elseif($row['screenshot']): ?>
                                    <a href="<?php echo $row['screenshot']; ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye"></i> ثبوت</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>
<?php
/**
 * QR Code & Barcode Smart Attendance System
 * جامعہ اشرف المدارس - اسمارٹ کیو آر کوڈ اور بارکوڈ حاضری
 */
require_once 'db.php';

// Handle AJAX attendance marking
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json; charset=utf-8');
    if (session_status() === PHP_SESSION_NONE) session_start();

    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['status' => 'error', 'message' => 'لاگ ان درکار ہے۔']);
        exit;
    }

    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true) ?: $_POST;

    $code = trim((string)($data['code'] ?? ''));
    $type = trim((string)($data['type'] ?? 'student')); // student or teacher
    $slot = intval($data['slot'] ?? 1); // 1, 2, 3, 4
    if ($slot < 1 || $slot > 4) $slot = 1;

    $today = date('Y-m-d');
    $currentTime = date('h:i A');
    $sysType = $_SESSION['system_type'] ?? 'Madrasa';

    if (empty($code)) {
        echo json_encode(['status' => 'error', 'message' => 'کوڈ خالی ہے!']);
        exit;
    }

    try {
        if ($type === 'teacher') {
            // Teacher lookup by ID, Phone, or barcode
            $stmt = $pdo->prepare("SELECT id, name, phone, designation FROM teachers WHERE (id = ? OR phone = ? OR phone LIKE ?) AND (is_deleted = 0 OR is_deleted IS NULL) LIMIT 1");
            $cleanCode = preg_replace('/[^0-9]/', '', $code);
            $stmt->execute([$code, $code, "%$cleanCode%"]);
            $teacher = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$teacher) {
                echo json_encode(['status' => 'error', 'message' => 'استاد کا ریکارڈ نہیں ملا: ' . htmlspecialchars($code)]);
                exit;
            }

            // Mark Teacher Attendance
            $checkStmt = $pdo->prepare("SELECT id FROM attendance_teachers WHERE teacher_id = ? AND date = ? LIMIT 1");
            $checkStmt->execute([$teacher['id'], $today]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $upStmt = $pdo->prepare("UPDATE attendance_teachers SET status = 'P', is_synced = 0 WHERE id = ?");
                $upStmt->execute([$existing['id']]);
            } else {
                $insStmt = $pdo->prepare("INSERT INTO attendance_teachers (teacher_id, date, status, is_synced) VALUES (?, ?, 'P', 0)");
                $insStmt->execute([$teacher['id'], $today]);
            }

            echo json_encode([
                'status' => 'success',
                'name' => $teacher['name'],
                'detail' => $teacher['designation'] ?? 'استاد کرام',
                'id' => $teacher['id'],
                'time' => $currentTime,
                'message' => 'حاضری درج ہو گئی'
            ]);
            exit;

        } else {
            // Student lookup by admission_number, roll_no, or ID
            $stmt = $pdo->prepare("SELECT id, name, father_name, class, admission_number, roll_no FROM students WHERE (admission_number = ? OR roll_no = ? OR id = ?) AND (is_deleted = 0 OR is_deleted IS NULL) LIMIT 1");
            $stmt->execute([$code, $code, $code]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$student) {
                echo json_encode(['status' => 'error', 'message' => 'طالب علم نہیں ملا: ' . htmlspecialchars($code)]);
                exit;
            }

            $stdId = $student['id'];
            $colStatus = "status_$slot";
            $colLate = "late_$slot";

            // Check if attendance row exists for today
            $checkStmt = $pdo->prepare("SELECT id FROM attendance WHERE student_id = ? AND date = ? LIMIT 1");
            $checkStmt->execute([$stdId, $today]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $upSql = "UPDATE attendance SET $colStatus = 'P', $colLate = 0, is_synced = 0 WHERE id = ?";
                $pdo->prepare($upSql)->execute([$existing['id']]);
            } else {
                $insSql = "INSERT INTO attendance (student_id, date, $colStatus, $colLate, is_synced) VALUES (?, ?, 'P', 0, 0)";
                $pdo->prepare($insSql)->execute([$stdId, $today]);
            }

            echo json_encode([
                'status' => 'success',
                'name' => $student['name'],
                'father_name' => $student['father_name'],
                'class' => $student['class'],
                'adm_no' => $student['admission_number'] ?? $student['roll_no'],
                'id' => $stdId,
                'slot' => $slot,
                'time' => $currentTime,
                'message' => 'حاضری درج ہو گئی'
            ]);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'ڈیٹابیس ایرر: ' . $e->getMessage()]);
        exit;
    }
}

include 'header.php';
$today = date('Y-m-d');
?>

<div class="container-fluid py-4">
    <div class="row mb-4 align-items-center">
        <div class="col-md-7">
            <h3 class="fw-bold text-primary mb-1">
                <i class="fas fa-qrcode text-success me-2"></i> اسمارٹ کیو آر و بارکوڈ حاضری اسکینر
            </h3>
            <p class="text-muted small mb-0">کیمرہ اسکینر یا بارکوڈ گن سے فوری خودکار حاضری مارک کریں۔</p>
        </div>
        <div class="col-md-5 text-md-end mt-3 mt-md-0">
            <span class="badge bg-primary fs-6 px-3 py-2 rounded-pill shadow-sm">
                <i class="fas fa-calendar-alt me-1"></i> آج: <?php echo date('d-M-Y'); ?>
            </span>
        </div>
    </div>

    <div class="row g-4">
        <!-- Scanner & Configuration Column -->
        <div class="col-lg-6 col-md-12">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-dark">حاضری کی قسم:</label>
                            <select id="attType" class="form-select border-primary shadow-none fw-bold">
                                <option value="student" selected>🎓 طلباء کرام</option>
                                <option value="teacher">👨‍🏫 اساتذہ کرام</option>
                            </select>
                        </div>
                        <div class="col-6" id="slotContainer">
                            <label class="form-label small fw-bold text-dark">نوبت / وقت:</label>
                            <select id="attSlot" class="form-select border-primary shadow-none fw-bold">
                                <option value="1" selected>صبح (حاضری 1)</option>
                                <option value="2">ظہر (حاضری 2)</option>
                                <option value="3">عصر (حاضری 3)</option>
                                <option value="4">مغرب / عشاء (حاضری 4)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="card-body p-4">
                    <!-- Scanner Viewport -->
                    <div id="reader-container" class="position-relative overflow-hidden rounded-4 border bg-dark text-white text-center mb-3" style="min-height: 280px;">
                        <div id="reader" style="width: 100%;"></div>
                        <div id="scannerPlaceholder" class="p-5 d-flex flex-column align-items-center justify-content-center" style="min-height: 280px;">
                            <i class="fas fa-camera fa-3x text-white-50 mb-3 animate__animated animate__pulse animate__infinite"></i>
                            <button id="startScanBtn" class="btn btn-success btn-lg px-4 rounded-pill shadow fw-bold">
                                <i class="fas fa-video me-2"></i> کیمرہ آن کریں
                            </button>
                            <small class="text-white-50 mt-2">یا نیچے بارکوڈ گن سے اسکین کریں</small>
                        </div>
                    </div>

                    <!-- Manual / Barcode Gun Input -->
                    <div class="input-group input-group-lg shadow-sm rounded-pill overflow-hidden border">
                        <span class="input-group-text bg-light border-0 ps-3">
                            <i class="fas fa-barcode fa-lg text-primary"></i>
                        </span>
                        <input type="text" id="barcodeInput" class="form-control border-0 px-3 fs-5 text-center fw-bold" placeholder="بارکوڈ گن یا داخلہ نمبر یہاں اسکین کریں..." autofocus autocomplete="off">
                        <button id="manualSubmitBtn" class="btn btn-primary px-4 fw-bold">
                            <i class="fas fa-check-circle me-1"></i> اندراج
                        </button>
                    </div>
                    <small class="text-muted d-block text-center mt-2">
                        <i class="fas fa-info-circle me-1"></i> بارکوڈ گن براہ راست ان پٹ کر کے خودکار Enter دباتی ہے۔
                    </small>
                </div>
            </div>
        </div>

        <!-- Real-time Live Result Card Column -->
        <div class="col-lg-6 col-md-12">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white border-0 pt-4 px-4 pb-0 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0 text-dark">
                        <i class="fas fa-user-check text-success me-2"></i> تازہ ترین اسکین شدہ تفصیل
                    </h5>
                    <span class="badge bg-success-subtle text-success border border-success px-3 py-1 rounded-pill fw-bold">
                        آج کے اسکینز: <span id="scanCount">0</span>
                    </span>
                </div>

                <div class="card-body p-4 d-flex flex-column justify-content-center align-items-center text-center">
                    <!-- Default Idle State -->
                    <div id="idleState" class="py-5">
                        <div class="rounded-circle bg-light p-4 d-inline-block mb-3 border">
                            <i class="fas fa-id-card fa-4x text-muted"></i>
                        </div>
                        <h5 class="text-muted fw-bold">اسکیننگ کے لیے تیار...</h5>
                        <p class="text-muted small">طالب علم یا استاد کا کیو آر کوڈ یا بارکوڈ اسکین کیجیے۔</p>
                    </div>

                    <!-- Success Scanned State -->
                    <div id="scannedCard" class="w-100 py-3 d-none">
                        <div class="rounded-circle bg-success bg-opacity-10 text-success p-3 d-inline-block mb-3 border border-success">
                            <i class="fas fa-check-circle fa-4x"></i>
                        </div>
                        <h3 class="fw-bold text-dark mb-1" id="resName">-</h3>
                        <h5 class="text-primary fw-bold mb-2" id="resDetail">-</h5>
                        <div class="d-flex justify-content-center gap-2 mb-3">
                            <span class="badge bg-dark fs-6 px-3 py-2 rounded-pill" id="resAdm">-</span>
                            <span class="badge bg-info text-dark fs-6 px-3 py-2 rounded-pill" id="resTime">-</span>
                        </div>
                        <div class="alert alert-success fw-bold rounded-3 py-2 px-4 shadow-sm d-inline-block">
                            <i class="fas fa-check-double me-1"></i> <span id="resMsg">حاضری کامیابی سے درج ہو گئی</span>
                        </div>
                    </div>

                    <!-- Error State -->
                    <div id="errorCard" class="w-100 py-3 d-none">
                        <div class="rounded-circle bg-danger bg-opacity-10 text-danger p-3 d-inline-block mb-3 border border-danger">
                            <i class="fas fa-exclamation-triangle fa-4x"></i>
                        </div>
                        <h4 class="fw-bold text-danger mb-2" id="errMsg">ریکارڈ نہیں ملا</h4>
                        <button class="btn btn-sm btn-outline-secondary rounded-pill px-3 mt-2" onclick="resetCard()">
                            دوبارہ کوشش کریں
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- HTML5 QR Code Scanner Library via CDN -->
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>

<script>
let html5QrcodeScanner = null;
let scanCounter = 0;
let isScanningActive = false;

// Audio Chime Synthesizer via Web Audio API (Zero external assets needed)
function playBeep(success = true) {
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);
        if (success) {
            osc.frequency.setValueAtTime(880, ctx.currentTime); // High pleasant A5
            gain.gain.setValueAtTime(0.3, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.25);
            osc.start(ctx.currentTime);
            osc.stop(ctx.currentTime + 0.25);
        } else {
            osc.frequency.setValueAtTime(220, ctx.currentTime); // Low buzz
            gain.gain.setValueAtTime(0.4, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.4);
            osc.start(ctx.currentTime);
            osc.stop(ctx.currentTime + 0.4);
        }
    } catch(e) {}
}

function processAttendance(code) {
    if (!code || code.trim() === '') return;
    const type = document.getElementById('attType').value;
    const slot = document.getElementById('attSlot').value;

    fetch('qr_attendance.php?ajax=1', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ code: code.trim(), type: type, slot: slot })
    })
    .then(r => r.json())
    .then(data => {
        if (data.status === 'success') {
            playBeep(true);
            scanCounter++;
            document.getElementById('scanCount').innerText = scanCounter;

            document.getElementById('resName').innerText = data.name;
            document.getElementById('resDetail').innerText = (type === 'teacher') ? (data.detail || 'استاد') : ('کلاس: ' + (data.class || '-'));
            document.getElementById('resAdm').innerText = (type === 'teacher') ? ('آئی ڈی: ' + data.id) : ('داخلہ نمبر: ' + (data.adm_no || data.id));
            document.getElementById('resTime').innerText = 'وقت: ' + data.time;
            document.getElementById('resMsg').innerText = data.message;

            document.getElementById('idleState').classList.add('d-none');
            document.getElementById('errorCard').classList.add('d-none');
            document.getElementById('scannedCard').classList.remove('d-none');
        } else {
            playBeep(false);
            document.getElementById('errMsg').innerText = data.message || 'ریکارڈ نہیں ملا';
            document.getElementById('idleState').classList.add('d-none');
            document.getElementById('scannedCard').classList.add('d-none');
            document.getElementById('errorCard').classList.remove('d-none');
        }
        document.getElementById('barcodeInput').value = '';
        document.getElementById('barcodeInput').focus();
    })
    .catch(err => {
        playBeep(false);
        document.getElementById('errMsg').innerText = 'سرور سے رابطہ نہ ہو سکا';
        document.getElementById('idleState').classList.add('d-none');
        document.getElementById('scannedCard').classList.add('d-none');
        document.getElementById('errorCard').classList.remove('d-none');
    });
}

function resetCard() {
    document.getElementById('idleState').classList.remove('d-none');
    document.getElementById('scannedCard').classList.add('d-none');
    document.getElementById('errorCard').classList.add('d-none');
    document.getElementById('barcodeInput').focus();
}

// Camera Scanner Setup
document.getElementById('startScanBtn').addEventListener('click', function() {
    document.getElementById('scannerPlaceholder').classList.add('d-none');
    html5QrcodeScanner = new Html5Qrcode("reader");
    html5QrcodeScanner.start(
        { facingMode: "environment" },
        { fps: 10, qrbox: { width: 250, height: 250 } },
        (decodedText) => {
            processAttendance(decodedText);
        },
        (errorMessage) => {}
    ).catch(err => {
        alert('کیمرہ ایکسیس کرنے میں مسئلہ: ' + err);
        document.getElementById('scannerPlaceholder').classList.remove('d-none');
    });
});

// Barcode Gun Keyboard Listener
const barcodeInput = document.getElementById('barcodeInput');
barcodeInput.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        processAttendance(this.value);
    }
});

document.getElementById('manualSubmitBtn').addEventListener('click', function() {
    processAttendance(barcodeInput.value);
});

// Switch teacher/student slot visibility
document.getElementById('attType').addEventListener('change', function() {
    const slotBox = document.getElementById('slotContainer');
    if (this.value === 'teacher') {
        slotBox.classList.add('d-none');
    } else {
        slotBox.classList.remove('d-none');
    }
    barcodeInput.focus();
});
</script>

<?php include 'footer.php'; ?>

<?php 

require_once 'db.php'; 


$selected_class = $_GET['class'] ?? '';


if (empty($selected_class)) {


    header('Location: academic_entry_dashboard.php');


    exit;


}





$allowed_maktab_classes = get_allowed_maktab_classes($pdo);


$allowed_class_names = array_column($allowed_maktab_classes, 'name');


if (!in_array($selected_class, $allowed_class_names) && $_SESSION['role'] !== 'admin') {


    die("<div style='text-align:center;margin-top:50px;font-family:sans-serif;'><h2>Access Denied</h2><p>You don't have permission to access this class.</p></div>");


}





include 'header.php'; 





// --- 1.      ---


if (isset($_POST['save_nazira'])) {


    try {


        $date = $_POST['date'];


        $std_id = $_POST['std_id'];


        


        $m_sabaq = $_POST['morning_sabaq'] ?? '';


        $m_qty = $_POST['morning_qty'] ?? '';


        $m_time = $_POST['morning_time'] ?? '';


        $m_status = $_POST['morning_status'] ?? '';


        


        $e_sabaq = $_POST['evening_sabaq'] ?? '';


        $e_qty = $_POST['evening_qty'] ?? '';


        $e_time = $_POST['evening_time'] ?? '';


        $e_status = $_POST['evening_status'] ?? '';





        $cur_tenant_id = (int)get_current_tenant_id();
        $exist = $pdo->prepare("SELECT * FROM daily_nazira WHERE student_id = ? AND date = ? AND tenant_id = ?");
        $exist->execute([$std_id, $date, $cur_tenant_id]);
        $row = $exist->fetch();





        if ($row) {


            $keepOld = function($newValue, $oldValue) {


                return trim((string)$newValue) !== '' ? $newValue : $oldValue;


            };


            $m_sabaq = $keepOld($m_sabaq, $row['morning_sabaq'] ?? '');


            $m_qty = $keepOld($m_qty, $row['morning_qty'] ?? '');


            $m_time = $keepOld($m_time, $row['morning_time'] ?? '');


            $m_status = $keepOld($m_status, $row['morning_status'] ?? '');


            


            $e_sabaq = $keepOld($e_sabaq, $row['evening_sabaq'] ?? '');


            $e_qty = $keepOld($e_qty, $row['evening_qty'] ?? '');


            $e_time = $keepOld($e_time, $row['evening_time'] ?? '');


            $e_status = $keepOld($e_status, $row['evening_status'] ?? '');


            


            $sql = "UPDATE daily_nazira SET morning_sabaq=?, morning_qty=?, morning_time=?, morning_status=?, evening_sabaq=?, evening_qty=?, evening_time=?, evening_status=? WHERE id=? AND tenant_id=?";
            $pdo->prepare($sql)->execute([$m_sabaq, $m_qty, $m_time, $m_status, $e_sabaq, $e_qty, $e_time, $e_status, $row['id'], $cur_tenant_id]);
        } else {
            $sql = "INSERT INTO daily_nazira (student_id, date, morning_sabaq, morning_qty, morning_time, morning_status, evening_sabaq, evening_qty, evening_time, evening_status, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([$std_id, $date, $m_sabaq, $m_qty, $m_time, $m_status, $e_sabaq, $e_qty, $e_time, $e_status, $cur_tenant_id]);
        }


        


        echo "<div class='alert alert-success text-center fw-bold shadow mt-2' id='alert_msg'>✅ ناظرہ کا ریکارڈ محفوظ / اپڈیٹ ہو گیا!</div>";


        echo "<script>setTimeout(()=>{ document.getElementById('alert_msg').style.display='none'; }, 2000);</script>";





    } catch (Exception $e) { echo "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>"; }


}





//       (    )
$cur_tenant_id = (int)get_current_tenant_id();
$stmt = $pdo->prepare("
    SELECT s.* FROM maktab_students s
    WHERE s.tenant_id = ?
      AND (LOWER(s.status) = 'active' OR s.status = 'حاضر' OR s.status = 'Present' OR s.status IS NULL OR s.status = '') 
      AND (s.is_deleted = 0 OR s.is_deleted IS NULL)
      AND s.class = ?
    ORDER BY s.admission_number ASC
");
$stmt->execute([$cur_tenant_id, $selected_class]);
$maktab_students_data = $stmt->fetchAll();


?>





<div class="container py-3 text-end" dir="rtl">


    <a href="academic_entry_dashboard.php" class="btn btn-outline-secondary btn-sm fw-bold float-start mt-2"><i class="fas fa-arrow-right me-2"></i>   </a>


    <h2 class="fw-bold text-success mb-4 border-bottom pb-2"><i class="fas fa-book-open me-2"></i>    </h2>


    


    <div class="sticky-top pt-2" style="z-index: 100;">


        <div class="card mb-3 bg-dark text-white shadow-lg border-0" style="background: linear-gradient(135deg, #134E5E 0%, #71B280 100%);">


            <div class="card-body text-center py-2">


                <div class="d-flex justify-content-center align-items-center gap-3">


                    <button type="button" id="mic_btn" class="btn btn-warning fw-bold rounded-pill px-4 shadow" onclick="toggleVoice()">


                        <i class="fas fa-microphone me-2"></i> <span> (Start)</span>


                    </button>


                    <div id="status_text" class="text-white small text-start border-start ps-3" style="min-width: 250px;">


                        <span class="opacity-75">  </span>


                    </div>


                </div>


            </div>


        </div>


    </div>





    <form method="POST" id="nazira_form" class="card p-4 shadow-sm border-0 border-top border-5 border-success">


        


        <div class="row mb-4 bg-light p-3 rounded mx-0 border">


            <div class="col-md-6">


                <label class="fw-bold"><?php echo t('select_student'); ?>:</label>


                <select name="std_id" id="std_id" class="form-select fw-bold select2" required>


                    <option value="">--   --</option>


                    <?php foreach($maktab_students_data as $s) {


                        $label = trim(($s['name'] ?? '') . ' - ' . ($s['father_name'] ?? '') . ' | : ' . ($s['admission_number'] ?? '') . ' | : ' . ($s['class'] ?? ''));


                        echo "<option value='{$s['id']}'>" . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . "</option>";


                    } ?>


                </select>


            </div>


            <div class="col-md-6">


                <label class="fw-bold">:</label>


                <input type="date" name="date" class="form-control fw-bold" value="<?php echo date('Y-m-d'); ?>" required>


            </div>


        </div>





        <div id="nazira_section">


            <div class="row g-0 border rounded overflow-hidden mb-3">


                


                <!--    -->


                <div class="col-md-6 p-3 border-start section-box" id="box_morning">


                    <h5 class="text-white text-center bg-success p-2 rounded mb-3 shadow-sm">  </h5>


                    <div class="mb-2">


                        <label class="small fw-bold"> / </label>


                        <select name="morning_sabaq" id="morning_sabaq" class="form-select fw-bold">


                            <option value="">--   --</option>


                            <option value=" "> </option>


                            <option value=" "> </option>


                            <?php for ($i = 1; $i <= 30; $i++) echo "<option value=' $i'> $i</option>"; ?>


                        </select>


                    </div>


                    <div class="mb-2">


                        <label class="small fw-bold"> (Quantity)</label>


                        <input type="text" name="morning_qty" id="morning_qty" class="form-control fw-bold" placeholder=":  ,  ...">


                    </div>


                    <div class="mb-2">


                        <label class="small fw-bold">  (Time)</label>


                        <input type="time" name="morning_time" id="morning_time" class="form-control fw-bold">


                    </div>


                    <div class="mb-2">


                        <label class="small fw-bold"> / </label>


                        <select name="morning_status" id="morning_status" class="form-select status-select" onchange="colorStatus(this)">


                            <option value="">--</option>


                            <option value=" "> </option>


                            <option value=""></option>


                            <option value=""></option>


                            <option value=""></option>


                            <option value=""></option>


                        </select>


                    </div>


                </div>





                <!--    -->


                <div class="col-md-6 p-3 bg-light section-box" id="box_evening">


                    <h5 class="text-white text-center bg-primary p-2 rounded mb-3 shadow-sm">  </h5>


                    <div class="mb-2">


                        <label class="small fw-bold"> / </label>


                        <select name="evening_sabaq" id="evening_sabaq" class="form-select fw-bold">


                            <option value="">--   --</option>


                            <option value=" "> </option>


                            <option value=" "> </option>


                            <?php for ($i = 1; $i <= 30; $i++) echo "<option value=' $i'> $i</option>"; ?>


                        </select>


                    </div>


                    <div class="mb-2">


                        <label class="small fw-bold"> (Quantity)</label>


                        <input type="text" name="evening_qty" id="evening_qty" class="form-control fw-bold" placeholder=":  ,  ...">


                    </div>


                    <div class="mb-2">


                        <label class="small fw-bold">  (Time)</label>


                        <input type="time" name="evening_time" id="evening_time" class="form-control fw-bold">


                    </div>


                    <div class="mb-2">


                        <label class="small fw-bold"> / </label>


                        <select name="evening_status" id="evening_status" class="form-select status-select" onchange="colorStatus(this)">


                            <option value="">--</option>


                            <option value=" "> </option>


                            <option value=""></option>


                            <option value=""></option>


                            <option value=""></option>


                            <option value=""></option>


                        </select>


                    </div>


                </div>


            </div>


            


            <div class="row mt-3">


                <div class="col-12">


                    <button type="submit" name="save_nazira" id="save_nazira_btn" class="btn btn-success w-100 py-3 fw-bold shadow fs-5"> /  </button>


                </div>


            </div>


        </div>


    </form>


</div>





<script>


function colorStatus(sel) {


    if(sel.value == ' ') sel.style.backgroundColor = '#d1e7dd';


    else if(sel.value == '' || sel.value == '') sel.style.backgroundColor = '#f8d7da';


    else if(sel.value == '') sel.style.backgroundColor = '';


    else sel.style.backgroundColor = '#fff3cd';


}





// --- Voice Recognition ---


let recognition;


if ('webkitSpeechRecognition' in window) {


    recognition = new webkitSpeechRecognition();


    recognition.continuous = true;


    recognition.interimResults = true;


    recognition.lang = 'ur-PK';





    recognition.onresult = function(event) {


        let final_transcript = '';


        for (let i = event.resultIndex; i < event.results.length; ++i) {


            if (event.results[i].isConfirmed) final_transcript += event.results[i][0].transcript;


        }


        if(final_transcript) processVoiceCommand(final_transcript);


    };


}





function toggleVoice() {


    const btn = document.getElementById('mic_btn');


    const status = document.getElementById('status_text');


    if(btn.classList.contains('btn-warning')) {


        recognition.start();


        btn.classList.replace('btn-warning', 'btn-danger');


        btn.innerHTML = '<i class="fas fa-stop me-2"></i> <span>  (Stop)</span>';


        status.innerHTML = '<span class="text-danger fw-bold pulse">  ...</span>';


    } else {


        recognition.stop();


        btn.classList.replace('btn-danger', 'btn-warning');


        btn.innerHTML = '<i class="fas fa-microphone me-2"></i> <span> (Start)</span>';


        status.innerHTML = '<span class="opacity-75">  </span>';


    }


}





function processVoiceCommand(text) {


    text = text.toLowerCase();


    document.getElementById('status_text').innerText = "  : " + text;


    


    if(text.includes('') || text.includes('') || text.includes('')) {


        let val = text.includes('') ? '' : (text.includes('') ? '' : '');


        if(text.includes('')) { document.getElementById('morning_status').value = val; colorStatus(document.getElementById('morning_status')); }


        if(text.includes('')) { document.getElementById('evening_status').value = val; colorStatus(document.getElementById('evening_status')); }


    }


}





// Auto-load record


document.addEventListener('DOMContentLoaded', () => {


    $('#std_id').on('change', fetchNaziraRecord);


    document.querySelector('[name="date"]').addEventListener('change', fetchNaziraRecord);


});





function fetchNaziraRecord() {


    let std_id = document.getElementById('std_id').value;


    let date = document.querySelector('[name="date"]').value;


    if(!std_id || !date) return;


    


    fetch(`get_student_record.php?type=nazira&std_id=${std_id}&date=${date}`)


    .then(r => r.json())


    .then(res => {


        if(res.status === 'success') {


            let data = res.data;


            document.getElementById('morning_sabaq').value = data.morning_sabaq || '';


            document.getElementById('morning_qty').value = data.morning_qty || '';


            document.getElementById('morning_time').value = data.morning_time || '';


            document.getElementById('morning_status').value = data.morning_status || '';


            document.getElementById('evening_sabaq').value = data.evening_sabaq || '';


            document.getElementById('evening_qty').value = data.evening_qty || '';


            document.getElementById('evening_time').value = data.evening_time || '';


            document.getElementById('evening_status').value = data.evening_status || '';


            


            ['morning_status', 'evening_status'].forEach(id => colorStatus(document.getElementById(id)));


        } else {


            document.getElementById('morning_sabaq').value = '';


            document.getElementById('morning_qty').value = '';


            document.getElementById('morning_time').value = '';


            document.getElementById('morning_status').value = '';


            document.getElementById('evening_sabaq').value = '';


            document.getElementById('evening_qty').value = '';


            document.getElementById('evening_time').value = '';


            document.getElementById('evening_status').value = '';


            ['morning_status', 'evening_status'].forEach(id => colorStatus(document.getElementById(id)));


        }


    });


}


</script>





<style>


.section-box { transition: all 0.3s ease; }


.section-box:hover { background: rgba(0,0,0,0.02); }


.status-select { font-weight: bold; cursor: pointer; }


.pulse { animation: pulse-red 1.5s infinite; }


@keyframes pulse-red { 0% { opacity: 1; } 50% { opacity: 0.5; } 100% { opacity: 1; } }


</style>





<?php include 'footer.php'; ?>



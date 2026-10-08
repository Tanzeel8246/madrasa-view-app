<?php


require_once 'db.php';
$sys = 'Maktab';


include 'header.php';





$role = $_SESSION['role'] ?? '';


$can_teacher_attendance_page = app_has_perm('manage_teacher_attendance') || app_has_perm('manage_maktab_attendance');


if (!$can_teacher_attendance_page) {


    echo "<script>window.location='dashboard.php';</script>";


    exit;


}





$date = $_GET['date'] ?? $_POST['date'] ?? date('Y-m-d');


$success_msg = '';





function ta_status_label($status) {


    $labels = ['P' => '', 'L' => '', 'LA' => ' ', 'OD' => ' '];


    return $labels[$status] ?? $status;


}





function ta_lower($value) {


    $value = (string)$value;


    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);


}





function ta_text_has($value, $needles) {


    $text = ta_lower($value);


    foreach ($needles as $needle) {


        $needleText = ta_lower($needle);


        $found = function_exists('mb_strpos') ? mb_strpos($text, $needleText, 0, 'UTF-8') : strpos($text, $needleText);


        if ($needle !== '' && $found !== false) return true;


    }


    return false;


}





function ta_class_text($classRowOrName) {


    if (is_array($classRowOrName)) {


        return trim((string)($classRowOrName['name'] ?? '') . ' ' . (string)($classRowOrName['class_type'] ?? '') . ' ' . (string)($classRowOrName['section'] ?? ''));


    }


    return (string)$classRowOrName;


}





function ta_is_hifz_class($classRowOrName) {


    return ta_text_has(ta_class_text($classRowOrName), ['', '', '', '', 'حظ', 'hifz', 'gardan', 'husn']);


}





function ta_is_nazira_class($classRowOrName) {


    return ta_text_has(ta_class_text($classRowOrName), ['', '', 'ناظر', 'nazira']);


}





function ta_period_label($className, $periodNo) {


    $name = (string)$className;


    $p = (int)$periodNo;


    if (ta_is_hifz_class($name)) {


        return [1 => '', 2 => '  ', 3 => '', 4 => ''][$p] ?? (' ' . $p);


    }


    if (ta_is_nazira_class($name)) {


        return [1 => '', 2 => ''][$p] ?? (' ' . $p);


    }


    if (strpos($name, '') !== false || strpos($name, '') !== false || strpos($name, ' ') !== false) {


        return [1 => '', 2 => '  ', 3 => '', 4 => ''][$p] ?? (' ' . $p);


    }


    if (strpos($name, '') !== false) {


        return [1 => '', 2 => ''][$p] ?? (' ' . $p);


    }


    return ' ' . $p;


}





function ta_sync_daily_summary(PDO $pdo, $teacherId, $date) {


    $stmt = $pdo->prepare("SELECT status, late_minutes FROM teacher_period_attendance WHERE system_type='$sys' AND teacher_id = ? AND date = ?");


    $stmt->execute([$teacherId, $date]);


    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);


    if (!$rows) return;


    $status = 'L'; // Default to Leave instead of Absent


    $late = 0;


    foreach ($rows as $r) {


        $st = $r['status'] ?? '';


        if ($st === 'P' || $st === 'OD') $status = 'P';


        elseif ($status !== 'P' && ($st === 'L' || $st === 'LA')) $status = 'L';


        $late = max($late, (int)($r['late_minutes'] ?? 0));


    }


    $check = $pdo->prepare("SELECT id FROM attendance_maktab_teachers WHERE teacher_id = ? AND date = ? LIMIT 1");


    $check->execute([$teacherId, $date]);


    $id = $check->fetchColumn();


    if ($id) {


        $pdo->prepare("UPDATE attendance_maktab_teachers SET status = ?, late_minutes = ?, remarks = ? WHERE id = ?")


            ->execute([$status, $late, '', $id]);


    } else {


        $pdo->prepare("INSERT INTO attendance_maktab_teachers (teacher_id, date, status, late_minutes, remarks) VALUES (?, ?, ?, ?, ?)")


            ->execute([$teacherId, $date, $status, $late, '']);


    }


}





function ta_list_values($value) {


    if (is_array($value)) {


        $items = $value;


    } else {


        $raw = trim((string)$value);


        if ($raw === '') return [];


        $decoded = json_decode($raw, true);


        $items = is_array($decoded) ? $decoded : explode(',', $raw);


    }


    $out = [];


    foreach ($items as $item) {


        $item = trim((string)$item);


        if ($item !== '') $out[] = $item;


    }


    return array_values(array_unique($out));


}





function ta_period_no_from_key($key) {


    $key = trim((string)$key);


    $map = [


        'hifz_sabaq' => 1,


        'hifz_morning' => 1,


        'hifz_sabqi' => 2,


        'hifz_after_breakfast' => 2,


        'hifz_manzil' => 3,


        'hifz_noon' => 3,


        'hifz_review' => 4,


        'hifz_evening' => 4,


        'nazira_morning' => 1,


        'nazira_evening' => 2,


    ];


    return isset($map[$key]) ? $map[$key] : (int)$key;


}





function ta_assigned_period_numbers($assignedPeriods, $className) {


    $raw = trim((string)$assignedPeriods);


    if ($raw === '') return [];


    $values = [];


    $decoded = json_decode($raw, true);


    if (is_array($decoded)) {


        if (array_key_exists($className, $decoded)) {


            $values = is_array($decoded[$className])


                ? $decoded[$className]


                : explode(',', (string)$decoded[$className]);


        }


    } else {


        $values = explode(',', $raw);


    }


    $periods = [];


    foreach ($values as $value) {


        $periodNo = ta_period_no_from_key($value);


        if ($periodNo > 0) $periods[] = $periodNo;


    }


    return array_values(array_unique($periods));


}





function ta_default_periods_for_class($className) {


    $name = (string)$className;


    if (strpos($name, '') !== false || strpos($name, '') !== false || strpos($name, ' ') !== false || stripos($name, 'hifz') !== false) {


        return [1, 2, 3, 4];


    }


    if (strpos($name, '') !== false || stripos($name, 'nazira') !== false) {


        return [1, 2];


    }


    return [1, 2, 3, 4, 5, 6, 7, 8];


}





function ta_default_periods_for_class_safe($classRowOrName) {


    if (ta_is_hifz_class($classRowOrName)) return [1, 2, 3, 4];


    if (ta_is_nazira_class($classRowOrName)) return [1, 2];


    return [1, 2, 3, 4, 5, 6, 7, 8];


}





if (isset($_POST['save_teacher_period_attendance'])) {


    $statuses = $_POST['status'] ?? [];


    $lateMins = $_POST['late_minutes'] ?? [];


    $remarks = $_POST['remarks'] ?? [];


    $teacherTouched = [];


    foreach ($statuses as $key => $status) {


        $parts = explode('|', $key);


        if (count($parts) !== 3) continue;


        [$teacherId, $classId, $periodNo] = array_map('intval', $parts);


        if (!$teacherId || !$classId || !$periodNo) continue;


        $late = (int)($lateMins[$key] ?? 0);


        if ($status !== 'P') $late = 0;


        $rem = trim((string)($remarks[$key] ?? ''));


        $check = $pdo->prepare("SELECT id FROM teacher_period_attendance WHERE system_type='$sys' AND teacher_id = ? AND class_id = ? AND date = ? AND period_no = ? LIMIT 1");


        $check->execute([$teacherId, $classId, $date, $periodNo]);


        $id = $check->fetchColumn();


        if ($id) {


            $pdo->prepare("UPDATE teacher_period_attendance SET system_type='$sys', status = ?, late_minutes = ?, remarks = ? WHERE id = ?")


                ->execute([$status, $late, $rem, $id]);


        } else {


            $pdo->prepare("INSERT INTO teacher_period_attendance (teacher_id, class_id, date, period_no, status, late_minutes, remarks) VALUES (?, ?, ?, ?, ?, ?, ?)")


                ->execute([$teacherId, $classId, $date, $periodNo, $status, $late, $rem]);


        }


        $teacherTouched[$teacherId] = true;


    }


    foreach (array_keys($teacherTouched) as $teacherId) ta_sync_daily_summary($pdo, $teacherId, $date);


    $success_msg = '       ';


}





$assignments = [];


$sql = "SELECT cp.class_id, cp.period_no, cp.teacher_id, cp.teacher_ids, c.name AS class_name, c.main_teacher_ids


        FROM class_periods cp


        JOIN classes c ON c.id = cp.class_id


        WHERE (c.status = 'Active' OR c.status IS NULL OR c.status = '')


        ORDER BY c.name ASC, cp.period_no ASC";


$all_cp = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);





// First, build a map of class -> teacher -> explicit_periods


$explicit_map = [];


foreach ($all_cp as $row) {


    $cid = $row['class_id'];


    $pno = $row['period_no'];


    


    $tids = [];


    if (!empty($row['teacher_ids'])) {


        $decoded = json_decode($row['teacher_ids'], true);


        if (is_array($decoded)) $tids = array_merge($tids, $decoded);


    }


    if (!empty($row['teacher_id'])) $tids[] = $row['teacher_id'];


    


    foreach ($tids as $tid) {


        $explicit_map[$cid][$tid][] = $pno;


    }


}





// Now generate $assignments


foreach ($all_cp as $row) {


    $cid = $row['class_id'];


    $pno = $row['period_no'];


    $cname = $row['class_name'];


    


    $tids_for_this_period = [];


    


    // 1. Teachers explicitly assigned to this period


    if (!empty($row['teacher_ids'])) {


        $decoded = json_decode($row['teacher_ids'], true);


        if (is_array($decoded)) $tids_for_this_period = array_merge($tids_for_this_period, $decoded);


    }


    if (!empty($row['teacher_id'])) $tids_for_this_period[] = $row['teacher_id'];


    


    // 2. Teachers from main_teacher_ids (fallback logic)


    if (!empty($row['main_teacher_ids'])) {


        $m_ids = json_decode($row['main_teacher_ids'], true);


        if (!is_array($m_ids)) $m_ids = array_filter(explode(',', $row['main_teacher_ids']));


        


        foreach ($m_ids as $tid) {


            $has_explicit = isset($explicit_map[$cid][$tid]) && count($explicit_map[$cid][$tid]) > 0;


            if (!$has_explicit) {


                $tids_for_this_period[] = $tid;


            }


        }


    }


    


    foreach (array_unique(array_filter($tids_for_this_period)) as $tid) {


        $assignments[] = [


            'teacher_id' => (int)$tid,


            'class_id' => (int)$cid,


            'class_name' => $cname,


            'period_no' => (int)$pno,


        ];


    }


}





$all_classes_for_profiles = $pdo->query("SELECT id, name, class_type, section, main_teacher_ids FROM classes WHERE (status = 'Active' OR status IS NULL OR status = '')")->fetchAll(PDO::FETCH_ASSOC);


$classes_by_name = [];


foreach ($all_classes_for_profiles as $classRow) {


    if (!empty($classRow['name'])) $classes_by_name[$classRow['name']] = $classRow;


}





$profile_explicit_periods = [];


$profile_assignments = [];


$t_stmt = $pdo->prepare("SELECT id, assigned_classes, assigned_periods FROM maktab_teachers WHERE (status='Active' OR status='حاضر' OR status='فعال' OR status='' OR status IS NULL) AND (is_deleted=0 OR is_deleted IS NULL) AND (joining_date IS NULL OR joining_date = '' OR joining_date <= ?)");
$t_stmt->execute([$date]);
$teacher_profiles = $t_stmt->fetchAll(PDO::FETCH_ASSOC);


foreach ($teacher_profiles as $teacher) {


    $tid = (int)$teacher['id'];


    foreach ($classes_by_name as $className => $classRow) {


        $periods = ta_assigned_period_numbers($teacher['assigned_periods'] ?? '', $className);


        if (!empty($periods)) {


            $profile_explicit_periods[$tid . '|' . $className] = array_map('intval', $periods);


        }


    }


    foreach (ta_list_values($teacher['assigned_classes'] ?? '') as $className) {


        if (!isset($classes_by_name[$className])) continue;


        $classRow = $classes_by_name[$className];


        $periods = ta_assigned_period_numbers($teacher['assigned_periods'] ?? '', $className);


        if (!empty($periods)) {


            $profile_explicit_periods[$tid . '|' . $className] = array_map('intval', $periods);


        } else {


            $periods = ta_default_periods_for_class_safe($classRow);


        }


        foreach ($periods as $periodNo) {


            $profile_assignments[] = [


                'teacher_id' => $tid,


                'class_id' => (int)$classRow['id'],


                'class_name' => $className,


                'period_no' => (int)$periodNo,


            ];


        }


    }


}





foreach ($all_classes_for_profiles as $classRow) {


    $className = (string)($classRow['name'] ?? '');


    if ($className === '') continue;


    foreach (ta_list_values($classRow['main_teacher_ids'] ?? '') as $tidRaw) {


        $tid = (int)$tidRaw;


        if (!$tid) continue;


        $key = $tid . '|' . $className;


        $periods = $profile_explicit_periods[$key] ?? ta_default_periods_for_class_safe($classRow);


        foreach ($periods as $periodNo) {


            $profile_assignments[] = [


                'teacher_id' => $tid,


                'class_id' => (int)$classRow['id'],


                'class_name' => $className,


                'period_no' => (int)$periodNo,


            ];


        }


    }


}





$assignments = array_values(array_filter($assignments, function($assignment) use ($profile_explicit_periods) {


    $key = (int)$assignment['teacher_id'] . '|' . (string)$assignment['class_name'];


    return !isset($profile_explicit_periods[$key]) || in_array((int)$assignment['period_no'], $profile_explicit_periods[$key], true);


}));





$seen_assignments = [];


foreach ($assignments as $assignment) {


    $seen_assignments[(int)$assignment['teacher_id'] . '|' . (int)$assignment['class_id'] . '|' . (int)$assignment['period_no']] = true;


}


foreach ($profile_assignments as $assignment) {


    $key = (int)$assignment['teacher_id'] . '|' . (int)$assignment['class_id'] . '|' . (int)$assignment['period_no'];


    if (!isset($seen_assignments[$key])) {


        $assignments[] = $assignment;


        $seen_assignments[$key] = true;


    }


}





usort($assignments, function($a, $b) {


    $classCompare = strcmp((string)$a['class_name'], (string)$b['class_name']);


    if ($classCompare !== 0) return $classCompare;


    $periodCompare = (int)$a['period_no'] <=> (int)$b['period_no'];


    if ($periodCompare !== 0) return $periodCompare;


    return (int)$a['teacher_id'] <=> (int)$b['teacher_id'];


});





if ($assignments) {


    $teacherIds = array_values(array_unique(array_column($assignments, 'teacher_id')));


    $placeholders = implode(',', array_fill(0, count($teacherIds), '?'));


    $stmt = $pdo->prepare("SELECT id, name, phone FROM maktab_teachers WHERE id IN ($placeholders) AND (status='Active' OR status='حاضر' OR status='فعال' OR status='' OR status IS NULL) AND (is_deleted=0 OR is_deleted IS NULL) AND (joining_date IS NULL OR joining_date = '' OR joining_date <= ?)");


    $stmt->execute(array_merge($teacherIds, [$date]));


    $teacherMap = [];


    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) $teacherMap[(int)$t['id']] = $t;


} else {


    $teacherMap = [];


}





$existing = [];


$stmt = $pdo->prepare("SELECT * FROM teacher_period_attendance WHERE system_type='$sys' AND date = ?");


$stmt->execute([$date]);


foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {


    $existing[$r['teacher_id'].'|'.$r['class_id'].'|'.$r['period_no']] = $r;


}


$status_opts = ['P' => '', 'L' => '', 'LA' => ' ', 'OD' => ' '];


?>





<div class="container-fluid py-3">


    <div class="d-flex align-items-center justify-content-between mb-3">


        <h4 class="fw-bold mb-0"><i class="fas fa-chalkboard-teacher me-2 text-success"></i>    </h4>


        <a href="teacher_attendance_report.php" class="btn btn-outline-primary btn-sm fw-bold"><i class="fas fa-chart-bar me-1"></i> </a>


    </div>


    <?php if ($success_msg): ?><div class="alert alert-success fw-bold shadow-sm"> <?= htmlspecialchars($success_msg) ?></div><?php endif; ?>


    <form method="POST">


        <div class="card shadow-sm mb-4 border-0"><div class="card-body p-3"><div class="row align-items-end g-2">


            <div class="col-md-3"><label class="fw-bold mb-1">:</label><input type="date" name="date" class="form-control fw-bold" value="<?= htmlspecialchars($date) ?>" onchange="this.form.submit()"></div>


            <div class="col-md-4">


                <label class="fw-bold mb-1">  :</label>


                <select id="teacherFilter" class="form-select fw-bold">


                    <option value="">--   --</option>


                    <?php 


                    if (!empty($teacherMap)) {


                        foreach ($teacherMap as $tid => $t) {


                            echo '<option value="'.htmlspecialchars($tid).'">'.htmlspecialchars($t['name']).'</option>';


                        }


                    }


                    ?>


                </select>


            </div>


            <div class="col-md-2"><label class="fw-bold mb-1">  :</label><div class="fw-bold text-primary fs-5" id="visiblePeriodsCount"><?= count($assignments) ?></div></div>


            <div class="col-md-3 text-end"><button type="submit" name="save_teacher_period_attendance" class="btn btn-success fw-bold px-4 shadow w-100"><i class="fas fa-save me-2"></i> </button></div>


        </div></div></div>


        <?php if (empty($assignments)): ?>


            <div class="alert alert-warning text-center fw-bold">    /    </div>


        <?php else: ?>


        <div class="card shadow-sm border-0"><div class="card-body p-0"><div class="table-responsive">


            <table class="table table-bordered table-hover align-middle text-center m-0">


                <thead style="background:#284D3D;color:white"><tr><th>#</th><th class="text-start"></th><th></th><th></th><th></th><th></th><th></th></tr></thead>


                <tbody>


                <?php $sr=1; foreach ($assignments as $a): if (!isset($teacherMap[$a['teacher_id']])) continue; $key=$a['teacher_id'].'|'.$a['class_id'].'|'.$a['period_no']; $att=$existing[$key]??[]; $cur=$att['status']??'P'; $late=(int)($att['late_minutes']??0); ?>


                    <tr class="attendance-row <?= ($cur==='P'||$cur==='OD') ? ($late>0?'table-warning':'table-success') : 'table-info' ?>" data-teacher-id="<?= htmlspecialchars($a['teacher_id']) ?>">


                        <td><?= $sr++ ?></td>


                        <td class="text-start fw-bold"><?= htmlspecialchars($teacherMap[$a['teacher_id']]['name']) ?><br><small class="text-muted"><?= htmlspecialchars($teacherMap[$a['teacher_id']]['phone'] ?? '') ?></small></td>


                        <td><?= htmlspecialchars($a['class_name']) ?></td>


                        <td><?= htmlspecialchars(ta_period_label($a['class_name'], $a['period_no'])) ?></td>


                        <td><select name="status[<?= htmlspecialchars($key) ?>]" class="form-select form-select-sm fw-bold"><?php foreach($status_opts as $v=>$label): ?><option value="<?= $v ?>" <?= $cur===$v?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></td>


                        <td><input type="number" min="0" name="late_minutes[<?= htmlspecialchars($key) ?>]" value="<?= $late ?>" class="form-control form-control-sm text-center"></td>


                        <td><input type="text" name="remarks[<?= htmlspecialchars($key) ?>]" value="<?= htmlspecialchars($att['remarks'] ?? '') ?>" class="form-control form-control-sm"></td>


                    </tr>


                <?php endforeach; ?>


                </tbody>


            </table>


        </div></div></div>


        <div class="mt-3 text-end"><button type="submit" name="save_teacher_period_attendance" class="btn btn-success fw-bold px-5 shadow-sm">   </button></div>


        <?php endif; ?>


    </form>


</div>





<script>


document.addEventListener('DOMContentLoaded', function() {


    const filter = document.getElementById('teacherFilter');


    if (!filter) return;


    


    filter.addEventListener('change', function() {


        const selectedId = this.value;


        const rows = document.querySelectorAll('.attendance-row');


        let count = 0;


        


        rows.forEach(row => {


            const inputs = row.querySelectorAll('select, input');


            if (selectedId === '' || row.getAttribute('data-teacher-id') === selectedId) {


                row.style.display = '';


                inputs.forEach(inp => inp.disabled = false);


                count++;


            } else {


                row.style.display = 'none';


                inputs.forEach(inp => inp.disabled = true);


            }


        });


        


        document.getElementById('visiblePeriodsCount').textContent = count;


    });


});


</script>


<?php include 'footer.php'; ?>






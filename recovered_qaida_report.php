                            $list_absent = [];
                            $list_leave = [];
                            $list_s1_sender = [];
                            $list_s1_nagha = [];
                            $list_s1_abs_leave = [];
                            $list_s2_sender = [];
                            $list_s2_nagha = [];
                            $list_s2_abs_leave = [];
                            $list_s3_sender = [];
                            $list_s3_nagha = [];
                            $list_s3_abs_leave = [];

                            $sr_no = 1;
                            foreach ($all_qaida_students as $student) {
                                $student_id = $student['id'];
                                $rec = $nazira_map[$date][$student_id] ?? null;
                                $st_roll = ($student['roll_number'] ?: $student['admission_number']) ?? '';
                                $st_name = $student['name'] ?? '';
                                $st_fname = $student['father_name'] ?? '';
                                
                                $attStatus = $att_map[$date][$student_id] ?? ['s1'=>'P', 's2'=>'P', 's3'=>'P'];
                                $as1 = $attStatus['s1'] ?? 'P';
                                $as2 = $attStatus['s2'] ?? 'P';
                                $as3 = $attStatus['s3'] ?? 'P';
                                
                                // If record exists and marks specific absence, override
                                if ($rec) {
                                    if (in_array($rec['attendance'] ?? '', ['غیر حاضر', 'Absent'])) { $as1='A'; $as2='A'; $as3='A'; }
                                    elseif (in_array($rec['attendance'] ?? '', ['رخصت', 'Leave'])) { $as1='L'; $as2='L'; $as3='L'; }
                                }
                                
                                if ($as1 === 'A') $summary_s1_absent++; elseif ($as1 === 'L') $summary_s1_leave++;
                                if ($as2 === 'A') $summary_s2_absent++; elseif ($as2 === 'L') $summary_s2_leave++;
                                if ($as3 === 'A') $summary_s3_absent++; elseif ($as3 === 'L') $summary_s3_leave++;
                                
                                if ($as1 === 'A' || $as2 === 'A' || $as3 === 'A') {
                                    $summary_absent_cnt++;
                                    $list_absent[] = ['roll' => $st_roll, 'name' => $st_name, 'father_name' => $st_fname, 'status' => 'غیر حاضر'];
                                }
                                if ($as1 === 'L' || $as2 === 'L' || $as3 === 'L') {
                                    $summary_leave_cnt++;
                                    $list_leave[] = ['roll' => $st_roll, 'name' => $st_name, 'father_name' => $st_fname, 'status' => 'رخصت'];
                                }
                                
                                if ($rec) {
                                    $students_with_records[] = $student_id;
                                    $ht = empty($rec['hearing_time']) ? '-' : date("h:i A", strtotime($rec['hearing_time']));
                                    
                                    $s1 = htmlspecialchars($rec['sabaq_1'] ?? '-');
                                    $q1 = htmlspecialchars($rec['qty_1'] ?? '-');
                                    $g1_raw = $rec['grade_1'] ?? '-';
                                    
                                    if ($as1 === 'A') {
                                        $g1 = 'غیر حاضر';
                                        $list_s1_abs_leave[] = ['roll' => $st_roll, 'name' => $st_name, 'father_name' => $st_fname, 'status' => 'غیر حاضر (سبق)'];
                                    } elseif ($as1 === 'L') {
                                        $g1 = 'رخصت';
                                        $list_s1_abs_leave[] = ['roll' => $st_roll, 'name' => $st_name, 'father_name' => $st_fname, 'status' => 'رخصت (سبق)'];
                                    } elseif (($s1 !== '-' && $s1 !== '') || ($g1_raw !== '-' && $g1_raw !== '')) {
                                        $g1 = $g1_raw !== '-' ? $g1_raw : 'سنایا';
                                        $list_s1_sender[] = ['roll' => $st_roll, 'name' => $st_name, 'father_name' => $st_fname, 'status' => "سبق: $s1 | مقدار: $q1 | کیفیت: $g1"];
                                    } else {
                                        $g1 = '-';
                                        $summary_missed_sabaq++;
                                        $list_s1_nagha[] = ['roll' => $st_roll, 'name' => $st_name, 'father_name' => $st_fname, 'status' => 'ناغہ (سبق نہیں سنایا)'];
                                    }

                                    $s2 = htmlspecialchars($rec['sabaq_2'] ?? '-');
                                    $q2 = htmlspecialchars($rec['qty_2'] ?? '-');
                                    $g2_raw = $rec['grade_2'] ?? '-';
                                    
                                    if ($as2 === 'A') {
                                        $g2 = 'غیر حاضر';
                                        $list_s2_abs_leave[] = ['roll' => $st_roll, 'name' => $st_name, 'father_name' => $st_fname, 'status' => 'غیر حاضر (سبقی)'];
                                    } elseif ($as2 === 'L') {
                                        $g2 = 'رخصت';
                                        $list_s2_abs_leave[] = ['roll' => $st_roll, 'name' => $st_name, 'father_name' => $st_fname, 'status' => 'رخصت (سبقی)'];
                                    } elseif (($s2 !== '-' && $s2 !== '') || ($g2_raw !== '-' && $g2_raw !== '')) {
                                        $g2 = $g2_raw !== '-' ? $g2_raw : 'سنایا';
                                        $list_s2_sender[] = ['roll' => $st_roll, 'name' => $st_name, 'father_name' => $st_fname, 'status' => "سبقی: $s2 | مقدار: $q2 | کیفیت: $g2"];
                                    } else {
                                        $g2 = '-';
                                        $summary_missed_sabqi++;
                                        $list_s2_nagha[] = ['roll' => $st_roll, 'name' => $st_name, 'father_name' => $st_fname, 'status' => 'ناغہ (سبقی نہیں سنائی)'];
                                    }

                                    $s3 = htmlspecialchars($rec['sabaq_3'] ?? '-');
                                    $q3 = htmlspecialchars($rec['qty_3'] ?? '-');
                                    $g3_raw = $rec['grade_3'] ?? '-';
                                    
                                    if ($as3 === 'A') {
                                        $g3 = 'غیر حاضر';
                                        $list_s3_abs_leave[] = ['roll' => $st_roll, 'name' => $st_name, 'father_name' => $st_fname, 'status' => 'غیر حاضر (منزل)'];
                                    } elseif ($as3 === 'L') {
                                        $g3 = 'رخصت';
                                        $list_s3_abs_leave[] = ['roll' => $st_roll, 'name' => $st_name, 'father_name' => $st_fname, 'status' => 'رخصت (منزل)'];
                                    } elseif (($s3 !== '-' && $s3 !== '') || ($g3_raw !== '-' && $g3_raw !== '')) {
                                        $g3 = $g3_raw !== '-' ? $g3_raw : 'سنایا';
                                        $list_s3_sender[] = ['roll' => $st_roll, 'name' => $st_name, 'father_name' => $st_fname, 'status' => "منزل: $s3 | مقدار: $q3 | کیفیت: $g3"];
                                    } else {
                                        $g3 = '-';
                                        $summary_missed_manzil++;
                                        $list_s3_nagha[] = ['roll' => $st_roll, 'name' => $st_name, 'father_name' => $st_fname, 'status' => 'ناغہ (منزل نہیں سنائی)'];
                                    }
                                    
                                    $s4 = htmlspecialchars($rec['sabaq_4'] ?? '-');
                                    $q4 = htmlspecialchars($rec['qty_4'] ?? '-');
                                    $g4 = $rec['grade_4'] ?? '-';
                                    
                                    $att = translateStatusClean($rec['attendance'] ?? '-');
                                    $beh = htmlspecialchars($rec['behavior'] ?? '-');
                                } else {
                                    $ht = '-';
                                    $s1 = '-'; $q1 = '-';
                                    if ($as1 === 'A') {
                                        $g1 = 'غیر حاضر';
                                        $list_s1_abs_leave[] = ['roll' => $st_roll, 'name' => $st_name, 'father_name' => $st_fname, 'status' => 'غیر حاضر (سبق)'];
                                    } elseif ($as1 === 'L') {
                                        $g1 = 'رخصت';
                                        $list_s1_abs_leave[] = ['roll' => $st_roll, 'name' => $st_name, 'father_name' => $st_fname, 'status' => 'رخصت (سبق)'];
                                    } else {
                                        $g1 = '-';
                                        $summary_missed_sabaq++;
                                        $list_s1_nagha[] = ['roll' => $st_roll, 'name' => $st_name, 'father_name' => $st_fname, 'status' => 'ناغہ (سبق نہیں سنایا)'];
                                    }

                                    $s2 = '-'; $q2 = '-';
                                    if ($as2 === 'A') {
                                        $g2 = 'غیر حاضر';
                                        $list_s2_abs_leave[] = ['roll' => $st_roll, 'name' => $st_name, 'father_name' => $st_fname, 'status' => 'غیر حاضر (سبقی)'];
                                    } elseif ($as2 === 'L') {
                                        $g2 = 'رخصت';
                                        $list_s2_abs_leave[] = ['roll' => $st_roll, 'name' => $st_name, 'father_name' => $st_fname, 'status' => 'رخصت (سبقی)'];
                                    } else {
                                        $g2 = '-';
                                        $summary_missed_sabqi++;
                                        $list_s2_nagha[] = ['roll' => $st_roll, 'name' => $st_name, 'father_name' => $st_fname, 'status' => 'ناغہ (سبقی نہیں سنائی)'];
                                    }

                                    $s3 = '-'; $q3 = '-';
                                    if ($as3 === 'A') {
                                        $g3 = 'غیر حاضر';
                                        $list_s3_abs_leave[] = ['roll' => $st_roll, 'name' => $st_name, 'father_name' => $st_fname, 'status' => 'غیر حاضر (منزل)'];
                                    } elseif ($as3 === 'L') {
                                        $g3 = 'رخصت';
                                        $list_s3_abs_leave[] = ['roll' => $st_roll, 'name' => $st_name, 'father_name' => $st_fname, 'status' => 'رخصت (منزل)'];
                                    } else {
                                        $g3 = '-';
                                        $summary_missed_manzil++;
                                        $list_s3_nagha[] = ['roll' => $st_roll, 'name' => $st_name, 'father_name' => $st_fname, 'status' => 'ناغہ (منزل نہیں سنائی)'];
                                    }
                                    
                                    $s4 = '-'; $q4 = '-'; $g4 = '-';
                                    
                                    if ($as1 === 'A' || $as2 === 'A' || $as3 === 'A') $att = 'غیر حاضر';
                                    elseif ($as1 === 'L' || $as2 === 'L' || $as3 === 'L') $att = 'رخصت';
                                    else $att = 'حاضر';
                                    $beh = '-';
                                }
                                
                                $comp_key = $student_id . '_' . $date;
                                if (isset($complaints_map[$comp_key])) {
                                    $complaints_html = implode('<br><hr class="my-1 border-danger">', $complaints_map[$comp_key]);
                                    $daily_complaints += count($complaints_map[$comp_key]);
                                } else {
                                    $complaints_html = '-';
                                }
                                
                                echo "<tr>
                                    <td>$sr_no</td>
                                    <td>".htmlspecialchars($st_roll)."</td>
                                    <td class='fw-bold'>".htmlspecialchars($st_name)." " . (!empty($st_fname) ? "<br><small class='text-muted'>".htmlspecialchars($st_fname)."</small>" : "") . "</td>
                                    <td dir='ltr'>{$ht}</td>
                                    <td>{$s1}</td><td>{$q1}</td><td>{$g1}</td>
                                    <td>{$s2}</td><td>{$q2}</td><td>{$g2}</td>
                                    <td>{$s3}</td><td>{$q3}</td><td>{$g3}</td>
                                    <td>{$s4}</td><td>{$q4}</td><td>{$g4}</td>
                                    <td class='fw-bold'>{$att}</td>
                                    <td class='small text-start text-danger' style='font-size:11px;'><b>رویہ: {$beh}</b><br>{$complaints_html}</td>
                                </tr>";
                                
                                $sr_no++;
                            }
                            
                            // Daily Summary Row
                            $total_st = count($all_qaida_students);
                            $cnt_absent = count($list_absent);
                            $cnt_leave = count($list_leave);
                            $cnt_s1_sender = count($list_s1_sender);
                            $cnt_s1_nagha = count($list_s1_nagha);
                            $cnt_s1_abs_leave = count($list_s1_abs_leave);
                            $cnt_s2_sender = count($list_s2_sender);
                            $cnt_s2_nagha = count($list_s2_nagha);
                            $cnt_s2_abs_leave = count($list_s2_abs_leave);
                            $cnt_s3_sender = count($list_s3_sender);
                            $cnt_s3_nagha = count($list_s3_nagha);
                            $cnt_s3_abs_leave = count($list_s3_abs_leave);
                            
                            $json_absent = htmlspecialchars(json_encode($list_absent, JSON_UNESCAPED_UNICODE), ENT_QUOTES);
                            $json_leave = htmlspecialchars(json_encode($list_leave, JSON_UNESCAPED_UNICODE), ENT_QUOTES);
                            $json_s1_sender = htmlspecialchars(json_encode($list_s1_sender, JSON_UNESCAPED_UNICODE), ENT_QUOTES);
                            $json_s1_nagha = htmlspecialchars(json_encode($list_s1_nagha, JSON_UNESCAPED_UNICODE), ENT_QUOTES);
                            $json_s1_abs_leave = htmlspecialchars(json_encode($list_s1_abs_leave, JSON_UNESCAPED_UNICODE), ENT_QUOTES);
                            $json_s2_sender = htmlspecialchars(json_encode($list_s2_sender, JSON_UNESCAPED_UNICODE), ENT_QUOTES);
                            $json_s2_nagha = htmlspecialchars(json_encode($list_s2_nagha, JSON_UNESCAPED_UNICODE), ENT_QUOTES);
                            $json_s2_abs_leave = htmlspecialchars(json_encode($list_s2_abs_leave, JSON_UNESCAPED_UNICODE), ENT_QUOTES);
                            $json_s3_sender = htmlspecialchars(json_encode($list_s3_sender, JSON_UNESCAPED_UNICODE), ENT_QUOTES);
                            $json_s3_nagha = htmlspecialchars(json_encode($list_s3_nagha, JSON_UNESCAPED_UNICODE), ENT_QUOTES);
                            $json_s3_abs_leave = htmlspecialchars(json_encode($list_s3_abs_leave, JSON_UNESCAPED_UNICODE), ENT_QUOTES);

                            echo "<tr class='border-top border-dark border-2' style='background:#f0f4f8;'>
                                <td colspan='14' class='p-2'>
                                  <div class='d-flex justify-content-between align-items-center mb-1' dir='rtl'>
                                    <div class='d-flex gap-3 align-items-center'>
                                      <span class='badge bg-secondary p-2' style='font-size:11px;'>کل طلبہ: {$total_st}</span>
                                      <a href='javascript:void(0)' onclick='openStudentDrillModal(\"غیر حاضر طلبہ ({$formatted_date})\", \"غیر حاضر\", {$json_absent})' class='badge bg-danger p-2 text-decoration-none' style='font-size:11px;' title='فہرست دیکھنے کے لیے کلک کریں'><i class='fas fa-user-times me-1'></i> غیر حاضر: <u>{$cnt_absent}</u></a>
                                      <a href='javascript:void(0)' onclick='openStudentDrillModal(\"رخصت والے طلبہ ({$formatted_date})\", \"رخصت\", {$json_leave})' class='badge bg-warning text-dark p-2 text-decoration-none' style='font-size:11px;' title='فہرست دیکھنے کے لیے کلک کریں'><i class='fas fa-envelope me-1'></i> رخصت: <u>{$cnt_leave}</u></a>
                                      <span class='fw-bold text-danger' style='font-size:11px;'>شکایات: $daily_complaints</span>
                                    </div>
                                    <span class='fw-bold text-dark' style='font-size:12px;'><i class='fas fa-chart-pie me-1 text-primary'></i> روزانہ سمری ({$formatted_date})</span>
                                  </div>
                                  <div class='row g-2' dir='rtl'>
                                    <div class='col-4'>
                                      <div class='border border-success rounded p-2 text-center shadow-sm' style='font-size:11px; background:rgba(25,135,84,0.06)'>
                                        <div class='fw-bold text-success mb-1 border-bottom pb-1'><i class='fas fa-book-reader me-1'></i> سبق (Sabaq)</div>
                                        <a href='javascript:void(0)' onclick='openStudentDrillModal(\"سبق سنانے والے ({$formatted_date})\", \"سبق سنانے والے\", {$json_s1_sender})' class='text-success fw-bold text-decoration-none d-block py-1'><i class='fas fa-check-circle me-1'></i> سنانے والے: <span class='badge bg-success'>$cnt_s1_sender</span></a>
                                        <a href='javascript:void(0)' onclick='openStudentDrillModal(\"سبق ناغہ کرنے والے ({$formatted_date})\", \"سبق ناغہ\", {$json_s1_nagha})' class='text-danger fw-bold text-decoration-none d-block py-1'><i class='fas fa-times-circle me-1'></i> ناغہ: <span class='badge bg-danger'>$cnt_s1_nagha</span></a>
                                        <a href='javascript:void(0)' onclick='openStudentDrillModal(\"سبق پیریڈ غیر حاضر / رخصت ({$formatted_date})\", \"غیر حاضر / رخصت\", {$json_s1_abs_leave})' class='text-secondary fw-bold text-decoration-none d-block py-1' style='opacity:0.85'><i class='fas fa-user-clock me-1'></i> غیر حاضر/رخصت: <span class='badge bg-secondary'>$cnt_s1_abs_leave</span></a>
                                      </div>
                                    </div>
                                    <div class='col-4'>
                                      <div class='border border-primary rounded p-2 text-center shadow-sm' style='font-size:11px; background:rgba(13,110,253,0.06)'>
                                        <div class='fw-bold text-primary mb-1 border-bottom pb-1'><i class='fas fa-sync me-1'></i> سبقی (Sabqi)</div>
                                        <a href='javascript:void(0)' onclick='openStudentDrillModal(\"سبقی سنانے والے ({$formatted_date})\", \"سبقی سنانے والے\", {$json_s2_sender})' class='text-primary fw-bold text-decoration-none d-block py-1'><i class='fas fa-check-circle me-1'></i> سنانے والے: <span class='badge bg-primary'>$cnt_s2_sender</span></a>
                                        <a href='javascript:void(0)' onclick='openStudentDrillModal(\"سبقی ناغہ کرنے والے ({$formatted_date})\", \"سبقی ناغہ\", {$json_s2_nagha})' class='text-danger fw-bold text-decoration-none d-block py-1'><i class='fas fa-times-circle me-1'></i> ناغہ: <span class='badge bg-danger'>$cnt_s2_nagha</span></a>
                                        <a href='javascript:void(0)' onclick='openStudentDrillModal(\"سبقی پیریڈ غیر حاضر / رخصت ({$formatted_date})\", \"غیر حاضر / رخصت\", {$json_s2_abs_leave})' class='text-secondary fw-bold text-decoration-none d-block py-1' style='opacity:0.85'><i class='fas fa-user-clock me-1'></i> غیر حاضر/رخصت: <span class='badge bg-secondary'>$cnt_s2_abs_leave</span></a>
                                      </div>
                                    </div>
                                    <div class='col-4'>
                                      <div class='border border-warning rounded p-2 text-center shadow-sm' style='font-size:11px; background:rgba(255,193,7,0.06)'>
                                        <div class='fw-bold text-warning text-dark mb-1 border-bottom pb-1'><i class='fas fa-quran me-1'></i> منزل (Manzil)</div>
                                        <a href='javascript:void(0)' onclick='openStudentDrillModal(\"منزل سنانے والے ({$formatted_date})\", \"منزل سنانے والے\", {$json_s3_sender})' class='text-dark fw-bold text-decoration-none d-block py-1'><i class='fas fa-check-circle text-success me-1'></i> سنانے والے: <span class='badge bg-dark'>$cnt_s3_sender</span></a>
                                        <a href='javascript:void(0)' onclick='openStudentDrillModal(\"منزل ناغہ کرنے والے ({$formatted_date})\", \"منزل ناغہ\", {$json_s3_nagha})' class='text-danger fw-bold text-decoration-none d-block py-1'><i class='fas fa-times-circle me-1'></i> ناغہ: <span class='badge bg-danger'>$cnt_s3_nagha</span></a>
                                        <a href='javascript:void(0)' onclick='openStudentDrillModal(\"منزل پیریڈ غیر حاضر / رخصت ({$formatted_date})\", \"غیر حاضر / رخصت\", {$json_s3_abs_leave})' class='text-secondary fw-bold text-decoration-none d-block py-1' style='opacity:0.85'><i class='fas fa-user-clock me-1'></i> غیر حاضر/رخصت: <span class='badge bg-secondary'>$cnt_s3_abs_leave</span></a>
                                      </div>
                                    </div>
                                  </div>
                                </td>
                              </tr>";
                        }
                    } else {
                        echo "<tr><td colspan='14' class='text-danger p-4 fw-bold'>اس کلاس میں کوئی طالب علم نہیں ملا۔</td></tr>";
                    }
                    
                    $students_with_records = array_unique($students_with_records);
                    $academicScore = ($h_count > 0) ? round(($totalSabaq + $totalSabqi + $totalManzil) / $h_count) : 0;
                    ?>
                </tbody>
            </table>
        </div>

        <?php
        $students_with_records = array_unique($students_with_records);
        $lazy_students = [];
        foreach($all_qaida_students as $hs) {
            if(!in_array($hs['id'], $students_with_records)) {
                $lazy_students[] = $hs;
            }
        }
        usort($lazy_students, function($a, $b) {
            return $a['admission_number'] <=> $b['admission_number'];
        });
        ?>
        
        <?php if(count($lazy_students) > 0): ?>
        <div class="mt-5">
            <h4 class="text-danger border-bottom border-danger pb-2 fw-bold"><i class="fas fa-exclamation-circle me-2"></i> سست طلبہ (جن کی کوئی رپورٹ درج نہیں ہوئی)</h4>
            <table class="table table-bordered border-danger text-center table-sm mt-3" style="font-size: 13px;">
                <thead class="bg-danger text-white">
                    <tr>
                        <th style="width: 10%;">رول نمبر</th>
                        <th style="width: 30%;">نام <?php echo t('student'); ?></th>
                        <th style="width: 30%;">ولدیت</th>
                        <th style="width: 30%;">کیفیت</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($lazy_students as $ls): ?>
                    <tr>
                        <td class="fw-bold bg-light h6 m-0"><?php echo $ls['admission_number']; ?></td>
                        <td class="text-start ps-3 fw-bold"><?php echo $ls['name']; ?></td>
                        <td class="text-start ps-3 text-muted"><?php echo $ls['father_name']; ?></td>
                        <td class="text-danger fw-bold">کوئی <?php echo t('sabaq'); ?> نہیں سنایا</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if($h_count > 0): ?>
        <div class="row mt-4">
            <div class="col-md-4 text-center">
                <div class="card p-3 shadow-sm border-0 bg-light">
                    <h6 class="fw-bold mb-3">حفظ کی مجموعی کارکردگی</h6>
                    <div class="gauge-container">
                        <svg class="gauge-svg" viewBox="0 0 100 100">
                            <circle class="gauge-bg" cx="50" cy="50" r="40"></circle>
                            <circle class="gauge-fill" cx="50" cy="50" r="40" style="stroke-dasharray: <?php echo ($academicScore * 2.51); ?>, 251.2;"></circle>
                        </svg>
                        <div class="gauge-text"><?php echo $academicScore; ?>%</div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Drill-down Student List Modal -->
<div class="modal fade" id="drillDownStudentModal" tabindex="-1" aria-labelledby="drillDownModalLabel" aria-hidden="true" dir="rtl">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content shadow-lg border-0">
            <div class="modal-header bg-success text-white py-2">
                <div class="d-flex align-items-center gap-2">
                    <i class="fas fa-users-cog fs-5"></i>
                    <h5 class="modal-title fw-bold fs-6 mb-0" id="drillDownModalLabel">تفصیلات طلبہ</h5>
                    <span class="badge bg-light text-dark rounded-pill px-2" id="drillDownCount">0</span>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3 bg-light">
                <div class="d-flex justify-content-between align-items-center mb-2 gap-2">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" id="drillDownSearchInput" class="form-control" placeholder="نام یا رول نمبر سے تلاش کریں..." onkeyup="filterDrillDownTable()">
                    </div>
                    <button class="btn btn-sm btn-outline-secondary text-nowrap" onclick="printDrillDownTable()"><i class="fas fa-print me-1"></i> پرنٹ</button>
                </div>
                <div class="table-responsive bg-white rounded shadow-sm" style="max-height: 400px;">
                    <table class="table table-hover table-bordered table-sm text-center mb-0 align-middle" id="drillDownTable" style="font-size: 13px;">
                        <thead class="table-secondary sticky-top">
                            <tr>
                                <th style="width: 8%;">#</th>
                                <th style="width: 15%;">رول نمبر</th>
                                <th style="width: 32%;">طالب علم کا نام</th>
                                <th style="width: 25%;">ولدیت</th>
                                <th style="width: 20%;">کیفیت / تفصیل</th>
                            </tr>
                        </thead>
                        <tbody id="drillDownTableBody">
                            <!-- Populated via JS -->
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer py-2 bg-white d-flex justify-content-between">
                <small class="text-muted" id="drillDownCategoryNote"></small>
                <button type="button" class="btn btn-secondary btn-sm fw-bold px-3" data-bs-dismiss="modal">بند کریں</button>
            </div>
        </div>
    </div>
</div>

<script>
let currentDrillStudents = [];
function openStudentDrillModal(title, categoryName, students) {
    currentDrillStudents = Array.isArray(students) ? students : [];
    document.getElementById('drillDownModalLabel').innerText = title;
    document.getElementById('drillDownCount').innerText = currentDrillStudents.length + ' طلبہ';
    document.getElementById('drillDownCategoryNote').innerText = 'کیٹیگری: ' + categoryName;
    document.getElementById('drillDownSearchInput').value = '';
    
    renderDrillDownRows(currentDrillStudents);
    
    const modalEl = document.getElementById('drillDownStudentModal');
    const modalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
    modalInstance.show();
}

function renderDrillDownRows(list) {
    const tbody = document.getElementById('drillDownTableBody');
    if (!list || list.length === 0) {
        tbody.innerHTML = `<tr><td colspan="5" class="p-3 text-muted fw-bold">اس کیٹیگری میں کوئی طالب علم موجود نہیں ہے۔</td></tr>`;
        return;
    }
    let html = '';
    list.forEach((st, idx) => {
        const roll = st.roll || '-';
        const name = st.name || '-';
        const fname = st.father_name || '-';
        const status = st.status || '-';
        
        let statusBadge = status;
        if (status.includes('غیر حاضر')) {
            statusBadge = `<span class="badge bg-danger">${status}</span>`;
        } else if (status.includes('رخصت')) {
            statusBadge = `<span class="badge bg-warning text-dark">${status}</span>`;
        } else if (status.includes('ناغہ')) {
            statusBadge = `<span class="badge bg-secondary">${status}</span>`;
        } else {
            statusBadge = `<span class="text-success fw-bold">${status}</span>`;
        }
        
        html += `<tr>
            <td class="text-muted fw-bold">${idx + 1}</td>
            <td class="fw-bold">${roll}</td>
            <td class="text-start ps-3 fw-bold text-dark">${name}</td>
            <td class="text-start ps-3 text-muted">${fname}</td>
            <td>${statusBadge}</td>
        </tr>`;
    });
    tbody.innerHTML = html;
}

function filterDrillDownTable() {
    const q = (document.getElementById('drillDownSearchInput').value || '').trim().toLowerCase();
    if (!q) {
        renderDrillDownRows(currentDrillStudents);
        return;
    }
    const filtered = currentDrillStudents.filter(st => {
        return (st.name && st.name.toLowerCase().includes(q)) ||
               (st.roll && String(st.roll).toLowerCase().includes(q)) ||
               (st.father_name && st.father_name.toLowerCase().includes(q)) ||
               (st.status && st.status.toLowerCase().includes(q));
    });
    renderDrillDownRows(filtered);
}

function printDrillDownTable() {
    const title = document.getElementById('drillDownModalLabel').innerText;
    const count = document.getElementById('drillDownCount').innerText;
    const content = document.getElementById('drillDownTable').outerHTML;
    const printWin = window.open('', '', 'height=650,width=900');
    printWin.document.write('<html dir="rtl"><head><title>' + title + '</title>');
    printWin.document.write('<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">');
    printWin.document.write('<style>body{font-family:Tahoma,Arial,sans-serif;padding:20px;} table{width:100%;font-size:12px;}</style></head><body>');
    printWin.document.write('<h4 class="text-center fw-bold mb-1">' + title + '</h4>');
    printWin.document.write('<p class="text-center text-muted mb-3">تعداد: ' + count + '</p>');
    printWin.document.write(content);
    printWin.document.write('</body></html>');
    printWin.document.close();
    printWin.focus();
    setTimeout(() => { printWin.print(); printWin.close(); }, 400);
}
</script>

<?php include 'footer.php'; ?>
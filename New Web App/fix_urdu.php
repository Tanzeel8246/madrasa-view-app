<?php
\ = ['academic_report_view.php', 'hifz_filtered_report.php', 'hifz_report.php', 'nazira_report.php', 'parent_report.php', 'qaida_report.php'];
foreach (\ as \) {
    \ = file_get_contents(\);
    \ = '/function translateDateUrduFull\(\\)\s*\{.*?\}/s';
    \ = "function translateDateUrduFull(\) {\n    if(!\) return '-';\n    \ = strtotime(\);\n    \ = ['Jan'=>'جنوری', 'Feb'=>'فروری', 'Mar'=>'مارچ', 'Apr'=>'اپریل', 'May'=>'مئی', 'Jun'=>'جون', 'Jul'=>'جولائی', 'Aug'=>'اگست', 'Sep'=>'ستمبر', 'Oct'=>'اکتوبر', 'Nov'=>'نومبر', 'Dec'=>'دسمبر'];\n    \ = ['Mon'=>'پیر', 'Tue'=>'منگل', 'Wed'=>'بدھ', 'Thu'=>'جمعرات', 'Fri'=>'جمعہ', 'Sat'=>'ہفتہ', 'Sun'=>'اتوار'];\n    return date('d', \) . '-' . (\[date('M', \)] ?? '') . '-' . date('Y', \) . ' (' . (\[date('D', \)] ?? '') . ')';\n}";
    \ = preg_replace(\, \, \);
    file_put_contents(\, \);
    echo "Fixed \\n";
}

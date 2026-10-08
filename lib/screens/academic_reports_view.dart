// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import '../db_helper.dart';
import '../utils/globals.dart';
import '../widgets/app_drawer.dart';
import 'academic_result_preview.dart';

class AcademicReportsViewPage extends StatefulWidget {
  const AcademicReportsViewPage({super.key});

  @override
  State<AcademicReportsViewPage> createState() => _AcademicReportsViewPageState();
}

class _AcademicReportsViewPageState extends State<AcademicReportsViewPage>
    with SingleTickerProviderStateMixin {
  late TabController _tabController;

  final Color primaryGreen = const Color(0xFF1B4332);
  final Color secondaryGreen = const Color(0xFF284D3D);
  final Color accentGold = const Color(0xFFD4AF37);
  final Color bgColor = const Color(0xFFF4F6F9);

  // Common filters
  List<Map<String, dynamic>> _exams = [];
  List<Map<String, dynamic>> _courses = [];
  int? _selectedExamId;
  int? _selectedCourseId;
  int _selectedYearNo = 1;

  // Tab 1: Ranking
  bool _isLoadingRankings = false;
  List<Map<String, dynamic>> _rankedStudents = [];
  double _classTotalMaxMarks = 0.0;
  Map<String, dynamic> _classStats = {
    'total': 0,
    'passed': 0,
    'failed': 0,
    'mumtaz': 0,
    'jayyid_jiddan': 0,
    'jayyid': 0,
    'maqbool': 0,
    'avg_pct': 0.0,
  };

  // Tab 2: Subject Analysis
  bool _isLoadingSubjects = false;
  List<Map<String, dynamic>> _subjects = [];
  int? _selectedSubjectId;
  Map<String, dynamic> _subjectStats = {
    'total': 0,
    'passed': 0,
    'failed': 0,
    'avg_marks': 0.0,
    'max_score': 0.0,
    'min_score': 0.0,
  };
  List<Map<String, dynamic>> _weakStudents = [];

  // Tab 3: Student Transcript
  bool _isLoadingTranscript = false;
  List<Map<String, dynamic>> _allEnrollments = [];
  int? _selectedStudentId;
  Map<String, dynamic>? _selectedStudentInfo;
  List<Map<String, dynamic>> _studentExamHistory = [];

  // Tab 4: Course Summary
  bool _isLoadingCourseSummary = false;
  List<Map<String, dynamic>> _courseSummaries = [];

  final Map<int, String> _yearNames = {
    1: 'سال اول (درجہ اولیٰ)',
    2: 'سال دوم (درجہ ثانیہ)',
    3: 'سال سوم (درجہ ثالثہ)',
    4: 'سال چہارم (درجہ رابعہ)',
    5: 'سال پنجم (درجہ خامسہ)',
    6: 'سال ششم (درجہ سادسہ)',
    7: 'سال ہفتم (درجہ عالیہ)',
    8: 'سال ہشتم (درجہ عالمیہ / دورۂ حدیث)',
  };

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 4, vsync: this);
    _tabController.addListener(() {
      if (!_tabController.indexIsChanging) {
        _onTabChanged(_tabController.index);
      }
    });
    _loadInitialData();
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  void _onTabChanged(int index) {
    if (index == 0) {
      _loadClassRankings();
    } else if (index == 1) {
      _loadSubjectAnalysis();
    } else if (index == 2) {
      _loadStudentTranscript();
    } else if (index == 3) {
      _loadCourseSummary();
    }
  }

  Future<void> _loadInitialData() async {
    final db = DBHelper();
    final exams = await db.getExams();
    final courses = await db.getCourses(isActive: 1);

    setState(() {
      _exams = exams;
      _courses = courses;

      if (_exams.isNotEmpty && _selectedExamId == null) {
        _selectedExamId = _exams.first['id'] as int;
      }
      if (_courses.isNotEmpty && _selectedCourseId == null) {
        _selectedCourseId = _courses.first['id'] as int;
      }
    });

    await _loadClassRankings();
    await _loadCourseSummary();
  }

  // -------------------------------------------------------------
  // TAB 1: CLASS RANKINGS & POSITIONS
  // -------------------------------------------------------------
  Future<void> _loadClassRankings() async {
    if (_selectedExamId == null || _selectedCourseId == null) return;
    setState(() => _isLoadingRankings = true);

    final db = DBHelper();

    // 1. Get subjects max marks sum
    final subjects = await db.getCourseSubjects(_selectedCourseId!, yearNo: _selectedYearNo);
    double totalMax = 0;
    for (var sub in subjects) {
      totalMax += (sub['max_marks'] as num? ?? 100).toDouble();
    }

    // 2. Get enrollments
    final enrollments = await db.getCourseEnrollments(
      courseId: _selectedCourseId,
      yearNo: _selectedYearNo,
      status: 'active',
    );

    // 3. Get results for exam
    final results = await db.getExamSubjectResults(
      examId: _selectedExamId!,
      courseId: _selectedCourseId,
      yearNo: _selectedYearNo,
    );

    // Group results by student
    Map<int, List<Map<String, dynamic>>> studentResults = {};
    for (var r in results) {
      final sid = r['student_id'] as int;
      studentResults.putIfAbsent(sid, () => []).add(r);
    }

    List<Map<String, dynamic>> ranked = [];
    int totalCount = 0;
    int passCount = 0;
    int failCount = 0;
    int mumtazCount = 0;
    int jayyidJiddanCount = 0;
    int jayyidCount = 0;
    int maqboolCount = 0;
    double totalPctSum = 0;

    for (var en in enrollments) {
      final sid = en['student_id'] as int;
      final marksList = studentResults[sid] ?? [];

      double obtainedSum = 0;
      bool hasFailedSubject = false;
      bool hasAbsent = false;

      for (var m in marksList) {
        final obt = (m['marks_obtained'] as num? ?? 0).toDouble();
        final isAbs = (m['is_absent'] as int? ?? 0) == 1;
        final passMarks = (m['pass_marks'] as num? ?? 40).toDouble();

        if (isAbs) {
          hasAbsent = true;
          hasFailedSubject = true;
        } else {
          obtainedSum += obt;
          if (obt < passMarks) {
            hasFailedSubject = true;
          }
        }
      }

      if (marksList.isNotEmpty && totalMax > 0) {
        totalCount++;
        final pct = (obtainedSum / totalMax) * 100;
        totalPctSum += pct;

        String grade;
        bool isPassed;
        if (!hasFailedSubject && !hasAbsent && pct >= 40) {
          isPassed = true;
          passCount++;
          if (pct >= 80) {
            grade = 'ممتاز';
            mumtazCount++;
          } else if (pct >= 70) {
            grade = 'جید جدا';
            jayyidJiddanCount++;
          } else if (pct >= 60) {
            grade = 'جید';
            jayyidCount++;
          } else {
            grade = 'مقبول';
            maqboolCount++;
          }
        } else {
          isPassed = false;
          grade = 'راسب';
          failCount++;
        }

        ranked.add({
          'student_id': sid,
          'student_name': en['student_name'] ?? 'طالب علم',
          'father_name': en['father_name'] ?? '',
          'roll_number': en['roll_number'] ?? '',
          'total_obtained': obtainedSum,
          'total_max': totalMax,
          'percentage': pct,
          'grade': grade,
          'is_passed': isPassed,
          'subjects_count': marksList.length,
          'has_absent': hasAbsent,
        });
      }
    }

    // Sort descending by total_obtained
    ranked.sort((a, b) {
      int cmp = (b['total_obtained'] as double).compareTo(a['total_obtained'] as double);
      if (cmp != 0) return cmp;
      return (a['roll_number']?.toString() ?? '').compareTo(b['roll_number']?.toString() ?? '');
    });

    setState(() {
      _classTotalMaxMarks = totalMax;
      _rankedStudents = ranked;
      _classStats = {
        'total': totalCount,
        'passed': passCount,
        'failed': failCount,
        'mumtaz': mumtazCount,
        'jayyid_jiddan': jayyidJiddanCount,
        'jayyid': jayyidCount,
        'maqbool': maqboolCount,
        'avg_pct': totalCount > 0 ? (totalPctSum / totalCount) : 0.0,
      };
      _isLoadingRankings = false;
    });
  }

  // -------------------------------------------------------------
  // TAB 2: SUBJECT ANALYSIS
  // -------------------------------------------------------------
  Future<void> _loadSubjectAnalysis() async {
    if (_selectedExamId == null || _selectedCourseId == null) return;
    setState(() => _isLoadingSubjects = true);

    final db = DBHelper();
    final subs = await db.getCourseSubjects(_selectedCourseId!, yearNo: _selectedYearNo);

    int? activeSubId = _selectedSubjectId;
    if (activeSubId == null || !subs.any((s) => s['id'] == activeSubId)) {
      activeSubId = subs.isNotEmpty ? (subs.first['id'] as int) : null;
    }

    List<Map<String, dynamic>> weakList = [];
    Map<String, dynamic> stats = {
      'total': 0,
      'passed': 0,
      'failed': 0,
      'avg_marks': 0.0,
      'max_score': 0.0,
      'min_score': 0.0,
    };

    if (activeSubId != null) {
      final results = await db.getExamSubjectResults(
        examId: _selectedExamId!,
        courseId: _selectedCourseId,
        yearNo: _selectedYearNo,
      );

      final filtered = results.where((r) => r['subject_id'] == activeSubId).toList();
      final subRecord = subs.firstWhere((s) => s['id'] == activeSubId, orElse: () => {});
      final passMarks = (subRecord['pass_marks'] as num? ?? 40).toDouble();

      int tot = 0;
      int pas = 0;
      int fai = 0;
      double sumMarks = 0;
      double maxM = 0;
      double minM = 999999;

      for (var r in filtered) {
        tot++;
        final obt = (r['marks_obtained'] as num? ?? 0).toDouble();
        final isAbs = (r['is_absent'] as int? ?? 0) == 1;

        if (isAbs || obt < passMarks) {
          fai++;
          weakList.add({
            'student_id': r['student_id'],
            'student_name': r['student_name'] ?? 'طالب علم',
            'roll_number': r['roll_number'] ?? '',
            'marks_obtained': obt,
            'max_marks': r['max_marks'] ?? 100,
            'is_absent': isAbs,
            'remarks': isAbs ? 'غیر حاضر' : 'پاس مارکس سے کم ($obt)',
          });
        } else {
          pas++;
        }

        if (!isAbs) {
          sumMarks += obt;
          if (obt > maxM) maxM = obt;
          if (obt < minM) minM = obt;
        }
      }

      stats = {
        'total': tot,
        'passed': pas,
        'failed': fai,
        'avg_marks': tot > 0 ? (sumMarks / (tot - (weakList.where((w) => w['is_absent'] == true).length))) : 0.0,
        'max_score': maxM,
        'min_score': (minM == 999999) ? 0.0 : minM,
      };
    }

    setState(() {
      _subjects = subs;
      _selectedSubjectId = activeSubId;
      _subjectStats = stats;
      _weakStudents = weakList;
      _isLoadingSubjects = false;
    });
  }

  // -------------------------------------------------------------
  // TAB 3: STUDENT TRANSCRIPT
  // -------------------------------------------------------------
  Future<void> _loadStudentTranscript() async {
    setState(() => _isLoadingTranscript = true);
    final db = DBHelper();

    final enrollments = await db.getCourseEnrollments(
      courseId: _selectedCourseId,
      yearNo: _selectedYearNo,
      status: 'active',
    );

    int? activeSid = _selectedStudentId;
    if (activeSid == null || !enrollments.any((e) => e['student_id'] == activeSid)) {
      activeSid = enrollments.isNotEmpty ? (enrollments.first['student_id'] as int) : null;
    }

    Map<String, dynamic>? stdInfo;
    List<Map<String, dynamic>> examCards = [];

    if (activeSid != null) {
      stdInfo = enrollments.firstWhere((e) => e['student_id'] == activeSid, orElse: () => {});

      // Fetch results across all exams for this student
      final allResults = await db.getExamSubjectResults(studentId: activeSid);

      // Group by exam_id
      Map<int, List<Map<String, dynamic>>> byExam = {};
      for (var r in allResults) {
        final eid = r['exam_id'] as int;
        byExam.putIfAbsent(eid, () => []).add(r);
      }

      for (var exam in _exams) {
        final eid = exam['id'] as int;
        if (byExam.containsKey(eid)) {
          final resList = byExam[eid]!;
          double obt = 0;
          double max = 0;
          bool fail = false;

          for (var r in resList) {
            final isAbs = (r['is_absent'] as int? ?? 0) == 1;
            final mObt = (r['marks_obtained'] as num? ?? 0).toDouble();
            final mMax = (r['max_marks'] as num? ?? 100).toDouble();
            final passM = (r['pass_marks'] as num? ?? 40).toDouble();

            obt += isAbs ? 0 : mObt;
            max += mMax;
            if (isAbs || mObt < passM) {
              fail = true;
            }
          }

          final pct = max > 0 ? (obt / max) * 100 : 0.0;
          String gr;
          if (!fail && pct >= 80) gr = 'ممتاز';
          else if (!fail && pct >= 70) gr = 'جید جدا';
          else if (!fail && pct >= 60) gr = 'جید';
          else if (!fail && pct >= 40) gr = 'مقبول';
          else gr = 'راسب';

          examCards.add({
            'exam_id': eid,
            'exam_name': exam['exam_name'] ?? 'امتحان',
            'exam_date': exam['exam_date'] ?? '',
            'total_obtained': obt,
            'total_max': max,
            'percentage': pct,
            'grade': gr,
            'is_passed': !fail && pct >= 40,
            'subjects': resList,
          });
        }
      }
    }

    setState(() {
      _allEnrollments = enrollments;
      _selectedStudentId = activeSid;
      _selectedStudentInfo = stdInfo;
      _studentExamHistory = examCards;
      _isLoadingTranscript = false;
    });
  }

  // -------------------------------------------------------------
  // TAB 4: COURSE SUMMARY
  // -------------------------------------------------------------
  Future<void> _loadCourseSummary() async {
    setState(() => _isLoadingCourseSummary = true);
    final db = DBHelper();

    final allCourses = await db.getCourses();
    List<Map<String, dynamic>> summaries = [];

    for (var c in allCourses) {
      final cid = c['id'] as int;
      final totalYears = c['total_years'] as int? ?? 8;

      final enList = await db.getCourseEnrollments(courseId: cid, status: 'active');
      Map<int, int> yearCounts = {};
      for (int y = 1; y <= totalYears; y++) {
        yearCounts[y] = 0;
      }
      for (var en in enList) {
        final yr = en['current_year'] as int? ?? 1;
        yearCounts[yr] = (yearCounts[yr] ?? 0) + 1;
      }

      summaries.add({
        'course_id': cid,
        'course_name': c['name'] ?? '',
        'course_type': c['course_type'] ?? 'Darse_Nizami',
        'gender': c['gender'] ?? 'بنین',
        'total_years': totalYears,
        'total_enrolled': enList.length,
        'year_distribution': yearCounts,
      });
    }

    setState(() {
      _courseSummaries = summaries;
      _isLoadingCourseSummary = false;
    });
  }

  Color _getGradeColor(String grade) {
    switch (grade) {
      case 'ممتاز':
        return const Color(0xFF2E7D32);
      case 'جید جدا':
        return const Color(0xFF1976D2);
      case 'جید':
        return const Color(0xFF0097A7);
      case 'مقبول':
        return const Color(0xFFF57C00);
      case 'راسب':
      default:
        return const Color(0xFFD32F2F);
    }
  }

  @override
  Widget build(BuildContext context) {
    final isUrdu = isUrduGlobal.value;

    return Directionality(
      textDirection: isUrdu ? TextDirection.rtl : TextDirection.ltr,
      child: Scaffold(
        backgroundColor: bgColor,
        drawer: const AppDrawer(),
        appBar: AppBar(
          backgroundColor: primaryGreen,
          elevation: 0,
          title: Text(
            isUrdu ? 'تعلیمی کارکردگی و امتحانی رپورٹس' : 'Academic Performance & Reports',
            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 18, color: Colors.white),
          ),
          actions: [
            IconButton(
              tooltip: isUrdu ? 'تازہ کریں' : 'Refresh',
              icon: const Icon(Icons.refresh, color: Colors.white),
              onPressed: () => _onTabChanged(_tabController.index),
            ),
          ],
          bottom: TabBar(
            controller: _tabController,
            isScrollable: true,
            indicatorColor: accentGold,
            indicatorWeight: 3,
            labelColor: Colors.white,
            unselectedLabelColor: Colors.white70,
            labelStyle: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
            tabs: const [
              Tab(icon: Icon(Icons.emoji_events_outlined, size: 20), text: 'پوزیشنز و رینکنگ'),
              Tab(icon: Icon(Icons.analytics_outlined, size: 20), text: 'مضمون وار تجزیہ'),
              Tab(icon: Icon(Icons.person_search_outlined, size: 20), text: 'طالب علم تاریخچہ'),
              Tab(icon: Icon(Icons.account_balance_outlined, size: 20), text: 'کورسز خلاصہ'),
            ],
          ),
        ),
        body: Column(
          children: [
            // Filter Bar (for Tabs 1, 2, 3)
            AnimatedBuilder(
              animation: _tabController,
              builder: (ctx, _) {
                if (_tabController.index == 3) {
                  return const SizedBox.shrink(); // No exam/year filter for course summary tab
                }
                return _buildUniversalFilterBar();
              },
            ),
            // Tab Views
            Expanded(
              child: TabBarView(
                controller: _tabController,
                children: [
                  _buildRankingTab(),
                  _buildSubjectAnalysisTab(),
                  _buildTranscriptTab(),
                  _buildCourseSummaryTab(),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  // -------------------------------------------------------------
  // UNIVERSAL FILTER BAR
  // -------------------------------------------------------------
  Widget _buildUniversalFilterBar() {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      decoration: BoxDecoration(
        color: Colors.white,
        border: Border(bottom: BorderSide(color: Colors.grey.shade300)),
        boxShadow: [
          BoxShadow(color: Colors.black.withOpacity(0.03), blurRadius: 4, offset: const Offset(0, 2)),
        ],
      ),
      child: Column(
        children: [
          Row(
            children: [
              // Exam Dropdown
              Expanded(
                flex: 3,
                child: DropdownButtonFormField<int>(
                  value: _selectedExamId,
                  isExpanded: true,
                  decoration: InputDecoration(
                    labelText: 'امتحان منتخب کریں',
                    labelStyle: TextStyle(fontSize: 12, color: primaryGreen),
                    contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                    border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
                  ),
                  items: _exams.map((e) {
                    return DropdownMenuItem<int>(
                      value: e['id'] as int,
                      child: Text(
                        e['exam_name'] ?? 'امتحان',
                        style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold),
                        overflow: TextOverflow.ellipsis,
                      ),
                    );
                  }).toList(),
                  onChanged: (val) {
                    if (val != null) {
                      setState(() => _selectedExamId = val);
                      _onTabChanged(_tabController.index);
                    }
                  },
                ),
              ),
              const SizedBox(width: 8),
              // Course Dropdown
              Expanded(
                flex: 4,
                child: DropdownButtonFormField<int>(
                  value: _selectedCourseId,
                  isExpanded: true,
                  decoration: InputDecoration(
                    labelText: 'کورس منتخب کریں',
                    labelStyle: TextStyle(fontSize: 12, color: primaryGreen),
                    contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                    border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
                  ),
                  items: _courses.map((c) {
                    return DropdownMenuItem<int>(
                      value: c['id'] as int,
                      child: Text(
                        '${c['name']} (${c['gender']})',
                        style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold),
                        overflow: TextOverflow.ellipsis,
                      ),
                    );
                  }).toList(),
                  onChanged: (val) {
                    if (val != null) {
                      setState(() => _selectedCourseId = val);
                      _onTabChanged(_tabController.index);
                    }
                  },
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          // Year Chips
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(
              children: List.generate(8, (index) {
                final year = index + 1;
                final isSelected = _selectedYearNo == year;
                return Padding(
                  padding: const EdgeInsets.only(left: 6),
                  child: FilterChip(
                    label: Text(
                      _yearNames[year] ?? 'سال $year',
                      style: TextStyle(
                        fontSize: 11,
                        fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
                        color: isSelected ? Colors.white : Colors.black87,
                      ),
                    ),
                    selected: isSelected,
                    selectedColor: primaryGreen,
                    backgroundColor: Colors.grey.shade100,
                    checkmarkColor: Colors.white,
                    onSelected: (selected) {
                      if (selected) {
                        setState(() => _selectedYearNo = year);
                        _onTabChanged(_tabController.index);
                      }
                    },
                  ),
                );
              }),
            ),
          ),
        ],
      ),
    );
  }

  // -------------------------------------------------------------
  // 1. RANKING & POSITIONS TAB
  // -------------------------------------------------------------
  Widget _buildRankingTab() {
    if (_isLoadingRankings) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_rankedStudents.isEmpty) {
      return Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(Icons.military_tech_outlined, size: 70, color: Colors.grey.shade400),
            const SizedBox(height: 12),
            const Text(
              'اس امتحان اور سال کے لیے رزلٹ ڈیٹا موجود نہیں ہے',
              style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold, color: Colors.grey),
            ),
            const SizedBox(height: 6),
            const Text(
              'پہلے نتائج کا اندراج فرمائیں',
              style: TextStyle(fontSize: 13, color: Colors.grey),
            ),
          ],
        ),
      );
    }

    return ListView(
      padding: const EdgeInsets.all(12),
      children: [
        // Summary KPI Banner
        _buildRankingKPICard(),
        const SizedBox(height: 12),

        // Positions Header
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text(
              'کلاس پوزیشنز و رینکنگ لسٹ (${_rankedStudents.length} طلبہ)',
              style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold, color: primaryGreen),
            ),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
              decoration: BoxDecoration(
                color: Colors.amber.shade100,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: Colors.amber.shade300),
              ),
              child: Text(
                'کل نمبر: ${_classTotalMaxMarks.toStringAsFixed(0)}',
                style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Colors.amber.shade900),
              ),
            ),
          ],
        ),
        const SizedBox(height: 8),

        // Ranked Student Cards
        ...List.generate(_rankedStudents.length, (index) {
          final student = _rankedStudents[index];
          final pos = index + 1;
          return _buildPositionCard(student, pos);
        }),
      ],
    );
  }

  Widget _buildRankingKPICard() {
    final s = _classStats;
    final total = s['total'] as int;
    final passed = s['passed'] as int;
    final failed = s['failed'] as int;
    final avgPct = (s['avg_pct'] as num).toDouble();

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: [primaryGreen, secondaryGreen],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(16),
        boxShadow: [
          BoxShadow(color: primaryGreen.withOpacity(0.3), blurRadius: 8, offset: const Offset(0, 4)),
        ],
      ),
      child: Column(
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceAround,
            children: [
              _buildKPISubItem('کل طلبہ', total.toString(), Icons.people_outline, Colors.white),
              _buildKPISubItem('کامیاب', passed.toString(), Icons.check_circle_outline, Colors.greenAccent),
              _buildKPISubItem('ناکام / راسب', failed.toString(), Icons.cancel_outlined, Colors.redAccent.shade100),
              _buildKPISubItem('اوسط فیصد', '${avgPct.toStringAsFixed(1)}%', Icons.analytics_outlined, Colors.amberAccent),
            ],
          ),
          const Divider(color: Colors.white24, height: 20),
          // Grade counts pills
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceAround,
            children: [
              _buildGradePill('ممتاز', s['mumtaz'], const Color(0xFF2E7D32)),
              _buildGradePill('جید جدا', s['jayyid_jiddan'], const Color(0xFF1976D2)),
              _buildGradePill('جید', s['jayyid'], const Color(0xFF0097A7)),
              _buildGradePill('مقبول', s['maqbool'], const Color(0xFFF57C00)),
              _buildGradePill('راسب', failed, const Color(0xFFD32F2F)),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildKPISubItem(String label, String value, IconData icon, Color color) {
    return Column(
      children: [
        Icon(icon, size: 20, color: color),
        const SizedBox(height: 4),
        Text(value, style: TextStyle(color: color, fontSize: 16, fontWeight: FontWeight.bold)),
        Text(label, style: const TextStyle(color: Colors.white70, fontSize: 11)),
      ],
    );
  }

  Widget _buildGradePill(String title, int count, Color color) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: color.withOpacity(0.9),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text('$title: ', style: const TextStyle(color: Colors.white70, fontSize: 10)),
          Text('$count', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 11)),
        ],
      ),
    );
  }

  Widget _buildPositionCard(Map<String, dynamic> student, int position) {
    final grade = student['grade'] ?? '';
    final gradeColor = _getGradeColor(grade);
    final obt = (student['total_obtained'] as num).toDouble();
    final max = (student['total_max'] as num).toDouble();
    final pct = (student['percentage'] as num).toDouble();

    Widget posBadge;
    if (position == 1) {
      posBadge = _buildTrophyBadge('🥇 1st', Colors.amber.shade700, Colors.amber.shade50);
    } else if (position == 2) {
      posBadge = _buildTrophyBadge('🥈 2nd', Colors.blueGrey.shade700, Colors.blueGrey.shade50);
    } else if (position == 3) {
      posBadge = _buildTrophyBadge('🥉 3rd', Colors.brown.shade600, Colors.brown.shade50);
    } else {
      posBadge = Container(
        width: 38,
        height: 38,
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: Colors.grey.shade200,
          shape: BoxShape.circle,
        ),
        child: Text(
          '$position',
          style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: Colors.grey.shade800),
        ),
      );
    }

    return Card(
      elevation: 1.5,
      margin: const EdgeInsets.only(bottom: 8),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Row(
          children: [
            posBadge,
            const SizedBox(width: 12),
            // Student details
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    student['student_name'] ?? '',
                    style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    'ولد: ${student['father_name']} | رول نمبر: ${student['roll_number']}',
                    style: TextStyle(fontSize: 12, color: Colors.grey.shade700),
                  ),
                  const SizedBox(height: 4),
                  Row(
                    children: [
                      Text(
                        'نمبر: ${obt.toStringAsFixed(1)} / ${max.toStringAsFixed(0)}',
                        style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold),
                      ),
                      const SizedBox(width: 8),
                      Text(
                        '(${pct.toStringAsFixed(1)}%)',
                        style: TextStyle(fontSize: 12, color: Colors.grey.shade800),
                      ),
                    ],
                  ),
                ],
              ),
            ),
            // Grade badge & Action button
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
                  decoration: BoxDecoration(
                    color: gradeColor.withOpacity(0.12),
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(color: gradeColor),
                  ),
                  child: Text(
                    grade,
                    style: TextStyle(color: gradeColor, fontWeight: FontWeight.bold, fontSize: 11),
                  ),
                ),
                const SizedBox(height: 6),
                InkWell(
                  onTap: () {
                    Navigator.push(
                      context,
                      MaterialPageRoute(
                        builder: (_) => AcademicResultPreviewPage(
                          examId: _selectedExamId!,
                          courseId: _selectedCourseId!,
                          yearNo: _selectedYearNo,
                          student: student,
                        ),
                      ),
                    );
                  },
                  borderRadius: BorderRadius.circular(6),
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                    decoration: BoxDecoration(
                      color: primaryGreen.withOpacity(0.08),
                      borderRadius: BorderRadius.circular(6),
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(Icons.print_outlined, size: 13, color: primaryGreen),
                        const SizedBox(width: 3),
                        Text(
                          'کارڈ',
                          style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: primaryGreen),
                        ),
                      ],
                    ),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildTrophyBadge(String text, Color borderColor, Color bgColor) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
      decoration: BoxDecoration(
        color: bgColor,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: borderColor, width: 1.5),
      ),
      child: Text(
        text,
        style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: borderColor),
      ),
    );
  }

  // -------------------------------------------------------------
  // 2. SUBJECT ANALYSIS & WEAK STUDENTS TAB
  // -------------------------------------------------------------
  Widget _buildSubjectAnalysisTab() {
    if (_isLoadingSubjects) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_subjects.isEmpty) {
      return const Center(
        child: Text('اس سال کے لیے کوئی مضمون درج نہیں ہے', style: TextStyle(color: Colors.grey)),
      );
    }

    final stat = _subjectStats;

    return ListView(
      padding: const EdgeInsets.all(12),
      children: [
        // Subject Selector Chips
        Text(
          'مضمون کا انتخاب کریں:',
          style: TextStyle(fontSize: 13, fontWeight: FontWeight.bold, color: primaryGreen),
        ),
        const SizedBox(height: 6),
        SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          child: Row(
            children: _subjects.map((sub) {
              final isSel = _selectedSubjectId == sub['id'];
              return Padding(
                padding: const EdgeInsets.only(left: 6),
                child: ChoiceChip(
                  label: Text(
                    sub['subject_name'] ?? 'مضمون',
                    style: TextStyle(
                      fontSize: 12,
                      fontWeight: isSel ? FontWeight.bold : FontWeight.normal,
                      color: isSel ? Colors.white : Colors.black87,
                    ),
                  ),
                  selected: isSel,
                  selectedColor: secondaryGreen,
                  backgroundColor: Colors.white,
                  onSelected: (val) {
                    if (val) {
                      setState(() => _selectedSubjectId = sub['id'] as int);
                      _loadSubjectAnalysis();
                    }
                  },
                ),
              );
            }).toList(),
          ),
        ),
        const SizedBox(height: 12),

        // Subject Analytics Card
        Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(14),
            border: Border.all(color: Colors.grey.shade200),
            boxShadow: [
              BoxShadow(color: Colors.black.withOpacity(0.04), blurRadius: 6, offset: const Offset(0, 2)),
            ],
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'مضمون کی تفصیلی کارکردگی خلاصہ',
                style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: primaryGreen),
              ),
              const Divider(height: 20),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceAround,
                children: [
                  _buildAnalysisMetric('کل شریک طلبہ', '${stat['total']}', Colors.blue.shade700),
                  _buildAnalysisMetric('کامیاب طلبہ', '${stat['passed']}', Colors.green.shade700),
                  _buildAnalysisMetric('ناکام / کمزور', '${stat['failed']}', Colors.red.shade700),
                ],
              ),
              const SizedBox(height: 12),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceAround,
                children: [
                  _buildAnalysisMetric('اوسط حاصل کردہ نمبر', (stat['avg_marks'] as double).toStringAsFixed(1), Colors.teal.shade700),
                  _buildAnalysisMetric('بلند ترین نمبر', (stat['max_score'] as double).toStringAsFixed(1), Colors.amber.shade800),
                  _buildAnalysisMetric('کم ترین نمبر', (stat['min_score'] as double).toStringAsFixed(1), Colors.purple.shade700),
                ],
              ),
            ],
          ),
        ),
        const SizedBox(height: 16),

        // Weak / Failed Students Section
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text(
              '⚠️ کمزور اور غیر حاضر طلبہ کی فہرست (${_weakStudents.length})',
              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: Colors.red),
            ),
          ],
        ),
        const SizedBox(height: 8),

        if (_weakStudents.isEmpty)
          Container(
            padding: const EdgeInsets.all(20),
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: Colors.green.shade50,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: Colors.green.shade200),
            ),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Icon(Icons.check_circle, color: Colors.green.shade700, size: 22),
                const SizedBox(width: 8),
                Text(
                  'ماشاءاللہ! اس مضمون میں کوئی طالب علم ناکام یا غیر حاضر نہیں ہے۔',
                  style: TextStyle(fontWeight: FontWeight.bold, color: Colors.green.shade800, fontSize: 13),
                ),
              ],
            ),
          )
        else
          ..._weakStudents.map((ws) {
            final isAbs = ws['is_absent'] == true;
            return Card(
              elevation: 1,
              margin: const EdgeInsets.only(bottom: 6),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
              child: ListTile(
                leading: CircleAvatar(
                  backgroundColor: isAbs ? Colors.grey.shade300 : Colors.red.shade100,
                  child: Icon(
                    isAbs ? Icons.person_off : Icons.warning_amber_rounded,
                    color: isAbs ? Colors.grey.shade700 : Colors.red.shade700,
                    size: 20,
                  ),
                ),
                title: Text(
                  ws['student_name'] ?? '',
                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                ),
                subtitle: Text('رول نمبر: ${ws['roll_number']} | کیفیت: ${ws['remarks']}'),
                trailing: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                  decoration: BoxDecoration(
                    color: isAbs ? Colors.grey.shade200 : Colors.red.shade50,
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(color: isAbs ? Colors.grey : Colors.red),
                  ),
                  child: Text(
                    isAbs ? 'غیر حاضر' : 'حاصل: ${ws['marks_obtained']}',
                    style: TextStyle(
                      fontWeight: FontWeight.bold,
                      fontSize: 12,
                      color: isAbs ? Colors.grey.shade800 : Colors.red.shade800,
                    ),
                  ),
                ),
              ),
            );
          }),
      ],
    );
  }

  Widget _buildAnalysisMetric(String label, String value, Color color) {
    return Column(
      children: [
        Text(value, style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16, color: color)),
        const SizedBox(height: 2),
        Text(label, style: TextStyle(fontSize: 11, color: Colors.grey.shade600)),
      ],
    );
  }

  // -------------------------------------------------------------
  // 3. STUDENT TRANSCRIPT TAB
  // -------------------------------------------------------------
  Widget _buildTranscriptTab() {
    if (_isLoadingTranscript) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_allEnrollments.isEmpty) {
      return const Center(
        child: Text('اس کلاس میں کوئی طالب علم داخل نہیں ہے', style: TextStyle(color: Colors.grey)),
      );
    }

    return ListView(
      padding: const EdgeInsets.all(12),
      children: [
        // Student Selector Dropdown
        DropdownButtonFormField<int>(
          value: _selectedStudentId,
          isExpanded: true,
          decoration: InputDecoration(
            labelText: 'طالب علم منتخب فرمائیں',
            labelStyle: TextStyle(fontSize: 13, color: primaryGreen),
            filled: true,
            fillColor: Colors.white,
            contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
            border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
          ),
          items: _allEnrollments.map((en) {
            return DropdownMenuItem<int>(
              value: en['student_id'] as int,
              child: Text(
                '${en['student_name']} بن ${en['father_name']} (رول نمبر: ${en['roll_number']})',
                style: const TextStyle(fontSize: 13, fontWeight: FontWeight.bold),
                overflow: TextOverflow.ellipsis,
              ),
            );
          }).toList(),
          onChanged: (val) {
            if (val != null) {
              setState(() => _selectedStudentId = val);
              _loadStudentTranscript();
            }
          },
        ),
        const SizedBox(height: 12),

        // Student Profile Header Card
        if (_selectedStudentInfo != null)
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: Colors.grey.shade200),
            ),
            child: Row(
              children: [
                CircleAvatar(
                  radius: 26,
                  backgroundColor: primaryGreen.withOpacity(0.1),
                  child: Icon(Icons.person, size: 30, color: primaryGreen),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        _selectedStudentInfo!['student_name'] ?? '',
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                      ),
                      Text(
                        'ولدیت: ${_selectedStudentInfo!['father_name']} | رول نمبر: ${_selectedStudentInfo!['roll_number']}',
                        style: TextStyle(fontSize: 12, color: Colors.grey.shade700),
                      ),
                      Text(
                        'کورس: ${_selectedStudentInfo!['course_name'] ?? 'درس نظامی'} (${_yearNames[_selectedStudentInfo!['current_year'] ?? 1]})',
                        style: TextStyle(fontSize: 12, color: primaryGreen, fontWeight: FontWeight.bold),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        const SizedBox(height: 16),

        Text(
          'طالب علم کے امتحانی نتائج کی تاریخ (Academic History):',
          style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: primaryGreen),
        ),
        const SizedBox(height: 8),

        if (_studentExamHistory.isEmpty)
          Container(
            padding: const EdgeInsets.all(24),
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(12),
            ),
            child: const Text(
              'اس طالب علم کا کسی امتحان میں ابھی تک کوئی نتیجہ درج نہیں ہے',
              style: TextStyle(color: Colors.grey),
            ),
          )
        else
          ..._studentExamHistory.map((ex) {
            final gr = ex['grade'] ?? '';
            final grColor = _getGradeColor(gr);
            final subs = (ex['subjects'] as List? ?? []);

            return Card(
              margin: const EdgeInsets.only(bottom: 12),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
              child: ExpansionTile(
                initiallyExpanded: true,
                title: Text(
                  ex['exam_name'] ?? 'امتحان',
                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                ),
                subtitle: Text(
                  'حاصل نمبر: ${(ex['total_obtained'] as double).toStringAsFixed(1)} / ${(ex['total_max'] as double).toStringAsFixed(0)} (${(ex['percentage'] as double).toStringAsFixed(1)}%)',
                  style: TextStyle(fontSize: 12, color: Colors.grey.shade700),
                ),
                trailing: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                  decoration: BoxDecoration(
                    color: grColor.withOpacity(0.12),
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(color: grColor),
                  ),
                  child: Text(
                    gr,
                    style: TextStyle(fontWeight: FontWeight.bold, color: grColor, fontSize: 12),
                  ),
                ),
                children: [
                  const Divider(height: 1),
                  ...subs.map((s) {
                    final isAbs = (s['is_absent'] as int? ?? 0) == 1;
                    final obt = (s['marks_obtained'] as num? ?? 0).toDouble();
                    final max = (s['max_marks'] as num? ?? 100).toDouble();
                    final pass = (s['pass_marks'] as num? ?? 40).toDouble();
                    final isPass = !isAbs && obt >= pass;

                    return Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Text(
                            s['subject_name'] ?? 'مضمون',
                            style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600),
                          ),
                          Row(
                            children: [
                              Text(
                                isAbs ? 'غیر حاضر' : '${obt.toStringAsFixed(1)} / ${max.toStringAsFixed(0)}',
                                style: TextStyle(
                                  fontSize: 13,
                                  fontWeight: FontWeight.bold,
                                  color: isPass ? Colors.black87 : Colors.red,
                                ),
                              ),
                              const SizedBox(width: 8),
                              Icon(
                                isPass ? Icons.check_circle : Icons.cancel,
                                size: 16,
                                color: isPass ? Colors.green : Colors.red,
                              ),
                            ],
                          ),
                        ],
                      ),
                    );
                  }),
                  // View Result Card Button
                  Padding(
                    padding: const EdgeInsets.all(12),
                    child: ElevatedButton.icon(
                      style: ElevatedButton.styleFrom(
                        backgroundColor: primaryGreen,
                        foregroundColor: Colors.white,
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                      ),
                      icon: const Icon(Icons.print, size: 18),
                      label: const Text('اس امتحان کا مکمل رزلٹ کارڈ دیکھیں و پرنٹ کریں'),
                      onPressed: () {
                        Navigator.push(
                          context,
                          MaterialPageRoute(
                            builder: (_) => AcademicResultPreviewPage(
                              examId: ex['exam_id'] as int,
                              courseId: _selectedCourseId!,
                              yearNo: _selectedYearNo,
                              student: _selectedStudentInfo!,
                            ),
                          ),
                        );
                      },
                    ),
                  ),
                ],
              ),
            );
          }),
      ],
    );
  }

  // -------------------------------------------------------------
  // 4. COURSE SUMMARY TAB
  // -------------------------------------------------------------
  Widget _buildCourseSummaryTab() {
    if (_isLoadingCourseSummary) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_courseSummaries.isEmpty) {
      return const Center(
        child: Text('کوئی کورس رجسٹر نہیں ہے', style: TextStyle(color: Colors.grey)),
      );
    }

    return ListView(
      padding: const EdgeInsets.all(12),
      children: [
        Text(
          'ادارے کے تعلیمی کورسز و طلبہ تعداد کا مجموعی جائزہ:',
          style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: primaryGreen),
        ),
        const SizedBox(height: 10),
        ..._courseSummaries.map((c) {
          final dist = c['year_distribution'] as Map<int, int>? ?? {};
          final totalEnrolled = c['total_enrolled'] as int? ?? 0;
          final totalYears = c['total_years'] as int? ?? 8;

          return Card(
            elevation: 2,
            margin: const EdgeInsets.only(bottom: 12),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text(
                        c['course_name'] ?? '',
                        style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16, color: primaryGreen),
                      ),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                        decoration: BoxDecoration(
                          color: primaryGreen.withOpacity(0.1),
                          borderRadius: BorderRadius.circular(10),
                        ),
                        child: Text(
                          'کل طلبہ: $totalEnrolled',
                          style: TextStyle(fontWeight: FontWeight.bold, color: primaryGreen, fontSize: 13),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 4),
                  Text(
                    'قسم: ${c['course_type']} | صنف: ${c['gender']} | دورانیہ: $totalYears سال',
                    style: TextStyle(fontSize: 12, color: Colors.grey.shade700),
                  ),
                  const Divider(height: 20),
                  Text(
                    'سال وار طلبہ تقسیم:',
                    style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Colors.grey.shade800),
                  ),
                  const SizedBox(height: 8),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: List.generate(totalYears, (idx) {
                      final yr = idx + 1;
                      final cnt = dist[yr] ?? 0;
                      return Container(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                        decoration: BoxDecoration(
                          color: cnt > 0 ? Colors.teal.shade50 : Colors.grey.shade100,
                          borderRadius: BorderRadius.circular(8),
                          border: Border.all(
                            color: cnt > 0 ? Colors.teal.shade300 : Colors.grey.shade300,
                          ),
                        ),
                        child: Column(
                          children: [
                            Text(
                              _yearNames[yr]?.split(' ').take(2).join(' ') ?? 'سال $yr',
                              style: TextStyle(fontSize: 11, color: Colors.grey.shade800),
                            ),
                            Text(
                              '$cnt طالب علم',
                              style: TextStyle(
                                fontSize: 12,
                                fontWeight: FontWeight.bold,
                                color: cnt > 0 ? Colors.teal.shade800 : Colors.grey,
                              ),
                            ),
                          ],
                        ),
                      );
                    }),
                  ),
                ],
              ),
            ),
          );
        }),
      ],
    );
  }
}

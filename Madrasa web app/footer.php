    </div>
    <footer class="text-center py-3 bg-light border-top mt-5">
        <div class="container small">
            <?php 
            $footer_org_name = !empty($header_current_name) ? $header_current_name : (!empty($_SESSION['tenant_name']) ? $_SESSION['tenant_name'] : 'نظامِ مدارس و مساجد');
            ?>
            <span class="text-muted">© <?php echo date('Y'); ?> <?php echo htmlspecialchars($footer_org_name); ?>. <?php echo (isset($lang) && $lang === 'en') ? 'All rights reserved.' : 'جملہ حقوق محفوظ ہیں۔'; ?></span>
            <span class="text-muted ms-2 badge bg-secondary bg-opacity-10 text-secondary border font-monospace">v5.7.134 (SaaS Edition)</span>
            <div class="mt-2">
                <a href="privacy.php" class="text-decoration-none text-secondary mx-2"><?php echo (isset($lang) && $lang === 'en') ? 'Privacy Policy' : 'پرائیویسی پالیسی'; ?></a> |
                <a href="terms.php" class="text-decoration-none text-secondary mx-2"><?php echo (isset($lang) && $lang === 'en') ? 'Terms & Conditions' : 'شرائط و ضوابط'; ?></a> |
                <a href="delete_account.php" class="text-decoration-none text-danger mx-2"><?php echo (isset($lang) && $lang === 'en') ? 'Delete Account' : 'اکاؤنٹ ختم کریں'; ?></a>
            </div>
        </div>
    </footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
    // مینیو ٹوگل و آؤٹ سائیڈ ٹچ سے بند ہونا (Sidebar toggle and outside tap to close)
    var el = document.getElementById("wrapper");
    var toggleButton = document.getElementById("menu-toggle");
    var overlay = document.getElementById("overlay");

    function closeSidebar() {
        if (document.body.classList.contains("active-nav")) {
            document.body.classList.remove("active-nav");
            if (el) el.classList.remove("toggled");
        }
    }

    if (toggleButton && el) {
        toggleButton.onclick = function (e) {
            e.stopPropagation();
            el.classList.toggle("toggled");
            document.body.classList.toggle("active-nav");
        };
    }

    if (overlay) {
        overlay.addEventListener("click", closeSidebar);
        overlay.addEventListener("touchstart", closeSidebar, { passive: true });
    }

    // سائیڈ بار کے باہر کہیں بھی ٹچ یا کلک کرنے پر اسے خودکار بند کریں
    document.addEventListener("click", function(e) {
        if (!document.body.classList.contains("active-nav")) return;
        var sidebar = document.getElementById("sidebar-wrapper");
        if (sidebar && !sidebar.contains(e.target) && toggleButton && !toggleButton.contains(e.target)) {
            closeSidebar();
        }
    });

    document.addEventListener("touchstart", function(e) {
        if (!document.body.classList.contains("active-nav")) return;
        var sidebar = document.getElementById("sidebar-wrapper");
        if (sidebar && !sidebar.contains(e.target) && toggleButton && !toggleButton.contains(e.target)) {
            closeSidebar();
        }
    }, { passive: true });

    // فعال صفحہ کے ڈراپ ڈاؤن کو خودکار کھلا رکھنا (Keep active dropdown open on refresh/load)
    ['maktabSubmenu', 'masjidFinanceSubmenu', 'erpSubmenu'].forEach(function(menuId) {
        var menu = document.getElementById(menuId);
        if (!menu) return;
        if (menu.querySelector('.active-link')) {
            menu.classList.add('show');
            var btn = document.querySelector('[href="#' + menuId + '"]');
            if (btn) {
                btn.classList.remove('collapsed');
                btn.setAttribute('aria-expanded', 'true');
            }
        }
    });

    // سلیکٹ 2 (سرچ ایبل ڈراپ ڈاؤن) اور خودکار ٹیبل موبائل رسپانسو ریپر
    $(document).ready(function() {
        $('.select2').select2({ width: '100%' });

        // تمام ٹیبلز کو موبائل پر کٹنے سے بچانے کے لیے خودکار رسپانسو ریپر
        $('table').each(function() {
            if (!$(this).closest('.table-responsive').length && !$(this).hasClass('no-responsive')) {
                $(this).wrap('<div class="table-responsive auto-table-responsive"></div>');
            }
        });
    });

    // ==========================================
    // 🎤 گلوبل وائس ٹائپنگ سسٹم (Global Voice Logic)
    // ==========================================
    function startVoice(inputId) {
        // 1. براؤزر چیک
        if (!('webkitSpeechRecognition' in window)) {
            alert("معذرت! آپ کا براؤزر وائس ٹائپنگ کو سپورٹ نہیں کرتا۔ براہ کرم Google Chrome استعمال کریں۔");
            return;
        }

        var recognition = new webkitSpeechRecognition();
        recognition.lang = 'ur-PK'; // زبان: اردو (پاکستان)
        recognition.continuous = false;
        recognition.interimResults = false;

        // 2. شروع ہونے پر
        recognition.onstart = function() {
            var btn = document.querySelector(`button[onclick="startVoice('${inputId}')"]`);
            if(btn) {
                // پرانا آئیکن محفوظ کریں
                btn.setAttribute('data-original-html', btn.innerHTML);
                // نیا سپنر دکھائیں
                btn.classList.remove('btn-outline-danger', 'btn-outline-secondary');
                btn.classList.add('btn-danger');
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            }
        };

        // 3. رزلٹ آنے پر
        recognition.onresult = function(event) {
            var transcript = event.results[0][0].transcript;
            var inputField = document.getElementById(inputId);
            
            // اگر پہلے کچھ لکھا ہے تو اس کے آگے اسپیس دے کر نیا لکھیں
            if(inputField.value.trim() !== "") {
                inputField.value += " " + transcript;
            } else {
                inputField.value = transcript;
            }
        };

        // 4. ختم ہونے پر
        recognition.onend = function() {
            var btn = document.querySelector(`button[onclick="startVoice('${inputId}')"]`);
            if(btn) {
                btn.classList.remove('btn-danger');
                btn.classList.add('btn-outline-danger');
                // پرانا آئیکن واپس لائیں
                if(btn.hasAttribute('data-original-html')) {
                    btn.innerHTML = btn.getAttribute('data-original-html');
                } else {
                    btn.innerHTML = '<i class="fas fa-microphone"></i>';
                }
            }
        };

        // 5. ایرر ہینڈلنگ
        recognition.onerror = function(event) {
            console.error(event.error);
            alert("آواز صاف نہیں آئی، دوبارہ کوشش کریں۔");
        };

        recognition.start();
    }
</script>

</body>
</html>
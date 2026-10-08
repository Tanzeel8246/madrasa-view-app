    </div>
    <footer class="text-center py-3 bg-light border-top mt-5">
        <div class="container small">
            <span class="text-muted">© 2026 مدرسہ اشرف المدارس۔ جملہ حقوق محفوظ ہیں۔</span>
            <div class="mt-2">
                <a href="privacy.php" class="text-decoration-none text-secondary mx-2">پرائیسی پالیسی</a> |
                <a href="terms.php" class="text-decoration-none text-secondary mx-2">شرائط و ضوابط</a> |
                <a href="delete_account.php" class="text-decoration-none text-danger mx-2">اکاؤنٹ ختم کریں</a>
            </div>
        </div>
    </footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
    // مینیو ٹوگل
    var el = document.getElementById("wrapper");
    var toggleButton = document.getElementById("menu-toggle");
    toggleButton.onclick = function () {
        el.classList.toggle("toggled");
        document.body.classList.toggle("active-nav");
    };

    // سلیکٹ 2 (سرچ ایبل ڈراپ ڈاؤن)
    $(document).ready(function() {
        $('.select2').select2({ width: '100%' });
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
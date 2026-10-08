<?php
/**
 * ════════════════════════════════════════════════════════════════════
 *  سوپر ایڈمن مشترکہ فوٹر و اسکرپٹس (Super Admin Shared Footer & Scripts)
 * ════════════════════════════════════════════════════════════════════
 */
?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Theme Management (Dark / Light)
function applySaTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('sa_theme', theme);

    const icon = document.getElementById('saThemeIcon');
    const text = document.getElementById('saThemeText');

    if (icon && text) {
        if (theme === 'light') {
            icon.className = 'fas fa-moon';
            text.innerText = 'ڈارک موڈ';
        } else {
            icon.className = 'fas fa-sun';
            text.innerText = 'لائٹ موڈ';
        }
    }
}

function toggleSaTheme() {
    const current = document.documentElement.getAttribute('data-theme') || 'dark';
    const next = current === 'dark' ? 'light' : 'dark';
    applySaTheme(next);
}

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', function() {
    const saved = localStorage.getItem('sa_theme') || 'dark';
    applySaTheme(saved);

    // Ensure all dropdowns inside responsive tables use fixed popper strategy to prevent clipping
    document.querySelectorAll('.table-responsive [data-bs-toggle="dropdown"]').forEach(function(el) {
        new bootstrap.Dropdown(el, {
            boundary: 'viewport',
            popperConfig: function(defaultBsPopperConfig) {
                return Object.assign({}, defaultBsPopperConfig, { strategy: 'fixed' });
            }
        });
    });
});
</script>
</body>
</html>

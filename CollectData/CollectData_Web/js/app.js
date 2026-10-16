(function () {
    const themeKey = 'collectData.theme';
    const allowedThemes = ['dark', 'light', 'forest', 'rose'];

    function getSavedTheme() {
        let savedTheme = null;
        try {
            savedTheme = localStorage.getItem(themeKey);
        } catch (error) {
            savedTheme = null;
        }
        return allowedThemes.includes(savedTheme) ? savedTheme : 'dark';
    }

    function applyTheme(theme) {
        const nextTheme = allowedThemes.includes(theme) ? theme : 'dark';
        document.documentElement.setAttribute('data-theme', nextTheme);
        try {
            localStorage.setItem(themeKey, nextTheme);
        } catch (error) {
            console.warn('Cannot save theme preference:', error);
        }
    }

    applyTheme(getSavedTheme());

    window.setCollectDataTheme = applyTheme;

    window.openModal = function (id) {
        const modal = document.getElementById(id);
        if (modal) modal.classList.add('active');
    };

    window.closeModal = function (id) {
        const modal = document.getElementById(id);
        if (modal) modal.classList.remove('active');
    };

    function getSwalButtonColor() {
        return getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() || '#00d2ff';
    }

    window.showAlert = function (message, icon = 'info', title = 'แจ้งเตือน') {
        if (window.Swal) {
            return Swal.fire({
                icon,
                title,
                text: message,
                confirmButtonText: 'ตกลง',
                confirmButtonColor: getSwalButtonColor()
            });
        }

        console.warn('SweetAlert2 is not loaded:', message);
        return Promise.resolve();
    };

    window.showError = function (message) {
        return window.showAlert(message, 'error', 'เกิดข้อผิดพลาด');
    };

    window.showWarning = function (message) {
        return window.showAlert(message, 'warning', 'กรุณาตรวจสอบข้อมูล');
    };

    window.showSuccess = function (message) {
        return window.showAlert(message, 'success', 'สำเร็จ');
    };

    window.confirmAction = async function ({
        title = 'ยืนยันการทำรายการ',
        text = '',
        confirmButtonText = 'ยืนยัน',
        cancelButtonText = 'ยกเลิก',
        icon = 'warning'
    } = {}) {
        if (window.Swal) {
            const result = await Swal.fire({
                icon,
                title,
                text,
                showCancelButton: true,
                confirmButtonText,
                cancelButtonText,
                confirmButtonColor: getSwalButtonColor(),
                cancelButtonColor: '#64748b',
                reverseButtons: true
            });
            return result.isConfirmed;
        }

        console.warn('SweetAlert2 is not loaded:', title, text);
        return false;
    };

    document.addEventListener('DOMContentLoaded', function () {
        const themeSelect = document.getElementById('themeSelect');
        if (!themeSelect) return;

        themeSelect.value = getSavedTheme();
        themeSelect.addEventListener('change', function () {
            applyTheme(this.value);
        });
    });
})();

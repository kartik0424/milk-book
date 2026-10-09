/**
 * MilkBook - UI Utilities
 * Toasts, confirmation modal, currency/date formatters, escaping, and navbar active states.
 */

const ui = {
    /**
     * Escape HTML to prevent XSS
     */
    escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    },

    /**
     * Format Indian Rupee currency (₹)
     */
    formatCurrency(amount) {
        const val = parseFloat(amount) || 0;
        return '₹' + val.toLocaleString('en-IN', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    },

    /**
     * Format milk quantity with litres unit
     */
    formatQty(qty) {
        const val = parseFloat(qty) || 0;
        return val.toFixed(2) + ' L';
    },

    /**
     * Format date string (YYYY-MM-DD) into readable Indian format (e.g. 21 Sep 2026)
     */
    formatDate(dateStr) {
        if (!dateStr) return '-';
        const parts = dateStr.split('-');
        if (parts.length !== 3) return dateStr;

        const date = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
        if (isNaN(date.getTime())) return dateStr;

        return date.toLocaleDateString('en-IN', {
            day: '2-digit',
            month: 'short',
            year: 'numeric'
        });
    },

    /**
     * Display a Bootstrap Toast message
     *
     * @param {string} message
     * @param {'success'|'danger'|'warning'|'info'} type
     * @param {string} title
     */
    toast(message, type = 'info', title = '') {
        let container = document.getElementById('mb-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'mb-toast-container';
            container.className = 'toast-container position-fixed top-0 end-0 p-3';
            container.style.zIndex = '2100';
            document.body.appendChild(container);
        }

        const iconMap = {
            success: 'bi-check-circle-fill text-success',
            danger: 'bi-exclamation-triangle-fill text-danger',
            warning: 'bi-exclamation-circle-fill text-warning',
            info: 'bi-info-circle-fill text-primary'
        };

        const titleText = title || (type.charAt(0).toUpperCase() + type.slice(1));
        const iconClass = iconMap[type] || iconMap.info;

        const toastEl = document.createElement('div');
        toastEl.className = `toast align-items-center shadow-lg border-0 mb-2`;
        toastEl.setAttribute('role', 'alert');
        toastEl.setAttribute('aria-live', 'assertive');
        toastEl.setAttribute('aria-atomic', 'true');

        toastEl.innerHTML = `
            <div class="toast-header">
                <i class="bi ${iconClass} me-2 fs-5"></i>
                <strong class="me-auto">${ui.escapeHtml(titleText)}</strong>
                <small class="text-muted">just now</small>
                <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
            <div class="toast-body bg-white text-dark rounded-bottom">
                ${ui.escapeHtml(message)}
            </div>
        `;

        container.appendChild(toastEl);
        const bsToast = new bootstrap.Toast(toastEl, { delay: 4000 });
        bsToast.show();

        toastEl.addEventListener('hidden.bs.toast', () => {
            toastEl.remove();
        });
    },

    /**
     * Programmatic confirmation modal returning a Promise<boolean>
     *
     * @param {object} options
     * @param {string} options.title
     * @param {string} options.message
     * @param {string} [options.confirmText]
     * @param {string} [options.confirmClass]
     * @returns {Promise<boolean>}
     */
    confirm({ title = 'Confirm Action', message = 'Are you sure?', confirmText = 'Confirm', confirmClass = 'btn-danger' } = {}) {
        return new Promise((resolve) => {
            let modalEl = document.getElementById('mb-confirm-modal');
            if (!modalEl) {
                modalEl = document.createElement('div');
                modalEl.id = 'mb-confirm-modal';
                modalEl.className = 'modal fade';
                modalEl.tabIndex = -1;
                modalEl.innerHTML = `
                    <div class="modal-dialog modal-dialog-centered modal-sm">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title fs-6 fw-bold" id="mb-confirm-title"></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body fs-6" id="mb-confirm-message"></div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-sm btn-light border" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-sm" id="mb-confirm-btn"></button>
                            </div>
                        </div>
                    </div>
                `;
                document.body.appendChild(modalEl);
            }

            document.getElementById('mb-confirm-title').textContent = title;
            document.getElementById('mb-confirm-message').textContent = message;
            const confirmBtn = document.getElementById('mb-confirm-btn');
            confirmBtn.textContent = confirmText;
            confirmBtn.className = `btn btn-sm ${confirmClass}`;

            const bsModal = new bootstrap.Modal(modalEl);

            let confirmed = false;
            const handleConfirm = () => {
                confirmed = true;
                bsModal.hide();
            };

            confirmBtn.onclick = handleConfirm;

            const handleHidden = () => {
                modalEl.removeEventListener('hidden.bs.modal', handleHidden);
                confirmBtn.onclick = null;
                resolve(confirmed);
            };

            modalEl.addEventListener('hidden.bs.modal', handleHidden);
            bsModal.show();
        });
    },

    /**
     * Show loading spinner inside button
     */
    setLoading(btn, isLoading, loadingText = 'Saving...') {
        if (!btn) return;
        if (isLoading) {
            btn.dataset.originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = `<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>${ui.escapeHtml(loadingText)}`;
        } else {
            btn.disabled = false;
            if (btn.dataset.originalHtml) {
                btn.innerHTML = btn.dataset.originalHtml;
            }
        }
    },

    /**
     * Set active navbar links based on current page
     */
    initNavigation() {
        const path = window.location.pathname.split('/').pop() || 'index.html';
        
        // Desktop nav links
        document.querySelectorAll('.navbar-nav .nav-link').forEach(link => {
            const href = link.getAttribute('href');
            if (href === path || (path === '' && href === 'index.html')) {
                link.classList.add('active');
            } else {
                link.classList.remove('active');
            }
        });

        // Mobile bottom nav items
        document.querySelectorAll('.mobile-nav-item').forEach(link => {
            const href = link.getAttribute('href');
            if (href === path || (path === '' && href === 'index.html')) {
                link.classList.add('active');
            } else {
                link.classList.remove('active');
            }
        });
    }
};

document.addEventListener('DOMContentLoaded', () => {
    ui.initNavigation();
});

window.ui = ui;

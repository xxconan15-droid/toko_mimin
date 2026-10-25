</div><footer class="footer">TokoMimin · Sistem Kasir Toko Kelontong</footer></main></div>
<div class="action-notification" id="action-notification" role="status" aria-live="polite" aria-atomic="true"></div>
<script>
(() => {
    const notification = document.getElementById('action-notification');
    let dismissTimer;
    let hideTimer;
    let refreshTimer;
    let lastUserActivity = Date.now() - 5000;
    const notificationStorageKey = 'tokomimin-action-notification';
    const notificationParamsByPage = {
        'products.php': ['notice'],
        'promos.php': ['notice'],
        'transactions.php': ['notice'],
        'receipt.php': ['saved', 'settled']
    };
    const clearNotificationQuery = (params) => {
        if (!params.length) return;
        const url = new URL(window.location.href);
        params.forEach((param) => url.searchParams.delete(param));
        window.history.replaceState(window.history.state, '', url);
    };
    const hasUnsavedFormChanges = () => Array.from(document.forms).some((form) =>
        form.method.toLowerCase() !== 'get' && Array.from(form.elements).some((field) => {
            if (field.disabled || ['button', 'submit', 'reset', 'hidden', 'file'].includes(field.type)) return false;
            if (field.tagName === 'SELECT') {
                return Array.from(field.options).some((option) => option.selected !== option.defaultSelected);
            }
            if (field.type === 'checkbox' || field.type === 'radio') return field.checked !== field.defaultChecked;
            return field.value !== field.defaultValue;
        })
    );
    const scheduleSafeAction = (action = 'reload') => {
        window.clearTimeout(refreshTimer);
        refreshTimer = window.setTimeout(() => {
            const activeElement = document.activeElement;
            const editingField = activeElement && activeElement.matches('input, select, textarea');
            const dialogOpen = document.querySelector('dialog[open]');
            const safeToReload = Date.now() - lastUserActivity >= 5000
                && !editingField
                && (action !== 'reload' || (!dialogOpen && !hasUnsavedFormChanges()));
            if (safeToReload) {
                if (action === 'reload') {
                    window.location.reload();
                } else {
                    window.dispatchEvent(new Event(action));
                }
                return;
            }
            scheduleSafeAction(action);
        }, 1000);
    };
    ['pointerdown', 'keydown', 'input', 'change', 'focusin'].forEach((eventName) => {
        document.addEventListener(eventName, () => {
            lastUserActivity = Date.now();
        }, true);
    });
    window.showActionNotification = (text, type = 'success', scheduleRefresh = true) => {
        window.clearTimeout(dismissTimer);
        window.clearTimeout(hideTimer);
        window.clearTimeout(refreshTimer);
        const page = window.location.pathname.split('/').pop();
        const url = new URL(window.location.href);
        const notificationParams = (notificationParamsByPage[page] || [])
            .filter((param) => url.searchParams.has(param));
        const refreshProductsAfterNotice = notification.dataset.refreshProducts === 'true';
        notification.dataset.refreshProducts = 'false';
        clearNotificationQuery(notificationParams);
        notification.classList.remove('visible');
        notification.classList.toggle('error', type === 'error');
        notification.classList.toggle('success', type !== 'error');
        notification.setAttribute('role', type === 'error' ? 'alert' : 'status');
        notification.textContent = String(text);
        notification.hidden = false;
        void notification.offsetWidth;
        notification.classList.add('visible');
        if (scheduleRefresh && notificationParams.length > 0) {
            sessionStorage.setItem(notificationStorageKey, JSON.stringify({
                text: String(text),
                type
            }));
            scheduleSafeAction();
        } else if (scheduleRefresh && refreshProductsAfterNotice) {
            scheduleSafeAction('safeproductsrefresh');
        }
        dismissTimer = window.setTimeout(() => {
            notification.classList.remove('visible');
            hideTimer = window.setTimeout(() => {
                notification.hidden = true;
            }, 400);
        }, 3000);
    };
    document.querySelectorAll('.alert:not(#deviceNotice):not([data-notification="false"])').forEach((alert) => {
        const type = alert.classList.contains('error') ? 'error' : 'success';
        const text = alert.textContent.trim();
        notification.dataset.refreshProducts = alert.dataset.refreshProducts === 'true' ? 'true' : 'false';
        alert.hidden = true;
        if (text) window.showActionNotification(text, type);
    });
    const storedNotification = sessionStorage.getItem(notificationStorageKey);
    if (storedNotification) {
        sessionStorage.removeItem(notificationStorageKey);
        const notificationState = JSON.parse(storedNotification);
        window.showActionNotification(notificationState.text, notificationState.type, false);
    }
})();
</script>
</body></html>
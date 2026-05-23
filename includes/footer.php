</div><!-- /page-body -->
</div><!-- /main-wrap -->
</div><!-- /appShell -->

<!-- Toast notification stack -->
<div id="toastStack" class="toast-stack"></div>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- Bootstrap 5 -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- DataTables -->
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<!-- Select2 -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<!-- Custom -->
<script src="<?= BASE_URL ?>/assets/js/main.js?v=<?= @filemtime(BASE_PATH . '/assets/js/main.js') ?: time() ?>"></script>
<?php if (isset($extraJs)) echo $extraJs; ?>
<script>
// Auto-inject CSRF token into all HTML forms that POST to this site
(function() {
    var token = document.querySelector('meta[name="csrf-token"]');
    if (!token) return;
    var tokenValue = token.getAttribute('content');
    document.querySelectorAll('form[method="post"], form[method="POST"]').forEach(function(form) {
        if (!form.querySelector('input[name="csrf_token"]')) {
            var input = document.createElement('input');
            input.type  = 'hidden';
            input.name  = 'csrf_token';
            input.value = tokenValue;
            form.appendChild(input);
        }
    });
    // Also set header for fetch/XMLHttpRequest
    var origFetch = window.fetch;
    window.fetch = function(url, opts) {
        opts = opts || {};
        if (opts.method && opts.method.toUpperCase() === 'POST') {
            opts.headers = Object.assign({ 'X-CSRF-Token': tokenValue }, opts.headers || {});
        }
        return origFetch(url, opts);
    };
    if (window.XMLHttpRequest) {
        var origOpen = XMLHttpRequest.prototype.open;
        XMLHttpRequest.prototype.open = function(method) {
            this._method = method;
            return origOpen.apply(this, arguments);
        };
        var origSend = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.send = function(body) {
            if (this._method && this._method.toUpperCase() === 'POST') {
                this.setRequestHeader('X-CSRF-Token', tokenValue);
            }
            return origSend.apply(this, arguments);
        };
    }
}());
</script>
<?php if (isset($extraModal)) echo $extraModal; ?>
</body>
</html>

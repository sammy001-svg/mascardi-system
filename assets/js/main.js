/* Mascardi System — main.js v6 */

/* ─────────────────────────────────────────────────────────
   DARK MODE
   ───────────────────────────────────────────────────────── */
(function () {
    var saved = localStorage.getItem('mascardiTheme');
    if (saved === 'dark') {
        document.documentElement.setAttribute('data-theme', 'dark');
    }
}());

function applyTheme(theme) {
    var icon = document.getElementById('themeIcon');
    if (theme === 'dark') {
        document.documentElement.setAttribute('data-theme', 'dark');
        localStorage.setItem('mascardiTheme', 'dark');
        if (icon) icon.className = 'fa fa-sun';
    } else {
        document.documentElement.removeAttribute('data-theme');
        localStorage.setItem('mascardiTheme', 'light');
        if (icon) icon.className = 'fa fa-moon';
    }
}

/* ─────────────────────────────────────────────────────────
   TOAST SYSTEM
   ───────────────────────────────────────────────────────── */
var toastIcons = {
    success: 'fa fa-circle-check',
    error:   'fa fa-circle-xmark',
    warning: 'fa fa-triangle-exclamation',
    info:    'fa fa-circle-info'
};

window.showToast = function (message, type, duration) {
    type     = type     || 'info';
    duration = duration || 4000;

    var stack = document.getElementById('toastStack');
    if (!stack) return;

    var el = document.createElement('div');
    el.className = 'toast-item toast-' + type;

    el.innerHTML =
        '<i class="' + (toastIcons[type] || toastIcons.info) + ' toast-icon"></i>' +
        '<div class="toast-body">' + message + '</div>' +
        '<button class="toast-close" aria-label="Close"><i class="fa fa-xmark"></i></button>';

    stack.appendChild(el);

    requestAnimationFrame(function () {
        requestAnimationFrame(function () { el.classList.add('toast-show'); });
    });

    function dismiss() {
        el.classList.remove('toast-show');
        el.classList.add('toast-hide');
        setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 350);
    }

    var timer = setTimeout(dismiss, duration);

    el.querySelector('.toast-close').addEventListener('click', function (e) {
        e.stopPropagation();
        clearTimeout(timer);
        dismiss();
    });
    el.addEventListener('click', function () { clearTimeout(timer); dismiss(); });
};

/* ─────────────────────────────────────────────────────────
   JQUERY-DEPENDENT CODE
   ───────────────────────────────────────────────────────── */
$(function () {

    /* ── Dark mode toggle — sync icon after DOM ready ─── */
    (function syncThemeIcon() {
        var icon = document.getElementById('themeIcon');
        if (!icon) return;
        icon.className = document.documentElement.getAttribute('data-theme') === 'dark'
            ? 'fa fa-sun' : 'fa fa-moon';
    }());

    $('#themeToggle').on('click', function () {
        var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        applyTheme(isDark ? 'light' : 'dark');
    });

    /* ── Sidebar: restore collapsed state ─────────────── */
    var sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
    if (sidebarCollapsed && $(window).width() > 768) {
        $('#sidebar').addClass('collapsed');
    }

    /* ── Sidebar toggle ────────────────────────────────── */
    $('#sidebarToggle').on('click', function () {
        if ($(window).width() <= 768) {
            $('#sidebar').toggleClass('open');
            $('#sidebarBackdrop').toggleClass('show');
        } else {
            var nowCollapsed = $('#sidebar').toggleClass('collapsed').hasClass('collapsed');
            localStorage.setItem('sidebarCollapsed', nowCollapsed);
        }
    });

    $('#sidebarBackdrop').on('click', function () {
        $('#sidebar').removeClass('open');
        $(this).removeClass('show');
    });

    /* ── Wrap tables for responsive ────────────────────── */
    $('table.table').each(function () {
        if (!$(this).parent().hasClass('table-responsive')) {
            $(this).wrap('<div class="table-responsive" style="width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch;margin-bottom:1rem"></div>');
        }
    });

    /* ── DataTables init ───────────────────────────────── */
    if ($.fn.DataTable) {
        $('.datatable').each(function () {
            $(this).DataTable({
                pageLength: 25,
                order: [],
                language: {
                    search: '',
                    searchPlaceholder: 'Search...',
                    emptyTable: 'No records found',
                    zeroRecords: 'No matching records found',
                },
                dom: '<"d-flex justify-content-between align-items-center mb-3"lf>t<"d-flex justify-content-between align-items-center mt-3"ip>',
            });
        });
    }

    /* ── Select2 init ──────────────────────────────────── */
    if ($.fn.select2) {
        $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });
    }

    /* ── Dynamic line items (Invoice / Quotation / LPO) ── */
    $(document).on('click', '.add-line-item', function () {
        var template = $(this).closest('.line-items-wrapper').find('.line-item-row:first').clone();
        template.find('input, select, textarea').val('');
        template.find('.item-total').text('0.00');
        $(this).closest('.line-items-wrapper').find('.line-items-body').append(template);
        if ($.fn.select2) {
            template.find('.select2').select2({ theme: 'bootstrap-5', width: '100%' });
        }
    });

    $(document).on('click', '.remove-line-item', function () {
        var rows = $(this).closest('.line-items-body').find('.line-item-row');
        if (rows.length > 1) {
            $(this).closest('.line-item-row').remove();
            recalcTotals();
        }
    });

    $(document).on('input change', '.item-qty, .item-price, .item-discount', function () {
        recalcTotals();
    });

    $('#overall_discount, #tax_rate').on('input change', function () {
        recalcTotals();
    });

    function recalcTotals() {
        var subtotal = 0;
        $('.line-item-row').each(function () {
            var qty      = parseFloat($(this).find('.item-qty').val())      || 0;
            var price    = parseFloat($(this).find('.item-price').val())     || 0;
            var discount = parseFloat($(this).find('.item-discount').val())  || 0;
            var total    = qty * price * (1 - discount / 100);
            $(this).find('.item-total').text(total.toFixed(2));
            subtotal += total;
        });
        var taxRate     = parseFloat($('#tax_rate').val())          || 0;
        var discount    = parseFloat($('#overall_discount').val())  || 0;
        var discountAmt = subtotal * (discount / 100);
        var taxable     = subtotal - discountAmt;
        var taxAmt      = taxable * (taxRate / 100);
        var total       = taxable + taxAmt;

        $('#subtotal_display').text(subtotal.toFixed(2));
        $('#discount_display').text(discountAmt.toFixed(2));
        $('#tax_display').text(taxAmt.toFixed(2));
        $('#total_display').text(total.toFixed(2));

        $('#hidden_subtotal').val(subtotal.toFixed(2));
        $('#hidden_discount').val(discountAmt.toFixed(2));
        $('#hidden_tax').val(taxAmt.toFixed(2));
        $('#hidden_total').val(total.toFixed(2));
    }

    recalcTotals();

    /* ── Part price auto-fill ──────────────────────────── */
    $(document).on('change', '.inventory-select', function () {
        var row      = $(this).closest('.line-item-row');
        var selected = $(this).find(':selected');
        var price    = selected.data('price') || '';
        var desc     = selected.data('desc')  || selected.text();
        if (price) row.find('.item-price').val(price).trigger('input');
        if (desc)  row.find('.item-desc').val(desc);
        recalcTotals();
    });

    /* ── Confirm delete ────────────────────────────────── */
    $(document).on('click', '.confirm-delete', function (e) {
        if (!confirm('Are you sure you want to delete this record? This cannot be undone.')) {
            e.preventDefault();
        }
    });

    /* ── Flash alert auto-dismiss ─────────────────────── */
    setTimeout(function () { $('.alert').fadeOut(400); }, 5500);

    /* ── Print ─────────────────────────────────────────── */
    $('#btnPrint').on('click', function () { window.print(); });

});

/* ─────────────────────────────────────────────────────────
   IMAGE PERFORMANCE — skeleton removal + broken-image fallback
   Runs after DOM is ready so all injected loading="lazy" attrs are present.
   ───────────────────────────────────────────────────────── */
(function () {
    function markLoaded(img) {
        img.classList.add('img-loaded');
    }
    function markError(img) {
        img.classList.add('img-loaded');
        img.style.opacity = '0.25';
        img.style.filter  = 'grayscale(1)';
        if (!img.alt) img.alt = 'Image unavailable';
    }

    document.querySelectorAll('img[loading="lazy"]').forEach(function (img) {
        if (img.complete) {
            (img.naturalWidth > 0) ? markLoaded(img) : markError(img);
            return;
        }
        img.addEventListener('load',  function () { markLoaded(this); });
        img.addEventListener('error', function () { markError(this); });
    });
}());

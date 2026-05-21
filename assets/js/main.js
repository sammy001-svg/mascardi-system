$(function () {

    // ── Wrap HTML tables dynamically for mobile ─────
    $('table.table').each(function () {
        if (!$(this).parent().hasClass('table-responsive')) {
            $(this).wrap('<div class="table-responsive" style="width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; margin-bottom: 1rem;"></div>');
        }
    });

    // ── Sidebar toggle ──────────────────────────────
    $('#sidebarToggle').on('click', function () {
        if ($(window).width() <= 768) {
            $('#sidebar').toggleClass('open');
            $('#sidebarBackdrop').toggleClass('show');
        } else {
            $('#sidebar').toggleClass('collapsed');
        }
    });

    $('#sidebarBackdrop').on('click', function () {
        $('#sidebar').removeClass('open');
        $(this).removeClass('show');
    });

    // ── DataTables init ─────────────────────────────
    if ($.fn.DataTable) {
        $('.datatable').each(function () {

            $(this).DataTable({
                pageLength: 25,
                language: { search: '', searchPlaceholder: 'Search...' },
                dom: '<"d-flex justify-content-between align-items-center mb-3"lf>t<"d-flex justify-content-between align-items-center mt-3"ip>',
            });
        });
    }

    // ── Select2 init ────────────────────────────────
    if ($.fn.select2) {
        $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });
    }

    // ── Dynamic line items (Quotation / Invoice / LPO) ──
    $(document).on('click', '.add-line-item', function () {
        const template = $(this).closest('.line-items-wrapper').find('.line-item-row:first').clone();
        template.find('input, select, textarea').val('');
        template.find('.item-total').text('0.00');
        $(this).closest('.line-items-wrapper').find('.line-items-body').append(template);
        if ($.fn.select2) {
            template.find('.select2').select2({ theme: 'bootstrap-5', width: '100%' });
        }
    });

    $(document).on('click', '.remove-line-item', function () {
        const rows = $(this).closest('.line-items-body').find('.line-item-row');
        if (rows.length > 1) {
            $(this).closest('.line-item-row').remove();
            recalcTotals();
        }
    });

    $(document).on('input change', '.item-qty, .item-price, .item-discount', function () {
        recalcTotals();
    });

    function recalcTotals() {
        let subtotal = 0;
        $('.line-item-row').each(function () {
            const qty      = parseFloat($(this).find('.item-qty').val())   || 0;
            const price    = parseFloat($(this).find('.item-price').val())  || 0;
            const discount = parseFloat($(this).find('.item-discount').val()) || 0;
            const total    = qty * price * (1 - discount / 100);
            $(this).find('.item-total').text(total.toFixed(2));
            subtotal += total;
        });
        const taxRate = parseFloat($('#tax_rate').val()) || 0;
        const discount = parseFloat($('#overall_discount').val()) || 0;
        const discountAmt = subtotal * (discount / 100);
        const taxable  = subtotal - discountAmt;
        const taxAmt   = taxable * (taxRate / 100);
        const total    = taxable + taxAmt;

        $('#subtotal_display').text(subtotal.toFixed(2));
        $('#discount_display').text(discountAmt.toFixed(2));
        $('#tax_display').text(taxAmt.toFixed(2));
        $('#total_display').text(total.toFixed(2));

        $('#hidden_subtotal').val(subtotal.toFixed(2));
        $('#hidden_discount').val(discountAmt.toFixed(2));
        $('#hidden_tax').val(taxAmt.toFixed(2));
        $('#hidden_total').val(total.toFixed(2));
    }

    // Initial calc on page load
    recalcTotals();

    // ── Part price auto-fill from inventory ─────────
    $(document).on('change', '.inventory-select', function () {
        const row = $(this).closest('.line-item-row');
        const selected = $(this).find(':selected');
        const price = selected.data('price') || '';
        const desc  = selected.data('desc')  || selected.text();
        if (price) row.find('.item-price').val(price).trigger('input');
        if (desc)  row.find('.item-desc').val(desc);
        recalcTotals();
    });

    // ── Confirm delete ───────────────────────────────
    $(document).on('click', '.confirm-delete', function (e) {
        if (!confirm('Are you sure you want to delete this record? This cannot be undone.')) {
            e.preventDefault();
        }
    });

    // ── Auto-dismiss alerts ──────────────────────────
    setTimeout(function () {
        $('.alert').fadeOut(400);
    }, 5000);

    // ── Print page ───────────────────────────────────
    $('#btnPrint').on('click', function () {
        window.print();
    });

});

@extends('layouts.app')
@section('title', 'Till')

@section('content')
@if (! auth()->user()->school_id)
    <div class="alert alert-info">Log in as a school user to use the till.</div>
@elseif ($products->isEmpty())
    <div class="alert alert-warning">There are no products yet. Ask an admin to add some under Products.</div>
@else
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="mb-2" id="categories"></div>
            <div class="row g-2" id="productGrid"></div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm sticky-top" style="top: 1rem">
                <div class="card-header fw-semibold">Cart</div>
                <ul class="list-group list-group-flush" id="cartLines"></ul>
                <div class="card-body">
                    <div class="d-flex justify-content-between fs-4 fw-bold">
                        <span>Total</span><span id="cartTotal"></span>
                    </div>
                    <div class="d-grid gap-2 mt-3">
                        <button type="button" id="payBtn" class="btn btn-success btn-lg" disabled>Pay · tap card</button>
                        <button type="button" id="clearBtn" class="btn btn-outline-secondary">Clear cart</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="payModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body text-center p-5">

                    <div class="pay-state" id="st-waiting">
                        <div class="display-6 mb-2" id="payTotal"></div>
                        <p class="fs-3 mb-3">Tap the card</p>
                        <form id="tapForm" autocomplete="off">
                            <div class="input-group input-group-lg">
                                <input id="tapUid" class="form-control text-center" placeholder="Card number" autocomplete="off">
                                <button type="submit" class="btn btn-primary">Charge</button>
                            </div>
                        </form>
                        <button type="button" class="btn btn-link mt-3" data-bs-dismiss="modal">Cancel</button>
                    </div>

                    <div class="pay-state d-none" id="st-busy">
                        <div class="spinner-border" role="status"></div>
                        <p class="fs-4 mt-3 mb-0">Processing…</p>
                    </div>

                    <div class="pay-state d-none" id="st-ok">
                        <div class="text-success display-3">✓</div>
                        <div class="fs-3" id="okStudent"></div>
                        <ul class="list-unstyled text-start mx-auto my-3" id="okLines" style="max-width: 320px"></ul>
                        <div class="d-flex justify-content-between fs-5 mx-auto" style="max-width: 320px">
                            <span>Paid</span><strong id="okTotal"></strong>
                        </div>
                        <div class="d-flex justify-content-between fs-5 mx-auto" style="max-width: 320px">
                            <span>Balance left</span><strong id="okBalance"></strong>
                        </div>
                        <button type="button" class="btn btn-success btn-lg mt-4" id="doneBtn">New sale</button>
                    </div>

                    <div class="pay-state d-none" id="st-err">
                        <div class="text-danger display-3">✕</div>
                        <p class="fs-4 mb-4" id="errMessage"></p>
                        <button type="button" class="btn btn-primary btn-lg" id="retryBtn">Try again</button>
                        <button type="button" class="btn btn-link" data-bs-dismiss="modal">Cancel</button>
                    </div>

                </div>
            </div>
        </div>
    </div>
@endif
@endsection

@if ($products->isNotEmpty())
@push('scripts')
<script>
// Vite's module script runs just before DOMContentLoaded, so wait for it
document.addEventListener('DOMContentLoaded', function () {
    var PRODUCTS = @json($products);
    var CURRENCY = @json($currency);
    var CHECKOUT_URL = @json(route('till.checkout'));

    var byId = {};
    PRODUCTS.forEach(function (p) { byId[p.id] = p; });

    var cart = {};        // product id -> quantity
    var saleKey = null;   // idempotency key: a retried request can never charge twice
    var category = 'All';
    var state = 'waiting';
    var modalOpen = false;

    var payModalEl = document.getElementById('payModal');
    var payModal = new bootstrap.Modal(payModalEl);

    function money(n) {
        return CURRENCY === 'KHR'
            ? Number(n).toLocaleString('en-US') + ' ៛'
            : '$' + (n / 100).toFixed(2);
    }

    function newKey() {
        return 'sale-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
    }

    function maxQty(p) { return p.track_stock ? Math.max(p.stock, 0) : 99; }

    function cartIds() { return Object.keys(cart); }

    function cartTotal() {
        var total = 0;
        cartIds().forEach(function (id) { total += byId[id].price * cart[id]; });
        return total;
    }

    function renderCategories() {
        var names = ['All'];
        PRODUCTS.forEach(function (p) { if (names.indexOf(p.category) === -1) { names.push(p.category); } });

        var $box = $('#categories').empty();
        names.forEach(function (name) {
            $('<button type="button" class="btn me-2 mb-2 js-cat"></button>')
                .addClass(name === category ? 'btn-dark' : 'btn-outline-dark')
                .attr('data-cat', name)
                .text(name)
                .appendTo($box);
        });
    }

    function renderProducts() {
        var $grid = $('#productGrid').empty();
        PRODUCTS.forEach(function (p) {
            if (category !== 'All' && p.category !== category) { return; }

            var soldOut = p.track_stock && p.stock <= 0;

            var $btn = $('<button type="button" class="btn w-100 js-add" style="min-height: 90px"></button>')
                .addClass(soldOut ? 'btn-outline-secondary' : 'btn-outline-primary')
                .prop('disabled', soldOut)
                .attr('data-id', p.id)
                .append($('<div class="fw-semibold"></div>').text(p.name))
                .append($('<div class="small"></div>').text(p.price_formatted));

            if (p.track_stock) {
                $btn.append(
                    $('<div class="small"></div>')
                        .addClass(soldOut ? 'text-danger' : 'text-muted')
                        .text(soldOut ? 'Out of stock' : p.stock + ' left')
                );
            }

            $('<div class="col-6 col-md-4 col-xl-3"></div>').append($btn).appendTo($grid);
        });
    }

    function renderCart() {
        var $list = $('#cartLines').empty();
        var ids = cartIds();

        if (!ids.length) {
            $list.append('<li class="list-group-item text-muted">The cart is empty</li>');
        }

        ids.forEach(function (id) {
            var p = byId[id];
            var atMax = p.track_stock && cart[id] >= p.stock;
            var sub = money(p.price * cart[id]) + (atMax ? ' · all that is in stock' : '');
            var $li = $('<li class="list-group-item d-flex justify-content-between align-items-center"></li>');

            $li.append(
                $('<div></div>')
                    .append($('<div></div>').text(p.name))
                    .append($('<div class="small text-muted"></div>').text(sub))
            );
            $li.append(
                $('<div class="btn-group btn-group-sm"></div>')
                    .append($('<button type="button" class="btn btn-outline-secondary js-dec"></button>').attr('data-id', id).text('-'))
                    .append($('<span class="btn btn-light disabled"></span>').text(cart[id]))
                    .append($('<button type="button" class="btn btn-outline-secondary js-inc"></button>').attr('data-id', id).prop('disabled', atMax).text('+'))
            );
            $list.append($li);
        });

        $('#cartTotal').text(money(cartTotal()));
        $('#payBtn').prop('disabled', !ids.length);
    }

    function changeQty(id, delta) {
        var p = byId[id];
        cart[id] = (cart[id] || 0) + delta;
        if (cart[id] > maxQty(p)) { cart[id] = maxQty(p); }
        if (cart[id] <= 0) { delete cart[id]; }
        saleKey = null;   // a changed cart is a new sale
        renderCart();
    }

    function show(which) {
        state = which;
        $('.pay-state').addClass('d-none');
        $('#st-' + which).removeClass('d-none');

        if (which === 'waiting') {
            $('#tapUid').val('');
            setTimeout(function () { $('#tapUid').trigger('focus'); }, 50);
        }
    }

    function payload() {
        return cartIds().map(function (id) {
            return { product_id: Number(id), quantity: cart[id] };
        });
    }

    function onPaid(res) {
        $('#okStudent').text(res.student.name);
        $('#okTotal').text(res.total_formatted);
        $('#okBalance').text(res.balance_formatted);

        var $lines = $('#okLines').empty();
        res.items.forEach(function (item) {
            $('<li class="d-flex justify-content-between"></li>')
                .append($('<span></span>').text(item.quantity + ' × ' + item.name))
                .append($('<span></span>').text(item.line_total_formatted))
                .appendTo($lines);
        });

        // Fresh stock numbers from the server (other tills may have sold too)
        $.each(res.stock || {}, function (id, stock) {
            if (byId[id]) { byId[id].stock = stock; }
        });

        cart = {};
        saleKey = null;
        renderProducts();
        renderCart();
        show('ok');
    }

    function onRejected(xhr) {
        console.error('Checkout failed', xhr.status, xhr.responseText);

        var message = (xhr.responseJSON && xhr.responseJSON.message)
            || (xhr.status === 0
                ? 'No connection to the server. Please try again.'
                : 'Something went wrong (error ' + xhr.status + '). Please try again.');

        $('#errMessage').text(message);
        show('err');   // the sale key is kept: a retry of the same sale can never charge twice
    }

    // ---- events
    $('#categories').on('click', '.js-cat', function () {
        category = $(this).attr('data-cat');
        renderCategories();
        renderProducts();
    });

    $('#productGrid').on('click', '.js-add', function () { changeQty($(this).attr('data-id'), 1); });
    $('#cartLines').on('click', '.js-inc', function () { changeQty($(this).attr('data-id'), 1); });
    $('#cartLines').on('click', '.js-dec', function () { changeQty($(this).attr('data-id'), -1); });

    $('#clearBtn').on('click', function () {
        cart = {};
        saleKey = null;
        renderCart();
    });

    $('#payBtn').on('click', function () {
        if (!cartIds().length) { return; }
        if (!saleKey) { saleKey = newKey(); }

        $('#payTotal').text(money(cartTotal()));
        show('waiting');
        payModal.show();
    });

    // The reader "types" the card number and presses Enter; the Charge button does the same by hand
    $('#tapForm').on('submit', function (e) {
        e.preventDefault();

        var uid = $.trim($('#tapUid').val());
        if (!uid || state !== 'waiting') { return; }

        show('busy');
        $.ajax({
            url: CHECKOUT_URL,
            method: 'POST',
            dataType: 'json',
            contentType: 'application/json',
            data: JSON.stringify({ uid: uid, key: saleKey, items: payload() })
        }).done(onPaid).fail(onRejected);
    });

    $('#retryBtn').on('click', function () { show('waiting'); });
    $('#doneBtn').on('click', function () { payModal.hide(); });

    payModalEl.addEventListener('shown.bs.modal', function () {
        modalOpen = true;
        if (state === 'waiting') { $('#tapUid').trigger('focus'); }
    });
    payModalEl.addEventListener('hidden.bs.modal', function () { modalOpen = false; });

    // Keep the cursor in the box while waiting, so the reader's keystrokes are never lost
    $('#tapUid').on('blur', function (e) {
        var goingToButton = e.relatedTarget && e.relatedTarget.tagName === 'BUTTON';
        if (state === 'waiting' && modalOpen && !goingToButton) {
            setTimeout(function () { $('#tapUid').trigger('focus'); }, 10);
        }
    });

    renderCategories();
    renderProducts();
    renderCart();
});
</script>
@endpush
@endif

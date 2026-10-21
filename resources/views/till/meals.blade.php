@extends('layouts.app')
@section('title', __('Meal screen'))

@section('content')
@if (! auth()->user()->school_id)
    <div class="alert alert-info">{{ __('Log in as a school user to serve meals.') }}</div>
@elseif ($meals->isEmpty())
    <div class="alert alert-warning">{{ __('No meals are set up yet. Ask an admin to add them under Meals.') }}</div>
@else
    <div class="d-flex flex-wrap gap-2 mb-3" id="mealButtons"></div>

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card shadow-sm">
                <div class="card-body">
                    <label class="form-label fw-semibold" for="tapInput">{{ __('Tap the card') }}</label>
                    <form id="tapForm" autocomplete="off">
                        <input id="tapInput" class="form-control form-control-lg text-center" placeholder="{{ __('Card or student number') }}" autocomplete="off" autofocus>
                    </form>
                    <button type="button" class="btn btn-link btn-sm mt-2 p-0" id="autoBtn">{{ __('Choose the meal by time of day') }}</button>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 text-muted">{{ __('Served today') }}</h2>
                    <div id="counts" class="fs-4"></div>
                </div>
            </div>
        </div>
    </div>

    {{-- The big result: green OK or red reason. It clears itself so the queue keeps moving. --}}
    <div id="overlay" class="d-none position-fixed top-0 start-0 w-100 h-100" style="z-index: 1080">
        <div id="ovInner" class="w-100 h-100 d-flex flex-column justify-content-center align-items-center text-center p-4"></div>
    </div>
@endif
@endsection

@if ($meals->isNotEmpty())
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var MEALS = @json($meals);
    var NOW = @json($serverNow);
    var COUNTS = @json($counts);
    var COLLECT_URL = @json(route('till.meals.collect'));
    var COUNTS_URL = @json(route('till.meals.counts'));
    var MODE_KEY = 'canteen.meal-mode';

    var mealId = null;
    var busy = false;
    var timer = null;
    var waiting = null;   // a typed number waiting for the staff member's confirmation

    function byTime(hhmm) {
        var hit = MEALS.filter(function (m) { return m.from && m.to && hhmm >= m.from && hhmm <= m.to; })[0];
        return (hit || MEALS[0]).id;
    }

    function newKey() {
        return 'meal-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
    }

    function renderButtons() {
        var $box = $('#mealButtons').empty();
        MEALS.forEach(function (m) {
            var label = m.name + (m.from ? '  ' + m.from + '–' + m.to : '');
            $('<button type="button" class="btn btn-lg js-meal"></button>')
                .addClass(m.id === mealId ? 'btn-dark' : 'btn-outline-dark')
                .attr('data-id', m.id)
                .text(label)
                .appendTo($box);
        });
    }

    function renderCounts(list) {
        var $box = $('#counts').empty();
        (list || []).forEach(function (c) {
            $('<div class="d-flex justify-content-between border-bottom py-2"></div>')
                .append($('<span></span>').text(c.name))
                .append($('<strong></strong>').text(c.count))
                .appendTo($box);
        });
    }

    function panel(classes) {
        $('#overlay').removeClass('d-none');
        $('#ovInner').attr('class', 'w-100 h-100 d-flex flex-column justify-content-center align-items-center text-center p-4 ' + classes).empty();
        return $('#ovInner');
    }

    function clearAll() {
        clearTimeout(timer);
        waiting = null;
        $('#overlay').addClass('d-none');
        $('#tapInput').val('').trigger('focus');
    }

    function scheduleClear(ms) {
        clearTimeout(timer);
        timer = setTimeout(clearAll, ms);
    }

    function showOk(res) {
        var $p = panel('bg-success text-white');
        $p.append($('<div class="display-1 fw-bold"></div>').text('✓ OK'));
        $p.append($('<div class="display-4 mt-3"></div>').text(res.student.first_name));
        $p.append($('<div class="fs-3 mt-2"></div>').text(res.meal + ' · ' + res.time));
        renderCounts(res.counts);
        scheduleClear(2000);
    }

    function showFail(xhr) {
        var message = (xhr.responseJSON && xhr.responseJSON.message)
            || (xhr.status === 0 ? __('No connection. Try again.') : 'Something went wrong (' + xhr.status + ').');
        var $p = panel('bg-danger text-white');
        $p.append($('<div class="display-3 fw-bold"></div>').text('✕ NO'));
        $p.append($('<div class="fs-3 mt-3"></div>').text(message));
        scheduleClear(3000);
    }

    function showConfirm(student, identifier, key) {
        waiting = { identifier: identifier, key: key, code: student.code };
        clearTimeout(timer);   // a typed number waits for a person: no timer
        var $p = panel('bg-light text-dark');
        $p.append($('<div class="text-muted"></div>').text('Student number ' + student.code));
        $p.append($('<div class="display-5 fw-bold"></div>').text(student.name));
        if (student.grade) { $p.append($('<div class="fs-4 text-muted"></div>').text('Grade ' + student.grade)); }
        $p.append($('<div class="fs-4 mt-3 mb-4"></div>').text('Record the meal for this student?'));
        $('<button type="button" class="btn btn-success btn-lg me-2 js-yes"></button>').text('Yes, record').appendTo($p);
        $('<button type="button" class="btn btn-outline-secondary btn-lg js-no"></button>').text('No').appendTo($p);
    }

    function send(identifier, confirmCode, key) {
        busy = true;
        $.ajax({
            url: COLLECT_URL,
            method: 'POST',
            dataType: 'json',
            contentType: 'application/json',
            data: JSON.stringify({
                identifier: identifier,
                meal_type_id: mealId,
                key: key,
                confirm_student: confirmCode || null
            })
        }).done(function (res) {
            busy = false;
            if (res.confirm) {
                showConfirm(res.student, identifier, key);
            } else {
                showOk(res);
            }
        }).fail(function (xhr) {
            busy = false;
            console.error('Meal collection failed', xhr.status, xhr.responseText);
            showFail(xhr);
        });
    }

    // ---- events
    $('#mealButtons').on('click', '.js-meal', function () {
        mealId = Number($(this).attr('data-id'));
        localStorage.setItem(MODE_KEY, String(mealId));
        renderButtons();
        $('#tapInput').trigger('focus');
    });

    $('#autoBtn').on('click', function () {
        localStorage.removeItem(MODE_KEY);
        mealId = byTime(NOW);
        renderButtons();
        $('#tapInput').trigger('focus');
    });

    // The reader types the card number and presses Enter; a person can type a student number the same way
    $('#tapForm').on('submit', function (e) {
        e.preventDefault();
        var value = String($('#tapInput').val() || '').trim();
        if (!value || busy || !mealId || waiting) { return; }
        send(value, null, newKey());
    });

    $('#overlay').on('click', '.js-yes', function () {
        if (!waiting) { return; }
        var w = waiting;
        waiting = null;
        $('#overlay').addClass('d-none');
        send(w.identifier, w.code, w.key);
    });

    $('#overlay').on('click', '.js-no', function () { clearAll(); });

    // Keep the cursor in the box so the reader's keystrokes are never lost
    $('#tapInput').on('blur', function (e) {
        var toButton = e.relatedTarget && e.relatedTarget.tagName === 'BUTTON';
        if (!toButton && $('#overlay').hasClass('d-none')) {
            setTimeout(function () { $('#tapInput').trigger('focus'); }, 10);
        }
    });

    // ---- start: the meal chosen last time on this screen, otherwise by the time of day
    var stored = parseInt(localStorage.getItem(MODE_KEY) || '', 10);
    mealId = MEALS.some(function (m) { return m.id === stored; }) ? stored : byTime(NOW);

    renderButtons();
    renderCounts(COUNTS);
    $('#tapInput').trigger('focus');
});
</script>
@endpush
@endif

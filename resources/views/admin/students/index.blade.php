@extends('layouts.app')
@section('title', 'Students')

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">Students</h1>
    <form method="GET" class="d-flex" role="search">
        <input name="q" value="{{ request('q') }}" class="form-control me-2" placeholder="Search name or code">
        <button class="btn btn-outline-secondary">Search</button>
    </form>
</div>

@if ($errors->any())
    <div class="alert alert-danger">
        @foreach ($errors->all() as $error)
            <div>{{ $error }}</div>
        @endforeach
    </div>
@endif

@if (! auth()->user()->school_id)
    <div class="alert alert-info">You are viewing all schools. Log in as a school admin to add students.</div>
@else
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.students.store') }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-3">
                    <label class="form-label" for="student_code">Student code</label>
                    <input id="student_code" name="student_code" value="{{ old('student_code') }}" class="form-control" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="name">Name</label>
                    <input id="name" name="name" value="{{ old('name') }}" class="form-control" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="grade">Grade</label>
                    <input id="grade" name="grade" type="number" min="1" max="12" value="{{ old('grade') }}" class="form-control">
                </div>
                <div class="col-md-3">
                    <button class="btn btn-primary w-100">Add student</button>
                </div>
            </form>
        </div>
    </div>
@endif

<div class="table-responsive">
    <table class="table table-hover align-middle bg-white">
        <thead class="table-light">
            <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Grade</th>
                <th class="text-end">Balance</th>
                <th>Card</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
        @forelse ($students as $student)
            @php $card = $student->cards->first(); @endphp
            <tr>
                <td>{{ $student->student_code }}</td>
                <td>{{ $student->name }}</td>
                <td>{{ $student->grade ?? '–' }}</td>
                <td class="text-end">{{ \App\Support\Money::format($student->account?->balance ?? 0, $student->school->currency) }}</td>
                <td>
                    @if ($card)
                        <code>{{ $card->uid }}</code>
                        <span class="badge {{ $card->status === 'active' ? 'bg-success' : 'bg-warning text-dark' }}">{{ $card->status }}</span>
                    @else
                        <span class="text-muted">No card</span>
                    @endif
                </td>
                <td class="text-end text-nowrap">
                    <button type="button" class="btn btn-sm btn-primary js-card"
                            data-url="{{ route('admin.students.card', $student) }}"
                            data-name="{{ $student->name }}"
                            data-mode="{{ $card ? 'replace' : 'bind' }}">
                        {{ $card ? 'Replace card' : 'Bind card' }}
                    </button>
                    @if ($card)
                        <button type="button" class="btn btn-sm btn-outline-secondary js-toggle-card"
                                data-url="{{ route($card->status === 'active' ? 'admin.cards.block' : 'admin.cards.unblock', $card) }}">
                            {{ $card->status === 'active' ? 'Block' : 'Unblock' }}
                        </button>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-center text-muted py-4">No students yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

{{ $students->links() }}

<div class="modal fade" id="cardModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" id="cardForm" autocomplete="off">
            <div class="modal-header">
                <h5 class="modal-title"><span id="cardMode">Bind card</span> · <span id="cardStudent"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted mb-2">Tap the card on the reader, or type the card number, then press Enter.</p>
                <p class="small text-warning-emphasis d-none" id="cardReplaceNote">
                    The current card will stop working. The balance stays on the student's account.
                </p>
                <input id="cardUid" class="form-control form-control-lg" placeholder="Card number (UID)" required>
                <div id="cardError" class="text-danger mt-2 d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save card</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
// Vite's module script runs just before DOMContentLoaded, so wait for it
document.addEventListener('DOMContentLoaded', function () {
    var modalEl = document.getElementById('cardModal');
    var modal = new bootstrap.Modal(modalEl);
    var currentUrl = null;

    $('.js-card').on('click', function () {
        var $btn = $(this);
        currentUrl = $btn.data('url');
        var replacing = $btn.data('mode') === 'replace';

        $('#cardStudent').text($btn.data('name'));
        $('#cardMode').text(replacing ? 'Replace card' : 'Bind card');
        $('#cardReplaceNote').toggleClass('d-none', !replacing);
        $('#cardUid').val('');
        $('#cardError').addClass('d-none');
        modal.show();
    });

    // Put the cursor in the box so a USB reader can "type" straight into it
    modalEl.addEventListener('shown.bs.modal', function () {
        $('#cardUid').trigger('focus');
    });

    $('#cardForm').on('submit', function (e) {
        e.preventDefault();   // the reader's Enter key lands here

        $.post(currentUrl, { uid: $('#cardUid').val() })
            .done(function () { location.reload(); })
            .fail(function (xhr) {
                var message = (xhr.responseJSON && xhr.responseJSON.message) || 'Something went wrong.';
                $('#cardError').text(message).removeClass('d-none');
                $('#cardUid').trigger('select');
            });
    });

    $('.js-toggle-card').on('click', function () {
        $.post($(this).data('url'))
            .done(function () { location.reload(); })
            .fail(function (xhr) {
                alert((xhr.responseJSON && xhr.responseJSON.message) || 'Something went wrong.');
            });
    });
});
</script>
@endpush

@extends('layouts.app')
@section('title', 'Meal distribution')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-8 col-xl-6">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h3 mb-0">Meal distribution</h1>
            <a href="{{ route('till') }}" class="btn btn-outline-secondary btn-sm">Regular till</a>
        </div>

        @if ($mealTypes->isEmpty())
            <div class="alert alert-warning">No active meal types are configured. Ask an administrator to set them up.</div>
        @else
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <div id="feedback" class="alert d-none" role="status" aria-live="polite"></div>
                    @unless ($manualEnabled)
                        <div class="alert alert-info">Manual entry is disabled by configuration. RFID distribution remains available.</div>
                    @endunless

                    <form id="distributionForm" autocomplete="off">
                        <div class="mb-3">
                            <label class="form-label" for="identification-method">How is the student identified?</label>
                            <select id="identification-method" class="form-select form-select-lg">
                                <option value="rfid">RFID scan</option>
                                @if ($manualEnabled)
                                    <option value="manual">Manual entry</option>
                                @endif
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="identifier-type">Identifier type</label>
                            <select id="identifier-type" class="form-select form-select-lg">
                                <option value="rfid_card_id">RFID Card ID</option>
                                @if ($manualEnabled)
                                    <option value="student_id">Student ID</option>
                                @endif
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="meal-type">Meal type</label>
                            <select id="meal-type" class="form-select form-select-lg" required>
                                @foreach ($mealTypes as $mealType)
                                    <option value="{{ $mealType->id }}">{{ $mealType->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-4">
                            <label class="form-label" for="identifier">Identifier</label>
                            <input id="identifier" type="text" class="form-control form-control-lg font-monospace" maxlength="80" autocomplete="off" autocapitalize="off" spellcheck="false" autofocus required>
                            <div id="identifierHelp" class="form-text">Focus this field and scan an RFID card, or enter the card ID.</div>
                        </div>
                        <button id="collectButton" type="submit" class="btn btn-primary btn-lg w-100">Collect meal</button>
                    </form>
                </div>
            </div>

            <p class="text-muted small mt-3 mb-0">Requires a live connection to validate the subscription and record the collection. Meal collections are never queued offline.</p>
        @endif
    </div>
</div>
@endsection

@if ($mealTypes->isNotEmpty())
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('distributionForm');
    const method = document.getElementById('identification-method');
    const type = document.getElementById('identifier-type');
    const input = document.getElementById('identifier');
    const help = document.getElementById('identifierHelp');
    const feedback = document.getElementById('feedback');
    const button = document.getElementById('collectButton');
    const url = @json(route('till.meals.collect'));
    let key = newKey();

    function newKey() {
        return 'meal-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
    }

    function syncIdentifierOptions() {
        if (method.value === 'rfid') {
            type.value = 'rfid_card_id';
            type.disabled = true;
            help.textContent = 'Focus this field and scan an RFID card, or enter the card ID.';
        } else {
            type.disabled = false;
            help.textContent = type.value === 'student_id'
                ? 'Enter the student ID exactly as recorded, including any leading zeros.'
                : 'Enter the RFID Card ID exactly as printed or encoded.';
        }
    }

    function showFeedback(message, success) {
        feedback.textContent = message;
        feedback.className = 'alert ' + (success ? 'alert-success' : 'alert-danger');
    }

    method.addEventListener('change', syncIdentifierOptions);
    type.addEventListener('change', syncIdentifierOptions);
    syncIdentifierOptions();

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        button.disabled = true;
        button.textContent = 'Checking eligibility…';
        feedback.className = 'alert alert-info';
        feedback.textContent = 'Checking eligibility…';

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({
                    meal_type_id: document.getElementById('meal-type').value,
                    identifier_type: type.value,
                    identification_method: method.value,
                    identifier: input.value,
                    key: key
                })
            });
            const result = await response.json();

            if (!response.ok) {
                showFeedback(result.message || 'Meal collection was rejected.', false);
                return;
            }

            showFeedback(
                result.student.name + ' · ' + result.meal_type + ' collected. '
                    + result.remaining_entitlements + ' entitlements remaining.',
                true
            );
            form.reset();
            key = newKey();
            syncIdentifierOptions();
            input.focus();
        } catch (error) {
            showFeedback('Could not reach the server. Retry when the connection is restored.', false);
        } finally {
            button.disabled = false;
            button.textContent = 'Collect meal';
        }
    });
});
</script>
@endpush
@endif

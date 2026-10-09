@extends('layouts.app')
@section('title', 'Top-ups')

@section('content')
<h1 class="h3 mb-3">Top-ups</h1>

@if ($errors->any())
    <div class="alert alert-danger">
        @foreach ($errors->all() as $error)
            <div>{{ $error }}</div>
        @endforeach
    </div>
@endif

@if ($students->isNotEmpty())
    <div class="card shadow-sm mb-4 border-warning">
        <div class="card-header fw-semibold">Start a test top-up <span class="badge bg-warning text-dark">development only</span></div>
        <div class="card-body">
            <form method="POST" action="{{ route('admin.topups.store') }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-5">
                    <label class="form-label" for="student_id">Student</label>
                    <select id="student_id" name="student_id" class="form-select" required>
                        @foreach ($students as $student)
                            <option value="{{ $student->id }}" @selected(old('student_id') == $student->id)>{{ $student->name }} ({{ $student->student_code }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="amount">Amount</label>
                    <input id="amount" name="amount" type="number" min="0" step="0.01" value="{{ old('amount') }}" class="form-control" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="currency">Pay in</label>
                    <select id="currency" name="currency" class="form-select">
                        <option value="USD">USD</option>
                        <option value="KHR">KHR</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary w-100">Start</button>
                </div>
            </form>
        </div>
    </div>
@endif

<div class="table-responsive">
    <table class="table table-hover align-middle bg-white">
        <thead class="table-light">
            <tr>
                <th>Time</th>
                <th>Student</th>
                <th class="text-end">Credited</th>
                <th class="text-end">Paid with</th>
                <th>Gateway</th>
                <th>Status</th>
                <th>Reference</th>
            </tr>
        </thead>
        <tbody>
        @php
            $badges = ['paid' => 'bg-success', 'pending' => 'bg-warning text-dark', 'failed' => 'bg-danger', 'review' => 'bg-danger', 'expired' => 'bg-secondary'];
        @endphp
        @forelse ($topups as $topup)
            <tr>
                <td class="text-nowrap">{{ $topup->created_at->timezone($topup->school->timezone)->format('Y-m-d H:i') }}</td>
                <td>{{ $topup->student->name }} <span class="text-muted">({{ $topup->student->student_code }})</span></td>
                <td class="text-end">{{ \App\Support\Money::format($topup->amount, $topup->school->currency) }}</td>
                <td class="text-end">{{ \App\Support\Money::format($topup->pay_amount, $topup->pay_currency) }}</td>
                <td>{{ $topup->gateway }}</td>
                <td>
                    <span class="badge {{ $badges[$topup->status] ?? 'bg-secondary' }}">
                        {{ $topup->status === 'review' ? 'needs review' : $topup->status }}
                    </span>
                    @if ($topup->failure_reason)
                        <div class="small text-muted">{{ $topup->failure_reason }}</div>
                    @endif
                </td>
                <td><code>{{ $topup->gateway_ref }}</code></td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-center text-muted py-4">No top-ups yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

{{ $topups->links() }}
@endsection

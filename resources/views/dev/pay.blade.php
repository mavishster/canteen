@extends('layouts.app')
@section('title', 'Fake bank')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="alert alert-warning">This is a pretend bank page for development. It does not exist in production.</div>

        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h4 mb-3">Pay {{ \App\Support\Money::format($topup->pay_amount, $topup->pay_currency) }}</h1>
                <dl class="row mb-4">
                    <dt class="col-5">Student</dt><dd class="col-7">{{ $topup->student->name }}</dd>
                    <dt class="col-5">Will be credited</dt><dd class="col-7">{{ \App\Support\Money::format($topup->amount, $topup->school->currency) }}</dd>
                    <dt class="col-5">Reference</dt><dd class="col-7"><code>{{ $topup->gateway_ref }}</code></dd>
                    <dt class="col-5">Status</dt><dd class="col-7">{{ $topup->status }}</dd>
                </dl>

                @if ($topup->status === 'pending')
                    <div class="d-grid gap-2">
                        <form method="POST" action="{{ route('dev.pay.complete', ['ref' => $topup->gateway_ref, 'outcome' => 'paid']) }}">
                            @csrf
                            <button class="btn btn-success btn-lg w-100">Simulate: payment succeeded</button>
                        </form>
                        <form method="POST" action="{{ route('dev.pay.complete', ['ref' => $topup->gateway_ref, 'outcome' => 'failed']) }}">
                            @csrf
                            <button class="btn btn-outline-danger w-100">Simulate: payment failed</button>
                        </form>
                    </div>
                @else
                    <a href="{{ route('admin.topups') }}" class="btn btn-primary">Back to top-ups</a>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection

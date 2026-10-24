@extends('layouts.app')
@section('title', __('Import cards'))

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">{{ __('Import cards') }}</h1>
    <a href="{{ url('/admin/students') }}" class="btn btn-outline-secondary btn-sm">{{ __('← Students') }}</a>
</div>

@if ($errors->any())
    <div class="alert alert-danger">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
@endif

<div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold">{{ __('How it works') }}</div>
    <div class="card-body">
        <ol class="mb-3">
            <li>{{ __('Download the list of students who have no card yet. Each line has the student number; the card column is empty.') }}</li>
            <li>{{ __('Click the card cell of a student, then tap that student\'s card on the reader: the reader types the card number into the cell. Move down to the next student and repeat.') }}</li>
            <li>{{ __('Save the file as CSV and upload it below. You will see a check first: nothing is changed until you confirm.') }}</li>
        </ol>
        <a class="btn btn-outline-primary" href="{{ route('admin.cards.missing') }}">{{ __('Download the list of students without a card') }}</a>
    </div>
</div>

<form method="POST" action="{{ route('admin.cards.import.preview') }}" enctype="multipart/form-data" class="card shadow-sm">
    @csrf
    <div class="card-header fw-semibold">{{ __('Upload the file') }}</div>
    <div class="card-body">
        <div class="mb-3">
            <label class="form-label" for="file">{{ __('File (CSV)') }}</label>
            <input id="file" name="file" type="file" accept=".csv,.txt,text/csv,text/plain" class="form-control">
        </div>
        <div class="mb-3">
            <label class="form-label" for="paste">{{ __('or paste the lines here') }}</label>
            <textarea id="paste" name="paste" rows="6" class="form-control font-monospace" placeholder="{{ __('student number, card number') }}">{{ old('paste') }}</textarea>
        </div>
        <div class="form-text">
            {{ __('One student per line: the student number, then the card number. A first line with column titles is fine. Commas, semicolons and tabs all work.') }}
            {{ __('Use the number the reader types when the card is tapped.') }}
        </div>
    </div>
    <div class="card-footer"><button class="btn btn-primary">{{ __('Check the file') }}</button></div>
</form>
@endsection

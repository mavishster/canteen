@extends('layouts.app')
@section('title', 'Parent consent')

@section('content')
<h1 class="h3 mb-3">Parent consent</h1>

@if ($errors->any())
    <div class="alert alert-danger">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
@endif

<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card shadow-sm {{ $required ? 'border-success' : 'border-warning' }}"><div class="card-body">
        <div class="text-muted small">Consent is</div>
        <div class="fs-4">{{ $required ? 'Compulsory' : 'Not compulsory' }}</div>
        <form method="POST" action="{{ route('admin.consent.toggle') }}" class="mt-2"
              onsubmit="return confirm('{{ $required ? 'Stop asking parents to accept?' : 'From now on parents must accept the newest text to use the app. Continue?' }}')">
            @csrf
            <button class="btn btn-sm {{ $required ? 'btn-outline-secondary' : 'btn-success' }}">{{ $required ? 'Switch off' : 'Make compulsory' }}</button>
        </form>
    </div></div></div>
    <div class="col-md-4"><div class="card shadow-sm"><div class="card-body">
        <div class="text-muted small">Current text</div>
        <div class="fs-4">{{ $document ? 'Version ' . $document->version : 'None published' }}</div>
    </div></div></div>
    <div class="col-md-4"><div class="card shadow-sm"><div class="card-body">
        <div class="text-muted small">Accepted the current text</div>
        <div class="fs-4">{{ $acceptedCount }} of {{ $total }} parents have accepted</div>
    </div></div></div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold">{{ $document ? 'Publish a new version' : 'Publish the first text' }}</div>
    <div class="card-body">
        <div class="alert alert-warning small">
            The text below is only a starting point. Have the school (and a legal adviser) read and rewrite it, in the language parents read, before you publish.
        </div>
        <form method="POST" action="{{ route('admin.consent.publish') }}">
            @csrf
            <textarea name="text" rows="14" class="form-control mb-2" minlength="100" maxlength="20000" required>{{ $draft }}</textarea>
            <button class="btn btn-primary">Publish {{ $document ? 'version ' . ($document->version + 1) : 'version 1' }}</button>
            <span class="form-text ms-2">
                A published text cannot be edited. A change is a new version, and
                @if ($required) every parent is asked to accept it again. @else parents will be asked again once consent is compulsory. @endif
            </span>
        </form>
    </div>
</div>

@if ($document)
    <div class="row g-4">
        <div class="col-lg-6">
            <h2 class="h5">Not accepted yet (version {{ $document->version }})</h2>
            <table class="table table-sm bg-white align-middle">
                <tbody>
                @forelse ($pending as $parent)
                    <tr>
                        <td>{{ $parent->name }}</td>
                        <td class="text-muted small">{{ str_ends_with($parent->email, '.invalid') ? 'signs in through the school system' : $parent->email }}</td>
                    </tr>
                @empty
                    <tr><td class="text-center text-muted py-3">{{ $total === 0 ? 'No parents yet.' : 'Everyone has accepted.' }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="col-lg-6">
            <h2 class="h5">Versions</h2>
            <table class="table table-sm bg-white align-middle">
                <thead class="table-light"><tr><th>Version</th><th>Published</th><th class="text-end">Accepted by</th></tr></thead>
                <tbody>
                @foreach ($versions as $version)
                    <tr>
                        <td>{{ $version->version }}</td>
                        <td>{{ $version->created_at->timezone($school->timezone)->format('Y-m-d H:i') }}</td>
                        <td class="text-end">{{ $counts[$version->id] ?? 0 }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection

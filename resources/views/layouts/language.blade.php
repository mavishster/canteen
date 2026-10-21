@php $locales = (array) config('locales.supported', []); @endphp
@if (count($locales) > 1)
    <li class="nav-item dropdown ms-lg-2">
        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">{{ $locales[app()->getLocale()] ?? app()->getLocale() }}</a>
        <ul class="dropdown-menu">
            @foreach ($locales as $code => $name)
                <li>
                    <form method="POST" action="{{ route('locale.set') }}">
                        @csrf
                        <input type="hidden" name="locale" value="{{ $code }}">
                        <button class="dropdown-item {{ app()->getLocale() === $code ? 'active' : '' }}">{{ $name }}</button>
                    </form>
                </li>
            @endforeach
        </ul>
    </li>
@endif

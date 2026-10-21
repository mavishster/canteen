@php $menu = app(\App\Support\Menu::class)->for(auth()->user()); @endphp
@foreach ($menu as $entry)
    @if ($entry['type'] === 'link')
        <li class="nav-item">
            <a class="nav-link {{ $entry['active'] ? 'active' : '' }}" href="{{ $entry['url'] }}">{{ $entry['label'] }}</a>
        </li>
    @else
        <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle {{ $entry['active'] ? 'active' : '' }}" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">{{ $entry['label'] }}</a>
            <ul class="dropdown-menu">
                @foreach ($entry['items'] as $item)
                    <li><a class="dropdown-item {{ $item['active'] ? 'active' : '' }}" href="{{ $item['url'] }}">{{ $item['label'] }}</a></li>
                @endforeach
            </ul>
        </li>
    @endif
@endforeach

@include('layouts.language')

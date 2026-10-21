@php $pendingRefunds = \App\Models\SaleRefund::where('status', 'pending')->count(); @endphp
<ul class="nav nav-pills mb-4 flex-wrap">
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('manager.reports.daily') ? 'active' : '' }}" href="{{ route('manager.reports.daily') }}">{{ __('Daily sales') }}</a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('manager.reports.meals') ? 'active' : '' }}" href="{{ route('manager.reports.meals') }}">{{ __('Meals') }}</a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('manager.reports.topups') ? 'active' : '' }}" href="{{ route('manager.reports.topups') }}">{{ __('Top-ups') }}</a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('manager.reports.balances') ? 'active' : '' }}" href="{{ route('manager.reports.balances') }}">{{ __('Balances') }}</a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('manager.reports.reconciliation') ? 'active' : '' }}" href="{{ route('manager.reports.reconciliation') }}">{{ __('Reconciliation') }}</a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('manager.refunds') ? 'active' : '' }}" href="{{ route('manager.refunds') }}">
            {{ __('Refunds') }}
            @if ($pendingRefunds > 0)
                <span class="badge bg-danger ms-1">{{ $pendingRefunds }}</span>
            @endif
        </a>
    </li>
</ul>

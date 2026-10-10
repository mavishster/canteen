@php $pendingRefunds = \App\Models\SaleRefund::where('status', 'pending')->count(); @endphp
<ul class="nav nav-pills mb-4">
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('manager.reports.daily') ? 'active' : '' }}" href="{{ route('manager.reports.daily') }}">Daily sales</a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('manager.reports.reconciliation') ? 'active' : '' }}" href="{{ route('manager.reports.reconciliation') }}">Reconciliation</a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('manager.refunds') ? 'active' : '' }}" href="{{ route('manager.refunds') }}">
            Refunds
            @if ($pendingRefunds > 0)
                <span class="badge bg-danger ms-1">{{ $pendingRefunds }}</span>
            @endif
        </a>
    </li>
</ul>

<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use App\Models\User;
use App\Models\Vehicle;

new #[Layout('layouts.admin-layout')] class extends Component
{
    // ===================== KPI CARDS =====================

    #[Computed]
    public function totalOperators()
    {
        return User::where('role', 'operator')->count();
    }

    #[Computed]
    public function totalVehicles()
    {
        return Vehicle::count();
    }

    // ===================== OPERATOR DOCUMENT EXPIRY =====================

    /**
     * Every vehicle whose franchise is already expired or expires within
     * 30 days. Most overdue first. No limit, so nothing gets hidden.
     */
    #[Computed]
    public function expiringOperatorDocs()
    {
        $soon = today()->addDays(30)->endOfDay();

        return Vehicle::with('user:id,name,phone_number')
            ->whereNotNull('franchise_expiry_date')
            ->where('franchise_expiry_date', '<=', $soon)
            ->orderBy('franchise_expiry_date', 'asc')
            ->get(['id', 'user_id', 'plate_number', 'vehicle_type', 'franchise_expiry_date']);
    }

    #[Computed]
    public function expiredCount()
    {
        return $this->expiringOperatorDocs
            ->filter(fn ($v) => $v->franchise_expiry_date->lt(today()))
            ->count();
    }

    #[Computed]
    public function expiringSoonCount()
    {
        return $this->expiringOperatorDocs->count() - $this->expiredCount;
    }

    // ===================== LIVE REFRESH =====================

    #[On('echo:user-info-updated,.UserInfoUpdated')]
    #[On('echo:vehicle-queue,.QueuedVehicleEvent')]
    public function refreshDashboard()
    {
        unset(
            $this->totalOperators,
            $this->totalVehicles,
            $this->expiringOperatorDocs,
            $this->expiredCount,
            $this->expiringSoonCount,
        );
    }
};
?>

<div>
    <style>
        .zone-bar { width: 4px; height: 1.1rem; border-radius: 2px; }
        .zone-rule { border: none; border-top: 1.5px dashed; opacity: 0.35; margin-top: 0.5rem; margin-bottom: 1rem; }
    </style>

    {{-- ===================== PAGE HEADER ===================== --}}
    <x-page-header heading="System overview and key metrics" />

    {{-- Mobile-visible heading, since the page header above is desktop-only --}}
    <div class="sm:hidden mb-4 pb-4 border-b border-light-bd-default dark:border-dark-bd-default">
        <x-heading
            size="xl"
            class="!font-primary !font-bold !text-light-txt-primary dark:!text-dark-txt-primary"
            style="font-size: var(--text-page-title)"
        >
            My Dashboard
        </x-heading>
    </div>

    {{-- ===================== ZONE: OVERVIEW ===================== --}}
    <div class="mt-6 flex items-center gap-2.5 text-light-txt-primary dark:text-dark-txt-primary">
        <span class="zone-bar bg-primary dark:bg-dark-txt-primary"></span>
        <span class="font-secondary text-nav-label font-bold uppercase tracking-widest">Overview</span>
    </div>
    <hr class="zone-rule border-light-bd-default dark:border-dark-bd-default">

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 sm:gap-3 mb-8">
        <flux:card class="p-3 sm:p-4">
            <div class="flex items-center justify-between gap-2">
                <div class="flex items-center justify-center w-8 h-8 sm:w-10 sm:h-10 rounded-xl bg-primary/10 dark:bg-primary/20 shrink-0">
                    <flux:icon.users class="w-4 h-4 sm:w-5 sm:h-5 text-primary dark:text-dark-txt-primary" />
                </div>
            </div>
            <x-text class="font-secondary text-xs sm:text-stat-label font-medium text-light-txt-body dark:text-dark-txt-body block mt-2.5 sm:mt-3">Total operators</x-text>
            <x-text class="font-primary text-xl sm:text-stat-value font-bold tabular-nums text-light-txt-primary dark:text-dark-txt-primary block mt-0.5">
                {{ number_format($this->totalOperators) }}
            </x-text>
        </flux:card>

        <flux:card class="p-3 sm:p-4">
            <div class="flex items-center justify-between gap-2">
                <div class="flex items-center justify-center w-8 h-8 sm:w-10 sm:h-10 rounded-xl bg-info/10 dark:bg-dark-info/20 shrink-0">
                    <flux:icon.truck class="w-4 h-4 sm:w-5 sm:h-5 text-info dark:text-dark-info" />
                </div>
            </div>
            <x-text class="font-secondary text-xs sm:text-stat-label font-medium text-light-txt-body dark:text-dark-txt-body block mt-2.5 sm:mt-3">Total vehicles</x-text>
            <x-text class="font-primary text-xl sm:text-stat-value font-bold tabular-nums text-info dark:text-dark-info block mt-0.5">
                {{ number_format($this->totalVehicles) }}
            </x-text>
        </flux:card>
    </div>

    {{-- ===================== ZONE: DOCUMENT EXPIRY ===================== --}}
    <div class="flex items-center gap-2.5 text-light-txt-primary dark:text-dark-txt-primary">
        <span class="zone-bar bg-danger dark:bg-dark-danger"></span>
        <span class="font-secondary text-nav-label font-bold uppercase tracking-widest">Operator Documents</span>
    </div>
    <hr class="zone-rule border-light-bd-default dark:border-dark-bd-default">

    <flux:card class="p-0 overflow-hidden">
        <div class="px-4 pt-4 flex flex-wrap items-center justify-between gap-2">
            <x-text class="font-secondary text-sm sm:text-card-title font-semibold text-light-txt-primary dark:text-dark-txt-primary">
                Expiring &amp; expired documents
            </x-text>
            <div class="flex items-center gap-1.5">
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-badge font-medium bg-danger/10 text-danger dark:bg-dark-danger/15 dark:text-dark-danger">
                    {{ $this->expiredCount }} expired
                </span>
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-badge font-medium bg-warning/10 text-warning dark:bg-dark-warning/15 dark:text-dark-warning">
                    {{ $this->expiringSoonCount }} expiring in 30d
                </span>
            </div>
        </div>

        <div class="overflow-x-auto mt-2">
            <table class="w-full font-secondary text-table-row">
                <thead>
                    <tr class="text-left text-light-txt-body dark:text-dark-txt-body border-b border-light-bd-default dark:border-dark-bd-default">
                        <th class="py-2 px-4 font-semibold">Operator</th>
                        <th class="py-2 px-4 font-semibold hidden sm:table-cell">Contact</th>
                        <th class="py-2 px-4 font-semibold">Plate</th>
                        <th class="py-2 px-4 font-semibold">Document</th>
                        <th class="py-2 px-4 font-semibold hidden md:table-cell">Expiry date</th>
                        <th class="py-2 px-4 font-semibold text-right">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->expiringOperatorDocs as $vehicle)
                        @php $daysLeft = today()->diffInDays($vehicle->franchise_expiry_date, false); @endphp
                        <tr class="border-b border-light-bd-default/50 dark:border-dark-bd-default/50 last:border-0">
                            <td class="py-2.5 px-4 font-semibold text-light-txt-primary dark:text-dark-txt-primary">
                                {{ $vehicle->user->name ?? 'Unassigned' }}
                            </td>
                            <td class="py-2.5 px-4 text-light-txt-muted dark:text-dark-txt-muted hidden sm:table-cell">
                                {{ $vehicle->user->phone_number ?? '—' }}
                            </td>
                            <td class="py-2.5 px-4 text-light-txt-body dark:text-dark-txt-body">
                                {{ $vehicle->plate_number }}
                            </td>
                            <td class="py-2.5 px-4 text-light-txt-body dark:text-dark-txt-body">Franchise</td>
                            <td class="py-2.5 px-4 text-light-txt-muted dark:text-dark-txt-muted hidden md:table-cell whitespace-nowrap">
                                {{ $vehicle->franchise_expiry_date->format('M d, Y') }}
                            </td>
                            <td class="py-2.5 px-4 text-right whitespace-nowrap">
                                <span @class([
                                    'inline-flex items-center px-2 py-0.5 rounded-full text-badge font-medium',
                                    'bg-danger/10 text-danger dark:bg-dark-danger/15 dark:text-dark-danger' => $daysLeft < 0,
                                    'bg-warning/10 text-warning dark:bg-dark-warning/15 dark:text-dark-warning' => $daysLeft >= 0,
                                ])>
                                    {{ $daysLeft < 0 ? 'Expired ' . abs($daysLeft) . 'd ago' : ($daysLeft === 0 ? 'Expires today' : "Expires in {$daysLeft}d") }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center text-light-txt-muted dark:text-dark-txt-muted">
                                No documents expiring or expired.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="h-2"></div>
    </flux:card>
</div>
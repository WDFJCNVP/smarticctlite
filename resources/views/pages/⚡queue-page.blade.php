<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use App\Jobs\ProcessAfterDepart;
use App\Models\Queue;

new class extends Component {

    public string $search = '';
    public string $vehicleType = '';
    public string $route = '';

    public bool $show_dispatch_modal = false;
    public ?int $pendingQueueId = null;
    public string $pendingQueuePlate = '';

    /**
     * Vehicle type => [destination => fare].
     * Source: ICCT route/fare sheet.
     *
     * The destination keys must match the `destination` values saved in the
     * queues table (matching is case-insensitive). Any destination that is in
     * the DB but NOT listed here is still rendered, as an extra route lane
     * (without a fare), so nothing ever disappears from the board.
     */
    protected function routeMap(): array
    {
        return [
            'Bus'        => ['Naga' => 70],
            'UV-express' => ['Naga' => 100, 'Legazpi' => 170],
            'Jeep'       => ['Baao' => 20, 'Buhi' => 34, 'Bato' => 35, 'Pili' => 50, 'Mountain-unit' => 70],
            'Multi-cab'  => ['Nabua' => 15, 'Baao' => 20],
        ];
    }

    public function updatedVehicleType(): void
    {
        // A route belongs to one vehicle type, so reset it when the type changes.
        $this->route = '';
    }

    public function confirmDispatch(int $queueId, string $plate): void
    {
        $this->pendingQueueId = $queueId;
        $this->pendingQueuePlate = $plate;
        $this->show_dispatch_modal = true;
    }

    protected function canManageQueue(): bool
    {
        return auth()->check() && in_array(auth()->user()->role, ['cashier', 'admin']);
    }

    public function dispatchVehicle($queueId)
    {
        $this->show_dispatch_modal = false;

        if (!$this->canManageQueue()) {
            return;
        }

        $queue = Queue::where('id', $queueId)
            ->where('status', 'loading')
            ->first();

        if (!$queue) {
            return;
        }

        $queue->update([
            'time_departed' => now(),
            'status' => 'departed'
        ]);

        ProcessAfterDepart::dispatch($queue->id);

        $this->refreshQueuedVehicleList();

        Flux::toast(
            duration: 4000,
            variant: 'success',
            heading: 'Vehicle dispatched',
            text: 'The vehicle has been dispatched.',
        );
    }

    /** All active queue rows (search applied, NOT filtered by type/route). */
    #[Computed]
    public function queues()
    {
        return Queue::whereIn('status', ['staging', 'loading'])
            ->when(
                $this->search,
                fn($q) => $q->where(function ($q2) {
                    $q2->where('destination', 'like', '%' . $this->search . '%')
                       ->orWhere('vehicle_type', 'like', '%' . $this->search . '%')
                       ->orWhere('plate_number', 'like', '%' . $this->search . '%')
                       ->orWhere('driver_name', 'like', '%' . $this->search . '%');
                })
            )
            ->orderByRaw("CASE status WHEN 'loading' THEN 1 WHEN 'staging' THEN 2 ELSE 3 END")
            ->orderBy('slot_position')
            ->orderBy('time_queued')
            ->get();
    }

    /** Vehicle types for the tab bar: known types first, then any unexpected ones from the DB. */
    #[Computed]
    public function tabTypes(): array
    {
        return array_values(array_unique(array_merge(
            array_keys($this->routeMap()),
            $this->queues->pluck('vehicle_type')->all()
        )));
    }

    #[Computed]
    public function typeCounts()
    {
        return $this->queues->countBy('vehicle_type');
    }

    /**
     * type => collection of routes:
     *   ['key' => 'buhi', 'label' => 'Buhi', 'fare' => 34, 'queues' => Collection]
     */
    #[Computed]
    public function board()
    {
        $map    = $this->routeMap();
        $byType = $this->queues->groupBy('vehicle_type');
        $types  = $this->vehicleType ? [$this->vehicleType] : $this->tabTypes;

        return collect($types)->mapWithKeys(function ($type) use ($map, $byType) {
            $configured = collect($map[$type] ?? [])->mapWithKeys(
                fn($fare, $dest) => [strtolower($dest) => ['name' => $dest, 'fare' => $fare]]
            );

            $byRoute = $byType->get($type, collect())
                ->groupBy(fn($q) => strtolower(trim($q->destination)));

            $routes = $configured->keys()
                ->merge($byRoute->keys())
                ->unique()
                ->values()
                ->map(function ($key) use ($configured, $byRoute) {
                    $cfg    = $configured->get($key);
                    $queues = $byRoute->get($key, collect())->values();

                    return [
                        'key'    => $key,
                        'label'  => Str::headline($cfg['name'] ?? $queues->first()->destination),
                        'fare'   => $cfg['fare'] ?? null,
                        'queues' => $queues,
                    ];
                });

            return [$type => $routes];
        });
    }

    #[On('echo:vehicle-queue,.QueuedVehicleEvent')]
    public function refreshQueuedVehicleList() {
        unset($this->queues, $this->tabTypes, $this->typeCounts, $this->board);
    }

    #[On('echo:trigger-depart-event,.TriggerDepartingEvent')]
    public function triggerDepartEvent($payload)
    {
        $queueId = $payload['vehicle']['id'] ?? $payload['id'] ?? null;
        if (!$queueId) return;

        $queue = Queue::where('id', $queueId)->lockForUpdate()->first();
        if (!$queue) return;

        if (($queue->vehicle_type === 'Jeep' && ($queue->destination === 'Buhi' || $queue->destination === 'Mountain-unit')) && $queue->id === $queue->id) {
            $queue->update(['departs_at' => Carbon::now()]);
            ProcessAfterDepart::dispatch($queue->id);

        } elseif ($queue->vehicle_type === 'UV-express') {

            $queue->update(['departs_at' => Carbon::now()->addMinutes(30)]);
            ProcessAfterDepart::dispatch($queue->id)->delay($queue->departs_at);

            $this->refreshQueuedVehicleList();
        }

        $this->refreshQueuedVehicleList();
    }

    public function render() {

        if(!auth()->user()) {
            return $this->view()->layout('layouts.public-layout');
        }

        $role = auth()->user()->role;

        return $this->view()->layout('layouts.' . $role . '-layout');
    }

};
?>

<div>
    <x-page-header
        heading="Live Queue"
        class="mb-4"
    >
        {{-- Empty slot – no buttons inside the header --}}
    </x-page-header>

    @if ($this->canManageQueue())
        <div class="hidden sm:flex sm:items-center sm:justify-between gap-3 mb-6">
            <x-heading
                class="!font-primary !font-bold !text-light-txt-primary dark:!text-dark-txt-primary"
                style="font-size: var(--text-section-heading)"
            >
                Monitor the terminal's queueing
            </x-heading>

            <div class="flex items-center justify-end gap-2 w-full sm:w-auto">
                <flux:link href="{{ route('cashier.queue.vehicle') }}" wire:navigate class="w-full sm:w-auto">
                    <flux:button variant="primary" icon="plus" size="sm" class="font-secondary w-full sm:w-auto justify-center bg-primary text-white hover:bg-primary-hover dark:bg-secondary dark:text-[var(--color-dark-primary)] dark:hover:bg-secondary-hover">
                        Queue Vehicle
                    </flux:button>
                </flux:link>

                <flux:link href="{{ route('cashier.active-group') }}" wire:navigate class="w-full sm:w-auto">
                    <flux:button variant="primary" icon="plus" size="sm" class="font-secondary w-full sm:w-auto justify-center bg-primary text-white hover:bg-primary-hover dark:bg-secondary dark:text-[var(--color-dark-primary)] dark:hover:bg-secondary-hover">
                        View Active Groups
                    </flux:button>
                </flux:link>
            </div>
        </div>
    @endif

    {{-- Search --}}
    <div class="mb-4">
        <flux:input
            type="text"
            wire:model.live.debounce.300ms="search"
            placeholder="Search route, plate or driver..."
            class="w-full font-secondary text-table-row dark:bg-dark-secondary dark:border-dark-bd-default dark:text-dark-txt-primary"
            icon="magnifying-glass"
        />
    </div>

    {{-- Level 1: vehicle type tabs --}}
    <div class="-mx-4 px-4 sm:mx-0 sm:px-0 overflow-x-auto pb-1">
        <div class="flex items-center gap-2 w-max sm:w-auto">
            @php $totalCount = $this->queues->count(); @endphp

            <button
                type="button"
                wire:click="$set('vehicleType', '')"
                @class([
                    'inline-flex items-center gap-2 whitespace-nowrap rounded-full px-4 py-2 font-secondary text-sm font-semibold transition',
                    'bg-primary text-white shadow-sm' => $vehicleType === '',
                    'bg-light-secondary dark:bg-dark-secondary border border-light-bd-default dark:border-dark-bd-default text-light-txt-body dark:text-dark-txt-body hover:border-primary' => $vehicleType !== '',
                ])
            >
                All vehicles
                <span @class([
                    'rounded-full px-2 py-0.5 text-xs tabular-nums',
                    'bg-white/20' => $vehicleType === '',
                    'bg-light-subtle dark:bg-dark-subtle' => $vehicleType !== '',
                ])>{{ $totalCount }}</span>
            </button>

            @foreach ($this->tabTypes as $type)
                <button
                    type="button"
                    wire:key="tab-{{ $type }}"
                    wire:click="$set('vehicleType', '{{ $type }}')"
                    @class([
                        'inline-flex items-center gap-2 whitespace-nowrap rounded-full px-4 py-2 font-secondary text-sm font-semibold transition',
                        'bg-primary text-white shadow-sm' => $vehicleType === $type,
                        'bg-light-secondary dark:bg-dark-secondary border border-light-bd-default dark:border-dark-bd-default text-light-txt-body dark:text-dark-txt-body hover:border-primary' => $vehicleType !== $type,
                    ])
                >
                    {{ $type }}
                    <span @class([
                        'rounded-full px-2 py-0.5 text-xs tabular-nums',
                        'bg-white/20' => $vehicleType === $type,
                        'bg-light-subtle dark:bg-dark-subtle' => $vehicleType !== $type,
                    ])>{{ $this->typeCounts->get($type, 0) }}</span>
                </button>
            @endforeach
        </div>
    </div>

    {{-- Level 2: route chips (only when a single vehicle type is selected) --}}
    @if ($vehicleType && $this->board->has($vehicleType))
        <div class="-mx-4 px-4 sm:mx-0 sm:px-0 overflow-x-auto mt-3 pb-1">
            <div class="flex items-center gap-2 w-max sm:w-auto">
                <span class="font-secondary text-xs font-semibold uppercase tracking-wide text-light-txt-muted dark:text-dark-txt-muted pr-1">Route</span>

                <button
                    type="button"
                    wire:click="$set('route', '')"
                    @class([
                        'whitespace-nowrap rounded-lg px-3 py-1.5 font-secondary text-xs font-semibold transition',
                        'bg-secondary text-[var(--color-dark-primary)]' => $route === '',
                        'bg-light-subtle dark:bg-dark-subtle text-light-txt-body dark:text-dark-txt-body hover:bg-light-bd-default dark:hover:bg-dark-bd-default' => $route !== '',
                    ])
                >
                    All routes
                </button>

                @foreach ($this->board[$vehicleType] as $r)
                    <button
                        type="button"
                        wire:key="chip-{{ $r['key'] }}"
                        wire:click="$set('route', '{{ $r['key'] }}')"
                        @class([
                            'inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-1.5 font-secondary text-xs font-semibold transition',
                            'bg-secondary text-[var(--color-dark-primary)]' => $route === $r['key'],
                            'bg-light-subtle dark:bg-dark-subtle text-light-txt-body dark:text-dark-txt-body hover:bg-light-bd-default dark:hover:bg-dark-bd-default' => $route !== $r['key'],
                        ])
                    >
                        {{ $r['label'] }}
                        <span class="tabular-nums opacity-70">{{ $r['queues']->count() }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Board: one section per vehicle type, one lane per route --}}
    <div class="mt-6 space-y-8">
        @foreach ($this->board as $type => $routes)
            @php
                $visible = $route ? $routes->where('key', $route) : $routes;
                $active  = $visible->filter(fn($r) => $r['queues']->isNotEmpty() || $route);
                $idle    = $visible->reject(fn($r) => $r['queues']->isNotEmpty() || $route);
                $typeLoading = $routes->sum(fn($r) => $r['queues']->where('status', 'loading')->count());
                $typeTotal   = $routes->sum(fn($r) => $r['queues']->count());
            @endphp

            <section wire:key="type-{{ $type }}" class="space-y-3">

                {{-- Section header (only in "All vehicles" view; tabs already name the type otherwise) --}}
                @unless ($vehicleType)
                    <div class="flex items-center gap-3">
                        <h2 class="font-primary text-xl font-bold text-light-txt-primary dark:text-dark-txt-primary">{{ $type }}</h2>
                        <span class="font-secondary text-sm text-light-txt-muted dark:text-dark-txt-muted">
                            {{ $typeTotal }} in queue
                            @if ($typeLoading) · <span class="text-success dark:text-dark-success font-semibold">{{ $typeLoading }} loading</span> @endif
                        </span>
                        <div class="flex-1 h-px bg-light-bd-default dark:bg-dark-bd-default"></div>
                    </div>
                @endunless

                {{-- Route lanes (only routes that have vehicles) --}}
                @if ($active->isNotEmpty())
                    <div class="grid grid-cols-1 gap-4 items-start sm:grid-cols-[repeat(auto-fill,minmax(18rem,1fr))]">
                        @foreach ($active as $r)
                            <div wire:key="lane-{{ $type }}-{{ $r['key'] }}" class="flex flex-col gap-3 min-w-0">

                                {{-- Lane header --}}
                                <div class="flex items-center justify-between gap-2 rounded-xl bg-primary px-4 py-2.5">
                                    <div class="min-w-0">
                                        <h3 class="font-primary text-base font-bold text-white truncate">{{ $r['label'] }}</h3>
                                        <span class="font-secondary text-xs text-white/80">{{ $r['queues']->count() }} in queue</span>
                                    </div>
                                    @if ($r['fare'])
                                        <span class="shrink-0 rounded-full bg-white/15 px-2.5 py-1 font-secondary text-xs font-bold text-white tabular-nums">
                                            ₱{{ $r['fare'] }}
                                        </span>
                                    @endif
                                </div>

                                @forelse ($r['queues'] as $index => $queue)
                                    @php
                                        $isLoading = $queue->status === 'loading';
                                        $pct = $queue->seat_capacity > 0 ? round(($queue->seat_count / $queue->seat_capacity) * 100) : 0;
                                    @endphp

                                    <div
                                        wire:key="queue-{{ $queue->id }}-{{ $queue->departs_at?->timestamp }}-{{ $queue->status }}"
                                        @class([
                                            'rounded-2xl p-4',
                                            'border-4 border-success bg-success/5 dark:bg-dark-success/5 shadow-md' => $isLoading,
                                            'border border-light-bd-default dark:border-dark-bd-default bg-light-secondary dark:bg-dark-secondary' => !$isLoading,
                                        ])
                                    >
                                        {{-- Top row: position (within this route), queue number, status --}}
                                        <div class="flex items-center gap-3">
                                            <div @class([
                                                'flex items-center justify-center w-7 h-7 shrink-0 rounded-full font-secondary text-sm font-bold',
                                                'bg-success text-white' => $isLoading,
                                                'bg-light-subtle dark:bg-dark-subtle text-light-txt-muted dark:text-dark-txt-muted' => !$isLoading,
                                            ])>
                                                {{ $index + 1 }}
                                            </div>

                                            <span class="flex-1 font-mono text-2xl font-extrabold tracking-wide text-light-txt-primary dark:text-dark-txt-primary">
                                                {{ $queue->queue_number ?? '#' . $queue->id }}
                                            </span>

                                            @if ($isLoading)
                                                <span class="inline-flex items-center gap-1.5">
                                                    <span class="relative flex h-2 w-2">
                                                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-success opacity-75"></span>
                                                        <span class="relative inline-flex rounded-full h-2 w-2 bg-success"></span>
                                                    </span>
                                                    <span class="font-secondary text-xs font-bold uppercase tracking-wide text-success dark:text-dark-success">Loading</span>
                                                </span>
                                            @else
                                                <span class="font-secondary text-xs font-medium uppercase tracking-wide text-light-txt-muted dark:text-dark-txt-muted">Waiting</span>
                                            @endif
                                        </div>

                                        {{-- Plate, driver + timer (route is already in the lane header) --}}
                                        <div class="mt-3 flex items-end justify-between gap-3">
                                            <div class="min-w-0">
                                                <div class="font-mono text-lg font-semibold tracking-widest text-light-txt-primary dark:text-dark-txt-primary truncate">
                                                    {{ $queue->plate_number }}
                                                </div>
                                                <div class="font-secondary text-sm text-light-txt-body dark:text-dark-txt-body truncate">
                                                    {{ $queue->driver_name }}
                                                </div>
                                            </div>

                                            <div class="shrink-0">
                                                @if ($isLoading && $queue->departs_at)
                                                    <span
                                                        x-data="{
                                                            endTime: {{ $queue->departs_at->timestamp }} * 1000,
                                                            display: '--:--',
                                                            urgent: false,
                                                            intervalId: null,
                                                            init() { this.update(); this.intervalId = setInterval(() => this.update(), 1000); },
                                                            destroy() { clearInterval(this.intervalId); },
                                                            update() {
                                                                const remaining = this.endTime - Date.now();
                                                                if (remaining <= 0) { this.display = '00:00'; this.urgent = false; clearInterval(this.intervalId); return; }
                                                                this.urgent = remaining < 30000;
                                                                const m = String(Math.floor(remaining / 60000)).padStart(2, '0');
                                                                const s = String(Math.floor((remaining % 60000) / 1000)).padStart(2, '0');
                                                                this.display = m + ':' + s;
                                                            }
                                                        }"
                                                        :class="urgent
                                                            ? 'font-mono text-xl font-bold tabular-nums text-danger'
                                                            : 'font-mono text-xl font-bold tabular-nums text-warning'"
                                                        x-text="display"
                                                    ></span>
                                                @else
                                                    <span class="font-mono text-xl font-bold tabular-nums text-light-txt-muted dark:text-dark-txt-muted">--:--</span>
                                                @endif
                                            </div>
                                        </div>

                                        {{-- Seats --}}
       <!--                                  <div class="mt-3 flex items-center gap-2">
                                            <div class="flex-1 h-1.5 rounded-full bg-light-bd-default dark:bg-dark-bd-default overflow-hidden">
                                                <div class="h-full rounded-full {{ $pct >= 75 ? 'bg-danger' : 'bg-success' }} transition-all" style="width: {{ $pct }}%"></div>
                                            </div>
                                            <span class="font-secondary text-xs tabular-nums text-light-txt-muted dark:text-dark-txt-muted whitespace-nowrap">
                                                {{ $queue->seat_count }}/{{ $queue->seat_capacity }} seats
                                            </span>
                                        </div> -->

                                        {{-- Actions --}}
                                        @if ($isLoading && $this->canManageQueue())
                                            <div class="mt-3 pt-3 border-t border-light-bd-default dark:border-dark-bd-default">
                                                <flux:button
                                                    size="sm"
                                                    variant="primary"
                                                    wire:click="confirmDispatch({{ $queue->id }}, '{{ $queue->plate_number }}')"
                                                    class="w-full font-secondary"
                                                >
                                                    Dispatch
                                                </flux:button>
                                            </div>
                                        @endif
                                    </div>
                                @empty
                                    <div class="rounded-2xl border-2 border-dashed border-light-bd-strong dark:border-dark-bd-strong px-4 py-8 text-center">
                                        <span class="font-secondary text-sm text-light-txt-muted dark:text-dark-txt-muted">No vehicles in queue.</span>
                                    </div>
                                @endforelse
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="rounded-2xl border-2 border-dashed border-light-bd-strong dark:border-dark-bd-strong px-4 py-8 text-center">
                        <span class="font-secondary text-sm text-light-txt-muted dark:text-dark-txt-muted">No vehicles in queue.</span>
                    </div>
                @endif

                {{-- Idle routes: shown as quiet chips instead of empty columns --}}
                @if ($idle->isNotEmpty() && !$route)
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-secondary text-xs text-light-txt-muted dark:text-dark-txt-muted">No vehicles on:</span>
                        @foreach ($idle as $r)
                            <span
                                wire:key="idle-{{ $type }}-{{ $r['key'] }}"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-dashed border-light-bd-strong dark:border-dark-bd-strong px-2.5 py-1 font-secondary text-xs text-light-txt-muted dark:text-dark-txt-muted"
                            >
                                {{ $r['label'] }}
                                @if ($r['fare']) <span class="tabular-nums opacity-70">₱{{ $r['fare'] }}</span> @endif
                            </span>
                        @endforeach
                    </div>
                @endif
            </section>
        @endforeach
    </div>

    {{-- Dispatch vehicle confirmation --}}
    <flux:modal wire:model="show_dispatch_modal" :closable="false" class="w-[calc(100%-2rem)] max-w-xs sm:max-w-sm">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Dispatch vehicle?</flux:heading>
                <flux:text class="mt-2">Dispatch {{ $pendingQueuePlate }} now?</flux:text>
            </div>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button wire:click="dispatchVehicle({{ $pendingQueueId }})" wire:loading.attr="disabled" variant="primary">
                    Dispatch
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
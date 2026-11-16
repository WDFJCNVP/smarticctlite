<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Illuminate\Support\Carbon;
use App\Jobs\ProcessAfterDepart;
use App\Models\Queue;

new class extends Component {

    public string $search = '';
    public string $vehicleType = '';

    // Preferred column order; any other vehicle type is added after these.
    public array $types = ['Bus', 'UV-express', 'Jeep', 'Multi-cab'];

    public bool $show_dispatch_modal = false;
    public ?int $pendingQueueId = null;
    public string $pendingQueuePlate = '';

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

    #[Computed]
    public function columns()
    {
        $grouped = Queue::whereIn('status', ['staging', 'loading'])
            ->when(
                $this->vehicleType,
                fn($q) => $q->where('vehicle_type', $this->vehicleType)
            )
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
            ->get()
            ->groupBy('vehicle_type');

        $order = $this->vehicleType
            ? [$this->vehicleType]
            : array_values(array_unique(array_merge($this->types, $grouped->keys()->all())));

        return collect($order)->mapWithKeys(fn($type) => [$type => $grouped->get($type, collect())]);
    }

    #[On('echo:vehicle-queue,.QueuedVehicleEvent')]
    public function refreshQueuedVehicleList() {
        unset($this->columns);
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

    {{-- Search + filter --}}
    <div class="flex flex-col sm:flex-row sm:items-center gap-3 mb-6">
        <div class="flex-1">
            <flux:input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="Search route, plate or driver..."
                class="w-full font-secondary text-table-row dark:bg-dark-secondary dark:border-dark-bd-default dark:text-dark-txt-primary"
                icon="magnifying-glass"
            />
        </div>
        <div class="w-full sm:w-48">
            <flux:select wire:model.live="vehicleType" class="w-full font-secondary text-table-row dark:bg-dark-secondary dark:border-dark-bd-default dark:text-dark-txt-primary">
                <flux:select.option value="">All vehicles</flux:select.option>
                <flux:select.option value="Jeep">Jeep</flux:select.option>
                <flux:select.option value="Bus">Bus</flux:select.option>
                <flux:select.option value="Multi-cab">Multi-cab</flux:select.option>
                <flux:select.option value="UV-express">UV-express</flux:select.option>
            </flux:select>
        </div>
    </div>

    {{-- Queue board: one column per vehicle type --}}
    <div @class([
        'grid grid-cols-1 gap-4 items-start',
        'md:grid-cols-2 xl:grid-cols-4' => !$vehicleType,
        'max-w-xl' => $vehicleType,
    ])>
        @foreach ($this->columns as $type => $queues)
            <div class="flex flex-col gap-3 min-w-0">

                {{-- Column header --}}
                <div class="flex items-center justify-between rounded-xl bg-primary px-4 py-2.5">
                    <h2 class="font-primary text-lg font-bold text-white">{{ $type }}</h2>
                    <span class="font-secondary text-sm text-white/80">{{ $queues->count() }} in queue</span>
                </div>

                @forelse ($queues as $index => $queue)
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
                        {{-- Top row: position, queue number, status --}}
                        <div class="flex items-center gap-3">
                            <div @class([
                                'flex items-center justify-center w-7 h-7 shrink-0 rounded-full font-secondary text-sm font-bold',
                                'bg-success text-white' => $isLoading,
                                'bg-light-subtle dark:bg-dark-subtle text-light-txt-muted dark:text-dark-txt-muted' => !$isLoading,
                            ])>
                                {{ $index + 1 }}
                            </div>

                            <span class="flex-1 font-mono text-2xl font-extrabold tracking-wide text-light-txt-primary dark:text-dark-txt-primary">
                                {{ $queue->queue_number }}
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

                        {{-- Plate, driver, destination --}}
                        <div class="mt-3 flex items-end justify-between gap-3">
                            <div class="min-w-0">
                                <div class="font-mono text-lg font-semibold tracking-widest text-light-txt-primary dark:text-dark-txt-primary truncate">
                                    {{ $queue->plate_number }}
                                </div>
                                <div class="font-secondary text-sm text-light-txt-body dark:text-dark-txt-body truncate">
                                    {{ $queue->driver_name }}
                                </div>
                                <div class="font-secondary text-xs text-light-txt-muted dark:text-dark-txt-muted truncate">
                                    to {{ $queue->destination }}
                                </div>
                            </div>

                            {{-- Timer --}}
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
                        <div class="mt-3 flex items-center gap-2">
                            <div class="flex-1 h-1.5 rounded-full bg-light-bd-default dark:bg-dark-bd-default overflow-hidden">
                                <div class="h-full rounded-full {{ $pct >= 75 ? 'bg-danger' : 'bg-success' }} transition-all" style="width: {{ $pct }}%"></div>
                            </div>
                            <span class="font-secondary text-xs tabular-nums text-light-txt-muted dark:text-dark-txt-muted whitespace-nowrap">
                                {{ $queue->seat_count }}/{{ $queue->seat_capacity }} seats
                            </span>
                        </div>

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
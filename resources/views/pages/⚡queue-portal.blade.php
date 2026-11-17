<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Illuminate\Support\Str;
use App\Models\Queue;

new #[Layout('layouts.board-layout')] class extends Component {

    /**
     * Routes shown on the board, in display order (left to right).
     * Must match the `destination` values saved in the queues table
     * (case-insensitive). Destinations found in the DB that are not listed
     * here are appended automatically so nothing is ever hidden.
     */
    protected function routeOrder(): array
    {
        return ['Naga', 'Legazpi', 'Baao', 'Buhi', 'Bato', 'Pili', 'Nabua', 'Mountain-unit'];
    }

    /** Badge colours so the public can tell vehicle types apart at a glance. */
    public function typeBadgeClasses(string $type): string
    {
        return match (strtolower($type)) {
            'bus'        => 'bg-blue-100 text-blue-800 dark:bg-blue-500/20 dark:text-blue-300',
            'uv-express' => 'bg-violet-100 text-violet-800 dark:bg-violet-500/20 dark:text-violet-300',
            'jeep'       => 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-300',
            'multi-cab'  => 'bg-teal-100 text-teal-800 dark:bg-teal-500/20 dark:text-teal-300',
            default      => 'bg-zinc-200 text-zinc-800 dark:bg-zinc-500/20 dark:text-zinc-300',
        };
    }

    /**
     * One lane per route. Vehicle types are mixed inside a lane
     * (e.g. Naga has both Bus and UV-express).
     * Only vehicles that are currently LOADING are shown; staging is hidden.
     */
    #[Computed]
    public function lanes()
    {
        $loading = Queue::where('status', 'loading')
            ->orderBy('slot_position')
            ->orderBy('time_queued')
            ->get()
            ->groupBy(fn($q) => strtolower(trim($q->destination)));

        $configured = collect($this->routeOrder())
            ->mapWithKeys(fn($name) => [strtolower($name) => $name]);

        return $configured->keys()
            ->merge($loading->keys())
            ->unique()
            ->values()
            ->map(function ($key) use ($configured, $loading) {
                $vehicles = $loading->get($key, collect())->values();

                return [
                    'key'      => $key,
                    'label'    => Str::headline($configured->get($key) ?? $vehicles->first()->destination),
                    'vehicles' => $vehicles,
                ];
            });
    }

    #[On('echo:vehicle-queue,.QueuedVehicleEvent')]
    public function refreshBoard()
    {
        unset($this->lanes);
    }

    #[On('echo:trigger-depart-event,.TriggerDepartingEvent')]
    public function onDepartTriggered()
    {
        unset($this->lanes);
    }

};
?>

{{-- wire:poll is a safety net in case a websocket event is missed on an unattended screen --}}
<div wire:poll.30s class="flex min-h-screen flex-col p-3 sm:p-4 lg:p-6">

    {{-- Header --}}
    <header class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="font-primary text-3xl font-extrabold tracking-tight text-light-txt-primary dark:text-dark-txt-primary sm:text-4xl">
                Now Loading
            </h1>
            <p class="font-secondary text-sm text-light-txt-muted dark:text-dark-txt-muted sm:text-base">
                Iriga City Central Terminal
            </p>
        </div>

        <div
            x-data="{
                now: new Date(),
                init() { setInterval(() => this.now = new Date(), 1000); }
            }"
            class="text-right"
        >
            <div class="font-mono text-3xl font-bold tabular-nums text-light-txt-primary dark:text-dark-txt-primary sm:text-4xl"
                 x-text="now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })"></div>
            <div class="font-secondary text-sm text-light-txt-muted dark:text-dark-txt-muted"
                 x-text="now.toLocaleDateString([], { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })"></div>
        </div>
    </header>

    {{-- Board: one column per route. Stays in a single row on wide screens, wraps on small ones. --}}
    <main
        style="--cols: {{ $this->lanes->count() }}"
        class="grid flex-1 grid-cols-2 items-start gap-2 md:grid-cols-[repeat(var(--cols),minmax(0,1fr))] lg:gap-3"
    >
        @foreach ($this->lanes as $lane)
            @php $count = $lane['vehicles']->count(); @endphp

            <section wire:key="lane-{{ $lane['key'] }}" class="flex min-w-0 flex-col gap-2 [container-type:inline-size] lg:gap-3">

                {{-- Route header --}}
                <div class="flex items-center justify-between gap-1 rounded-xl bg-primary px-[6cqw] py-[4cqw]">
                    <h2 class="break-words font-primary text-[clamp(0.8rem,8.5cqw,1.75rem)] font-bold uppercase leading-tight tracking-wide text-white">
                        {{ $lane['label'] }}
                    </h2>
                    @if ($count)
                        <span class="shrink-0 rounded-full bg-white/20 px-[3cqw] py-[0.5cqw] font-secondary text-[clamp(0.65rem,5.5cqw,1rem)] font-bold text-white tabular-nums">
                            {{ $count }}
                        </span>
                    @endif
                </div>

                @forelse ($lane['vehicles'] as $queue)
                    <article
                        wire:key="board-{{ $queue->id }}-{{ $queue->departs_at?->timestamp }}"
                        class="rounded-2xl border-[3px] border-success bg-success/5 p-[6cqw] shadow-md dark:bg-dark-success/5"
                    >
                        {{-- Status + countdown --}}
                        <div class="flex items-center justify-between gap-2">
                            <span class="inline-flex items-center gap-1.5">
                                <span class="relative flex h-[3cqw] w-[3cqw] min-h-2 min-w-2">
                                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-success opacity-75"></span>
                                    <span class="relative inline-flex h-full w-full rounded-full bg-success"></span>
                                </span>
                                <span class="font-secondary text-[clamp(0.6rem,5.5cqw,0.95rem)] font-bold uppercase tracking-wide text-success dark:text-dark-success">Loading</span>
                            </span>

                            @if ($queue->departs_at)
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
                                        ? 'font-mono text-[clamp(0.8rem,8cqw,1.5rem)] font-bold tabular-nums text-danger'
                                        : 'font-mono text-[clamp(0.8rem,8cqw,1.5rem)] font-bold tabular-nums text-warning'"
                                    x-text="display"
                                ></span>
                            @endif
                        </div>

                        {{-- Plate number --}}
                        <div class="mt-[4cqw] truncate font-mono text-[clamp(0.9rem,12cqw,2.5rem)] font-extrabold tracking-wide text-light-txt-primary dark:text-dark-txt-primary">
                            {{ $queue->plate_number }}
                        </div>

                        {{-- Vehicle type --}}
                        <div class="mt-[4cqw]">
                            <span class="inline-block rounded-lg px-[4cqw] py-[1.5cqw] font-secondary text-[clamp(0.65rem,6.5cqw,1.1rem)] font-bold uppercase tracking-wide {{ $this->typeBadgeClasses($queue->vehicle_type) }}">
                                {{ $queue->vehicle_type }}
                            </span>
                        </div>
                    </article>
                @empty
                    <div class="rounded-2xl border-2 border-dashed border-light-bd-strong px-[4cqw] py-[12cqw] text-center dark:border-dark-bd-strong">
                        <span class="font-secondary text-[clamp(0.65rem,6cqw,1rem)] text-light-txt-muted dark:text-dark-txt-muted">No vehicle loading</span>
                    </div>
                @endforelse
            </section>
        @endforeach
    </main>
</div>
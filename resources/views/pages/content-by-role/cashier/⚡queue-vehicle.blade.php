<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Computed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\User;

use App\Http\Controllers\Api\CashQueueController;

new class extends Component
{
    public string $operator_name = '';
    public string $driver_name = '';

    // Holds the selected vehicle's id (name kept from the original component)
    public $route_list_id;

    public $cash_operator_id = null;
    public string $operatorSearch = '';
    public $amount_received = null;
    public bool $showInsufficientAmountAlert = false;

    public function queueVehicle()
    {
        if (!$this->selectedOperator) {
            Flux::toast(
                variant: 'warning',
                duration: 4000,
                heading: 'Missing Operator',
                text: 'Please search and select an operator first.'
            );
            return;
        }

        if (!$this->selectedVehicle) {
            Flux::toast(
                variant: 'warning',
                duration: 4000,
                heading: 'Missing Vehicle',
                text: 'Please choose which vehicle to queue.'
            );
            return;
        }

        if (trim($this->driver_name) === '') {
            Flux::toast(
                variant: 'warning',
                duration: 4000,
                heading: 'Missing Driver Name',
                text: 'Please enter the driver\'s name before queueing this vehicle.'
            );
            return;
        }

        if (empty($this->amount_received) || $this->amount_received < $this->queueFee) {
            $this->showInsufficientAmountAlert = true;
            return;
        }

        try {
            $request = new Request();
            $request->merge([
                'operator_id'     => $this->selectedOperator->id,
                'vehicle_id'      => $this->selectedVehicle->id,
                'driver_name'     => $this->driver_name ?: $this->selectedVehicle->driver_name,
                'amount'          => $this->queueFee,
                'amount_received' => $this->amount_received,
                'change'          => $this->change,
                'destination'     => $this->selectedVehicle->route_list->terminal,
                'vehicle_type'    => $this->selectedVehicle->vehicle_type,
                'plate_number'    => $this->selectedVehicle->plate_number,
            ]);

            $response = (new CashQueueController())->queue($request);
            $responseData = $response->getData(true);
        } catch (\Exception $e) {
            Log::error('Vehicle queueing failed', ['error' => $e->getMessage()]);
            Flux::toast(
                variant: 'warning',
                duration: 4000,
                heading: 'Failed to Queue Vehicle',
                text: 'Something went wrong while queuing this vehicle. Please try again.'
            );
            return;
        }

        if (($responseData['success'] ?? false) === true) {
            Flux::toast(
                variant: 'success',
                duration: 4000,
                heading: 'Vehicle Queued Successfully',
                text: 'Queueing fee of ₱' . number_format($this->queueFee, 2) . ' recorded. Change: ₱' . number_format($this->change, 2)
            );

            $this->clearForm();
            $this->redirect(route('user.queue'), navigate: true);
            return;
        }

        Flux::toast(
            variant: 'warning',
            duration: 4000,
            heading: 'Failed to Queue Vehicle',
            text: $responseData['message'] ?? 'An error occurred while queuing the vehicle.'
        );
    }

    #[Computed]
    public function selectedOperator()
    {
        return $this->cash_operator_id ? User::find($this->cash_operator_id) : null;
    }

    #[Computed]
    public function selectedVehicle()
    {
        if (!$this->selectedOperator || !$this->route_list_id) {
            return null;
        }

        return $this->selectedOperator->vehicles()
            ->with('route_list.operatorTicketRate')
            ->where('id', $this->route_list_id)
            ->first();
    }

    #[Computed]
    public function allOperators()
    {
        return User::where('role', 'operator')->has('vehicles')->get();
    }

    #[Computed]
    public function filteredOperators()
    {
        if (empty($this->operatorSearch)) {
            return collect();
        }

        return $this->allOperators->filter(function ($op) {
            return stripos($op->name, $this->operatorSearch) !== false;
        });
    }

    #[Computed]
    public function queueFee()
    {
        if ($this->selectedVehicle && $this->selectedVehicle->route_list && $this->selectedVehicle->route_list->operatorTicketRate) {
            return $this->selectedVehicle->route_list->operatorTicketRate->queueing_fee;
        }
        return 0;
    }

    #[Computed]
    public function change()
    {
        if ($this->amount_received && $this->queueFee > 0) {
            return max(0, $this->amount_received - $this->queueFee);
        }
        return 0;
    }

    // Pre-fill from the vehicle's registered driver, but leave it editable
    public function updatedRouteListId()
    {
        $this->driver_name = $this->selectedVehicle?->driver_name ?? '';
    }

    public function selectOperator($id)
    {
        $operator = User::find($id);
        if ($operator) {
            $this->cash_operator_id = $id;
            $this->operator_name = $operator->name;
            $this->operatorSearch = $operator->name;
            $this->route_list_id = null;
            $this->amount_received = null;
            $this->driver_name = '';
        }
    }

    public function updatedOperatorSearch()
    {
        if ($this->cash_operator_id) {
            $this->cash_operator_id = null;
            $this->operator_name = '';
            $this->route_list_id = null;
            $this->amount_received = null;
            $this->driver_name = '';
        }
    }

    public function clearForm(): void
    {
        $this->operator_name = '';
        $this->driver_name = '';
        $this->route_list_id = null;
        $this->cash_operator_id = null;
        $this->operatorSearch = '';
        $this->amount_received = null;
    }

    public function render()
    {
        $role = auth()->user()->role;

        return $this->view()->layout('layouts.' . $role . '-layout');
    }
};
?>

<div>

    <x-page-header
        description="Queue Vehicle"
        class="mb-3"
    >
    </x-page-header>

    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2 sm:gap-4 mb-6">
        <flux:breadcrumbs class="order-1 sm:order-2 shrink-0 sm:pt-1">
            <flux:breadcrumbs.item href="{{ route('user.queue') }}" wire:navigate>Back to Live Queue</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>Queue Vehicle</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        {{-- Mobile-visible heading, since the page header above is desktop-only --}}
        <div class="order-2 sm:order-1 w-full sm:w-auto">
            <x-heading
                size="xl"
                class="!font-primary !font-bold !text-light-txt-primary dark:!text-dark-txt-primary"
                style="font-size: var(--text-section-heading)"
            >
                Search an operator, choose the vehicle, and record the queueing fee collected.
            </x-heading>
        </div>
    </div>

        <x-card class="!p-0">
            <div class="flex items-center gap-3 p-4 rounded-t-xl border-b bg-primary/5 dark:bg-primary/10 border-primary/10 dark:border-primary/20">
                <flux:icon name="banknotes" class="w-5 h-5 shrink-0 text-primary dark:text-dark-txt-primary" />
                <div class="flex-1 min-w-0">
                    <p class="font-secondary text-sm font-medium text-light-txt-primary dark:text-dark-txt-primary">Cash queueing</p>
                    <p class="font-secondary text-xs text-light-txt-muted dark:text-dark-txt-muted">Search the operator, choose the vehicle, then enter the amount received</p>
                </div>
                @if($cash_operator_id)
                    <button wire:click="clearForm"
                        class="text-light-txt-muted hover:text-light-txt-primary dark:text-dark-txt-muted dark:hover:text-dark-txt-primary transition shrink-0"
                        aria-label="Clear operator">
                        <flux:icon name="x-mark" class="w-5 h-5" />
                    </button>
                @endif
            </div>

            <div class="p-5">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label class="font-secondary text-table-row font-medium text-light-txt-body dark:text-dark-txt-primary">
                            Search Operator
                        </flux:label>
                        <div class="relative">
                            <x-input
                                wire:model.live.debounce.300ms="operatorSearch"
                                placeholder="Type operator name..."
                                class="w-full mt-1"
                                autocomplete="off"
                                autofocus
                            />
                            @if(!empty($this->operatorSearch) && is_null($this->cash_operator_id))
                                <div class="absolute z-20 w-full mt-1 bg-light-secondary dark:bg-dark-subtle border border-light-bd-strong dark:border-dark-bd-strong rounded-lg shadow-xl max-h-48 overflow-y-auto">
                                    @if($this->filteredOperators->count())
                                        @foreach($this->filteredOperators as $op)
                                            <div
                                                wire:click="selectOperator({{ $op->id }})"
                                                class="px-3 py-2 hover:bg-light-subtle dark:hover:bg-dark-primary cursor-pointer font-secondary text-sm text-light-txt-body dark:text-dark-txt-body"
                                            >
                                                {{ $op->name }}
                                            </div>
                                        @endforeach
                                    @else
                                        <div class="px-3 py-2 font-secondary text-sm text-light-txt-muted dark:text-dark-txt-muted">No operator found.</div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </flux:field>

                    <flux:field>
                        <flux:label class="font-secondary text-table-row font-medium text-light-txt-body dark:text-dark-txt-primary">
                            Amount Received
                        </flux:label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3 mt-1 text-light-txt-muted dark:text-dark-txt-muted">₱</span>
                            <x-input
                                wire:model.live.debounce.300ms="amount_received"
                                type="number"
                                step="0.01"
                                min="0"
                                placeholder="0.00"
                                class="pl-7 mt-1"
                            />
                        </div>
                    </flux:field>
                </div>
            </div>
        </x-card>

        @if($this->selectedOperator)
            <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">

                <div class="lg:col-span-2 space-y-6">
                    <x-card class="!p-0 overflow-hidden">
                        <div class="px-4 sm:px-5 py-3 border-b border-light-bd-default dark:border-dark-bd-default bg-light-secondary/50 dark:bg-dark-secondary/50">
                            <h3 class="font-primary text-card-title font-semibold text-light-txt-primary dark:text-dark-txt-primary">
                                Operator's Details
                            </h3>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 p-4 sm:p-5">
                            <flux:field>
                                <flux:label class="font-secondary text-table-row font-medium text-light-txt-body dark:text-dark-txt-primary flex items-center gap-1.5 mb-2">
                                    Operator name
                                    <span class="text-light-txt-muted dark:text-dark-txt-muted font-normal">&middot; selected</span>
                                </flux:label>
                                <div class="flex items-center gap-2 h-9 px-3 rounded-lg bg-light-subtle dark:bg-dark-subtle border border-light-bd-default dark:border-dark-bd-default">
                                    <flux:icon name="user" class="w-3.5 h-3.5 text-light-txt-muted dark:text-dark-txt-muted shrink-0" />
                                    <span class="font-secondary text-sm text-light-txt-body dark:text-dark-txt-body truncate">{{ $this->selectedOperator->name }}</span>
                                </div>
                            </flux:field>

                            <flux:field>
                                <flux:label class="font-secondary text-table-row font-medium text-light-txt-body dark:text-dark-txt-primary mb-2">
                                    Driver name
                                    <span class="text-light-txt-muted dark:text-dark-txt-muted font-normal">
                                        &middot; {{ ! $this->selectedVehicle ? 'select a vehicle first' : ($this->selectedVehicle->driver_name ? 'from vehicle record, editable' : 'no driver on file') }}
                                    </span>
                                </flux:label>
                                <x-input
                                    wire:model="driver_name"
                                    placeholder="{{ $this->selectedVehicle ? 'Enter driver\'s name' : 'Select a vehicle to enable this field' }}"
                                    size="sm"
                                    :disabled="!$this->selectedVehicle"
                                />
                            </flux:field>
                        </div>
                    </x-card>

                    <x-card class="!p-0 overflow-hidden">
                        <div class="px-4 sm:px-5 py-3 border-b border-light-bd-default dark:border-dark-bd-default bg-light-secondary/50 dark:bg-dark-secondary/50">
                            <h3 class="font-primary text-card-title font-semibold text-light-txt-primary dark:text-dark-txt-primary">
                                Vehicles
                            </h3>
                        </div>

                        <flux:radio.group wire:model.live="route_list_id" class="flex flex-col gap-2 w-full p-4 sm:p-5">
                            @forelse ($this->selectedOperator->vehicles as $vehicle)
                                <label @class([
                                    'flex items-center justify-between gap-3 p-3 rounded-lg border cursor-pointer transition',
                                    'border-primary/30 bg-primary/5 dark:border-primary/40 dark:bg-primary/10' => (string) $route_list_id === (string) $vehicle->id,
                                    'border-light-bd-default dark:border-dark-bd-default hover:bg-light-subtle dark:hover:bg-dark-subtle' => (string) $route_list_id !== (string) $vehicle->id,
                                ])>
                                    <div class="min-w-0">
                                        <p @class([
                                            'font-secondary text-sm font-medium truncate',
                                            'text-light-txt-primary dark:text-dark-txt-primary' => (string) $route_list_id === (string) $vehicle->id,
                                            'text-light-txt-body dark:text-dark-txt-body' => (string) $route_list_id !== (string) $vehicle->id,
                                        ])>
                                            {{ $vehicle->vehicle_type ?? 'Unknown Vehicle' }} &middot; {{ $vehicle->plate_number ?? 'No Plate' }}
                                        </p>
                                        <p @class([
                                            'font-secondary text-xs mt-0.5 truncate',
                                            'text-light-txt-muted dark:text-dark-txt-muted',
                                        ])>
                                            Iriga Terminal to {{ $vehicle->route_list?->terminal ?? 'N/A' }}
                                        </p>
                                    </div>
                                    <flux:radio value="{{ $vehicle->id }}" class="shrink-0" />
                                </label>
                            @empty
                                <p class="font-secondary text-xs text-light-txt-muted dark:text-dark-txt-muted py-4 text-center">This operator has no vehicles.</p>
                            @endforelse
                        </flux:radio.group>
                    </x-card>
                </div>

                <div class="lg:col-span-1 lg:sticky lg:top-4 space-y-4">
                    <x-card class="!p-0 overflow-hidden">
                        <div class="px-4 sm:px-5 py-3 border-b border-light-bd-default dark:border-dark-bd-default bg-light-secondary/50 dark:bg-dark-secondary/50">
                            <h3 class="font-primary text-card-title font-semibold text-light-txt-primary dark:text-dark-txt-primary">
                                Summary
                            </h3>
                        </div>

                        <div class="p-4 sm:p-5">
                            @if ($this->selectedVehicle)
                                <div class="divide-y divide-light-bd-default dark:divide-dark-bd-default font-secondary text-sm">
                                    <div class="flex items-center justify-between py-1.5">
                                        <span class="text-light-txt-muted dark:text-dark-txt-muted">Plate no.</span>
                                        <span class="font-medium text-light-txt-primary dark:text-dark-txt-primary">{{ $this->selectedVehicle->plate_number }}</span>
                                    </div>
                                    <div class="flex items-center justify-between py-1.5">
                                        <span class="text-light-txt-muted dark:text-dark-txt-muted">Vehicle type</span>
                                        <span class="text-light-txt-body dark:text-dark-txt-body">{{ $this->selectedVehicle->vehicle_type }}</span>
                                    </div>
                                    <div class="flex items-center justify-between py-1.5">
                                        <span class="text-light-txt-muted dark:text-dark-txt-muted">Route</span>
                                        <span class="text-light-txt-body dark:text-dark-txt-body">Iriga &rarr; {{ $this->selectedVehicle->route_list?->terminal ?? 'N/A' }}</span>
                                    </div>
                                    <div class="flex items-center justify-between py-1.5">
                                        <span class="text-light-txt-muted dark:text-dark-txt-muted">Driver</span>
                                        <span class="text-light-txt-body dark:text-dark-txt-body">{{ $driver_name ?: '—' }}</span>
                                    </div>
                                        <div class="flex items-center justify-between py-1.5">
                                            <span class="text-light-txt-muted dark:text-dark-txt-muted">Queueing fee</span>
                                            <span class="font-medium text-light-txt-primary dark:text-dark-txt-primary">₱{{ number_format($this->queueFee, 2) }}</span>
                                        </div>
                                        <div class="flex items-center justify-between py-1.5">
                                            <span class="text-light-txt-muted dark:text-dark-txt-muted">Amount received</span>
                                            <span class="text-light-txt-body dark:text-dark-txt-body">{{ $this->amount_received ? '₱'.number_format($this->amount_received, 2) : '—' }}</span>
                                        </div>
                                        <div class="flex items-center justify-between py-1.5">
                                            <span class="text-light-txt-muted dark:text-dark-txt-muted">Change</span>
                                            <span class="font-semibold text-success dark:text-dark-success">₱{{ number_format($this->change, 2) }}</span>
                                        </div>
                                </div>
                            @else
                                <div class="flex flex-col items-center justify-center py-6 text-center text-light-txt-muted dark:text-dark-txt-muted">
                                    <flux:icon name="cursor-arrow-rays" class="w-8 h-8 mb-2 stroke-1" />
                                    <x-text variant="subtle" class="!font-secondary" style="font-size: var(--text-timestamp)">Select a vehicle to see its breakdown</x-text>
                                </div>
                            @endif

                            <x-button
                                variant="primary"
                                size="sm"
                                class="mt-4 w-full !font-secondary"
                                :disabled="!$this->selectedVehicle"
                                wire:click="queueVehicle"
                            >
                                Queue this vehicle
                            </x-button>
                        </div>
                    </x-card>
                </div>

            </div>
        @else
            <x-card class="!rounded-xl !border !border-dashed !border-light-bd-strong dark:!border-dark-bd-strong !bg-light-secondary dark:!bg-dark-secondary !text-center !p-8 mt-6">
                <flux:icon name="identification" class="w-8 h-8 mx-auto text-light-txt-muted dark:text-dark-txt-muted mb-2" />
                <x-text variant="subtle" class="!font-secondary block" style="font-size: var(--text-table-row)">
                    No operator selected yet.
                </x-text>
                <x-text variant="subtle" class="!font-secondary block mt-1" style="font-size: var(--text-timestamp)">
                    Search for an operator above to get started.
                </x-text>
            </x-card>
        @endif

    <flux:modal wire:model.live="showInsufficientAmountAlert" class="max-w-sm">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg" class="!text-danger dark:!text-dark-danger">Insufficient Amount</flux:heading>
                <flux:subheading>Amount received is less than the queue fee.</flux:subheading>
            </div>
            <x-text variant="subtle" class="!font-secondary block" style="font-size: var(--text-table-row)">
                The queue fee is <strong class="text-light-txt-primary dark:text-dark-txt-primary">₱{{ number_format($this->queueFee, 2) }}</strong>. Please enter an amount equal to or greater than the fee.
            </x-text>
            <div class="flex justify-end">
                <flux:button wire:click="$set('showInsufficientAmountAlert', false)" variant="primary">
                    Got it
                </flux:button>
            </div>
        </div>
    </flux:modal>

</div>
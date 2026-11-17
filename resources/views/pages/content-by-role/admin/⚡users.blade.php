<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use App\Events\UserInfoUpdated;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use App\Models\User;
use App\Services\QueueManagementService;
use Illuminate\Support\Carbon;

use App\Jobs\ProcessAfterDepart;
use App\Models\Queue;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Livewire\WithFileUploads;
use Livewire\Attributes\Locked;
use Flux\Flux;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use App\Models\RouteList;
use App\Models\VehicleGroup;
use App\Services\AuditLogsService;



new  #[Layout('layouts.admin-layout')] class extends Component
{
    use WithPagination, WithFileUploads;

    public $filtered_role = "";
    public $filtered_status = "";
    public $search = "";
    public $selectedUserId = null;

    public $user;

    // ===================== EXPORT OPERATORS MODAL =====================

    public string $exportDateFrom = '';
    public string $exportDateTo = '';
    public string $exportVehicleType = '';
    public string $exportStatus = '';

    // Left blank by default — an export scoped to "today" would exclude
    // almost every existing operator, so this starts as "all time" and can
    // be narrowed down from there.
    public function prepareExportModal()
    {
        $this->exportDateFrom = '';
        $this->exportDateTo = '';
        $this->exportVehicleType = '';
        $this->exportStatus = '';
    }

    public function resetExportDateRange()
    {
        $this->exportDateFrom = '';
        $this->exportDateTo = '';
    }

    public function setExportDateRangeToday()
    {
        $this->exportDateFrom = today()->toDateString();
        $this->exportDateTo = today()->toDateString();
    }

    #[Computed]
    public function exportUrl(): string
    {
        return route('admin.operators.export', array_filter([
            'from'         => $this->exportDateFrom,
            'to'           => $this->exportDateTo,
            'vehicle_type' => $this->exportVehicleType,
            'status'       => $this->exportStatus,
        ]));
    }

    #[Computed]
    public function vehicleTypes()
    {
        return Vehicle::query()->distinct()->pluck('vehicle_type');
    }

    public function mount() {

        app(QueueManagementService::class)->generateSchedule(today());
    }

    public function selectUser($id) {
        $this->selectedUserId = $id;
    }

    public function setRoleFilter($role) {
        $this->filtered_role = $role;
        $this->resetPage();
    }

    public function updatedFilteredStatus() {
        $this->resetPage();
    }

    // Worst-case franchise status across an operator's vehicles,
    // for the "Docs Expiring" / "Docs Expired" badge in the users table.
    public function operatorDocumentStatus(User $user): ?string
    {
        if ($user->role !== 'operator') {
            return null;
        }

        $statuses = $user->vehicles->map(fn ($vehicle) => $vehicle->documentStatus())->filter();

        if ($statuses->contains('expired')) {
            return 'expired';
        }

        if ($statuses->contains('expiring')) {
            return 'expiring';
        }

        return null;
    }

    #[Computed]
    public function getUsers() {
        return User::with('card', 'userStatus', 'vehicles')
            ->whereIn('role', ['operator', 'commuter'])
            ->when($this->filtered_role, fn($q) => $q->where('role', $this->filtered_role))
            ->when($this->filtered_status, function ($q) {
                match ($this->filtered_status) {
                    'active' => $q->whereDoesntHave('userStatus', function ($s) {
                        $s->where('is_deleted', true)->orWhere('status', 'suspended');
                    }),
                    'suspended' => $q->whereHas('userStatus', function ($s) {
                        $s->where('status', 'suspended')->where('is_deleted', false);
                    }),
                    'deleted' => $q->whereHas('userStatus', function ($s) {
                        $s->where('is_deleted', true);
                    }),
                    default => null,
                };
            })
            ->when($this->search, fn($q) => $q->where(function ($q2) {
                $q2->where('name', 'like', '%' . $this->search . '%')
                ->orWhere('email_address', 'like', '%' . $this->search . '%')
                ->orWhere('user_code', 'like', '%' . $this->search . '%');
            }))
            ->paginate(10);
    }

    #[Computed]
    public function withCardCount() {
        return User::whereIn('role', ['operator', 'commuter'])
            ->whereHas('card')
            ->count();
    }



    #[On('echo:user-info-updated,.UserInfoUpdated')]
    public function refreshUserInfo() {

        unset($this->getUsers);
        unset($this->withCardCount);
    }

    // ===================== IMPORT OPERATORS MODAL =====================

    public $importFile;

    #[Locked]
    public array $importRows = [];

    public function prepareImportModal(): void
    {
        $this->reset(['importFile', 'importRows']);
        $this->resetValidation();
    }

    public function downloadImportTemplate()
    {
        $headings = [
            'operator_name', 'email_address', 'phone_number', 'address',
            'vehicle_type', 'route', 'plate_number', 'total_seats', 'group_number',
            'engine_number', 'body_number', 'chassis_number', 'driver_name',
            'franchise_expiry_date',
        ];

        return response()->streamDownload(function () use ($headings) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headings);
            fputcsv($out, [
                'Juan Dela Cruz', 'juan@example.com', '09171234567', 'Zone 3, San Roque, Iriga City, Camarines Sur',
                'Bus', 'Naga', 'ABC-1234', 30, 1,
                'ENG-12345', 'BODY-12345', 'CH-123456789', 'Pedro Santos',
                now()->addYear()->format('Y-m-d'),
            ]);
            fclose($out);
        }, 'operators-import-template.csv');
    }

    public function updatedImportFile(): void
    {
        $this->reset('importRows');

        $this->validate([
            'importFile' => 'required|file|mimes:xlsx,csv,txt|max:5120',
        ]);

        // Read the file directly with PhpSpreadsheet (installed together with Laravel Excel).
        // Raw values are kept (dates stay Excel serial numbers), which normalizeImportRow() handles.
        $spreadsheet = IOFactory::load($this->importFile->getRealPath());
        $sheetRows = $spreadsheet->getSheet(0)->toArray(null, true, false, false);

        // Row 1 = headings, e.g. "Operator Name" or "operator_name" -> operator_name
        $headers = array_map(
            fn ($h) => strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', trim((string) $h)), '_')),
            array_shift($sheetRows) ?? []
        );

        $rows = [];
        $seenPlates = [];

        foreach ($sheetRows as $index => $cells) {
            // Skip completely blank rows
            if (collect($cells)->filter(fn ($v) => filled($v))->isEmpty()) {
                continue;
            }

            $raw = [];
            foreach ($headers as $i => $key) {
                if ($key !== '') {
                    $raw[$key] = $cells[$i] ?? null;
                }
            }

            $data = $this->normalizeImportRow($raw);
            $errors = $this->validateImportRow($data, $seenPlates);
            $seenPlates[] = $data['plate_number'];

            $rows[] = [
                'line'   => $index + 2, // +1 for 0-index, +1 for the heading row
                'data'   => $data,
                'errors' => $errors,
            ];
        }

        $this->importRows = $rows;
    }

    private function normalizeImportRow(array $raw): array
    {
        $text = fn ($key) => trim((string) ($raw[$key] ?? ''));

        // Excel turns 09171234567 into the number 9171234567 and drops the leading 0.
        $phone = preg_replace('/[\s\-]/', '', $text('phone_number'));
        if (preg_match('/^9\d{9}$/', $phone)) {
            $phone = '0' . $phone;
        }

        // Dates may arrive as Excel serial numbers or as text.
        $dateRaw = $raw['franchise_expiry_date'] ?? null;
        $date = null;
        if (is_numeric($dateRaw)) {
            $date = ExcelDate::excelToDateTimeObject($dateRaw)->format('Y-m-d');
        } elseif (filled($dateRaw)) {
            try {
                $date = Carbon::parse($dateRaw)->format('Y-m-d');
            } catch (\Throwable $e) {
                $date = (string) $dateRaw; // fails the "date" rule with a clear message
            }
        }

        return [
            'operator_name'         => $text('operator_name'),
            'email_address'         => strtolower($text('email_address')),
            'phone_number'          => $phone,
            'address'               => $text('address'),
            'vehicle_type'          => $text('vehicle_type'),
            'route'                 => $text('route'),
            'plate_number'          => strtoupper($text('plate_number')),
            'total_seats'           => $text('total_seats'),
            'group_number'          => $text('group_number') !== '' ? $text('group_number') : null,
            'engine_number'         => $text('engine_number'),
            'body_number'           => $text('body_number'),
            'chassis_number'        => $text('chassis_number'),
            'driver_name'           => $text('driver_name') ?: null,
            'franchise_expiry_date' => $date,
        ];
    }

    private function validateImportRow(array $row, array $seenPlates): array
    {
        // Same rules as addNewVehicle() so imports and manual entry stay consistent.
        $validator = Validator::make($row, [
            'operator_name'         => 'required|string|min:2|max:255',
            'email_address'         => 'nullable|email|max:255',
            'phone_number'          => ['required', 'regex:/^09\d{9}$/'],
            'address'               => 'required|string|min:1',
            'vehicle_type'          => 'required|string',
            'route'                 => 'required|string',
            'plate_number'          => 'required|string|unique:vehicles,plate_number',
            'total_seats'           => 'required|integer|min:10|max:50',
            'group_number'          => 'required_if:vehicle_type,Bus,UV-express|nullable|integer|min:1|max:2',
            'engine_number'         => 'required|string|min:2',
            'body_number'           => 'required|string|min:2',
            'chassis_number'        => 'required|string|min:2',
            'driver_name'           => 'nullable|string|max:100',
            // Matches manual entry (must be in the future). To allow already-expired
            // franchises for legacy records, change to 'required|date'.
            'franchise_expiry_date' => 'required|date|after:today',
        ]);

        $errors = $validator->errors()->all();

        if ($row['plate_number'] !== '' && in_array($row['plate_number'], $seenPlates, true)) {
            $errors[] = 'Plate number appears more than once in this file.';
        }

        $existing = $row['phone_number'] !== ''
            ? User::where('phone_number', $row['phone_number'])->first()
            : null;

        if ($existing && $existing->role !== 'operator') {
            $errors[] = 'This phone number already belongs to a non-operator account.';
        }

        if (! $existing && $row['email_address'] !== ''
            && User::where('email_address', $row['email_address'])->exists()) {
            $errors[] = 'Email address is already used by another user.';
        }

        if ($row['vehicle_type'] !== '' && $row['route'] !== ''
            && ! RouteList::whereHas('operatorTicketRate', fn ($q) => $q->where('vehicle_type', $row['vehicle_type']))
                ->where('terminal', $row['route'])
                ->exists()) {
            $errors[] = "No route \"{$row['route']}\" exists for vehicle type \"{$row['vehicle_type']}\".";
        }

        return $errors;
    }

    #[Computed]
    public function importValidCount(): int
    {
        return collect($this->importRows)->filter(fn ($r) => empty($r['errors']))->count();
    }

    #[Computed]
    public function importInvalidCount(): int
    {
        return count($this->importRows) - $this->importValidCount;
    }

    public function confirmImport(): void
    {
        $valid = collect($this->importRows)
            ->filter(fn ($r) => empty($r['errors']))
            ->pluck('data');

        if ($valid->isEmpty()) {
            return;
        }

        $operatorsCreated = 0;
        $vehiclesCreated  = 0;

        DB::transaction(function () use ($valid, &$operatorsCreated, &$vehiclesCreated) {
            foreach ($valid->groupBy('phone_number') as $phone => $rows) {
                $first = $rows->first();

                $operator = User::where('phone_number', $phone)->first();

                if (! $operator) {
                    $operator = User::create([
                        'name'          => $first['operator_name'],
                        'email_address' => $first['email_address'] ?: null,
                        'phone_number'  => $phone,
                        'address'       => $first['address'],
                        'role'          => 'operator',
                        // No password: operators are records only and cannot log in.
                    ]);
                    $operatorsCreated++;
                }

                foreach ($rows as $row) {
                    $routeList = RouteList::whereHas('operatorTicketRate',
                            fn ($q) => $q->where('vehicle_type', $row['vehicle_type']))
                        ->where('terminal', $row['route'])
                        ->first();

                    $vehicle = $operator->vehicles()->create([
                        'route_list_id'         => $routeList->id,
                        'vehicle_type'          => $row['vehicle_type'],
                        'plate_number'          => $row['plate_number'],
                        'total_seats'           => (int) $row['total_seats'],
                        'engine_number'         => $row['engine_number'],
                        'body_number'           => $row['body_number'],
                        'chassis_number'        => $row['chassis_number'],
                        'driver_name'           => $row['driver_name'],
                        'has_franchise'         => true,
                        'franchise_expiry_date' => $row['franchise_expiry_date'],
                    ]);

                    if (in_array($row['vehicle_type'], ['Bus', 'UV-express']) && $row['group_number'] !== null) {
                        $order = VehicleGroup::where('group_number', (int) $row['group_number'])
                            ->whereHas('vehicle', fn ($q) => $q->where('vehicle_type', $vehicle->vehicle_type))
                            ->max('order_number') + 1;

                        $vehicle->vehicle_group()->create([
                            'group_number' => (int) $row['group_number'],
                            'order_number' => $order,
                        ]);
                    }

                    $vehiclesCreated++;
                }
            }

            // One summary log entry per import, not one per row.
            app(AuditLogsService::class)->create([
                'user_id'  => auth()->id(),
                'action'   => 'Operators Imported',
                'subject'  => 'Admin imported operators and vehicles from a spreadsheet',
                'channel'  => 'Web',
                'metadata' => [
                    'ip_address' => request()->ip(),
                    'message'    => "Imported {$operatorsCreated} new operator(s) and {$vehiclesCreated} vehicle(s) from a spreadsheet.",
                ],
            ]);
        });

        $skipped = $this->importInvalidCount;

        $this->reset(['importFile', 'importRows']);
        unset($this->getUsers, $this->importValidCount, $this->importInvalidCount);

        Flux::modal('import-operators')->close();

        Flux::toast(
            variant: 'success',
            duration: 6000,
            heading: 'Import complete.',
            text: "{$operatorsCreated} new operator(s) and {$vehiclesCreated} vehicle(s) added."
                . ($skipped ? " {$skipped} row(s) skipped due to errors." : '')
        );
    }



};
?>

<div>
    <x-page-header
        description="Registered Users"
        class="mb-4"
    >
        <div class="flex items-center gap-2">
            <flux:modal.trigger name="import-operators" wire:click="prepareImportModal">
                <flux:button
                    icon="arrow-up-tray"
                    size="sm"
                    variant="outline"
                    class="font-secondary justify-center"
                >
                    Import Operators
                </flux:button>
            </flux:modal.trigger>

            <flux:modal.trigger name="export-operators" wire:click="prepareExportModal">
                <flux:button
                    icon="arrow-down-tray"
                    size="sm"
                    class="font-secondary justify-center !bg-black !text-white !border-0 hover:!bg-neutral-800 dark:!bg-white dark:!text-black dark:hover:!bg-neutral-200"
                >
                    Export Operators
                </flux:button>
            </flux:modal.trigger>
        </div>
    </x-page-header>

    <div class="hidden sm:flex sm:items-center sm:justify-between gap-3 mb-6">
        <x-heading
            class="!font-primary !font-bold !text-light-txt-primary dark:!text-dark-txt-primary"
            style="font-size: var(--text-section-heading)"
        >
        </x-heading>

        <flux:link href="{{ route('admin.register.user') }}" wire:navigate class="w-full sm:w-auto">
            <flux:button variant="primary" icon="plus" size="sm" class="font-secondary w-full sm:w-auto justify-center bg-primary text-white hover:bg-primary-hover dark:bg-secondary dark:text-[var(--color-dark-primary)] dark:hover:bg-secondary-hover">
                Register Operator
            </flux:button>
        </flux:link>
    </div>

    {{-- Mobile-visible heading, since the page header above is desktop-only --}}
    <div class="sm:hidden flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <div>
            <x-heading
                size="xl"
                class="!font-primary !font-bold !text-light-txt-primary dark:!text-dark-txt-primary"
                style="font-size: var(--text-page-title)"
            >
                Users
            </x-heading>
        </div>

        <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 w-full sm:w-auto shrink-0">

            <div class="flex items-center gap-2">
                <flux:modal.trigger name="import-operators" wire:click="prepareImportModal">
                    <flux:button
                        icon="arrow-up-tray"
                        size="sm"
                        variant="outline"
                        class="font-secondary w-full sm:w-auto justify-center"
                    >
                        Import Operators
                    </flux:button>
                </flux:modal.trigger>

                <flux:modal.trigger name="export-operators" wire:click="prepareExportModal">
                    <flux:button
                        icon="arrow-down-tray"
                        size="sm"
                        class="font-secondary w-full sm:w-auto justify-center !bg-black !text-white !border-0 hover:!bg-neutral-800 dark:!bg-white dark:!text-black dark:hover:!bg-neutral-200"
                    >
                        Export Operators
                    </flux:button>
                </flux:modal.trigger>
            </div>

            <flux:link href="{{ route('admin.register.user') }}" wire:navigate class="w-full sm:w-auto">
                <flux:button variant="primary" icon="plus" size="sm" class="font-secondary w-full sm:w-auto justify-center bg-primary text-white hover:bg-primary-hover dark:bg-secondary dark:text-[var(--color-dark-primary)] dark:hover:bg-secondary-hover">
                    Add user
                </flux:button>
            </flux:link>
        </div>
    </div>

    {{-- ===================== EXPORT OPERATORS MODAL ===================== --}}
    <flux:modal
        name="export-operators"
        :closable="false"
        class="w-[calc(100%-2rem)] sm:max-w-lg mx-auto rounded-xl overflow-hidden"
    >
        <div class="flex flex-col p-4 sm:p-6 !pr-4 sm:!pr-6 space-y-5 overflow-y-auto max-h-[70vh]">
            <div class="flex items-start justify-between">
                <div>
                    <flux:heading size="xl" class="!font-primary !font-bold text-light-txt-primary dark:text-dark-txt-primary">
                        Export operators
                    </flux:heading>
                    <flux:text class="mt-1 font-secondary text-sm text-light-txt-muted dark:text-dark-txt-muted">
                        Choose what to include in the spreadsheet. Leave the date range blank to include every registered vehicle.
                    </flux:text>
                </div>
                <flux:modal.close>
                    <button type="button" class="p-1 rounded-full hover:bg-light-subtle dark:hover:bg-dark-subtle text-light-txt-muted dark:text-dark-txt-muted -mt-1">
                        <flux:icon name="x-mark" class="w-5 h-5" />
                    </button>
                </flux:modal.close>
            </div>

            <flux:field>
                <flux:label class="font-secondary text-table-row font-medium text-light-txt-body dark:text-dark-txt-primary">Registered date range</flux:label>
                <div class="flex items-center gap-2">
                    <flux:input
                        type="date"
                        wire:model.live="exportDateFrom"
                        size="sm"
                        class="font-secondary text-table-row bg-light-primary dark:bg-dark-surface text-light-txt-body dark:text-dark-txt-primary border-light-bd-default dark:border-dark-bd-default"
                    />
                    <span class="text-light-txt-muted dark:text-dark-txt-muted text-sm shrink-0">to</span>
                    <flux:input
                        type="date"
                        wire:model.live="exportDateTo"
                        size="sm"
                        class="font-secondary text-table-row bg-light-primary dark:bg-dark-surface text-light-txt-body dark:text-dark-txt-primary border-light-bd-default dark:border-dark-bd-default"
                    />
                </div>
                <div class="flex items-center gap-2 mt-2">
                    <button
                        type="button"
                        wire:click="resetExportDateRange"
                        @class([
                            'px-3 py-1 rounded-md font-secondary text-xs font-medium transition-colors',
                            'bg-light-subtle dark:bg-dark-subtle text-light-txt-primary dark:text-dark-txt-primary' => $exportDateFrom === '' && $exportDateTo === '',
                            'text-light-txt-muted dark:text-dark-txt-muted hover:text-light-txt-primary dark:hover:text-dark-txt-primary' => !($exportDateFrom === '' && $exportDateTo === ''),
                        ])
                    >
                        All time
                    </button>
                    <button
                        type="button"
                        wire:click="setExportDateRangeToday"
                        @class([
                            'px-3 py-1 rounded-md font-secondary text-xs font-medium transition-colors',
                            'bg-light-subtle dark:bg-dark-subtle text-light-txt-primary dark:text-dark-txt-primary' => $exportDateFrom === today()->toDateString() && $exportDateTo === today()->toDateString(),
                            'text-light-txt-muted dark:text-dark-txt-muted hover:text-light-txt-primary dark:hover:text-dark-txt-primary' => !($exportDateFrom === today()->toDateString() && $exportDateTo === today()->toDateString()),
                        ])
                    >
                        Today
                    </button>
                </div>
            </flux:field>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <flux:field>
                    <flux:label class="font-secondary text-table-row font-medium text-light-txt-body dark:text-dark-txt-primary">Vehicle type</flux:label>
                    <flux:select
                        wire:model.live="exportVehicleType"
                        size="sm"
                        placeholder="All vehicle types"
                        class="font-secondary text-table-row bg-light-primary dark:bg-dark-surface text-light-txt-body dark:text-dark-txt-primary border-light-bd-default dark:border-dark-bd-default"
                    >
                        <flux:select.option value="">All vehicle types</flux:select.option>
                        @foreach ($this->vehicleTypes as $type)
                            <flux:select.option value="{{ $type }}">{{ $type }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </flux:field>

                <flux:field>
                    <flux:label class="font-secondary text-table-row font-medium text-light-txt-body dark:text-dark-txt-primary">Account status</flux:label>
                    <flux:select
                        wire:model.live="exportStatus"
                        size="sm"
                        placeholder="All statuses"
                        class="font-secondary text-table-row bg-light-primary dark:bg-dark-surface text-light-txt-body dark:text-dark-txt-primary border-light-bd-default dark:border-dark-bd-default"
                    >
                        <flux:select.option value="">All statuses</flux:select.option>
                        <flux:select.option value="active">Active</flux:select.option>
                        <flux:select.option value="suspended">Suspended</flux:select.option>
                    </flux:select>
                </flux:field>
            </div>

            <div class="flex flex-col-reverse sm:flex-row justify-end items-stretch sm:items-center gap-2 pt-2 border-t border-light-bd-default dark:border-dark-bd-default">
                <flux:modal.close class="w-full sm:w-auto">
                    <flux:button type="button" variant="ghost" class="w-full sm:w-auto justify-center font-secondary">
                        Cancel
                    </flux:button>
                </flux:modal.close>
                <flux:button
                    href="{{ $this->exportUrl }}"
                    icon="arrow-down-tray"
                    variant="primary"
                    class="font-secondary w-full sm:w-auto justify-center"
                >
                    Download Excel
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- ===================== IMPORT OPERATORS MODAL ===================== --}}
    <flux:modal
        name="import-operators"
        :closable="false"
        class="w-[calc(100%-2rem)] sm:max-w-lg md:max-w-3xl mx-auto rounded-xl overflow-hidden"
    >
        <div class="flex flex-col p-4 sm:p-6 !pr-4 sm:!pr-6 space-y-5 overflow-y-auto max-h-[70vh]">
            <div class="flex items-start justify-between">
                <div>
                    <flux:heading size="xl" class="!font-primary !font-bold text-light-txt-primary dark:text-dark-txt-primary">
                        Import operators
                    </flux:heading>
                    <flux:text class="mt-1 font-secondary text-sm text-light-txt-muted dark:text-dark-txt-muted">
                        Upload an Excel (.xlsx) or CSV file with one row per vehicle. Rows sharing a phone number are added to the same operator.
                    </flux:text>
                </div>
                <flux:modal.close>
                    <button type="button" class="p-1 rounded-full hover:bg-light-subtle dark:hover:bg-dark-subtle text-light-txt-muted dark:text-dark-txt-muted -mt-1">
                        <flux:icon name="x-mark" class="w-5 h-5" />
                    </button>
                </flux:modal.close>
            </div>

            <flux:field>
                <div class="flex items-center justify-between">
                    <flux:label class="font-secondary text-table-row font-medium text-light-txt-body dark:text-dark-txt-primary">Spreadsheet file</flux:label>
                    <flux:button type="button" variant="ghost" size="xs" icon="arrow-down-tray" wire:click="downloadImportTemplate" class="font-secondary">
                        Download template
                    </flux:button>
                </div>
                <flux:input type="file" wire:model="importFile" accept=".xlsx,.csv" />
                <flux:error name="importFile" />
                <div wire:loading wire:target="importFile" class="font-secondary text-xs text-light-txt-muted dark:text-dark-txt-muted mt-1">
                    Reading file…
                </div>
            </flux:field>

            @if (count($importRows))
                <div class="flex flex-wrap items-center gap-2">
                    <flux:badge color="green" size="sm">{{ $this->importValidCount }} ready</flux:badge>
                    @if ($this->importInvalidCount)
                        <flux:badge color="red" size="sm">{{ $this->importInvalidCount }} with errors (will be skipped)</flux:badge>
                    @endif
                </div>

                <div class="border border-light-bd-default dark:border-dark-bd-default rounded-lg overflow-hidden">
                    <div class="overflow-auto max-h-64">
                        <table class="w-full text-left font-secondary text-xs">
                            <thead class="sticky top-0 bg-light-subtle dark:bg-dark-secondary text-light-txt-muted dark:text-dark-txt-muted">
                                <tr>
                                    <th class="px-3 py-2">Row</th>
                                    <th class="px-3 py-2">Operator</th>
                                    <th class="px-3 py-2">Plate</th>
                                    <th class="px-3 py-2">Type</th>
                                    <th class="px-3 py-2">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-light-bd-default dark:divide-dark-bd-default text-light-txt-body dark:text-dark-txt-primary">
                                @foreach ($importRows as $row)
                                    <tr wire:key="import-row-{{ $row['line'] }}">
                                        <td class="px-3 py-2 align-top">{{ $row['line'] }}</td>
                                        <td class="px-3 py-2 align-top">{{ $row['data']['operator_name'] ?: '—' }}</td>
                                        <td class="px-3 py-2 align-top">{{ $row['data']['plate_number'] ?: '—' }}</td>
                                        <td class="px-3 py-2 align-top">{{ $row['data']['vehicle_type'] ?: '—' }}</td>
                                        <td class="px-3 py-2 align-top">
                                            @if (empty($row['errors']))
                                                <span class="text-success dark:text-dark-success">OK</span>
                                            @else
                                                <ul class="text-danger dark:text-dark-danger list-disc pl-4 space-y-0.5">
                                                    @foreach ($row['errors'] as $error)
                                                        <li>{{ $error }}</li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            <div class="flex flex-col-reverse sm:flex-row justify-end items-stretch sm:items-center gap-2 pt-2 border-t border-light-bd-default dark:border-dark-bd-default">
                <flux:modal.close class="w-full sm:w-auto">
                    <flux:button type="button" variant="ghost" class="w-full sm:w-auto justify-center font-secondary">
                        Cancel
                    </flux:button>
                </flux:modal.close>
                @if (count($importRows) && $this->importValidCount > 0)
                    <flux:button
                        type="button"
                        icon="arrow-up-tray"
                        wire:click="confirmImport"
                        wire:loading.attr="disabled"
                        wire:target="confirmImport"
                        class="font-secondary w-full sm:w-auto justify-center bg-primary text-white hover:bg-primary-hover dark:bg-secondary dark:text-[var(--color-dark-primary)] dark:hover:bg-secondary-hover"
                    >
                        Import {{ $this->importValidCount }} valid row(s)
                    </flux:button>
                @endif
            </div>
        </div>
    </flux:modal>

    {{-- Stat cards --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 sm:gap-3 mb-5">
        <flux:card class="p-3 sm:p-4">
            <div class="flex items-center gap-1.5 sm:gap-2 mb-1.5">
                <div class="flex items-center justify-center w-6 h-6 sm:w-7 sm:h-7 rounded-lg bg-info/10 dark:bg-dark-info/20 shrink-0">
                    <flux:icon.users class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-info dark:text-dark-info" />
                </div>
                <x-text class="font-secondary text-xs sm:text-stat-label text-light-txt-muted dark:text-dark-txt-muted">
                    Total operators
                </x-text>
            </div>
            <x-text class="font-primary text-stat-value font-bold text-info dark:text-dark-info block">
                {{ User::where('role', 'operator')->count() }}
            </x-text>
            <x-text class="font-secondary text-xs text-light-txt-muted dark:text-dark-txt-muted">
                vehicle owners &amp; drivers
            </x-text>
        </flux:card>

        <flux:card class="p-3 sm:p-4">
            <div class="flex items-center gap-1.5 sm:gap-2 mb-1.5">
                <div class="flex items-center justify-center w-6 h-6 sm:w-7 sm:h-7 rounded-lg bg-primary/10 dark:bg-primary/20 shrink-0">
                    <flux:icon.truck class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-primary dark:text-dark-txt-primary" />
                </div>
                <x-text class="font-secondary text-xs sm:text-stat-label text-light-txt-muted dark:text-dark-txt-muted">
                    Total vehicles
                </x-text>
            </div>
            <x-text class="font-primary text-stat-value font-bold text-light-txt-primary dark:text-dark-txt-primary block">
                {{ Vehicle::count() }}
            </x-text>
            <x-text class="font-secondary text-xs text-light-txt-muted dark:text-dark-txt-muted">
                registered vehicles
            </x-text>
        </flux:card>

<!--         <flux:card class="p-3 sm:p-4">
            <div class="flex items-center gap-1.5 sm:gap-2 mb-1.5">
                <div class="flex items-center justify-center w-6 h-6 sm:w-7 sm:h-7 rounded-lg bg-success/10 dark:bg-dark-success/20 shrink-0">
                    <flux:icon.credit-card class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-success dark:text-dark-success" />
                </div>
                <x-text class="font-secondary text-xs sm:text-stat-label text-light-txt-muted dark:text-dark-txt-muted">
                    With card
                </x-text>
            </div>
            <x-text class="font-primary text-stat-value font-bold text-success dark:text-dark-success block">
                {{ $this->withCardCount }}
            </x-text>
            <x-text class="font-secondary text-xs text-light-txt-muted dark:text-dark-txt-muted">
                {{ $this->getUsers->total() - $this->withCardCount }} awaiting issuance
            </x-text>
        </flux:card>

        <flux:card class="p-3 sm:p-4">
            <div class="flex items-center gap-1.5 sm:gap-2 mb-1.5">
                <div class="flex items-center justify-center w-6 h-6 sm:w-7 sm:h-7 rounded-lg bg-warning/10 dark:bg-dark-warning/20 shrink-0">
                    <flux:icon.calendar-days class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-warning dark:text-dark-warning" />
                </div>
                <x-text class="font-secondary text-xs sm:text-stat-label text-light-txt-muted dark:text-dark-txt-muted">
                    Registered today
                </x-text>
            </div>
            <x-text class="font-primary text-stat-value font-bold text-warning dark:text-dark-warning block">
                {{ User::whereIn('role', ['operator', 'commuter'])->whereDate('created_at', today())->count() }}
            </x-text>
            <x-text class="font-secondary text-xs text-light-txt-muted dark:text-dark-txt-muted">
                new sign-ups
            </x-text>
        </flux:card> -->
    </div>

    {{-- Filter pills + search --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <div class="flex flex-col sm:flex-row items-stretch sm:items-center sm:justify-end gap-2 w-full sm:flex-1">
            <flux:select
                wire:model.live="filtered_status"
                placeholder="All statuses"
                class="w-full sm:w-44 font-secondary text-table-row dark:bg-dark-secondary dark:border-dark-bd-default dark:text-dark-txt-primary"
            >
                <!-- <flux:select.option value="">All statuses</flux:select.option> -->
                <flux:select.option value="active">Docs Expiring</flux:select.option>
<!--                 <flux:select.option value="suspended">Suspended</flux:select.option>
                <flux:select.option value="deleted">Deleted</flux:select.option> -->
            </flux:select>

            <div class="w-full sm:flex-1 sm:max-w-md">
                <flux:input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search name, ID or email"
                    class="w-full font-secondary text-table-row dark:bg-dark-secondary dark:border-dark-bd-default dark:text-dark-txt-primary"
                    icon="magnifying-glass"
                />
            </div>
        </div>
    </div>

    <flux:card class="mb-4 p-0! overflow-hidden">
        <div class="overflow-x-auto">
            <flux:table container:class="md:max-h-160">
                <flux:table.columns sticky class="bg-light-subtle/50 dark:bg-dark-secondary/50 font-secondary text-nav-label text-light-txt-muted dark:text-dark-txt-muted">
                    <flux:table.column align="center" class="px-3! md:px-4! py-2">User</flux:table.column>
                    <flux:table.column align="center" class="hidden lg:table-cell px-2 md:px-4 py-2">Address</flux:table.column>
                    <flux:table.column align="center" class="hidden sm:table-cell px-2 md:px-4 py-2">Joined</flux:table.column>
                    <flux:table.column align="center" class="px-2! md:px-4! py-2">Details</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->getUsers as $user)
                        <flux:table.row :key="$user->id">
                            <flux:table.cell class="px-3! md:px-4! py-2.5">
                                <div class="flex items-center gap-2.5">
                                    <flux:avatar size="sm" src="{{ $user->avatar_url }}" name="{{ $user->name }}" />
                                    <div class="min-w-0 flex flex-col">
                                        <span class="font-secondary text-xs md:text-table-row font-medium text-light-txt-body dark:text-dark-txt-primary truncate flex items-center gap-1.5">
                                            {{ $user->name }}
                                            @if ($user->isDeleted())
                                                <flux:badge color="zinc" size="sm" class="font-secondary text-badge text-xs">Deleted</flux:badge>
                                            @elseif ($user->isSuspended())
                                                <flux:badge color="red" size="sm" class="font-secondary text-badge text-xs">Suspended</flux:badge>
                                            @endif
                                            @php($docStatus = $this->operatorDocumentStatus($user))
                                            @if ($docStatus === 'expired')
                                                <flux:badge color="red" size="sm" class="font-secondary text-badge text-xs">Docs Expired</flux:badge>
                                            @elseif ($docStatus === 'expiring')
                                                <flux:badge color="orange" size="sm" class="font-secondary text-badge text-xs">Docs Expiring</flux:badge>
                                            @endif
                                        </span>
                                        <span class="font-secondary text-xs text-light-txt-muted dark:text-dark-txt-muted truncate">
                                            {{ $user->email_address }}
                                        </span>
                                    </div>
                                </div>
                            </flux:table.cell>

                            <flux:table.cell align="center" class="hidden lg:table-cell px-2 md:px-4 py-2.5 font-secondary text-xs md:text-timestamp text-light-txt-muted dark:text-dark-txt-muted max-w-48 truncate">
                                {{ $user->address ?: '—' }}
                            </flux:table.cell>

                            <flux:table.cell align="center" class="hidden sm:table-cell px-2 md:px-4 py-2.5 font-secondary text-xs md:text-timestamp text-light-txt-muted dark:text-dark-txt-muted">
                                {{ $user->created_at->format('M j, Y') }}
                            </flux:table.cell>

                            <flux:table.cell align="center" class="px-2! md:px-4! py-2.5">
                                <flux:link href="/admin/edit/user/{{ $user->id }}" wire:navigate>
                                    <flux:button variant="ghost" size="sm" class="font-secondary text-xs md:text-table-row">
                                        View
                                    </flux:button>
                                </flux:link>
                            </flux:table.cell>
                        </flux:table.row>

                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="6" class="px-2 md:px-4 py-4">
                                <div class="flex flex-col items-center justify-center py-6 md:py-12 gap-2">
                                    <flux:icon.users class="w-6 h-6 md:w-8 md:h-8 text-light-txt-muted dark:text-dark-txt-muted" />
                                    <x-text class="font-secondary text-sm md:text-table-row text-light-txt-muted dark:text-dark-txt-muted">
                                        No users found.
                                    </x-text>
                                    @if ($this->search)
                                        <x-text class="font-secondary text-xs md:text-timestamp text-light-txt-muted dark:text-dark-txt-muted">
                                            Try a different search term.
                                        </x-text>
                                    @elseif ($this->filtered_status)
                                        <x-text class="font-secondary text-xs md:text-timestamp text-light-txt-muted dark:text-dark-txt-muted">
                                            No {{ $this->filtered_status }} users found.
                                        </x-text>
                                    @elseif ($this->filtered_role)
                                        <x-text class="font-secondary text-xs md:text-timestamp text-light-txt-muted dark:text-dark-txt-muted">
                                            No {{ $this->filtered_role }}s registered yet.
                                        </x-text>
                                    @endif
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>

        @if ($this->getUsers->hasPages())
            <div class="flex flex-wrap items-center justify-end gap-2 px-3 sm:px-4 py-2 border-t border-light-bd-default dark:border-dark-bd-default bg-light-secondary dark:bg-dark-secondary">
                {{ $this->getUsers->links() }}
            </div>
        @endif
    </flux:card>

{{-- <livewire:pages::content-by-role.admin.pending-users /> --}}
</div>
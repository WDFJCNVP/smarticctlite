<?php

namespace App\Imports;

use Livewire\WithFileUploads;
use Livewire\Attributes\Locked;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Excel as ExcelType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use App\Imports\OperatorRowsImport;
use App\Models\RouteList;
use App\Models\VehicleGroup;
use App\Services\AuditLogsService;
use Livewire\WithPagination;

class OperatorRowsImport
{
    use WithPagination, WithFileUploads;

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

        $type = strtolower($this->importFile->getClientOriginalExtension()) === 'xlsx'
            ? ExcelType::XLSX
            : ExcelType::CSV;

        $sheet = Excel::toCollection(
            new OperatorRowsImport,
            $this->importFile->getRealPath(),
            null,
            $type
        )->first() ?? collect();

        $rows = [];
        $seenPlates = [];

        foreach ($sheet as $index => $raw) {
            $raw = $raw->toArray();

            // Skip completely blank rows
            if (collect($raw)->filter(fn ($v) => filled($v))->isEmpty()) {
                continue;
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
}

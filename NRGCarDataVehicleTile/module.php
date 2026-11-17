<?php

declare(strict_types=1);

/**
 * Read-only Symcon HTML SDK tile for NRGCarDataVehicle.
 * The adapter supplies telematics and the original BMW CarData vehicle image.
 */
class NRGCarDataVehicleTile extends IPSModule
{
    private const ADAPTER_GUID = '{48F2EE15-0D72-40E4-8E94-612A178D554E}';
    // Stable, explicitly mapped CarData slots. New telemetry never changes
    // the layout or replaces one KPI with a different metric.
    private const KEY_ODOMETER = 'vehicle.vehicle.travelledDistance';
    private const KEY_LOCK = 'vehicle.cabin.door.lock.status';
    private const KEY_PRECONDITIONING = 'vehicle.cabin.hvac.preconditioning.status.comfortState';
    private const KEY_CHARGE_POWER = 'vehicle.powertrain.electric.battery.charging.power';
    private const KEY_CHARGE_REMAINING = 'vehicle.drivetrain.electricEngine.charging.timeRemaining';
    private const KEY_PLUG = 'vehicle.body.chargingPort.status';
    private const KEY_TRUNK = 'vehicle.body.trunk.door.isOpen';
    private const DOOR_KEYS = [
        'vehicle.cabin.door.row1.driver.isOpen',
        'vehicle.cabin.door.row1.passenger.isOpen',
        'vehicle.cabin.door.row2.driver.isOpen',
        'vehicle.cabin.door.row2.passenger.isOpen',
    ];
    private const TIRE_KEYS = [
        'vehicle.chassis.axle.row1.wheel.left.tire.pressure',
        'vehicle.chassis.axle.row1.wheel.right.tire.pressure',
        'vehicle.chassis.axle.row2.wheel.left.tire.pressure',
        'vehicle.chassis.axle.row2.wheel.right.tire.pressure',
    ];

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyInteger('SourceInstanceID', 0);
        $this->RegisterPropertyBoolean('ShowUpdateTime', true);
        $this->RegisterAttributeString('SubscribedVariables', '[]');
        $this->RegisterAttributeString('LastImageFingerprint', '');
        $this->RegisterAttributeInteger('LastRenderedAt', 0);
        $this->RegisterTimer('Refresh', 60000, 'NRGCDT_Refresh(' . $this->InstanceID . ');');
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetVisualizationType(1);
        $this->refreshSubscriptions();
        $this->sendMessage(true);
    }

    public function Destroy()
    {
        // References and messages are removed automatically with the instance.
        parent::Destroy();
    }

    public function GetConfigurationForm()
    {
        $adapterOptions = [['caption' => 'Bitte Fahrzeug wählen', 'value' => 0]];
        foreach (IPS_GetInstanceListByModuleID(self::ADAPTER_GUID) as $id) {
            $adapterOptions[] = ['caption' => IPS_GetName($id) . ' (#' . $id . ')', 'value' => $id];
        }

        return json_encode([
            'elements' => [
                [
                    'type' => 'Select', 'name' => 'SourceInstanceID',
                    'caption' => 'NRGCarData-Fahrzeugadapter', 'options' => $adapterOptions,
                ],
                [
                    'type' => 'CheckBox', 'name' => 'ShowUpdateTime',
                    'caption' => 'Letzten Symcon-Empfang und Aktualität des Ladestands anzeigen',
                ],
            ],
            'status' => [
                ['code' => 200, 'icon' => 'inactive', 'caption' => 'Kein Fahrzeugadapter ausgewählt'],
                ['code' => 201, 'icon' => 'error', 'caption' => 'Ausgewählter Fahrzeugadapter fehlt'],
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        // JSON_HEX_TAG prevents a vehicle name containing </script> from
        // terminating the initializer and injecting arbitrary script.
        $initial = json_encode($this->payload(true), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
        $script = "<script>handleMessage(" . $initial . ");</script>";
        return str_replace('</body>', $script . "\n</body>", $html);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message !== VM_UPDATE || IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
        if (time() - $this->ReadAttributeInteger('LastRenderedAt') < 2) {
            return;
        }
        try {
            $this->sendMessage(false, false);
        } catch (Throwable $e) {
            // During module reload, both the source and this tile may vanish.
        }
    }

    /** Discover local variables; image retries use the adapter's 15 min cache. */
    public function Refresh(): void
    {
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
        $this->refreshSubscriptions();
        $this->sendMessage(false);
    }

    /** Read-only diagnostic endpoint for test scripts. */
    public function GetDisplayData(): string
    {
        return $this->message(true);
    }

    private function message(bool $includeImage = false): string
    {
        return json_encode($this->payload($includeImage), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function sendMessage(bool $forceImage, bool $checkImage = true): void
    {
        $source = $this->resolveSource();
        $fingerprint = $this->ReadAttributeString('LastImageFingerprint');
        $includeImage = $forceImage;
        if ($checkImage) {
            $image = ($source > 0 && function_exists('NRGCD_GetVehicleImage'))
                ? (string) @NRGCD_GetVehicleImage($source)
                : '';
            $newFingerprint = $source . ':' . ($image !== '' ? hash('sha256', $image) : '');
            $includeImage = $forceImage || $fingerprint !== $newFingerprint;
            $fingerprint = $newFingerprint;
        }
        $this->UpdateVisualizationValue($this->message($includeImage));
        if ($checkImage) {
            $this->WriteAttributeString('LastImageFingerprint', $fingerprint);
        }
        $this->WriteAttributeInteger('LastRenderedAt', time());
    }

    private function payload(bool $includeImage = false): array
    {
        $source = $this->resolveSource();
        $default = [
            'available' => false,
            'name' => 'Fahrzeug',
            'vin' => '',
            'soc' => null,
            'rangeKm' => null,
            'chargeLimit' => null,
            'charging' => null,
            'lastReceiptAt' => null,
            'socReceivedAt' => null,
            'showUpdateTime' => $this->ReadPropertyBoolean('ShowUpdateTime'),
            'kpis' => [
                'odometerKm' => null, 'lock' => null,
                'preconditioning' => null, 'charging' => null,
            ],
            'supplemental' => [
                'plug' => null, 'doors' => null, 'trunk' => null,
                'tiresBar' => [null, null, null, null],
                'chargingPowerKw' => null, 'chargingRemainingMin' => null,
            ],
        ];
        if ($includeImage) {
            $default['image'] = '';
        }
        if ($source <= 0 || !function_exists('NRGCD_GetVehicleState')) {
            return $default;
        }
        $state = json_decode((string) @NRGCD_GetVehicleState($source), true);
        if (!is_array($state)) {
            return $default;
        }

        $stateSource = (int) ($state['sourceInstanceID'] ?? 0);
        $available = $stateSource > 0 && IPS_InstanceExists($stateSource);
        $out = array_merge($default, [
            'available' => $available,
            'name' => (string) ($state['name'] ?? IPS_GetName($source)),
            'vin' => (string) ($state['vin'] ?? ''),
            'soc' => $state['soc'] ?? null,
            'rangeKm' => $state['rangeKm'] ?? null,
            'chargeLimit' => $state['chargeLimit'] ?? null,
            'charging' => $state['charging'] ?? null,
            'socReceivedAt' => $state['socUpdatedAt'] ?? null,
        ]);
        if ($includeImage && function_exists('NRGCD_GetVehicleImage')) {
            $out['image'] = (string) @NRGCD_GetVehicleImage($source);
        }

        if (!function_exists('NRGCD_GetTelemetry')) {
            return $out;
        }
        $telemetry = json_decode((string) @NRGCD_GetTelemetry($source), true);
        if (!is_array($telemetry) || !is_array($telemetry['fields'] ?? null)) {
            return $out;
        }
        $index = [];
        $lastReceipt = 0;
        foreach ($telemetry['fields'] as $field) {
            if (is_array($field) && isset($field['key'])) {
                $index[(string) $field['key']] = $field;
                $lastReceipt = max($lastReceipt, (int) ($field['receivedAt'] ?? 0));
            }
        }
        $out['lastReceiptAt'] = $lastReceipt > 0 ? $lastReceipt : null;
        $out['kpis'] = [
            'odometerKm' => $this->numericValue($index, self::KEY_ODOMETER, 0, 1000000),
            'lock' => $this->lockValue($index),
            'preconditioning' => $this->preconditioningValue($index),
            'charging' => $state['charging'] ?? null,
        ];
        $plug = strtoupper(trim((string) ($index[self::KEY_PLUG]['value'] ?? '')));
        $out['supplemental']['plug'] = match ($plug) {
            'CONNECTED' => 'Verbunden', 'DISCONNECTED' => 'Getrennt', default => null,
        };
        $out['supplemental']['doors'] = $this->doorSummary($index);
        $out['supplemental']['trunk'] = $this->openState($index[self::KEY_TRUNK]['value'] ?? null);
        foreach (self::TIRE_KEYS as $wheel => $key) {
            $pressure = $this->numericValue($index, $key, 0, 1000);
            $out['supplemental']['tiresBar'][$wheel] = $pressure !== null ? round($pressure / 100, 2) : null;
        }
        if ($state['charging'] === true) {
            $watts = $this->numericValue($index, self::KEY_CHARGE_POWER, 0, 500000);
            $out['supplemental']['chargingPowerKw'] = $watts !== null ? round($watts / 1000, 2) : null;
            $out['supplemental']['chargingRemainingMin'] = $this->numericValue($index, self::KEY_CHARGE_REMAINING, 0, 10000);
        }
        return $out;
    }

    private function numericValue(array $index, string $key, float $min, float $max): ?float
    {
        $raw = $index[$key]['value'] ?? null;
        if (!is_numeric($raw) || is_bool($raw)) {
            return null;
        }
        $value = (float) $raw;
        if (!is_finite($value) || $value < $min || $value > $max) {
            return null;
        }
        return $value;
    }

    private function lockValue(array $index): ?string
    {
        $code = strtoupper(trim((string) ($index[self::KEY_LOCK]['value'] ?? '')));
        return match ($code) {
            'SECURED' => 'Gesichert', 'LOCKED' => 'Verriegelt',
            'UNLOCKED' => 'Entriegelt', 'SELECTIVELOCKED' => 'Teilentriegelt',
            default => null,
        };
    }

    /** CarData comfortState is a read-only status, not a control command. */
    private function preconditioningValue(array $index): ?string
    {
        $code = strtoupper(trim((string) ($index[self::KEY_PRECONDITIONING]['value'] ?? '')));
        return match ($code) {
            'NOT_ACTIVE' => 'Inaktiv',
            'COMFORT_HEATING' => 'Heizt',
            'COMFORT_COOLING' => 'Kühlt',
            'COMFORT_VENTILATION' => 'Lüftet',
            'DEFROST' => 'Enteist',
            'COMFORT_UNDEFINED' => 'Unbestimmt',
            default => null,
        };
    }

    private function openState($value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'Offen' : 'Geschlossen';
        }
        return match (strtoupper(trim((string) $value))) {
            'OPEN', 'TRUE' => 'Offen', 'CLOSED', 'FALSE' => 'Geschlossen',
            default => null,
        };
    }

    private function doorSummary(array $index): ?string
    {
        $known = 0;
        $open = 0;
        foreach (self::DOOR_KEYS as $key) {
            $state = $this->openState($index[$key]['value'] ?? null);
            if ($state === null) {
                continue;
            }
            ++$known;
            if ($state === 'Offen') {
                ++$open;
            }
        }
        if ($open > 0) {
            return $open === 1 ? '1 Tür offen' : $open . ' Türen offen';
        }
        return $known === count(self::DOOR_KEYS) ? 'Alle geschlossen' : null;
    }

    private function resolveSource(): int
    {
        $configured = $this->ReadPropertyInteger('SourceInstanceID');
        if ($configured > 0 && $this->isAdapter($configured)) {
            return $configured;
        }
        if ($configured > 0) {
            return 0; // explicit invalid selection must not silently switch cars
        }
        $candidates = IPS_GetInstanceListByModuleID(self::ADAPTER_GUID);
        return count($candidates) === 1 ? (int) $candidates[0] : 0;
    }

    private function isAdapter(int $id): bool
    {
        if (!IPS_InstanceExists($id)) {
            return false;
        }
        return strtoupper((string) (IPS_GetInstance($id)['ModuleInfo']['ModuleID'] ?? '')) === self::ADAPTER_GUID;
    }

    private function refreshSubscriptions(): void
    {
        $source = $this->resolveSource();
        $desired = [];
        if ($source > 0 && function_exists('NRGCD_GetVehicleState')) {
            $state = json_decode((string) @NRGCD_GetVehicleState($source), true);
            $rawSource = (int) ($state['sourceInstanceID'] ?? 0);
            if ($rawSource > 0 && IPS_InstanceExists($rawSource)) {
                foreach (IPS_GetChildrenIDs($rawSource) as $id) {
                    if (IPS_VariableExists($id)) {
                        $desired[] = (int) $id;
                    }
                }
            }
        }
        $desired = array_values(array_unique($desired));
        $previous = json_decode($this->ReadAttributeString('SubscribedVariables'), true);
        $previous = is_array($previous) ? array_map('intval', $previous) : [];
        foreach (array_diff($previous, $desired) as $id) {
            $this->UnregisterMessage($id, VM_UPDATE);
            $this->UnregisterReference($id);
        }
        foreach (array_diff($desired, $previous) as $id) {
            $this->RegisterReference($id);
            $this->RegisterMessage($id, VM_UPDATE);
        }
        $this->WriteAttributeString('SubscribedVariables', json_encode($desired));

        $this->SetStatus($source > 0 ? 102 : ($this->ReadPropertyInteger('SourceInstanceID') > 0 ? 201 : 200));
    }
}

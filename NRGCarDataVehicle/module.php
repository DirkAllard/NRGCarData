<?php

/**
 * Read-only NRG adapter for the BMW CarData Vehicle module by parcivad.
 *
 * The existing BMW CarData module owns authentication, MQTT and data storage.
 * This adapter exposes its selected variables through the NRG vehicle contract.
 * No vehicle commands are implemented.
 */
class NRGCarDataVehicle extends IPSModule
{
    private const BMW_VEHICLE_GUID = '{E147D4C3-A21A-F7C9-EE09-F54D5FD91B86}';

    private const FIELD_KEYS = [
        'soc' => [
            'vehicle.drivetrain.batteryManagement.header',
            'vehicle.powertrain.electric.battery.stateOfCharge.displayed',
            'vehicle.trip.segment.end.drivetrain.batteryManagement.hvSoc',
        ],
        'charging' => [
            'vehicle.drivetrain.electricEngine.charging.status',
            'vehicle.drivetrain.electricEngine.charging.hvStatus',
            'vehicle.body.chargingPort.status',
            'vehicle.body.chargingPort.combinedStatus',
        ],
        'chargeLimit' => ['vehicle.powertrain.electric.battery.stateOfCharge.target'],
        'rangeKm' => [
            'vehicle.drivetrain.electricEngine.kombiRemainingElectricRange',
            'vehicle.drivetrain.lastRemainingRange',
            'vehicle.drivetrain.electricEngine.remainingElectricRange',
        ],
    ];

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyInteger('SourceInstanceID', 0);
        $this->RegisterAttributeString('CachedVehicleImage', '');
        $this->RegisterAttributeString('CachedImageIdentity', '');
        $this->RegisterAttributeInteger('ImageLastAttempt', 0);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $source = $this->sourceInstanceID();
        if ($source === 0) {
            $this->SetStatus(200); // CarData source not selected
        } elseif (!$this->isBMWVehicle($source)) {
            $this->SetStatus(201); // not a supported BMW CarData instance
        } elseif (IPS_GetInstance($source)['InstanceStatus'] !== 102) {
            $this->SetStatus(202); // configured CarData instance is not active
        } else {
            $this->SetStatus(102);
        }
    }

    public function GetConfigurationForm()
    {
        $options = [['caption' => 'Bitte wählen', 'value' => 0]];
        foreach (IPS_GetInstanceListByModuleID(self::BMW_VEHICLE_GUID) as $instanceID) {
            $vin = trim((string) IPS_GetProperty($instanceID, 'vin'));
            $options[] = [
                'caption' => IPS_GetName($instanceID) . ($vin !== '' ? ' (' . $vin . ')' : ''),
                'value' => $instanceID,
            ];
        }

        return json_encode([
            'status' => [
                ['code' => 200, 'icon' => 'inactive', 'caption' => 'Keine BMW CarData Fahrzeugquelle gewählt'],
                ['code' => 201, 'icon' => 'error', 'caption' => 'Fahrzeugquelle fehlt oder ist kein BMW CarData Vehicle'],
                ['code' => 202, 'icon' => 'error', 'caption' => 'BMW CarData Fahrzeugquelle ist nicht aktiv'],
            ],
            'elements' => [
                ['type' => 'Select', 'name' => 'SourceInstanceID', 'caption' => 'BMW CarData Fahrzeuginstanz', 'options' => $options],
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * NRG vehicle contract (1.6, read-only subset).
     * Unknown values are null, unknown Symcon variable IDs are 0.
     * 'connected' describes vehicle availability in Tessie; CarData does not
     * provide equivalent evidence, so it is deliberately null here.
     */
    public function GetVehicleState(): string
    {
        $source = $this->sourceInstanceID();
        $source = $this->isBMWVehicle($source) ? $source : 0;

        $socField = $this->firstNumeric($source, self::FIELD_KEYS['soc'], 0.0, 100.0);
        $limitField = $this->firstNumeric($source, self::FIELD_KEYS['chargeLimit'], 0.0, 100.0);
        $rangeField = $this->firstNumeric($source, self::FIELD_KEYS['rangeKm'], 0.0, null);
        $chargingField = $this->chargingField($source);

        $socVar = $socField['id'] ?? 0;
        $chargingVar = $chargingField['id'] ?? 0;
        $vin = $source > 0 ? trim((string) IPS_GetProperty($source, 'vin')) : '';

        return json_encode([
            'contractVersion' => '1.6',
            'instanceID' => $this->InstanceID,
            'name' => IPS_GetName($this->InstanceID),
            'vin' => $vin,
            'socID' => $socVar,
            'soc' => $socField['value'] ?? null,
            'connected' => null,
            'chargingID' => $chargingVar,
            'charging' => $chargingField['charging'] ?? null,
            'chargeLimit' => $limitField['value'] ?? null,
            'chargeAmpsRequest' => null,
            'chargeAmpsMax' => null,
            'atHome' => null,
            'scheduledChargingActive' => null,
            'scheduledDeparture' => null,
            'energyRemainingKwh' => null,
            'batteryCapacityKwh' => null,
            'distanceToHomeKm' => null,
            'headingHome' => null,
            'expectedHomeArrivalSocPercent' => null,
            'rangeKm' => $rangeField['value'] ?? null,
            'vehicleStatus' => null,
            // Additive fields. IPS VariableUpdated is the local receipt time,
            // not necessarily the timestamp at which the BMW measured a value.
            'sourceModule' => 'BMW CarData',
            'sourceInstanceID' => $source,
            'readOnly' => true,
            'socUpdatedAt' => $socVar > 0 ? $this->updatedAt($socVar) : null,
            'chargingUpdatedAt' => $chargingVar > 0 ? $this->updatedAt($chargingVar) : null,
        ], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** Diagnostic inventory, resolved against the selected source instance. */
    public function GetFieldMap(): string
    {
        $source = $this->sourceInstanceID();
        $source = $this->isBMWVehicle($source) ? $source : 0;
        $result = [];
        foreach (self::FIELD_KEYS as $field => $keys) {
            $result[$field] = [];
            foreach ($keys as $key) {
                $id = $this->variableID($source, $key);
                $result[$field][] = [
                    'key' => $key,
                    'variableID' => $id,
                    'value' => $id > 0 ? GetValue($id) : null,
                    'updatedAt' => $id > 0 ? $this->updatedAt($id) : null,
                ];
            }
        }
        return json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Full inventory of variables actually created by the BMW CarData module.
     * This intentionally does not invent values for descriptors that have
     * been enabled in the portal but have never been delivered by the stream.
     */
    public function GetTelemetry(): string
    {
        $source = $this->sourceInstanceID();
        $source = $this->isBMWVehicle($source) ? $source : 0;
        $fields = [];
        if ($source > 0) {
            foreach (IPS_GetChildrenIDs($source) as $id) {
                $object = IPS_GetObject($id);
                if (($object['ObjectType'] ?? -1) !== 2 || !IPS_VariableExists($id)) {
                    continue;
                }
                $variable = IPS_GetVariable($id);
                $fields[] = [
                    'key' => $object['ObjectName'],
                    'ident' => $object['ObjectIdent'],
                    'variableID' => $id,
                    'variableType' => $variable['VariableType'],
                    'value' => GetValue($id),
                    'receivedAt' => $this->updatedAt($id),
                ];
            }
        }
        usort($fields, static fn(array $a, array $b): int => strcmp($a['key'], $b['key']));

        return json_encode([
            'contractVersion' => '1.0',
            'instanceID' => $this->InstanceID,
            'sourceInstanceID' => $source,
            'count' => count($fields),
            'fields' => $fields,
        ], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Actual BMW vehicle image from the already installed BMW CarData Vehicle
     * instance. The BMW module handles authentication and the REST request.
     * Cache by source instance AND VIN to avoid cross-vehicle image leakage.
     * Failed requests are throttled to one attempt every 15 minutes.
     */
    public function GetVehicleImage(): string
    {
        $source = $this->sourceInstanceID();
        if (!$this->isBMWVehicle($source)) {
            return '';
        }
        $vin = trim((string) IPS_GetProperty($source, 'vin'));
        if ($vin === '') {
            return '';
        }
        $identity = $source . ':' . $vin;
        if ($this->ReadAttributeString('CachedImageIdentity') !== $identity) {
            $this->WriteAttributeString('CachedImageIdentity', $identity);
            $this->WriteAttributeString('CachedVehicleImage', '');
            $this->WriteAttributeInteger('ImageLastAttempt', 0);
        }

        $cached = $this->ReadAttributeString('CachedVehicleImage');
        if ($cached !== '') {
            return $cached;
        }

        $now = time();
        if ($now - $this->ReadAttributeInteger('ImageLastAttempt') < 900 ||
            !function_exists('BMW_getImage') ||
            IPS_GetKernelRunlevel() !== KR_READY) {
            return '';
        }

        // Set the attempt timestamp before calling the network-backed method
        // so concurrent tile refreshes do not amplify the request.
        $this->WriteAttributeInteger('ImageLastAttempt', $now);
        try {
            $image = (string) @BMW_getImage($source);
            if (!str_starts_with($image, 'data:image/png;base64,')) {
                return '';
            }
            $encoded = substr($image, strlen('data:image/png;base64,'));
            if (strlen($encoded) > 12 * 1024 * 1024) {
                return '';
            }
            $binary = base64_decode($encoded, true);
            if ($binary === false || substr($binary, 0, 8) !== "\x89PNG\r\n\x1a\n") {
                return '';
            }
            $this->WriteAttributeString('CachedVehicleImage', $image);
            return $image;
        } catch (Throwable $e) {
            return '';
        }
    }

    private function sourceInstanceID(): int
    {
        return $this->ReadPropertyInteger('SourceInstanceID');
    }

    private function isBMWVehicle(int $instanceID): bool
    {
        if ($instanceID <= 0 || !IPS_InstanceExists($instanceID)) {
            return false;
        }
        $instance = IPS_GetInstance($instanceID);
        return strtoupper((string) ($instance['ModuleInfo']['ModuleID'] ?? '')) === self::BMW_VEHICLE_GUID;
    }

    private function variableID(int $source, string $key): int
    {
        if ($source <= 0) {
            return 0;
        }
        // parcivad/ips-bmw-cardata: updateVariables() strips literal dots.
        $ident = str_replace('.', '', $key);
        $id = @IPS_GetObjectIDByIdent($ident, $source);
        if (!is_int($id) || $id <= 0 || !IPS_VariableExists($id)) {
            return 0;
        }
        return $id;
    }

    private function updatedAt(int $id): ?int
    {
        $ts = (int) (IPS_GetVariable($id)['VariableUpdated'] ?? 0);
        return $ts > 0 ? $ts : null;
    }

    private function firstNumeric(int $source, array $keys, float $min, ?float $max): ?array
    {
        foreach ($keys as $key) {
            $id = $this->variableID($source, $key);
            if ($id <= 0) {
                continue;
            }
            $raw = GetValue($id);
            if ((!is_string($raw) && !is_int($raw) && !is_float($raw)) || !is_numeric($raw)) {
                continue;
            }
            $value = (float) $raw;
            if (!is_finite($value) || $value < $min || ($max !== null && $value > $max)) {
                continue;
            }
            return ['id' => $id, 'value' => $value];
        }
        return null;
    }

    private function chargingField(int $source): ?array
    {
        $chargeTrue = ['CHARGINGACTIVE', 'CHARGING'];
        $chargeFalse = [
            'INITIALIZATION', 'CHARGINGPAUSED', 'CHARGINGENDED',
            'WAITING_FOR_CHARGING', 'FINISHED_FULLY_CHARGED', 'FINISHED_NOT_FULL',
        ];
        // Keep the raw status ID for VariableChanged, even if its enum is unknown.
        $firstID = 0;
        // Port state is a fallback, not proof that charging is active.
        foreach (self::FIELD_KEYS['charging'] as $key) {
            $id = $this->variableID($source, $key);
            if ($id <= 0) {
                continue;
            }
            if ($firstID === 0) {
                $firstID = $id;
            }
            $value = strtoupper(trim((string) GetValue($id)));
            if (in_array($value, $chargeTrue, true)) {
                return ['id' => $id, 'charging' => true];
            }
            if (in_array($value, $chargeFalse, true)) {
                return ['id' => $id, 'charging' => false];
            }
            if ($value === 'DISCONNECTED') {
                return ['id' => $id, 'charging' => false];
            }
        }
        return $firstID > 0 ? ['id' => $firstID, 'charging' => null] : null;
    }
}

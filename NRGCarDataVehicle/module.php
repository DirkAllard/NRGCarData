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
        'connected' => [
            'vehicle.body.chargingPort.status',
            'vehicle.body.chargingPort.combinedStatus',
            'vehicle.drivetrain.electricEngine.charging.connectorStatus',
            'vehicle.powertrain.tractionBattery.charging.port.rearMiddle.isPlugged',
            'vehicle.powertrain.tractionBattery.charging.port.rearRight.isPlugged',
        ],
        'chargePowerW' => ['vehicle.powertrain.electric.battery.charging.power'],
        'chargeTimeRemainingMin' => ['vehicle.drivetrain.electricEngine.charging.timeRemaining'],
        'chargeAmpsRequest' => ['vehicle.powertrain.electric.battery.charging.acLimit.selected'],
        'chargeAmpsMax' => ['vehicle.powertrain.electric.battery.charging.acLimit.max'],
        'bidirectionalCapable' => ['vehicle.powertrain.electric.battery.biDirectionalCharging.availability'],
        'dischargeAllowed' => ['vehicle.drivetrain.electricEngine.charging.profile.settings.biDirectionalCharging.dischargeAllowed'],
        'targetSocProfessionalPercent' => ['vehicle.powertrain.electric.battery.stateOfCharge.targetSoCForProfessionalMode'],
        'departureRelevant' => ['vehicle.drivetrain.electricEngine.charging.profile.settings.biDirectionalCharging.departureTimeRelevant'],
    ];

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyInteger('SourceInstanceID', 0);
        $this->RegisterPropertyFloat('BatteryCapacityKwh', 0.0);
        // Legacy image attribute is kept only to clear or migrate existing data.
        $this->RegisterAttributeString('CachedVehicleImage', '');
        $this->RegisterAttributeString('CachedImageIdentity', '');
        $this->RegisterAttributeString('CachedMediaIdentity', '');
        $this->RegisterAttributeInteger('ImageLastAttempt', 0);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->migrateLegacyImage();
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
                [
                    'type' => 'NumberSpinner', 'name' => 'BatteryCapacityKwh',
                    'caption' => 'Nutzbare Batteriekapazität (kWh; 0 = unbekannt)',
                    'minimum' => 0, 'digits' => 1, 'suffix' => 'kWh',
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * NRG vehicle contract (1.6, read-only subset).
     * Unknown values are null, unknown Symcon variable IDs are 0.
     * This legacy 1.6 contract is kept for existing consumers. The NRG-Stack
     * 1.0 contract is independently available through GetVehicleData().
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

    /**
     * Manufacturer-neutral NRG-Stack vehicle contract 1.0. The full contract
     * is independent of the HTML tile and its limited set of subscriptions.
     * Every known contract key is present; unavailable measurements are null.
     */
    public function GetVehicleData(): string
    {
        $v = json_decode($this->GetVehicleState(), true) ?: [];
        $source = $this->sourceInstanceID();
        $source = $this->isBMWVehicle($source) ? $source : 0;
        $charging = $v['charging'] ?? null;
        $connected = $this->connectionState($source);
        if ($connected === null && $charging === true) {
            $connected = true;
        }
        $capacity = $this->ReadPropertyFloat('BatteryCapacityKwh');
        $capacity = is_finite($capacity) && $capacity > 0.0 && $capacity <= 300.0 ? $capacity : null;
        $power = $this->firstNumeric($source, self::FIELD_KEYS['chargePowerW'], 0.0, 500000.0);
        $time = $charging === true
            ? $this->firstNumeric($source, self::FIELD_KEYS['chargeTimeRemainingMin'], 0.0, 10000.0)
            : null;
        $limit = $v['chargeLimit'] ?? null;
        $state = $this->chargeState($source);

        return json_encode([
            'contractType' => 'vehicle',
            'contractVersion' => '1.0',
            'instanceID' => $this->InstanceID,
            'name' => IPS_GetName($this->InstanceID),
            'provider' => 'BMW CarData',
            // Required: never use 0 as a substitute for missing measurements.
            'soc' => $v['soc'] ?? null,
            'connected' => $connected,
            'charging' => $charging,
            'chargeLimit' => $limit === null ? null : (int) round((float) $limit),
            'atHome' => null,
            'batteryCapacityKwh' => $capacity,
            'lastSeenAt' => $this->lastSeenAt($source),
            'socID' => (int) ($v['socID'] ?? 0),
            'chargingID' => (int) ($v['chargingID'] ?? 0),
            // Optional fields from the same contract, including V2H fields.
            'vin' => $v['vin'] ?? '',
            'manufacturer' => null,
            'powertrain' => null,
            'chargeState' => $state,
            'chargePowerW' => $charging === false ? 0.0 : ($power['value'] ?? null),
            'chargeAmpsRequest' => $this->firstNumeric($source, self::FIELD_KEYS['chargeAmpsRequest'], 0.0, 1000.0)['value'] ?? null,
            'chargeAmpsMax' => $this->firstNumeric($source, self::FIELD_KEYS['chargeAmpsMax'], 0.0, 1000.0)['value'] ?? null,
            'chargeTimeRemainingMin' => $time === null ? null : (int) round($time['value']),
            'energyRemainingKwh' => null,
            'rangeKm' => $v['rangeKm'] ?? null,
            'scheduledChargingActive' => null,
            'scheduledDepartureAt' => null,
            'distanceToHomeKm' => null,
            'headingHome' => null,
            'expectedHomeArrivalSocPercent' => null,
            'vehicleStatus' => null,
            'bidirectionalCapable' => $this->firstBoolean($source, self::FIELD_KEYS['bidirectionalCapable']),
            'dischargeAllowed' => $this->firstBoolean($source, self::FIELD_KEYS['dischargeAllowed']),
            'targetSocProfessionalPercent' => $this->firstNumeric($source, self::FIELD_KEYS['targetSocProfessionalPercent'], 0.0, 100.0)['value'] ?? null,
            'departureRelevant' => $this->firstBoolean($source, self::FIELD_KEYS['departureRelevant']),
        ], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function lastSeenAt(int $source): ?int
    {
        $last = 0;
        if ($source > 0) {
            foreach (IPS_GetChildrenIDs($source) as $id) {
                if (IPS_VariableExists($id)) {
                    $last = max($last, $this->updatedAt($id) ?? 0);
                }
            }
        }
        // BMW CarData exposes Symcon's local receipt/update time, not the
        // vehicle's original measurement timestamp.
        return $last > 0 ? $last : null;
    }

    private function connectionState(int $source): ?bool
    {
        foreach (self::FIELD_KEYS['connected'] as $key) {
            $id = $this->variableID($source, $key);
            if ($id <= 0) {
                continue;
            }
            $raw = GetValue($id);
            if (is_bool($raw)) {
                return $raw;
            }
            $code = strtoupper(trim((string) $raw));
            if (in_array($code, ['CONNECTED', 'PLUGGED', 'PLUGGED_IN', 'PLUGGEDIN'], true)) {
                return true;
            }
            if (in_array($code, ['DISCONNECTED', 'UNPLUGGED', 'NOT_CONNECTED', 'NOTCONNECTED'], true)) {
                return false;
            }
        }
        return null;
    }

    private function chargeState(int $source): ?string
    {
        foreach (self::FIELD_KEYS['charging'] as $key) {
            $id = $this->variableID($source, $key);
            if ($id <= 0) {
                continue;
            }
            $code = strtoupper(trim((string) GetValue($id)));
            $mapped = match ($code) {
                'CHARGINGACTIVE', 'CHARGING' => 'charging',
                'INITIALIZATION' => 'starting',
                'CHARGINGPAUSED' => 'paused',
                'WAITING_FOR_CHARGING' => 'idle',
                'FINISHED_FULLY_CHARGED' => 'complete',
                // A stopped or prematurely ended session does not prove
                // that the charging target was reached.
                'DISCONNECTED' => 'disconnected',
                default => null,
            };
            if ($mapped !== null) {
                return $mapped;
            }
        }
        return null;
    }

    private function firstBoolean(int $source, array $keys): ?bool
    {
        foreach ($keys as $key) {
            $id = $this->variableID($source, $key);
            if ($id <= 0) {
                continue;
            }
            $raw = GetValue($id);
            if (is_bool($raw)) {
                return $raw;
            }
            if (is_string($raw)) {
                $state = strtolower(trim($raw));
                if ($state === 'true') {
                    return true;
                }
                if ($state === 'false') {
                    return false;
                }
            }
        }
        return null;
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
            $this->WriteAttributeInteger('ImageLastAttempt', 0);
        }
        $mediaID = $this->imageMediaID(false);
        if ($this->ReadAttributeString('CachedMediaIdentity') === $identity && $mediaID > 0) {
            try {
                $encoded = IPS_GetMediaContent($mediaID);
                if ($encoded !== '') {
                    return 'data:image/png;base64,' . $encoded;
                }
            } catch (Throwable $e) {
                // Empty media file: retry through the normal throttled path.
            }
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
            $mediaID = $this->imageMediaID(true);
            if ($mediaID <= 0 || !IPS_SetMediaContent($mediaID, $encoded)) {
                return '';
            }
            $this->WriteAttributeString('CachedMediaIdentity', $identity);
            $this->WriteAttributeString('CachedVehicleImage', '');
            return $image;
        } catch (Throwable $e) {
            return '';
        }
    }

    /** Keep the BMW PNG in a Symcon media object, not an instance attribute. */
    private function imageMediaID(bool $create): int
    {
        $id = @IPS_GetObjectIDByIdent('VehicleImage', $this->InstanceID);
        if (is_int($id) && $id > 0 && IPS_MediaExists($id)) {
            return $id;
        }
        if (!$create) {
            return 0;
        }
        $id = IPS_CreateMedia(1); // MEDIATYPE_IMAGE
        IPS_SetParent($id, $this->InstanceID);
        IPS_SetIdent($id, 'VehicleImage');
        IPS_SetName($id, 'Fahrzeugbild');
        IPS_SetMediaFile($id, 'media/NRGCarDataVehicle_' . $this->InstanceID . '.png', false);
        IPS_SetMediaCached($id, true);
        return $id;
    }

    /** One-time upgrade of the former Base64 attribute without another BMW call. */
    private function migrateLegacyImage(): void
    {
        $legacy = $this->ReadAttributeString('CachedVehicleImage');
        if ($legacy === '') {
            return;
        }
        $identity = $this->ReadAttributeString('CachedImageIdentity');
        $source = $this->sourceInstanceID();
        if ($source > 0 && $this->isBMWVehicle($source)) {
            $current = $source . ':' . trim((string) IPS_GetProperty($source, 'vin'));
            if ($identity === $current && str_starts_with($legacy, 'data:image/png;base64,')) {
                $encoded = substr($legacy, strlen('data:image/png;base64,'));
                $binary = base64_decode($encoded, true);
                if ($binary !== false && substr($binary, 0, 8) === "\x89PNG\r\n\x1a\n") {
                    try {
                        $id = $this->imageMediaID(true);
                        if (IPS_SetMediaContent($id, $encoded)) {
                            $this->WriteAttributeString('CachedMediaIdentity', $current);
                        }
                    } catch (Throwable $e) {
                        return; // Keep the legacy cache until migration succeeds.
                    }
                }
            }
        }
        $this->WriteAttributeString('CachedVehicleImage', '');
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

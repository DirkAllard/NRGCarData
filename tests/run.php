<?php

/**
 * Run outside Symcon: php NRGCarData/tests/run.php
 * Minimal in-memory IPS stubs. No BMW API access, no network, no changes to
 * production Symcon instances.
 */
declare(strict_types=1);

define('VM_UPDATE', 10603);
define('KR_READY', 10103);

$GLOBALS['ipsInstances'] = [];
$GLOBALS['ipsVariables'] = [];
$GLOBALS['ipsModules'] = [];
$GLOBALS['ipsMedia'] = [];

class IPSModule
{
    protected int $InstanceID;
    private array $properties = [];
    private array $attributes = [];
    private array $messages = [];
    public int $status = 0;
    public string $lastVisualizationMessage = '';

    public function __construct(int $id)
    {
        $this->InstanceID = $id;
        $this->Create();
    }

    public function Create() {}
    public function ApplyChanges() {}
    public function Destroy() {}
    protected function RegisterPropertyInteger(string $name, int $default): void { $this->properties[$name] = $default; }
    protected function RegisterPropertyString(string $name, string $default): void { $this->properties[$name] = $default; }
    protected function RegisterPropertyBoolean(string $name, bool $default): void { $this->properties[$name] = $default; }
    protected function RegisterPropertyFloat(string $name, float $default): void { $this->properties[$name] = $default; }
    protected function ReadPropertyInteger(string $name): int { return (int) $this->properties[$name]; }
    protected function ReadPropertyString(string $name): string { return (string) $this->properties[$name]; }
    protected function ReadPropertyBoolean(string $name): bool { return (bool) $this->properties[$name]; }
    protected function ReadPropertyFloat(string $name): float { return (float) $this->properties[$name]; }
    protected function RegisterAttributeString(string $name, string $default): void { $this->attributes[$name] = $default; }
    protected function ReadAttributeString(string $name): string { return $this->attributes[$name]; }
    protected function WriteAttributeString(string $name, string $value): void { $this->attributes[$name] = $value; }
    protected function RegisterAttributeInteger(string $name, int $default): void { $this->attributes[$name] = $default; }
    protected function ReadAttributeInteger(string $name): int { return (int) $this->attributes[$name]; }
    protected function WriteAttributeInteger(string $name, int $value): void { $this->attributes[$name] = $value; }
    protected function RegisterTimer(string $ident, int $interval, string $script): void {}
    protected function SetVisualizationType(int $type): void {}
    protected function SetStatus(int $status): void { $this->status = $status; }
    protected function RegisterReference(int $id): void {}
    protected function UnregisterReference(int $id): void {}
    protected function RegisterMessage(int $id, int $message): void { $this->messages[$id][] = $message; }
    protected function UnregisterMessage(int $id, int $message): void { unset($this->messages[$id]); }
    protected function GetMessageList(): array { return $this->messages; }
    protected function UpdateVisualizationValue(string $json): void { $this->lastVisualizationMessage = $json; }

    public function configure(string $key, $value): void { $this->properties[$key] = $value; }
    public function subscribedIDs(): array { return array_keys($this->messages); }
}

function IPS_InstanceExists(int $id): bool { return isset($GLOBALS['ipsInstances'][$id]); }
function IPS_GetInstance(int $id): array { return $GLOBALS['ipsInstances'][$id]; }
function IPS_GetInstanceListByModuleID(string $guid): array
{
    return array_keys(array_filter($GLOBALS['ipsInstances'], static fn($v) => ($v['ModuleInfo']['ModuleID'] ?? '') === $guid));
}
function IPS_GetName(int $id): string { return $GLOBALS['ipsInstances'][$id]['name'] ?? 'Unbenannt'; }
function IPS_GetProperty(int $id, string $name) { return $GLOBALS['ipsInstances'][$id]['properties'][$name] ?? null; }
function IPS_GetObjectIDByIdent(string $ident, int $parent)
{
    foreach ($GLOBALS['ipsMedia'] as $id => $m) {
        if (($m['parent'] ?? 0) === $parent && ($m['ident'] ?? '') === $ident) {
            return $id;
        }
    }
    foreach ($GLOBALS['ipsVariables'] as $id => $v) {
        if ($v['parent'] === $parent && $v['ObjectIdent'] === $ident) {
            return $id;
        }
    }
    return false;
}
function IPS_GetChildrenIDs(int $parent): array
{
    return array_keys(array_filter($GLOBALS['ipsVariables'], static fn($v) => $v['parent'] === $parent));
}
function IPS_VariableExists(int $id): bool { return isset($GLOBALS['ipsVariables'][$id]); }
function IPS_GetVariable(int $id): array { return $GLOBALS['ipsVariables'][$id]['variable']; }
function IPS_GetObject(int $id): array
{
    $v = $GLOBALS['ipsVariables'][$id];
    return ['ObjectType' => 2, 'ObjectIdent' => $v['ObjectIdent'], 'ObjectName' => $v['ObjectName']];
}
function GetValue(int $id) { return $GLOBALS['ipsVariables'][$id]['value']; }
function IPS_GetKernelRunlevel(): int { return KR_READY; }
function IPS_CreateMedia(int $type): int
{
    $id = 1000 + count($GLOBALS['ipsMedia']);
    $GLOBALS['ipsMedia'][$id] = ['type' => $type, 'content' => '', 'parent' => 0, 'ident' => ''];
    return $id;
}
function IPS_MediaExists(int $id): bool { return isset($GLOBALS['ipsMedia'][$id]); }
function IPS_SetParent(int $id, int $parent): void { $GLOBALS['ipsMedia'][$id]['parent'] = $parent; }
function IPS_SetIdent(int $id, string $ident): void { $GLOBALS['ipsMedia'][$id]['ident'] = $ident; }
function IPS_SetName(int $id, string $name): void { $GLOBALS['ipsMedia'][$id]['name'] = $name; }
function IPS_SetMediaFile(int $id, string $path, bool $mustExist): bool { $GLOBALS['ipsMedia'][$id]['file'] = $path; return true; }
function IPS_SetMediaCached(int $id, bool $cached): bool { $GLOBALS['ipsMedia'][$id]['cached'] = $cached; return true; }
function IPS_SetMediaContent(int $id, string $content): bool { $GLOBALS['ipsMedia'][$id]['content'] = $content; return true; }
function IPS_GetMediaContent(int $id): string { return $GLOBALS['ipsMedia'][$id]['content']; }

require_once __DIR__ . '/../NRGCarDataVehicle/module.php';
require_once __DIR__ . '/../NRGCarDataVehicleTile/module.php';

function NRGCD_GetVehicleState(int $id): string { return $GLOBALS['ipsModules'][$id]->GetVehicleState(); }
function NRGCD_GetVehicleData(int $id): string { return $GLOBALS['ipsModules'][$id]->GetVehicleData(); }
function NRGCD_GetTelemetry(int $id): string { return $GLOBALS['ipsModules'][$id]->GetTelemetry(); }
function NRGCD_GetVehicleImage(int $id): string { return $GLOBALS['ipsModules'][$id]->GetVehicleImage(); }
function BMW_getImage(int $id): string
{
    $GLOBALS['imageCalls'][$id] = ($GLOBALS['imageCalls'][$id] ?? 0) + 1;
    return 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\n" . 'fake-image-' . $id);
}

function testAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function addValue(int $id, int $parent, string $key, $value): void
{
    $type = is_bool($value) ? 0 : (is_int($value) ? 1 : (is_float($value) ? 2 : 3));
    $GLOBALS['ipsVariables'][$id] = [
        'parent' => $parent,
        'ObjectIdent' => str_replace('.', '', $key),
        'ObjectName' => $key,
        'value' => $value,
        'variable' => ['VariableType' => $type, 'VariableUpdated' => 1700000000],
    ];
}

try {
    $bmwGuid = '{E147D4C3-A21A-F7C9-EE09-F54D5FD91B86}';
    $adapterGuid = '{48F2EE15-0D72-40E4-8E94-612A178D554E}';
    $GLOBALS['ipsInstances'][100] = [
        'ModuleInfo' => ['ModuleID' => $bmwGuid], 'InstanceStatus' => 102,
        'name' => 'BMW iX1', 'properties' => ['vin' => 'TESTVIN123'],
    ];
    $GLOBALS['ipsInstances'][200] = [
        'ModuleInfo' => ['ModuleID' => $adapterGuid], 'InstanceStatus' => 102,
        'name' => 'BMW iX1 – NRG', 'properties' => [],
    ];
    addValue(301, 100, 'vehicle.drivetrain.batteryManagement.header', 52);
    addValue(302, 100, 'vehicle.drivetrain.electricEngine.charging.status', 'CHARGINGACTIVE');
    addValue(303, 100, 'vehicle.powertrain.electric.battery.stateOfCharge.target', 80);
    addValue(304, 100, 'vehicle.drivetrain.electricEngine.kombiRemainingElectricRange', 293);
    addValue(306, 100, 'vehicle.powertrain.electric.battery.charging.power', 6400);
    addValue(307, 100, 'vehicle.vehicle.travelledDistance', 20458);
    addValue(308, 100, 'vehicle.cabin.door.lock.status', 'SECURED');
    addValue(309, 100, 'vehicle.powertrain.electric.battery.charging.acLimit.selected', 32);
    addValue(310, 100, 'vehicle.body.chargingPort.status', 'CONNECTED');
    addValue(311, 100, 'vehicle.cabin.door.row1.driver.isOpen', 'CLOSED');
    addValue(312, 100, 'vehicle.cabin.door.row1.passenger.isOpen', 'CLOSED');
    addValue(313, 100, 'vehicle.cabin.door.row2.driver.isOpen', 'CLOSED');
    addValue(314, 100, 'vehicle.cabin.door.row2.passenger.isOpen', 'CLOSED');
    addValue(315, 100, 'vehicle.body.trunk.door.isOpen', false);
    addValue(316, 100, 'vehicle.chassis.axle.row1.wheel.left.tire.pressure', 240);
    addValue(317, 100, 'vehicle.chassis.axle.row1.wheel.right.tire.pressure', 250);
    addValue(318, 100, 'vehicle.chassis.axle.row2.wheel.left.tire.pressure', 270);
    addValue(319, 100, 'vehicle.chassis.axle.row2.wheel.right.tire.pressure', 260);
    addValue(320, 100, 'vehicle.drivetrain.electricEngine.charging.timeRemaining', 28);
    addValue(321, 100, 'vehicle.cabin.hvac.preconditioning.status.comfortState', 'COMFORT_HEATING');
    $GLOBALS['ipsVariables'][308]['variable']['VariableUpdated'] = 1700000900;

    $vehicle = new NRGCarDataVehicle(200);
    $vehicle->configure('SourceInstanceID', 100);
    $vehicle->ApplyChanges();
    $GLOBALS['ipsModules'][200] = $vehicle;
    testAssert($vehicle->status === 102, 'Adapter source status');

    $state = json_decode($vehicle->GetVehicleState(), true, 512, JSON_THROW_ON_ERROR);
    testAssert($state['contractVersion'] === '1.6', 'Contract version');
    testAssert($state['soc'] === 52.0 && $state['socID'] === 301, 'SoC from header');
    testAssert($state['charging'] === true && $state['chargingID'] === 302, 'Charging enum');
    testAssert($state['chargeLimit'] === 80.0 && $state['rangeKm'] === 293.0, 'Limit and range');
    testAssert($state['connected'] === null && $state['batteryCapacityKwh'] === null, 'Unknown stays unknown');

    $data = json_decode($vehicle->GetVehicleData(), true, 512, JSON_THROW_ON_ERROR);
    testAssert($data['contractType'] === 'vehicle' && $data['contractVersion'] === '1.0', 'NRG contract version');
    testAssert($data['provider'] === 'NRGCD' && $data['manufacturer'] === 'BMW' && $data['instanceID'] === 200, 'NRG provider and manufacturer');
    testAssert($data['connected'] === true && $data['charging'] === true, 'NRG plug and charge states are independent');
    testAssert($data['soc'] === 52.0 && $data['chargeLimit'] === 80, 'NRG SoC and integer charge target');
    testAssert($data['chargeState'] === 'charging' && $data['chargePowerW'] === 6400.0, 'NRG charging details');
    testAssert($data['chargeTimeRemainingMin'] === 28, 'NRG charging target time in minutes');
    testAssert($data['chargeAmpsRequest'] === 32.0, 'NRG charge amperes read independently of the tile');
    testAssert($data['batteryCapacityKwh'] === null && $data['atHome'] === false, 'Unknown capacity is null; unknown location means not at home');
    testAssert($data['lastSeenAt'] === 1700000900, 'NRG lastSeenAt from all available BMW variables');
    $contractKeys = [
        'contractType', 'contractVersion', 'instanceID', 'name', 'provider',
        'soc', 'connected', 'charging', 'chargeLimit', 'atHome', 'batteryCapacityKwh',
        'lastSeenAt', 'socID', 'chargingID', 'vin', 'manufacturer', 'powertrain',
        'chargeState', 'chargePowerW', 'chargeAmpsRequest', 'chargeAmpsMax',
        'chargeTimeRemainingMin', 'energyRemainingKwh', 'rangeKm',
        'scheduledChargingActive', 'scheduledDepartureAt', 'distanceToHomeKm',
        'headingHome', 'expectedHomeArrivalSocPercent', 'vehicleStatus',
        'bidirectionalCapable', 'dischargeAllowed', 'targetSocProfessionalPercent',
        'departureRelevant',
    ];
    testAssert(array_keys($data) === $contractKeys, 'Every NRG contract key must exist');
    $vehicle->configure('BatteryCapacityKwh', 71.5);
    testAssert(json_decode($vehicle->GetVehicleData(), true)['batteryCapacityKwh'] === 71.5, 'Manual usable capacity');

    $telemetry = json_decode($vehicle->GetTelemetry(), true, 512, JSON_THROW_ON_ERROR);
    testAssert($telemetry['count'] === 20, 'Complete source variable inventory');
    testAssert(str_starts_with($vehicle->GetVehicleImage(), 'data:image/png;base64,'), 'Real BMW-image function wired to adapter');
    $firstImage = $vehicle->GetVehicleImage();
    testAssert($vehicle->GetVehicleImage() === $firstImage, 'Image cached as data URI');
    testAssert(($GLOBALS['imageCalls'][100] ?? 0) === 1, 'Cached image avoids additional BMW API calls');
    $imageMediaID = IPS_GetObjectIDByIdent('VehicleImage', 200);
    testAssert(IPS_MediaExists($imageMediaID), 'Image stored as child media object');
    testAssert($GLOBALS['ipsMedia'][$imageMediaID]['content'] !== '', 'PNG payload stored in media');

    $GLOBALS['ipsInstances'][400] = [
        'ModuleInfo' => ['ModuleID' => '{9E4E3D3F-4414-4682-BFA9-781ACC4FB099}'],
        'InstanceStatus' => 102, 'name' => 'Kachel', 'properties' => [],
    ];
    $tile = new NRGCarDataVehicleTile(400);
    $tile->configure('SourceInstanceID', 200);
    $tile->ApplyChanges();
    $tileState = json_decode($tile->GetDisplayData(), true, 512, JSON_THROW_ON_ERROR);
    testAssert($tileState['soc'] === 52.0 && $tileState['available'] === true, 'Tile contract');
    testAssert($tileState['kpis']['odometerKm'] === 20458.0, 'Stable odometer slot');
    testAssert($tileState['kpis']['lock'] === 'Gesichert', 'Stable lock slot');
    testAssert($tileState['kpis']['chargeTimeRemainingMin'] === 28, 'Remaining charge time replaces preconditioning KPI');
    testAssert(!array_key_exists('acLimitA', $tileState['kpis']), 'AC limit removed from fixed KPI layout');
    testAssert($tileState['supplemental']['plug'] === 'Verbunden', 'Charging port descriptor');
    testAssert($tileState['supplemental']['doors'] === 'Alle geschlossen', 'All doors known closed');
    testAssert($tileState['supplemental']['trunk'] === 'Geschlossen', 'Trunk signal');
    testAssert($tileState['supplemental']['tiresBar'] === [2.4, 2.5, 2.7, 2.6], 'Tire pressure kPa to bar');
    testAssert($tileState['supplemental']['chargingPowerKw'] === 6.4, 'Charging power W to kW');
    testAssert($tileState['supplemental']['chargingRemainingMin'] === 28.0, 'Charging time minutes');
    testAssert($tileState['socReceivedAt'] === 1700000000, 'SoC timestamp kept separate');
    testAssert($tileState['lastReceiptAt'] === 1700000900, 'Last receipt across all source fields');
    testAssert(str_starts_with($tileState['image'], 'data:image/png;base64,'), 'BMW vehicle image available in tile');
    testAssert(!str_contains($tile->GetConfigurationForm(), 'AdditionalFields'), 'No manual extra-field form');
    testAssert(!str_contains($tile->lastVisualizationMessage, 'data:image/png;base64,') || str_contains($tile->lastVisualizationMessage, 'image'), 'Image only sent with data contract');
    testAssert(str_contains($tile->GetVisualizationTile(), 'handleMessage('), 'HTML SDK initializer');
    testAssert(!str_contains($tile->GetVisualizationTile(), 'id="sheet"'), 'No detail overlay');
    testAssert(str_contains($tile->GetVisualizationTile(), 'id="charge-remaining"'), 'Charging time has a fixed visual KPI slot');
    testAssert(!str_contains($tile->GetVisualizationTile(), 'Vorklimatisierung'), 'Preconditioning removed from tile');
    testAssert(count($tile->subscribedIDs()) < count(IPS_GetChildrenIDs(100)), 'Tile subscribes only to displayed signals');
    testAssert(!in_array(321, $tile->subscribedIDs(), true), 'Unrelated preconditioning signal not subscribed');
    $watchedBefore = $tile->subscribedIDs();
    addValue(330, 100, 'vehicle.test.unusedTelemetry', 123);
    $GLOBALS['ipsVariables'][330]['variable']['VariableUpdated'] = 1700001234;
    $tile->Refresh();
    testAssert($tile->subscribedIDs() === $watchedBefore, 'Unrelated new telemetry does not add a tile subscription');
    $data = json_decode($vehicle->GetVehicleData(), true, 512, JSON_THROW_ON_ERROR);
    testAssert($data['lastSeenAt'] === 1700001234, 'NRG receipt time follows full telemetry, not tile subscriptions');
    testAssert(json_decode($vehicle->GetTelemetry(), true)['count'] === 21, 'Full telemetry includes unobserved fields');
    addValue(331, 100, 'vehicle.powertrain.electric.battery.charging.acLimit.max', 32);
    addValue(332, 100, 'vehicle.drivetrain.electricEngine.charging.profile.settings.biDirectionalCharging.dischargeAllowed', true);
    addValue(333, 100, 'vehicle.powertrain.electric.battery.stateOfCharge.targetSoCForProfessionalMode', 25);
    addValue(334, 100, 'vehicle.drivetrain.electricEngine.charging.profile.settings.biDirectionalCharging.departureTimeRelevant', false);
    $data = json_decode($vehicle->GetVehicleData(), true, 512, JSON_THROW_ON_ERROR);
    testAssert($data['chargeAmpsMax'] === 32.0, 'NRG charge-current limit from additional BMW field');
    testAssert($data['dischargeAllowed'] === true && $data['departureRelevant'] === false, 'NRG V2H Boolean fields retain true/false');
    testAssert($data['targetSocProfessionalPercent'] === 25.0, 'NRG professional target SoC');
    testAssert(!str_contains($tile->GetVisualizationTile(), 'id="ac-limit"'), 'Old AC limit KPI removed');
    testAssert(strpos($tile->GetVisualizationTile(), 'handleMessage(') < strpos($tile->GetVisualizationTile(), '</body>'), 'Initializer inside HTML body');

    $GLOBALS['ipsVariables'][320]['value'] = 91;
    $display = json_decode($tile->GetDisplayData(), true, 512, JSON_THROW_ON_ERROR);
    testAssert($display['kpis']['chargeTimeRemainingMin'] === 91, 'Charging time updated independently of fixed KPI layout');
    $GLOBALS['ipsVariables'][320]['value'] = 28;

    $GLOBALS['ipsVariables'][302]['value'] = 'CHARGINGPAUSED';
    $state = json_decode($vehicle->GetVehicleState(), true, 512, JSON_THROW_ON_ERROR);
    testAssert($state['charging'] === false, 'Paused is not charging');
    $data = json_decode($vehicle->GetVehicleData(), true, 512, JSON_THROW_ON_ERROR);
    testAssert($data['chargeState'] === 'paused' && $data['chargePowerW'] === 0.0 && $data['chargeTimeRemainingMin'] === null, 'Paused charging clears power and time');

    $GLOBALS['ipsVariables'][302]['value'] = 'NEW_UNKNOWN_STATE';
    $state = json_decode($vehicle->GetVehicleState(), true, 512, JSON_THROW_ON_ERROR);
    testAssert($state['charging'] === null && $state['chargingID'] === 302, 'Unknown enum remains null');
    $data = json_decode($vehicle->GetVehicleData(), true, 512, JSON_THROW_ON_ERROR);
    testAssert($data['chargeState'] === null, 'Unknown charge status remains null in NRG contract');

    $GLOBALS['ipsVariables'][301]['value'] = 150;
    addValue(305, 100, 'vehicle.powertrain.electric.battery.stateOfCharge.displayed', '53');
    $state = json_decode($vehicle->GetVehicleState(), true, 512, JSON_THROW_ON_ERROR);
    testAssert($state['soc'] === 53.0 && $state['socID'] === 305, 'Invalid header falls back');
    $tileAfterNewField = json_decode($tile->GetDisplayData(), true, 512, JSON_THROW_ON_ERROR);
    testAssert(array_keys($tileAfterNewField['kpis']) === array_keys($tileState['kpis']), 'New telemetry cannot reprioritize KPI slots');

    // GPS geofence works separately from the tile and every other signal.
    $vehicle->configure('UseHomeLocation', true);
    $vehicle->configure('HomeLatitude', 50.0000);
    $vehicle->configure('HomeLongitude', 8.0000);
    $vehicle->configure('HomeRadiusMeters', 250);
    testAssert(json_decode($vehicle->GetVehicleData(), true)['atHome'] === false, 'Missing GPS pair => false');
    addValue(340, 100, 'vehicle.cabin.infotainment.navigation.currentLocation.latitude', 50.0005);
    testAssert(json_decode($vehicle->GetVehicleData(), true)['atHome'] === false, 'Partial GPS pair => false');
    addValue(341, 100, 'vehicle.cabin.infotainment.navigation.currentLocation.longitude', 8.0005);
    testAssert(json_decode($vehicle->GetVehicleData(), true)['atHome'] === true, 'Inside geofence => true');
    testAssert(json_decode($vehicle->GetVehicleState(), true)['atHome'] === true, 'Contracts use same location');
    $GLOBALS['ipsVariables'][340]['value'] = 50.0100;
    testAssert(json_decode($vehicle->GetVehicleData(), true)['atHome'] === false, 'Outside geofence => false');
    $GLOBALS['ipsVariables'][340]['value'] = 'invalid';
    testAssert(json_decode($vehicle->GetVehicleData(), true)['atHome'] === false, 'Invalid GPS => false');
    $GLOBALS['ipsVariables'][340]['value'] = 0.0;
    $GLOBALS['ipsVariables'][341]['value'] = 0.0;
    testAssert(json_decode($vehicle->GetVehicleData(), true)['atHome'] === false, 'Uninitialised 0/0 => false');
    $GLOBALS['ipsVariables'][340]['value'] = 50.0005;
    $GLOBALS['ipsVariables'][341]['value'] = 8.0005;
    $vehicle->configure('UseHomeLocation', false);
    testAssert(json_decode($vehicle->GetVehicleData(), true)['atHome'] === false, 'Disabled geofence => false');
    $vehicle->configure('UseHomeLocation', true);
    testAssert(str_contains($vehicle->GetConfigurationForm(), '"digits":4'), 'Four decimal input for coordinates');

    // Second vehicle on the same BMW transport must not leak values or VIN.
    $GLOBALS['ipsInstances'][101] = [
        'ModuleInfo' => ['ModuleID' => $bmwGuid], 'InstanceStatus' => 102,
        'name' => 'MINI', 'properties' => ['vin' => 'MINITESTVIN'],
    ];
    $GLOBALS['ipsInstances'][201] = [
        'ModuleInfo' => ['ModuleID' => $adapterGuid], 'InstanceStatus' => 102,
        'name' => 'MINI – NRG', 'properties' => [],
    ];
    addValue(401, 101, 'vehicle.drivetrain.batteryManagement.header', 68);
    $mini = new NRGCarDataVehicle(201);
    $mini->configure('SourceInstanceID', 101);
    $mini->ApplyChanges();
    $GLOBALS['ipsModules'][201] = $mini;
    $miniState = json_decode($mini->GetVehicleState(), true, 512, JSON_THROW_ON_ERROR);
    testAssert($miniState['soc'] === 68.0 && $miniState['vin'] === 'MINITESTVIN', 'Independent MINI');
    $miniData = json_decode($mini->GetVehicleData(), true, 512, JSON_THROW_ON_ERROR);
    testAssert($miniData['connected'] === null && $miniData['chargeTimeRemainingMin'] === null, 'MINI does not inherit BMW cable or charge duration');
    testAssert(json_decode($vehicle->GetVehicleState(), true)['soc'] === 53.0, 'BMW unchanged by MINI');
    testAssert($mini->GetVehicleImage() !== $vehicle->GetVehicleImage(), 'Separate BMW image cache for separate vehicles');
    testAssert(IPS_GetObjectIDByIdent('VehicleImage', 200) !== IPS_GetObjectIDByIdent('VehicleImage', 201), 'Independent media objects for BMW and MINI');

    $ambiguous = new NRGCarDataVehicleTile(500);
    $ambiguous->ApplyChanges();
    $ambiguousState = json_decode($ambiguous->GetDisplayData(), true, 512, JSON_THROW_ON_ERROR);
    testAssert($ambiguousState['available'] === false, 'Multiple vehicles require explicit tile selection');

    echo "NRGCarData adapter + tile: tests passed\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'TEST FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}

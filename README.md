# NRG-Stack CarData – Fahrzeugadapter und Kachel

Dieser Adapter verwendet **bestehende** Symcon-Instanzen von
[`parcivad/ips-bmw-cardata`](https://github.com/parcivad/ips-bmw-cardata).
Das Fremdmodul bleibt führend für BMW-Authentifizierung, CarData-MQTT-Stream
und das Anlegen der ausgewählten Variablen. Es wird kein zweiter Transport
aufgebaut. Jedes BMW- oder MINI-Fahrzeug bekommt eine eigene Adapterinstanz,
die auf die zugehörige BMW-CarData-Fahrzeuginstanz verweist.

Der Adapter stellt `NRGCD_GetVehicleState($id)` als **lesenden** JSON-Vertrag
in der Feldstruktur von `TESSIE_GetVehicleState()` v1.6 bereit. Die Quellvariablen werden über den technischen BMW-Schlüssel
gefunden (Ident = Schlüssel ohne Punkte). Für nicht
gelieferte Felder stehen `null` bzw. `0` bei Variablen-IDs.

| NRG-Feld | BMW-CarData-Telematik |
| --- | --- |
| `soc`/`socID` | `vehicle.drivetrain.batteryManagement.header`; anschließend `vehicle.powertrain.electric.battery.stateOfCharge.displayed`; dann trip-end fallback |
| `charging`/`chargingID` | `vehicle.drivetrain.electricEngine.charging.status`; anschließend `...hvStatus` und Portstatus |
| `chargeLimit` | `vehicle.powertrain.electric.battery.stateOfCharge.target` |
| `rangeKm` | `vehicle.drivetrain.electricEngine.kombiRemainingElectricRange`; anschließend weitere Reichweitenfelder |

Die Statuswerte für den Ladevorgang orientieren sich an der überprüften
[evcc-BMW-CarData-Implementierung](https://github.com/evcc-io/evcc/blob/master/vehicle/bmw/cardata/provider.go).

**Wichtig:** `socUpdatedAt` entspricht dem
letzten Variablenempfang in Symcon und nicht zwingend der Messzeit im Fahrzeug.

`NRGCD_GetFieldMap($id)` zeigt die Zuordnung und jeweils vorhandene Werte.
`NRGCD_GetTelemetry($id)` liefert darüber hinaus **alle tatsächlich
angelegten** Variablen der ausgewählten CarData-Fahrzeuginstanz mit technischem
Schlüssel, Wert, Datentyp und Symcon-Empfangszeit. 

## Symcon-Kachel und BMW-Fahrzeugbild

Das zweite Modul `NRGCarDataVehicleTile` ist eine unabhängige HTML-SDK-Kachel
(`SetVisualizationType(1)`). Sie nutzt die lesenden Vertragsfunktionen
des Adapters und zeigt:

- Das originale Fahrzeugbild der BMW-CarData-API (kein lokales Modellbild),
  **zentriert beschnitten**: Die Auto-Größe bleibt erhalten, der Bildrahmen
  benötigt deutlich weniger horizontale Fläche.
- Ladestand mit Balkenanzeige und Markierung des Ladelimits sowie Reichweite.
- **Vier feste KPI-Plätze** für Kilometerstand, Verriegelung,
  Vorklimatisierung und Ladevorgang. Wenn das Auto lädt, stehen Ladeleistung
  und verbleibende Ladezeit zusätzlich im Ladevorgangsfeld.
- **Drei feste ergänzende Plätze** für Ladeanschluss, Türen/Heckklappe und
  Reifendruck aller vier Reifen (bar). Nicht gelieferte Daten erscheinen als
  Gedankenstrich. Zusätzliche CarData-Messwerte verändern weder die Reihenfolge
  noch die Gestaltung der Kennzahlen.
- Zwei getrennte Aktualitätsangaben: **letzter Symcon-Empfang irgendeines
  vorhandenen Telemetriefelds** und **Alter des zuletzt empfangenen SoC-Werts**.
  Beide beruhen auf `VariableUpdated` in Symcon, nicht auf einem nachgewiesenen
  BMW-Messzeitpunkt.
  
Feste Zuordnungen zu CarData-Bezeichnern (optional, sofern die Werte tatsächlich
als Variablen im BMW-Modul vorhanden sind):

| Kachelbereich | CarData-Bezeichner |
| --- | --- |
| Kilometerstand | `vehicle.vehicle.travelledDistance` |
| Verriegelung | `vehicle.cabin.door.lock.status` |
| Vorklimatisierung | `vehicle.cabin.hvac.preconditioning.status.comfortState` (`NOT_ACTIVE`, `COMFORT_HEATING`, `COMFORT_COOLING`, `COMFORT_VENTILATION`, `DEFROST`, `COMFORT_UNDEFINED`) |
| Ladeleistung | `vehicle.powertrain.electric.battery.charging.power` (W → kW) |
| Restladezeit | `vehicle.drivetrain.electricEngine.charging.timeRemaining` (min) |
| Ladeanschluss | `vehicle.body.chargingPort.status` |
| Türen | `vehicle.cabin.door.row1.driver.isOpen`, `vehicle.cabin.door.row1.passenger.isOpen`, `vehicle.cabin.door.row2.driver.isOpen`, `vehicle.cabin.door.row2.passenger.isOpen` |
| Heckklappe | `vehicle.body.trunk.door.isOpen` |
| Reifendruck | `vehicle.chassis.axle.row1.wheel.left.tire.pressure`, `vehicle.chassis.axle.row1.wheel.right.tire.pressure`, `vehicle.chassis.axle.row2.wheel.left.tire.pressure`, `vehicle.chassis.axle.row2.wheel.right.tire.pressure` (kPa → bar) |

`NRGCD_GetVehicleImage($adapterID)` verwendet die vom installierten BMW-Modul
angebotene Funktion `BMW_getImage($fahrzeugID)` und damit dessen vorhandene
Authentifizierung für `GET /customers/vehicles/{vin}/image`. Das gelieferte
PNG wird fahrzeugbezogen im Adapter zwischengespeichert. Nur ein Fehlversuch
wird frühestens nach 15 Minuten wiederholt. Bei einem anderen Fahrzeug/VIN
verfällt der Cache. Das Bild wird in der laufenden Kachel nur bei Änderung
übertragen.

Die Kachel abonniert `VM_UPDATE` für vorhandene Variablen der BMW-CarData-
Quellinstanz. Ein lokaler 60-Sekunden-Timer findet nachträglich angelegte
Variablen, aktualisiert die Kachel und prüft den Bildcache. Nur bei noch
fehlendem Bild kann der Adapter in diesem Zuge den bestehenden BMW-REST-
Transport erneut nutzen. 

Die Vorklimatisierung wird ausschließlich **lesend** abgebildet. Eine spätere
Schaltfläche/Aktion ist nur möglich, falls die dafür verwendete Schnittstelle
eine ausdrücklich unterstützte Schreibfunktion bietet. 

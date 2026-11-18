# BMW Fahrzeugdaten – Datenadapter und Kachel

Die Module heißen im Symcon-Store **BMW Datenadapter** und **BMW Fahrzeugkachel**.
Der Adapter bereitet Fahrzeugdaten zur Weiterverarbeitung auf; die Kachel
stellt ausgewählte Fahrzeugwerte übersichtlich dar.

Dieser Adapter verwendet **bestehende** Symcon-Instanzen von
[`parcivad/ips-bmw-cardata`](https://github.com/parcivad/ips-bmw-cardata).
Das Fremdmodul bleibt führend für BMW-Authentifizierung, CarData-MQTT-Stream
und das Anlegen der ausgewählten Variablen. Es wird kein zweiter Transport
aufgebaut. Jedes BMW- oder MINI-Fahrzeug bekommt eine eigene Adapterinstanz,
die auf die zugehörige BMW-CarData-Fahrzeuginstanz verweist.

Der Adapter stellt `NRGCD_GetVehicleData($id)` als **lesende,
herstellerneutrale Fahrzeugschnittstelle** bereit.

Setzt den analog Tessie (siehe [GitHub - DG65/NRGTessie · GitHub](http://github.com/DG65/NRGTessie)) den Fahrzeugvertrag `vehicle` 1.0 um.

Alle im Vertrag definierten Schlüssel werden unabhängig von der Kachel geliefert;
nicht verfügbare Messwerte bleiben `null`, auch `atHome` bei unbekannter Position.
Die ältere Funktion `NRGCD_GetVehicleState($id)` (v1.6) bleibt aus
Kompatibilitätsgründen unverändert verfügbar. Die Quellvariablen werden über
den technischen BMW-Schlüssel gefunden (Ident = Schlüssel ohne Punkte).
Für nicht gelieferte Felder stehen `null` bzw. `0` bei Variablen-IDs.

| Vertragsfeld | BMW-CarData-Telematik |
| --- | --- |
| `soc`/`socID` | `vehicle.drivetrain.batteryManagement.header`; anschließend `vehicle.powertrain.electric.battery.stateOfCharge.displayed`; dann trip-end fallback |
| `charging`/`chargingID` | `vehicle.drivetrain.electricEngine.charging.status`; anschließend `...hvStatus` und Portstatus |
| `chargeLimit` | `vehicle.powertrain.electric.battery.stateOfCharge.target` |
| `rangeKm` | `vehicle.drivetrain.electricEngine.kombiRemainingElectricRange`; anschließend weitere Reichweitenfelder |
| `connected` | `vehicle.body.chargingPort.status` (Ladekabel gesteckt, unabhängig davon, ob geladen wird); alternative eindeutige Port-Statusfelder |
| `chargeState` | Normalisierter Ladestatus (`starting`, `charging`, `paused`, `idle`, `complete`, `disconnected`; sonst `null`) |
| `chargePowerW` | `vehicle.powertrain.electric.battery.charging.power` (W); bei sicher inaktivem Ladevorgang 0 W |
| `chargeTimeRemainingMin` | `vehicle.drivetrain.electricEngine.charging.timeRemaining` (Minuten; nur beim Laden) |
| `chargeAmpsRequest` / `chargeAmpsMax` | `vehicle.powertrain.electric.battery.charging.acLimit.selected` / `.max` (A) |
| `batteryCapacityKwh` | Manuell konfigurierbare nutzbare Netto-Kapazität in kWh (0 in der Konfiguration = unbekannt) |
| `lastSeenAt` | Größter `VariableUpdated`-Zeitstempel aller tatsächlich angelegten BMW-Quellvariablen (Unix-Sekunden) |
| `provider` / `manufacturer` | Feste Werte `NRGCD` / `BMW` |
| `atHome` | BMW-CarData-GPS-Felder `vehicle.cabin.infotainment.navigation.currentLocation.latitude` und `.longitude`; berechneter Abstand zum konfigurierten Heimstandort |
| `bidirectionalCapable`, `dischargeAllowed`, `targetSocProfessionalPercent`, `departureRelevant` | Entsprechende BMW-CarData-Felder, sofern eindeutig als Boolean bzw. Prozentwert geliefert |

Die übrigen Vertragsfelder (`energyRemainingKwh`, geplante Abfahrt,
Navigation/Heimweg und Fahrzeugstatus) bleiben ohne belastbare BMW-Datenquelle
`null`. Eine 0 wird nicht als Ersatz für unbekannte Messwerte verwendet.
`manufacturer` ist entsprechend dem Fahrzeugvertrag fest `BMW`, auch wenn das
Quellmodul technisch MINI-Fahrzeuge unterstützt; `powertrain` bleibt unbekannt.

**Heimposition im Fahrzeugadapter:** Optional aktivierbar; geografische Breite
und Länge werden mit jeweils vier Nachkommastellen eingegeben. Der Heimradius
ist konfigurierbar (250 m als Standard, erlaubt sind 25 bis 5000 m). Die
Berechnung verwendet die Haversine-Formel. Ohne aktivierte Heimposition oder
bei fehlendem, unvollständigem oder ungültigem Fahrzeug-GPS-Paar, ebenso bei
0/0, ist `atHome` **`null`**. Bei gültiger Position ist der Wert `true`,
wenn das Fahrzeug innerhalb des Heimradius steht, andernfalls `false`.
BMW-GPS-Messwerte können älter sein als der letzte Fahrzeugstatus; die
GPS-Schlüssel müssen im BMW-CarData-Stream aktiviert sein.

Die Statuswerte für den Ladevorgang orientieren sich an der überprüften
[evcc-BMW-CarData-Implementierung](https://github.com/evcc-io/evcc/blob/master/vehicle/bmw/cardata/provider.go).

**Wichtig:** `socUpdatedAt` und `lastSeenAt` entsprechen dem
letzten Variablenempfang in Symcon und nicht zwingend der Messzeit im Fahrzeug.

`NRGCD_GetFieldMap($id)` zeigt die Zuordnung und jeweils vorhandene Werte.
`NRGCD_GetTelemetry($id)` liefert darüber hinaus **alle tatsächlich
angelegten** Variablen der ausgewählten CarData-Fahrzeuginstanz mit technischem
Schlüssel, Wert, Datentyp und Symcon-Empfangszeit. 

## BMW Fahrzeugkachel und BMW-Fahrzeugbild

Die **BMW Fahrzeugkachel** (technischer Modulordner: `NRGCarDataVehicleTile`)
ist eine unabhängige HTML-SDK-Kachel
(`SetVisualizationType(1)`). Sie nutzt die lesenden Vertragsfunktionen
des Adapters und zeigt:

- Das originale Fahrzeugbild der BMW-CarData-API (kein lokales Modellbild),
  **zentriert beschnitten**: Die Auto-Größe bleibt erhalten, der Bildrahmen
  benötigt deutlich weniger horizontale Fläche.
- Ladestand mit Balkenanzeige und Markierung des Ladelimits sowie Reichweite.
- **Vier feste KPI-Plätze** für Kilometerstand, Verriegelung,
  verbleibende Zeit bis zum Ladeziel und Ladevorgang. Die Restladezeit stammt
  aus dem Fahrzeugvertrag und wird nur beim Laden angezeigt; die Ladeleistung
  steht im Ladevorgangsfeld.
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
| Bis zum Ladeziel | `vehicle.drivetrain.electricEngine.charging.timeRemaining` (Minuten; nur während des Ladevorgangs) |
| Ladeleistung | `vehicle.powertrain.electric.battery.charging.power` (W → kW) |
| Ladeanschluss | `vehicle.body.chargingPort.status` |
| Türen | `vehicle.cabin.door.row1.driver.isOpen`, `vehicle.cabin.door.row1.passenger.isOpen`, `vehicle.cabin.door.row2.driver.isOpen`, `vehicle.cabin.door.row2.passenger.isOpen` |
| Heckklappe | `vehicle.body.trunk.door.isOpen` |
| Reifendruck | `vehicle.chassis.axle.row1.wheel.left.tire.pressure`, `vehicle.chassis.axle.row1.wheel.right.tire.pressure`, `vehicle.chassis.axle.row2.wheel.left.tire.pressure`, `vehicle.chassis.axle.row2.wheel.right.tire.pressure` (kPa → bar) |

`NRGCD_GetVehicleImage($adapterID)` verwendet die vom installierten BMW-Modul
angebotene Funktion `BMW_getImage($fahrzeugID)` und damit dessen vorhandene
Authentifizierung für `GET /customers/vehicles/{vin}/image`. Das gelieferte
PNG wird fahrzeugbezogen als **Symcon-Medienobjekt** unter der Adapterinstanz
zwischengespeichert, nicht mehr als bis zu 12 MB großes Instanzattribut.
Ein bisheriger Attribut-Cache wird beim Update automatisch übernommen.
Ein Fehlversuch
wird frühestens nach 15 Minuten wiederholt. Bei einem anderen Fahrzeug/VIN
verfällt der Cache. Das Bild wird in der laufenden Kachel nur bei Änderung
übertragen.

Die Kachel abonniert `VM_UPDATE` **nur für ihre tatsächlich verwendeten
Status- und Anzeigevariablen**, nicht für sämtliche BMW-CarData-Datenpunkte.
Die beiden Vollständigkeitsfunktionen `NRGCD_GetVehicleData()` und
`NRGCD_GetTelemetry()` bleiben davon unabhängig. Ein lokaler 60-Sekunden-Timer findet nachträglich angelegte
Variablen, aktualisiert die Kachel und prüft den Bildcache. Nur bei noch
fehlendem Bild kann der Adapter in diesem Zuge den bestehenden BMW-REST-
Transport erneut nutzen. 

Vorklimatisierung wird nicht angezeigt, da der bisherige Wert nicht
zuverlässig nutzbar war.

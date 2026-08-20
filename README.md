# LoxBerry-Plugin Govee

Bindet Govee-Leuchten an Loxone an — über das Heimnetz, ohne Cloud, ohne Konto
und ohne Internet, solange die Leuchte LAN Control beherrscht.

Fassung 0.9.9 · Lizenz MIT · LoxBerry ab 3.0.0 · PHP 7.4 und 8.x

---

## Was es kann

| | |
|---|---|
| **Gerätesuche** | Multicast 239.255.255.250:4001, findet alle Leuchten mit eingeschaltetem LAN Control samt SKU, Gerätekennung und Firmwarestand |
| **Zustand lesen** | `devStatus` je Gerät: ein/aus, Helligkeit, Farbtemperatur, RGB — veröffentlicht über das MQTT-Gateway und abrufbar über einen Token-Endpunkt |
| **Grundbefehle** | ein, aus, Helligkeit 1–100, Farbe RGB, Farbtemperatur in Kelvin |
| **Szenen** | 14 aufgezeichnete Szenen für H70C4, H6076 und RGBIC-Streifen, über den nicht dokumentierten `ptReal`-Befehl |
| **Segmente und Pixel** | zwei Verfahren: *Graffiti* spricht einzelne Pixel an (an der H6076 gemessen), *Bitmaske* ganze Segmente (Form der H70C4) — welches die eigene Leuchte versteht, sagt der Versuch |
| **Prozentbalken** | „die ersten n Pixel leuchten" — für PV-Leistung, Ladezustand, Programmfortschritt |
| **Musikbetrieb** | vier Befehlsgruppen, weil die Form je Modellreihe abweicht |
| **Cloud** | offizielle Entwicklerschnittstelle mit API-Schlüssel, nur für Geräte ohne LAN Control |
| **Eigene Szenen** | wer die Befehlsfolge seiner eigenen Leuchte kennt, hinterlegt sie selbst; geprüft wird sie beim Speichern auf Länge und Prüfsumme |
| **Loxone-Vorlagen** | fertige XML-Importdateien: virtueller Ausgang für Grundbefehle, virtueller Ausgang für Szenen und Balken, **virtueller Ausgang über den LoxBerry mit stufenlosen Analogwerten**, virtueller Eingang für den Zustand |
| **Ausfallerkennung** | `OK` und `ALTER` werden zur Abrufzeit gerechnet, dazu ein Zähler fehlgeschlagener Abrufe in Folge und ein Herzschlag über MQTT |
| **Diagnose** | Trockenlauf (zeigt, *was* ein Befehl sendet, ohne ihn zu senden), zeitlich begrenzter Mitschnitt des UDP-Verkehrs, Rohantwort je Leuchte |
| **Gruppen** | `geraet=alle` schaltet alle eingerichteten Leuchten und liest sie in einem Abruf |

## Die drei Wege

**LAN** ist der Regelweg. In der Govee-App muss je Leuchte unter
*Geräteeinstellungen* der Schalter **LAN Control** eingeschaltet sein. Danach
spricht die Leuchte UDP:

    4001   Multicast 239.255.255.250 — Gerätesuche (scan)
    4002   der Port, an den die Leuchte ANTWORTET (beim Anfragenden)
    4003   der Port, an dem die Leuchte Befehle ENTGEGENNIMMT

Der dokumentierte Befehlsvorrat ist klein: `turn`, `brightness`, `colorwc`,
`devStatus`.

**ptReal** ist ein vom Hersteller nicht dokumentierter Befehl derselben
Schnittstelle. Er nimmt die BLE-Befehle entgegen, die die Govee-App sonst per
Bluetooth schickt — base64-kodiert:

    {"msg":{"cmd":"ptReal","data":{"command":[CMD, CMD, ...]}}}

Jedes `CMD` ist ein 20 Byte langer Block: 19 Nutzbytes, dahinter eine
XOR-Prüfsumme über genau diese 19 Bytes. Längere Befehle werden auf mehrere
`a3`-Pakete verteilt. Das Plugin baut diese Blöcke selbst.

**Cloud** ist nur für Geräte gedacht, die kein LAN Control können. Sie braucht
Internet, ein Govee-Konto und einen API-Schlüssel und kann nur die
Grundbefehle.

## Warum es einen Dienst gibt

Jede Govee-Leuchte antwortet an **UDP-Port 4002 des Anfragenden**. Diesen Port
kann im ganzen Rechner nur ein einziges Programm halten. Deshalb fragt nur der
Abrufdienst (`bin/govee_dienst.php`) die Leuchten ab; die Oberfläche und der
Miniserver-Endpunkt reichen ihre Wünsche über eine Warteschlange im
Dateisystem an ihn weiter. Läuft der Dienst nicht, kommen keine Zustandswerte
an — und genau das sagt die Selbstprüfung im Reiter *Test* dann auch.

## Der Nachbau ist gemessen, nicht geraten

Ein nachgebautes Protokoll, das „läuft", ist nicht geprüft. Deshalb liegen im
Plugin **39 Prüffälle**, deren Sollwert im Loxforum protokolliert ist:

    php bin/govee_dienst.php --selbsttest

Er rechnet unter anderem durch:

* Ein/Aus und Helligkeit als ptReal-Befehl
* den Anstoßbefehl für den Graffiti-Betrieb
* drei einfache Szenen über `33 05 04 <ID>`
* **alle fünfzehn Stufen** des Prozentbalkens über 14 Pixel
* das Segmentbeispiel „Pixel 1 rot, 5 grün, 10 blau, 11+12 gelb"
* zwei Fälle des Maskenverfahrens (Bitrechnung und Byte-Reihenfolge)
* alle 14 Einträge des Szenenkatalogs auf Länge und Prüfsumme

Stand 09.08.2026 stimmen alle 39 Fälle, unter PHP 7.4 und unter PHP 8.2.
Derselbe Selbsttest steckt als Knopf im Reiter *Test* und läuft zusätzlich bei
der Installation.

Zwei der 39 Fälle haben einen **anderen Beleggrad**: für das
Maskenverfahren steht im Forumsbeitrag nur die Byte-Reihenfolge, keine
fertig kodierte Beispielzeichenkette. Dort prüft der Selbsttest die
Bitrechnung und die Reihenfolge, nicht die Übereinstimmung mit einer
Aufzeichnung. Das steht auch im Quelltext daneben.

**Was das nicht beweist:** dass *Ihre* Leuchte die Befehle versteht. Govee
vergibt Szenenkennungen und die Form der Segmentbefehle je Modellreihe. Der
Katalog stammt von der Stehlampe H6076, der Lichterkette H70C4 und einem
RGBIC-Streifen.

## Endpunkt für den Miniserver

    /plugins/govee/index.php?token=<TOKEN>&aktion=<Befehl>

Lesend: `status`, `liste`, `szenen`, `roh`. `status` nimmt `geraet=alle` und
liefert dann alle Leuchten in einer Antwort, mit `G<Nr>_` vor jedem Feldnamen —
damit reicht in Loxone **ein** virtueller Eingang statt einem je Gerät.
Schaltend: `ein`, `aus`, `hell`, `kelvin`, `farbe`, `szene`, `balken`,
`segment`, `musik`, `pt`, `abruf`, `suche`.

Drei Angaben sind seit 0.9.9 dazugekommen, alle hinten angehängt, damit
bestehende Suchmuster in Loxone gültig bleiben:

* `szene` nimmt statt `name` auch `nr=0..255` — eine Szenenkennung ohne
  Katalogeintrag.
* `farbe` nimmt statt `hex` auch `wert=0..16777215`, gerechnet
  `r*65536 + g*256 + b`. Das ist der Weg für einen virtuellen Ausgang: ein
  `<v.0>` trägt genau **einen** Analogwert, drei Farbkanäle passen anders
  nicht durch.
* `hell` mit `wert=0` schaltet **aus**, statt auf die kleinste Stufe zu gehen.
  Auf dem unmittelbaren UDP-Weg geht das nicht — dort erreicht der Rohwert
  die Leuchte, ohne dass das Plugin ihn sieht.
* Jede schaltende Aktion nimmt `geraet=alle` und geht dann an jede
  eingerichtete Leuchte. Gemeldet wird je Gerät, nicht pauschal.

Die Statuszeile trägt hinten zusätzlich `FEHL` — die Zahl der Abrufe in Folge,
bei denen dieses Gerät nicht geantwortet hat.

Das Token wird beim ersten Öffnen der Oberfläche erzeugt und mit `hash_equals`
verglichen. Schaltende Aufrufe sind ab Werk gesperrt; rohe `ptReal`-Befehle
brauchen einen zweiten, eigenen Haken. Was nicht ins Muster passt, wird
abgewiesen und gemeldet — nie stillschweigend zurechtgebogen.

Fehlt ein Einzelwert, sendet der Endpunkt einen Strich statt einer erfundenen
Null. Eine Null wäre eine stille Falschaussage, und in der Loxone-App sähe
alles normal aus.

## Was in dieser Fassung dazugekommen ist

Der Nachrichtenbau steht seit 0.9.9 in der Bibliothek statt im Dienst
(`gv_befehl_pruefen()` und `gv_nachricht_bauen()`). Der Trockenlauf im Reiter
*Test* ruft dieselben zwei Funktionen auf und zeigt damit genau das, was im
Ernstfall hinausgeht — zwei Kopien derselben Logik laufen zwangsläufig
auseinander.

Die Gerätenummer ist eine **Adresse** und keine Aufzählung mehr: sie steht in
der Konfiguration und wird nie neu vergeben. Vorher war sie die Stellung in
der Tabelle — wer die erste Zeile leerte, verschob alle folgenden, und
`GOVEE_1_*`, `govee/geraet1/*` und `&geraet=1` zeigten still auf eine andere
Leuchte. Eine bestehende Anlage merkt von der Umstellung nichts: beim ersten
Speichern werden die Nummern genau so gebildet, wie die bisherige Zählung
ausfiel.

## Aufbau

    bin/govee_dienst.php              Abrufdienst, hält Port 4002
    bin/dienst.sh                     Start, Stopp, Wächter
    cron/cron.01min                   startet den Dienst neu, wenn er laufen soll
    webfrontend/html/gv_lib.php       gemeinsame Bibliothek (Protokoll, Konfiguration, MQTT, XML)
    webfrontend/html/index.php        Endpunkt für den Miniserver, Token-geschützt
    webfrontend/htmlauth/index.php    Oberfläche, fünf Reiter
    webfrontend/htmlauth/gv_test.php  Selbstprüfung und die Aktionen des Reiters Test
    templates/lang/language_de.ini    466 Schlüssel
    templates/lang/language_en.ini    dieselben 466 Schlüssel

Der Cloud-Schlüssel steht in einer eigenen Datei mit den Rechten 0600, nicht in
der Konfiguration, die die Oberfläche anzeigt. Sein Wert wird nirgends
ausgegeben — nur seine Länge.

## Herkunft der Angaben

* Dokumentierte LAN-Schnittstelle: <https://app-h5.govee.com/user-manual/wlan-guide>
* ptReal, Szenen, Segmentsteuerung, alle aufgezeichneten Befehlsfolgen:
  MarkusCosi, *Govee: BLE > local API — Segmentsteuerung / Szenen*, loxforum.com
* Cloud-Schnittstelle: <https://developer.govee.com/reference>

Nichts davon ist geraten. Wo eine Angabe nur für ein bestimmtes Modell gemessen
wurde, steht das Modell im Quelltext daneben.

## Was bis zur Veröffentlichung noch geändert wurde

**Der Plugin-Ordner wird ermittelt, nicht geraten.** `gv_paths()` fiel auf den
festen Namen `govee` zurück, sobald `config/plugins/<ordner>` noch fehlte —
etwa im Augenblick der Installation. Hängt LoxBerry bei einer
Zweitinstallation einen Zähler an (`govee_01`, weil der Name schon belegt
war), zeigten deren Pfade damit auf die **erste** Installation: gemeinsame
Konfiguration — und darin steht der API-Schlüssel —, gemeinsame Warteschlange,
gemeinsames Protokoll. Maßgeblich ist jetzt `LBPPLUGINDIR`; der feste Name
greift nur noch, wo der ermittelte nachweislich kein Plugin-Ordner sein kann
(aus dem ausgepackten Archiv heraus heißt er `html`).

**Eine leere Befehlsdatei konnte in die Warteschlange geraten.**
`gv_befehl_senden()` schrieb `json_encode($befehl)` direkt weiter. Gibt
`json_encode` bei ungültigem UTF-8 `false` zurück, macht `file_put_contents`
daraus eine leere Zeichenkette, schreibt null Byte und meldet **Erfolg** — der
Rückgabewert ist `0`, nicht `false`, die Prüfung auf `=== false` greift also
nicht. `gv_json_schreiben()` im selben Modul macht es seit jeher richtig; jetzt
tut es diese Stelle auch.

**Auto-Update ist an.** Bis zur Veröffentlichung stand `AUTOMATIC_UPDATES=false`
mit leeren Adressen — richtig, solange es kein eigenes Repository gab. Jetzt
gibt es eines, und die Adressen zeigen ausschließlich dorthin.

## Bekannte Grenzen

* Manche Leuchten gehen nicht mit `turn`, sondern nur mit einem Farbbefehl an.
* Bei einigen Leuchten (H7025) ist die BLE-Kommunikation verschlüsselt; Befehle
  lassen sich dort nicht mitschneiden. Über ptReal gesendet wirken sie
  trotzdem, man muss sie nur bei einer ähnlichen Leuchte finden.
* Die `a4`-Befehle der H70C4 (Graffiti über 200 LEDs) lassen sich nach dem
  Stand des Forumsbeitrags **nicht** über ptReal absetzen. Das Plugin bietet sie
  deshalb nicht an, statt sie ins Leere zu senden.
* Über die Cloud sind nur die Grundbefehle möglich, keine Szenen und keine
  Segmente. Der Zustand eines Cloud-Geräts wird im **Cloud-Takt** abgefragt,
  nicht im LAN-Takt; die Altersgrenze für `OK` richtet sich entsprechend nach
  `cloud_takt`. Meldet die Schnittstelle HTTP 429, wird `Retry-After` gelesen
  und bis dahin nicht mehr abgerufen — ein übersprungener Lauf ist dabei kein
  Fehler und rührt den Zustand nicht an.
* Die Vorlage *Grundbefehle* schickt Helligkeit unmittelbar an die Leuchte.
  Eine 0 aus Loxones Lichtsteuerung ergibt dort die kleinste Stufe statt
  Dunkelheit; dafür bleibt der Begrenzer nötig. Über die Vorlage *Stufenlos
  über den LoxBerry* nimmt das Plugin die 0 entgegen und schaltet aus.

# Schein — Hinweise für die Arbeit an diesem Repository

## Einordnung

- **Marke: DG65 Toolkit**, nicht NRG-Stack. Domänenfrei: funktioniert für JEDE schaltbare Variable,
  unabhängig vom Gewerk. `library.json→name` = „DG65 Toolkit Schein", Alias „Schein" +
  „Anwesenheitssimulation".
- **Technischer Name = Anzeigename:** Klasse `Schein`, Präfix `SCHEIN_`, Repo `DG65/Schein`,
  Ordner `Schein/`. Auf Katasteramts Empfehlung (09.09.2026) bewusst identisch gehalten — die
  seltene Chance eines brandneuen Moduls ohne Bestandsinstanzen. Ab der ersten echten Instanz
  eingefroren (TOOLKIT.md-Faustregel, Kernel-Reflection auf den Klassennamen).
- **Kickoff:** `/Users/dietmar/Nextcloud/Claude/Schein-Kickoff.md` (Destille-Sitzung, 09.09.2026).
  Verbindliche Konventionen: `TOOLKIT.md` (Toolkit-spezifisch) und `SUITE.md` (Symcon-Allgemeines,
  Stolpersteine, Store-Review, Formular-Optik) — beide nur lokal unter `Nextcloud/Claude/`, nicht
  im Repo.
- **Kein Cross-Modul-Vertrag.** `SCHEIN_SetActive/Preview/ForgetChannel` sind Bedien-Einstiege für
  Skripte/Formular, kein Datenvertrag mit `contractVersion`. Sonnenauf-/-untergang kommt aus
  Symcons Kernmodul Location Control, nicht aus einem Toolkit-Partner (Katasteramt: „erst prüfen,
  ob der Kernel das schon löst, bevor ein Cross-Modul-Vertrag entsteht").

## Design-Entscheidungen (09.09.2026, Antworten auf die vier Kickoff-Fragen)

1. **Auslöser:** beides — Instanzvariable `Active` mit `EnableAction` (WebFront/Kachel/Ereignis/
   `IPS_RequestAction`) PLUS optionale Auslöser-Variable (`TriggerID`, `TriggerInvert`). Der
   manuelle Schalter bleibt immer bedienbar.
2. **Jahreszeit/Astro:** rollendes Lernfenster (28 Tage) folgt der Jahreszeit von selbst; zusätzlich
   je Kanal `TimeRef` Uhrzeit/Sonnenuntergang/Sonnenaufgang über Location Control
   (`{45E97A63-F870-408A-B259-2933F7EABF74}`, Idents `Sunset`/`Sunrise`, live verifiziert an
   IPS 9.0). Die Variablen zeigen immer aufs NÄCHSTE Ereignis → `anchorTs()` normalisiert auf
   ±12 h um den Bezugszeitpunkt. Jedes gelernte Ereignis trägt beide Anker (`ss`/`sr`), damit ein
   späterer Wechsel des Zeitbezugs mit alten Daten funktioniert.
3. **Ausreißer:** kein Filter, sondern das Vorbild-Tag-Prinzip — ein Party-Abend wird mit 1/N
   Wahrscheinlichkeit als Ganzes abgespielt, was realistischer ist als ein statistisches Mittel.
   Lernfenster instanzweit (nicht je Kanal wie im Kickoff-Entwurf): weniger Spalten, ein Wert.
4. **Gruppen:** 1 Kanal = 1 Zielvariable, aber `CoherentReplay` (Standard an): alle Kanäle spielen
   denselben Vorbild-Tag mit demselben relativen Versatz-Faktor — der Gruppeneffekt ohne
   Listen-Komplexität. Kanäle, die erst nach dem gemeinsamen Vorbild-Tag dazukamen, bekommen
   ein eigenes Vorbild statt fälschlich „war still".

## Kernmechanik — nicht verhandelbar

1. **Nie während der Simulation lernen** (`learn()` prüft `isActive()` zuerst). Sonst lernt Schein
   seine eigenen Wiedergaben. Auch beim Ausbauen nie ein „lernt trotzdem mit"-Flag einführen.
2. **Nur `$Data[0]` von `VM_UPDATE` verwenden.** Die weiteren Indizes ([1]=geändert?) sind nur
   Community-Vermutung, nicht dokumentiert — `learn()` erkennt Wertänderungen selbst per Vergleich
   mit dem letzten Puffer-Eintrag.
3. **Lernspeicher = versteckte String-Variable `buf_<TargetID>`** unter der Instanz (kein Attribut,
   überlebt Modul-Resync; Schlüssel ist die Zielvariable, nicht die Bezeichnung — Umbenennen
   verliert nichts). Verwaiste Puffer räumt `ApplyChanges()` über den Ident-Präfix auf.
   `ReplayPlan` (Tagesplan) ist dagegen bewusst ein Attribut: geht es verloren, wählt der nächste
   Tick einfach neu.
4. **Einschwingen statt Nachspielen:** beim Start/Tageswechsel nur die letzte fällige Schaltung je
   Kanal (`settle()`), ersatzweise der Zustand vor Beginn des Vorbild-Tags (`stateBefore()`) — nie
   alle vergangenen Ereignisse des Tages in einer Sekunde durchschalten (Flackern).
5. **Koaleszieren nur vorwärts** (`$delta >= 0`): ein Zeitsprung rückwärts darf keine Ereignisse
   fressen (im Prüfstand mit absichtlich rückwärts laufender Uhr gefunden).
6. **Kalenderrechnung DST-sicher** (SUITE.md Stolperstein 18): `mktime()`/`strtotime('-N days')`,
   nie `±86400`.
7. **Schalten:** `HasAction()` → `RequestAction($vid, $wert)`, sonst `SetValue()`; Wert per
   `castForVariable()` auf den Variablentyp. Fehlschlag → `IPS_LogMessage()` (dauerhaft), nicht nur
   `SendDebug()`.
8. **Öffentliche Methoden:** nur skalare Parametertypen, keine PHP-Standardwerte (Stolperstein
   8/20), `SetActive()` (public) vs. `switchSimulation()` (private) — PHP-Methodennamen sind
   case-insensitiv, `setActive` wäre eine Kollision.
9. **Sprachregel:** alles Nutzersichtbare deutsch. **Keine eigene Anlage als Norm:** Beispiele
   („Wohnzimmerlicht", „Abwesend") nur als „z. B.", keine IDs, Defaults neutral (Trigger 0,
   Simulation aus).

## Prüfen

```
php .tools/test-sim.php           # 0 = alle Blöcke bestanden (läuft auch in der CI)
php .tools/check-standalone.php   # 0 = kein ungesicherter Fremdaufruf
```

Der Prüfstand simuliert `IPSModule` + IPS-Kernfunktionen mit steuerbarer Uhr und Zufall
(`ScheinTest` überschreibt `now()`/`randomUnit()`/`randomIndex()`). Live-Test in Symcon bleibt
Pflicht vor jedem Release: Installation nur manuell über die Modulverwaltung, Formular durchklicken,
jeden Button auf sichtbare Rückmeldung prüfen, mindestens einen Abend echt abspielen lassen.

## Branch-Modell

`main`+`beta` beim allerersten Commit gemeinsam, danach nur noch `beta` bis zum ersten echten
Live-Test (TOOLKIT.md, Präzedenzfall Pförtner). `LICENSE_URL` zeigt auf `main` — dieses Repo hat
von Anfang an PolyForm, keinen MIT-Altstand.

## Roadmap / bewusst nicht drin

- Kein „beim Beenden alles ausschalten" (v0.1): wer heimkommt, übernimmt selbst; bei Bedarf als
  Option nachrüstbar (nur für Bool-Kanäle sinnvoll).
- Keine Wahrscheinlichkeits-/Slot-Statistik: Vorbild-Tag bewusst als Ganzes, nicht als Mittel.
- Keine WebFront-Listenpflege (SUITE.md „WebFront-taugliche Listenpflege") — Kanäle werden in der
  Konsole gepflegt, der Schalter „Simulation aktiv" reicht fürs WebFront.
- Kein Feiertags-/Ferienbezug (wäre über eine Auslöser-Variable aus einem Kalender-Modul ohnehin
  von außen steuerbar).

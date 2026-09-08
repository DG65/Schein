# Changelog

Alle nennenswerten Änderungen an diesem Modul werden hier dokumentiert.
Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).

## [0.1.0] - 2026-09-08

### Added
- Erstes Release. Anwesenheitssimulation für JEDE schaltbare Symcon-Variable (Bool/Integer/Float,
  z. B. Licht, Steckdose, Rollladen, Radio), unabhängig vom liefernden Modul — nicht an IPSLight
  gekoppelt wie Symcons Bordmittel.
- **Lernen** ohne Symcon-Archiv: jede echte Wertänderung einer Zielvariable (per `VM_UPDATE`,
  kein Polling) landet mit Zeitstempel in einem versteckten Lernspeicher je Kanal; rollendes
  Lernfenster (Standard 28 Tage) — das Muster folgt so der Jahreszeit von selbst. Schreibvorgänge
  ohne Wertänderung werden ignoriert, Dimmer-Schieber (mehrere Werte binnen 20 s) zu einem
  Schaltvorgang zusammengefasst, Obergrenze 5000 Ereignisse je Kanal.
- **Abspielen nach dem Vorbild-Tag-Prinzip:** pro Tag wählt Schein einen echten gelernten Tag
  desselben Wochentags (ersatzweise Werktag/Wochenende, ersatzweise beliebig) und spielt dessen
  Schaltfolge mit zufälligem Versatz (je Kanal einstellbar, ± Minuten) ab. Beim Start und beim
  Tageswechsel wird zuerst der Zustand hergestellt, den das Vorbild um diese Uhrzeit hatte
  („Einschwingen", ohne Flackern). Option „gemeinsamer Vorbild-Tag": alle Kanäle spielen
  denselben Tag mit demselben relativen Versatz — die Reihenfolge zwischen den Räumen bleibt echt.
- **Zeitbezug je Kanal:** Uhrzeit oder Abstand zu Sonnenuntergang/-aufgang — nutzt Symcons
  eingebaute Location-Control-Instanz (Kernbestandteil), ohne sie automatischer Rückfall auf
  Uhrzeit mit Hinweis im Formular.
- **Start/Stopp:** Instanzvariable „Simulation aktiv" (mit Aktion — WebFront/Kachel, Ereignis,
  `IPS_RequestAction`), Formular-Buttons, `SCHEIN_SetActive()`, optionale Auslöser-Variable
  (z. B. Abwesend/Urlaub/Alarm scharf, invertierbar). Während der Simulation wird nicht gelernt.
- Sichtbare Rückmeldung: Variablen „Letzte Schaltung"/„Nächste Schaltung", Statuszeile und
  Kanal-Zusammenfassung im Formular, „📅 Heutigen Ablauf anzeigen" (`SCHEIN_Preview()`),
  `SCHEIN_ForgetChannel()`; fehlgeschlagene Schaltungen dauerhaft im Symcon-Log.
- Formular nach Verbund-Konvention (Zweck-Intro, News, Doku, Rückmeldungen, Über-dieses-Modul),
  Prüfstand `.tools/test-sim.php` (vier Lernwochen + Simulationstag ohne Symcon, läuft in der CI),
  `.tools/check-standalone.php`, PolyForm-Noncommercial-Lizenz.

### Herkunft
- Kandidat aus der DG65-Toolkit-Ideenrunde (Recherche der Destille-Sitzung, 08.09.2026:
  Symcon-Bordmittel an IPSLight gekoppelt und als unzuverlässig gemeldet, ioBroker ohne Adapter,
  Home Assistant mit mehreren Blueprints als Beleg für den Wert des Konzepts). Kickoff-Briefing
  `Schein-Kickoff.md`.

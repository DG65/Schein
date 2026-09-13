<?php

// ===========================================================================
// Schein (DG65 Toolkit) — Anwesenheitssimulation für JEDE schaltbare
// Variable, geräteunabhängig.
//
// „Schein" ist beides: der Lichtschein, den das Modul erzeugt, und der
// Anschein, den es damit vorgibt — der Schein trügt.
//
// WIE ES ARBEITET:
//   Lernen  — solange die Simulation AUS ist, zeichnet Schein jede echte
//             Wertänderung der Zielvariablen auf (per VM_UPDATE-Nachricht,
//             kein Polling): Zeitstempel + Wert, in einem versteckten
//             Ringpuffer je Kanal, begrenzt auf das Lernfenster (Tage).
//   Abspielen — ist die Simulation AN, wählt Schein pro Tag einen echten
//             „Vorbild-Tag" desselben Wochentags aus dem Gelernten und
//             spielt dessen Schaltfolge zeitversetzt (Jitter) ab. So bleibt
//             die Reihenfolge eines realen Abends erhalten (erst Wohnzimmer,
//             dann Küche, dann alles aus), ohne dass jede Woche exakt
//             dieselbe Uhrzeit fällt. Optional kann jeder Kanal an
//             Sonnenauf-/-untergang statt an die Uhrzeit gekoppelt sein —
//             dafür reicht Symcons eingebaute Location-Control-Instanz.
//   Während die Simulation läuft, wird NICHT gelernt — sonst würde Schein
//   seine eigenen Wiedergaben als echtes Verhalten mitlernen.
//
// Eigenständig: setzt kein anderes Modul voraus (Location Control ist ein
// Symcon-Kernbestandteil, kein Fremdmodul) und wird von keinem
// vorausgesetzt.
// ===========================================================================

class Schein extends IPSModule
{
    private const LIBRARY_GUID = '{B4C508FF-14C4-47C2-8EC4-7AF933A3CCD0}';
    private const MODULE_GUID  = '{00F44496-648D-4AE0-9AC8-A6002EFCEF54}';

    // Symcon-Kernmodul "Location Control" — liefert Sonnenauf-/-untergang
    // als Unix-Zeitstempel-Variablen (Idents Sunrise/Sunset), live
    // verifiziert an IP-Symcon 9.0 (08.09.2026).
    private const LOCATION_CONTROL_GUID = '{45E97A63-F870-408A-B259-2933F7EABF74}';

    // Formular-Konvention (SUITE.md "Einheitliche Formular-Optik").
    private const NEWS_VERSION = '0.1.0';
    private const REPO_URL     = 'https://github.com/DG65/Schein';
    private const LICENSE_URL  = 'https://github.com/DG65/Schein/blob/main/LICENSE';
    private const PAYPAL_URL   = 'https://paypal.me/DietmarGureth';

    private const TICK_MS      = 60000; // Wiedergabe-Takt: minütlich
    private const MAX_EVENTS   = 5000;  // Obergrenze je Kanal-Ringpuffer
    private const COALESCE_SEC = 20;    // Wertänderungen innerhalb dieser Spanne gelten als EIN Schaltvorgang (Dimmer-Schieber)

    // Kanal-Modus (Spalte "Modus")
    private const MODE_OFF        = 0;
    private const MODE_LEARN_PLAY = 1;
    private const MODE_LEARN_ONLY = 2;

    // Zeitbezug je Kanal (Spalte "Zeitbezug")
    private const TIMEREF_CLOCK   = 0;
    private const TIMEREF_SUNSET  = 1;
    private const TIMEREF_SUNRISE = 2;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Channels', '[]');
        $this->RegisterPropertyInteger('LearnDays', 28);
        $this->RegisterPropertyInteger('TriggerID', 0);
        $this->RegisterPropertyBoolean('TriggerInvert', false);
        $this->RegisterPropertyBoolean('CoherentReplay', true);

        // Tagesplan der laufenden Simulation (Vorbild-Tag, Jitter, bis
        // wohin schon abgespielt wurde). Bewusst ein Attribut: geht bei
        // einem Modul-Resync verloren — dann wird beim nächsten Tick
        // einfach ein neuer Plan gewählt, nichts Gelerntes ist betroffen
        // (die Ringpuffer sind Variablen, keine Attribute).
        $this->RegisterAttributeString('ReplayPlan', '{}');
        $this->RegisterAttributeInteger('ActiveSince', 0);
        $this->RegisterAttributeInteger('LastLearnTs', 0);

        // Dismiss-Zustände der Rahmen-Panels.
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeBoolean('ForumHintGone', false);

        $this->RegisterTimer('Tick', 0, 'SCHEIN_Tick($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELMESSAGE);
            return;
        }

        // Alle bisherigen Nachrichten-Registrierungen lösen, danach frisch
        // aufbauen — sonst bleiben gelöschte Kanäle registriert.
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        // Instanz-Variablen (MaintainVariable ist idempotent, SUITE.md
        // Stolperstein 3). "Simulation aktiv" ist bewusst eine Variable mit
        // Aktion statt einer Property: so lässt sie sich aus WebFront/
        // Kachel, per Ereignis und per IPS_RequestAction() schalten.
        $this->MaintainVariable('Active', 'Simulation aktiv', VARIABLETYPE_BOOLEAN, '~Switch', 0, true);
        $this->EnableAction('Active');
        $this->MaintainVariable('LastAction', 'Letzte Schaltung', VARIABLETYPE_STRING, '', 1, true);
        $this->MaintainVariable('NextAction', 'Nächste Schaltung', VARIABLETYPE_STRING, '', 2, true);

        $channels = $this->channels();
        $validTargets = [];
        foreach ($channels as $ch) {
            $tid = (int)($ch['TargetID'] ?? 0);
            if ($tid <= 0 || !IPS_VariableExists($tid)) {
                continue;
            }
            $validTargets[$tid] = true;
            $this->ensureBuffer($tid, (string)($ch['Caption'] ?? ''));
            if ((int)($ch['Mode'] ?? self::MODE_OFF) !== self::MODE_OFF) {
                $this->RegisterMessage($tid, VM_UPDATE);
            }
        }

        // Ringpuffer verwaister Kanäle (Zeile gelöscht / Zielvariable
        // gewechselt) entfernen — ein Wechsel der Bezeichnung lässt den
        // Puffer dagegen unangetastet (Schlüssel ist die Zielvariable).
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $cid) {
            $ident = IPS_GetObject($cid)['ObjectIdent'] ?? '';
            if (strpos($ident, 'buf_') !== 0) {
                continue;
            }
            $tid = (int)substr($ident, 4);
            if (!isset($validTargets[$tid])) {
                IPS_DeleteVariable($cid);
            }
        }

        $trigger = $this->ReadPropertyInteger('TriggerID');
        if ($trigger > 0 && IPS_VariableExists($trigger)) {
            $this->RegisterMessage($trigger, VM_UPDATE);
        }

        if (count($validTargets) === 0) {
            $this->SetTimerInterval('Tick', 0);
            $this->SetStatus(104);
            return;
        }
        $this->SetStatus(102);
        $this->SetTimerInterval('Tick', $this->isActive() ? self::TICK_MS : 0);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELMESSAGE) {
            if (isset($Data[0]) && $Data[0] === KR_READY) {
                $this->ApplyChanges();
            }
            return;
        }
        if ($Message !== VM_UPDATE) {
            return;
        }
        if ($SenderID === $this->ReadPropertyInteger('TriggerID') && $SenderID > 0) {
            // Auslöser-Variable: TRUE = "niemand da" (bzw. invertiert).
            $wanted = (bool)($Data[0] ?? false);
            if ($this->ReadPropertyBoolean('TriggerInvert')) {
                $wanted = !$wanted;
            }
            if ($wanted !== $this->isActive()) {
                $this->switchSimulation($wanted, 'Auslöser-Variable');
            }
            return;
        }
        // Nur $Data[0] (neuer Wert) ist verlässlich dokumentiert — ob sich
        // der Wert geändert hat, prüft learn() selbst gegen den Puffer.
        $this->learn((int)$SenderID, $Data[0] ?? null);
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'Active') {
            $this->switchSimulation((bool)$Value, 'Schalter');
            return;
        }
        throw new Exception('Unbekannte Aktion: ' . $Ident);
    }

    // -----------------------------------------------------------------
    // Öffentliche Aktionen (Formular-Buttons, Skripte)
    // -----------------------------------------------------------------

    /** SCHEIN_SetActive($id, bool $active): string — Simulation starten/beenden, mit Ergebnistext. */
    public function SetActive(bool $active): string
    {
        if ($active === $this->isActive()) {
            return $active
                ? 'ℹ️ Die Simulation läuft bereits (seit ' . date('d.m.Y H:i', $this->ReadAttributeInteger('ActiveSince')) . ' Uhr).'
                : 'ℹ️ Die Simulation ist bereits aus — Schein lernt gerade.';
        }
        if ($active && count($this->playableChannels()) === 0) {
            return '⚠️ Kein Kanal steht auf „Lernen + Abspielen" mit gültiger Zielvariable — es gäbe nichts abzuspielen. Erst Kanäle anlegen und übernehmen.';
        }
        $this->switchSimulation($active, 'Formular');
        if (!$active) {
            return '⏹ Simulation beendet — ab jetzt lernt Schein wieder mit. Die Zielvariablen bleiben so, wie sie gerade stehen.';
        }
        $plan = $this->plan();
        $lines = ['▶️ Simulation gestartet.'];
        foreach ($this->playableChannels() as $ch) {
            $tid = (int)$ch['TargetID'];
            $p = $plan['channels'][$tid] ?? null;
            $lines[] = '• ' . $this->channelName($ch) . ': '
                . ($p ? 'Vorbild ' . $this->fmtDay($p['refDate']) . ', Versatz ' . $this->fmtJitter((int)$p['jitterSec']) : 'noch nichts gelernt — bleibt still');
        }
        return implode("\n", $lines);
    }

    /**
     * SCHEIN_Preview($id): string — zeigt, was heute abgespielt wird
     * (bei laufender Simulation der echte Plan, sonst ein probeweise
     * gewählter Plan, der NICHT gespeichert wird).
     */
    public function Preview(): string
    {
        $now = $this->now();
        $active = $this->isActive();
        $plan = $active ? $this->plan() : $this->buildPlan($now);
        $channels = $this->playableChannels();
        if (count($channels) === 0) {
            return 'ℹ️ Kein Kanal steht auf „Lernen + Abspielen" — nichts anzuzeigen.';
        }
        $lines = [$active
            ? '📅 Heutiger Ablauf (Simulation läuft seit ' . date('H:i', $this->ReadAttributeInteger('ActiveSince')) . ' Uhr):'
            : '📅 So würde Schein heute abspielen (Simulation ist aus, Vorbild-Tag probeweise gewählt):'];
        foreach ($channels as $ch) {
            $tid = (int)$ch['TargetID'];
            $p = $plan['channels'][$tid] ?? null;
            if (!$p) {
                $lines[] = '• ' . $this->channelName($ch) . ': noch nichts gelernt — bleibt still.';
                continue;
            }
            $steps = [];
            foreach ($this->scheduledEvents($ch, $p, $now) as $ev) {
                $steps[] = date('H:i', $ev['at']) . ' ' . $this->fmtValue($ev['v']);
            }
            $lines[] = '• ' . $this->channelName($ch) . ' (Vorbild ' . $this->fmtDay($p['refDate']) . ', Versatz ' . $this->fmtJitter((int)$p['jitterSec']) . '): '
                . ($steps ? implode(', ', $steps) : 'an diesem Vorbild-Tag keine Schaltung');
        }
        return implode("\n", $lines);
    }

    /** SCHEIN_ForgetChannel($id, string $caption): string — Gelerntes eines Kanals verwerfen. */
    public function ForgetChannel(string $caption): string
    {
        foreach ($this->channels() as $ch) {
            if (($ch['Caption'] ?? '') !== $caption) {
                continue;
            }
            $bid = $this->bufferID((int)($ch['TargetID'] ?? 0));
            if (!$bid) {
                return 'ℹ️ Kanal „' . $caption . '" hat noch keinen Lernspeicher — nichts zu verwerfen.';
            }
            SetValueString($bid, '[]');
            return '🗑 Gelerntes von „' . $caption . '" verworfen — der Kanal lernt ab jetzt neu.';
        }
        return '⚠️ Kein Kanal mit der Bezeichnung „' . $caption . '" gefunden.';
    }

    /** Timer-Einstieg (minütlich, nur bei laufender Simulation). */
    public function Tick()
    {
        if (!$this->isActive()) {
            $this->SetTimerInterval('Tick', 0);
            return;
        }
        $now = $this->now();
        $plan = $this->plan();
        if (($plan['day'] ?? '') !== date('Y-m-d', $now)) {
            // Tageswechsel: neuer Vorbild-Tag, neuer Versatz, Einschwingen
            // auf den Zustand, den das Vorbild um diese Uhrzeit hatte.
            $plan = $this->buildPlan($now);
            $this->settle($plan, $now);
        }

        $nextAt = null;
        $nextText = '';
        foreach ($this->playableChannels() as $ch) {
            $tid = (int)$ch['TargetID'];
            if (!isset($plan['channels'][$tid])) {
                continue;
            }
            $p = &$plan['channels'][$tid];
            $playedUpTo = (int)($p['playedUpTo'] ?? 0);
            foreach ($this->scheduledEvents($ch, $p, $now) as $ev) {
                if ($ev['at'] > $playedUpTo && $ev['at'] <= $now) {
                    $this->execute($ch, $ev['v'], $p['refDate']);
                } elseif ($ev['at'] > $now && ($nextAt === null || $ev['at'] < $nextAt)) {
                    $nextAt = $ev['at'];
                    $nextText = date('H:i', $ev['at']) . ' Uhr: ' . $this->channelName($ch) . ' → ' . $this->fmtValue($ev['v']);
                }
            }
            $p['playedUpTo'] = $now;
            unset($p);
        }
        $this->WriteAttributeString('ReplayPlan', json_encode($plan));
        $this->setStringIfChanged('NextAction', $nextAt === null ? 'Heute keine weitere Schaltung geplant' : $nextText);
    }

    // -----------------------------------------------------------------
    // Lernen
    // -----------------------------------------------------------------

    private function learn(int $tid, $value): void
    {
        if ($this->isActive() || $value === null) {
            return; // eigene Wiedergabe nie als echtes Verhalten lernen
        }
        $ch = $this->channelByTarget($tid);
        if ($ch === null || (int)($ch['Mode'] ?? self::MODE_OFF) === self::MODE_OFF) {
            return;
        }
        $bid = $this->bufferID($tid);
        if (!$bid) {
            return;
        }
        $now = $this->now();
        $buffer = json_decode(GetValueString($bid), true);
        if (!is_array($buffer)) {
            $buffer = [];
        }
        $value = $this->normalizeValue($value);
        $count = count($buffer);
        if ($count > 0) {
            $last = $buffer[$count - 1];
            if ($this->sameValue($last['v'], $value)) {
                return; // Schreibvorgang ohne Wertänderung
            }
            $delta = $now - (int)$last['t'];
            if ($delta >= 0 && $delta < self::COALESCE_SEC) {
                // Dimmer-Schieber & Co.: mehrere Zwischenwerte binnen
                // Sekunden sind EIN Schaltvorgang — nur der Endwert zählt.
                array_pop($buffer);
                $count--;
                if ($count > 0 && $this->sameValue($buffer[$count - 1]['v'], $value)) {
                    SetValueString($bid, json_encode($buffer));
                    return; // zurück auf den Ausgangswert = nichts passiert
                }
            }
        }

        $ev = ['t' => $now, 'v' => $value];
        $sunset = $this->anchorTs('Sunset', $now);
        $sunrise = $this->anchorTs('Sunrise', $now);
        if ($sunset !== null) {
            $ev['ss'] = $sunset;
        }
        if ($sunrise !== null) {
            $ev['sr'] = $sunrise;
        }
        $buffer[] = $ev;
        $buffer = $this->trim($buffer, $now);
        SetValueString($bid, json_encode($buffer));
        $this->WriteAttributeInteger('LastLearnTs', $now);
        $this->SendDebug('Lernen', $this->channelName($ch) . ' → ' . $this->fmtValue($value) . ' (' . count($buffer) . ' Ereignisse)', 0);
    }

    private function trim(array $buffer, int $now): array
    {
        $days = max(1, $this->ReadPropertyInteger('LearnDays'));
        $cutoff = strtotime('-' . $days . ' days', $now); // kein +86400-Rechnen (SUITE.md Stolperstein 18)
        $buffer = array_values(array_filter($buffer, function ($e) use ($cutoff) {
            return (int)($e['t'] ?? 0) >= $cutoff;
        }));
        if (count($buffer) > self::MAX_EVENTS) {
            $buffer = array_slice($buffer, -self::MAX_EVENTS);
        }
        return $buffer;
    }

    // -----------------------------------------------------------------
    // Abspielen
    // -----------------------------------------------------------------

    private function switchSimulation(bool $active, string $source): void
    {
        $this->SetValue('Active', $active);
        if ($active) {
            $now = $this->now();
            $this->WriteAttributeInteger('ActiveSince', $now);
            $plan = $this->buildPlan($now);
            $this->settle($plan, $now);
            $this->WriteAttributeString('ReplayPlan', json_encode($plan));
            $this->SetTimerInterval('Tick', self::TICK_MS);
            $this->Tick();
        } else {
            $this->WriteAttributeString('ReplayPlan', '{}');
            $this->SetTimerInterval('Tick', 0);
            $this->setStringIfChanged('NextAction', '');
        }
        $this->SendDebug('Simulation', ($active ? 'gestartet' : 'beendet') . ' (' . $source . ')', 0);
    }

    /**
     * Wählt für heute je Kanal einen Vorbild-Tag + Versatz. Bei
     * "CoherentReplay" bekommen alle Kanäle DENSELBEN Vorbild-Tag und
     * denselben relativen Versatz — so bleibt die Abfolge zwischen den
     * Räumen so glaubwürdig wie an dem echten Tag, an dem sie gelernt wurde.
     */
    private function buildPlan(int $now): array
    {
        $today = date('Y-m-d', $now);
        $coherent = $this->ReadPropertyBoolean('CoherentReplay');
        $r = $this->randomUnit(); // gemeinsamer Versatz-Faktor in [-1, 1]

        $channels = $this->playableChannels();
        $perChannelDays = [];
        foreach ($channels as $ch) {
            $tid = (int)$ch['TargetID'];
            $perChannelDays[$tid] = $this->learnedDays($tid, $today);
        }

        $sharedRef = null;
        if ($coherent) {
            $union = [];
            foreach ($perChannelDays as $days) {
                $union = $union + $days; // Datum => Wochentag(N)
            }
            $sharedRef = $this->pickReferenceDay($union, $now);
        }

        $plan = ['day' => $today, 'channels' => []];
        foreach ($channels as $ch) {
            $tid = (int)$ch['TargetID'];
            $days = $perChannelDays[$tid];
            if (count($days) === 0) {
                continue; // noch nichts gelernt → dieser Kanal bleibt still
            }
            $ref = $sharedRef;
            // Kanal kam erst nach dem gemeinsamen Vorbild-Tag dazu (hat dort
            // noch gar nicht gelernt)? Dann eigenes Vorbild, statt fälschlich
            // "war den ganzen Tag still" abzuspielen.
            if ($ref === null || min(array_keys($days)) > $ref) {
                $ref = $this->pickReferenceDay($days, $now);
            }
            $jitterMin = max(0, (int)($ch['JitterMin'] ?? 0));
            $factor = $coherent ? $r : $this->randomUnit();
            $plan['channels'][$tid] = [
                'refDate'    => $ref,
                'jitterSec'  => (int)round($factor * $jitterMin * 60),
                'playedUpTo' => $now,
            ];
        }
        return $plan;
    }

    /** Alle Kalendertage (außer heute) mit gelernten Ereignissen: 'Y-m-d' => Wochentag 1..7 */
    private function learnedDays(int $tid, string $today): array
    {
        $days = [];
        foreach ($this->buffer($tid) as $ev) {
            $d = date('Y-m-d', (int)$ev['t']);
            if ($d !== $today) {
                $days[$d] = (int)date('N', (int)$ev['t']);
            }
        }
        ksort($days);
        return $days;
    }

    /**
     * Vorbild-Tag: bevorzugt derselbe Wochentag, sonst dieselbe Tagesklasse
     * (Werktag/Wochenende), sonst irgendein gelernter Tag. Innerhalb der
     * besten Stufe zufällig — so wiederholt sich nie exakt dieselbe Woche.
     */
    private function pickReferenceDay(array $days, int $now): ?string
    {
        if (count($days) === 0) {
            return null;
        }
        $todayN = (int)date('N', $now);
        $weekend = $todayN >= 6;
        $tiers = [[], [], []];
        foreach ($days as $date => $n) {
            if ($n === $todayN) {
                $tiers[0][] = $date;
            } elseif (($n >= 6) === $weekend) {
                $tiers[1][] = $date;
            } else {
                $tiers[2][] = $date;
            }
        }
        foreach ($tiers as $tier) {
            if (count($tier) > 0) {
                return $tier[$this->randomIndex(count($tier))];
            }
        }
        return null;
    }

    /**
     * Ereignisse des Vorbild-Tags, auf heute übertragen (Uhrzeit bzw.
     * Abstand zu Sonnenauf-/-untergang) und um den Tagesversatz verschoben.
     * Rückgabe: [['at' => ts, 'v' => Wert], ...] aufsteigend nach 'at'.
     */
    private function scheduledEvents(array $ch, array $p, int $now): array
    {
        $tid = (int)$ch['TargetID'];
        $ref = (string)$p['refDate'];
        $jitter = (int)($p['jitterSec'] ?? 0);
        $timeRef = (int)($ch['TimeRef'] ?? self::TIMEREF_CLOCK);
        $anchorKey = $timeRef === self::TIMEREF_SUNSET ? 'ss' : ($timeRef === self::TIMEREF_SUNRISE ? 'sr' : '');
        $anchorToday = $anchorKey !== '' ? $this->anchorTs($timeRef === self::TIMEREF_SUNSET ? 'Sunset' : 'Sunrise', $now) : null;

        $out = [];
        foreach ($this->buffer($tid) as $ev) {
            $t = (int)$ev['t'];
            if (date('Y-m-d', $t) !== $ref) {
                continue;
            }
            if ($anchorToday !== null && isset($ev[$anchorKey])) {
                $at = $anchorToday + ($t - (int)$ev[$anchorKey]);
            } else {
                // Uhrzeit des Vorbilds auf das heutige Kalenderdatum legen —
                // per mktime(), nicht per Sekundenarithmetik (DST-sicher).
                $at = mktime((int)date('G', $t), (int)date('i', $t), (int)date('s', $t), (int)date('n', $now), (int)date('j', $now), (int)date('Y', $now));
            }
            $out[] = ['at' => $at + $jitter, 'v' => $ev['v']];
        }
        usort($out, function ($a, $b) {
            return $a['at'] <=> $b['at'];
        });
        return $out;
    }

    /**
     * Einschwingen: beim Start (und beim Tageswechsel) den Zustand
     * herstellen, den der Vorbild-Tag um diese Uhrzeit hatte — nur die
     * jeweils LETZTE fällige Schaltung je Kanal, kein Nachspielen des
     * ganzen Tages (das wäre ein sichtbares Flackern).
     */
    private function settle(array &$plan, int $now): void
    {
        foreach ($this->playableChannels() as $ch) {
            $tid = (int)$ch['TargetID'];
            if (!isset($plan['channels'][$tid])) {
                continue;
            }
            $refDate = (string)$plan['channels'][$tid]['refDate'];
            $lastDue = null;
            foreach ($this->scheduledEvents($ch, $plan['channels'][$tid], $now) as $ev) {
                if ($ev['at'] <= $now) {
                    $lastDue = $ev;
                }
            }
            // Noch nichts vom Vorbild-Tag fällig (z. B. kurz nach
            // Mitternacht)? Dann gilt der Zustand, in dem der Vorbild-Tag
            // BEGONNEN hat — die letzte gelernte Schaltung davor (meist das
            // „Aus" vom Vorabend).
            $target = $lastDue !== null ? $lastDue['v'] : $this->stateBefore($tid, $refDate);
            if ($target !== null) {
                $current = $this->normalizeValue(GetValue($tid));
                if (!$this->sameValue($current, $target)) {
                    $this->execute($ch, $target, $refDate);
                }
            }
            $plan['channels'][$tid]['playedUpTo'] = $now;
        }
    }

    /** Wert der letzten gelernten Schaltung VOR Beginn des Tages $ymd (null, wenn keine). */
    private function stateBefore(int $tid, string $ymd)
    {
        $dayStart = strtotime($ymd . ' 00:00:00');
        $value = null;
        $best = 0;
        foreach ($this->buffer($tid) as $ev) {
            $t = (int)$ev['t'];
            if ($t < $dayStart && $t >= $best) {
                $best = $t;
                $value = $ev['v'];
            }
        }
        return $value;
    }

    private function execute(array $ch, $value, string $refDate): void
    {
        $tid = (int)$ch['TargetID'];
        if (!IPS_VariableExists($tid)) {
            return;
        }
        $value = $this->castForVariable($tid, $value);
        $label = $this->channelName($ch) . ' → ' . $this->fmtValue($value);
        $ok = false;
        $error = '';
        try {
            if (HasAction($tid)) {
                $ok = (bool)RequestAction($tid, $value);
            } else {
                SetValue($tid, $value);
                $ok = true;
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        if (!$ok) {
            // Fehlschlag dauerhaft loggen, nicht nur ins flüchtige Debug
            // (SUITE.md "Sichtbare Rückmeldung", Regel 5).
            IPS_LogMessage('Schein', 'Schaltung fehlgeschlagen: ' . $label . ' (Variable #' . $tid . ')' . ($error !== '' ? ' — ' . $error : ''));
            $this->setStringIfChanged('LastAction', date('H:i:s', $this->now()) . ' Uhr: ⚠️ ' . $label . ' fehlgeschlagen');
            return;
        }
        $this->SendDebug('Abspielen', $label . ' (Vorbild ' . $this->fmtDay($refDate) . ')', 0);
        $this->setStringIfChanged('LastAction', date('H:i:s', $this->now()) . ' Uhr: ' . $label . ' (Vorbild ' . $this->fmtDay($refDate) . ')');
    }

    // -----------------------------------------------------------------
    // Sonnenauf-/-untergang aus Symcons Location Control
    // -----------------------------------------------------------------

    /**
     * Zeitstempel des Sonnenauf-/-untergangs, der dem Zeitpunkt $ts am
     * nächsten liegt. Location Control hält in seinen Variablen immer das
     * NÄCHSTE Ereignis (nach dem heutigen Untergang steht dort schon der
     * morgige) — deshalb auf ±12 h um $ts normalisieren. null ohne
     * Location-Control-Instanz.
     */
    private function anchorTs(string $ident, int $ts): ?int
    {
        $vid = $this->locationVariable($ident);
        if ($vid === 0) {
            return null;
        }
        $v = (int)GetValue($vid);
        if ($v <= 0) {
            return null;
        }
        while ($v - $ts > 43200) {
            $v = strtotime('-1 day', $v);
        }
        while ($ts - $v > 43200) {
            $v = strtotime('+1 day', $v);
        }
        return $v;
    }

    private function locationVariable(string $ident): int
    {
        foreach (IPS_GetInstanceListByModuleID(self::LOCATION_CONTROL_GUID) as $iid) {
            $vid = @IPS_GetObjectIDByIdent($ident, $iid);
            if ($vid && IPS_VariableExists($vid)) {
                return (int)$vid;
            }
        }
        return 0;
    }

    private function hasLocationControl(): bool
    {
        return $this->locationVariable('Sunset') > 0;
    }

    // -----------------------------------------------------------------
    // Helfer
    // -----------------------------------------------------------------

    /** Testbar überschreibbar (Prüfstand ohne Symcon). */
    protected function now(): int
    {
        return time();
    }

    /** Zufallsfaktor in [-1, 1] — testbar überschreibbar. */
    protected function randomUnit(): float
    {
        return random_int(-1000, 1000) / 1000;
    }

    /** Zufallsindex 0..$count-1 — testbar überschreibbar. */
    protected function randomIndex(int $count): int
    {
        return random_int(0, max(0, $count - 1));
    }

    private function isActive(): bool
    {
        return (bool)$this->GetValue('Active');
    }

    private function plan(): array
    {
        $plan = json_decode($this->ReadAttributeString('ReplayPlan'), true);
        return is_array($plan) ? $plan : [];
    }

    private function channels(): array
    {
        $channels = json_decode($this->ReadPropertyString('Channels'), true);
        return is_array($channels) ? $channels : [];
    }

    /** Kanäle, die abgespielt werden (Modus "Lernen + Abspielen", gültige Zielvariable). */
    private function playableChannels(): array
    {
        $out = [];
        foreach ($this->channels() as $ch) {
            $tid = (int)($ch['TargetID'] ?? 0);
            if ((int)($ch['Mode'] ?? self::MODE_OFF) === self::MODE_LEARN_PLAY && $tid > 0 && IPS_VariableExists($tid)) {
                $out[] = $ch;
            }
        }
        return $out;
    }

    private function channelByTarget(int $tid): ?array
    {
        foreach ($this->channels() as $ch) {
            if ((int)($ch['TargetID'] ?? 0) === $tid) {
                return $ch;
            }
        }
        return null;
    }

    private function channelName(array $ch): string
    {
        $caption = trim((string)($ch['Caption'] ?? ''));
        if ($caption !== '') {
            return $caption;
        }
        $tid = (int)($ch['TargetID'] ?? 0);
        return $tid > 0 && IPS_VariableExists($tid) ? IPS_GetName($tid) : 'Kanal';
    }

    private function bufferID(int $tid): int
    {
        if ($tid <= 0) {
            return 0;
        }
        $bid = @IPS_GetObjectIDByIdent('buf_' . $tid, $this->InstanceID);
        return $bid ? (int)$bid : 0;
    }

    private function ensureBuffer(int $tid, string $caption): int
    {
        $bid = $this->bufferID($tid);
        if (!$bid) {
            $bid = $this->RegisterVariableString('buf_' . $tid, 'Gelernt: ' . ($caption !== '' ? $caption : IPS_GetName($tid)), '', 100 + $tid % 1000);
            SetValueString($bid, '[]');
        }
        IPS_SetHidden($bid, true);
        return $bid;
    }

    private function buffer(int $tid): array
    {
        $bid = $this->bufferID($tid);
        if (!$bid) {
            return [];
        }
        $buffer = json_decode(GetValueString($bid), true);
        return is_array($buffer) ? $buffer : [];
    }

    private function normalizeValue($value)
    {
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return $value + 0;
        }
        return (string)$value;
    }

    private function sameValue($a, $b): bool
    {
        if (is_bool($a) || is_bool($b)) {
            return (bool)$a === (bool)$b;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return abs((float)$a - (float)$b) < 0.000001;
        }
        return $a === $b;
    }

    private function castForVariable(int $tid, $value)
    {
        switch ((int)(IPS_GetVariable($tid)['VariableType'] ?? -1)) {
            case VARIABLETYPE_BOOLEAN:
                return (bool)$value;
            case VARIABLETYPE_INTEGER:
                return (int)$value;
            case VARIABLETYPE_FLOAT:
                return (float)$value;
            default:
                return (string)$value;
        }
    }

    private function fmtValue($value): string
    {
        if (is_bool($value)) {
            return $value ? 'An' : 'Aus';
        }
        if (is_float($value)) {
            return rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',');
        }
        return (string)$value;
    }

    private function fmtDay(string $ymd): string
    {
        $ts = strtotime($ymd . ' 12:00:00');
        $names = [1 => 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
        return ($names[(int)date('N', $ts)] ?? '') . ' ' . date('d.m.Y', $ts); // TT.MM.JJJJ, SUITE.md Store-Checkliste 9b
    }

    private function fmtJitter(int $sec): string
    {
        $min = (int)round($sec / 60);
        return ($min >= 0 ? '+' : '') . $min . ' min';
    }

    private function setStringIfChanged(string $ident, string $value): void
    {
        if ($this->GetValue($ident) !== $value) {
            $this->SetValue($ident, $value);
        }
    }

    // -----------------------------------------------------------------
    // Formular
    // -----------------------------------------------------------------

    public function AckPurposeIntro(): void
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
    }

    public function AckNews(): void
    {
        $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    public function AckForumHint(): void
    {
        $this->WriteAttributeBoolean('ForumHintGone', true);
        $this->UpdateFormField('ForumHintPanel', 'visible', false);
    }

    private function PurposeIntro(): ?array
    {
        if ($this->ReadAttributeBoolean('PurposeIntroGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true,
            'caption' => '👋  Wozu dieses Modul?',
            'items' => [
                ['type' => 'Label', 'caption' => 'Schein lässt ein leeres Haus bewohnt aussehen. Es merkt sich, wann ihr im Alltag wirklich Licht, Rollläden, Steckdosen oder das Radio schaltet — jede schaltbare Symcon-Variable, egal aus welchem Modul — und spielt dieses echte Muster ab, sobald niemand da ist.'],
                ['type' => 'Label', 'caption' => 'Der Nutzen: keine starren Zeitpläne, die jeder Beobachter nach zwei Abenden durchschaut. Schein wählt jeden Tag einen echten Vorbild-Tag desselben Wochentags aus den letzten Wochen, verschiebt ihn zufällig um ein paar Minuten und hält die Reihenfolge zwischen den Räumen glaubwürdig — der Schein trügt.'],
                ['type' => 'Label', 'caption' => 'Starten per Schalter „Simulation aktiv" (auch aus WebFront/Kachel oder per Skript) oder automatisch über eine Abwesenheits-/Alarm-Variable. Solange die Simulation aus ist, lernt Schein einfach mit.'],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'SCHEIN_AckPurposeIntro($id);'],
            ],
        ];
    }

    private function NewsBanner(): ?array
    {
        if ($this->ReadAttributeString('SeenNews') === self::NEWS_VERSION) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'expanded' => true,
            'caption' => '🆕  Neu in Version ' . self::NEWS_VERSION,
            'items' => [
                ['type' => 'Label', 'caption' => '• Erstes Release: Lernen echter Schaltmuster je Kanal (ohne Symcon-Archiv), Abspielen nach dem Vorbild-Tag-Prinzip mit Zufallsversatz, gemeinsamer Vorbild-Tag für alle Kanäle.'],
                ['type' => 'Label', 'caption' => '• Zeitbezug je Kanal wahlweise Uhrzeit oder Abstand zu Sonnenauf-/-untergang (nutzt Symcons eingebaute Location-Control-Instanz, falls vorhanden).'],
                ['type' => 'Label', 'caption' => '• Start/Stopp per Schalter, Formular-Button, Skript (IPS_RequestAction) oder Auslöser-Variable; „📅 Heutigen Ablauf anzeigen" zeigt den Plan.'],
                ['type' => 'Label', 'caption' => '• 👋 Zweck-Einführung ganz oben im Formular (einmalig wegklickbar).'],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'SCHEIN_AckNews($id);'],
            ],
        ];
    }

    private function DocPanel(): array
    {
        $lib    = @IPS_GetLibrary(self::LIBRARY_GUID);
        $verTxt = (is_array($lib) && isset($lib['Version']))
            ? 'ℹ️ Schein Version ' . $lib['Version'] . ' (Build ' . ($lib['Build'] ?? '?') . ')'
            : 'ℹ️ Schein';
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '📖  Dokumentation & Hilfe',
            'items' => [
                ['type' => 'Label', 'caption' => $verTxt],
                ['type' => 'Label', 'caption' => 'Kanal anlegen: Bezeichnung, Zielvariable (Bool, Integer oder Float — z. B. ein Licht, eine Steckdose, ein Rollladen), Modus, Zeitbezug und Versatz wählen, dann „Übernehmen". Pro Kanal entsteht eine versteckte Variable „Gelernt: …" (der Lernspeicher, kein Symcon-Archiv nötig).'],
                ['type' => 'Label', 'caption' => 'Lernen: Solange „Simulation aktiv" AUS ist, zeichnet Schein jede echte Wertänderung der Zielvariable auf — Zeitpunkt und Wert. Alles außerhalb des Lernfensters (Standard 28 Tage) fällt automatisch heraus; so folgt das Muster der Jahreszeit von selbst.'],
                ['type' => 'Label', 'caption' => 'Abspielen: Ist die Simulation AN, wählt Schein für jeden Tag einen echten Vorbild-Tag desselben Wochentags (ersatzweise Werktag/Wochenende) und spielt dessen Schaltfolge ab — verschoben um einen zufälligen Versatz bis zum eingestellten Maximum. Beim Start wird zuerst der Zustand hergestellt, den das Vorbild um diese Uhrzeit hatte.'],
                ['type' => 'Label', 'caption' => 'Modus „Nur lernen" sammelt Muster, ohne je zu schalten — praktisch, um einen neuen Kanal erst einmal zu beobachten („📅 Heutigen Ablauf anzeigen" zeigt, was er täte). Modus „Aus" pausiert den Kanal komplett, behält aber das Gelernte.'],
                ['type' => 'Label', 'caption' => 'Zeitbezug „Sonnenuntergang"/„Sonnenaufgang": Schaltzeiten werden als Abstand zum Sonnenstand gelernt und abgespielt (Beleuchtung folgt so der Dämmerung, nicht der Uhr). Braucht Symcons Location-Control-Instanz (Kernbestandteil, Einstellungen → Standort) — ohne sie gilt automatisch die Uhrzeit.'],
                ['type' => 'Label', 'caption' => 'Auslöser-Variable (optional): eine Bool-Variable, z. B. „Abwesend", „Urlaub" oder „Alarmanlage scharf". Wird sie TRUE, startet die Simulation; FALSE beendet sie. „Invertiert" dreht das um (für Variablen wie „Anwesend"). Der Schalter „Simulation aktiv" bleibt daneben immer manuell bedienbar.'],
                ['type' => 'Label', 'caption' => 'Während die Simulation läuft, wird NICHT gelernt — sonst würde Schein seine eigenen Wiedergaben als echtes Verhalten mitlernen. Beim Beenden bleiben die Zielvariablen so stehen, wie sie gerade sind.'],
                ['type' => 'Label', 'caption' => 'Skripte: IPS_RequestAction(<InstanzID>, \'Active\', true/false) startet/beendet; SCHEIN_Preview(<InstanzID>) liefert den heutigen Ablauf als Text; SCHEIN_ForgetChannel(<InstanzID>, \'Bezeichnung\') verwirft das Gelernte eines Kanals.'],
                ['type' => 'Label', 'caption' => 'Mehrere Instanzen sind erlaubt (z. B. je Etage oder Wohnung). Jede Instanz lernt und spielt unabhängig.'],
            ],
        ];
    }

    private function SimulationPanel(): array
    {
        $channels = $this->channels();
        $playable = count($this->playableChannels());
        $learning = 0;
        $events = 0;
        foreach ($channels as $ch) {
            $tid = (int)($ch['TargetID'] ?? 0);
            if ((int)($ch['Mode'] ?? 0) !== self::MODE_OFF && $tid > 0 && IPS_VariableExists($tid)) {
                $learning++;
            }
            $events += count($this->buffer($tid));
        }
        $lastLearn = $this->ReadAttributeInteger('LastLearnTs');
        if ($this->isActive()) {
            $status = '🎭 Simulation AKTIV seit ' . date('d.m.Y H:i', $this->ReadAttributeInteger('ActiveSince')) . ' Uhr — ' . $playable . ' Kanal/Kanäle spielen ab. Nächste Schaltung: ' . ($this->GetValue('NextAction') ?: '—');
        } elseif ($learning === 0) {
            $status = 'ℹ️ Noch kein Kanal aktiv — unten Kanäle anlegen und übernehmen.';
        } else {
            $status = '💤 Simulation aus — ' . $learning . ' Kanal/Kanäle lernen mit (' . $events . ' Schaltvorgänge im Lernfenster'
                . ($lastLearn > 0 ? ', zuletzt ' . date('d.m.Y H:i:s', $lastLearn) . ' Uhr' : ', noch keiner') . ').';
        }
        $astro = $this->hasLocationControl()
            ? '✅ Sonnenauf-/-untergang verfügbar (Symcon Location Control gefunden) — Zeitbezug „Sonnenuntergang"/„Sonnenaufgang" ist nutzbar.'
            : 'ℹ️ Keine Location-Control-Instanz gefunden — Kanäle mit Zeitbezug Sonnenauf-/-untergang fallen auf die Uhrzeit zurück. Anlegen unter Einstellungen → Standort, falls gewünscht.';

        return [
            'type' => 'ExpansionPanel', 'expanded' => true,
            'caption' => '🎭  Simulation',
            'items' => [
                ['type' => 'Label', 'name' => 'SimStatus', 'caption' => $status],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => '▶️ Simulation jetzt starten', 'onClick' => 'echo SCHEIN_SetActive($id, true);'],
                    ['type' => 'Button', 'caption' => '⏹ Simulation beenden', 'onClick' => 'echo SCHEIN_SetActive($id, false);'],
                    ['type' => 'Button', 'caption' => '📅 Heutigen Ablauf anzeigen', 'onClick' => 'echo SCHEIN_Preview($id);'],
                ]],
                ['type' => 'Label', 'caption' => 'Der Schalter „Simulation aktiv" unter der Instanz tut dasselbe — auch aus WebFront/Kachel, per Ereignis oder Skript.'],
                ['type' => 'SelectVariable', 'name' => 'TriggerID', 'caption' => 'Auslöser-Variable (optional, Bool)'],
                ['type' => 'CheckBox', 'name' => 'TriggerInvert', 'caption' => 'Auslöser invertiert (Variable TRUE = jemand ist da, z. B. „Anwesend")'],
                ['type' => 'Label', 'caption' => 'ℹ️ Ohne Auslöser-Variable wird nur manuell gestartet. Mit Auslöser: TRUE startet, FALSE beendet — z. B. eine Abwesenheits-, Urlaubs- oder Alarm-scharf-Variable aus einem beliebigen Modul.'],
                ['type' => 'NumberSpinner', 'name' => 'LearnDays', 'caption' => 'Lernfenster', 'suffix' => ' Tage', 'minimum' => 7, 'maximum' => 365],
                ['type' => 'CheckBox', 'name' => 'CoherentReplay', 'caption' => 'Alle Kanäle spielen denselben Vorbild-Tag ab (Reihenfolge zwischen den Räumen bleibt echt)'],
                ['type' => 'Label', 'caption' => $astro],
            ],
        ];
    }

    private function ChannelsPanel(): array
    {
        $summary = [];
        foreach ($this->channels() as $ch) {
            $tid = (int)($ch['TargetID'] ?? 0);
            $name = $this->channelName($ch);
            if ($tid <= 0 || !IPS_VariableExists($tid)) {
                $summary[] = '⚠️ ' . $name . ': Zielvariable fehlt oder existiert nicht mehr.';
                continue;
            }
            $buf = $this->buffer($tid);
            $n = count($buf);
            if ($n === 0) {
                $summary[] = 'ℹ️ ' . $name . ': noch nichts gelernt.';
                continue;
            }
            $summary[] = '✅ ' . $name . ': ' . $n . ' Schaltvorgänge seit ' . date('d.m.Y', (int)$buf[0]['t']) . ', zuletzt ' . date('d.m.Y H:i', (int)$buf[$n - 1]['t']) . ' Uhr.';
        }
        return [
            'type' => 'ExpansionPanel', 'expanded' => true,
            'caption' => '💡  Kanäle',
            'items' => [
                ['type' => 'Label', 'caption' => 'Ein Kanal = eine schaltbare Zielvariable (z. B. Wohnzimmerlicht, Steckdose Stehlampe, Rollladen Küche). Verschiedene Kanäle derselben Instanz werden gemeinsam glaubwürdig abgespielt, siehe oben.'],
                [
                    'type' => 'List', 'name' => 'Channels', 'caption' => 'Kanäle', 'rowCount' => 8, 'add' => true, 'delete' => true,
                    'columns' => [
                        ['caption' => 'Bezeichnung', 'name' => 'Caption', 'width' => '180px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => 'Zielvariable', 'name' => 'TargetID', 'width' => 'auto', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                        ['caption' => 'Modus', 'name' => 'Mode', 'width' => '190px', 'add' => self::MODE_LEARN_PLAY, 'edit' => ['type' => 'Select', 'options' => [
                            ['caption' => 'Aus', 'value' => self::MODE_OFF],
                            ['caption' => 'Lernen + Abspielen', 'value' => self::MODE_LEARN_PLAY],
                            ['caption' => 'Nur lernen (nie schalten)', 'value' => self::MODE_LEARN_ONLY],
                        ]]],
                        ['caption' => 'Zeitbezug', 'name' => 'TimeRef', 'width' => '170px', 'add' => self::TIMEREF_CLOCK, 'edit' => ['type' => 'Select', 'options' => [
                            ['caption' => 'Uhrzeit', 'value' => self::TIMEREF_CLOCK],
                            ['caption' => 'Sonnenuntergang', 'value' => self::TIMEREF_SUNSET],
                            ['caption' => 'Sonnenaufgang', 'value' => self::TIMEREF_SUNRISE],
                        ]]],
                        ['caption' => 'Versatz max.', 'name' => 'JitterMin', 'width' => '130px', 'add' => 10, 'edit' => ['type' => 'NumberSpinner', 'suffix' => ' min', 'minimum' => 0, 'maximum' => 180]],
                    ],
                ],
                ['type' => 'PopupButton', 'caption' => 'Was bedeuten Modus, Zeitbezug und Versatz?', 'width' => '420px', 'popup' => [
                    'caption' => 'Kanal-Einstellungen',
                    'items' => [
                        ['type' => 'Label', 'caption' => 'Modus — „Lernen + Abspielen": Normalfall. „Nur lernen": beobachtet nur, schaltet nie (zum Ausprobieren). „Aus": Kanal pausiert, Gelerntes bleibt.'],
                        ['type' => 'Label', 'caption' => 'Zeitbezug — „Uhrzeit": Schaltung wird zur gleichen Uhrzeit wie am Vorbild-Tag abgespielt. „Sonnenuntergang"/„Sonnenaufgang": zum gleichen Abstand vor/nach dem Sonnenstand — sinnvoll für Beleuchtung, die der Dämmerung folgt. Braucht Symcons Location Control.'],
                        ['type' => 'Label', 'caption' => 'Versatz max. — größter zufälliger Zeitversatz pro Tag in Minuten (± um die Vorbild-Zeit). 0 = exakt wie am Vorbild-Tag; 10–20 wirken natürlich. Bei „gemeinsamer Vorbild-Tag" bekommen alle Kanäle denselben relativen Versatz, die Reihenfolge bleibt.'],
                    ],
                ]],
                ['type' => 'Label', 'name' => 'ChannelSummary', 'caption' => $summary ? implode("\n", $summary) : 'ℹ️ Noch keine Kanäle angelegt.'],
            ],
        ];
    }

    private function ForumHint(): ?array
    {
        if ($this->ReadAttributeBoolean('ForumHintGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'ForumHintPanel', 'expanded' => true,
            'caption' => '💬  Rückmeldungen',
            'items' => [
                ['type' => 'Label', 'caption' => '🧪 Schein ist neu — Fragen, Wünsche oder Fehler sind willkommen (Symcon-Forum bzw. GitHub).'],
                ['type' => 'Button', 'caption' => 'Zum Repository', 'onClick' => "echo '" . self::REPO_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'SCHEIN_AckForumHint($id);'],
            ],
        ];
    }

    /**
     * Lizenz-/Unterstützungs-Hinweis — Wortlaut verbundweit identisch
     * (SUITE.md "Einheitliche Formular-Optik", Variante A). Bewusst NICHT
     * dismissible, ganz unten nach dem Forum-Hinweis.
     */
    private function LicenseHint(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🧡  Über dieses Modul',
            'items' => [
                ['type' => 'Label', 'caption' => 'Entstanden aus echter Begeisterung für die eigene Anlage — und ein paar durchgetippten Abenden. Trotzdem: Software-Hobby hin oder her, das hier ist geistiges Eigentum und echte Arbeit steckt drin.'],
                ['type' => 'Label', 'caption' => 'Lizenz: PolyForm Noncommercial 1.0.0 — privat und nicht-kommerziell frei nutzbar, für den gewerblichen Einsatz braucht es eine gesonderte Lizenz vom Rechteinhaber.'],
                ['type' => 'Button', 'caption' => 'Lizenztext ansehen', 'onClick' => "echo '" . self::LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Gewerbliche Nutzung oder Fragen zur Lizenz? Einfach melden: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'Gefällt dir das Modul und du möchtest trotzdem etwas dalassen? Über eine kleine Spende freue ich mich — völlig freiwillig, keine Gegenleistung nötig.'],
                ['type' => 'Button', 'caption' => '☕  Spenden via PayPal', 'onClick' => "echo '" . self::PAYPAL_URL . "';", 'link' => true],
            ],
        ];
    }

    public function GetConfigurationForm()
    {
        $base   = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $status = $base['status'] ?? [];

        $elements = array_values(array_filter(array_merge(
            [$this->PurposeIntro(), $this->NewsBanner(), $this->DocPanel()],
            [$this->SimulationPanel(), $this->ChannelsPanel()],
            [$this->ForumHint(), $this->LicenseHint()]
        )));

        return json_encode(['elements' => $elements, 'actions' => [], 'status' => $status]);
    }
}

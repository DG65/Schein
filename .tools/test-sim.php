<?php
/**
 * test-sim.php — Prüfstand für Schein OHNE laufendes Symcon.
 *
 * Simuliert IPSModule (Properties/Attribute/Variablen/Timer/Nachrichten) und
 * die benötigten IPS_*-Kernfunktionen, spielt dann vier Lernwochen plus einen
 * kompletten Simulationstag durch: Lernen (Dedupe, Zusammenfassen, Trimmen),
 * kein Lernen während der Wiedergabe, Vorbild-Tag-Wahl (gemeinsam/eigen),
 * Einschwingen, minütliche Wiedergabe mit Versatz, Tageswechsel, Auslöser-
 * Variable, Sonnenuntergangs-Bezug, Vergessen, Resync-Fail-safe, Formular- und
 * Vertrags-Hygiene.
 *
 * Aufruf:   php .tools/test-sim.php
 * Rückgabe: 0 = alle Blöcke bestanden, 1 = mindestens ein Fehlschlag
 */

date_default_timezone_set('Europe/Berlin');

const VM_UPDATE = 10603;
const KR_READY = 10103;
const IPS_KERNELMESSAGE = 10100;
const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT = 2;
const VARIABLETYPE_STRING = 3;

$GLOBALS['VARS'] = [];      // id => [type, value, name, ident, parent, hidden, action]
$GLOBALS['NEXT_ID'] = 50000;
$GLOBALS['ACTIONS'] = [];   // [ts, id, value, via]
$GLOBALS['LOG'] = [];
$GLOBALS['LOC'] = [];       // Location-Control-Instanzen

function mkvar(int $id, int $type, $value, string $name, string $ident = '', int $parent = 0, bool $action = false): void
{
    $GLOBALS['VARS'][$id] = ['type' => $type, 'value' => $value, 'name' => $name, 'ident' => $ident, 'parent' => $parent, 'hidden' => false, 'action' => $action];
}
function IPS_GetKernelRunlevel(): int { return KR_READY; }
function IPS_VariableExists($id): bool { return isset($GLOBALS['VARS'][(int)$id]); }
function IPS_GetVariable($id): array { return ['VariableType' => $GLOBALS['VARS'][(int)$id]['type']]; }
function GetValue($id) { return $GLOBALS['VARS'][(int)$id]['value']; }
function GetValueString($id): string { return (string)$GLOBALS['VARS'][(int)$id]['value']; }
function SetValue($id, $v): bool { $GLOBALS['VARS'][(int)$id]['value'] = $v; $GLOBALS['ACTIONS'][] = [$GLOBALS['CLOCK'], (int)$id, $v, 'SetValue']; return true; }
function SetValueString($id, string $v): bool { $GLOBALS['VARS'][(int)$id]['value'] = $v; return true; }
function HasAction($id): bool { return (bool)$GLOBALS['VARS'][(int)$id]['action']; }
function RequestAction($id, $v): bool { $GLOBALS['VARS'][(int)$id]['value'] = $v; $GLOBALS['ACTIONS'][] = [$GLOBALS['CLOCK'], (int)$id, $v, 'RequestAction']; return true; }
function IPS_LogMessage(string $s, string $m): bool { $GLOBALS['LOG'][] = "$s: $m"; return true; }
function IPS_GetInstanceListByModuleID(string $guid): array { return array_keys($GLOBALS['LOC']); }
function IPS_GetObjectIDByIdent(string $ident, int $parent) {
    foreach ($GLOBALS['VARS'] as $id => $v) { if ($v['ident'] === $ident && $v['parent'] === $parent) { return $id; } }
    return false;
}
function IPS_GetChildrenIDs(int $parent): array { $o = []; foreach ($GLOBALS['VARS'] as $id => $v) { if ($v['parent'] === $parent) { $o[] = $id; } } return $o; }
function IPS_GetObject(int $id): array { return ['ObjectIdent' => $GLOBALS['VARS'][$id]['ident'] ?? '', 'ObjectName' => $GLOBALS['VARS'][$id]['name'] ?? '']; }
function IPS_DeleteVariable(int $id): bool { unset($GLOBALS['VARS'][$id]); return true; }
function IPS_SetHidden(int $id, bool $h): bool { $GLOBALS['VARS'][$id]['hidden'] = $h; return true; }
function IPS_GetName(int $id): string { return $GLOBALS['VARS'][$id]['name'] ?? ''; }
function IPS_GetLibrary(string $guid): array { return ['Version' => '0.0.0-test', 'Build' => 0]; }

class IPSModule
{
    public int $InstanceID = 12345;
    public array $props = [];
    public array $attrs = [];
    public int $status = 0;
    public array $timers = [];
    public array $messages = [];
    public array $debug = [];
    public array $fieldUpdates = [];
    public int $reloads = 0;

    public function Create() {}
    public function ApplyChanges() {}
    public function RegisterPropertyString(string $n, string $d): void { if (!array_key_exists($n, $this->props)) { $this->props[$n] = $d; } }
    public function RegisterPropertyInteger(string $n, int $d): void { if (!array_key_exists($n, $this->props)) { $this->props[$n] = $d; } }
    public function RegisterPropertyBoolean(string $n, bool $d): void { if (!array_key_exists($n, $this->props)) { $this->props[$n] = $d; } }
    public function ReadPropertyString(string $n): string { return $this->props[$n]; }
    public function ReadPropertyInteger(string $n): int { return $this->props[$n]; }
    public function ReadPropertyBoolean(string $n): bool { return $this->props[$n]; }
    public function RegisterAttributeString(string $n, string $d): void { if (!array_key_exists($n, $this->attrs)) { $this->attrs[$n] = $d; } }
    public function RegisterAttributeInteger(string $n, int $d): void { if (!array_key_exists($n, $this->attrs)) { $this->attrs[$n] = $d; } }
    public function RegisterAttributeBoolean(string $n, bool $d): void { if (!array_key_exists($n, $this->attrs)) { $this->attrs[$n] = $d; } }
    public function ReadAttributeString(string $n): string { return $this->attrs[$n]; }
    public function ReadAttributeInteger(string $n): int { return $this->attrs[$n]; }
    public function ReadAttributeBoolean(string $n): bool { return $this->attrs[$n]; }
    public function WriteAttributeString(string $n, string $v): void { $this->attrs[$n] = $v; }
    public function WriteAttributeInteger(string $n, int $v): void { $this->attrs[$n] = $v; }
    public function WriteAttributeBoolean(string $n, bool $v): void { $this->attrs[$n] = $v; }
    public function SetStatus(int $s): void { $this->status = $s; }
    public function RegisterTimer(string $n, int $ms, string $script): void { $this->timers[$n] = $ms; }
    public function SetTimerInterval(string $n, int $ms): void { $this->timers[$n] = $ms; }
    public function RegisterMessage(int $sender, int $msg): void { $this->messages[$sender][$msg] = true; }
    public function UnregisterMessage(int $sender, int $msg): void { unset($this->messages[$sender][$msg]); if (empty($this->messages[$sender])) { unset($this->messages[$sender]); } }
    public function GetMessageList(): array { $o = []; foreach ($this->messages as $s => $m) { $o[$s] = array_keys($m); } return $o; }
    public function SendDebug(string $a, string $b, int $c): void { $this->debug[] = "$a: $b"; }
    public function UpdateFormField(string $n, string $p, $v): void { $this->fieldUpdates[] = [$n, $p, $v]; }
    public function ReloadForm(): void { $this->reloads++; }
    public function MaintainVariable(string $ident, string $name, int $type, string $profile, int $pos, bool $keep): void
    {
        if ($keep && IPS_GetObjectIDByIdent($ident, $this->InstanceID) === false) {
            $def = [VARIABLETYPE_BOOLEAN => false, VARIABLETYPE_INTEGER => 0, VARIABLETYPE_FLOAT => 0.0, VARIABLETYPE_STRING => ''][$type];
            mkvar($GLOBALS['NEXT_ID']++, $type, $def, $name, $ident, $this->InstanceID);
        }
    }
    public function RegisterVariableString(string $ident, string $name, string $profile, int $pos): int
    {
        $id = IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($id === false) { $id = $GLOBALS['NEXT_ID']++; mkvar($id, VARIABLETYPE_STRING, '', $name, $ident, $this->InstanceID); }
        return $id;
    }
    public function EnableAction(string $ident): void { $id = IPS_GetObjectIDByIdent($ident, $this->InstanceID); if ($id !== false) { $GLOBALS['VARS'][$id]['action'] = true; } }
    public function SetValue(string $ident, $v): void { $id = IPS_GetObjectIDByIdent($ident, $this->InstanceID); $GLOBALS['VARS'][$id]['value'] = $v; }
    public function GetValue(string $ident) { $id = IPS_GetObjectIDByIdent($ident, $this->InstanceID); return $GLOBALS['VARS'][$id]['value']; }
    /** Simuliert MC_DeleteModule()+MC_CreateModule(): Attribute weg, Create()/ApplyChanges() laufen neu. */
    public function simulateResync(): void { $this->attrs = []; $this->Create(); $this->ApplyChanges(); }
}

require dirname(__DIR__) . '/Schein/module.php';

class ScheinTest extends Schein
{
    public float $unit = 0.5;
    public int $index = 0;
    protected function now(): int { return $GLOBALS['CLOCK']; }
    protected function randomUnit(): float { return $this->unit; }
    protected function randomIndex(int $count): int { return min($this->index, $count - 1); }
}

$fails = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $fails;
    echo ($ok ? '  ✅ ' : '  ❌ ') . $what . ($ok || $detail === '' ? '' : ' — ' . $detail) . "\n";
    if (!$ok) { $fails++; }
}
function heading(string $t): void { echo "\n[$t]\n"; }
function ts(string $s): int { return strtotime($s); }
function clock(string $s): void { $GLOBALS['CLOCK'] = ts($s); }
function fire(Schein $m, int $vid, $value): void { $GLOBALS['VARS'][$vid]['value'] = $value; $m->MessageSink($GLOBALS['CLOCK'], $vid, VM_UPDATE, [$value, true, null, $GLOBALS['CLOCK']]); }
function bufCount(int $tid): int { $bid = IPS_GetObjectIDByIdent('buf_' . $tid, 12345); return $bid ? count(json_decode(GetValueString($bid), true)) : -1; }
function bufFirst(int $tid): array { $bid = IPS_GetObjectIDByIdent('buf_' . $tid, 12345); $b = json_decode(GetValueString($bid), true); return $b[0] ?? []; }
function actionsSince(int $from): array { return array_values(array_filter($GLOBALS['ACTIONS'], fn($a) => $a[0] >= $from)); }
function form(Schein $m): array { $f = json_decode($m->GetConfigurationForm(), true); if (!is_array($f)) { throw new RuntimeException('GetConfigurationForm() liefert kein gültiges JSON'); } return $f; }
function formText(array $f): string { return json_encode($f, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }

// Zielvariablen
mkvar(100, VARIABLETYPE_BOOLEAN, false, 'Wohnzimmerlicht', '', 1, true);
mkvar(101, VARIABLETYPE_BOOLEAN, false, 'Küche', '', 1, true);
mkvar(102, VARIABLETYPE_BOOLEAN, false, 'Probe', '', 1, true);
mkvar(103, VARIABLETYPE_INTEGER, 0, 'Dimmer', '', 1, false);
mkvar(104, VARIABLETYPE_BOOLEAN, false, 'Außenlicht', '', 1, true);
mkvar(200, VARIABLETYPE_BOOLEAN, false, 'Abwesend', '', 1, false);
// Location Control (Sonnenuntergang) — Instanz 10443, Variable Sunset
mkvar(10444, VARIABLETYPE_INTEGER, 0, 'Sonnenuntergang', 'Sunset', 10443);
$GLOBALS['LOC'] = [10443 => true];

$channels = [
    ['Caption' => 'Wohnzimmer', 'TargetID' => 100, 'Mode' => 1, 'TimeRef' => 0, 'JitterMin' => 10],
    ['Caption' => 'Küche',      'TargetID' => 101, 'Mode' => 1, 'TimeRef' => 0, 'JitterMin' => 5],
    ['Caption' => 'Probe',      'TargetID' => 102, 'Mode' => 2, 'TimeRef' => 0, 'JitterMin' => 10],
    ['Caption' => 'Dimmer',     'TargetID' => 103, 'Mode' => 1, 'TimeRef' => 0, 'JitterMin' => 0],
    ['Caption' => 'Außenlicht', 'TargetID' => 104, 'Mode' => 1, 'TimeRef' => 1, 'JitterMin' => 10],
];

clock('2026-08-01 12:00:00');
$m = new ScheinTest();
$m->Create();
$m->props['Channels'] = json_encode($channels);
$m->props['TriggerID'] = 200;
$m->ApplyChanges();

heading('1. Anlage — Status, Nachrichten, Lernspeicher, Timer');
check('Status 102 (Kanäle konfiguriert)', $m->status === 102);
check('Timer aus, solange Simulation aus (Lernen ist ereignisgesteuert)', $m->timers['Tick'] === 0);
check('VM_UPDATE für alle Zielvariablen + Auslöser registriert', isset($m->messages[100][VM_UPDATE], $m->messages[101][VM_UPDATE], $m->messages[102][VM_UPDATE], $m->messages[103][VM_UPDATE], $m->messages[104][VM_UPDATE], $m->messages[200][VM_UPDATE]));
check('Lernspeicher je Kanal angelegt und versteckt', IPS_GetObjectIDByIdent('buf_100', 12345) !== false && $GLOBALS['VARS'][IPS_GetObjectIDByIdent('buf_100', 12345)]['hidden'] === true);
check('Instanzvariable „Simulation aktiv" mit Aktion', IPS_GetObjectIDByIdent('Active', 12345) !== false && HasAction(IPS_GetObjectIDByIdent('Active', 12345)));

heading('2. Vier Lernwochen (10.08.–06.09.2026), plus ein Alt-Ereignis vom 05.08.');
clock('2026-08-05 18:00:00'); fire($m, 100, true);
clock('2026-08-05 23:00:00'); fire($m, 100, false);
$sunsetOf = function (string $day): int { $i = (int)((ts($day) - ts('2026-08-10')) / 86400); return ts($day . ' 20:30:00') - $i * 120; }; // -2 min/Tag
for ($i = 0; $i < 28; $i++) {
    $day = date('Y-m-d', ts('2026-08-10 +' . $i . ' days'));
    $n = (int)date('N', ts($day));
    if ($n >= 6) { clock($day . ' 12:00:00'); fire($m, 101, true); clock($day . ' 13:00:00'); fire($m, 101, false); }
    clock($day . ' ' . sprintf('18:%02d:00', ($i % 4) * 3)); fire($m, 100, true);
    clock($day . ' 18:10:00'); fire($m, 101, true);
    clock($day . ' 19:00:00'); fire($m, 101, false);
    clock($day . ' 20:00:00'); fire($m, 102, true);
    clock($day . ' 21:00:00'); fire($m, 102, false);
    // Außenlicht: 30 min nach dem (wandernden) Sonnenuntergang an, 23:00 aus.
    $GLOBALS['VARS'][10444]['value'] = $sunsetOf($day);
    clock(date('Y-m-d H:i:s', $sunsetOf($day) + 1800)); fire($m, 104, true);
    clock($day . ' 23:00:00'); fire($m, 104, false);
    clock($day . ' 23:30:00'); fire($m, 100, false);
}
check('Wohnzimmer: 56 Schaltvorgänge (2/Tag × 28)', bufCount(100) === 56, 'ist ' . bufCount(100));
check('Küche: 72 (Wochenende 4/Tag)', bufCount(101) === 72, 'ist ' . bufCount(101));
check('Probe (nur lernen) lernt trotzdem mit: 56', bufCount(102) === 56, 'ist ' . bufCount(102));
check('Alt-Ereignis vom 05.08. ist aus dem 28-Tage-Fenster gefallen', date('Y-m-d', bufFirst(100)['t']) === '2026-08-10', 'erstes ' . date('Y-m-d', bufFirst(100)['t']));
check('Sonnenuntergangs-Anker („ss") liegt in jedem Ereignis', isset(bufFirst(104)['ss']));
$before = bufCount(100);
clock('2026-09-06 23:40:00'); fire($m, 100, false);
check('Schreibvorgang ohne Wertänderung wird nicht gelernt', bufCount(100) === $before);
clock('2026-09-01 19:00:00'); fire($m, 103, 30);
clock('2026-09-01 19:00:04'); fire($m, 103, 60);
clock('2026-09-01 19:00:09'); fire($m, 103, 100);
clock('2026-09-01 20:00:00'); fire($m, 103, 0);
check('Dimmer-Schieber (3 Werte in 9 s) = EIN Schaltvorgang mit Endwert 100, dann Aus → 2 Ereignisse', bufCount(103) === 2 && bufFirst(103)['v'] === 100, 'ist ' . bufCount(103) . ' / ' . json_encode(bufFirst(103)));
clock('2026-09-01 20:00:05'); fire($m, 103, 100);
clock('2026-09-01 20:00:08'); fire($m, 103, 0);
check('Hin und zurück binnen Sekunden = nichts passiert (bleibt bei 2)', bufCount(103) === 2, 'ist ' . bufCount(103));

heading('3. Start am Montag 07.09. um 17:00 — Plan, Einschwingen');
$m->unit = 0.5; $m->index = 0;
clock('2026-09-07 17:00:00');
$GLOBALS['VARS'][10444]['value'] = ts('2026-09-08 19:55:00'); // Location Control zeigt schon auf MORGEN
$startIdx = count($GLOBALS['ACTIONS']);
$r = $m->SetActive(true);
check('SetActive(true) meldet ▶️ mit Vorbild-Zeile je Kanal', str_starts_with($r, '▶️') && str_contains($r, 'Wohnzimmer') && str_contains($r, 'Vorbild'));
check('Timer läuft minütlich', $m->timers['Tick'] === 60000);
$plan = json_decode($m->attrs['ReplayPlan'], true);
check('Plan für heute angelegt', ($plan['day'] ?? '') === '2026-09-07');
check('Gemeinsamer Vorbild-Tag: Wohnzimmer und Küche = Mo 10.08.', ($plan['channels'][100]['refDate'] ?? '') === '2026-08-10' && ($plan['channels'][101]['refDate'] ?? '') === '2026-08-10', json_encode($plan['channels'] ?? []));
check('Dimmer (erst ab 01.09. gelernt) bekommt eigenen Vorbild-Tag 01.09.', ($plan['channels'][103]['refDate'] ?? '') === '2026-09-01', $plan['channels'][103]['refDate'] ?? '-');
check('Versatz Wohnzimmer +5 min, Küche +2,5 min (gleicher Faktor 0,5)', ($plan['channels'][100]['jitterSec'] ?? 0) === 300 && ($plan['channels'][101]['jitterSec'] ?? 0) === 150);
check('Probe (nur lernen) steht NICHT im Plan', !isset($plan['channels'][102]));
check('Einschwingen um 17:00: nichts fällig, keine Schaltung', count(actionsSince(ts('2026-09-07 17:00:00'))) === 0, json_encode(actionsSince(ts('2026-09-07 17:00:00'))));
check('NextAction zeigt die nächste Schaltung 18:05 Wohnzimmer → An', str_contains($m->GetValue('NextAction'), '18:05') && str_contains($m->GetValue('NextAction'), 'Wohnzimmer'), $m->GetValue('NextAction'));
$b = bufCount(100);
fire($m, 100, true);
check('Während der Simulation wird NICHT gelernt', bufCount(100) === $b);
$GLOBALS['VARS'][100]['value'] = false;

heading('4. Minütliche Wiedergabe bis Mitternacht');
for ($t = ts('2026-09-07 17:01:00'); $t <= ts('2026-09-07 23:59:00'); $t += 60) {
    $GLOBALS['CLOCK'] = $t;
    $m->Tick();
}
$acts = array_map(fn($a) => date('H:i', $a[0]) . ' #' . $a[1] . '=' . var_export($a[2], true) . ' ' . $a[3], actionsSince(ts('2026-09-07 17:00:00')));
// Dimmer: koaleszierte Ereignisse tragen den Zeitstempel des LETZTEN Werts
// (19:00:09 / 20:00:08) → fällig beim nächsten vollen Minuten-Tick (19:01/20:01).
// Außenlicht (Sonnenuntergang): ALLE Ereignisse relativ zum Sonnenstand — am
// Vorbild-Tag Untergang 20:30, An 21:00 (+30 min), Aus 23:00 (+2:30 h); heute
// Untergang 19:55 → An 20:25, Aus 22:25, je +5 min Versatz.
$expected = ['18:05 #100=true RequestAction', '18:13 #101=true RequestAction', '19:03 #101=false RequestAction', '19:01 #103=100 SetValue', '20:01 #103=0 SetValue', '20:30 #104=true RequestAction', '22:30 #104=false RequestAction', '23:35 #100=false RequestAction'];
sort($acts); $exp = $expected; sort($exp);
check('Schaltfolge exakt wie erwartet (Uhrzeit + Versatz, Sonnenuntergangs-Abstand, Dimmer per SetValue ohne Aktion)', $acts === $exp, "\n      ist:  " . implode(' | ', $acts) . "\n      soll: " . implode(' | ', $exp));
check('Jede Schaltung genau einmal', count($acts) === count($expected));
check('LastAction nennt die letzte Schaltung mit Vorbild-Tag', str_contains($m->GetValue('LastAction'), 'Wohnzimmer → Aus') && str_contains($m->GetValue('LastAction'), 'Vorbild Mo 10.08.'), $m->GetValue('LastAction'));
check('NextAction: heute nichts mehr', str_contains($m->GetValue('NextAction'), 'keine weitere'), $m->GetValue('NextAction'));
check('Kein Fehler-Log', count($GLOBALS['LOG']) === 0, implode(' | ', $GLOBALS['LOG']));

heading('5. Tageswechsel — neuer Vorbild-Tag (Dienstag), Einschwingen');
$GLOBALS['VARS'][10444]['value'] = ts('2026-09-08 19:53:00');
clock('2026-09-08 00:00:00'); $m->Tick();
$plan = json_decode($m->attrs['ReplayPlan'], true);
check('Plan für 08.09. mit Vorbild Di 11.08.', ($plan['day'] ?? '') === '2026-09-08' && ($plan['channels'][100]['refDate'] ?? '') === '2026-08-11', json_encode($plan['channels'][100] ?? []));
check('Einschwingen um Mitternacht: Wohnzimmer war um 0:00 am Vorbild aus → keine Schaltung nötig (Ist = Aus)', count(actionsSince(ts('2026-09-08 00:00:00'))) === 0, json_encode(actionsSince(ts('2026-09-08 00:00:00'))));
$GLOBALS['VARS'][100]['value'] = true; // jemand hat es „von Hand" angelassen
clock('2026-09-08 00:01:00'); $m->attrs['ReplayPlan'] = '{}'; $m->Tick();
$a = actionsSince(ts('2026-09-08 00:01:00'));
check('Einschwingen stellt den Vorbild-Zustand her: Wohnzimmer An → Aus', count($a) === 1 && $a[0][1] === 100 && $a[0][2] === false, json_encode($a));

heading('6. Beenden per RequestAction(Active=false)');
$m->RequestAction('Active', false);
check('Timer aus, Plan geleert, NextAction leer', $m->timers['Tick'] === 0 && $m->attrs['ReplayPlan'] === '{}' && $m->GetValue('NextAction') === '');
check('SetActive(false) im Aus-Zustand: ℹ️', str_starts_with($m->SetActive(false), 'ℹ️'));
clock('2026-09-08 07:00:00'); fire($m, 100, false); fire($m, 100, true);
$bid = IPS_GetObjectIDByIdent('buf_100', 12345); $buf = json_decode(GetValueString($bid), true); $lastEv = end($buf);
check('Lernen läuft wieder: letztes Ereignis = An um 07:00 (das Alt-„Aus" wurde dedupliziert)', $lastEv['v'] === true && $lastEv['t'] === ts('2026-09-08 07:00:00'), json_encode($lastEv));
check('Lernfenster rollt: ältestes Ereignis jetzt vom 11.08. (10.08. herausgefallen)', date('Y-m-d', bufFirst(100)['t']) === '2026-08-11', date('Y-m-d', bufFirst(100)['t']));
$GLOBALS['VARS'][100]['value'] = false;

heading('7. Auslöser-Variable');
clock('2026-09-08 08:00:00');
fire($m, 200, true);
check('Auslöser TRUE → Simulation an', $m->GetValue('Active') === true && $m->timers['Tick'] === 60000);
fire($m, 200, false);
check('Auslöser FALSE → Simulation aus', $m->GetValue('Active') === false);
$m->props['TriggerInvert'] = true; $m->ApplyChanges();
fire($m, 200, false);
check('Invertiert: FALSE („Anwesend"=nein) → Simulation an', $m->GetValue('Active') === true);
fire($m, 200, true);
check('Invertiert: TRUE → aus', $m->GetValue('Active') === false);
$m->props['TriggerInvert'] = false; $m->ApplyChanges();

heading('8. Vorschau, Vergessen, Kanal ohne Daten');
$m->index = 2;
$p = $m->Preview();
check('Preview bei ausgeschalteter Simulation: probeweise, mit Kanalnamen und Zeiten', str_contains($p, 'probeweise') && str_contains($p, 'Wohnzimmer') && preg_match('/\d\d:\d\d An/', $p) === 1, $p);
check('Preview verändert den gespeicherten Plan nicht', $m->attrs['ReplayPlan'] === '{}');
check('ForgetChannel(unbekannt) → ⚠️', str_starts_with($m->ForgetChannel('Gibtsnicht'), '⚠️'));
check('ForgetChannel(Probe) leert den Speicher', str_starts_with($m->ForgetChannel('Probe'), '🗑') && bufCount(102) === 0);
$m->props['Channels'] = json_encode([['Caption' => 'Leer', 'TargetID' => 102, 'Mode' => 1, 'TimeRef' => 0, 'JitterMin' => 5]]);
$m->ApplyChanges();
clock('2026-09-08 09:00:00');
$r = $m->SetActive(true);
check('Start mit Kanal ohne Gelerntes: startet, meldet „bleibt still"', str_contains($r, 'bleibt still'), $r);
$m->Tick();
check('… und schaltet nichts', count(actionsSince(ts('2026-09-08 09:00:00'))) === 0);
$m->RequestAction('Active', false);
$m->props['Channels'] = json_encode([]);
$m->ApplyChanges();
check('Ohne Kanäle: Status 104, verwaiste Lernspeicher gelöscht', $m->status === 104 && IPS_GetObjectIDByIdent('buf_100', 12345) === false);
check('SetActive(true) ohne abspielbare Kanäle → ⚠️', str_starts_with($m->SetActive(true), '⚠️'));
$m->props['Channels'] = json_encode($channels);
$m->ApplyChanges();

heading('9. Resync (Attribut-Verlust) — Gelerntes bleibt, Simulation läuft weiter');
clock('2026-09-08 10:00:00'); fire($m, 101, true);
clock('2026-09-08 11:00:00'); fire($m, 101, false);
$b = bufCount(101);
$m->RequestAction('Active', true);
$m->simulateResync();
check('Lernspeicher überlebt den Resync (Variable, kein Attribut)', bufCount(101) === $b);
check('Aktive Simulation: Timer nach Resync wieder an, nächster Tick baut neuen Plan', $m->timers['Tick'] === 60000);
clock('2026-09-08 10:01:00'); $m->Tick();
check('Neuer Plan nach Resync', (json_decode($m->attrs['ReplayPlan'], true)['day'] ?? '') === '2026-09-08');
$m->RequestAction('Active', false);

heading('10. Formular — Konvention und Verdrahtung');
$f = form($m);
check('Zweck-Intro ganz vorn', ($f['elements'][0]['name'] ?? '') === 'PurposeIntroPanel');
check('News-Panel an zweiter Stelle', ($f['elements'][1]['name'] ?? '') === 'NewsPanel');
check('Doku-Panel an dritter Stelle', str_contains($f['elements'][2]['caption'] ?? '', 'Dokumentation'));
check('Simulation-Panel, dann Kanäle', str_contains($f['elements'][3]['caption'] ?? '', 'Simulation') && str_contains($f['elements'][4]['caption'] ?? '', 'Kanäle'));
check('Lizenz-Panel ganz unten', str_contains(end($f['elements'])['caption'] ?? '', 'Über dieses Modul'));
check('Status-Codes 102/104 beschriftet', count($f['status'] ?? []) === 2);
$txt = formText($f);
check('Astro-Status sichtbar (✅, da Location Control im Test vorhanden)', str_contains($txt, 'Sonnenauf-/-untergang verfügbar'));
check('Kanal-Zusammenfassung nennt Schaltvorgänge', str_contains($txt, 'Schaltvorgänge seit'));
check('Kanalliste hat genau eine auto-Spalte (Zielvariable)', substr_count($txt, '"width":"auto"') === 1);
check('Kein Link-Button trägt die URL direkt in "link"', !preg_match('/"link":"http/', $txt));
$m->AckPurposeIntro(); $m->AckNews(); $m->AckForumHint();
$f2 = form($m);
check('Nach Bestätigen: Intro/News/Forum weg, Lizenz bleibt', count($f2['elements']) === count($f['elements']) - 3 && str_contains(end($f2['elements'])['caption'], 'Über dieses Modul'));
$GLOBALS['LOC'] = [];
check('Ohne Location Control: ℹ️-Rückfall-Hinweis statt ✅', str_contains(formText(form($m)), 'Keine Location-Control-Instanz'));
$GLOBALS['LOC'] = [10443 => true];

heading('11. Vertrags-Hygiene (SUITE.md Stolperstein 8/20) und onClick-Verdrahtung');
$rc = new ReflectionClass(Schein::class);
foreach (['SetActive', 'Preview', 'ForgetChannel', 'Tick', 'AckPurposeIntro', 'AckNews', 'AckForumHint'] as $name) {
    $rm = $rc->getMethod($name);
    $okTypes = true; $okDefaults = true;
    foreach ($rm->getParameters() as $p) {
        $t = $p->getType();
        if ($t === null || !in_array($t->getName(), ['bool', 'int', 'float', 'string'], true)) { $okTypes = false; }
        if ($p->isOptional()) { $okDefaults = false; }
    }
    check("$name(): Parameter skalar typisiert, keine PHP-Standardwerte", $okTypes && $okDefaults);
}
preg_match_all('/SCHEIN_([A-Za-z]+)\(/', $txt, $mm);
foreach (array_unique($mm[1]) as $fn) {
    check("SCHEIN_$fn → public function $fn() existiert", $rc->hasMethod($fn) && $rc->getMethod($fn)->isPublic());
}
check('Kern-Aktionen sind im Formular verdrahtet', count(array_diff(['SetActive', 'Preview'], array_unique($mm[1]))) === 0);

echo "\n" . str_repeat('-', 62) . "\n";
if ($fails === 0) {
    echo "OK — alle Prüfungen bestanden.\n";
    exit(0);
}
echo "FEHLER — $fails Prüfung(en) fehlgeschlagen.\n";
exit(1);

<?php
declare(strict_types=1);

/** Integration regression: automatic browser activation, pacing and shared worker safety. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../public_html/includes/schema.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function activation_expect(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL: '.$label);
    echo 'PASS: '.$label.PHP_EOL;
}

function activation_state(mysqli $db): array {
    return [
        'worker' => dn_row($db, "SELECT * FROM nxt_worker_state WHERE worker_key='tamers'"),
        'bots' => hash('sha256', json_encode(dn_rows($db, 'SELECT * FROM nxt_bots ORDER BY bot_no'), JSON_THROW_ON_ERROR)),
        'activity' => (int)dn_row($db, 'SELECT COUNT(*) n FROM nxt_bot_activity')['n'],
        'matches' => (int)dn_row($db, 'SELECT COUNT(*) n FROM nxt_ranked_matches')['n'],
    ];
}

function activation_browser_tick(mysqli $db): array {
    // These are the fixed browser endpoint limits, with no CLI worker running.
    $result = dn_bots_tick($db, 40, 5, 1.5);
    if ($result['errors']) throw new RuntimeException('Bot action failed: '.implode(' | ', $result['errors']));
    return $result;
}

$cfg = pv_config('db');
$db = new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], '', (int)$cfg['port']);
$db->set_charset('utf8mb4');
$name = 'nxt_activation_test_'.bin2hex(random_bytes(5));
$db->query('CREATE DATABASE `'.$name.'`');
$db->select_db($name);
$GLOBALS['mysql_connection'] = $db;

try {
    dn_apply_schema_migrations($db);
    activation_expect((int)dn_row($db, 'SELECT COUNT(*) n FROM nxt_bots')['n'] === 1000,
        'fresh setup creates all 1000 persistent tamers');
    activation_expect((int)dn_row($db, 'SELECT COUNT(*) n FROM digimon_instances')['n'] === 3000,
        'fresh tamers own three genuine starter partners each');
    activation_expect((int)dn_row($db, 'SELECT COUNT(*) n FROM nxt_bot_activity')['n'] === 0,
        'fresh setup does not manufacture activity or wins');

    // Limit this first sweep to 40 due tamers, while all 1000 remain eligible defenders.
    dn_write($db, 'UPDATE nxt_bots SET next_action_at=?', [time()+86400]);
    dn_write($db, 'UPDATE nxt_bots SET next_action_at=0 WHERE bot_no<=40');
    $first = activation_browser_tick($db);
    activation_expect(!$first['skipped'] && $first['processed'] > 0 && $first['processed'] <= 40,
        'the browser-sized tick starts real work without a manual worker');
    $before = activation_state($db);
    $immediate = activation_browser_tick($db);
    activation_expect($immediate['skipped'] && $immediate['processed'] === 0,
        'an immediate second browser pulse respects the shared five-second interval');
    activation_expect($before === activation_state($db),
        'a throttled pulse preserves last_run, cursor, bot steps and recorded actions');

    $calls = 1;
    while ((int)dn_row($db, 'SELECT COUNT(*) n FROM nxt_bots WHERE bot_no<=40 AND activity_step=1')['n'] < 40) {
        if (++$calls > 40) throw new RuntimeException('First 40 tamers did not finish within 40 bounded pulses.');
        // Advance the persisted clock instead of sleeping; action due times remain real.
        dn_write($db, "UPDATE nxt_worker_state SET last_run=? WHERE worker_key='tamers'", [time()-6]);
        $result = activation_browser_tick($db);
        activation_expect(!$result['skipped'] && $result['processed'] > 0,
            'next eligible browser pulse continues the fair sweep (pulse '.$calls.')');
    }
    activation_expect((int)dn_row($db, 'SELECT SUM(activity_step) n FROM nxt_bots')['n'] === 40,
        'first sweep advances each of 40 due tamers exactly once');
    activation_expect((int)dn_row($db, "SELECT cursor_no FROM nxt_worker_state WHERE worker_key='tamers'")['cursor_no'] === 40,
        'cursor reaches the final processed tamer without skipping due accounts');

    $actions = [];
    foreach (dn_rows($db, 'SELECT action,COUNT(*) n FROM nxt_bot_activity GROUP BY action') as $row) {
        $actions[$row['action']] = (int)$row['n'];
    }
    activation_expect(($actions['wild'] ?? 0) > 0 && ($actions['ranked'] ?? 0) === 8 && ($actions['party'] ?? 0) === 4,
        'staggered first actions include wild combat, eight ranked matches and four party changes');
    $outcomes = dn_row($db, 'SELECT SUM(wild_wins) wins,SUM(wild_losses) losses FROM nxt_bots');
    activation_expect((int)$outcomes['wins'] > 0 && (int)$outcomes['losses'] > 0,
        'first browser-driven sweep produces genuine wild wins and losses');
    activation_expect((int)dn_row($db, "SELECT COUNT(*) n FROM battle_log WHERE battle_type='wild'")['n'] === $actions['wild'],
        'every displayed wild action has a persisted combat history record');
    foreach (dn_rows($db, "SELECT owner_id,detail_json FROM nxt_bot_activity WHERE action='wild'") as $row) {
        $detail = json_decode($row['detail_json'], true, 512, JSON_THROW_ON_ERROR);
        if (empty($detail['log']) || (int)$detail['rounds'] < 1 || (int)$detail['scan'] < 1) {
            throw new RuntimeException('Wild activity is missing simulated turns or earned scan data.');
        }
        $scan = dn_scan_row($db, (int)$row['owner_id'], (int)$detail['species_id']);
        if ((int)$scan['scan_percent'] !== (int)$detail['scan']) {
            throw new RuntimeException('Recorded encounter scan is not present on the actual bot account.');
        }
    }
    activation_expect(true, 'wild reports contain combat turns and retained scan on the actual bot accounts');
    $matches = dn_rows($db, 'SELECT m.*,p.energy FROM nxt_ranked_matches m JOIN nxt_ranked_profiles p ON p.owner_id=m.attacker_id');
    activation_expect(count($matches) === 8, 'ranked feed events correspond to eight persisted real matches');
    foreach ($matches as $match) {
        $combat = json_decode($match['battle_json'], true, 512, JSON_THROW_ON_ERROR);
        if ((int)$match['energy'] !== 4 || (int)($combat['actions'] ?? 0) < 1
            || count(json_decode($match['attacker_json'], true, 512, JSON_THROW_ON_ERROR)) !== 3
            || count(json_decode($match['defender_json'], true, 512, JSON_THROW_ON_ERROR)) !== 3) {
            throw new RuntimeException('Ranked match is missing team combat or its energy debit.');
        }
    }
    activation_expect(true, 'browser-triggered ranked matches simulate both teams and spend one real energy');
    activation_expect((int)dn_row($db, 'SELECT COUNT(*) n FROM digimon_instances d JOIN digimon_party p ON p.digimon_id=d.id WHERE d.hp<>d.max_hp OR d.sp<>d.max_sp')['n'] === 0,
        'every active bot partner returns to full HP and SP');

    // One tiny time budget still completes an atomic action, then yields before the next.
    dn_write($db, 'UPDATE nxt_bots SET next_action_at=0 WHERE bot_no IN (41,42)');
    $tiny = dn_bots_tick($db, 40, 0, 0.000000001);
    activation_expect(!$tiny['skipped'] && $tiny['processed'] === 1 && !$tiny['errors'],
        'a tiny soft budget completes one action and yields between tamers');
    activation_expect((int)$tiny['cursor'] === 41
        && (int)dn_row($db, 'SELECT activity_step FROM nxt_bots WHERE bot_no=41')['activity_step'] === 1
        && (int)dn_row($db, 'SELECT activity_step FROM nxt_bots WHERE bot_no=42')['activity_step'] === 0,
        'budget exhaustion leaves the next due bot untouched and cursor at the last completed bot');
    $continued = dn_bots_tick($db, 40, 0, 0.000000001);
    activation_expect($continued['processed'] === 1 && (int)$continued['cursor'] === 42 && !$continued['errors']
        && (int)dn_row($db, 'SELECT activity_step FROM nxt_bots WHERE bot_no=42')['activity_step'] === 1,
        'the next bounded tick resumes at the unprocessed bot');

    // A CLI worker and browser pulses share the exact same database advisory lock.
    $lock = 'dn_bot_tick_'.substr(hash('sha256', $name), 0, 30);
    $other = new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $name, (int)$cfg['port']);
    try {
        activation_expect((int)dn_row($other, 'SELECT GET_LOCK(?,0) ok', [$lock])['ok'] === 1,
            'a separate worker connection can hold the shared bot lock');
        $before = activation_state($db);
        $locked = dn_bots_tick($db, 40, 0, 1.5);
        activation_expect($locked['skipped'] && $locked['processed'] === 0 && $before === activation_state($db),
            'browser activation during another worker cannot duplicate actions or move the cursor');
    } finally {
        dn_row($other, 'SELECT RELEASE_LOCK(?) ok', [$lock]);
        $other->close();
    }

    $before = activation_state($db);
    $partners = dn_rows($db, 'SELECT * FROM digimon_instances ORDER BY id');
    $parties = dn_rows($db, 'SELECT * FROM digimon_party ORDER BY owner_id,slot');
    activation_expect(dn_seed_bots($db, 1000) === 0 && $before === activation_state($db)
        && $partners === dn_rows($db, 'SELECT * FROM digimon_instances ORDER BY id')
        && $parties === dn_rows($db, 'SELECT * FROM digimon_party ORDER BY owner_id,slot'),
        'repeat bot initialization preserves activity, partners, progress and formations');

    echo 'First-sweep actions: '.json_encode($actions, JSON_THROW_ON_ERROR).PHP_EOL;
    echo 'First-sweep wild outcomes: '.json_encode($outcomes, JSON_THROW_ON_ERROR).PHP_EOL;
    echo 'All browser bot activation regression checks passed.'.PHP_EOL;
} finally {
    $db->query('DROP DATABASE `'.$name.'`');
    $db->close();
}

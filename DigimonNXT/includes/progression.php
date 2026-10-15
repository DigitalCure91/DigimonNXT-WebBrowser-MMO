<?php
declare(strict_types=1);

const DN_ABI_MAX = 200;
const DN_WILD_MAX_LEVEL = 24;

/** Cyber Sleuth / Hacker's Memory ordinary stage ABI gains. */
function dn_abi_gain(string $fromStage, string $toStage, int $level, string $direction): int {
    $level = max(1, min(99, $level));
    $special = [
        'Rookie:Armor'=>[2,0,4], 'Champion:Armor'=>[0,0,1],
        'Champion:Mega'=>[4,0,9], 'Rookie:Ultimate'=>[3,5,7],
        'Ultimate:Ultra'=>[5,0,11], 'Rookie:Mega'=>[6,0,12],
    ];
    $key = $direction === 'down' ? $toStage.':'.$fromStage : $fromStage.':'.$toStage;
    if (isset($special[$key])) {
        [$base, $offset, $back] = $special[$key];
        return $direction === 'down' ? $back + intdiv($level,5) : $base + intdiv($level+$offset,10);
    }
    $stages = ['Baby'=>0, 'In-Training'=>1, 'Rookie'=>2, 'Champion'=>3, 'Ultimate'=>4, 'Mega'=>5, 'Ultra'=>6];
    if (!isset($stages[$fromStage], $stages[$toStage])) {
        throw new RuntimeException('This evolution stage needs an explicit ABI rule.');
    }
    $from = $stages[$fromStage]; $to = $stages[$toStage];
    $level = max(1, min(99, $level));
    if ($direction === 'down') {
        if ($to > $from) throw new RuntimeException('Invalid de-digivolution direction.');
        $base = 0;
        for ($stage = $from; $stage > $to; $stage--) $base += $stage;
        return $base + intdiv($level, 5);
    }
    if ($direction !== 'up' || $to < $from) throw new RuntimeException('Invalid digivolution direction.');
    if ($to === $from) return intdiv($level, 10);
    // Adjacent stage formula: floor(stage/2) + floor((level + odd offset)/10).
    // The offset is 5 for Baby, Rookie and Ultimate; 0 for the other stages.
    if ($to === $from + 1) return intdiv($from + 1, 2) + intdiv($level + ($from % 2 === 0 ? 5 : 0), 10);
    throw new RuntimeException('This stage relationship needs an explicit ABI rule.');
}

function dn_conversion_abi(int $scan): int {
    return min(5, max(0, intdiv(min(200, $scan) - 100, 20)));
}

function dn_wild_level_bounds(int $min, int $max): array {
    $min = max(1, min(DN_WILD_MAX_LEVEL - 1, $min));
    $max = max($min + 1, min(DN_WILD_MAX_LEVEL, $max));
    return [$min, $max];
}

/** Serialize gameplay mutations across browsers and logins for the same owner. */
function dn_lock_owner(mysqli $db, int $uid): array {
    $stmt = $db->prepare('SELECT id,money FROM members WHERE id=? FOR UPDATE');
    $stmt->bind_param('i', $uid); $stmt->execute();
    $owner = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$owner) throw new RuntimeException('Tamer account is unavailable.');
    return $owner;
}

function dn_active_battle(mysqli $db, int $uid): ?array {
    $stmt = $db->prepare('SELECT state_json FROM digimon_battle_state WHERE owner_id=?');
    $stmt->bind_param('i', $uid); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$row) return null;
    $state = json_decode((string)$row['state_json'], true, 512, JSON_THROW_ON_ERROR);
    return is_array($state) ? $state : null;
}

function dn_require_idle(mysqli $db, int $uid): void {
    $battle = dn_active_battle($db, $uid);
    if ($battle && empty($battle['finished'])) {
        throw new RuntimeException('Finish or disconnect from your wild battle first. Use a capsule from the battle menu to heal during combat.');
    }
}

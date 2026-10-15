<?php
declare(strict_types=1);
require_once __DIR__.'/ranked.php';
/** Compatibility for older integrations. All settlements use real team combat. */
function dn_arena_opponents(): array {
    return [['key'=>'battle-park','name'=>'Battle Park matchmaking','team'=>[],'rating'=>0,'reward_bits'=>0]];
}
function dn_arena_opponent(string $key): ?array {return $key==='battle-park'?dn_arena_opponents()[0]:null;}
function dn_arena_progress(mysqli $db,int $uid): array {
    $p=dn_ranked_profile($db,$uid);return $p+['rating'=>$p['points'],'updated_at'=>time()];
}
function dn_arena_challenge(mysqli $db,int $uid,string $key): array {
    dn_require_idle($db,$uid);
    if($key!=='battle-park')throw new RuntimeException('The old arena has moved to Battle Park. Find a rival on the Battle Park screen.');
    $ticket=dn_ranked_ticket($db,$uid);if(!$ticket)throw new RuntimeException('No rival is currently available.');
    $result=dn_ranked_challenge($db,$uid,(string)$ticket['token']);
    return $result+['delta'=>abs($result['after']-$result['before']),'team'=>$result['opponent']['team']??[]];
}

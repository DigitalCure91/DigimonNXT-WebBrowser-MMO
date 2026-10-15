<?php
declare(strict_types=1);

function dn_catalog(): array {
    static $rows;
    if ($rows === null) {
        $rows = [];
        foreach (json_decode((string)file_get_contents(dirname(__DIR__).'/config/digimon_species.json'), true, 512, JSON_THROW_ON_ERROR) as $row) $rows[$row['slug']] = $row;
    }
    return $rows;
}

function dn_species_meta(string $slug): array { return dn_catalog()[$slug] ?? []; }

function dn_is_paradox(array|string $species): bool {
    if(is_string($species))$species=['slug'=>$species];
    return (dn_species_meta((string)($species['slug'] ?? ''))['variant'] ?? '') === 'paradox';
}

function dn_moves_catalog(): array {
    static $moves;
    if ($moves === null) $moves = json_decode((string)file_get_contents(dirname(__DIR__).'/config/digimon_moves.json'), true, 512, JSON_THROW_ON_ERROR);
    return $moves;
}

function dn_species_moves(string $slug, int $level): array {
    $moves = dn_moves_catalog(); $result = [];
    foreach (dn_species_meta($slug)['learnset'] ?? [] as $entry) {
        if ((int)$entry['level'] <= $level && isset($moves[$entry['move']])) $result[$entry['move']] = $moves[$entry['move']];
    }
    return $result;
}

function dn_learn_moves(mysqli $db, int $id, string $slug, int $level): void {
    $stmt = $db->prepare('INSERT IGNORE INTO digimon_learned_moves(digimon_id,move_slug) VALUES(?,?)');
    foreach (dn_species_moves($slug, $level) as $key=>$move) {
        if (empty($move['inheritable'])) continue;
        $stmt->bind_param('is', $id, $key); $stmt->execute();
    }
    $stmt->close();
}

function dn_instance_moves(mysqli $db, array $instance): array {
    $available = dn_species_moves((string)$instance['slug'], (int)$instance['level']);
    $catalog = dn_moves_catalog(); $id = (int)$instance['id'];
    $stmt = $db->prepare('SELECT move_slug FROM digimon_learned_moves WHERE digimon_id=?');
    $stmt->bind_param('i', $id); $stmt->execute(); $rows = $stmt->get_result();
    while ($row = $rows->fetch_assoc()) {
        $key = (string)$row['move_slug'];
        if (!empty($catalog[$key]['inheritable'])) $available[$key] = $catalog[$key];
    }
    $stmt->close();
    return $available;
}

function dn_move_summary(array $move): string {
    $parts = [$move['type'], $move['element']];
    if (!empty($move['power'])) $parts[] = 'Power '.$move['power'];
    if (!empty($move['fixed'])) $parts[] = $move['fixed'].' fixed damage';
    if (!empty($move['pierce'])) $parts[] = strtoupper($move['pierce']).' piercing';
    if (!empty($move['hits']) && $move['hits'][1] > 1) $parts[] = implode('–', $move['hits']).' hits';
    foreach ($move['effects'] ?? [] as $effect) {
        $parts[] = ucwords(str_replace('_', ' ', $effect['kind'])).(!empty($effect['stat']) ? ' '.strtoupper($effect['stat']) : '');
    }
    return implode(' · ', $parts);
}

<?php
declare(strict_types=1);

function dn_sql(mysqli $db, string $sql, array $params=[]): mysqli_stmt {
    $stmt=$db->prepare($sql);
    if ($params) {
        $types=''; foreach ($params as $p) $types.=is_int($p)?'i':(is_float($p)?'d':'s');
        $stmt->bind_param($types,...$params);
    }
    $stmt->execute(); return $stmt;
}
function dn_row(mysqli $db,string $sql,array $params=[]): ?array {
    $stmt=dn_sql($db,$sql,$params);$row=$stmt->get_result()->fetch_assoc();$stmt->close();return $row?:null;
}
function dn_rows(mysqli $db,string $sql,array $params=[]): array {
    $stmt=dn_sql($db,$sql,$params);$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();return $rows;
}
function dn_write(mysqli $db,string $sql,array $params=[]): int {
    $stmt=dn_sql($db,$sql,$params);$n=$stmt->affected_rows;$stmt->close();return $n;
}

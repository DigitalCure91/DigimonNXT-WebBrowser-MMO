<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/digimon.php';
require_once __DIR__.'/community.php';

function dn_item_catalog(mysqli $db): array {$rows=[];$r=$db->query('SELECT * FROM items WHERE is_active=1 ORDER BY category,name');if($r)while($x=$r->fetch_assoc())$rows[]=$x;return$rows;}
function dn_inventory(mysqli $db,int $uid): array {$stmt=$db->prepare('SELECT i.*,COALESCE(mi.quantity,0) quantity FROM items i LEFT JOIN member_items mi ON mi.item_id=i.id AND mi.owner_id=? WHERE i.is_active=1 ORDER BY i.category,i.name');if(!$stmt)return[];$stmt->bind_param('i',$uid);$stmt->execute();$r=$stmt->get_result();$rows=[];while($x=$r->fetch_assoc())$rows[]=$x;$stmt->close();return$rows;}
function dn_add_item(mysqli $db,int $uid,int $itemId,int $qty): void {if($qty<=0)return;$stmt=$db->prepare('INSERT INTO member_items(owner_id,item_id,quantity) VALUES(?,?,?) ON DUPLICATE KEY UPDATE quantity=quantity+VALUES(quantity)');if(!$stmt)throw new RuntimeException('Inventory update unavailable.');$stmt->bind_param('iii',$uid,$itemId,$qty);if(!$stmt->execute())throw new RuntimeException($stmt->error);$stmt->close();}
function dn_recovery_catalog(): array {
    static $catalog; if($catalog===null)$catalog=json_decode((string)file_get_contents(dirname(__DIR__).'/config/recovery_items.json'),true,512,JSON_THROW_ON_ERROR);return $catalog;
}
function dn_receipt(mysqli $db,int $uid,string $token): ?string {
    if(!preg_match('/^[a-f0-9]{48}$/D',$token))throw new RuntimeException('The item form expired. Reload the page and try again.');
    $r=dn_row($db,'SELECT result_text FROM digimon_action_receipts WHERE owner_id=? AND token=?',[$uid,$token]);return $r['result_text']??null;
}
function dn_save_receipt(mysqli $db,int $uid,string $token,string $message): void {
    dn_write($db,'INSERT INTO digimon_action_receipts(owner_id,token,result_text,created_at) VALUES(?,?,?,?)',[$uid,$token,$message,time()]);
}
function dn_buy_item(mysqli $db,int $uid,int $itemId,int $qty=1,string $token=''): string {
    if($qty<1||$qty>99)throw new RuntimeException('Choose a quantity from 1 to 99.');
    if($token==='')$token=bin2hex(random_bytes(24));
    $db->begin_transaction();try {
        $owner=dn_lock_owner($db,$uid);$prior=dn_receipt($db,$uid,$token);if($prior!==null){$db->commit();return $prior;}
        $item=dn_row($db,'SELECT * FROM items WHERE id=? AND is_active=1 FOR UPDATE',[$itemId]);
        if(!$item||!isset(dn_recovery_catalog()[$item['slug']]))throw new RuntimeException('That item is not available in the recovery shop.');
        $owned=dn_row($db,'SELECT quantity FROM member_items WHERE owner_id=? AND item_id=? FOR UPDATE',[$uid,$itemId]);$quantity=(int)($owned['quantity']??0);
        $cost=(int)$item['price']*$qty;if((int)$owner['money']<$cost)throw new RuntimeException('Not enough Bits for this purchase.');
        if($quantity+$qty>(int)$item['stack_limit'])throw new RuntimeException('You can hold at most '.$item['stack_limit'].' of this capsule.');
        dn_write($db,'UPDATE members SET money=money-? WHERE id=? AND money>=?',[$cost,$uid,$cost]);
        dn_add_item($db,$uid,$itemId,$qty);$message='Purchased '.$item['name'].' ×'.$qty.' for '.number_format($cost).' Bits.';
        dn_save_receipt($db,$uid,$token,$message);$db->commit();dn_world_game_event($db,$uid,'shop.purchase',$message,['bits'=>-$cost,'quantity'=>$qty]);return $message;
    }catch(Throwable $e){$db->rollback();throw $e;}
}
/** Caller owns the account lock and transaction (also used by battle turns). */
function dn_use_recovery_item_locked(mysqli $db,int $uid,int $itemId,int $digimonId): string {
    $item=dn_row($db,'SELECT i.*,mi.quantity FROM member_items mi JOIN items i ON i.id=mi.item_id WHERE mi.owner_id=? AND mi.item_id=? AND i.is_active=1 FOR UPDATE',[$uid,$itemId]);
    if(!$item||(int)$item['quantity']<1)throw new RuntimeException('You do not own that capsule.');
    $effect=dn_recovery_catalog()[$item['slug']]??null;if(!$effect)throw new RuntimeException('That item cannot be used for recovery.');
    $d=dn_owned_instance($db,$uid,$digimonId,true);if(!$d||(int)$d['locked_trade'])throw new RuntimeException('This Digimon is unavailable for recovery.');
    if((int)$d['hp']<=0)throw new RuntimeException('Capsules heal conscious Digimon. Use the Recovery Terminal or a revival skill for a defeated Digimon.');
    $field=$effect['field'];$before=(int)$d[$field];$after=min((int)$d['max_'.$field],$before+(int)$effect['amount']);$restored=$after-$before;
    if($restored<=0)throw new RuntimeException('That Digimon already has full '.strtoupper($field).'. No capsule was used.');
    if($field==='hp')dn_write($db,'UPDATE digimon_instances SET hp=? WHERE id=? AND owner_id=?',[$after,$digimonId,$uid]);
    else dn_write($db,'UPDATE digimon_instances SET sp=? WHERE id=? AND owner_id=?',[$after,$digimonId,$uid]);
    if(dn_write($db,'UPDATE member_items SET quantity=quantity-1 WHERE owner_id=? AND item_id=? AND quantity>0',[$uid,$itemId])!==1)throw new RuntimeException('Inventory changed before recovery completed.');
    return $item['name'].' restored '.$restored.' '.strtoupper($field).' to '.($d['nickname']?:$d['name']).'.';
}
function dn_use_recovery_item(mysqli $db,int $uid,int $itemId,int $digimonId,string $token=''): string {
    if($token==='')$token=bin2hex(random_bytes(24));$db->begin_transaction();try {
        dn_lock_owner($db,$uid);$prior=dn_receipt($db,$uid,$token);if($prior!==null){$db->commit();return $prior;}
        dn_require_idle($db,$uid);$message=dn_use_recovery_item_locked($db,$uid,$itemId,$digimonId);dn_save_receipt($db,$uid,$token,$message);$db->commit();dn_world_game_event($db,$uid,'item.use','Used a recovery capsule',['digimon_id'=>$digimonId,'quantity'=>1]);return $message;
    }catch(Throwable $e){$db->rollback();throw $e;}
}

function dn_market_list(mysqli $db,int $limit=60): array {$limit=max(1,min(100,$limit));$sql='SELECT t.*,m.username,d.nickname,d.level,s.name species_name,s.stage,s.attribute,s.sprite FROM trade_listings t JOIN members m ON m.id=t.seller_id JOIN digimon_instances d ON d.id=t.digimon_id JOIN digimon_species s ON s.id=d.species_id WHERE t.status="active" ORDER BY t.created_at DESC LIMIT '.$limit;$r=$db->query($sql);$rows=[];if($r)while($x=$r->fetch_assoc())$rows[]=$x;return$rows;}
function dn_create_listing(mysqli $db,int $uid,int $digimonId,int $price): void {
    if($price<1||$price>999999999)throw new RuntimeException('Choose a price between 1 and 999,999,999 Bits.');
    $db->begin_transaction();try {
        dn_lock_owner($db,$uid);dn_require_idle($db,$uid);$d=dn_owned_instance($db,$uid,$digimonId,true);
        if(!$d||(int)$d['locked_trade'])throw new RuntimeException('That Digimon is unavailable for exchange.');
        if(dn_row($db,'SELECT slot FROM digimon_party WHERE owner_id=? AND digimon_id=?',[$uid,$digimonId]))throw new RuntimeException('Remove this Digimon from your party before listing it.');
        dn_write($db,"INSERT INTO trade_listings(seller_id,digimon_id,price,status,created_at) VALUES(?,?,?,'active',?)",[$uid,$digimonId,$price,time()]);
        dn_write($db,'UPDATE digimon_instances SET locked_trade=1 WHERE id=? AND owner_id=?',[$digimonId,$uid]);
        dn_audit($db,$uid,'trade.list',$d['name'].' @ '.$price);$db->commit();dn_world_game_event($db,$uid,'trade.list','Listed '.$d['name'].' for '.$price.' Bits',['digimon_id'=>$digimonId,'bits'=>$price]);
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function dn_cancel_listing(mysqli $db,int $uid,int $listingId): void {
    $db->begin_transaction();try {
        dn_lock_owner($db,$uid);
        $r=dn_row($db,"SELECT digimon_id FROM trade_listings WHERE id=? AND seller_id=? AND status='active' FOR UPDATE",[$listingId,$uid]);
        if(!$r)throw new RuntimeException('Active listing not found.');
        dn_write($db,"UPDATE trade_listings SET status='cancelled',completed_at=? WHERE id=?",[time(),$listingId]);
        dn_write($db,'UPDATE digimon_instances SET locked_trade=0 WHERE id=? AND owner_id=?',[(int)$r['digimon_id'],$uid]);$db->commit();dn_world_game_event($db,$uid,'trade.cancel','Cancelled an exchange listing',['digimon_id'=>(int)$r['digimon_id']]);
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function dn_buy_listing(mysqli $db,int $buyer,int $listingId): void {
    $preview=dn_row($db,"SELECT seller_id FROM trade_listings WHERE id=? AND status='active'",[$listingId]);
    if(!$preview)throw new RuntimeException('Listing is no longer available.');$seller=(int)$preview['seller_id'];
    if($seller===$buyer)throw new RuntimeException('You cannot buy your own listing.');
    $db->begin_transaction();try {
        $owners=[$buyer,$seller];sort($owners);$locked=[];foreach($owners as $id)$locked[$id]=dn_lock_owner($db,$id);
        $t=dn_row($db,"SELECT t.*,d.owner_id,d.species_id,d.locked_trade,s.name FROM trade_listings t JOIN digimon_instances d ON d.id=t.digimon_id JOIN digimon_species s ON s.id=d.species_id WHERE t.id=? AND t.status='active' FOR UPDATE",[$listingId]);
        if(!$t||(int)$t['owner_id']!==$seller||(int)$t['seller_id']!==$seller||!(int)$t['locked_trade'])throw new RuntimeException('Listing is no longer available.');
        $price=(int)$t['price'];if((int)$locked[$buyer]['money']<$price)throw new RuntimeException('Not enough Bits.');
        dn_write($db,'UPDATE members SET money=money-? WHERE id=?',[$price,$buyer]);dn_write($db,'UPDATE members SET money=money+? WHERE id=?',[$price,$seller]);
        dn_write($db,'UPDATE digimon_instances SET owner_id=?,locked_trade=0 WHERE id=? AND owner_id=?',[$buyer,(int)$t['digimon_id'],$seller]);
        dn_write($db,"UPDATE trade_listings SET status='sold',buyer_id=?,completed_at=? WHERE id=?",[$buyer,time(),$listingId]);
        dn_discover_species($db,$buyer,(int)$t['species_id']);
        dn_notify($db,$seller,'Digimon exchange completed',$t['name'].' sold for '.number_format($price).' Bits.','trade.php','trade');
        dn_notify($db,$buyer,'Digimon received',$t['name'].' has been transferred to your collection.','digimon.php','trade');$db->commit();dn_world_game_event($db,$buyer,'trade.purchase','Bought '.$t['name'].' for '.$price.' Bits',['digimon_id'=>(int)$t['digimon_id'],'target_id'=>$seller,'bits'=>-$price]);
    }catch(Throwable $e){$db->rollback();throw $e;}
}

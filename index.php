<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

$db = new PDO('sqlite:' . DB_PATH);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(file_get_contents(__DIR__ . '/schema.sql'));

// Мягкие миграции для уже существующей базы Railway.
foreach ([
    "ALTER TABLE users ADD COLUMN respect INTEGER NOT NULL DEFAULT 0",
    "ALTER TABLE users ADD COLUMN vip_tier INTEGER NOT NULL DEFAULT 0"
] as $sql) {
    try { $db->exec($sql); } catch (Throwable $e) { /* колонка уже есть */ }
}
$db->exec("CREATE TABLE IF NOT EXISTS player_state (user_id INTEGER PRIMARY KEY, casino_game TEXT NOT NULL DEFAULT 'slots', FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");

const VIPS = [
    1 => ['🔪 VIP Уличный', 200, 'Работа +10%\nОграбление +5%'],
    2 => ['🔫 VIP Авторитет', 500, 'Работа +20%\nОграбление +10%\nДоп. ежедневный бонус'],
    3 => ['💰 VIP Криминальный босс', 1000, 'Работа +30%\nОграбление +15%\nДоход магазина +20%'],
    4 => ['👑 VIP Главарь', 2000, 'Работа +45%\nОграбление +20%\nДоход магазина +30%\nXP +10%'],
    5 => ['💎 VIP Легенда', 5500, 'Работа +60%\nОграбление +25%\nДоход магазина +50%\nXP +25%\nОсобый статус 👑']
];

function tg(string $method, array $data = []): array {
    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/' . $method;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$data, CURLOPT_TIMEOUT=>30]);
    $raw = curl_exec($ch); curl_close($ch);
    return json_decode($raw ?: '{}', true) ?: [];
}
function money(int $n): string { return number_format($n,0,'.','.'); }
function xpNeed(int $level): int { return 1000 + (($level - 1) * 250); }
function vip(int $tier): array { return VIPS[$tier] ?? ['',0,'']; }
function vipPercent(int $tier,string $kind): int {
    $map=['work'=>[0,10,20,30,45,60],'rob'=>[0,5,10,15,20,25],'shop'=>[0,0,20,30,50,50],'xp'=>[0,0,0,0,10,25]];
    return $map[$kind][$tier] ?? 0;
}
function vipName(int $tier): string { return $tier>0 ? VIPS[$tier][0] : 'Нет'; }
function applyPercent(int $amount,int $percent): int { return (int)floor($amount*(100+$percent)/100); }

function ensureUser(array $from): void {
    global $db;
    $now=time(); $q=$db->prepare('SELECT id FROM users WHERE id=?'); $q->execute([$from['id']]);
    if (!$q->fetchColumn()) {
        $db->prepare('INSERT INTO users(id,username,first_name,balance,strength,level,xp,registered_at,last_seen_at) VALUES(?,?,?,?,?,?,?,?,?)')
           ->execute([$from['id'],$from['username']??null,$from['first_name']??'',START_BALANCE,START_STRENGTH,START_LEVEL,START_XP,$now,$now]);
        if ((int)$from['id']===MAIN_ADMIN_ID) $db->prepare('INSERT OR IGNORE INTO admins(user_id,role,added_at) VALUES(?,?,?)')->execute([$from['id'],'main',$now]);
    } else {
        $db->prepare('UPDATE users SET username=?,first_name=?,last_seen_at=? WHERE id=?')->execute([$from['username']??null,$from['first_name']??'',$now,$from['id']]);
    }
    $db->prepare('INSERT OR IGNORE INTO player_state(user_id) VALUES(?)')->execute([$from['id']]);
}
function user(int $id): array { global $db; $q=$db->prepare('SELECT * FROM users WHERE id=?'); $q->execute([$id]); return $q->fetch(PDO::FETCH_ASSOC)?:[]; }
function changeBalance(int $id,int $delta,string $reason,?int $actor=null): bool {
    global $db; $db->beginTransaction();
    try { $u=user($id); if(!$u) throw new RuntimeException('user'); $new=(int)$u['balance']+$delta; if($new<0) throw new RuntimeException('money');
        $db->prepare('UPDATE users SET balance=? WHERE id=?')->execute([$new,$id]);
        $db->prepare('INSERT INTO transactions(user_id,amount,balance_after,reason,actor_id,created_at) VALUES(?,?,?,?,?,?)')->execute([$id,$delta,$new,$reason,$actor,time()]);
        $db->commit(); return true;
    } catch(Throwable $e) { if($db->inTransaction())$db->rollBack(); return false; }
}
function addXp(int $id,int $amount): void {
    global $db; $u=user($id); $amount=applyPercent($amount,vipPercent((int)$u['vip_tier'],'xp')); $xp=(int)$u['xp']+$amount; $level=(int)$u['level'];
    while($xp>=xpNeed($level)){ $xp-=xpNeed($level); $level++; }
    $db->prepare('UPDATE users SET xp=?,level=? WHERE id=?')->execute([$xp,$level,$id]);
}
function admin(int $id): bool { global $db; if($id===MAIN_ADMIN_ID)return true; $q=$db->prepare("SELECT 1 FROM admins WHERE user_id=? AND role='admin'"); $q->execute([$id]); return(bool)$q->fetchColumn(); }
function mainMenu(): array { return ['keyboard'=>[[['text'=>'👤 Профиль'],['text'=>'💼 Работа']],[['text'=>'🎁 Бонус'],['text'=>'🚨 Ограбить']],[['text'=>'🏪 Магазин'],['text'=>'🎰 Казино']],[['text'=>'💪 Прокачка'],['text'=>'🎒 Инвентарь']],[['text'=>'💎 VIP'],['text'=>'🏆 Рейтинг']],[['text'=>'📜 События'],['text'=>'📋 Задания']]],'resize_keyboard'=>true]; }
function send(int $chat,string $text,?array $keyboard=null):void { $d=['chat_id'=>$chat,'text'=>$text,'parse_mode'=>'HTML']; if($keyboard)$d['reply_markup']=json_encode($keyboard,JSON_UNESCAPED_UNICODE); tg('sendMessage',$d); }
function answer(array $cb,string $text=''):void { tg('answerCallbackQuery',['callback_query_id'=>$cb['id'],'text'=>$text]); }
function inline(array $rows):array{return ['inline_keyboard'=>$rows];}
function buttons(array $items):array{$rows=[];foreach(array_chunk($items,2) as $chunk){$r=[];foreach($chunk as $x)$r[]=['text'=>$x[0],'callback_data'=>$x[1]];$rows[]=$r;}return inline($rows);}
function profile(int $id):string { $u=user($id); $vn=vipName((int)$u['vip_tier']); return "👤 <b>ПРОФИЛЬ</b>\n\n💰 Бабки: <b>".money((int)$u['balance'])."</b>\n💎 Респекты: <b>".money((int)$u['respect'])."</b>\n👑 VIP: <b>".htmlspecialchars($vn)."</b>\n💪 Сила: <b>{$u['strength']}/100</b>\n⭐ Уровень: <b>{$u['level']}</b>\nXP: <b>{$u['xp']}/".xpNeed((int)$u['level'])."</b>\n\n🆔 ID: <code>{$id}</code>"; }
function shopText(int $id):string{return "🏪 <b>МАГАЗИН</b>\n\n🔪 Нож — 💰 10.000\n🔫 Пистолет — 💰 25.000\n🔫 Автомат — 💰 75.000\n🛡 Броня — 💰 50.000\n🏪 Свой магазин — 💰 150.000";}
function inventoryText(int $id):string{global $db;$names=['knife'=>'🔪 Нож','pistol'=>'🔫 Пистолет','rifle'=>'🔫 Автомат','armor'=>'🛡 Броня','shop'=>'🏪 Свой магазин'];$q=$db->prepare('SELECT item_key,quantity FROM inventory WHERE user_id=? AND quantity>0');$q->execute([$id]);$rows=$q->fetchAll(PDO::FETCH_KEY_PAIR);$s="🎒 <b>ИНВЕНТАРЬ</b>\n\n";if(!$rows)return$s.'Пока пусто.';foreach($rows as $k=>$n)$s.=($names[$k]??$k).": <b>{$n}</b>\n";return$s;}
function vipText(int $id):string{ $u=user($id); $tier=(int)$u['vip_tier']; $s="💎 <b>VIP СИСТЕМА</b>\n\n💎 Респекты: <b>".money((int)$u['respect'])."</b>\n⭐ 1 Telegram Star = 50 Респектов\n💳 Для пополнения: @hixub\n\n"; foreach(VIPS as $i=>$v){$status=$i<=$tier?'✅':($i===$tier+1?'➡️':'🔒');$s.="{$status} {$v[0]} — <b>{$v[1]}</b> Респектов\n".htmlspecialchars($v[2])."\n\n";} if($tier<5)$s.="Следующий VIP: <b>".htmlspecialchars(VIPS[$tier+1][0])." за ".money(VIPS[$tier+1][1])." Респектов</b>"; else $s.="👑 У тебя максимальный VIP."; return$s; }

$update=json_decode(file_get_contents('php://input'),true); if(!$update)exit;
if(isset($update['callback_query'])){
    $cb=$update['callback_query'];$from=$cb['from'];$chat=$cb['message']['chat']['id'];$id=(int)$from['id'];ensureUser($from);$a=$cb['data'];
    if($a==='profile'){answer($cb);send($chat,profile($id));exit;}
    if($a==='vip'){answer($cb);send($chat,vipText($id),buttons([["💎 Купить/улучшить VIP",'vip:buy'],['ℹ️ Бонусы VIP','vip:info']]));exit;}
    if($a==='vip:info'){answer($cb);send($chat,vipText($id));exit;}
    if($a==='vip:buy'){
        $u=user($id);$next=(int)$u['vip_tier']+1;if($next>5){answer($cb,'Максимальный VIP');exit;}$cost=VIPS[$next][1];
        if((int)$u['respect']<$cost){answer($cb,'Недостаточно Респектов');send($chat,"❌ Нужно <b>".money($cost)." Респектов</b>.\n💎 У тебя: <b>".money((int)$u['respect'])."</b>\n\nПополнение: @hixub\n⭐ 1 Star = 50 Респектов");exit;}
        $db->prepare('UPDATE users SET respect=respect-?,vip_tier=? WHERE id=?')->execute([$cost,$next,$id]);addXp($id,50);answer($cb,'VIP активирован!');send($chat,"👑 <b>VIP активирован!</b>\n\n".htmlspecialchars(VIPS[$next][0])."\n💎 Потрачено: <b>".money($cost)." Респектов</b>\n\n".htmlspecialchars(VIPS[$next][2]));exit;
    }
    if($a==='upgrade'){ $u=user($id);$cost=1000*((int)$u['strength']+1);if((int)$u['strength']>=100){answer($cb,'Максимальная сила');exit;}if((int)$u['balance']<$cost){answer($cb,'Недостаточно денег');exit;}changeBalance($id,-$cost,'Прокачка силы');$db->prepare('UPDATE users SET strength=strength+1 WHERE id=?')->execute([$id]);addXp($id,10);answer($cb,'Сила увеличена!');send($chat,"💪 Сила увеличена!\n\n".profile($id));exit; }
    if(str_starts_with($a,'buy:')){ $key=substr($a,4);$prices=['knife'=>10000,'pistol'=>25000,'rifle'=>75000,'armor'=>50000,'shop'=>150000];if(!isset($prices[$key]))exit;$price=$prices[$key];if(!changeBalance($id,-$price,'Покупка '.$key)){answer($cb,'Недостаточно денег');exit;}$db->prepare('INSERT INTO inventory(user_id,item_key,quantity) VALUES(?,?,1) ON CONFLICT(user_id,item_key) DO UPDATE SET quantity=quantity+1')->execute([$id,$key]);if($key==='shop')$db->prepare('INSERT OR IGNORE INTO shops(user_id,level,stored_income,last_income_at) VALUES(?,1,0,?)')->execute([$id,time()]);answer($cb,'Покупка совершена');send($chat,"✅ Куплено!\n\n".inventoryText($id));exit; }
    if($a==='shop'){global $db;$q=$db->prepare('SELECT * FROM shops WHERE user_id=?');$q->execute([$id]);$s=$q->fetch();if(!$s){answer($cb,'Сначала купи свой магазин');exit;}$base=500+((int)$s['level']-1)*300;$incomePerHour=applyPercent($base,vipPercent((int)user($id)['vip_tier'],'shop'));$elapsed=max(0,time()-(int)$s['last_income_at']);$generated=(int)floor($elapsed/3600)*$incomePerHour;if($generated>0){$db->prepare('UPDATE shops SET stored_income=stored_income+?,last_income_at=? WHERE user_id=?')->execute([$generated,time(),$id]);$s['stored_income']+=(int)$generated;}$text="🏪 <b>ТВОЙ МАГАЗИН</b>\n\n📊 Уровень: <b>{$s['level']}</b>\n💰 Доход: <b>".money($incomePerHour)."/час</b>\n💵 Накоплено: <b>".money((int)$s['stored_income'])."</b>";answer($cb);send($chat,$text,buttons([['💰 Забрать доход','shop:collect'],['⬆️ Улучшить магазин','shop:upgrade'],['🚨 Проверить магазин','shop:rob']]));exit; }
    if(str_starts_with($a,'shop:')){ $action=substr($a,5);$q=$db->prepare('SELECT * FROM shops WHERE user_id=?');$q->execute([$id]);$s=$q->fetch();if(!$s){answer($cb,'Магазина нет');exit;}if($action==='collect'){$amount=(int)$s['stored_income'];$db->prepare('UPDATE shops SET stored_income=0 WHERE user_id=?')->execute([$id]);if($amount)changeBalance($id,$amount,'Доход магазина');answer($cb,'Доход забран');send($chat,"💰 Получено: <b>".money($amount)."</b>");}elseif($action==='upgrade'){$cost=150000*(int)$s['level'];if(!changeBalance($id,-$cost,'Улучшение магазина')){answer($cb,'Недостаточно денег');exit;}$db->prepare('UPDATE shops SET level=level+1 WHERE user_id=?')->execute([$id]);addXp($id,30);answer($cb,'Магазин улучшен');send($chat,'⬆️ Магазин улучшен!');}else{$now=time();if($now-(int)$s['last_robbed_at']<259200){answer($cb,'Раз в 3 дня');exit;}$success=random_int(1,100)<=55;$amount=min((int)$s['stored_income'],random_int(1000,20000));$db->prepare('UPDATE shops SET last_robbed_at=? WHERE user_id=?')->execute([$now,$id]);if($success&&$amount>0){$db->prepare('UPDATE shops SET stored_income=stored_income-? WHERE user_id=?')->execute([$amount,$id]);changeBalance($id,$amount,'Ограбление собственного магазина');send($chat,"🚨 Проверка завершена!\n\n✅ Успех. 💰 +".money($amount));}else send($chat,'🚨 Проверка завершена!\n\n❌ Неудача.');addXp($id,30);}exit; }
    if($a==='casino'){answer($cb);send($chat,'🎰 <b>КАЗИНО</b>\n\nВыбери игру:',buttons([['🎰 Слоты','casino:slots'],['🎲 Кубик','casino:dice'],['🃏 Высокая карта','casino:high']]));exit;}
    if(str_starts_with($a,'casino:')){$game=substr($a,7);if(!in_array($game,['slots','dice','high'],true))$game='slots';$db->prepare('INSERT INTO player_state(user_id,casino_game) VALUES(?,?) ON CONFLICT(user_id) DO UPDATE SET casino_game=excluded.casino_game')->execute([$id,$game]);$labels=['slots'=>'🎰 Слоты','dice'=>'🎲 Кубик','high'=>'🃏 Высокая карта'];answer($cb,$labels[$game]);send($chat,"{$labels[$game]} выбраны.\n\nИспользуй <code>/ставка 10000</code>");exit;}
    if($a==='inventory'){answer($cb);send($chat,inventoryText($id));exit;}
    if($a==='admin'){if(!admin($id)){answer($cb,'Нет доступа');exit;}answer($cb);send($chat,'👑 <b>АДМИН-ПАНЕЛЬ</b>',buttons([['👥 Пользователи','adm:users'],['💰 Управление деньгами','adm:money'],['📊 Статистика','adm:stats'],['📢 Рассылка','adm:broadcast'],['👑 Администраторы','adm:admins']]));exit;}
    if(str_starts_with($a,'adm:')&&admin($id)){ $act=substr($a,4);
        if($act==='stats'){ $users=(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();$totalMoney=(int)$db->query('SELECT COALESCE(SUM(balance),0) FROM users')->fetchColumn();$totalRespect=(int)$db->query('SELECT COALESCE(SUM(respect),0) FROM users')->fetchColumn();$shops=(int)$db->query('SELECT COUNT(*) FROM shops')->fetchColumn();$weapons=(int)$db->query("SELECT COALESCE(SUM(quantity),0) FROM inventory WHERE item_key IN ('knife','pistol','rifle')")->fetchColumn();$casino=(int)$db->query('SELECT COUNT(*) FROM casino_games')->fetchColumn();$rob=(int)$db->query('SELECT COUNT(*) FROM robberies')->fetchColumn();$vips=(int)$db->query('SELECT COUNT(*) FROM users WHERE vip_tier>0')->fetchColumn();$today=strtotime('today');$q=$db->prepare('SELECT COUNT(*) FROM users WHERE last_seen_at>=?');$q->execute([$today]);$active=(int)$q->fetchColumn();$q=$db->prepare('SELECT COUNT(*) FROM users WHERE registered_at>=?');$q->execute([$today]);$new=(int)$q->fetchColumn();answer($cb);send($chat,"📊 <b>СТАТИСТИКА</b>\n\n👥 Пользователей: <b>{$users}</b>\n🟢 Активных сегодня: <b>{$active}</b>\n🆕 Новых сегодня: <b>{$new}</b>\n💰 Всего денег: <b>".money($totalMoney)."</b>\n💎 Всего Респектов: <b>".money($totalRespect)."</b>\n👑 VIP игроков: <b>{$vips}</b>\n🏪 Магазинов: <b>{$shops}</b>\n🔫 Оружия: <b>{$weapons}</b>\n🎰 Игр: <b>{$casino}</b>\n🚨 Ограблений: <b>{$rob}</b>");exit; }
        if($act==='users'){answer($cb);$rows=$db->query('SELECT id,username,first_name,balance,strength,level,vip_tier,registered_at FROM users ORDER BY balance DESC LIMIT 30')->fetchAll();$s="👥 <b>ПОЛЬЗОВАТЕЛИ — ТОП 30</b>\n\n";foreach($rows as $r){$name=$r['username']?'@'.$r['username']:($r['first_name']?:'Пользователь');$s.="🆔 <code>{$r['id']}</code> ".htmlspecialchars($name)." — 💰 ".money((int)$r['balance'])." | 💪 {$r['strength']} | ⭐ {$r['level']} | 👑 ".htmlspecialchars(vipName((int)$r['vip_tier']))."\n";}send($chat,$s);exit;}
        if($act==='admins'){answer($cb);$rows=$db->query('SELECT a.user_id,a.role,u.username,u.first_name FROM admins a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.role DESC,a.user_id')->fetchAll();$s="👑 <b>АДМИНИСТРАТОРЫ</b>\n\n";foreach($rows as $r){$name=$r['username']?'@'.$r['username']:($r['first_name']?:'Пользователь');$s.=($r['role']==='main'?'👑 Главный':'🛡 Администратор')." — <code>{$r['user_id']}</code> ".htmlspecialchars($name)."\n";}if($id===MAIN_ADMIN_ID)$s.="\n<code>/safonof50 ID</code> — добавить\n<code>/safonof50 ID del</code> — удалить";send($chat,$s);exit;}
        if($act==='money'){answer($cb);send($chat,"💰 <b>УПРАВЛЕНИЕ</b>\n\n/give ID SUM\n/take ID SUM\n/setmoney ID SUM\n/safonof51 ID SUM");exit;}
        if($act==='broadcast'){answer($cb);send($chat,"📢 <code>/safonof52 текст сообщения</code>");exit;}
    }
    exit;
}
if(!isset($update['message']))exit;$m=$update['message'];$from=$m['from'];$chat=(int)$m['chat']['id'];$id=(int)$from['id'];ensureUser($from);$text=trim($m['text']??'');
if($text==='/start'){send($chat,"💰 <b>РАБОТА БАНДИТА</b>\n\n".profile($id),mainMenu());exit;}
if($text==='/safonof'){if(!admin($id)){send($chat,'⛔ Доступ запрещён.');exit;}send($chat,'👑 <b>АДМИН-ПАНЕЛЬ</b>',buttons([['👥 Пользователи','adm:users'],['💰 Управление деньгами','adm:money'],['📊 Статистика','adm:stats'],['📢 Рассылка','adm:broadcast'],['👑 Администраторы','adm:admins']]));exit;}
if(preg_match('~^/safonof52\s+(.+)~us',$text,$mm)&&admin($id)){ $message=$mm[1];$sent=0;$failed=0;foreach($db->query('SELECT id FROM users') as $r){$res=tg('sendMessage',['chat_id'=>$r['id'],'text'=>$message,'parse_mode'=>'HTML']);if(!empty($res['ok']))$sent++;else$failed++;}$db->prepare('INSERT INTO broadcast_log(admin_id,message,sent_count,failed_count,created_at) VALUES(?,?,?,?,?)')->execute([$id,$message,$sent,$failed,time()]);send($chat,"📢 Рассылка завершена.\n\n✅ {$sent}\n❌ {$failed}");exit;}
if(preg_match('~^/safonof50\s+(\d+)(?:\s+(del|delete))?$~i',$text,$mm)&&$id===MAIN_ADMIN_ID){$target=(int)$mm[1];$del=!empty($mm[2]);if($target===MAIN_ADMIN_ID){send($chat,'❌ Главного администратора менять нельзя.');exit;}if($del){$st=$db->prepare("DELETE FROM admins WHERE user_id=? AND role='admin'");$st->execute([$target]);send($chat,$st->rowCount()?"✅ Администратор удалён: <code>{$target}</code>":'❌ Такой администратор не найден.');}else{$db->prepare("INSERT OR IGNORE INTO admins(user_id,role,added_at) VALUES(?,?,?)")->execute([$target,'admin',time()]);send($chat,"✅ Администратор добавлен: <code>{$target}</code>");}exit;}
if(preg_match('~^/safonof51\s+(\d+)\s+(\d+)$~',$text,$mm)&&$id===MAIN_ADMIN_ID){$target=(int)$mm[1];$sum=(int)$mm[2];if($sum<=0){send($chat,'❌ Сумма должна быть больше 0.');exit;}$u=user($target);if(!$u){send($chat,'❌ Пользователь не найден.');exit;}$db->prepare('UPDATE users SET respect=respect+? WHERE id=?')->execute([$sum,$target]);$db->prepare('INSERT INTO transactions(user_id,amount,balance_after,reason,actor_id,created_at) VALUES(?,?,?,?,?,?)')->execute([$target,0,(int)$u['balance'],'Выданы Респекты администратором',$id,time()]);send($chat,"✅ Выдано 💎 <b>".money($sum)." Респектов</b> пользователю <code>{$target}</code>");exit;}
if(preg_match('~^/give\s+(\d+)\s+(\d+)$~',$text,$mm)&&admin($id)){ $target=(int)$mm[1];$sum=(int)$mm[2];if(!$user($target)){send($chat,'Пользователь не найден.');exit;}changeBalance($target,$sum,'Выдано администратором',$id);send($chat,"✅ Выдано 💰 <b>".money($sum)."</b> пользователю <code>{$target}</code>");exit;}
if(preg_match('~^/take\s+(\d+)\s+(\d+)$~',$text,$mm)&&admin($id)){ $target=(int)$mm[1];$sum=(int)$mm[2];if(!changeBalance($target,-$sum,'Забрано администратором',$id)){send($chat,'❌ Не удалось снять деньги.');exit;}send($chat,"✅ Забрано 💰 <b>".money($sum)."</b> у <code>{$target}</code>");exit;}
if(preg_match('~^/setmoney\s+(\d+)\s+(\d+)$~',$text,$mm)&&admin($id)){ $target=(int)$mm[1];$sum=(int)$mm[2];$u=user($target);if(!$u){send($chat,'Пользователь не найден.');exit;}changeBalance($target,$sum-(int)$u['balance'],'Баланс установлен администратором',$id);send($chat,"✅ Баланс <code>{$target}</code>: 💰 <b>".money($sum)."</b>");exit;}
if(preg_match('~^/addadmin\s+(\d+)$~',$text,$mm)&&$id===MAIN_ADMIN_ID){$target=(int)$mm[1];$db->prepare("INSERT OR IGNORE INTO admins(user_id,role,added_at) VALUES(?,?,?)")->execute([$target,'admin',time()]);send($chat,"✅ Администратор добавлен: <code>{$target}</code>");exit;}
if(preg_match('~^/deladmin\s+(\d+)$~',$text,$mm)&&$id===MAIN_ADMIN_ID){$target=(int)$mm[1];if($target===MAIN_ADMIN_ID){send($chat,'❌ Нельзя удалить главного.');exit;}$st=$db->prepare("DELETE FROM admins WHERE user_id=? AND role='admin'");$st->execute([$target]);send($chat,$st->rowCount()?"✅ Администратор удалён: <code>{$target}</code>":'❌ Не найден.');exit;}
if(preg_match('~^/ставка\s+(\d+)$~u',$text,$mm)){ $bet=(int)$mm[1];$u=user($id);$max=(int)$db->query("SELECT value FROM settings WHERE key='casino_max_bet'")->fetchColumn();if($bet<=0||$bet>(int)$u['balance']||$bet>$max){send($chat,"❌ Некорректная ставка. Максимум: ".money(min($max,(int)$u['balance'])));exit;}$q=$db->prepare('SELECT casino_game FROM player_state WHERE user_id=?');$q->execute([$id]);$game=$q->fetchColumn()?:'slots';changeBalance($id,-$bet,'Ставка казино');$result='lose';$payout=0;$label='🎰 Слоты';
    if($game==='dice'){ $roll=random_int(1,6);$win=$roll>=4;$payout=$win?$bet*2:0;$result=$win?'win':'lose';$label='🎲 Кубик';$out="{$label}\nВыпало: <b>{$roll}</b>"; }
    elseif($game==='high'){ $player=random_int(2,14);$house=random_int(2,14);$win=$player>$house;$tie=$player===$house;$payout=$win?$bet*2:($tie?$bet:0);$result=$win?'win':($tie?'tie':'lose');$label='🃏 Высокая карта';$out="{$label}\nТвоя карта: <b>{$player}</b>\nКарта казино: <b>{$house}</b>"; }
    else { $symbols=['🍒','🍋','🍊','💎','7️⃣'];$a1=$symbols[array_rand($symbols)];$a2=$symbols[array_rand($symbols)];$a3=$symbols[array_rand($symbols)];$same=$a1===$a2&&$a2===$a3;$pair=$a1===$a2||$a2===$a3||$a1===$a3;$payout=$same?$bet*4:($pair?$bet*2:0);$result=$payout?'win':'lose';$label='🎰 Слоты';$out="{$label}\n{$a1} {$a2} {$a3}"; }
    if($payout)changeBalance($id,$payout,'Выигрыш казино');$db->prepare('INSERT INTO casino_games(user_id,game,bet,result,payout,created_at) VALUES(?,?,?,?,?,?)')->execute([$id,$game,$bet,$result,$payout,time()]);addXp($id,10);send($chat,$out."\n\n".($payout?"🎉 Выигрыш: 💰 <b>".money($payout)."</b>":($result==='tie'?'🤝 Ничья — ставка возвращена.':'😔 Проигрыш.')));exit;}
if($text==='👤 Профиль'){send($chat,profile($id));exit;}
if($text==='💎 VIP'){send($chat,vipText($id),buttons([['💎 Купить/улучшить VIP','vip:buy'],['ℹ️ Бонусы VIP','vip:info']]));exit;}
if($text==='🎒 Инвентарь'){send($chat,inventoryText($id));exit;}
if($text==='🏪 Магазин'){send($chat,shopText($id),buttons([['🔪 Нож — 10.000','buy:knife'],['🔫 Пистолет — 25.000','buy:pistol'],['🔫 Автомат — 75.000','buy:rifle'],['🛡 Броня — 50.000','buy:armor'],['🏪 Свой магазин — 150.000','buy:shop'],['🏪 Мой магазин','shop']]));exit;}
if($text==='💪 Прокачка'){$u=user($id);$cost=1000*((int)$u['strength']+1);send($chat,"💪 <b>ПРОКАЧКА</b>\n\nСила: <b>{$u['strength']}/100</b>\nСледующая: 💰 <b>".money($cost)."</b>",buttons([['💪 Прокачать','upgrade']]));exit;}
if($text==='🎰 Казино'){send($chat,'🎰 <b>КАЗИНО</b>\n\nВыбери игру:',buttons([['🎰 Слоты','casino:slots'],['🎲 Кубик','casino:dice'],['🃏 Высокая карта','casino:high']]));exit;}
if($text==='🎁 Бонус'){$u=user($id);if(time()-(int)$u['last_daily_at']<86400){send($chat,'🎁 Бонус уже получен. Возвращайся позже.');exit;}$bonus=(int)$db->query("SELECT value FROM settings WHERE key='daily_bonus'")->fetchColumn();if((int)$u['vip_tier']>=2)$bonus*=2;changeBalance($id,$bonus,'Ежедневный бонус');$db->prepare('UPDATE users SET last_daily_at=? WHERE id=?')->execute([time(),$id]);addXp($id,15);send($chat,"🎁 Ежедневный бонус: 💰 <b>".money($bonus)."</b>");exit;}
if($text==='💼 Работа'){$cool=(int)$db->query("SELECT value FROM settings WHERE key='work_cooldown'")->fetchColumn();$q=$db->prepare('SELECT COALESCE(MAX(created_at),0) FROM jobs WHERE user_id=?');$q->execute([$id]);$last=(int)$q->fetchColumn();if(time()-$last<$cool){send($chat,'💼 Работа пока недоступна. Попробуй позже.');exit;}$u=user($id);$reward=applyPercent(random_int(2000,6000),vipPercent((int)$u['vip_tier'],'work'));changeBalance($id,$reward,'Работа');addXp($id,20);$db->prepare('INSERT INTO jobs(user_id,job_key,reward,xp,created_at) VALUES(?,?,?,?,?)')->execute([$id,'thief',$reward,20,time()]);send($chat,"💼 Работа выполнена!\n💰 +<b>".money($reward)."</b>\n⭐ +20 XP");exit;}
if($text==='🏆 Рейтинг'){$rows=$db->query('SELECT id,username,first_name,balance FROM users ORDER BY balance DESC LIMIT 10')->fetchAll();$s="🏆 <b>ТОП-10 ПО БАЛАНСУ</b>\n\n";$i=1;foreach($rows as $r){$s.="{$i}. ".htmlspecialchars($r['username']?'@'.$r['username']:$r['first_name'])." — 💰 ".money((int)$r['balance'])."\n";$i++;}send($chat,$s);exit;}
if($text==='📜 События'){$q=$db->prepare('SELECT reason,amount,created_at FROM transactions WHERE user_id=? ORDER BY id DESC LIMIT 10');$q->execute([$id]);$s="📜 <b>ПОСЛЕДНИЕ СОБЫТИЯ</b>\n\n";foreach($q as $r)$s.=htmlspecialchars($r['reason']).": ".((int)$r['amount']>=0?'+':'').money((int)$r['amount'])."\n";send($chat,$s);exit;}
if($text==='📋 Задания'){send($chat,"📋 <b>ЗАДАНИЯ</b>\n\n💼 Выполняй работу\n🚨 Совершай ограбления\n🎰 Играй в казино\n💪 Прокачивай силу\n🏪 Развивай магазин\n\n🏅 За активность получаешь XP и открываешь достижения.");exit;}
if($text==='🚨 Ограбить'){send($chat,"🚨 <b>ОГРАБЛЕНИЕ ИГРОКА</b>\n\nВведи:\n<code>/ограбить TELEGRAM_ID</code>\n\nСистема учитывает силу, оружие, броню и VIP.");exit;}
if(preg_match('~^/ограбить\s+(\d+)$~u',$text,$mm)){ $target=(int)$mm[1];if($target===$id){send($chat,'❌ Нельзя выбрать себя.');exit;}$tu=user($target);$au=user($id);if(!$tu){send($chat,'❌ Игрок не найден.');exit;}$q=$db->prepare('SELECT COALESCE(MAX(created_at),0) FROM robberies WHERE attacker_id=?');$q->execute([$id]);$last=(int)$q->fetchColumn();$cool=(int)$db->query("SELECT value FROM settings WHERE key='robbery_cooldown'")->fetchColumn();if(time()-$last<$cool){send($chat,'⏳ Ограбление пока недоступно. Попробуй позже.');exit;}$hasPistol=(int)$db->query("SELECT COALESCE(quantity,0) FROM inventory WHERE user_id={$target} AND item_key='pistol'")->fetchColumn()>0;$hasArmor=(int)$db->query("SELECT COALESCE(quantity,0) FROM inventory WHERE user_id={$target} AND item_key='armor'")->fetchColumn()>0;$attWeapon=(int)$db->query("SELECT COALESCE(SUM(quantity),0) FROM inventory WHERE user_id={$id} AND item_key IN ('knife','pistol','rifle')")->fetchColumn();$base=35+(int)$au['strength']*0.35+$attWeapon*4+vipPercent((int)$au['vip_tier'],'rob');$defense=(int)$tu['strength']*0.25+($hasArmor?15:0);$success=random_int(1,100)<=max(5,min(90,(int)round($base-$defense)));$amount=$success?min((int)$tu['balance'],max(1000,(int)floor((int)$tu['balance']*random_int(5,20)/100))):0;$defenderStops=$hasPistol&&random_int(1,100)<=25;if($success&&!$defenderStops&&$amount>0){changeBalance($target,-$amount,'Ограблён игроком',$id);changeBalance($id,$amount,'Ограбление игрока',$id);$result='success';send($chat,"🚨 <b>Ограбление удалось!</b>\n💰 Получено: <b>".money($amount)."</b>");}elseif($defenderStops){$result='stopped';send($chat,'🚨 Ограбление сорвано: у цели был пистолет.');}else{$result='fail';send($chat,'🚨 Ограбление не удалось.');}$db->prepare('INSERT INTO robberies(attacker_id,target_id,type,result,amount,created_at) VALUES(?,?,?,?,?,?)')->execute([$id,$target,'player',$result,$amount,time()]);addXp($id,35);exit;}
?>

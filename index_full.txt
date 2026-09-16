<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

$db = new PDO('sqlite:' . DB_PATH);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(file_get_contents(__DIR__ . '/schema.sql'));

// Безопасные миграции существующей базы: данные пользователей не удаляются.
$migrations = [
    "ALTER TABLE users ADD COLUMN respect INTEGER NOT NULL DEFAULT 0",
    "ALTER TABLE users ADD COLUMN vip_tier INTEGER NOT NULL DEFAULT 0",
    "ALTER TABLE users ADD COLUMN vip_until INTEGER NOT NULL DEFAULT 0",
    "ALTER TABLE broadcast_log ADD COLUMN broadcast_type TEXT NOT NULL DEFAULT 'normal'"
];
foreach ($migrations as $sql) { try { $db->exec($sql); } catch (Throwable $e) {} }

if ((int)$db->query('SELECT COUNT(*) FROM vip_tasks')->fetchColumn() === 0) {
    $seed=$db->prepare('INSERT INTO vip_tasks(title,task_type,target_count,reward_money,reward_respect,min_vip,created_at) VALUES(?,?,?,?,?,?,?)');
    $seed->execute(['Выполнить 5 работ','work',5,4500,0,1,time()]);
    $seed->execute(['Совершить 3 ограбления','robbery',3,10000,0,1,time()]);
    $seed->execute(['Провести активность в чате','chat',30,15000,10,1,time()]);
}

const VIPS = [
    1 => ['🔪 VIP Уличный', 200, "Работа +10%\nОграбление +5%"],
    2 => ['🔫 VIP Авторитет', 500, "Работа +20%\nОграбление +10%\nДоп. ежедневный бонус"],
    3 => ['💰 VIP Криминальный босс', 1000, "Работа +30%\nОграбление +15%\nДоход магазина +20%"],
    4 => ['👑 VIP Главарь', 2000, "Работа +45%\nОграбление +20%\nДоход магазина +30%\nXP +10%"],
    5 => ['💎 VIP Легенда', 5500, "Работа +60%\nОграбление +25%\nДоход магазина +50%\nXP +25%\nОсобый статус 👑"]
];

function tg(string $method, array $data=[]): array {
    $url='https://api.telegram.org/bot'.BOT_TOKEN.'/'.$method;
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$data,CURLOPT_TIMEOUT=>30]);
    $raw=curl_exec($ch); curl_close($ch);
    return json_decode($raw?:'{}',true)?:[];
}
function money(int $n): string { return number_format($n,0,'.','.'); }
function xpNeed(int $level): int { return 1000+(($level-1)*250); }
function vipName(int $tier): string { return $tier>0?(VIPS[$tier][0]??'Нет'):'Нет'; }
function vipPercent(int $tier,string $kind): int {
    $map=['work'=>[0,10,20,30,45,60],'rob'=>[0,5,10,15,20,25],'shop'=>[0,0,20,30,50,50],'xp'=>[0,0,0,0,10,25]];
    return $map[$kind][$tier]??0;
}
function applyPercent(int $amount,int $percent): int { return (int)floor($amount*(100+$percent)/100); }
function esc(string $s): string { return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function user(int $id): array { global $db; $q=$db->prepare('SELECT * FROM users WHERE id=?'); $q->execute([$id]); return $q->fetch(PDO::FETCH_ASSOC)?:[]; }
function ensureUser(array $from): void {
    global $db; $now=time(); $id=(int)$from['id'];
    $q=$db->prepare('SELECT id FROM users WHERE id=?'); $q->execute([$id]);
    if(!$q->fetchColumn()) {
        $db->prepare('INSERT INTO users(id,username,first_name,balance,strength,level,xp,registered_at,last_seen_at) VALUES(?,?,?,?,?,?,?,?,?)')
            ->execute([$id,$from['username']??null,$from['first_name']??'',START_BALANCE,START_STRENGTH,START_LEVEL,START_XP,$now,$now]);
    } else {
        $db->prepare('UPDATE users SET username=?,first_name=?,last_seen_at=? WHERE id=?')->execute([$from['username']??null,$from['first_name']??'',$now,$id]);
    }
    if($id===MAIN_ADMIN_ID) $db->prepare("INSERT OR IGNORE INTO admins(user_id,role,added_at) VALUES(?,?,?)")->execute([$id,'main',$now]);
    $db->prepare('INSERT OR IGNORE INTO player_state(user_id) VALUES(?)')->execute([$id]);
    expireVip($id);
}
function admin(int $id): bool {
    global $db; if($id===MAIN_ADMIN_ID)return true;
    $q=$db->prepare("SELECT 1 FROM admins WHERE user_id=? AND role='admin'"); $q->execute([$id]); return (bool)$q->fetchColumn();
}
function mainAdmin(int $id): bool { return $id===MAIN_ADMIN_ID; }
function send(int $chat,string $text,?array $keyboard=null): void {
    $d=['chat_id'=>$chat,'text'=>$text,'parse_mode'=>'HTML']; if($keyboard)$d['reply_markup']=json_encode($keyboard,JSON_UNESCAPED_UNICODE); tg('sendMessage',$d);
}
function inline(array $rows): array { return ['inline_keyboard'=>$rows]; }
function buttons(array $items): array {
    $rows=[]; foreach(array_chunk($items,2) as $chunk){$r=[];foreach($chunk as $x)$r[]=['text'=>$x[0],'callback_data'=>$x[1]];$rows[]=$r;} return inline($rows);
}
function answer(array $cb,string $text=''): void { tg('answerCallbackQuery',['callback_query_id'=>$cb['id'],'text'=>$text]); }
function logAdmin(int $adminId,string $action,string $details=''): void { global $db; $db->prepare('INSERT INTO admin_logs(admin_id,action,details,created_at) VALUES(?,?,?,?)')->execute([$adminId,$action,$details,time()]); }
function setting(string $key,string $default=''): string { global $db; $q=$db->prepare('SELECT value FROM settings WHERE key=?');$q->execute([$key]);return (string)($q->fetchColumn()??$default); }
function setSetting(string $key,string $value): void { global $db; $db->prepare('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value')->execute([$key,$value]); }
function changeBalance(int $id,int $delta,string $reason,?int $actor=null): bool {
    global $db; $u=user($id); if(!$u)return false; $new=(int)$u['balance']+$delta; if($new<0)return false;
    $db->beginTransaction(); try{$db->prepare('UPDATE users SET balance=? WHERE id=?')->execute([$new,$id]);$db->prepare('INSERT INTO transactions(user_id,amount,balance_after,reason,actor_id,created_at) VALUES(?,?,?,?,?,?)')->execute([$id,$delta,$new,$reason,$actor,time()]);$db->commit();return true;}catch(Throwable $e){$db->rollBack();return false;}
}
function addRespect(int $id,int $amount,string $reason,?int $actor=null): bool {
    global $db; if(!user($id)||$amount===0)return false; $db->prepare('UPDATE users SET respect=MAX(0,respect+?) WHERE id=?')->execute([$amount,$id]);
    $u=user($id); $db->prepare('INSERT INTO transactions(user_id,amount,balance_after,reason,actor_id,created_at) VALUES(?,?,?,?,?,?)')->execute([$id,0,(int)$u['balance'],$reason,$actor,time()]); return true;
}
function expireVip(int $id): void {
    global $db; $u=user($id); if(!$u)return; if((int)$u['vip_tier']>0 && (int)$u['vip_until']>0 && (int)$u['vip_until']<=time()) $db->prepare('UPDATE users SET vip_tier=0,vip_until=0 WHERE id=?')->execute([$id]);
}
function grantVip(int $id,int $tier,int $days,?int $actor=null): bool {
    global $db; if(!user($id)||$tier<1||$tier>5)return false; $until=$days===0?0:time()+$days*86400;
    $db->prepare('UPDATE users SET vip_tier=?,vip_until=? WHERE id=?')->execute([$tier,$until,$id]); if($actor!==null)logAdmin($actor,'Выдача VIP',"user={$id};tier={$tier};days={$days}"); return true;
}
function profile(int $id): string {
    expireVip($id); $u=user($id); $vip=vipName((int)$u['vip_tier']); $until=(int)$u['vip_until'];
    $vtime=$until>0?'\n⏳ До: <b>'.date('d.m.Y H:i',$until).'</b>':((int)$u['vip_tier']>0?'\n♾️ Срок: <b>навсегда</b>':'');
    return "👤 <b>ПРОФИЛЬ</b>\n\n💰 Бабки: <b>".money((int)$u['balance'])."</b>\n💎 Респекты: <b>".money((int)$u['respect'])."</b>\n👑 VIP: <b>".esc($vip)."</b>{$vtime}\n💪 Сила: <b>{$u['strength']}/100</b>\n⭐ Уровень: <b>{$u['level']}</b>\nXP: <b>{$u['xp']}/".xpNeed((int)$u['level'])."</b>\n\n🆔 ID: <code>{$id}</code>";
}
function addXp(int $id,int $amount): void { global $db; $u=user($id);$amount=applyPercent($amount,vipPercent((int)$u['vip_tier'],'xp'));$xp=(int)$u['xp']+$amount;$level=(int)$u['level'];while($xp>=xpNeed($level)){$xp-=xpNeed($level);$level++;}$db->prepare('UPDATE users SET xp=?,level=? WHERE id=?')->execute([$xp,$level,$id]); }
function inventoryText(int $id): string { global $db; $names=['knife'=>'🔪 Нож','pistol'=>'🔫 Пистолет','rifle'=>'🔫 Автомат','armor'=>'🛡 Броня','shop'=>'🏪 Свой магазин'];$q=$db->prepare('SELECT item_key,quantity FROM inventory WHERE user_id=? AND quantity>0');$q->execute([$id]);$rows=$q->fetchAll(PDO::FETCH_KEY_PAIR);$s="🎒 <b>ИНВЕНТАРЬ</b>\n\n";if(!$rows)return$s.'Пока пусто.';foreach($rows as $k=>$n)$s.=($names[$k]??esc($k)).": <b>{$n}</b>\n";return$s; }
function shopText(int $id): string { return "🏪 <b>МАГАЗИН</b>\n\n🔪 Нож — 💰 10.000\n🔫 Пистолет — 💰 25.000\n🔫 Автомат — 💰 75.000\n🛡 Броня — 💰 50.000\n🏪 Свой магазин — 💰 150.000"; }
function vipText(int $id): string { expireVip($id);$u=user($id);$tier=(int)$u['vip_tier'];$s="💎 <b>VIP СИСТЕМА</b>\n\n💎 Респекты: <b>".money((int)$u['respect'])."</b>\n⭐ 1 Telegram Star = 50 Респектов\n💳 Для пополнения: @hixub\n\n";foreach(VIPS as $i=>$v){$status=$i<=$tier?'✅':($i===$tier+1?'➡️':'🔒');$s.="{$status} {$v[0]} — <b>{$v[1]}</b> Респектов\n".nl2br(esc($v[2]))."\n\n";}return $s.($tier<5?"Следующий VIP: <b>".esc(VIPS[$tier+1][0])." за ".money(VIPS[$tier+1][1])." Респектов</b>":'👑 Поздравляю, у тебя максимальный уровень VIP Статуса! 👑'); }
function mainMenu(): array { return ['keyboard'=>[[['text'=>'👤 Профиль'],['text'=>'💼 Работа']],[['text'=>'🎁 Бонус'],['text'=>'🚨 Ограбить']],[['text'=>'🏪 Магазин'],['text'=>'🎰 Казино']],[['text'=>'💪 Прокачка'],['text'=>'🎒 Инвентарь']],[['text'=>'💎 VIP'],['text'=>'🏆 Рейтинг']],[['text'=>'📜 События'],['text'=>'📋 Задания']],[['text'=>'👋 Новичкам']]],'resize_keyboard'=>true]; }
function adminMenu(int $id): array {
    $items=[['👥 Пользователи','adm:users'],['💰 Экономика','adm:money'],['💎 VIP','adm:vip'],['🎯 VIP-задания','adm:tasks'],['📢 Рассылки','adm:broadcast'],['🏠 Чаты','adm:chats'],['📊 Статистика','adm:stats'],['🎉 События','adm:events'],['👋 Новичкам','adm:welcome'],['📝 Логи','adm:logs']];
    if(mainAdmin($id))$items[]=['👑 Администраторы','adm:admins']; return buttons($items);
}
function upsertChat(array $chat,bool $active=true): void { global $db; if(!in_array($chat['type']??'', ['group','supergroup','channel'],true))return;$now=time();$db->prepare('INSERT INTO chats(chat_id,title,username,type,is_active,can_send,added_at,updated_at) VALUES(?,?,?,?,?,?,?,?) ON CONFLICT(chat_id) DO UPDATE SET title=excluded.title,username=excluded.username,type=excluded.type,is_active=excluded.is_active,updated_at=excluded.updated_at')->execute([(int)$chat['id'],$chat['title']??'', $chat['username']??null,$chat['type'],$active?1:0,1,$now,$now]); }
function markChatInactive(int $chatId): void { global $db;$db->prepare('UPDATE chats SET is_active=0,can_send=0,updated_at=? WHERE chat_id=?')->execute([time(),$chatId]); }
function recordActivity(int $chatId,int $userId): void { global $db; $date=date('Y-m-d');$db->prepare('INSERT INTO chat_activity(chat_id,user_id,activity_date,message_count) VALUES(?,?,?,?,1) ON CONFLICT(chat_id,user_id,activity_date) DO UPDATE SET message_count=message_count+1')->execute([$chatId,$userId,$date]); }
function periodKey(string $type): string { return $type==='chat'?date('Y-m-d'):date('Y-m-d'); }
function taskProgress(int $userId,int $taskId,string $type): int { global $db;$period=periodKey($type);$q=$db->prepare('SELECT progress FROM vip_task_progress WHERE task_id=? AND user_id=? AND period_key=?');$q->execute([$taskId,$userId,$period]);return(int)($q->fetchColumn()??0); }
function incrementTasks(int $userId,string $type,int $count=1): void {
    global $db; $u=user($userId); if(!$u||((int)$u['vip_tier']<1))return;
    $q=$db->prepare('SELECT * FROM vip_tasks WHERE is_active=1 AND task_type=? AND min_vip<=?');$q->execute([$type,(int)$u['vip_tier']]);
    $period=periodKey($type);
    foreach($q as $t){
        $db->prepare('INSERT INTO vip_task_progress(task_id,user_id,progress,period_key,completed,claimed) VALUES(?,?,?,?,0,0) ON CONFLICT(task_id,user_id,period_key) DO NOTHING')->execute([$t['id'],$userId,0,$period]);
        $db->prepare('UPDATE vip_task_progress SET progress=MIN(?,progress+?) WHERE task_id=? AND user_id=? AND period_key=? AND completed=0')->execute([(int)$t['target_count'],$count,(int)$t['id'],$userId,$period]);
        $p=taskProgress($userId,(int)$t['id'],$type);
        $q2=$db->prepare('SELECT completed,claimed FROM vip_task_progress WHERE task_id=? AND user_id=? AND period_key=?');$q2->execute([(int)$t['id'],$userId,$period]);$st=$q2->fetch(PDO::FETCH_ASSOC);
        if($p>=(int)$t['target_count'] && $st){
            if(!$st['claimed']){
                if((int)$t['reward_money']>0)changeBalance($userId,(int)$t['reward_money'],'Награда VIP-задания');
                if((int)$t['reward_respect']>0)addRespect($userId,(int)$t['reward_respect'],'Награда VIP-задания');
                $db->prepare('UPDATE vip_task_progress SET claimed=1,completed=1 WHERE task_id=? AND user_id=? AND period_key=?')->execute([(int)$t['id'],$userId,$period]);
            } elseif(!$st['completed']) $db->prepare('UPDATE vip_task_progress SET completed=1 WHERE task_id=? AND user_id=? AND period_key=?')->execute([(int)$t['id'],$userId,$period]);
        }
    }
}
function vipTasksText(int $id): string { global $db;$u=user($id);if((int)$u['vip_tier']<1)return "🎯 <b>VIP-ЗАДАНИЯ</b>\n\n🔒 Задания доступны с VIP-Уличного.";$q=$db->prepare('SELECT * FROM vip_tasks WHERE is_active=1 AND min_vip<=? ORDER BY id');$q->execute([(int)$u['vip_tier']]);$s="🎯 <b>VIP-ЗАДАНИЯ</b>\n\n";$found=false;foreach($q as $t){$found=true;$p=taskProgress($id,(int)$t['id'],$t['task_type']);$period=periodKey($t['task_type']);$st=$db->prepare('SELECT claimed FROM vip_task_progress WHERE task_id=? AND user_id=? AND period_key=?');$st->execute([(int)$t['id'],$id,$period]);$claimed=(int)($st->fetchColumn()??0);$s.="🎯 <b>".esc($t['title'])."</b>\n▰ ".min($p,(int)$t['target_count'])."/{$t['target_count']}\n💰 +".money((int)$t['reward_money'])." | 💎 +".money((int)$t['reward_respect'])."\n".($claimed?'✅ Награда получена':($p>=(int)$t['target_count']?'🎁 Награда готова':'⏳ Выполняй дальше'))."\n\n";}return$found?$s:'🎯 Активных VIP-заданий пока нет.'; }
function eventsText(): string { global $db;$q=$db->prepare('SELECT * FROM events WHERE is_active=1 AND ends_at>? ORDER BY ends_at ASC');$q->execute([time()]);$rows=$q->fetchAll();if(!$rows)return "🎉 <b>СОБЫТИЯ</b>\n\nСейчас активных розыгрышей нет.";$s="🎉 <b>СОБЫТИЯ</b>\n\n";foreach($rows as $e){$s.="🎁 <b>".esc($e['title'])."</b>\n".esc($e['description'])."\n💰 Награда: <b>".money((int)$e['reward_money'])."</b> бабок\n💎 Респекты: <b>".money((int)$e['reward_respect'])."</b>\n👥 Победителей: <b>{$e['winners_count']}</b>\n⏰ До: <b>".date('d.m.Y H:i',(int)$e['ends_at'])."</b>\n\n";}return$s; }
function eventButtons(): array { global $db;$q=$db->prepare('SELECT id,title FROM events WHERE is_active=1 AND ends_at>? ORDER BY ends_at');$q->execute([time()]);$rows=[];foreach($q as $e)$rows[]=['🎁 Участвовать: '.mb_substr($e['title'],0,25),'event:join:'.$e['id']];return buttons($rows); }
function chatsText(): string { global $db;$rows=$db->query('SELECT * FROM chats WHERE is_active=1 ORDER BY title COLLATE NOCASE')->fetchAll();$s="🏠 <b>ЧАТЫ БОТА</b>\n\n";if(!$rows)return$s.'Бот пока не видит подключённых чатов.';$i=1;foreach($rows as $r){$s.="{$i}. 🏠 <b>".esc($r['title']?:'Без названия')."</b> — <code>{$r['chat_id']}</code> ".((int)$r['can_send']?'✅':'❌')."\n";$i++;}return$s."\nВсего: <b>".count($rows)."</b>"; }
function statsText(?int $chatId=null): string { global $db;if($chatId!==null){$q=$db->prepare('SELECT COUNT(*) FROM chat_activity WHERE chat_id=? AND activity_date=?');$q->execute([$chatId,date('Y-m-d')]);$messages=(int)$q->fetchColumn();$q=$db->prepare('SELECT COUNT(DISTINCT user_id) FROM chat_activity WHERE chat_id=? AND activity_date=? AND message_count>0');$q->execute([$chatId,date('Y-m-d')]);$active=(int)$q->fetchColumn();$q=$db->prepare('SELECT user_id,message_count FROM chat_activity WHERE chat_id=? AND activity_date=? ORDER BY message_count DESC LIMIT 10');$q->execute([$chatId,date('Y-m-d')]);$s="📊 <b>СТАТИСТИКА ЧАТА</b>\n\n💬 Сообщений сегодня: <b>{$messages}</b>\n🟢 Активных: <b>{$active}</b>\n\n🏆 <b>ТОП-10</b>\n";$i=1;foreach($q as $r){$u=user((int)$r['user_id']);$name=$u['username']?'@'.$u['username']:($u['first_name']?:$r['user_id']);$s.="{$i}. ".esc($name)." — <b>{$r['message_count']}</b>\n";$i++;}return$s;}
    $users=(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();$active=(int)$db->query("SELECT COUNT(*) FROM users WHERE last_seen_at>=strftime('%s','now')-86400")->fetchColumn();$chats=(int)$db->query('SELECT COUNT(*) FROM chats WHERE is_active=1')->fetchColumn();$vip=(int)$db->query('SELECT COUNT(*) FROM users WHERE vip_tier>0')->fetchColumn();$msgs=(int)$db->query("SELECT COALESCE(SUM(message_count),0) FROM chat_activity WHERE activity_date='".date('Y-m-d')."'")->fetchColumn();return "📊 <b>ОБЩАЯ СТАТИСТИКА</b>\n\n👥 Пользователей: <b>{$users}</b>\n🟢 Активных за 24ч: <b>{$active}</b>\n🏠 Чатов: <b>{$chats}</b>\n💎 VIP-пользователей: <b>{$vip}</b>\n💬 Сообщений сегодня: <b>{$msgs}</b>"; }
function broadcastTargets(): array { global $db;return $db->query('SELECT chat_id FROM chats WHERE is_active=1 AND can_send=1')->fetchAll(PDO::FETCH_COLUMN); }
function copyBroadcast(int $fromChat,int $messageId,string $type,int $adminId): array { global $db;$sent=0;$failed=0;foreach(broadcastTargets() as $chatId){$r=tg('copyMessage',['chat_id'=>$chatId,'from_chat_id'=>$fromChat,'message_id'=>$messageId]);if(!empty($r['ok']))$sent++;else{$failed++;$desc=strtolower((string)($r['description']??''));if(str_contains($desc,'chat not found')||str_contains($desc,'bot was kicked')||str_contains($desc,'forbidden'))markChatInactive((int)$chatId);}}$db->prepare('INSERT INTO broadcast_log(admin_id,message,sent_count,failed_count,created_at,broadcast_type) VALUES(?,?,?,?,?,?)')->execute([$adminId,'message_id='.$messageId,$sent,$failed,time(),$type]);logAdmin($adminId,'Рассылка',"type={$type};sent={$sent};failed={$failed}");return[$sent,$failed]; }
function adminList(): string { global $db;$rows=$db->query('SELECT a.user_id,a.role,u.username,u.first_name FROM admins a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.role DESC,a.user_id')->fetchAll();$s="👑 <b>АДМИНИСТРАТОРЫ</b>\n\n";foreach($rows as $r){$name=$r['username']?'@'.$r['username']:($r['first_name']?:'Пользователь');$s.=($r['role']==='main'?'👑 Главный':'🛡 Администратор')." — <code>{$r['user_id']}</code> ".esc($name)."\n";}return$s; }
function adminHelp(int $id): string { $s="⚙️ <b>КАК ПОЛЬЗОВАТЬСЯ АДМИН-ПАНЕЛЬЮ</b>\n\n<b>/safonof</b> — открыть панель\n<b>/чаты</b> — список чатов\n<b>/статистика</b> — общая статистика\n\n<b>💰 Экономика</b>\n/give ID СУММА\n/take ID СУММА\n/setmoney ID СУММА\n/safonof51 ID СУММА — Респекты\n\n<b>💎 VIP</b>\n/выдатьVIP ID УРОВЕНЬ СРОК\n/снятьVIP ID\nСрок: 7 / 30 / 90 / 0 (навсегда)\n\n<b>📢 Рассылка</b>\n/рассылка — следующующее сообщение станет обычной рассылкой\n/реклама — следующующее сообщение станет рекламной рассылкой\nМожно отправлять текст, фото, видео и кружочки.\n\n<b>👑 Главный администратор</b>\n/safonof50 ID — добавить администратора\n/safonof50 ID del — удалить администратора\n";return$s; }

$update=json_decode(file_get_contents('php://input'),true); if(!$update)exit;

// Telegram сообщает об изменении статуса бота в группе/канале через my_chat_member.
if(isset($update['my_chat_member'])){
    $cm=$update['my_chat_member'];$c=$cm['chat'];$status=$cm['new_chat_member']['status']??'';upsertChat($c,!in_array($status,['left','kicked'],true));if(in_array($status,['left','kicked'],true))markChatInactive((int)$c['id']);exit;
}

if(isset($update['callback_query'])){
    $cb=$update['callback_query'];$from=$cb['from'];$chat=(int)$cb['message']['chat']['id'];$id=(int)$from['id'];ensureUser($from);$a=$cb['data'];
    if($a==='profile'){answer($cb);send($chat,profile($id),buttons([['🏢 Мои бизнесы','profile:businesses']]));exit;}
    if($a==='profile:businesses'){answer($cb);$q=$db->prepare('SELECT * FROM shops WHERE user_id=?');$q->execute([$id]);$shop=$q->fetch();if(!$shop){send($chat,'🏢 <b>МОИ БИЗНЕСЫ</b>\n\nПока у тебя нет бизнесов.\n\nКупи «🏪 Свой магазин» в разделе магазина.');}else{send($chat,'🏢 <b>МОИ БИЗНЕСЫ</b>\n\n🏪 <b>Свой магазин</b> — уровень '.(int)$shop['level'],buttons([['🏪 Открыть свой магазин','shop']]));}exit;}
    if($a==='vip'){answer($cb);send($chat,vipText($id),buttons([['💎 Купить/улучшить VIP','vip:buy'],['🎯 VIP-задания','vip:tasks']]));exit;}
    if($a==='vip:tasks'){answer($cb);send($chat,vipTasksText($id));exit;}
    if($a==='vip:buy'){ $u=user($id);$next=(int)$u['vip_tier']+1;if($next>5){answer($cb,'Максимальный VIP');exit;}$cost=VIPS[$next][1];if((int)$u['respect']<$cost){answer($cb,'Недостаточно Респектов');send($chat,"❌ Нужно <b>".money($cost)." Респектов</b>.\n💎 У тебя: <b>".money((int)$u['respect'])."</b>\n\nПополнение: @hixub\n⭐ 1 Star = 50 Респектов");exit;}$db->prepare('UPDATE users SET respect=respect-?,vip_tier=?,vip_until=0 WHERE id=?')->execute([$cost,$next,$id]);addXp($id,50);answer($cb,'VIP активирован!');send($chat,"👑 <b>VIP активирован!</b>\n\n".esc(VIPS[$next][0])."\n💎 Потрачено: <b>".money($cost)." Респектов</b>\n\n".nl2br(esc(VIPS[$next][2])));exit; }
    if($a==='upgrade'){ $u=user($id);$cost=1000*((int)$u['strength']+1);if((int)$u['strength']>=100){answer($cb,'Максимальная сила');exit;}if(!changeBalance($id,-$cost,'Прокачка силы')){answer($cb,'Недостаточно денег');exit;}$db->prepare('UPDATE users SET strength=strength+1 WHERE id=?')->execute([$id]);addXp($id,10);answer($cb,'Сила увеличена!');send($chat,"💪 Сила увеличена!\n\n".profile($id));exit; }
    if(str_starts_with($a,'buy:')){ $key=substr($a,4);$prices=['knife'=>10000,'pistol'=>25000,'rifle'=>75000,'armor'=>50000,'shop'=>150000];if(!isset($prices[$key]))exit;$price=$prices[$key];if(!changeBalance($id,-$price,'Покупка '.$key)){answer($cb,'Недостаточно денег');exit;}$db->prepare('INSERT INTO inventory(user_id,item_key,quantity) VALUES(?,?,1) ON CONFLICT(user_id,item_key) DO UPDATE SET quantity=quantity+1')->execute([$id,$key]);if($key==='shop')$db->prepare('INSERT OR IGNORE INTO shops(user_id,level,stored_income,last_income_at) VALUES(?,1,0,?)')->execute([$id,time()]);answer($cb,'Покупка совершена');send($chat,"✅ Куплено!\n\n".inventoryText($id));exit; }
    if($a==='shop'){$q=$db->prepare('SELECT * FROM shops WHERE user_id=?');$q->execute([$id]);$s=$q->fetch();if(!$s){answer($cb,'Сначала купи свой магазин');exit;}$base=500+((int)$s['level']-1)*300;$incomePerHour=applyPercent($base,vipPercent((int)user($id)['vip_tier'],'shop'));$elapsed=max(0,time()-(int)$s['last_income_at']);$generated=(int)floor($elapsed/3600)*$incomePerHour;if($generated>0){$db->prepare('UPDATE shops SET stored_income=stored_income+?,last_income_at=? WHERE user_id=?')->execute([$generated,time(),$id]);$s['stored_income']+=(int)$generated;}$text="🏪 <b>ТВОЙ МАГАЗИН</b>\n\n📊 Уровень: <b>{$s['level']}</b>\n💰 Доход: <b>".money($incomePerHour)."/час</b>\n💵 Накоплено: <b>".money((int)$s['stored_income'])."</b>";answer($cb);send($chat,$text,buttons([['💰 Забрать доход','shop:collect'],['⬆️ Улучшить магазин','shop:upgrade'],['🚨 Проверить магазин','shop:rob']]));exit; }
    if(str_starts_with($a,'shop:')){$action=substr($a,5);$q=$db->prepare('SELECT * FROM shops WHERE user_id=?');$q->execute([$id]);$s=$q->fetch();if(!$s){answer($cb,'Магазина нет');exit;}if($action==='collect'){$amount=(int)$s['stored_income'];$db->prepare('UPDATE shops SET stored_income=0 WHERE user_id=?')->execute([$id]);if($amount)changeBalance($id,$amount,'Доход магазина');answer($cb,'Доход забран');send($chat,"💰 Получено: <b>".money($amount)."</b>");}elseif($action==='upgrade'){$cost=150000*(int)$s['level'];if(!changeBalance($id,-$cost,'Улучшение магазина')){answer($cb,'Недостаточно денег');exit;}$db->prepare('UPDATE shops SET level=level+1 WHERE user_id=?')->execute([$id]);addXp($id,30);answer($cb,'Магазин улучшен');send($chat,'⬆️ Магазин улучшен!');}else{$now=time();if($now-(int)$s['last_robbed_at']<259200){answer($cb,'Раз в 3 дня');exit;}$success=random_int(1,100)<=55;$amount=min((int)$s['stored_income'],random_int(1000,20000));$db->prepare('UPDATE shops SET last_robbed_at=? WHERE user_id=?')->execute([$now,$id]);if($success&&$amount>0){$db->prepare('UPDATE shops SET stored_income=stored_income-? WHERE user_id=?')->execute([$amount,$id]);changeBalance($id,$amount,'Ограбление собственного магазина');send($chat,"🚨 Проверка завершена!\n\n✅ Успех. 💰 +".money($amount));}else send($chat,'🚨 Проверка завершена!\n\n❌ Неудача.');addXp($id,30);}exit;}
    if($a==='casino'){answer($cb);send($chat,'🎰 <b>КАЗИНО</b>\n\nВыбери игру:',buttons([['🎰 Слоты','casino:slots'],['🎲 Кубик','casino:dice'],['🃏 Высокая карта','casino:high']]));exit;}
    if(str_starts_with($a,'event:join:')){ $eid=(int)substr($a,11);$q=$db->prepare('SELECT * FROM events WHERE id=? AND is_active=1 AND ends_at>?');$q->execute([$eid,time()]);$e=$q->fetch();if(!$e){answer($cb,'Событие уже завершено');exit;}$st=$db->prepare('INSERT OR IGNORE INTO event_participants(event_id,user_id,joined_at) VALUES(?,?,?)');$st->execute([$eid,$id,time()]);answer($cb,$st->rowCount()?'Ты участвуешь!':'Ты уже участвуешь!');send($chat,eventsText(),eventButtons());exit;}
    if(str_starts_with($a,'casino:')){$game=substr($a,7);if(!in_array($game,['slots','dice','high'],true))$game='slots';$db->prepare('INSERT INTO player_state(user_id,casino_game) VALUES(?,?) ON CONFLICT(user_id) DO UPDATE SET casino_game=excluded.casino_game')->execute([$id,$game]);$labels=['slots'=>'🎰 Слоты','dice'=>'🎲 Кубик','high'=>'🃏 Высокая карта'];answer($cb,$labels[$game]);send($chat,"{$labels[$game]} выбраны.\n\nИспользуй <code>/ставка 10000</code>");exit;}
    if($a==='admin'){if(!admin($id)){answer($cb,'Нет доступа');exit;}answer($cb);send($chat,'👑 <b>АДМИН-ПАНЕЛЬ</b>',adminMenu($id));exit;}
    if(str_starts_with($a,'adm:')&&admin($id)){
        $act=substr($a,4);answer($cb);
        if($act==='users'){send($chat,'👥 <b>ПОЛЬЗОВАТЕЛИ</b>\n\nДля быстрого управления используй команды из раздела «Экономика» и «VIP».');exit;}
        if($act==='money'){send($chat,"💰 <b>ЭКОНОМИКА</b>\n\n<code>/give ID СУММА</code> — выдать\n<code>/take ID СУММА</code> — забрать\n<code>/setmoney ID СУММА</code> — установить\n<code>/safonof51 ID СУММА</code> — выдать Респекты");exit;}
        if($act==='vip'){send($chat,"💎 <b>УПРАВЛЕНИЕ VIP</b>\n\n<code>/выдатьVIP ID УРОВЕНЬ СРОК</code>\nУровень: 1–5\nСрок: 7 / 30 / 90 / 0 = навсегда\n\n<code>/снятьVIP ID</code>");exit;}
        if($act==='tasks'){send($chat,"🎯 <b>VIP-ЗАДАНИЯ</b>\n\n<code>/viptask Название | тип | количество | деньги | респекты | VIP</code>\nТип: work / robbery / chat\n\nПримеры:\n<code>/viptask 5 работ | work | 5 | 4500 | 0 | 1</code>\n<code>/viptask 3 ограбления | robbery | 3 | 10000 | 0 | 1</code>\n<code>/viptask 30 сообщений | chat | 30 | 15000 | 10 | 1</code>");exit;}
        if($act==='broadcast'){send($chat,"📢 <b>РАССЫЛКИ</b>\n\nВыбери режим. После выбора отправь следующее сообщение.\nМожно отправлять текст, фото, видео и кружочки.",buttons([['📢 Обычная рассылка','bc:normal'],['💰 РЕКЛАМА','bc:ad']]));exit;}
        if($act==='chats'){send($chat,chatsText());exit;}
        if($act==='stats'){send($chat,statsText(),buttons([['🏠 Выбрать чат','adm:stats_chats']]));exit;}
        if($act==='stats_chats'){global $db;$rows=$db->query('SELECT chat_id,title FROM chats WHERE is_active=1 ORDER BY title')->fetchAll();$arr=[];foreach($rows as $r)$arr[]=['🏠 '.mb_substr($r['title']?:'Без названия',0,30),'stat:'.$r['chat_id']];send($chat,'📊 <b>ВЫБЕРИ ЧАТ</b>',buttons($arr?:[['Нет чатов','noop']]));exit;}
        if($act==='events'){send($chat,"🎉 <b>СОБЫТИЯ</b>\n\n<code>/event Название | описание | деньги | респекты | победители | часы</code>\n<code>/event_del ID</code> — удалить\n<code>/event_end ID</code> — завершить\n\nПользователи видят только активные будущие события.");exit;}
        if($act==='welcome'){send($chat,"👋 <b>ИНФОРМАЦИЯ ДЛЯ НОВИЧКОВ</b>\n\nТекущий текст:\n\n".setting('welcome_info')."\n\nЧтобы заменить:\n<code>/новичкам НОВЫЙ ТЕКСТ</code>");exit;}
        if($act==='logs'){global $db;$rows=$db->query('SELECT * FROM admin_logs ORDER BY id DESC LIMIT 15')->fetchAll();$s="📝 <b>ПОСЛЕДНИЕ ЛОГИ</b>\n\n";foreach($rows as $r)$s.="👤 <code>{$r['admin_id']}</code> — ".esc($r['action'])."\n".esc($r['details'])."\n🕐 ".date('d.m H:i',(int)$r['created_at'])."\n\n";send($chat,$s);exit;}
        if($act==='admins'&&mainAdmin($id)){send($chat,adminList());exit;}
    }
    if($a==='bc:normal'&&admin($id)){setSetting('broadcast_state_'.$id,'normal');logAdmin($id,'Запуск рассылки','normal');answer($cb,'Готово');send($chat,'📢 Отправь следующее сообщение: текст, фото, видео или кружочек.');exit;}
    if($a==='bc:ad'&&admin($id)){setSetting('broadcast_state_'.$id,'ad');logAdmin($id,'Запуск рассылки','ad');answer($cb,'Готово');send($chat,'💰 Отправь следующее рекламное сообщение: текст, фото, видео или кружочек.');exit;}
    if($a==='events:refresh'){answer($cb);send($chat,eventsText(),eventButtons());exit;}
    if(str_starts_with($a,'stat:')&&admin($id)){ $cid=(int)substr($a,5);answer($cb);send($chat,statsText($cid));exit; }
    if($a==='noop'){answer($cb);exit;}
    exit;
}

if(!isset($update['message']))exit;
$m=$update['message'];$from=$m['from']??[];$chatData=$m['chat']??[];$chat=(int)($chatData['id']??0);$id=(int)($from['id']??0);if(!$id)return;
ensureUser($from);
if(in_array($chatData['type']??'', ['group','supergroup','channel'],true)){upsertChat($chatData,true);if(isset($m['text'])||isset($m['caption'])||isset($m['photo'])||isset($m['video'])||isset($m['video_note'])||isset($m['sticker'])||isset($m['document'])||isset($m['animation'])){recordActivity($chat,$id);incrementTasks($id,'chat');}}
$text=trim((string)($m['text']??''));

// Состояние рассылки: следующее сообщение администратора копируется во все чаты.
if(admin($id) && isset($m['message_id']) && !preg_match('/^\//u',$text)){
    $stateKey='broadcast_state_'.$id; $state=setting($stateKey,'');
    if($state==='normal'||$state==='ad'){$type=$state==='ad'?'ad':'normal';[$sent,$failed]=copyBroadcast($chat,(int)$m['message_id'],$type,$id);setSetting($stateKey,'');send($chat,"📢 <b>Рассылка завершена</b>\n\n".($type==='ad'?'💰 Тип: РЕКЛАМА\n':'')."✅ Отправлено: <b>{$sent}</b>\n❌ Ошибок: <b>{$failed}</b>");exit;}
}

if($text==='/start'){send($chat,"💰 <b>РАБОТА БАНДИТА</b>\n\n".profile($id),mainMenu());exit;}
if($text==='/safonof'){if(!admin($id)){send($chat,'⛔ Доступ запрещён.');exit;}send($chat,'👑 <b>АДМИН-ПАНЕЛЬ</b>',adminMenu($id));exit;}
if($text==='/чаты'&&admin($id)){send($chat,chatsText());exit;}
if($text==='/статистика'&&admin($id)){send($chat,statsText(),buttons([['🏠 Выбрать чат','adm:stats_chats']]));exit;}
if($text==='/рассылка'&&admin($id)){setSetting('broadcast_state_'.$id,'normal');logAdmin($id,'Запуск рассылки','normal');send($chat,"📢 <b>ОБЫЧНАЯ РАССЫЛКА</b>\n\nОтправь следующим сообщением текст, фото, видео или кружочек.\n\n❌ Отмена: <code>/отмена</code>");exit;}
if($text==='/реклама'&&admin($id)){setSetting('broadcast_state_'.$id,'ad');logAdmin($id,'Запуск рассылки','ad');send($chat,"💰 <b>РЕКЛАМНАЯ РАССЫЛКА</b>\n\nОтправь следующим сообщением рекламу: текст, фото, видео или кружочек.\n\n❌ Отмена: <code>/отмена</code>");exit;}
if($text==='/отмена'&&admin($id)){setSetting('broadcast_state_'.$id,'');send($chat,'❌ Действие отменено.');exit;}
if(preg_match('~^/safonof50\s+(\d+)(?:\s+(del|delete))?$~iu',$text,$mm)&&mainAdmin($id)){$target=(int)$mm[1];$del=!empty($mm[2]);if($target===MAIN_ADMIN_ID){send($chat,'❌ Главного администратора менять нельзя.');exit;}if($del){$st=$db->prepare("DELETE FROM admins WHERE user_id=? AND role='admin'");$st->execute([$target]);logAdmin($id,'Удаление администратора',"user={$target}");send($chat,$st->rowCount()?"✅ Администратор удалён: <code>{$target}</code>":'❌ Такой администратор не найден.');}else{$db->prepare("INSERT OR IGNORE INTO admins(user_id,role,added_at) VALUES(?,?,?)")->execute([$target,'admin',time()]);logAdmin($id,'Добавление администратора',"user={$target}");send($chat,"✅ Администратор добавлен: <code>{$target}</code>");}exit;}
if(preg_match('~^/safonof51\s+(\d+)\s+(\d+)$~u',$text,$mm)&&mainAdmin($id)){$target=(int)$mm[1];$sum=(int)$mm[2];if($sum<=0||!user($target)){send($chat,'❌ Некорректный пользователь или сумма.');exit;}addRespect($target,$sum,'Выданы Респекты администратором',$id);logAdmin($id,'Выдача Респектов',"user={$target};sum={$sum}");send($chat,"✅ Выдано 💎 <b>".money($sum)." Респектов</b> пользователю <code>{$target}</code>");exit;}
if(preg_match('~^/выдатьVIP\s+(\d+)\s+([1-5])\s+(7|30|90|0)$~u',$text,$mm)&&admin($id)){$target=(int)$mm[1];$tier=(int)$mm[2];$days=(int)$mm[3];if(!user($target)||!grantVip($target,$tier,$days,$id)){send($chat,'❌ Не удалось выдать VIP.');exit;}send($chat,"✅ Пользователю <code>{$target}</code> выдан <b>".esc(VIPS[$tier][0])."</b>\n⏳ ".($days===0?'Навсегда':$days.' дней'));exit;}
if(preg_match('~^/снятьVIP\s+(\d+)$~u',$text,$mm)&&admin($id)){$target=(int)$mm[1];if(!user($target)){send($chat,'❌ Пользователь не найден.');exit;}$db->prepare('UPDATE users SET vip_tier=0,vip_until=0 WHERE id=?')->execute([$target]);logAdmin($id,'Снятие VIP',"user={$target}");send($chat,"✅ VIP снят у <code>{$target}</code>");exit;}
if(preg_match('~^/viptask\s+(.+?)\s*\|\s*(work|robbery|chat)\s*\|\s*(\d+)\s*\|\s*(\d+)\s*\|\s*(\d+)\s*\|\s*([1-5])$~u',$text,$mm)&&admin($id)){$title=trim($mm[1]);$type=$mm[2];$target=(int)$mm[3];$rm=(int)$mm[4];$rr=(int)$mm[5];$minVip=(int)$mm[6];if($target<=0||$rm<0||$rr<0){send($chat,'❌ Неверные значения.');exit;}$db->prepare('INSERT INTO vip_tasks(title,task_type,target_count,reward_money,reward_respect,min_vip,created_at) VALUES(?,?,?,?,?,?,?)')->execute([$title,$type,$target,$rm,$rr,$minVip,time()]);logAdmin($id,'Создание VIP-задания',"{$title};type={$type}");send($chat,'✅ VIP-задание создано.');exit;}
if(preg_match('~^/viptask_del\s+(\d+)$~u',$text,$mm)&&admin($id)){ $tid=(int)$mm[1];$db->prepare('UPDATE vip_tasks SET is_active=0 WHERE id=?')->execute([$tid]);logAdmin($id,'Отключение VIP-задания','task='.$tid);send($chat,'✅ VIP-задание отключено.');exit;}
if(preg_match('~^/viptask_edit\s+(\d+)\s*\|\s*(.+?)\s*\|\s*(work|robbery|chat)\s*\|\s*(\d+)\s*\|\s*(\d+)\s*\|\s*(\d+)\s*\|\s*([1-5])$~u',$text,$mm)&&admin($id)){ $tid=(int)$mm[1];$title=trim($mm[2]);$type=$mm[3];$target=(int)$mm[4];$rm=(int)$mm[5];$rr=(int)$mm[6];$minVip=(int)$mm[7];$st=$db->prepare('UPDATE vip_tasks SET title=?,task_type=?,target_count=?,reward_money=?,reward_respect=?,min_vip=?,is_active=1 WHERE id=?');$st->execute([$title,$type,$target,$rm,$rr,$minVip,$tid]);logAdmin($id,'Изменение VIP-задания','task='.$tid);send($chat,'✅ VIP-задание изменено.');exit;}
if(preg_match('~^/новичкам\s+(.+)$~us',$text,$mm)&&admin($id)){setSetting('welcome_info',$mm[1]);logAdmin($id,'Изменение информации для новичков');send($chat,'✅ Информация для новичков обновлена.');exit;}
if(preg_match('~^/event\s+(.+?)\s*\|\s*(.*?)\s*\|\s*(\d+)\s*\|\s*(\d+)\s*\|\s*(\d+)\s*\|\s*(\d+)$~us',$text,$mm)&&admin($id)){$db->prepare('INSERT INTO events(title,description,reward_money,reward_respect,winners_count,ends_at,is_active,created_by,created_at) VALUES(?,?,?,?,?,?,1,?,?)')->execute([trim($mm[1]),trim($mm[2]),(int)$mm[3],(int)$mm[4],max(1,(int)$mm[5]),time()+max(1,(int)$mm[6])*3600,$id,time()]);logAdmin($id,'Создание события',trim($mm[1]));send($chat,'✅ Событие создано.');exit;}
if(preg_match('~^/event_(del|end)\s+(\d+)$~u',$text,$mm)&&admin($id)){$idEvent=(int)$mm[2];$db->prepare('UPDATE events SET is_active=0 WHERE id=?')->execute([$idEvent]);logAdmin($id,$mm[1]==='del'?'Удаление события':'Завершение события',"event={$idEvent}");send($chat,'✅ Событие отключено.');exit;}
if(preg_match('~^/event_draw\s+(\d+)$~u',$text,$mm)&&admin($id)){
    $eid=(int)$mm[1]; $q=$db->prepare('SELECT * FROM events WHERE id=? AND is_active=1'); $q->execute([$eid]); $e=$q->fetch();
    if(!$e){send($chat,'❌ Событие не найдено или уже завершено.');exit;}
    $q=$db->prepare('SELECT user_id FROM event_participants WHERE event_id=?');$q->execute([$eid]);$participants=$q->fetchAll(PDO::FETCH_COLUMN);
    if(!$participants){send($chat,'❌ Участников нет.');exit;}
    shuffle($participants);$winners=array_slice($participants,0,min((int)$e['winners_count'],count($participants)));
    foreach($winners as $winner){
        if((int)$e['reward_money']>0)changeBalance((int)$winner,(int)$e['reward_money'],'Победа в событии',$id);
        if((int)$e['reward_respect']>0)addRespect((int)$winner,(int)$e['reward_respect'],'Победа в событии',$id);
        send((int)$winner,"🏆 <b>Ты победил в событии!</b>\n\n🎁 ".esc($e['title'])."\n💰 Награда: <b>".money((int)$e['reward_money'])."</b>\n💎 Респекты: <b>".money((int)$e['reward_respect'])."</b>");
    }
    $db->prepare('UPDATE events SET is_active=0 WHERE id=?')->execute([$eid]);logAdmin($id,'Розыгрыш события','event='.$eid.';winners='.implode(',',array_map('intval',$winners)));send($chat,'🏆 Розыгрыш завершён. Победителей: <b>'.count($winners).'</b>');exit;
}
if(preg_match('~^/give\s+(\d+)\s+(\d+)$~',$text,$mm)&&admin($id)){ $target=(int)$mm[1];$sum=(int)$mm[2];if($sum<=0||!changeBalance($target,$sum,'Выдано администратором',$id)){send($chat,'❌ Не удалось выдать деньги.');exit;}logAdmin($id,'Выдача денег',"user={$target};sum={$sum}");send($chat,"✅ Выдано 💰 <b>".money($sum)."</b> пользователю <code>{$target}</code>");exit; }
if(preg_match('~^/take\s+(\d+)\s+(\d+)$~',$text,$mm)&&admin($id)){ $target=(int)$mm[1];$sum=(int)$mm[2];if($sum<=0||!changeBalance($target,-$sum,'Забрано администратором',$id)){send($chat,'❌ Не удалось снять деньги.');exit;}logAdmin($id,'Снятие денег',"user={$target};sum={$sum}");send($chat,"✅ Забрано 💰 <b>".money($sum)."</b> у <code>{$target}</code>");exit; }
if(preg_match('~^/setmoney\s+(\d+)\s+(\d+)$~',$text,$mm)&&admin($id)){ $target=(int)$mm[1];$sum=(int)$mm[2];$u=user($target);if(!$u||$sum<0){send($chat,'❌ Некорректные данные.');exit;}if(!changeBalance($target,$sum-(int)$u['balance'],'Баланс установлен администратором',$id)){send($chat,'❌ Не удалось изменить баланс.');exit;}logAdmin($id,'Установка баланса',"user={$target};sum={$sum}");send($chat,"✅ Баланс <code>{$target}</code>: 💰 <b>".money($sum)."</b>");exit; }
if($text==='/помощьадмин'&&admin($id)){send($chat,adminHelp($id));exit;}
if($text==='/admin'&&admin($id)){send($chat,'👑 <b>АДМИН-ПАНЕЛЬ</b>',adminMenu($id));exit;}
if($text==='👤 Профиль'){send($chat,profile($id),buttons([['🏢 Мои бизнесы','profile:businesses']]));exit;}
if($text==='💎 VIP'){send($chat,vipText($id),buttons([['💎 Купить/улучшить VIP','vip:buy'],['🎯 VIP-задания','vip:tasks']]));exit;}
if($text==='🎒 Инвентарь'){send($chat,inventoryText($id));exit;}
if($text==='🏪 Магазин'){send($chat,shopText($id),buttons([['🔪 Нож — 10.000','buy:knife'],['🔫 Пистолет — 25.000','buy:pistol'],['🔫 Автомат — 75.000','buy:rifle'],['🛡 Броня — 50.000','buy:armor'],['🏪 Свой магазин — 150.000','buy:shop'],['🏪 Мой магазин','shop']]));exit;}
if($text==='💪 Прокачка'){$u=user($id);$cost=1000*((int)$u['strength']+1);send($chat,"💪 <b>ПРОКАЧКА</b>\n\nСила: <b>{$u['strength']}/100</b>\nСледующая: 💰 <b>".money($cost)."</b>",buttons([['💪 Прокачать','upgrade']]));exit;}
if($text==='🎰 Казино'){send($chat,'🎰 <b>КАЗИНО</b>\n\nВыбери игру:',buttons([['🎰 Слоты','casino:slots'],['🎲 Кубик','casino:dice'],['🃏 Высокая карта','casino:high']]));exit;}
if($text==='🎁 Бонус'){$u=user($id);if(time()-(int)$u['last_daily_at']<86400){send($chat,'🎁 Бонус уже получен. Возвращайся позже.');exit;}$bonus=(int)setting('daily_bonus','5000');if((int)$u['vip_tier']>=2)$bonus*=2;changeBalance($id,$bonus,'Ежедневный бонус');$db->prepare('UPDATE users SET last_daily_at=? WHERE id=?')->execute([time(),$id]);addXp($id,15);send($chat,"🎁 Ежедневный бонус: 💰 <b>".money($bonus)."</b>");exit;}
if($text==='💼 Работа'){$cool=(int)setting('work_cooldown','3600');$q=$db->prepare('SELECT COALESCE(MAX(created_at),0) FROM jobs WHERE user_id=?');$q->execute([$id]);$last=(int)$q->fetchColumn();if(time()-$last<$cool){send($chat,'💼 Работа пока недоступна. Попробуй позже.');exit;}$u=user($id);$reward=applyPercent(random_int(2000,6000),vipPercent((int)$u['vip_tier'],'work'));changeBalance($id,$reward,'Работа');addXp($id,20);$db->prepare('INSERT INTO jobs(user_id,job_key,reward,xp,created_at) VALUES(?,?,?,?,?)')->execute([$id,'thief',$reward,20,time()]);incrementTasks($id,'work');send($chat,"💼 Работа выполнена!\n💰 +<b>".money($reward)."</b>\n⭐ +20 XP");exit;}
if($text==='🏆 Рейтинг'){$rows=$db->query('SELECT id,username,first_name,balance FROM users ORDER BY balance DESC LIMIT 10')->fetchAll();$s="🏆 <b>ТОП-10 ПО БАЛАНСУ</b>\n\n";$i=1;foreach($rows as $r){$s.="{$i}. ".esc($r['username']?'@'.$r['username']:$r['first_name'])." — 💰 ".money((int)$r['balance'])."\n";$i++;}send($chat,$s);exit;}
if($text==='📜 События'){send($chat,eventsText(),eventButtons());exit;}
if($text==='📋 Задания'){send($chat,vipTasksText($id));exit;}
if($text==='👋 Новичкам'){send($chat,setting('welcome_info'));exit;}
if($text==='🚨 Ограбить'){send($chat,"🚨 <b>ОГРАБЛЕНИЕ ИГРОКА</b>\n\nВведи:\n<code>/ограбить TELEGRAM_ID</code>\n\nСистема учитывает силу, оружие, броню и VIP.");exit;}
if(preg_match('~^/ставка\s+(\d+)$~u',$text,$mm)){ $bet=(int)$mm[1];$u=user($id);$max=(int)setting('casino_max_bet','1000000');if($bet<=0||$bet>(int)$u['balance']||$bet>$max){send($chat,"❌ Некорректная ставка. Максимум: ".money(min($max,(int)$u['balance'])));exit;}$q=$db->prepare('SELECT casino_game FROM player_state WHERE user_id=?');$q->execute([$id]);$game=$q->fetchColumn()?:'slots';changeBalance($id,-$bet,'Ставка казино');$result='lose';$payout=0;$label='🎰 Слоты';if($game==='dice'){$roll=random_int(1,6);$win=$roll>=4;$payout=$win?$bet*2:0;$result=$win?'win':'lose';$label='🎲 Кубик';$out="{$label}\nВыпало: <b>{$roll}</b>";}elseif($game==='high'){$player=random_int(2,14);$house=random_int(2,14);$win=$player>$house;$tie=$player===$house;$payout=$win?$bet*2:($tie?$bet:0);$result=$win?'win':($tie?'tie':'lose');$label='🃏 Высокая карта';$out="{$label}\nТвоя карта: <b>{$player}</b>\nКарта казино: <b>{$house}</b>";}else{$symbols=['🍒','🍋','🍊','💎','7️⃣'];$a1=$symbols[array_rand($symbols)];$a2=$symbols[array_rand($symbols)];$a3=$symbols[array_rand($symbols)];$same=$a1===$a2&&$a2===$a3;$pair=$a1===$a2||$a2===$a3||$a1===$a3;$payout=$same?$bet*4:($pair?$bet*2:0);$result=$payout?'win':'lose';$label='🎰 Слоты';$out="{$label}\n{$a1} {$a2} {$a3}";}if($payout)changeBalance($id,$payout,'Выигрыш казино');$db->prepare('INSERT INTO casino_games(user_id,game,bet,result,payout,created_at) VALUES(?,?,?,?,?,?)')->execute([$id,$game,$bet,$result,$payout,time()]);addXp($id,10);send($chat,$out."\n\n".($payout?"🎉 Выигрыш: 💰 <b>".money($payout)."</b>":($result==='tie'?'🤝 Ничья — ставка возвращена.':'😔 Проигрыш.')));exit; }
if(preg_match('~^/ограбить\s+(\d+)$~u',$text,$mm)){ $target=(int)$mm[1];if($target===$id){send($chat,'❌ Нельзя выбрать себя.');exit;}$tu=user($target);$au=user($id);if(!$tu){send($chat,'❌ Игрок не найден.');exit;}$q=$db->prepare('SELECT COALESCE(MAX(created_at),0) FROM robberies WHERE attacker_id=?');$q->execute([$id]);$last=(int)$q->fetchColumn();$cool=(int)setting('robbery_cooldown','86400');if(time()-$last<$cool){send($chat,'⏳ Ограбление пока недоступно. Попробуй позже.');exit;}$hasPistol=(int)$db->query("SELECT COALESCE(quantity,0) FROM inventory WHERE user_id={$target} AND item_key='pistol'")->fetchColumn()>0;$hasArmor=(int)$db->query("SELECT COALESCE(quantity,0) FROM inventory WHERE user_id={$target} AND item_key='armor'")->fetchColumn()>0;$attWeapon=(int)$db->query("SELECT COALESCE(SUM(quantity),0) FROM inventory WHERE user_id={$id} AND item_key IN ('knife','pistol','rifle')")->fetchColumn();$base=35+(int)$au['strength']*0.35+$attWeapon*4+vipPercent((int)$au['vip_tier'],'rob');$defense=(int)$tu['strength']*0.25+($hasArmor?15:0);$success=random_int(1,100)<=max(5,min(90,(int)round($base-$defense)));$amount=$success?min((int)$tu['balance'],max(1000,(int)floor((int)$tu['balance']*random_int(5,20)/100))):0;$defenderStops=$hasPistol&&random_int(1,100)<=25;if($success&&!$defenderStops&&$amount>0){changeBalance($target,-$amount,'Ограблён игроком',$id);changeBalance($id,$amount,'Ограбление игрока',$id);$result='success';send($chat,"🚨 <b>Ограбление удалось!</b>\n💰 Получено: <b>".money($amount)."</b>");}elseif($defenderStops){$result='stopped';send($chat,'🚨 Ограбление сорвано: у цели был пистолет.');}else{$result='fail';send($chat,'🚨 Ограбление не удалось.');}$db->prepare('INSERT INTO robberies(attacker_id,target_id,type,result,amount,created_at) VALUES(?,?,?,?,?,?)')->execute([$id,$target,'player',$result,$amount,time()]);addXp($id,35);incrementTasks($id,'robbery');exit;}
if($text==='events:refresh'){send($chat,eventsText());exit;}
?>

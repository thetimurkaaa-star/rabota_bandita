<?php
// Работа Бандита — Telegram bot webhook entrypoint.
// PHP 8.1+ / SQLite / no Composer required.

declare(strict_types=1);

const DB_FILE = __DIR__ . '/data/bot.sqlite';

$config = require __DIR__ . '/config.php';
$token = $config['BOT_TOKEN'];
$adminId = (string)$config['ADMIN_ID'];

if (!is_dir(__DIR__ . '/data')) mkdir(__DIR__ . '/data', 0755, true);

$pdo = new PDO('sqlite:' . DB_FILE);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY,
    username TEXT,
    first_name TEXT,
    balance INTEGER NOT NULL DEFAULT 0,
    last_bonus INTEGER NOT NULL DEFAULT 0,
    last_work INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)");

function tg(string $method, array $data = []): array {
    global $token;
    $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $out = json_decode($raw ?: '{}', true);
    return is_array($out) ? $out : [];
}

function money(int $n): string {
    return number_format($n, 0, '.', '.');
}
function now(): int { return time(); }

function upsertUser(array $u): void {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO users(id,username,first_name,created_at)
        VALUES(:id,:username,:first_name,:created_at)
        ON CONFLICT(id) DO UPDATE SET username=:username, first_name=:first_name");
    $stmt->execute([
        ':id'=>(int)$u['id'],
        ':username'=>$u['username'] ?? '',
        ':first_name'=>$u['first_name'] ?? 'Игрок',
        ':created_at'=>now()
    ]);
}
function user(int $id): array {
    global $pdo;
    $s=$pdo->prepare("SELECT * FROM users WHERE id=?"); $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: [];
}
function changeBalance(int $id, int $delta): int {
    global $pdo;
    $s=$pdo->prepare("UPDATE users SET balance=balance+? WHERE id=?"); $s->execute([$delta,$id]);
    return (int)user($id)['balance'];
}
function send(int $chat, string $text, ?array $keyboard=null): void {
    $d=['chat_id'=>$chat,'text'=>$text,'parse_mode'=>'HTML'];
    if ($keyboard) $d['reply_markup']=json_encode(['inline_keyboard'=>$keyboard], JSON_UNESCAPED_UNICODE);
    tg('sendMessage',$d);
}
function mainMenu(): array {
    return [
        [['text'=>'💰 Профиль','callback_data'=>'profile'],['text'=>'💼 Работа','callback_data'=>'work']],
        [['text'=>'🎁 Бонус','callback_data'=>'bonus'],['text'=>'🔫 Ограбить','callback_data'=>'rob']],
        [['text'=>'🏪 Магазин','callback_data'=>'shop'],['text'=>'🎰 Казино','callback_data'=>'casino']],
    ];
}
function isAdmin(int $id): bool { global $adminId; return $adminId !== '' && (string)$id === $adminId; }

$update=json_decode(file_get_contents('php://input'),true) ?: [];
$message=$update['message'] ?? null;
$callback=$update['callback_query'] ?? null;

if ($message) {
    $from=$message['from']; $chat=(int)$message['chat']['id']; $text=trim((string)($message['text'] ?? ''));
    upsertUser($from); $u=user((int)$from['id']);

    if (str_starts_with($text,'/start')) {
        send($chat,"👋 <b>Добро пожаловать в «Работу Бандита»!</b>\n\nТут всё просто: работай, рискуй, ограбляй и копи 💰 бабки.\n\nТвой баланс: <b>💰 ".money((int)$u['balance'])."</b>",mainMenu());
    } elseif ($text==='/balance' || $text==='/profile') {
        send($chat,"👤 <b>Профиль</b>\n\n🆔 <code>{$from['id']}</code>\n👤 ".htmlspecialchars($from['first_name'] ?? 'Игрок')."\n\n💰 Баланс: <b>".money((int)$u['balance'])." бабок</b>",mainMenu());
    } elseif ($text==='/work') {
        doWork($chat,(int)$from['id']);
    } elseif ($text==='/bonus') {
        doBonus($chat,(int)$from['id']);
    } elseif (isAdmin((int)$from['id']) && preg_match('/^\/give\s+(\d+)\s+(\d+)$/',$text,$m)) {
        $target=(int)$m[1]; $amount=(int)$m[2];
        if (!user($target)) send($chat,"❌ Пользователь не найден. Он должен сначала написать /start.");
        else { $b=changeBalance($target,$amount); send($chat,"✅ Выдано <b>".money($amount)." бабок</b>.\nНовый баланс: 💰 ".money($b)); }
    } elseif (isAdmin((int)$from['id']) && preg_match('/^\/take\s+(\d+)\s+(\d+)$/',$text,$m)) {
        $target=(int)$m[1]; $amount=(int)$m[2];
        if (!user($target)) send($chat,"❌ Пользователь не найден.");
        else { $b=changeBalance($target,-$amount); send($chat,"✅ Снято <b>".money($amount)." бабок</b>.\nНовый баланс: 💰 ".money(max(0,$b))); }
    } else {
        send($chat,"Выбери действие 👇",mainMenu());
    }
}

if ($callback) {
    $id=(int)$callback['from']['id']; $chat=(int)$callback['message']['chat']['id']; $data=$callback['data'];
    tg('answerCallbackQuery',['callback_query_id'=>$callback['id']]);
    upsertUser($callback['from']);
    if ($data==='profile') {
        $u=user($id); send($chat,"👤 <b>Твой профиль</b>\n\n💰 Бабки: <b>".money((int)$u['balance'])."</b>\n🆔 <code>{$id}</code>",mainMenu());
    } elseif ($data==='work') doWork($chat,$id);
    elseif ($data==='bonus') doBonus($chat,$id);
    elseif ($data==='rob') doRob($chat,$id);
    elseif ($data==='shop') send($chat,"🏪 <b>Магазин</b>\n\n🔫 Пистолет — 💰 25.000\n🛡 Броня — 💰 50.000\n🚗 Машина — 💰 250.000\n\nМагазин подготовлен для расширения.",mainMenu());
    elseif ($data==='casino') doCasino($chat,$id);
}
function doWork(int $chat,int $id): void {
    global $pdo;
    $u=user($id); $wait=300; $left=$wait-(now()-(int)$u['last_work']);
    if ($left>0) { send($chat,"⏳ Ты уже работал. Подожди <b>".ceil($left/60)." мин.</b>"); return; }
    $amount=random_int(5000,15000);
    $s=$pdo->prepare("UPDATE users SET balance=balance+?,last_work=? WHERE id=?"); $s->execute([$amount,now(),$id]);
    send($chat,"💼 <b>Работа выполнена!</b>\n\nТы получил: <b>💰 ".money($amount)." бабок</b>\nБаланс: 💰 ".money((int)user($id)['balance']),mainMenu());
}
function doBonus(int $chat,int $id): void {
    global $pdo;
    $u=user($id); $wait=86400; $left=$wait-(now()-(int)$u['last_bonus']);
    if ($left>0) { send($chat,"🎁 Бонус уже получен. Возвращайся через <b>".ceil($left/3600)." ч.</b>"); return; }
    $amount=25000;
    $s=$pdo->prepare("UPDATE users SET balance=balance+?,last_bonus=? WHERE id=?"); $s->execute([$amount,now(),$id]);
    send($chat,"🎁 <b>Ежедневный бонус!</b>\n\nТы получил: 💰 <b>".money($amount)." бабок</b>",mainMenu());
}
function doRob(int $chat,int $id): void {
    global $pdo;
    $u=user($id); $cost=5000;
    if ((int)$u['balance']<$cost) { send($chat,"🔫 Для ограбления нужно минимум 💰 ".money($cost)." бабок."); return; }
    $win=random_int(1,100);
    $delta=$win<=45 ? random_int(5000,25000) : -random_int(3000,15000);
    $pdo->prepare("UPDATE users SET balance=MAX(0,balance+?) WHERE id=?")->execute([$delta,$id]);
    $label=$delta>=0 ? "💰 Ты забрал ".money($delta)." бабок!" : "💸 Провал! Ты потерял ".money(abs($delta))." бабок.";
    send($chat,"🔫 <b>Ограбление</b>\n\n{$label}\n\nБаланс: 💰 <b>".money((int)user($id)['balance'])."</b>",mainMenu());
}
function doCasino(int $chat,int $id): void {
    global $pdo;
    $u=user($id); $bet=5000;
    if ((int)$u['balance']<$bet) { send($chat,"🎰 Минимальная ставка — 💰 ".money($bet)." бабок."); return; }
    $win=random_int(1,100)<=45;
    $delta=$win ? $bet : -$bet;
    $pdo->prepare("UPDATE users SET balance=balance+? WHERE id=?")->execute([$delta,$id]);
    send($chat,$win ? "🎰 <b>ДЖЕКПОТ!</b>\n\nТы выиграл 💰 ".money($bet)." бабок!" : "🎰 Не повезло...\n\nТы проиграл 💰 ".money($bet)." бабок.",mainMenu());
}
?>
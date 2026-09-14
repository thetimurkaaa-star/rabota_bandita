-- schema.sql
PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY,
    username TEXT,
    first_name TEXT NOT NULL DEFAULT '',
    balance INTEGER NOT NULL DEFAULT 10000 CHECK(balance >= 0),
    strength INTEGER NOT NULL DEFAULT 1 CHECK(strength BETWEEN 1 AND 100),
    level INTEGER NOT NULL DEFAULT 1 CHECK(level >= 1),
    xp INTEGER NOT NULL DEFAULT 0 CHECK(xp >= 0),
    registered_at INTEGER NOT NULL,
    last_seen_at INTEGER NOT NULL,
    last_daily_at INTEGER NOT NULL DEFAULT 0,
    protection_until INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS inventory (
    user_id INTEGER NOT NULL,
    item_key TEXT NOT NULL,
    quantity INTEGER NOT NULL DEFAULT 0 CHECK(quantity >= 0),
    PRIMARY KEY(user_id, item_key),
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS shops (
    user_id INTEGER PRIMARY KEY,
    level INTEGER NOT NULL DEFAULT 1 CHECK(level >= 1),
    stored_income INTEGER NOT NULL DEFAULT 0 CHECK(stored_income >= 0),
    last_income_at INTEGER NOT NULL,
    last_robbed_at INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS casino_games (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    game TEXT NOT NULL,
    bet INTEGER NOT NULL,
    result TEXT NOT NULL,
    payout INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS robberies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    attacker_id INTEGER NOT NULL,
    target_id INTEGER NOT NULL,
    type TEXT NOT NULL DEFAULT 'player',
    result TEXT NOT NULL,
    amount INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL,
    FOREIGN KEY(attacker_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(target_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    amount INTEGER NOT NULL,
    balance_after INTEGER NOT NULL,
    reason TEXT NOT NULL,
    actor_id INTEGER,
    created_at INTEGER NOT NULL,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS admins (
    user_id INTEGER PRIMARY KEY,
    role TEXT NOT NULL DEFAULT 'admin' CHECK(role IN ('admin','main')),
    added_at INTEGER NOT NULL,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS achievements (
    key TEXT PRIMARY KEY,
    title TEXT NOT NULL,
    description TEXT NOT NULL,
    reward_money INTEGER NOT NULL DEFAULT 0,
    reward_xp INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS user_achievements (
    user_id INTEGER NOT NULL,
    achievement_key TEXT NOT NULL,
    earned_at INTEGER NOT NULL,
    PRIMARY KEY(user_id, achievement_key),
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(achievement_key) REFERENCES achievements(key) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    job_key TEXT NOT NULL,
    reward INTEGER NOT NULL,
    xp INTEGER NOT NULL,
    created_at INTEGER NOT NULL,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS broadcast_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_id INTEGER NOT NULL,
    message TEXT NOT NULL,
    sent_count INTEGER NOT NULL DEFAULT 0,
    failed_count INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL,
    FOREIGN KEY(admin_id) REFERENCES users(id) ON DELETE CASCADE
);

INSERT OR IGNORE INTO settings(key,value) VALUES
('work_cooldown','3600'),
('robbery_cooldown','86400'),
('shop_robbery_cooldown','259200'),
('daily_bonus','5000'),
('casino_max_bet','1000000'),
('xp_work','20'),
('xp_robbery','35'),
('xp_casino','10'),
('xp_shop','30');

INSERT OR IGNORE INTO achievements VALUES
('first_work','💼 Первая работа','Выполни первую работу',1000,20),
('first_robbery','🚨 Первое ограбление','Соверши первое ограбление',2000,50),
('millionaire','💰 Миллионер','Накопи 1.000.000',10000,100),
('strength_100','💪 Максимальная сила','Достигни силы 100',25000,250),
('casino_100','🎰 Азартный игрок','Сыграй 100 игр в казино',15000,150),
('shop_10','🏪 Магнат','Улучши магазин до 10 уровня',25000,200);

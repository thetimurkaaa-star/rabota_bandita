CREATE TABLE IF NOT EXISTS users (
    id BIGINT PRIMARY KEY,
    username TEXT,
    first_name TEXT NOT NULL DEFAULT '',
    balance BIGINT NOT NULL DEFAULT 10000 CHECK(balance >= 0),
    strength INTEGER NOT NULL DEFAULT 1 CHECK(strength BETWEEN 1 AND 100),
    level INTEGER NOT NULL DEFAULT 1 CHECK(level >= 1),
    xp INTEGER NOT NULL DEFAULT 0 CHECK(xp >= 0),
    registered_at BIGINT NOT NULL,
    last_seen_at BIGINT NOT NULL,
    last_daily_at BIGINT NOT NULL DEFAULT 0,
    protection_until BIGINT NOT NULL DEFAULT 0,
    respect BIGINT NOT NULL DEFAULT 0 CHECK(respect >= 0),
    vip_tier INTEGER NOT NULL DEFAULT 0 CHECK(vip_tier BETWEEN 0 AND 5),
    vip_until BIGINT NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS inventory (
    user_id BIGINT NOT NULL,
    item_key TEXT NOT NULL,
    quantity INTEGER NOT NULL DEFAULT 0 CHECK(quantity >= 0),
    PRIMARY KEY(user_id, item_key),
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS shops (
    user_id BIGINT PRIMARY KEY,
    level INTEGER NOT NULL DEFAULT 1 CHECK(level >= 1),
    stored_income BIGINT NOT NULL DEFAULT 0 CHECK(stored_income >= 0),
    last_income_at BIGINT NOT NULL,
    last_robbed_at BIGINT NOT NULL DEFAULT 0,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS casino_games (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL,
    game TEXT NOT NULL,
    bet BIGINT NOT NULL,
    result TEXT NOT NULL,
    payout BIGINT NOT NULL DEFAULT 0,
    created_at BIGINT NOT NULL,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS robberies (
    id BIGSERIAL PRIMARY KEY,
    attacker_id BIGINT NOT NULL,
    target_id BIGINT NOT NULL,
    type TEXT NOT NULL DEFAULT 'player',
    result TEXT NOT NULL,
    amount BIGINT NOT NULL DEFAULT 0,
    created_at BIGINT NOT NULL,
    FOREIGN KEY(attacker_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(target_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS transactions (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL,
    amount BIGINT NOT NULL,
    balance_after BIGINT NOT NULL,
    reason TEXT NOT NULL,
    actor_id BIGINT,
    created_at BIGINT NOT NULL,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS admins (
    user_id BIGINT PRIMARY KEY,
    role TEXT NOT NULL DEFAULT 'admin' CHECK(role IN ('admin','main')),
    added_at BIGINT NOT NULL
    -- user_id intentionally has no FK: main admin can add a player before /start.
);
CREATE TABLE IF NOT EXISTS achievements (
    key TEXT PRIMARY KEY,
    title TEXT NOT NULL,
    description TEXT NOT NULL,
    reward_money BIGINT NOT NULL DEFAULT 0,
    reward_xp INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS user_achievements (
    user_id BIGINT NOT NULL,
    achievement_key TEXT NOT NULL,
    earned_at BIGINT NOT NULL,
    PRIMARY KEY(user_id, achievement_key),
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(achievement_key) REFERENCES achievements(key) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS jobs (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL,
    job_key TEXT NOT NULL,
    reward BIGINT NOT NULL,
    xp INTEGER NOT NULL,
    created_at BIGINT NOT NULL,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS player_state (
    user_id BIGINT PRIMARY KEY,
    casino_game TEXT NOT NULL DEFAULT 'slots',
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS broadcast_log (
    id BIGSERIAL PRIMARY KEY,
    admin_id BIGINT NOT NULL,
    message TEXT NOT NULL,
    sent_count INTEGER NOT NULL DEFAULT 0,
    failed_count INTEGER NOT NULL DEFAULT 0,
    created_at BIGINT NOT NULL,
    FOREIGN KEY(admin_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS chats (
    chat_id BIGINT PRIMARY KEY,
    title TEXT NOT NULL DEFAULT '',
    username TEXT,
    type TEXT NOT NULL DEFAULT 'group',
    is_active INTEGER NOT NULL DEFAULT 1,
    can_send INTEGER NOT NULL DEFAULT 1,
    member_count INTEGER NOT NULL DEFAULT 0,
    added_at BIGINT NOT NULL,
    updated_at BIGINT NOT NULL
);
CREATE TABLE IF NOT EXISTS chat_activity (
    chat_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    activity_date TEXT NOT NULL,
    message_count INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY(chat_id, user_id, activity_date),
    FOREIGN KEY(chat_id) REFERENCES chats(chat_id) ON DELETE CASCADE,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS admin_logs (
    id BIGSERIAL PRIMARY KEY,
    admin_id BIGINT NOT NULL,
    action TEXT NOT NULL,
    details TEXT NOT NULL DEFAULT '',
    created_at BIGINT NOT NULL,
    FOREIGN KEY(admin_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS events (
    id BIGSERIAL PRIMARY KEY,
    title TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    reward_money BIGINT NOT NULL DEFAULT 0,
    reward_respect BIGINT NOT NULL DEFAULT 0,
    winners_count INTEGER NOT NULL DEFAULT 1,
    ends_at BIGINT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_by BIGINT NOT NULL,
    created_at BIGINT NOT NULL,
    FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS event_participants (
    event_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    joined_at BIGINT NOT NULL,
    PRIMARY KEY(event_id, user_id),
    FOREIGN KEY(event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS vip_tasks (
    id BIGSERIAL PRIMARY KEY,
    title TEXT NOT NULL,
    task_type TEXT NOT NULL CHECK(task_type IN ('work','robbery','chat')),
    target_count INTEGER NOT NULL CHECK(target_count > 0),
    reward_money BIGINT NOT NULL DEFAULT 0,
    reward_respect BIGINT NOT NULL DEFAULT 0,
    min_vip INTEGER NOT NULL DEFAULT 1 CHECK(min_vip BETWEEN 1 AND 5),
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at BIGINT NOT NULL
);
CREATE TABLE IF NOT EXISTS vip_task_progress (
    task_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    progress INTEGER NOT NULL DEFAULT 0,
    period_key TEXT NOT NULL,
    completed INTEGER NOT NULL DEFAULT 0,
    claimed INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY(task_id, user_id, period_key),
    FOREIGN KEY(task_id) REFERENCES vip_tasks(id) ON DELETE CASCADE,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

INSERT INTO settings(key,value) VALUES
('work_cooldown','3600'),('robbery_cooldown','86400'),('shop_robbery_cooldown','259200'),
('daily_bonus','5000'),('casino_max_bet','1000000'),('xp_work','20'),('xp_robbery','35'),
('xp_casino','10'),('xp_shop','30'),
('welcome_info',E'👋 <b>Добро пожаловать в «Работу Бандита»!</b>\n\nЗдесь ты можешь работать, развиваться, покупать бизнесы и участвовать в событиях.\n\n📌 Используй меню бота, чтобы начать.'),
('active_chat_days','1') ON CONFLICT(key) DO NOTHING;

INSERT INTO achievements VALUES
('first_work','💼 Первая работа','Выполни первую работу',1000,20),
('first_robbery','🚨 Первое ограбление','Соверши первое ограбление',2000,50),
('millionaire','💰 Миллионер','Накопи 1.000.000',10000,100),
('strength_100','💪 Максимальная сила','Достигни силы 100',25000,250),
('casino_100','🎰 Азартный игрок','Сыграй 100 игр в казино',15000,150),
('shop_10','🏪 Магнат','Улучши магазин до 10 уровня',25000,200) ON CONFLICT(key) DO NOTHING;

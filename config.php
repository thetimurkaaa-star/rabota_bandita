<?php
// config.php
// Заполни токен бота и Telegram ID главного администратора.
const BOT_TOKEN = 'PUT_YOUR_BOT_TOKEN_HERE';
const MAIN_ADMIN_ID = 123456789;

// SQLite-файл. На Railway укажи постоянный Volume и измени путь,
// например: /data/bot.sqlite
const DB_PATH = __DIR__ . '/bot.sqlite';

const CURRENCY = '💰';
const MAX_STRENGTH = 100;
const START_BALANCE = 10000;
const START_STRENGTH = 1;
const START_LEVEL = 1;
const START_XP = 0;

// Секрет для webhook можно оставить пустым, если используешь обычный polling.
const WEBHOOK_SECRET = '';

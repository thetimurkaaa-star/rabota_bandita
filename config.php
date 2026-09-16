<?php
// Токен хранится в Railway Variables, а не в GitHub.
$token = getenv('BOT_TOKEN') ?: getenv('TELEGRAM_BOT_TOKEN');
if (!$token) throw new RuntimeException('BOT_TOKEN is not configured in Railway Variables.');
define('BOT_TOKEN', $token);

const MAIN_ADMIN_ID = 1282393103;
const CURRENCY = '💰';
const MAX_STRENGTH = 100;
const START_BALANCE = 10000;
const START_STRENGTH = 1;
const START_LEVEL = 1;
const START_XP = 0;
const WEBHOOK_SECRET = '';

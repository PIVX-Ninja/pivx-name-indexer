<?php declare(strict_types=1);

$templatePath = '/opt/pivx-name-indexer/config/release.inc.php.sample';
$outputPath = '/opt/pivx-name-indexer/config/docker.inc.php';

if (!is_file($templatePath)) {
    echo "Error: Template config sample not found at $templatePath\n";
    exit(1);
}

$template = file_get_contents($templatePath);

$replacements = [
    '{:db_user:}' => getenv('DB_USER') ?: 'namedbuser',
    '{:db_password:}' => getenv('DB_PASSWORD') ?: '',
    '{:db_name:}' => getenv('DB_NAME') ?: 'pivx-name',
    '{:rpc_host:}' => getenv('RPC_HOST') ?: '127.0.0.1',
    '{:rpc_port:}' => getenv('RPC_PORT') ?: '51473',
    '{:rpc_user:}' => getenv('RPC_USER') ?: '',
    '{:rpc_pass:}' => getenv('RPC_PASS') ?: '',
    '{:notify_type:}' => getenv('NOTIFY_TYPE') ?: 'telegram',
    '{:notify_tg_bot_key:}' => getenv('NOTIFY_TG_BOT_KEY') ?: '',
    '{:notify_tg_user_id:}' => getenv('NOTIFY_TG_USER_ID') ?: '',
    '{:logs_path:}' => getenv('LOGS_PATH') ?: '/var/log/indexer',
];

$content = strtr($template, $replacements);

if (file_put_contents($outputPath, $content) === false) {
    echo "Error: Failed to write config to $outputPath\n";
    exit(1);
}

echo "Successfully generated config file at $outputPath\n";
exit(0);

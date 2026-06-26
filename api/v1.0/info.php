<?php declare(strict_types=1);

use Indexer\Database;

/**
 * @var array $uri
 * @var Database $db
 */

if (isset($uri[2])) {
    throw new RuntimeException(API_ERROR_WRONG_API_METHOD, INT_EXC_API_ERROR);
}

$out = getParams(
    [
        PARAM_LAST_PROCESSED_EVM_BLOCK,
        PARAM_LAST_PROCESSED_PIVX_BLOCK
    ],
    $db
);

$qr = $db->doSelect(
    'checkpoints',
    '*',
    [],
    'ORDER BY `evm_block_id` DESC LIMIT 1',
);
if ($qr === false) {
    throw new RuntimeException(API_ERROR_INTERNAL, INT_EXC_DB);
}
if ($qr->num_rows !== 0) {
    $out['last_checkpoint'] = $qr->fetch_assoc();
}

$qr = $db->doSelect(
    'domains',
    'COUNT(*) AS `count`',
);
if ($qr === false) {
    throw new RuntimeException(API_ERROR_INTERNAL, INT_EXC_DB);
}
if ($qr->num_rows !== 0) {
    $out['domains_count'] = $qr->fetch_assoc()['count'];
}

$out['registrar_viewing_key'] = INCOME_WALLET_VK;

apiAnswer('response', $out);

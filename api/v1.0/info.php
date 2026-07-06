<?php declare(strict_types=1);

use Indexer\Database;
use Indexer\RustRocksDBProxy;
use Indexer\SparseMerkleTree;

/**
 * @var array $uri
 * @var Database $db
 */

if (isset($uri[2])) {
    throw new RuntimeException(API_ERROR_WRONG_API_METHOD, INT_EXC_API_ERROR);
}

$extended = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($raw = file_get_contents('php://input')) !== '') {
        $input = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
    }
    $extended = $input['extended'] ?? false;
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

// get latest indexer SMT Root
$rocksDb = new RustRocksDBProxy(INDEXER_ROCKSDB_PROXY_SOCKET_PATH);
$smt = new SparseMerkleTree($rocksDb);
$out['indexer_smt_root'] = bin2hex(pack('C*', ...$smt->getRoot()));
$out['indexer_synced'] = $out['indexer_smt_root'] === $out['last_checkpoint']['smt_root'];

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

if ($extended) {
    $out['registrar_address'] = INCOME_WALLET;
    $out['registrar_viewing_key'] = INCOME_WALLET_VK;
}

apiAnswer('response', $out);

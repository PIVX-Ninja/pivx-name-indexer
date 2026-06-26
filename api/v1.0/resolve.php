<?php declare(strict_types=1);

use Indexer\Database;
use Indexer\RustRocksDBProxy;
use Indexer\SparseMerkleTree;
use Indexer\Protocol;

/**
 * @var array $uri
 * @var Database $db
 */

$lookupType = 'name';
if (!Protocol::isDomainNameValid($domainName = strtolower($uri[2] ?? ''))) {
    if (!isset($uri[3]) || !in_array($uri[2], ['reverse', 'owner'], true) ||
        ($uri[3] === 'reverse' && Protocol::isAddressValid($domainName)) ||
        ($uri[3] === 'owner' && !Protocol::isValidEd25519Pubkey($domainName))) {
        throw new RuntimeException(API_ERROR_WRONG_INCOMING_PARAM_VALUE, INT_EXC_API_ERROR);
    }
    [,,$lookupType, $domainName] = $uri;
}

$qAdd = '';
$request = [
    'extended' => false,
    'with_checkpoint' => false,
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($raw = file_get_contents('php://input')) !== '') {
        $input = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
    }
    $qAdd = '';
    if (isset($input['extended']) && $input['extended'] === true) {
        $request['extended'] = true;
        $qAdd .= ', `domains`.`created_block_id`, `domains`.`updated_block_id`, `domains_history`.`domain_tx`';
    }

    if (isset($input['with_checkpoint']) && is_bool($input['with_checkpoint'])) {
        $request['with_checkpoint'] = $input['with_checkpoint'];
    }
}

$where = match ($lookupType) {
    'name' => ' `domains`.`domain_name` = {:target:}',
    'reverse' => ' `domains_history`.`target_address` = {:target:}',
    'owner' => ' `domains_history`.`owner_pubkey` = {:target:}',
    default => throw new RuntimeException(API_ERROR_INTERNAL)
};
$map = ['{:target:}' => $domainName];

$qr = $db->doPlainQuery(
    'SELECT `domains`.`domain_name`, `domains_history`.`target_address`, `domains_history`.`owner_pubkey`' . $qAdd .
    ' FROM `domains`
    LEFT JOIN `domains_history` ON `domains_history`.`domain_name` = `domains`.`domain_name`
                                AND `domains_history`.`domain_block_id` = `domains`.`updated_block_id`
    WHERE ' . $where,
    $map
);

if ($qr === false) {
    throw new RuntimeException(API_ERROR_INTERNAL, INT_EXC_DB);
}
if ($qr->num_rows === 0) {
    throw new RuntimeException(API_ERROR_NOT_FOUND, INT_EXC_API_ERROR);
}

$dbData = $qr->fetch_all(MYSQLI_ASSOC);

$rocksDb = new RustRocksDBProxy(INDEXER_ROCKSDB_PROXY_SOCKET_PATH);
$smt = new SparseMerkleTree($rocksDb);

$out = [];
foreach ($dbData as $oneDomain) {
    $out[] = $oneDomain;
    $index = array_key_last($out);

    // Fetch the proof (Returns an array of 128 raw byte arrays)
    $rawProof = $smt->getProof($oneDomain['domain_name']);
    // Convert the raw bytes to a clean JSON array of Hex strings for the API
    $hexProof = [];
    foreach ($rawProof as $siblingBytes) {
        // Pack the bytes back into a string, then convert to hex
        $hexProof[] = bin2hex(pack('C*', ...$siblingBytes));
    }
    // Get the current root
    if (!isset($input['with_checkpoint']) || $input['with_checkpoint'] === false) {
        $rootBytes = $smt->getRoot();
        $out[$index] += [
            'smt_root' => bin2hex(pack('C*', ...$rootBytes)),
        ];
    }

    $out[$index] += [
        'merkle_proof' => $hexProof
    ];

    if ($request['with_checkpoint']) {
        $qr = $db->doSelect(
            'checkpoints',
            ['block_id', 'smt_root', 'evm_block_id', 'evm_tx_hash'],
            ['block_id' => ['sign' => '>=', 'value' => $oneDomain['updated_block_id']]],
            'ORDER BY `block_id` DESC LIMIT 1',
        );
        if ($qr === false) {
            throw new RuntimeException(API_ERROR_INTERNAL, INT_EXC_DB);
        }
        $out[$index]['checkpoint'] = $qr->fetch_assoc();
    }
}

apiAnswer('response', $lookupType !== 'name' ? $out : $out[0]);

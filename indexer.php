<?php declare(strict_types=1);

use Indexer\Database;
use Indexer\Evm;
use Indexer\EvmRpcException;
use Indexer\Protocol;
use Indexer\RustRocksDBProxy;
use Indexer\SparseMerkleTree;
use Indexer\LockFileUtils;
use Denpa\Bitcoin\Client as RpcClient;
use Denpa\Bitcoin\Exceptions\BadRemoteCallException;
use Denpa\Bitcoin\Responses\BitcoindResponse;
use Telegram\Bot\Api;

require './vendor/autoload.php';
require __DIR__ . '/config.php';
require __DIR__ . '/include/functions.php';

$db = new Database();
Protocol::init($db);
$rocksDb = new RustRocksDBProxy(INDEXER_ROCKSDB_PROXY_SOCKET_PATH);
$smt = new SparseMerkleTree($rocksDb);

$command = $argv[1] ?? '';

try {
    if ($command === 'clean') {
        if (($argv[2] ?? '') !== 'confirm') {
            echo 'Warning!!!' . PHP_EOL .
                'You\'re about to clean all the databases to prepare it for the from-the-scratch synchronization.' . PHP_EOL .
                'Make sure you understand what you\'re doing! Please add "confirm" word after the "clean" command to process.' . PHP_EOL;
            exit;
        }
        foreach (INDEXER_DB_TABLES as $oneTable) {
            if ($db->query('TRUNCATE TABLE `' . $oneTable . '`') === false) {
                throw new RuntimeException('Can\'t clean the table "' . $oneTable . '"', INT_EXC_DB);
            }
        }
        if ($db->doInsert(
            'checkpoints',
            [
                'block_id' => PIVX_GENESIS_BLOCK,
                'smt_root' => SMT_GENESIS_ROOT,
                'evm_block_id' => EVM_GENESIS_BLOCK,
                'evm_tx_hash' => EVM_GENESIS_TX_HASH
            ]
        ) === false) {
            throw new RuntimeException('Can\'t insert Genesis Root to checkpoints', INT_EXC_DB);
        }

        setParams([
            PARAM_LAST_PROCESSED_EVM_BLOCK => EVM_GENESIS_BLOCK,
            PARAM_LAST_PROCESSED_PIVX_BLOCK => PIVX_GENESIS_BLOCK,
        ], $db);

        if ($rocksDb->clear() === false) {
            throw new RuntimeException('Can\'t clean RocksDB database', INT_EXC_DB);
        }

        echo 'The database has been successfully cleaned.' . PHP_EOL;
        exit;
    }
    if ($command !== 'sync') {
        echo '--== PIVX Domain name Indexer ==--' . PHP_EOL .
            'Supported commands:' . PHP_EOL .
            'sync - start the indexer sync iteration (the way it\'s started from Systemd timer service)' . PHP_EOL .
            'clean - perform the database clean for fresh initialization from on-chain data' . PHP_EOL;
        exit;
    }

    // Lock the execution more than one script at one moment
    if (LockFileUtils::setLock(__DIR__ . '/resources/sync.lock') === false) {
        if ($_SERVER['REQUEST_TIME'] - filemtime(__DIR__ . '/resources/sync.lock') > 600) {
            throw new RuntimeException(
                'Can\'t set the synchronization lock. Is Indexer already executing?',
                INT_EXC_FATAL
            );
        }
        exit;
    }

    // check if we have at least one RPC_URL added to work with
    if (($qr = $db->doSelect(
        'rpc_list',
        '`rpc_url`',
        ['chain_id' => EVM_NETWORK['chain_id']]
    )) === false) {
        throw new RuntimeException('Can\'t query rpc_list', INT_EXC_DB);
    }
    if ($qr->num_rows === 0) {
        throw new RuntimeException(
            'Can\'t start. Please, add at least one RPC endpoint (rpc_list DB table)',
            INT_EXC_FATAL
        );
    }

    $evm = new Evm(EVM_NETWORK, $db);

    if (($qr = $db->doSelect(
        'checkpoints',
        ['block_id', 'smt_root', 'evm_block_id'],
        [],
        'ORDER BY `evm_block_id` DESC LIMIT 1'
    )) === false) {
        throw new RuntimeException('Can\'t query checkpoints', INT_EXC_DB);
    }
    if ($qr->num_rows === 0) {
        throw new RuntimeException(
            'Genesis SMT root is not found in the database. It seems the initialization with "clean" command hasn\'t been executed properly.',
            INT_EXC_FATAL
        );
    }
    $checkpoint = $qr->fetch_assoc();

    // Check the data consistency in MySQL and RocksDB (if there's any uncommited MySQL transaction).
    // It can happen when commit to RocksDB or to MySQL on the last sync has failed.
    checkUncommitedXATX('indexer', $db, $smt, 'indexer.debug');

    $requestedParams = [PARAM_LAST_PROCESSED_EVM_BLOCK, PARAM_LAST_PROCESSED_PIVX_BLOCK];
    $params = getParams($requestedParams, $db);
    if (count($params) !== count($requestedParams)) {
        throw new RuntimeException(
            'One or several required parameters are absent in "params" table.',
            INT_EXC_FATAL
        );
    }

    $insData = [];
    $evmFromBlock = max(
        $params[PARAM_LAST_PROCESSED_EVM_BLOCK],
        $checkpoint['evm_block_id'],
        EVM_GENESIS_BLOCK - 1 // -1 is for sync logic to start from last_block + 1
    );
    $evmFromBlock++;

    // syncing checkpoints from EVM
    if (($evmCurrBlock = $evm->getBlockCount()) > $evmFromBlock) {
        while ($evmCurrBlock >= $evmFromBlock) {
            $evmToBlock = min($evmFromBlock + EVM_SYNC_BLOCK_STEP, $evmCurrBlock);
            $logData = $evm->getEventLogs('RootUpdated', $evmFromBlock, $evmToBlock);
            $logDataLast = array_key_last($logData);
            if ($logData !== []) {
                logEvent('There is some new data to process...', 'EVM checkpoints sync', 'indexer.debug');
            }
            foreach ($logData as $entryNumber => $oneLogEntry) {
                $oneInsData = [
                    'block_id' => $oneLogEntry['data']['endBlockHeight']->toString(),
                    'smt_root' => substr($oneLogEntry['data']['newRoot'], 2),
                    'evm_block_id' => $oneLogEntry['blockNumber'],
                    'evm_tx_hash' => $oneLogEntry['transactionHash'],
                ];
                $insData[] = $oneInsData;

                $checkpoint['evm_block_id'] = $oneInsData['evm_block_id'];
                $checkpoint['block_id'] = $oneInsData['block_id'];
                $checkpoint['smt_root'] = $oneInsData['smt_root'];

                // debug logging
                if (DEBUG) {
                    $debugLogData = array_map(
                        static fn($k, $v) => "$k: $v",
                        array_keys($checkpoint),
                        $checkpoint
                    );
                    logEvent(
                        implode(', ', $debugLogData),
                        'Received new EVM Checkpoint',
                        'indexer.debug'
                    );
                }

                if ($entryNumber === $logDataLast || count($insData) === 200) {
                    if ($db->doBulkInsert(
                        'checkpoints',
                        $insData,
                    ) === false) {
                        throw new RuntimeException('Bulk insert to checkpoints', INT_EXC_DB);
                    }
                    logEvent(
                        'Received checkpoints have been commited to the DB',
                        'EVM Checkpoints',
                        'indexer.debug'
                    );
                    $insData = [];
                }
            }
            setParams([PARAM_LAST_PROCESSED_EVM_BLOCK => $evmToBlock], $db);
            $evmFromBlock = $evmToBlock + 1;
        }
    }

    $rpc = new RpcClient(RPC_CONFIG);

    // check mnsync to prevent false data
    /** @var BitcoindResponse $response */
    $response = $rpc->request('mnsync', 'status');
    $mnSync = $response->get();
    if (!isset($mnSync['RequestedMasternodeAssets']) || $mnSync['RequestedMasternodeAssets'] !== 999) {
        throw new RuntimeException('PIVX Node is not synced. Skip Indexer synchronization iteration...');
    }
    // end check mnsync

    // syncing PIVX blockchain to the latest checkpoint, validating commands, update domains DB and SMT root
    if ($checkpoint['block_id'] > $params[PARAM_LAST_PROCESSED_PIVX_BLOCK]) {
        logEvent(
            'There is new checkpoint block to sync to: ' . $checkpoint['block_id'],
            'PIVX blockchain sync',
            'indexer.debug'
        );

        $pivxSynced = false;
        $pivxLastCheckpointBlockId = $params[PARAM_LAST_PROCESSED_PIVX_BLOCK];
        while ($pivxSynced === false) {
            if (($qr = $db->doSelect(
                'checkpoints',
                ['block_id', 'smt_root'],
                ['block_id' => ['sign' => '>', 'value' => $pivxLastCheckpointBlockId]],
                'ORDER BY `block_id` ASC LIMIT 1'
            )) === false) {
                throw new RuntimeException('Can\'t query next checkpoint for sync', INT_EXC_DB);
            }
            if ($qr->num_rows === 0) {
                $pivxSynced = true;
                logEvent(
                    'Synchronization is ended',
                    'PIVX blockchain sync',
                    'indexer.debug'
                );
                continue;
            }
            $smtOldRoot = bin2hex(pack('C*', ...$smt->getRoot()));
            $lastCheckpoint = $qr->fetch_assoc();

            $newData = [];
            for ($block = ++$pivxLastCheckpointBlockId; $block <= $lastCheckpoint['block_id']; $block++) {
                /** @var BitcoindResponse $response */
                $response = $rpc->request('getblockhash', $block);
                $blockHash = $response->get();
                if (strlen($blockHash) !== 64) {
                    throw new RuntimeException('Got wrong \'getblockhash\' response: ' . $blockHash);
                }

                /** @var BitcoindResponse $response */
                $response = $rpc->request('getblock', $blockHash, 2);
                $blockData = $response->get();

                if (!isset($blockData['tx'], $blockData['confirmations']) ||
                    !is_array($blockData['tx']) || $blockData['tx'] === [] ||
                    !is_int($blockData['confirmations']) || $blockData['confirmations'] < 1 ||
                    (($blockData['hash'] ?? '') !== $blockHash)) {
                    throw new RuntimeException(
                        'TX list of the block "' . $block . '" can\'t absent or be empty'
                    );
                }

                foreach ($blockData['tx'] as $oneBlockTx) {
                    if (!isset($oneBlockTx['vShieldOutput']) || $oneBlockTx['vShieldOutput'] === []) {
                        continue;
                    }

                    try {
                        /** @var BitcoindResponse $response */
                        $response = $rpc->request('viewshieldtransaction', $oneBlockTx['txid']);
                        $saplingTxInfo = $response->get();

                        if (!isset($saplingTxInfo['txid'])) {
                            throw new RuntimeException('Failed to get info of shielded TX: ' . $oneBlockTx['txid']);
                        }
                        // Strict check: Should never happen, just in case
                        if ($saplingTxInfo['txid'] !== $oneBlockTx['txid']) {
                            throw new RuntimeException(
                                'Strange txid "' . $saplingTxInfo['txid'] . '" in shielded tx: ' . $oneBlockTx['txid']
                            );
                        }

                        // Strict check: Should never happen, just in case
                        if (!isset($saplingTxInfo['outputs'])) {
                            if (!isset($saplingTxInfo['inputs'])) {
                                throw new RuntimeException(
                                    'TX "' . $oneBlockTx['txid'] . '" have no inputs/outputs',
                                    INT_EXC_FATAL
                                );
                            }
                            continue;
                        }

                        // Temporary protocol update for testnet addr change. TODO remove in prod
                        Protocol::$block = $block;

                        foreach ($saplingTxInfo['outputs'] as $oneTxOutput) {
                            if ($oneTxOutput['address'] === INCOME_WALLET && isset($oneTxOutput['memoStr']) &&
                                ($txValue = (string)$oneTxOutput['value']) &&
                                ($commandData = Protocol::parseCommand($oneTxOutput['memoStr'], $txValue)) !== []) {
                                // ignore TX with wrong coin amount
                                if (Protocol::paymentAmountValid($commandData, $txValue) === false) {
                                    Protocol::clearDomainState();
                                    continue;
                                }

                                Protocol::commitDomainState($commandData['domain_name']);

                                $commandData['tx_id'] = $oneBlockTx['txid'];
                                $commandData['block_id'] = $block;
                                $newData[] = $commandData;

                                // there can't be more than one output to the same address in one tx
                                break;
                            }
                        }
                    } catch (BadRemoteCallException $e) {
                        if ($e->getCode() === -5 &&
                            ($e->getMessage() === 'Invalid or non-wallet transaction id' ||
                                $e->getMessage() === 'Invalid transaction, no shield data available')) {
                            continue;
                        }
                        throw $e;
                    }
                }
            }

            if ($db->xaTXStart([$smtOldRoot, 'indexer']) === false) {
                throw new RuntimeException('Starting XA-TX', INT_EXC_DB);
            }
            $rocksDb->beginTransaction();

            foreach ($newData as $oneNewData) {
                // --- SMT SEQUENCE STEP A: Take the "Before" Picture ---
                $merkleProof = $smt->getProof($oneNewData['domain_name']);

                if ($oneNewData['op'] === Protocol::OP_REG) {
                    if (($qr = $db->doInsert(
                        'domains',
                        [
                            'domain_name' => $oneNewData['domain_name'],
                            'created_block_id' => $oneNewData['block_id'],
                            'updated_block_id' => $oneNewData['block_id'],
                        ],
                        true
                    )) === false) {
                        throw new RuntimeException('Inserting new domain', INT_EXC_DB);
                    }
                    // Strict check: Should never happen, just in case
                    if ($db->affectedRows() === 0) {
                        throw new RuntimeException(
                            'CRITICAL! Inserting existing domain',
                            INT_EXC_FATAL
                        );
                    }
                } else {
                    if (($qr = $db->doUpdate(
                        'domains',
                        ['updated_block_id' => $oneNewData['block_id']],
                        ['domain_name' => $oneNewData['domain_name']],
                    )) === false) {
                        throw new RuntimeException('Updating domain', INT_EXC_DB);
                    }
                    if ($db->affectedRows() === 0) {
                        throw new RuntimeException(
                            'CRITICAL! The domain "' . $oneNewData['domain_name'] .
                            '" should exist and should be updated!!!',
                            INT_EXC_FATAL
                        );
                    }

                    if ($oneNewData['op'] === Protocol::OP_LST) {
                        if (($qr = $db->doInsert(
                            'marketplace',
                            [
                                'domain_name' => $oneNewData['domain_name'],
                                'price' => $oneNewData['price'],
                                'block_id' => $oneNewData['block_id'],
                            ],
                            true
                        )) === false) {
                            throw new RuntimeException(
                                'Inserting ' . $oneNewData['domain_name'] . ' to marketplace',
                                INT_EXC_DB
                            );
                        }
                        // Strict check: Should never happen, just in case
                        if ($db->affectedRows() === 0) {
                            throw new RuntimeException(
                                'CRITICAL! Inserting existing domain ' . $oneNewData['domain_name'] . ' to marketplace',
                                INT_EXC_FATAL
                            );
                        }
                    } elseif (in_array($oneNewData['op'], [Protocol::OP_ULT, Protocol::OP_BUY], true)) {
                        if (($qr = $db->doDelete(
                            'marketplace',
                            [
                                'domain_name' => $oneNewData['domain_name'],
                            ]
                        )) === false) {
                            throw new RuntimeException(
                                'Deleting ' . $oneNewData['domain_name'] . ' form marketplace',
                                INT_EXC_DB
                            );
                        }
                        // Strict check: Should never happen, just in case
                        if ($db->affectedRows() === 0) {
                            throw new RuntimeException(
                                'CRITICAL! The domain ' . $oneNewData['domain_name'] . ' must be deleted from marketplace',
                                INT_EXC_FATAL
                            );
                        }
                    }
                }

                // common part for any domain update operation: inserting latest and actual domain info
                $oneDomainIns = [
                    'domain_name' => $oneNewData['domain_name'],
                    'domain_block_id' => $oneNewData['block_id'],
                    'target_address' => $oneNewData['target_address'],
                    'owner_pubkey' => $oneNewData['pubkey'],
                    'domain_tx' => $oneNewData['tx_id'],
                    'op' => $oneNewData['op'],
                    'nonce' => $oneNewData['nonce'],
                    'price' => $oneNewData['price'],
                ];

                if (($qr = $db->doInsert(
                    'domains_history',
                    $oneDomainIns
                )) === false) {
                    throw new RuntimeException('Inserting new domain history', INT_EXC_DB);
                }

                // --- SMT SEQUENCE STEP B: Move Time Forward ---
                // Calculate the new hash and update the SMT tree so the next transaction
                // gets the mathematically correct state.
                $newLeafHash = $smt::hashLeaf(
                    $oneNewData['domain_name'],
                    hex2bin($oneNewData['pubkey']),
                    $oneNewData['target_address'],
                    $oneNewData['price'],
                    $oneNewData['nonce'],
                );
                $smt->update($oneNewData['domain_name'], $newLeafHash);

                // debug logging
                if (DEBUG) {
                    $debugLogData = array_map(
                        static fn($k, $v) => "$k: $v",
                        array_keys($oneNewData),
                        $oneNewData
                    );
                    logEvent(
                        implode(', ', $debugLogData),
                        'Received valid domain operation command',
                        'indexer.debug'
                    );
                }
            }

            // update the latest processed block to the latest processed checkpoint
            setParams([PARAM_LAST_PROCESSED_PIVX_BLOCK => $lastCheckpoint['block_id']], $db);

            if ($db->xaTXFinalize(skipCommit: true) === false) {
                throw new RuntimeException('Finalizing DB transaction', INT_EXC_DB);
            }

            $rocksDb->commitTransaction();

            // commiting to MySQL only on successfully commit to the RocksDB
            // if xaTXCommit fails there will be inconsistency which we'll check and fix at the start of synchronization
            if ($db->xaTXCommit() === false) {
                throw new RuntimeException('Commiting DB transaction', INT_EXC_DB);
            }

            // check SMT root is valid at the end of the checkpoint
            if (($lastSMTRoot = bin2hex(pack('C*', ...$smt->getRoot()))) !== $lastCheckpoint['smt_root']) {
                throw new RuntimeException(
                    'SMT root verification failed. Expected: ' . $lastCheckpoint['smt_root'] . ', got: ' . $lastSMTRoot,
                    INT_EXC_FATAL
                );
            }
            logEvent('New SMT root validated: ' . $lastSMTRoot, 'SMT Root', 'indexer.debug');

            $pivxLastCheckpointBlockId = $lastCheckpoint['block_id'];

            // Free up memory after committing the checkpoint
            $rocksDb->clearCache();
        }
    }
} catch (Throwable $e) {
    logEvent(
        $e->getMessage(),
        match ($e->getCode()) {
            INT_EXC_DB => 'DB Error',
            INT_EXC_FATAL => 'FATAL Error',
            default => 'Exception (' . $e->getCode() . ')',
        },
        'indexer.error'
    );
    if ($e instanceof EvmRpcException && isset($evm)) {
        $evm->setRpcUrl(true);
    }

    // send a notification to the Admin
    if ($e->getCode() === INT_EXC_FATAL &&
        NOTIFY_TYPE === 'telegram' && NOTIFY_TG_BOT_KEY !== '' && NOTIFY_TG_USER_ID !== '') {
        try {
            $telegram = new Api(NOTIFY_TG_BOT_KEY);
            $telegram->sendMessage([
                'chat_id' => NOTIFY_TG_USER_ID,
                'text' => 'PIVX Name Indexer error: ' . $e->getMessage()
            ]);
        } catch (Throwable $e) {
            logEvent(
                $e->getMessage(),
                'Telegram sending error',
                'indexer.debug'
            );
        }
    }
}

LockFileUtils::releaseLock(__DIR__ . '/resources/sync.lock');

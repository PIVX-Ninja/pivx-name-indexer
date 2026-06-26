<?php declare(strict_types=1);

namespace Indexer;

use Throwable;
use Exception;
use Web3\Providers\HttpProvider;
use Web3\Contract;
use Web3\Contracts\Ethabi;
use Web3\Eth;
use ReflectionClass;
use RuntimeException;
use InvalidArgumentException;
use Indexer\Interfaces\Web3EthMethods;
use GuzzleHttp\Exception\TransferException;

class Evm
{
    protected Database $db;
    // chain info
    protected int $chainId;
    protected string $contractAddress;
    // RPC
    protected HttpProvider $httpProvider;
    protected float $rpcTimeout = 30.0;
    // contract
    protected Contract $contract;
    /**
     * @var Eth&Web3EthMethods
     */
    protected Eth $eth;
    protected Ethabi $ethAbi;
    protected array $events;

    protected array $eventNamesTypesCached = [];

    /**
     * @param array $networkInfo
     * @param Database $db
     * @throws RuntimeException
     */
    public function __construct(array $networkInfo, Database $db)
    {
        $this->db = $db;

        $this->chainId = $networkInfo['chain_id'];
        $this->contractAddress = $networkInfo['contract_address'];

        $this->setRpcUrl();

        $this->contract = new Contract($this->httpProvider, file_get_contents($networkInfo['abi']));
        $this->events = $this->contract->getEvents();
        $this->eth = $this->contract->getEth();
        $this->ethAbi = $this->contract->getEthabi();
    }

    /**
     * @return string
     * @throws RuntimeException
     */
    protected function getRpcUrl(): string
    {
        $qr = $this->db->doSelect(
            'rpc_list',
            '`rpc_url`',
            ['chain_id' => $this->chainId],
            'ORDER BY `issue_ts` ASC LIMIT 1'
        );
        if ($qr === false || $qr->num_rows !== 1) {
            throw new RuntimeException('DB Error: Unable to retrieve rpc URL', INT_EXC_DB);
        }

        return $this->db->fetchAssoc($qr)['rpc_url'];
    }

    /**
     * @param bool $upd
     * @return void
     */
    public function setRpcUrl(bool $upd = false): void
    {
        if (!isset($this->httpProvider)) {
            $this->httpProvider = new HttpProvider($this->getRpcUrl(), $this->rpcTimeout);
            return;
        }

        if ($upd) {
            $qr = $this->db->doUpdate(
                'rpc_list',
                ['issue_ts' => time()],
                [
                    'rpc_url' => $this->httpProvider->getHost(),
                    'chain_id' => $this->chainId
                ]
            );
            if ($qr === false) {
                throw new RuntimeException('DB Error: Unable to update rpc URL issue_ts', INT_EXC_DB);
            }

            $httpProviderRef = new ReflectionClass($this->httpProvider);
            $httpProviderRef->getProperty('host')->setValue($this->httpProvider, $this->getRpcUrl());
            return;
        }

        throw new RuntimeException('Error: httpProvider exists, but method called without upd');
    }

    /**
     * Inspects errors and classifies them as network/availability issues
     * (EvmRpcException) vs logic/parameter issues (passed through).
     *
     * @param mixed $error
     * @throws EvmRpcException
     * @throws Throwable
     */
    protected function handleRpcError(mixed $error): void
    {
        if ($error === null) {
            return;
        }

        $isRpcUnavailable = false;

        if ($error instanceof Throwable) {
            $message = $error->getMessage();
            
            // Catch Guzzle-level connection/timeout/transport exceptions
            if ($error instanceof TransferException) {
                $isRpcUnavailable = true;
            }
            
            // Check previous exception in case it is wrapped
            $prev = $error->getPrevious();
            if ($prev instanceof TransferException) {
                $isRpcUnavailable = true;
            }
        } else {
            $message = (string)$error;
        }

        // Heuristic patterns for nodes/infrastructure failing
        $lowerMsg = strtolower($message);
        $unavailabilityPatterns = [
            'curl error',
            'connection refused',
            'timed out',
            'timeout',
            'cannot resolve',
            'could not resolve',
            'host unreachable',
            'bad gateway',
            'service unavailable',
            'gateway timeout',
            'too many requests',
            'rate limit',
            'status code 429',
            'status code 502',
            'status code 503',
            'status code 504',
            'cloudflare',
        ];

        foreach ($unavailabilityPatterns as $pattern) {
            if (str_contains($lowerMsg, $pattern)) {
                $isRpcUnavailable = true;
                break;
            }
        }

        if ($isRpcUnavailable) {
            throw new EvmRpcException($message, 0, $error instanceof Throwable ? $error : null);
        }

        if ($error instanceof Throwable) {
            throw $error;
        }
        throw new RuntimeException($message);
    }

    /**
     * @return int
     * @throws Throwable
     */
    public function getBlockCount(): int
    {
        $result = null;
        try {
            $this->eth->blockNumber(function ($err, $data) use (&$result) {
                if ($err !== null) {
                    $this->handleRpcError($err);
                }
                $result = (int)$data->toString();
            });
        } catch (Throwable $e) {
            $this->handleRpcError($e);
        }

        return $result;
    }

    /**
     * @param string $eventName
     * @param string|int $fromBlock
     * @param string|int $toBlock
     * @return array
     * @throws Throwable
     */
    public function getEventLogs(
        string $eventName,
        string|int $fromBlock = 'latest',
        string|int $toBlock = 'latest'
    ): array {
        //try to ensure block numbers are valid together
        if ($fromBlock !== 'latest') {
            if (!is_int($fromBlock) || $fromBlock < 1) {
                throw new InvalidArgumentException('Please make sure fromBlock is a valid block number');
            }
            if ($toBlock !== 'latest' && (!is_int($toBlock) || $fromBlock > $toBlock)) {
                throw new InvalidArgumentException('Please make sure fromBlock is equal or less than toBlock');
            }
        }

        if ($toBlock !== 'latest') {
            if (!is_int($toBlock) || $toBlock < 1) {
                throw new InvalidArgumentException('Please make sure toBlock is a valid block number');
            }
            if ($fromBlock === 'latest') {
                throw new InvalidArgumentException('Please make sure toBlock is equal or greater than fromBlock');
            }
        }

        //ensure the event actually exists before trying to filter for it
        if (!array_key_exists($eventName, $this->events)) {
            throw new InvalidArgumentException("'$eventName' does not exist in the ABI for this contract");
        }

        //indexed and non-indexed event parameters must be treated separately
        //indexed parameters are stored in the 'topics' array
        //non-indexed parameters are stored in the 'data' value
        if (!isset($this->eventNamesTypesCached[$eventName])) {
            $parameterNames = [];
            $parameterTypes = [];
            $indexedNames = [];
            $indexedTypes = [];

            foreach ($this->events[$eventName]['inputs'] as $input) {
                if ($input['indexed']) {
                    $indexedNames[] = $input['name'];
                    $indexedTypes[] = $input['type'];
                } else {
                    $parameterNames[] = $input['name'];
                    $parameterTypes[] = $input['type'];
                }
            }

            $this->eventNamesTypesCached[$eventName] = [
                'parameterNames' => $parameterNames,
                'parameterTypes' => $parameterTypes,
                'indexedNames' => $indexedNames,
                'indexedTypes' => $indexedTypes,
                'numIndexed' => count($indexedNames)
            ];
        }

        $eventLogData = [];

        //filter through log data to find any logs which match this event (topic) from
        //this contract, between these specified blocks (defaulting to the latest block only)
        try {
            $this->eth->getLogs(
                [
                    'fromBlock' => (is_int($fromBlock)) ? '0x' . dechex($fromBlock) : $fromBlock,
                    'toBlock' => (is_int($toBlock)) ? '0x' . dechex($toBlock) : $toBlock,
                    'topics' => [$this->ethAbi->encodeEventSignature($this->events[$eventName])],
                    'address' => $this->contractAddress
                ],
                function ($error, $result) use (&$eventLogData, $eventName) {
                    if ($error !== null) {
                        $this->handleRpcError($error);
                    }

                    $cache = $this->eventNamesTypesCached[$eventName];

                    foreach ($result as $object) {
                        //decode the data from the log into the expected formats, with its corresponding named key
                        $decodedData = array_combine(
                            $cache['parameterNames'],
                            $this->ethAbi->decodeParameters($cache['parameterTypes'], $object->data)
                        );

                        //decode the indexed parameter data
                        for ($i = 0; $i < $cache['numIndexed']; $i++) {
                            //topics[0] is the event signature, so we start from $i + 1 for the indexed parameter data
                            $decodedData[$cache['indexedNames'][$i]] =
                                $this->ethAbi->decodeParameters(
                                    [$cache['indexedTypes'][$i]],
                                    $object->topics[$i + 1]
                                )[0];
                        }

                        //include block metadata for context, along with event data
                        $eventLogData[] = [
                            'transactionHash' => $object->transactionHash,
                            'blockHash' => $object->blockHash,
                            'blockNumber' => hexdec($object->blockNumber),
                            'data' => $decodedData
                        ];
                    }
                }
            );
        } catch (Throwable $e) {
            $this->handleRpcError($e);
        }

        return $eventLogData;
    }

    /**
     * @return int
     * @throws Throwable
     */
    public function getIndexBlockHeight(): int
    {
        $this->contract->at($this->contractAddress)->call(
            'currentBlockHeight',
            static function ($err, $result) use (&$indexBlockHeight) {
                if ($err !== null) {
                    if ($err instanceof Throwable) {
                        throw $err;
                    }
                    throw new RuntimeException($err);
                }

                $indexBlockHeight = (int)$result[0]->toString();
            }
        );

        return $indexBlockHeight;
    }
}

class EvmRpcException extends Exception
{
}

<?php declare(strict_types=1);

namespace Indexer;

use Exception;

class RustRocksDBProxy
{
    private string $socketPath;
    /**
     * @var resource|false|null
     */
    private $fp;
    private array $cache = [];

    public function __construct(string $socketPath)
    {
        $this->socketPath = $socketPath;
    }

    /**
     * @return void
     * @throws RustRocksDBProxyException
     */
    public function connect(): void
    {
        if ($this->fp === null) {
            $fp = @stream_socket_client($this->socketPath, $errNo, $errStr, 1);
            if (!$fp) {
                $this->fp = null; // Ensure it stays null
                throw new RustRocksDBProxyException(
                    "CRITICAL: Failed to connect to rr-proxy. Is the Rust daemon running? Error: $errStr ($errNo)"
                );
            }
            $this->fp = $fp;
        }
    }

    /**
     * @return void
     */
    public function disconnect(): void
    {
        if ($this->fp !== null) {
            if (is_resource($this->fp)) {
                fclose($this->fp);
            }
            $this->fp = null;
        }
    }

    /**
     * @param string $command
     * @return string
     * @throws RustRocksDBProxyException
     */
    private function sendCommand(string $command): string
    {
        $this->connect();

        fwrite($this->fp, $command . "\n");
        /**
         * While a 1024-byte buffer is more than enough for SMT node hashes (which are 64-byte hex strings),
         * if rr-proxy is extended in the future to return larger database values (such as domain history objects
         * or multi-record JSON payloads), any response exceeding 1023 bytes will be silently truncated, causing
         * decoding failures.
         * Remediation: Increase the buffer size to a higher threshold (e.g., 65536 or 131072) to future-proof
         * the protocol against larger payloads.
         */
        $response = stream_get_line($this->fp, 1024, "\n");

        if ($response === false) {
            throw new RustRocksDBProxyException(
                'CRITICAL: Socket connection dropped while waiting for rr-proxy response.'
            );
        }

        return $response;
    }

    // --- TRANSACTION METHODS ---

    /**
     * @return void
     * @throws RustRocksDBProxyException
     */
    public function beginTransaction(): void
    {
        $res = $this->sendCommand('BATCH_START');
        if ($res !== 'OK') {
            throw new RustRocksDBProxyException('CRITICAL: Failed to start transaction.');
        }
    }

    /**
     * @return void
     * @throws RustRocksDBProxyException
     */
    public function commitTransaction(): void
    {
        $res = $this->sendCommand('BATCH_COMMIT');
        if ($res !== 'OK') {
            throw new RustRocksDBProxyException('CRITICAL: Failed to commit RocksDB batch.');
        }
    }

    // --- NODE METHODS ---

    /**
     * @param string $path
     * @return string|null
     * @throws RustRocksDBProxyException
     */
    public function getNode(string $path): ?string
    {
        if (array_key_exists($path, $this->cache)) {
            return $this->cache[$path];
        }

        $res = $this->sendCommand('GET|' . $path);
        if ($res === 'ERROR') {
            throw new RustRocksDBProxyException('CRITICAL: RocksDB GET error.');
        }
        if ($res === 'NOT_FOUND') {
            $this->cache[$path] = null;
            return null;
        }

        $this->cache[$path] = $res;
        return $res;
    }

    /**
     * @param string $path
     * @param string $hash
     * @return void
     * @throws RustRocksDBProxyException
     */
    public function putNode(string $path, string $hash): void
    {
        $hexHash = bin2hex($hash);

        if (isset($this->cache[$path]) && $this->cache[$path] === $hexHash) {
            return;
        }

        $res = $this->sendCommand('PUT|' . $path . '|' . $hexHash);

        // It will return "QUEUED" if inside a transaction, or "OK" if doing a single standalone write
        if ($res !== 'OK' && $res !== 'QUEUED') {
            throw new RustRocksDBProxyException('CRITICAL: rr-proxy failed to save node. Response: ' . $res);
        }

        $this->cache[$path] = $hexHash;
    }

    /**
     * Completely wipes the RocksDB database.
     * Used for full resynchronization of the Rollup state.
     *
     * @return bool True if successful.
     * @throws RustRocksDBProxyException
     */
    public function clear(): bool
    {
        $response = $this->sendCommand("CLEAR");
        if (trim($response) === "OK") {
            $this->clearCache();
            return true;
        }
        return false;
    }

    public function clearCache(): void
    {
        $this->cache = [];
    }
}

class RustRocksDBProxyException extends Exception
{
}

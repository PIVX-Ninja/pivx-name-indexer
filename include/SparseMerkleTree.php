<?php declare(strict_types=1);

namespace Indexer;

class SparseMerkleTree
{
    private RustRocksDBProxy $db;
    private array $defaultHashes = [];

    /**
     * @param RustRocksDBProxy $db
     */
    public function __construct(RustRocksDBProxy $db)
    {
        $this->db = $db;

        // We still calculate default hashes in RAM because they never change
        // Stop at 128 levels.
        $emptyLeaf = str_repeat("\0", 32);
        $this->defaultHashes[0] = $emptyLeaf;
        for ($i = 1; $i <= 128; $i++) {
            $prev = $this->defaultHashes[$i - 1];
            $this->defaultHashes[$i] = hash('sha256', $prev . $prev, true);
        }
    }

    /**
     * @param string $domain
     * @return array
     */
    // Helper: Get the 128-bit path for a domain (Matches Rust exactly)
    private function getPathBits(string $domain): array
    {
        $hash = hash('sha256', $domain, true);
        $bits = [];
        // Stop at byte 16 (128 bits) to cut the tree depth in half.
        for ($i = 0; $i < 16; $i++) {
            $byte = ord($hash[$i]);
            for ($j = 7; $j >= 0; $j--) {
                $bits[] = ($byte >> $j) & 1;
            }
        }
        return $bits;
    }

    /**
     * @param string $domain
     * @param string $leafHash
     * @return void
     * @throws RustRocksDBProxyException
     */
    public function update(string $domain, string $leafHash): void
    {
        $bits = $this->getPathBits($domain);
        // Start traversal at height 128
        $this->updateNode(128, '', $bits, $leafHash);
    }

    /**
     * @param int $height
     * @param string $path
     * @param array $bits
     * @param string $leafHash
     * @return string
     * @throws RustRocksDBProxyException
     */
    private function updateNode(int $height, string $path, array $bits, string $leafHash): string
    {
        if ($height === 0) {
            $this->db->putNode($path, $leafHash); // NEW: Save directly to Rust
            return $leafHash;
        }

        // Max index is now 128
        $bit = $bits[128 - $height];
        $leftPath = $path . '0';
        $rightPath = $path . '1';

        if ($bit === 0) {
            $leftHash = $this->updateNode($height - 1, $leftPath, $bits, $leafHash);

            // Try to fetch from Rust. If null, use the default empty hash.
            $hexRight = $this->db->getNode($rightPath);
            $rightHash = $hexRight ? hex2bin($hexRight) : $this->defaultHashes[$height - 1];
        } else {
            $hexLeft = $this->db->getNode($leftPath);
            $leftHash = $hexLeft ? hex2bin($hexLeft) : $this->defaultHashes[$height - 1];

            $rightHash = $this->updateNode($height - 1, $rightPath, $bits, $leafHash);
        }

        $newHash = hash('sha256', $leftHash . $rightHash, true);
        $this->db->putNode($path, $newHash); // Save directly to Rust

        return $newHash;
    }

    /**
     * @param string $domain
     * @return array
     * @throws RustRocksDBProxyException
     */
    public function getProof(string $domain): array
    {
        $bits = $this->getPathBits($domain);
        $path = '';
        $pathsAtHeight = [];

        // PATCHED: Start from 128
        for ($h = 128; $h > 0; $h--) {
            $pathsAtHeight[$h] = $path;
            $bit = $bits[128 - $h];
            $path .= ($bit === 0) ? '0' : '1';
        }
        $pathsAtHeight[0] = $path;

        $proof = [];
        // PATCHED: Loop 128 times
        for ($h = 0; $h < 128; $h++) {
            $currentPath = $pathsAtHeight[$h];
            $bit = $bits[127 - $h]; // PATCHED: Max index for a 128-bit array is 127

            $parentPath = substr($currentPath, 0, -1);
            $siblingPath = $parentPath . (($bit === 0) ? '1' : '0');

            // NEW: Fetch sibling directly from Rust
            $hexSibling = $this->db->getNode($siblingPath);
            $siblingHash = $hexSibling ? hex2bin($hexSibling) : $this->defaultHashes[$h];

            $proof[] = array_values(unpack('C*', $siblingHash));
        }

        return $proof;
    }

    /**
     * @return array
     * @throws RustRocksDBProxyException
     */
    public function getRoot(): array
    {
        // NEW: Fetch root directly from Rust
        $hexRoot = $this->db->getNode('');
        // PATCHED: Use default 128 if tree is totally empty
        $rootBinary = $hexRoot ? hex2bin($hexRoot) : $this->defaultHashes[128];
        return array_values(unpack('C*', $rootBinary));
    }

    // Helper methods
    // Exactly mirrors Rust's `hash_leaf` function
    public static function hashLeaf(
        string $domain,
        string $pubkeyBinary,
        string $targetAddress,
        int $price,
        int $nonce
    ): string {
        // 'P' packs an unsigned 64-bit integer in little-endian byte order.
        // This is mathematically identical to Rust's u64::to_le_bytes()
        $priceBytes = pack('P', $price);
        $nonceBytes = pack('P', $nonce);

        return hash('sha256', $domain . $pubkeyBinary . $targetAddress . $priceBytes . $nonceBytes, true);
    }

    /**
     * Checks if a domain leaf already exists and is registered in the SMT.
     *
     * @param string $domain
     * @return bool
     * @throws RustRocksDBProxyException
     */
    public function leafExists(string $domain): bool
    {
        $leafHash = $this->getLeaf($domain);
        return $leafHash !== null;
    }

    /**
     * Fetches the raw hex leaf hash for a domain directly from RocksDB.
     * Returns null if it doesn't exist or is the default empty leaf.
     *
     * @param string $domain
     * @return string|null
     * @throws RustRocksDBProxyException
     */
    public function getLeaf(string $domain): ?string
    {
        // 1. Calculate the exact 128-bit database key (path) for this domain
        $bits = $this->getPathBits($domain);
        $path = implode('', $bits);

        // 2. Query RocksDB directly using your proxy
        $hexLeaf = $this->db->getNode($path);

        // 3. If nothing is returned, the leaf has never been touched
        if (!$hexLeaf) {
            return null;
        }

        // 4. Check if it explicitly matches the "empty" Genesis leaf
        // (in case a domain was deleted/reset)
        $binaryLeaf = hex2bin($hexLeaf);
        if ($binaryLeaf === $this->defaultHashes[0]) {
            return null;
        }

        return $hexLeaf;
    }

    /**
     * Verifies that a domain currently exists in the exact state provided.
     * Returns false if any single parameter (owner, address, price, nonce)
     * differs from the database, or if the domain doesn't exist.
     *
     * @param string $domain
     * @param string $pubkeyBinary 32-byte raw binary public key
     * @param string $targetAddress
     * @param int $price
     * @param int $nonce
     * @return bool
     * @throws RustRocksDBProxyException
     */
    public function verifyDomainState(
        string $domain,
        string $pubkeyBinary,
        string $targetAddress,
        int $price,
        int $nonce
    ): bool {
        // 1. Calculate the expected leaf hash for the state you are checking
        $expectedBinaryHash = self::hashLeaf($domain, $pubkeyBinary, $targetAddress, $price, $nonce);

        // Convert the binary hash to hex so we can compare it to the DB output
        $expectedHexHash = bin2hex($expectedBinaryHash);

        // 2. Fetch the actual current leaf hash from RocksDB
        $currentHexHash = $this->getLeaf($domain);

        // 3. If the domain doesn't exist, getLeaf() returns null, and this safely returns false.
        // Otherwise, it returns true ONLY if every single byte matches perfectly.
        return $expectedHexHash === $currentHexHash;
    }
}

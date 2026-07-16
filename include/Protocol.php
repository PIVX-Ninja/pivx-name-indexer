<?php declare(strict_types=1);

namespace Indexer;

use BitWasp;
use SodiumException;
use RuntimeException;

class Protocol
{
    protected static Database $db;
    protected static array $domainsCache = [];
    protected static array $domainIntermediateState = [];

    protected static array $commandArgsCountCache = [];

    // available domain zones
    protected const array DOMAIN_ZONES = ['pivx', 'private', 'secure', 'safe'];

    // OPs
    public const string OP_REG = 'REG';
    public const string OP_UPD = 'UPD';
    public const string OP_CHG = 'CHG';
    public const string OP_LST = 'LST';
    public const string OP_ULT = 'ULT';
    public const string OP_BUY = 'BUY';

    protected const array OP_LIST = [
        self::OP_REG,
        self::OP_UPD,
        self::OP_CHG,
        self::OP_LST,
        self::OP_ULT,
        self::OP_BUY
    ];

    // The protocol header
    protected const array PROTOCOL_HEADER = [
        'marker' => 0,
        'version' => 1,
        'op' => 2,
        'domain_name' => 3,
    ];

    // Several command format for various OPs
    protected const array COMMAND_TYPE_1 = [
        'target_address' => 4,
        'pubkey' => 5,
        'price' => null,
        'nonce' => 6,
        'signature' => 7
    ];
    protected const array COMMAND_TYPE_2 = [
        'target_address' => null,
        'pubkey' => 4,
        'price' => null,
        'nonce' => 5,
        'signature' => 6
    ];
    protected const array COMMAND_TYPE_3 = [
        'target_address' => null,
        'pubkey' => 4,
        'price' => 5,
        'nonce' => 6,
        'signature' => 7
    ];

    // Command formats to OPs mapping
    protected const array COMMAND_FORMAT = [
        self::OP_REG => self::COMMAND_TYPE_1,
        self::OP_UPD => self::COMMAND_TYPE_1,
        self::OP_BUY => self::COMMAND_TYPE_1,
        self::OP_CHG => self::COMMAND_TYPE_2,
        self::OP_ULT => self::COMMAND_TYPE_2,
        self::OP_LST => self::COMMAND_TYPE_3,
    ];

    public const array PRICES_REG = [
        // TODO back to production prices
        1 => 15,
        2 => 12,
        3 => 10,
        4 => 8,
        5 => 6,
        6 => 4,
        7 => 2,
    ];
    public const array PRICES_UPD = [
        // TODO back to production prices
        1 => 7,
        2 => 6,
        3 => 5,
        4 => 4,
        5 => 3,
        6 => 2,
        7 => 1,
    ];

    public static function init(Database $db): void
    {
        self::$db = $db;
    }

    /**
     * @param string $command
     * @param string $txAmount
     * @param bool $preCheck
     * @return array
     * @throws RuntimeException
     */
    public static function parseCommand(string $command, string $txAmount, bool $preCheck = false): array
    {
        $out = [];
        $parts = explode(':', $command);

        foreach (self::PROTOCOL_HEADER as $param => $index) {
            if (!isset($parts[$index]) ||
                match ($param) {
                    'marker' => $parts[$index] === 'PiNS',
                    'version' => $parts[$index] === '1',
                    'op' => in_array($parts[$index], self::OP_LIST, true),
                    'domain_name' => self::isDomainNameValid($parts[$index]),
                } === false) {
                return [];
            }
            $out[$param] = $parts[$index];
        }

        if (($partsCount = count(self::PROTOCOL_HEADER) + self::commArgCount($out['op'])) !== count($parts)) {
            return [];
        }

        if ($preCheck === false) {
            $domainExists = self::domainExists($out['domain_name']);
            if ($out['op'] === self::OP_REG) {
                // If domain is registering, it should not exist
                if ($domainExists) {
                    return [];
                }
            // if domain for update doesn't exist
            } elseif ($domainExists === false) {
                return [];
            }
        }

        try {
            foreach (self::COMMAND_FORMAT[$out['op']] as $param => $index) {
                if ($index === null) {
                    $out[$param] = match ($param) {
                        'target_address' => $preCheck ? '' : self::$domainsCache[$out['domain_name']]['target_address'],
                        'price' => ($preCheck || in_array($out['op'], [self::OP_REG, self::OP_BUY, self::OP_ULT], true)) ? 0 :
                            self::$domainsCache[$out['domain_name']]['price'],
                        default => throw new RuntimeException('Unknown default param: ' . $param),
                    };
                    continue;
                }
                if (!isset($parts[$index]) ||
                    match ($param) {
                        'target_address' => self::isAddressValid($parts[$index]),
                        'pubkey' => self::isValidEd25519Pubkey($parts[$index]),
                        'nonce', 'price' => is_int($parts[$index] = (int)$parts[$index]) && $parts[$index] > 0,
                        // skip sign check if this is pre-check for OP_CHG
                        'signature' => ($preCheck && $out['op'] === self::OP_CHG) ||
                            (
                                strlen($parts[$index]) === 128 &&
                                ctype_xdigit($parts[$index]) &&
                                sodium_crypto_sign_verify_detached(
                                    hex2bin($parts[$index]),
                                    implode(':', array_slice($parts, 0, $partsCount - 1)),
                                    // If we change the domain owner, we need to get old pubkey to check the signature
                                    hex2bin($out['op'] !== self::OP_CHG ? $out['pubkey'] :
                                        self::$domainsCache[$out['domain_name']]['owner_pubkey'])
                                )
                            )
                    } === false) {
                    return [];
                }
                $out[$param] = $parts[$index];
            }
        } catch (SodiumException) {
            return [];
        }

        if ($preCheck === false) {
            if ($out['op'] !== self::OP_REG) {
                if (
                    // if nonce in DB equal or more that came in the command
                    self::$domainsCache[$out['domain_name']]['nonce'] >= $out['nonce'] ||
                    // if OP_UPD and nothing to change
                    ($out['op'] === self::OP_UPD && self::$domainsCache[$out['domain_name']]['target_address'] === $out['target_address']) ||
                    // owner hasn't changed via CHG / there's no point to BUY from yourself
                    (in_array($out['op'], [self::OP_CHG, self::OP_BUY], true) && self::$domainsCache[$out['domain_name']]['owner_pubkey'] === $out['pubkey']) ||
                    // if OP_UPD or OP_CHG called on the domain which is on sale
                    (in_array($out['op'], [self::OP_UPD, self::OP_CHG], true) && self::$domainsCache[$out['domain_name']]['price'] !== 0) ||
                    // if OP_ULT or OP_BUY called on the domain which is not on sale
                    (in_array($out['op'], [self::OP_ULT, self::OP_BUY], true) && self::$domainsCache[$out['domain_name']]['price'] === 0) ||
                    // if OP_BUY and TX Amount not equal the selling price of the domain
                    ($out['op'] === self::OP_BUY && bccomp($txAmount, (string)self::$domainsCache[$out['domain_name']]['price'], 8) !== 0) ||
                    // on any update check that the operation is initiated by the domain owner
                    (in_array($out['op'], [self::OP_UPD, self::OP_LST, self::OP_ULT], true) && self::$domainsCache[$out['domain_name']]['owner_pubkey'] !== $out['pubkey'])
                ) {
                    return [];
                }
            }
            self::$domainIntermediateState = [
                'target_address' => $out['target_address'],
                'owner_pubkey' => $out['pubkey'],
                'nonce' => $out['nonce'],
                'price' => $out['price'],
            ];
        }

        return $out;
    }

    /**
     * @param string $op
     * @return int
     */
    protected static function commArgCount(string $op): int
    {
        if (!isset(self::$commandArgsCountCache[$op])) {
            self::$commandArgsCountCache[$op] = 0;
            foreach (self::COMMAND_FORMAT[$op] as $index) {
                if ($index === null) {
                    continue;
                }
                self::$commandArgsCountCache[$op]++;
            }
        }
        return self::$commandArgsCountCache[$op];
    }

    /**
     * @param string $domainName
     * @return bool
     */
    public static function isDomainNameValid(string $domainName): bool
    {
        if ($domainName === '' || strlen($domainName) > 64 || substr_count($domainName, '.') !== 1 ||
            str_starts_with($domainName, '-') || str_contains($domainName, '--')) {
            return false;
        }
        $domainNameParts = explode('.', $domainName);
        if ($domainNameParts[0] === '' || str_ends_with($domainNameParts[0], '-') ||
            !in_array($domainNameParts[1], self::DOMAIN_ZONES, true)) {
            return false;
        }
        if (!preg_match("/^[a-z0-9-]+$/", $domainNameParts[0])) {
            return false;
        }

        return true;
    }

    /**
     * @param string $address
     * @return bool
     */
    public static function isAddressValid(string $address): bool
    {
        // Enforce lowercase canonical formatting
        if ($address === '' || strtolower($address) !== $address) {
            return false;
        }

        // Validate Bech32 addresses (Sapling / Unified)
        try {
            [$hrp] = BitWasp\Bech32\decode($address);
            // Verify PIVX network Human Readable Parts (HRP)
            if ($hrp !== 'ptestsapling' || strlen($address) !== 88) {
                return false;
            }
        } catch (BitWasp\Bech32\Exception\Bech32Exception) {
            return false;
        }

        return true;
    }

    /**
     * @param string $pubkeyHex
     * @return bool
     */
    public static function isValidEd25519Pubkey(string $pubkeyHex): bool
    {
        // Basic length check (Must be exactly 32 bytes / 64 hex chars)
        // Enforce hexadecimal characters of length 64
        if (strlen($pubkeyHex) !== 64 || !ctype_xdigit($pubkeyHex)) {
            return false;
        }

        $pubkeyBytes = hex2bin($pubkeyHex);

        // The Curve Conversion Validation
        try {
            // This function enforces strict mathematical validation on the curve point.
            // It will throw a SodiumException if the point is invalid or part of a weak subgroup.
            sodium_crypto_sign_ed25519_pk_to_curve25519($pubkeyBytes);
            return true;
        } catch (SodiumException) {
            return false;
        }
    }

    /**
     * @param string $domain
     * @param bool $useCache
     * @return bool
     * @throws RuntimeException
     */
    public static function domainExists(string $domain, bool $useCache = true): bool
    {
        if ($useCache === false || !isset(self::$domainsCache[$domain])) {
            if (($qr = self::$db->doSelect(
                'domains_history',
                ['target_address', 'owner_pubkey', 'nonce', 'price'],
                ['domain_name' => $domain],
                'ORDER BY `domain_block_id` DESC LIMIT 1'
            )) === false) {
                throw new RuntimeException(
                    'Failed to query domains for existence check',
                    INT_EXC_DB
                );
            }

            if ($useCache) {
                self::$domainsCache[$domain] = $qr->num_rows === 0 ? false : $qr->fetch_assoc();
            } else {
                return $qr->num_rows !== 0;
            }
        }

        return is_array(self::$domainsCache[$domain]);
    }

    /**
     * @param array $command
     * @param string $amount
     * @return bool
     */
    public static function paymentAmountValid(array $command, string $amount): bool
    {
        // for BUY, the TX amount is the domain selling price
        return $command['op'] === self::OP_BUY ||
            bccomp($amount, (string)self::getDomainPrice($command['domain_name'], $command['op']), 8) === 0;
    }

    /**
     * @param string $domain
     * @param string $op
     * @return int
     */
    public static function getDomainPrice(string $domain, string $op): int
    {
        $len = strlen(explode('.', $domain)[0]);
        if ($op === self::OP_REG) {
            return self::PRICES_REG[min($len, 7)];
        }
        return self::PRICES_UPD[min($len, 7)];
    }

    /**
     * @param string $domain
     * @return void
     */
    public static function commitDomainState(string $domain): void
    {
        self::$domainsCache[$domain] = self::$domainIntermediateState;
        self::clearDomainState();
    }

    /**
     * @return void
     */
    public static function clearDomainState(): void
    {
        self::$domainIntermediateState = [];
    }
}

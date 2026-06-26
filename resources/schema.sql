--
-- Table structure for table `checkpoints`
--

DROP TABLE IF EXISTS `checkpoints`;
CREATE TABLE `checkpoints` (
  `block_id` int unsigned NOT NULL,
  `smt_root` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `evm_block_id` bigint unsigned NOT NULL,
  `evm_tx_hash` char(66) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (`block_id`),
  UNIQUE KEY `evm_block_id` (`evm_block_id` DESC) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Table structure for table `domains`
--

DROP TABLE IF EXISTS `domains`;
CREATE TABLE `domains` (
  `domain_name` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_block_id` int unsigned NOT NULL,
  `updated_block_id` int unsigned NOT NULL,
  PRIMARY KEY (`domain_name`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Table structure for table `domains_history`
--

DROP TABLE IF EXISTS `domains_history`;
CREATE TABLE `domains_history` (
  `domain_name` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `domain_block_id` int unsigned NOT NULL,
  `target_address` varchar(90) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `owner_pubkey` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `domain_tx` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `op` char(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nonce` bigint unsigned NOT NULL,
  `price` int unsigned NOT NULL,
  PRIMARY KEY (`domain_name`,`domain_block_id`) USING BTREE,
  KEY `target_address` (`target_address`) USING BTREE,
  KEY `owner_pubkey` (`owner_pubkey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Table structure for table `marketplace`
--

DROP TABLE IF EXISTS `marketplace`;
CREATE TABLE `marketplace` (
  `domain_name` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `price` int unsigned NOT NULL,
  `block_id` int unsigned NOT NULL,
  PRIMARY KEY (`domain_name`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Table structure for table `params`
--

DROP TABLE IF EXISTS `params`;
CREATE TABLE `params` (
  `param` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `value` int unsigned NOT NULL,
  PRIMARY KEY (`param`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Table structure for table `rpc_list`
--

DROP TABLE IF EXISTS `rpc_list`;
CREATE TABLE `rpc_list` (
  `chain_id` mediumint unsigned NOT NULL,
  `rpc_url` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `issue_ts` int unsigned NOT NULL,
  PRIMARY KEY (`chain_id`,`rpc_url`),
  KEY `idx_chain_issue` (`chain_id`,`issue_ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

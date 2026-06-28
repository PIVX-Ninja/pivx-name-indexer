# PIVX Name Indexer

The **PIVX Name Indexer** is a core back-end component of the **PIVX Name Service (PNS)** ecosystem. It acts as a high-performance synchronization daemon and query API that indexes domain name operations directly from the PIVX blockchain, utilizing an EVM-based rollup contract to ensure data integrity and validate Sparse Merkle Tree (SMT) roots.

---

## 📖 Documentation
Detailed installation guides, architecture overview, and API references are available at the official documentation portal:
* **[docs.pivx.name](https://docs.pivx.name)**

---

## 🚀 Key Features

* **Dual-State Synchronizer**: Processes and parses PIVX Core shielded transactions (`viewshieldtransaction`) alongside EVM checkpoint events (`RootUpdated`) to maintain state parity.
* **Sparse Merkle Tree (SMT)**: Validates cryptographic proofs using a 128-bit deep SMT backed by RocksDB and optimized with an in-memory batching cache.
* **Database Consistency**: Utilizes MySQL/MariaDB two-phase commit transactions (XA Transactions) with automatic crash-recovery protocols to prevent state mismatch between MySQL and RocksDB.
* **Domain Resolution APIs**: Exposes forward resolution (name lookup), reverse resolution (address lookup), owner query endpoints, checkpoint registries, and historical state changes.

---

## 🛠️ System Requirements

* **PHP**: version 8.1 or higher (with `mysqli`, `sodium`, and `bcmath` extensions).
* **Database**: MySQL 8.0+ or MariaDB (configured with `XA_RECOVER_ADMIN` privileges).
* **Key-Value Store**: RocksDB accessed via the [**rr-proxy**](http://github.com/PIVX-Ninja/pivx-name-rr-proxy) socket daemon.
* **Nodes**:
  * PIVX Core daemon (synced with Masternode list).
  * EVM RPC node supporting Arbitrum or arbitrary Ethereum-compatible JSON-RPC.

---

## ⚙️ Periodic Execution (`resources/contrib/`)

The [`resources/contrib/`](resources/contrib/) directory provides production-ready templates for scheduled synchronization:

* **Systemd Units** (Recommended):
  * [`resources/contrib/pivx-name-indexer.service`](resources/contrib/pivx-name-indexer.service): Systemd service unit defining the indexer execution.
  * [`resources/contrib/pivx-name-indexer.timer`](resources/contrib/pivx-name-indexer.timer): Systemd timer unit configured to start 1 minute after system boot (allowing MySQL, RPC, and `rr-proxy` services to initialize) and repeat every 30 seconds.
* **Cron Example**:
  * [`resources/contrib/crontab`](resources/contrib/crontab): Example crontab rules configured for 30-second interval execution.

---

## 📄 License

This project is licensed under the terms of the **GNU Affero General Public License v3.0 (AGPL-3.0)**. 

Please refer to the [LICENSE](LICENSE) file in the root of this repository for the full text of the license.

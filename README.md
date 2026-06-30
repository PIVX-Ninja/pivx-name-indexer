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

## 🐳 Docker Deployment

The indexer can be deployed easily as a Docker container. There are two ways to spin up the container: **from the GitHub Container Registry (GHCR) image** (recommended for production) or **built from local source files**.

### Prerequisites
Make sure you have [Docker](https://docs.docker.com/get-docker/) and [Docker Compose](https://docs.docker.com/compose/install/) installed on your server.

---

### Method A: Spin up from GitHub Docker Image (Recommended)

To run the indexer using the official pre-built image from GitHub Packages without cloning the full repository:

1. Download the `docker-compose.yml` and `env.sample` files from the repository's `resources/docker/` directory:
   ```bash
   curl -L -o docker-compose.yml https://raw.githubusercontent.com/PIVX-Ninja/pivx-name-indexer/master/resources/docker/docker-compose.yml
   curl -L -o env.sample https://raw.githubusercontent.com/PIVX-Ninja/pivx-name-indexer/master/resources/docker/env.sample
   ```
2. Copy `env.sample` to `.env`:
   ```bash
   cp env.sample .env
   ```
3. Open `.env` and fill in your PIVX wallet RPC and EVM RPC settings:
   ```bash
   nano .env
   ```
4. Start the container in detached mode:
   ```bash
   docker compose up -d
   ```

---

### Method B: Spin up from Local Docker Files

To build the Docker image yourself from the source code:

1. Clone this repository and navigate to the `resources/docker` directory:
   ```bash
   git clone https://github.com/PIVX-Ninja/pivx-name-indexer.git
   cd pivx-name-indexer/resources/docker
   ```
2. Copy `env.sample` to `.env`:
   ```bash
   cp env.sample .env
   ```
3. Open `.env` and fill in your PIVX wallet RPC and EVM RPC settings:
   ```bash
   nano .env
   ```
4. Build and start the container:
   ```bash
   docker compose up --build -d
   ```

---

### 🕹️ Start, Stop, and Management Commands

Use these standard commands from the directory containing your `docker-compose.yml` file:

* **Stop the container**:
  ```bash
  docker compose stop
  ```
* **Start the stopped container**:
  ```bash
  docker compose start
  ```
* **Stop and remove container + network resources**:
  ```bash
  docker compose down
  ```
* **Stop and remove container, network, and ALL persistent volumes (destructive)**:
  ```bash
  docker compose down -v
  ```
* **View container logs**:
  ```bash
  docker compose logs -f
  ```
* **Query indexer status / verify API (from the host machine)**:
  ```bash
  curl -i http://localhost:8080/v1.0/info
  ```

---

### 🔄 Server Autostart / Restart Policy

The container is configured with the `restart: unless-stopped` policy inside `docker-compose.yml`.

This ensures that:
- The container starts automatically when the host system boots (assuming the Docker daemon starts).
- The container restarts automatically if it crashes or if the Docker daemon restarts.
- If you manually stop the container (using `docker compose stop`), it will not start automatically on boot until you manually start it again.

No additional systemd service configuration is required on the host system, as Docker handles the lifecycle management natively.

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

PRAGMA foreign_keys = ON;

CREATE TABLE customers (
    customer_id VARCHAR(10) PRIMARY KEY,
    name        VARCHAR(100) NOT NULL
);
INSERT INTO customers (customer_id, name) VALUES
 ('C001', 'Kim'), ('C002', 'Lee'), ('C003', 'Park');

CREATE TABLE transfers (
    transfer_id          VARCHAR(36) PRIMARY KEY,
    customer_id          VARCHAR(10) NOT NULL,
    idempotency_key      VARCHAR(100) NOT NULL UNIQUE,
    request_hash         CHAR(64) NOT NULL,
    send_currency        CHAR(3) NOT NULL CHECK (send_currency = 'KRW'),
    send_amount_krw      INTEGER NOT NULL CHECK (send_amount_krw >= 10000),
    fee_krw              INTEGER NOT NULL CHECK (fee_krw >= 3000),
    total_debit_krw      INTEGER NOT NULL,
    receive_amount_minor INTEGER NOT NULL,
    receive_currency     CHAR(3) NOT NULL CHECK (receive_currency = 'PHP'),
    recipient_name       VARCHAR(100) NOT NULL CHECK (length(recipient_name) BETWEEN 1 AND 100),
    status               VARCHAR(20) NOT NULL CHECK (status IN ('REQUESTED','SENDING','COMPLETED','FAILED','CANCELLED')),
    failure_reason       VARCHAR(255),
    partner_ref          VARCHAR(100) UNIQUE,
    limit_date           DATE,
    created_at            DATETIME NOT NULL,
    updated_at           DATETIME NOT NULL,
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id)
);
CREATE INDEX idx_transfers_customer_created ON transfers(customer_id, created_at);
CREATE INDEX idx_transfers_status ON transfers(status);
CREATE INDEX idx_transfers_limit_date ON transfers(limit_date);

CREATE TABLE daily_limit_usage (
    customer_id     VARCHAR(10) NOT NULL,
    limit_date      DATE NOT NULL,
    used_amount_krw INTEGER NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL,
    PRIMARY KEY (customer_id, limit_date),
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id)
);

CREATE TABLE partner_callbacks (
    event_id    VARCHAR(100) PRIMARY KEY,
    transfer_id VARCHAR(36) NOT NULL,
    created_at  DATETIME NOT NULL,
    updated_at  DATETIME NOT NULL,
    FOREIGN KEY (transfer_id) REFERENCES transfers(transfer_id)
);


-- ============================================================
-- FLEETIFY 2.0 — MULTI-TENANT DATABASE SCHEMA
-- Phase 1 | MySQL 8 / MariaDB 10.11+
-- ============================================================

CREATE DATABASE IF NOT EXISTS fleetify_v2
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE fleetify_v2;

-- 1. GLOBAL REFERENCE DATA

CREATE TABLE IF NOT EXISTS countries (
    id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    iso2 CHAR(2) NOT NULL,
    iso3 CHAR(3) NOT NULL,
    name VARCHAR(120) NOT NULL,
    phone_code VARCHAR(12) DEFAULT NULL,
    region VARCHAR(100) DEFAULT NULL,
    subregion VARCHAR(100) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_countries_iso2 (iso2),
    UNIQUE KEY uq_countries_iso3 (iso3),
    INDEX idx_countries_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS states (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    country_id SMALLINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    code VARCHAR(30) DEFAULT NULL,
    UNIQUE KEY uq_states_country_name (country_id, name),
    UNIQUE KEY uq_states_country_id (country_id, id),
    CONSTRAINT fk_states_country
        FOREIGN KEY (country_id) REFERENCES countries(id)
        ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    country_id SMALLINT UNSIGNED NOT NULL,
    state_id BIGINT UNSIGNED DEFAULT NULL,
    name VARCHAR(150) NOT NULL,
    latitude DECIMAL(10,7) DEFAULT NULL,
    longitude DECIMAL(10,7) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_cities_country_id (country_id, id),
    INDEX idx_cities_state_name (state_id, name),
    CONSTRAINT fk_cities_country
        FOREIGN KEY (country_id) REFERENCES countries(id),
    CONSTRAINT fk_cities_state_country
        FOREIGN KEY (country_id, state_id)
        REFERENCES states(country_id, id)
) ENGINE=InnoDB;

-- 2. SAAS TENANTS

CREATE TABLE IF NOT EXISTS companies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(160) NOT NULL UNIQUE,
    email VARCHAR(190) NOT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    country_code CHAR(2) NOT NULL DEFAULT 'KE',
    currency_code CHAR(3) NOT NULL DEFAULT 'KES',
    timezone VARCHAR(64) NOT NULL DEFAULT 'Africa/Nairobi',
    status ENUM('active','suspended','cancelled')
        NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_companies_status (status),
    CONSTRAINT fk_companies_country
        FOREIGN KEY (country_code) REFERENCES countries(iso2)
) ENGINE=InnoDB;

-- 3. BRANCHES / DEPOTS

CREATE TABLE IF NOT EXISTS offices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    country_id SMALLINT UNSIGNED NOT NULL,
    city_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    code VARCHAR(20) NOT NULL,
    address VARCHAR(255) DEFAULT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    status ENUM('active','inactive')
        NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_offices_company_code (company_id, code),
    UNIQUE KEY uq_offices_company_id (company_id, id),
    CONSTRAINT fk_offices_company
        FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_offices_city_country
        FOREIGN KEY (country_id, city_id)
        REFERENCES cities(country_id, id)
) ENGINE=InnoDB;

-- 4. STAFF USERS

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    office_id BIGINT UNSIGNED DEFAULT NULL,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','branch_manager','dispatcher','driver')
        NOT NULL DEFAULT 'dispatcher',
    status ENUM('active','inactive')
        NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_company_email (company_id, email),
    UNIQUE KEY uq_users_company_id (company_id, id),
    CONSTRAINT fk_users_company
        FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_users_office
        FOREIGN KEY (company_id, office_id)
        REFERENCES offices(company_id, id)
) ENGINE=InnoDB;

-- 5. CUSTOMER ACCOUNTS

CREATE TABLE IF NOT EXISTS clients (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('active','inactive')
        NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_clients_company_email (company_id, email),
    UNIQUE KEY uq_clients_company_id (company_id, id),
    CONSTRAINT fk_clients_company
        FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB;

-- 6. VEHICLES

CREATE TABLE IF NOT EXISTS vehicles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    office_id BIGINT UNSIGNED DEFAULT NULL,
    registration_number VARCHAR(40) NOT NULL,
    vehicle_type ENUM('bike','van','pickup','truck','trailer')
        NOT NULL,
    capacity_kg DECIMAL(12,2) NOT NULL,
    status ENUM('available','in_transit','maintenance')
        NOT NULL DEFAULT 'available',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vehicles_company_registration
        (company_id, registration_number),
    UNIQUE KEY uq_vehicles_company_id (company_id, id),
    CONSTRAINT fk_vehicles_company
        FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_vehicles_office
        FOREIGN KEY (company_id, office_id)
        REFERENCES offices(company_id, id),
    CONSTRAINT chk_vehicles_capacity CHECK (capacity_kg > 0)
) ENGINE=InnoDB;

-- 7. APPROVED TRANSPORT ROUTES

CREATE TABLE IF NOT EXISTS routes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    origin_city_id BIGINT UNSIGNED NOT NULL,
    destination_city_id BIGINT UNSIGNED NOT NULL,
    transport_mode ENUM('road','rail','air','sea')
        NOT NULL DEFAULT 'road',
    distance_km DECIMAL(12,2) DEFAULT NULL,
    distance_source VARCHAR(150) DEFAULT NULL,
    verified_at DATETIME DEFAULT NULL,
    status ENUM('active','inactive')
        NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_routes_company_id (company_id, id),
    UNIQUE KEY uq_routes_direction (
        company_id, origin_city_id,
        destination_city_id, transport_mode
    ),
    CONSTRAINT fk_routes_company
        FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_routes_origin
        FOREIGN KEY (origin_city_id) REFERENCES cities(id),
    CONSTRAINT fk_routes_destination
        FOREIGN KEY (destination_city_id) REFERENCES cities(id),
    CONSTRAINT chk_routes_distance
        CHECK (distance_km IS NULL OR distance_km > 0),
    CONSTRAINT chk_routes_different_cities
        CHECK (origin_city_id <> destination_city_id)
) ENGINE=InnoDB;

-- 8. CONSIGNMENTS / SHIPMENTS

CREATE TABLE IF NOT EXISTS consignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED DEFAULT NULL,
    origin_office_id BIGINT UNSIGNED NOT NULL,
    destination_office_id BIGINT UNSIGNED NOT NULL,
    vehicle_id BIGINT UNSIGNED DEFAULT NULL,
    route_id BIGINT UNSIGNED DEFAULT NULL,
    consignment_number VARCHAR(50) NOT NULL,
    sender_name VARCHAR(150) NOT NULL,
    sender_phone VARCHAR(30) DEFAULT NULL,
    receiver_name VARCHAR(150) NOT NULL,
    receiver_phone VARCHAR(30) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    weight_kg DECIMAL(12,2) NOT NULL,
    declared_value DECIMAL(15,2) DEFAULT NULL,
    total_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    currency_code CHAR(3) NOT NULL DEFAULT 'KES',
    payment_status ENUM('unpaid','paid','cod')
        NOT NULL DEFAULT 'unpaid',
    payment_method ENUM('mpesa','cash','invoice')
        DEFAULT NULL,
    status ENUM(
        'booked','received','in_transit',
        'at_depot','out_for_delivery',
        'delivered','cancelled'
    ) NOT NULL DEFAULT 'booked',
    qr_token CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_consignments_company_number
        (company_id, consignment_number),
    UNIQUE KEY uq_consignments_qr_token (qr_token),
    UNIQUE KEY uq_consignments_company_id (company_id, id),
    INDEX idx_consignments_company_status (company_id, status),
    CONSTRAINT fk_consignments_company
        FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_consignments_client
        FOREIGN KEY (company_id, client_id)
        REFERENCES clients(company_id, id),
    CONSTRAINT fk_consignments_origin
        FOREIGN KEY (company_id, origin_office_id)
        REFERENCES offices(company_id, id),
    CONSTRAINT fk_consignments_destination
        FOREIGN KEY (company_id, destination_office_id)
        REFERENCES offices(company_id, id),
    CONSTRAINT fk_consignments_vehicle
        FOREIGN KEY (company_id, vehicle_id)
        REFERENCES vehicles(company_id, id),
    CONSTRAINT fk_consignments_route
        FOREIGN KEY (company_id, route_id)
        REFERENCES routes(company_id, id),
    CONSTRAINT chk_consignments_quantity CHECK (quantity > 0),
    CONSTRAINT chk_consignments_weight CHECK (weight_kg > 0),
    CONSTRAINT chk_consignments_amount CHECK (total_amount >= 0)
) ENGINE=InnoDB;

-- 9. CONSIGNMENT TRACKING EVENTS

CREATE TABLE IF NOT EXISTS consignment_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    consignment_id BIGINT UNSIGNED NOT NULL,
    office_id BIGINT UNSIGNED DEFAULT NULL,
    recorded_by BIGINT UNSIGNED DEFAULT NULL,
    status VARCHAR(50) NOT NULL,
    notes TEXT DEFAULT NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_events_tracking
        (company_id, consignment_id, occurred_at),
    CONSTRAINT fk_events_company
        FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_events_consignment
        FOREIGN KEY (company_id, consignment_id)
        REFERENCES consignments(company_id, id),
    CONSTRAINT fk_events_office
        FOREIGN KEY (company_id, office_id)
        REFERENCES offices(company_id, id),
    CONSTRAINT fk_events_user
        FOREIGN KEY (company_id, recorded_by)
        REFERENCES users(company_id, id)
) ENGINE=InnoDB;

-- 10. PAYMENT TRANSACTIONS

CREATE TABLE IF NOT EXISTS payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    consignment_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    currency_code CHAR(3) NOT NULL,
    method ENUM('mpesa','cash','invoice') NOT NULL,
    status ENUM('pending','completed','failed','refunded')
        NOT NULL DEFAULT 'pending',
    provider_reference VARCHAR(150) DEFAULT NULL,
    paid_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_payments_company_status (company_id, status),
    CONSTRAINT fk_payments_company
        FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_payments_consignment
        FOREIGN KEY (company_id, consignment_id)
        REFERENCES consignments(company_id, id),
    CONSTRAINT chk_payments_amount CHECK (amount > 0)
) ENGINE=InnoDB;

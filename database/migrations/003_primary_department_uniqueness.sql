-- Fleetify 2.0 / Phase 24 / Module 1
-- Migration 003: Enforce one primary department per staff member.
-- Apply after 002_department_foundation.sql.
-- Already applied manually to the development database on 2026-11-24.
-- Do not execute again against a database where this index exists.

USE fleetify_v2;

ALTER TABLE staff_departments
    ADD COLUMN primary_user_id BIGINT UNSIGNED
        GENERATED ALWAYS AS (
            CASE WHEN is_primary = 1 THEN user_id ELSE NULL END
        ) PERSISTENT,
    ADD UNIQUE KEY uq_staff_one_primary (
        company_id,
        primary_user_id
    );
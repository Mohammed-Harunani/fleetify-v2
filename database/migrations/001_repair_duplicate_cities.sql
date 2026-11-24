
-- ============================================================
-- FLEETIFY 2.0
-- Migration 001 - Repair duplicate cities and routes
-- MariaDB 10.11+
--
-- Prerequisites:
-- - Database backup created
-- - No consignments, events or payments
-- - Expected starting counts: 61 cities, 30 routes
-- ============================================================

USE fleetify_v2;

-- Stop rather than modify an unexpected database state.
DELIMITER $$

DROP PROCEDURE IF EXISTS repair_fleetify_duplicates$$

CREATE PROCEDURE repair_fleetify_duplicates()
BEGIN
    DECLARE v_cities INT DEFAULT 0;
    DECLARE v_routes INT DEFAULT 0;
    DECLARE v_consignments INT DEFAULT 0;
    DECLARE v_events INT DEFAULT 0;
    DECLARE v_payments INT DEFAULT 0;
    DECLARE v_remaining_duplicates INT DEFAULT 0;
    DECLARE v_remaining_routes INT DEFAULT 0;

    SELECT COUNT(*) INTO v_cities FROM cities;
    SELECT COUNT(*) INTO v_routes FROM routes;
    SELECT COUNT(*) INTO v_consignments FROM consignments;
    SELECT COUNT(*) INTO v_events FROM consignment_events;
    SELECT COUNT(*) INTO v_payments FROM payments;

    IF v_cities <> 61
       OR v_routes <> 30
       OR v_consignments <> 0
       OR v_events <> 0
       OR v_payments <> 0
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
            'Safety check failed: database counts differ';
    END IF;

    START TRANSACTION;

    -- Map duplicate cities to their earliest original ID.
    CREATE TEMPORARY TABLE city_repair_map (
        duplicate_id BIGINT UNSIGNED PRIMARY KEY,
        canonical_id BIGINT UNSIGNED NOT NULL
    ) ENGINE=InnoDB;

    INSERT INTO city_repair_map
        (duplicate_id, canonical_id)
    SELECT
        ci.id,
        (
            SELECT MIN(c2.id)
            FROM cities c2
            WHERE c2.country_id = ci.country_id
              AND c2.state_id <=> ci.state_id
              AND c2.name = ci.name
        )
    FROM cities ci
    WHERE ci.id <> (
        SELECT MIN(c3.id)
        FROM cities c3
        WHERE c3.country_id = ci.country_id
          AND c3.state_id <=> ci.state_id
          AND c3.name = ci.name
    );

    -- Build canonical route identities before modifying routes.
    CREATE TEMPORARY TABLE route_repair_map (
        route_id BIGINT UNSIGNED PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,
        origin_id BIGINT UNSIGNED NOT NULL,
        destination_id BIGINT UNSIGNED NOT NULL,
        transport_mode VARCHAR(20) NOT NULL
    ) ENGINE=InnoDB;

    INSERT INTO route_repair_map
        (route_id, company_id, origin_id,
         destination_id, transport_mode)
    SELECT
        r.id,
        r.company_id,
        COALESCE(om.canonical_id, r.origin_city_id),
        COALESCE(dm.canonical_id, r.destination_city_id),
        r.transport_mode
    FROM routes r
    LEFT JOIN city_repair_map om
        ON om.duplicate_id = r.origin_city_id
    LEFT JOIN city_repair_map dm
        ON dm.duplicate_id = r.destination_city_id;

    -- Preserve the earliest route for each tenant/corridor/mode.
    CREATE TEMPORARY TABLE route_repair_keep (
        keep_id BIGINT UNSIGNED PRIMARY KEY
    ) ENGINE=InnoDB;

    INSERT INTO route_repair_keep (keep_id)
    SELECT MIN(route_id)
    FROM route_repair_map
    GROUP BY company_id, origin_id,
             destination_id, transport_mode;

    -- Remove redundant routes.
    DELETE r
    FROM routes r
    LEFT JOIN route_repair_keep k
        ON k.keep_id = r.id
    WHERE k.keep_id IS NULL;

    -- Update surviving routes to canonical city IDs.
    UPDATE routes r
    JOIN route_repair_map m
        ON m.route_id = r.id
    SET
        r.origin_city_id = m.origin_id,
        r.destination_city_id = m.destination_id;

    -- Update offices referencing duplicate city IDs.
    UPDATE offices o
    JOIN city_repair_map m
        ON m.duplicate_id = o.city_id
    SET o.city_id = m.canonical_id;

    -- Delete redundant city records.
    DELETE ci
    FROM cities ci
    JOIN city_repair_map m
        ON m.duplicate_id = ci.id;

    -- Verify results before committing.
    SELECT COUNT(*) INTO v_remaining_duplicates
    FROM (
        SELECT country_id, state_id, name
        FROM cities
        GROUP BY country_id, state_id, name
        HAVING COUNT(*) > 1
    ) duplicate_groups;

    SELECT COUNT(*) INTO v_remaining_routes
    FROM routes;

    IF v_remaining_duplicates <> 0
       OR v_remaining_routes <> 8
       OR (SELECT COUNT(*) FROM cities) <> 41
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
            'Repair verification failed; transaction rolled back';
    END IF;

    COMMIT;

    DROP TEMPORARY TABLE IF EXISTS route_repair_keep;
    DROP TEMPORARY TABLE IF EXISTS route_repair_map;
    DROP TEMPORARY TABLE IF EXISTS city_repair_map;

END$$

DELIMITER ;

CALL repair_fleetify_duplicates();

DROP PROCEDURE IF EXISTS repair_fleetify_duplicates;

-- Prevent duplicate city records with a state.
ALTER TABLE cities
ADD UNIQUE KEY uq_cities_location
    (country_id, state_id, name);

-- Final verification.
SELECT 'countries' AS entity, COUNT(*) AS total FROM countries
UNION ALL SELECT 'states', COUNT(*) FROM states
UNION ALL SELECT 'cities', COUNT(*) FROM cities
UNION ALL SELECT 'companies', COUNT(*) FROM companies
UNION ALL SELECT 'offices', COUNT(*) FROM offices
UNION ALL SELECT 'vehicles', COUNT(*) FROM vehicles
UNION ALL SELECT 'routes', COUNT(*) FROM routes;

SELECT
    COUNT(*) AS duplicate_city_groups
FROM (
    SELECT country_id, state_id, name
    FROM cities
    GROUP BY country_id, state_id, name
    HAVING COUNT(*) > 1
) duplicates;

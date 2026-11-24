
-- =====================================================
-- FLEETIFY 2.0
-- Global Reference Data
-- =====================================================

USE fleetify_v2;

-- Countries, states and cities will be imported
-- from validated reference datasets.

-- Seed data must remain separate from schema.sql.


-- =====================================================
-- STEP 8: INITIAL COUNTRY REFERENCE DATA
-- =====================================================

INSERT INTO countries
    (iso2, iso3, name, phone_code, region, subregion)
VALUES
    ('KE', 'KEN', 'Kenya', '+254', 'Africa', 'Eastern Africa'),
    ('UG', 'UGA', 'Uganda', '+256', 'Africa', 'Eastern Africa'),
    ('TZ', 'TZA', 'Tanzania', '+255', 'Africa', 'Eastern Africa'),
    ('RW', 'RWA', 'Rwanda', '+250', 'Africa', 'Eastern Africa'),
    ('BI', 'BDI', 'Burundi', '+257', 'Africa', 'Eastern Africa'),
    ('ET', 'ETH', 'Ethiopia', '+251', 'Africa', 'Eastern Africa'),
    ('SO', 'SOM', 'Somalia', '+252', 'Africa', 'Eastern Africa'),
    ('SS', 'SSD', 'South Sudan', '+211', 'Africa', 'Eastern Africa'),

    ('GB', 'GBR', 'United Kingdom', '+44', 'Europe', 'Northern Europe'),
    ('DE', 'DEU', 'Germany', '+49', 'Europe', 'Western Europe'),
    ('FR', 'FRA', 'France', '+33', 'Europe', 'Western Europe'),
    ('NL', 'NLD', 'Netherlands', '+31', 'Europe', 'Western Europe'),
    ('ES', 'ESP', 'Spain', '+34', 'Europe', 'Southern Europe'),
    ('IT', 'ITA', 'Italy', '+39', 'Europe', 'Southern Europe'),

    ('US', 'USA', 'United States', '+1', 'Americas', 'Northern America'),
    ('CA', 'CAN', 'Canada', '+1', 'Americas', 'Northern America'),
    ('MX', 'MEX', 'Mexico', '+52', 'Americas', 'Central America'),

    ('IN', 'IND', 'India', '+91', 'Asia', 'Southern Asia'),
    ('PK', 'PAK', 'Pakistan', '+92', 'Asia', 'Southern Asia'),
    ('BD', 'BGD', 'Bangladesh', '+880', 'Asia', 'Southern Asia')
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    phone_code = VALUES(phone_code),
    region = VALUES(region),
    subregion = VALUES(subregion);


-- =====================================================
-- STEP 9: INITIAL STATES AND PROVINCES
-- =====================================================

INSERT INTO states (country_id, name, code)
SELECT c.id, s.state_name, s.state_code
FROM (
    SELECT 'KE' AS iso2, 'Nairobi County' AS state_name, 'KE-30' AS state_code
    UNION ALL SELECT 'KE', 'Mombasa County', 'KE-28'
    UNION ALL SELECT 'KE', 'Kiambu County', 'KE-13'
    UNION ALL SELECT 'KE', 'Nakuru County', 'KE-31'
    UNION ALL SELECT 'KE', 'Kisumu County', 'KE-17'

    UNION ALL SELECT 'GB', 'England', 'GB-ENG'
    UNION ALL SELECT 'GB', 'Scotland', 'GB-SCT'
    UNION ALL SELECT 'GB', 'Wales', 'GB-WLS'
    UNION ALL SELECT 'GB', 'Northern Ireland', 'GB-NIR'

    UNION ALL SELECT 'US', 'California', 'US-CA'
    UNION ALL SELECT 'US', 'Texas', 'US-TX'
    UNION ALL SELECT 'US', 'New York', 'US-NY'

    UNION ALL SELECT 'CA', 'Ontario', 'CA-ON'
    UNION ALL SELECT 'CA', 'British Columbia', 'CA-BC'
    UNION ALL SELECT 'CA', 'Quebec', 'CA-QC'

    UNION ALL SELECT 'IN', 'Maharashtra', 'IN-MH'
    UNION ALL SELECT 'IN', 'Gujarat', 'IN-GJ'
    UNION ALL SELECT 'IN', 'Karnataka', 'IN-KA'

    UNION ALL SELECT 'UG', 'Central Region', 'UG-C'
    UNION ALL SELECT 'TZ', 'Dar es Salaam Region', 'TZ-02'
) AS s
INNER JOIN countries c ON c.iso2 = s.iso2
ON DUPLICATE KEY UPDATE
    code = VALUES(code);

-- =====================================================
-- STEP 10: INITIAL GLOBAL CITY DATA
-- =====================================================

INSERT INTO cities
    (country_id, state_id, name, latitude, longitude)
SELECT
    c.id,
    s.id,
    city.city_name,
    city.latitude,
    city.longitude
FROM (
    SELECT 'KE' AS iso2, 'Nairobi County' AS state_name,
           'Nairobi' AS city_name, -1.286389 AS latitude,
           36.817223 AS longitude

    UNION ALL SELECT 'KE','Mombasa County','Mombasa',-4.043477,39.668206
    UNION ALL SELECT 'KE','Kisumu County','Kisumu',-0.091702,34.767956
    UNION ALL SELECT 'KE','Nakuru County','Nakuru',-0.303099,36.080026
    UNION ALL SELECT 'KE','Kiambu County','Thika',-1.039600,37.090000

    UNION ALL SELECT 'UG','Central Region','Kampala',0.347596,32.582520
    UNION ALL SELECT 'UG','Central Region','Entebbe',0.056210,32.479490

    UNION ALL SELECT 'TZ','Dar es Salaam Region','Dar es Salaam',-6.792354,39.208328

    UNION ALL SELECT 'GB','England','London',51.507351,-0.127758
    UNION ALL SELECT 'GB','England','Manchester',53.480759,-2.242631
    UNION ALL SELECT 'GB','England','Birmingham',52.486244,-1.890401
    UNION ALL SELECT 'GB','Scotland','Edinburgh',55.953251,-3.188267

    UNION ALL SELECT 'US','New York','New York City',40.712776,-74.005974
    UNION ALL SELECT 'US','California','Los Angeles',34.052235,-118.243683
    UNION ALL SELECT 'US','Texas','Houston',29.760427,-95.369804

    UNION ALL SELECT 'CA','Ontario','Toronto',43.653225,-79.383186
    UNION ALL SELECT 'CA','British Columbia','Vancouver',49.282729,-123.120738

    UNION ALL SELECT 'IN','Maharashtra','Mumbai',19.076090,72.877426
    UNION ALL SELECT 'IN','Karnataka','Bengaluru',12.971599,77.594566
    UNION ALL SELECT 'IN','Gujarat','Ahmedabad',23.022505,72.571365
) AS city
INNER JOIN countries c
    ON c.iso2 = city.iso2
INNER JOIN states s
    ON s.country_id = c.id
   AND s.name = city.state_name;


-- =====================================================
-- STEP 12: DEMONSTRATION TRANSPORT COMPANY
-- =====================================================

INSERT INTO companies (
    name,
    slug,
    email,
    phone,
    country_code,
    currency_code,
    timezone,
    status
)
VALUES (
    'Fleetify Demo Logistics',
    'fleetify-demo',
    'demo@example.com',
    '+254700000000',
    'KE',
    'KES',
    'Africa/Nairobi',
    'active'
)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    email = VALUES(email);


-- ============================================================
-- FLEETIFY 2.0 - REMAINING PHASE 1 SEED
-- MariaDB 10.11+ / MySQL 8+
-- Preserves existing records
-- ============================================================

USE fleetify_v2;

START TRANSACTION;

-- 1. ADDITIONAL GLOBAL COUNTRIES
INSERT INTO countries
(iso2, iso3, name, phone_code, region, subregion)
VALUES
('DJ','DJI','Djibouti','+253','Africa','Eastern Africa'),
('ER','ERI','Eritrea','+291','Africa','Eastern Africa'),
('CD','COD','DR Congo','+243','Africa','Middle Africa'),
('ZA','ZAF','South Africa','+27','Africa','Southern Africa'),
('NG','NGA','Nigeria','+234','Africa','Western Africa'),
('GH','GHA','Ghana','+233','Africa','Western Africa'),
('EG','EGY','Egypt','+20','Africa','Northern Africa'),
('AE','ARE','United Arab Emirates','+971','Asia','Western Asia'),
('SA','SAU','Saudi Arabia','+966','Asia','Western Asia'),
('QA','QAT','Qatar','+974','Asia','Western Asia'),
('CN','CHN','China','+86','Asia','Eastern Asia'),
('JP','JPN','Japan','+81','Asia','Eastern Asia'),
('SG','SGP','Singapore','+65','Asia','South-eastern Asia'),
('MY','MYS','Malaysia','+60','Asia','South-eastern Asia'),
('LK','LKA','Sri Lanka','+94','Asia','Southern Asia'),
('NP','NPL','Nepal','+977','Asia','Southern Asia'),
('AU','AUS','Australia','+61','Oceania','Australia and New Zealand'),
('NZ','NZL','New Zealand','+64','Oceania','Australia and New Zealand'),
('IE','IRL','Ireland','+353','Europe','Northern Europe'),
('BE','BEL','Belgium','+32','Europe','Western Europe'),
('CH','CHE','Switzerland','+41','Europe','Western Europe'),
('AT','AUT','Austria','+43','Europe','Western Europe'),
('PL','POL','Poland','+48','Europe','Eastern Europe'),
('PT','PRT','Portugal','+351','Europe','Southern Europe'),
('SE','SWE','Sweden','+46','Europe','Northern Europe'),
('NO','NOR','Norway','+47','Europe','Northern Europe'),
('DK','DNK','Denmark','+45','Europe','Northern Europe'),
('FI','FIN','Finland','+358','Europe','Northern Europe'),
('BR','BRA','Brazil','+55','Americas','South America'),
('AR','ARG','Argentina','+54','Americas','South America')
ON DUPLICATE KEY UPDATE
name = VALUES(name),
phone_code = VALUES(phone_code),
region = VALUES(region),
subregion = VALUES(subregion);

-- 2. ADDITIONAL ADMINISTRATIVE REGIONS
INSERT INTO states (country_id, name, code)
SELECT c.id, x.region_name, x.region_code
FROM (
 SELECT 'KE' iso2,'Uasin Gishu County' region_name,'KE-44' region_code
 UNION ALL SELECT 'KE','Machakos County','KE-22'
 UNION ALL SELECT 'UG','Eastern Region','UG-E'
 UNION ALL SELECT 'UG','Western Region','UG-W'
 UNION ALL SELECT 'TZ','Arusha Region','TZ-01'
 UNION ALL SELECT 'TZ','Dodoma Region','TZ-03'
 UNION ALL SELECT 'GB','England - Greater London','GB-LND'
 UNION ALL SELECT 'US','Illinois','US-IL'
 UNION ALL SELECT 'US','Florida','US-FL'
 UNION ALL SELECT 'CA','Alberta','CA-AB'
 UNION ALL SELECT 'IN','Delhi','IN-DL'
 UNION ALL SELECT 'IN','Tamil Nadu','IN-TN'
 UNION ALL SELECT 'AE','Dubai','AE-DU'
 UNION ALL SELECT 'ZA','Gauteng','ZA-GP'
 UNION ALL SELECT 'AU','New South Wales','AU-NSW'
 UNION ALL SELECT 'DE','Berlin','DE-BE'
 UNION ALL SELECT 'FR','Ile-de-France','FR-IDF'
 UNION ALL SELECT 'NL','North Holland','NL-NH'
 UNION ALL SELECT 'IE','Leinster','IE-L'
 UNION ALL SELECT 'SG','Central Region','SG-CR'
) x
JOIN countries c ON c.iso2 = x.iso2
WHERE NOT EXISTS (
 SELECT 1 FROM states s
 WHERE s.country_id = c.id
 AND s.name = x.region_name
);

-- 3. ADDITIONAL MAJOR CITIES
INSERT INTO cities
(country_id, state_id, name, latitude, longitude)
SELECT c.id, s.id, x.city_name, NULL, NULL
FROM (
 SELECT 'KE' iso2,'Uasin Gishu County' region_name,'Eldoret' city_name
 UNION ALL SELECT 'KE','Machakos County','Machakos'
 UNION ALL SELECT 'UG','Eastern Region','Jinja'
 UNION ALL SELECT 'TZ','Arusha Region','Arusha'
 UNION ALL SELECT 'TZ','Dodoma Region','Dodoma'
 UNION ALL SELECT 'GB','England','Bristol'
 UNION ALL SELECT 'GB','England','Liverpool'
 UNION ALL SELECT 'GB','Wales','Cardiff'
 UNION ALL SELECT 'US','Illinois','Chicago'
 UNION ALL SELECT 'US','Florida','Miami'
 UNION ALL SELECT 'CA','Alberta','Calgary'
 UNION ALL SELECT 'IN','Delhi','New Delhi'
 UNION ALL SELECT 'IN','Tamil Nadu','Chennai'
 UNION ALL SELECT 'AE','Dubai','Dubai'
 UNION ALL SELECT 'ZA','Gauteng','Johannesburg'
 UNION ALL SELECT 'AU','New South Wales','Sydney'
 UNION ALL SELECT 'DE','Berlin','Berlin'
 UNION ALL SELECT 'FR','Ile-de-France','Paris'
 UNION ALL SELECT 'NL','North Holland','Amsterdam'
 UNION ALL SELECT 'IE','Leinster','Dublin'
 UNION ALL SELECT 'SG','Central Region','Singapore'
) x
JOIN countries c ON c.iso2 = x.iso2
JOIN states s
 ON s.country_id = c.id
 AND s.name = x.region_name
WHERE NOT EXISTS (
 SELECT 1 FROM cities ci
 WHERE ci.country_id = c.id
 AND ci.state_id = s.id
 AND ci.name = x.city_name
);

-- 4. SECOND SAAS TENANT
INSERT INTO companies
(name,slug,email,phone,country_code,currency_code,timezone,status)
VALUES
('Global Freight Demo',
 'global-freight-demo',
 'global@example.com',
 '+447000000000',
 'GB','GBP','Europe/London','active')
ON DUPLICATE KEY UPDATE
name = VALUES(name);

-- 5. DEMONSTRATION OFFICES
INSERT INTO offices
(company_id,country_id,city_id,name,code,address,status)
SELECT co.id, ci.country_id, ci.id,
       x.office_name, x.office_code,
       'Demo depot - address not verified','active'
FROM (
 SELECT 'fleetify-demo' tenant_slug,'KE' iso2,
        'Nairobi' city_name,'Nairobi Depot' office_name,'NBO' office_code
 UNION ALL SELECT 'fleetify-demo','KE','Mombasa','Mombasa Depot','MBA'
 UNION ALL SELECT 'fleetify-demo','KE','Kisumu','Kisumu Depot','KIS'
 UNION ALL SELECT 'fleetify-demo','UG','Kampala','Kampala Depot','KLA'
 UNION ALL SELECT 'global-freight-demo','GB','London','London Hub','LON'
 UNION ALL SELECT 'global-freight-demo','GB','Manchester','Manchester Hub','MAN'
) x
JOIN companies co ON co.slug = x.tenant_slug
JOIN countries c ON c.iso2 = x.iso2
JOIN cities ci
 ON ci.country_id = c.id AND ci.name = x.city_name
ON DUPLICATE KEY UPDATE
name = VALUES(name);

-- 6. DEMONSTRATION VEHICLES
INSERT INTO vehicles
(company_id,office_id,registration_number,
 vehicle_type,capacity_kg,status)
SELECT co.id, o.id, x.registration_number,
       x.vehicle_type,x.capacity_kg,'available'
FROM (
 SELECT 'fleetify-demo' tenant_slug,'NBO' office_code,
        'DEMO-KE-001' registration_number,
        'truck' vehicle_type,5000.00 capacity_kg
 UNION ALL SELECT 'fleetify-demo','MBA','DEMO-KE-002','van',1500.00
 UNION ALL SELECT 'global-freight-demo','LON','DEMO-GB-001','truck',7500.00
) x
JOIN companies co ON co.slug = x.tenant_slug
JOIN offices o
 ON o.company_id = co.id AND o.code = x.office_code
ON DUPLICATE KEY UPDATE
capacity_kg = VALUES(capacity_kg);

-- 7. TENANT-SPECIFIC DEMONSTRATION ROUTES
-- Distances are illustrative, NOT verified road measurements.
INSERT INTO routes
(company_id,origin_city_id,destination_city_id,
 transport_mode,distance_km,distance_source,status)
SELECT co.id, origin.id, destination.id,
       'road',x.distance_km,
       'Demo estimate - unverified','active'
FROM (
 SELECT 'fleetify-demo' tenant_slug,
        'KE' origin_country,'Nairobi' origin_city,
        'KE' destination_country,'Mombasa' destination_city,
        485.00 distance_km
 UNION ALL SELECT 'fleetify-demo','KE','Nairobi','KE','Nakuru',160.00
 UNION ALL SELECT 'fleetify-demo','KE','Nairobi','KE','Kisumu',350.00
 UNION ALL SELECT 'fleetify-demo','KE','Nairobi','UG','Kampala',660.00
 UNION ALL SELECT 'fleetify-demo','UG','Kampala','TZ','Dar es Salaam',1500.00
 UNION ALL SELECT 'global-freight-demo','GB','London','GB','Manchester',340.00
 UNION ALL SELECT 'global-freight-demo','GB','London','GB','Birmingham',200.00
 UNION ALL SELECT 'global-freight-demo','GB','London','FR','Paris',460.00
) x
JOIN companies co ON co.slug = x.tenant_slug
JOIN countries oc ON oc.iso2 = x.origin_country
JOIN cities origin
 ON origin.country_id = oc.id AND origin.name = x.origin_city
JOIN countries dc ON dc.iso2 = x.destination_country
JOIN cities destination
 ON destination.country_id = dc.id AND destination.name = x.destination_city
ON DUPLICATE KEY UPDATE
distance_km = VALUES(distance_km),
distance_source = VALUES(distance_source);

COMMIT;

-- ============================================================
-- PHASE 1 VERIFICATION REPORT
-- ============================================================

SELECT 'countries' AS entity,COUNT(*) AS total FROM countries
UNION ALL SELECT 'states',COUNT(*) FROM states
UNION ALL SELECT 'cities',COUNT(*) FROM cities
UNION ALL SELECT 'companies',COUNT(*) FROM companies
UNION ALL SELECT 'offices',COUNT(*) FROM offices
UNION ALL SELECT 'vehicles',COUNT(*) FROM vehicles
UNION ALL SELECT 'routes',COUNT(*) FROM routes
UNION ALL SELECT 'users',COUNT(*) FROM users
UNION ALL SELECT 'clients',COUNT(*) FROM clients
UNION ALL SELECT 'consignments',COUNT(*) FROM consignments
UNION ALL SELECT 'payments',COUNT(*) FROM payments;

-- CHECK ROUTE OWNERSHIP AND CROSS-BORDER SUPPORT
SELECT
 co.name AS company,
 oc.name AS origin_country,
 origin.name AS origin_city,
 dc.name AS destination_country,
 destination.name AS destination_city,
 r.transport_mode,
 r.distance_km,
 r.distance_source
FROM routes r
JOIN companies co ON co.id = r.company_id
JOIN cities origin ON origin.id = r.origin_city_id
JOIN countries oc ON oc.id = origin.country_id
JOIN cities destination ON destination.id = r.destination_city_id
JOIN countries dc ON dc.id = destination.country_id
ORDER BY co.name, origin.name, destination.name;

-- CHECK FOR OFFICES LINKED TO INCORRECT COUNTRIES
SELECT COUNT(*) AS invalid_office_locations
FROM offices o
JOIN cities ci ON ci.id = o.city_id
WHERE o.country_id <> ci.country_id;

-- CHECK FOR ROUTES WITH THE SAME ORIGIN AND DESTINATION
SELECT COUNT(*) AS invalid_self_routes
FROM routes
WHERE origin_city_id = destination_city_id;

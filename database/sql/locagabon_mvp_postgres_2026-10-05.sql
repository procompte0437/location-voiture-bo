-- =============================================================================
-- LocaGabon — Proposition schéma MVP PostgreSQL
-- Date : 05/10/2026
-- Usage Navicat :
--   1) Créer une base : CREATE DATABASE locagabon;
--   2) Se connecter à locagabon
--   3) Exécuter ce script
-- =============================================================================

CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- -----------------------------------------------------------------------------
-- 1. Utilisateurs (clients, partenaires, admins)
-- -----------------------------------------------------------------------------
CREATE TABLE users (
    id              BIGSERIAL PRIMARY KEY,
    name            VARCHAR(120) NOT NULL,
    email           VARCHAR(190) NOT NULL UNIQUE,
    phone           VARCHAR(30) UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    role            VARCHAR(30) NOT NULL DEFAULT 'customer'
                    CHECK (role IN ('customer', 'partner', 'partner_agent', 'admin', 'super_admin')),
    status          VARCHAR(30) NOT NULL DEFAULT 'active'
                    CHECK (status IN ('active', 'suspended', 'banned')),
    locale          VARCHAR(5) NOT NULL DEFAULT 'fr',
    email_verified_at TIMESTAMPTZ,
    phone_verified_at TIMESTAMPTZ,
    last_login_at   TIMESTAMPTZ,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE customer_profiles (
    id                  BIGSERIAL PRIMARY KEY,
    user_id             BIGINT NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
    first_name          VARCHAR(80),
    last_name           VARCHAR(80),
    birth_date          DATE,
    address             VARCHAR(255),
    city                VARCHAR(120),
    license_number      VARCHAR(80),
    license_obtained_at DATE,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- -----------------------------------------------------------------------------
-- 2. Partenaires (validation admin obligatoire avant publication)
-- -----------------------------------------------------------------------------
CREATE TABLE partners (
    id               BIGSERIAL PRIMARY KEY,
    owner_user_id    BIGINT NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
    type             VARCHAR(30) NOT NULL
                     CHECK (type IN ('individual', 'company', 'agency')),
    company_name     VARCHAR(190),
    rccm             VARCHAR(100),
    nif              VARCHAR(100),
    manager_name     VARCHAR(120),
    address          VARCHAR(255),
    city             VARCHAR(120),
    phone            VARCHAR(30),
    whatsapp         VARCHAR(30),
    -- pending | info_requested | approved | rejected | suspended
    status           VARCHAR(30) NOT NULL DEFAULT 'pending',
    trust_level      VARCHAR(30) NOT NULL DEFAULT 'new'
                     CHECK (trust_level IN ('new', 'verified', 'trusted')),
    -- 0.00 au lancement (gratuit), puis ex. 15.00
    commission_rate  NUMERIC(5,2) NOT NULL DEFAULT 0.00,
    average_rating   NUMERIC(3,2) NOT NULL DEFAULT 0,
    reviews_count    INTEGER NOT NULL DEFAULT 0,
    validated_by     BIGINT REFERENCES users(id) ON DELETE SET NULL,
    validated_at     TIMESTAMPTZ,
    status_reason    TEXT,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_partners_status ON partners(status);
CREATE INDEX idx_partners_city ON partners(city);

CREATE TABLE partner_documents (
    id               BIGSERIAL PRIMARY KEY,
    partner_id       BIGINT NOT NULL REFERENCES partners(id) ON DELETE CASCADE,
    type             VARCHAR(50) NOT NULL, -- id_card, rccm, nif, proof_of_address, rib...
    file_path        VARCHAR(500) NOT NULL,
    status           VARCHAR(30) NOT NULL DEFAULT 'pending'
                     CHECK (status IN ('pending', 'accepted', 'rejected')),
    rejection_reason TEXT,
    expires_at       DATE,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- -----------------------------------------------------------------------------
-- 3. Lieux (recherche)
-- -----------------------------------------------------------------------------
CREATE TABLE locations (
    id          BIGSERIAL PRIMARY KEY,
    name        VARCHAR(190) NOT NULL,
    slug        VARCHAR(190) NOT NULL UNIQUE,
    type        VARCHAR(30) NOT NULL
                CHECK (type IN ('city', 'airport', 'district', 'agency_point')),
    city        VARCHAR(120),
    latitude    NUMERIC(10,7),
    longitude   NUMERIC(10,7),
    is_popular  BOOLEAN NOT NULL DEFAULT FALSE,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- -----------------------------------------------------------------------------
-- 4. Véhicules / offres (publiables seulement si partenaire approved)
-- -----------------------------------------------------------------------------
CREATE TABLE vehicles (
    id                   BIGSERIAL PRIMARY KEY,
    partner_id           BIGINT NOT NULL REFERENCES partners(id) ON DELETE CASCADE,
    brand                VARCHAR(80) NOT NULL,
    model                VARCHAR(80) NOT NULL,
    year                 SMALLINT,
    category             VARCHAR(40) NOT NULL, -- city_car, sedan, suv, 4x4, minibus...
    plate_number         VARCHAR(30) NOT NULL UNIQUE,
    color                VARCHAR(40),
    seats                SMALLINT NOT NULL DEFAULT 5,
    doors                SMALLINT NOT NULL DEFAULT 4,
    luggage              SMALLINT NOT NULL DEFAULT 2,
    transmission         VARCHAR(20) NOT NULL DEFAULT 'manual'
                         CHECK (transmission IN ('manual', 'automatic')),
    fuel                 VARCHAR(20) NOT NULL DEFAULT 'petrol',
    air_conditioning     BOOLEAN NOT NULL DEFAULT TRUE,
    included_km          INTEGER, -- NULL = illimité
    deposit_amount       INTEGER NOT NULL DEFAULT 0, -- XAF
    min_driver_age       SMALLINT NOT NULL DEFAULT 21,
    booking_mode         VARCHAR(20) NOT NULL DEFAULT 'instant'
                         CHECK (booking_mode IN ('instant', 'on_request')),
    cancellation_policy  VARCHAR(20) NOT NULL DEFAULT 'moderate',
    airport_delivery     BOOLEAN NOT NULL DEFAULT FALSE,
    free_cancellation    BOOLEAN NOT NULL DEFAULT FALSE,
    with_driver_available BOOLEAN NOT NULL DEFAULT FALSE,
    price_per_day        INTEGER NOT NULL CHECK (price_per_day >= 0), -- XAF
    description          TEXT,
    -- draft | pending_validation | published | suspended | archived
    status               VARCHAR(30) NOT NULL DEFAULT 'draft',
    created_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at           TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_vehicles_partner ON vehicles(partner_id);
CREATE INDEX idx_vehicles_status ON vehicles(status);
CREATE INDEX idx_vehicles_category ON vehicles(category);

CREATE TABLE vehicle_media (
    id          BIGSERIAL PRIMARY KEY,
    vehicle_id  BIGINT NOT NULL REFERENCES vehicles(id) ON DELETE CASCADE,
    url         VARCHAR(500) NOT NULL,
    sort_order  SMALLINT NOT NULL DEFAULT 0,
    is_cover    BOOLEAN NOT NULL DEFAULT FALSE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- -----------------------------------------------------------------------------
-- 5. Réservations + paiements
-- -----------------------------------------------------------------------------
CREATE TABLE bookings (
    id                  BIGSERIAL PRIMARY KEY,
    reference           VARCHAR(30) NOT NULL UNIQUE,
    customer_id         BIGINT REFERENCES users(id) ON DELETE SET NULL,
    guest_email         VARCHAR(190),
    guest_phone         VARCHAR(30),
    guest_name          VARCHAR(120),
    vehicle_id          BIGINT NOT NULL REFERENCES vehicles(id) ON DELETE RESTRICT,
    partner_id          BIGINT NOT NULL REFERENCES partners(id) ON DELETE RESTRICT,
    pickup_location_id  BIGINT REFERENCES locations(id) ON DELETE SET NULL,
    return_location_id  BIGINT REFERENCES locations(id) ON DELETE SET NULL,
    pickup_at           TIMESTAMPTZ NOT NULL,
    return_at           TIMESTAMPTZ NOT NULL,
    driver_age          SMALLINT,
    -- pending_payment | confirmed | ongoing | completed | cancelled | refunded
    status              VARCHAR(30) NOT NULL DEFAULT 'pending_payment',
    days_count          INTEGER NOT NULL DEFAULT 1,
    subtotal            INTEGER NOT NULL DEFAULT 0,
    total_amount        INTEGER NOT NULL DEFAULT 0, -- XAF
    deposit_amount      INTEGER NOT NULL DEFAULT 0,
    -- figés au moment de la réservation (historique)
    commission_rate     NUMERIC(5,2) NOT NULL DEFAULT 0.00,
    commission_amount   INTEGER NOT NULL DEFAULT 0,
    cancellation_policy VARCHAR(20),
    confirmed_at        TIMESTAMPTZ,
    cancelled_at        TIMESTAMPTZ,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CHECK (return_at > pickup_at)
);

CREATE INDEX idx_bookings_customer ON bookings(customer_id);
CREATE INDEX idx_bookings_partner ON bookings(partner_id);
CREATE INDEX idx_bookings_vehicle ON bookings(vehicle_id);
CREATE INDEX idx_bookings_status ON bookings(status);
CREATE INDEX idx_bookings_dates ON bookings(vehicle_id, pickup_at, return_at);

CREATE TABLE payments (
    id              BIGSERIAL PRIMARY KEY,
    booking_id      BIGINT NOT NULL REFERENCES bookings(id) ON DELETE CASCADE,
    gateway         VARCHAR(50), -- cinetpay, flutterwave, mock...
    method          VARCHAR(40) NOT NULL
                    CHECK (method IN ('airtel_money', 'moov_money', 'card', 'agency_cash')),
    amount          INTEGER NOT NULL CHECK (amount >= 0),
    currency        CHAR(3) NOT NULL DEFAULT 'XAF',
    status          VARCHAR(30) NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending', 'paid', 'failed', 'refunded')),
    transaction_ref VARCHAR(100) UNIQUE,
    paid_at         TIMESTAMPTZ,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- -----------------------------------------------------------------------------
-- 6. Configuration plateforme + audit admin
-- -----------------------------------------------------------------------------
CREATE TABLE platform_settings (
    id         BIGSERIAL PRIMARY KEY,
    key        VARCHAR(100) NOT NULL UNIQUE,
    value      JSONB,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

INSERT INTO platform_settings (key, value) VALUES
    ('default_commission_rate', '0'),          -- gratuit au lancement
    ('commission_enabled', 'false'),
    ('currency', '"XAF"'),
    ('booking_lock_minutes', '10');

-- -----------------------------------------------------------------------------
-- Piste d'audit COMPLÈTE (append-only) : qui, quoi, quand, où, navigateur...
-- Interdiction applicative de UPDATE/DELETE sur ces lignes.
-- -----------------------------------------------------------------------------
CREATE TABLE audit_logs (
    id               BIGSERIAL PRIMARY KEY,
    -- QUI
    actor_id         BIGINT REFERENCES users(id) ON DELETE SET NULL,
    actor_name       VARCHAR(120),          -- dénormalisé (reste si user supprimé)
    actor_email      VARCHAR(190),
    actor_role       VARCHAR(40),
    -- QUOI
    action           VARCHAR(100) NOT NULL, -- auth.login, partner.approved, booking.created...
    entity_type      VARCHAR(150),
    entity_id        BIGINT,
    before_data      JSONB,                 -- état avant
    after_data       JSONB,                 -- état après
    -- QUAND
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    -- OÙ (réseau + page)
    ip_address       VARCHAR(45),
    http_method      VARCHAR(10),
    url              VARCHAR(1000),
    route            VARCHAR(190),
    referer          VARCHAR(1000),
    -- AVEC QUEL CLIENT
    user_agent       TEXT,
    browser          VARCHAR(80),
    browser_version  VARCHAR(40),
    platform         VARCHAR(80),           -- Windows, macOS, Android, iOS...
    device_type      VARCHAR(40),           -- desktop, mobile, tablet, bot, unknown
    -- CORRÉLATION
    request_id       UUID,
    session_id       VARCHAR(100)
);

CREATE INDEX idx_audit_entity ON audit_logs(entity_type, entity_id);
CREATE INDEX idx_audit_actor ON audit_logs(actor_id);
CREATE INDEX idx_audit_action ON audit_logs(action);
CREATE INDEX idx_audit_created ON audit_logs(created_at);
CREATE INDEX idx_audit_ip ON audit_logs(ip_address);
CREATE INDEX idx_audit_request ON audit_logs(request_id);

COMMENT ON TABLE audit_logs IS 'Journal immuable : qui a fait quoi, quand, depuis quelle IP/URL, avec quel navigateur/OS';
COMMENT ON COLUMN audit_logs.actor_email IS 'Email figé au moment de l''action';
COMMENT ON COLUMN audit_logs.user_agent IS 'User-Agent brut du navigateur / app';
COMMENT ON COLUMN audit_logs.request_id IS 'Identifiant unique de la requête HTTP pour corrélation';

-- -----------------------------------------------------------------------------
-- 7. Commentaires métier (documentation)
-- -----------------------------------------------------------------------------
COMMENT ON TABLE partners IS 'Loueur : visible/publiable seulement si status=approved';
COMMENT ON COLUMN partners.commission_rate IS '0 au lancement (gratuit), puis taux appliqué sur les ventes';
COMMENT ON COLUMN bookings.commission_amount IS 'Montant commission figé à la réservation';
COMMENT ON TABLE vehicles IS 'Offre publiable si partner.approved ET vehicle.published';

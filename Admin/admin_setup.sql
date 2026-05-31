-- ============================================================================
-- Cosmic Tetris — Admin Panel DB Setup
-- Run this ONCE in your Supabase SQL Editor (or pgAdmin).
-- It is idempotent: safe to run multiple times.
-- ============================================================================

-- 1) Columns the admin panel relies on (player table) ------------------------
ALTER TABLE "TetrisGame" ADD COLUMN IF NOT EXISTS score      INTEGER;
ALTER TABLE "TetrisGame" ADD COLUMN IF NOT EXISTS is_blocked BOOLEAN     DEFAULT FALSE;
ALTER TABLE "TetrisGame" ADD COLUMN IF NOT EXISTS last_login TIMESTAMPTZ;

-- Make sure existing rows have a concrete blocked flag (not NULL)
UPDATE "TetrisGame" SET is_blocked = FALSE WHERE is_blocked IS NULL;

-- 2) Admin accounts table (separate from players) ----------------------------
CREATE TABLE IF NOT EXISTS "AdminUsers" (
    id         SERIAL PRIMARY KEY,
    username   VARCHAR(100) UNIQUE NOT NULL,
    email      VARCHAR(255) UNIQUE NOT NULL,
    password   VARCHAR(255) NOT NULL,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- 3) Blitz leaderboard table (read by the admin Leaderboard tab) -------------
CREATE TABLE IF NOT EXISTS blitz_leaderboard (
    username    VARCHAR(100) PRIMARY KEY,
    wins        INTEGER     DEFAULT 0,
    losses      INTEGER     DEFAULT 0,
    best_score  INTEGER     DEFAULT 0,
    total_games INTEGER     DEFAULT 0,
    updated_at  TIMESTAMPTZ DEFAULT NOW()
);

-- ============================================================================
-- After running this, register an admin at  Admin/register.php
-- (access key is defined in backEnd/AdminAuth.php -> ADMIN_REGISTER_KEY).
-- ============================================================================

-- Run this once in your Supabase SQL Editor
-- Dashboard → SQL Editor → paste this → Run

CREATE TABLE IF NOT EXISTS blitz_rooms (
    room_code    VARCHAR(8)   PRIMARY KEY,
    p1_username  VARCHAR(100) NOT NULL,
    p1_board     TEXT         DEFAULT '[]',
    p1_score     INTEGER      DEFAULT 0,
    p1_ready     INTEGER      DEFAULT 0,
    p1_alive     INTEGER      DEFAULT 1,
    p1_garbage   INTEGER      DEFAULT 0,
    p2_username  VARCHAR(100),
    p2_board     TEXT         DEFAULT '[]',
    p2_score     INTEGER      DEFAULT 0,
    p2_ready     INTEGER      DEFAULT 0,
    p2_alive     INTEGER      DEFAULT 1,
    p2_garbage   INTEGER      DEFAULT 0,
    status       VARCHAR(20)  DEFAULT 'waiting',
    winner       VARCHAR(100),
    rematch_code VARCHAR(8),
    created_at   TIMESTAMPTZ  DEFAULT NOW(),
    p1_updated   TIMESTAMPTZ  DEFAULT NOW(),
    p2_updated   TIMESTAMPTZ  DEFAULT NOW()
);

-- Run this on existing deployments to add the rematch column:
ALTER TABLE blitz_rooms ADD COLUMN IF NOT EXISTS rematch_code VARCHAR(8);

CREATE TABLE IF NOT EXISTS blitz_leaderboard (
    username    VARCHAR(100) PRIMARY KEY,
    wins        INTEGER      DEFAULT 0,
    losses      INTEGER      DEFAULT 0,
    best_score  INTEGER      DEFAULT 0,
    total_games INTEGER      DEFAULT 0,
    updated_at  TIMESTAMPTZ  DEFAULT NOW()
);

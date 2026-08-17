-- Reception Team Bride / Team Groom votes for PostgreSQL

CREATE TABLE IF NOT EXISTS reception_votes (
    id BIGSERIAL PRIMARY KEY,
    voter_token CHAR(64) NOT NULL UNIQUE,
    team VARCHAR(5) NOT NULL CHECK (team IN ('bride', 'groom')),
    voted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_reception_votes_team ON reception_votes(team);

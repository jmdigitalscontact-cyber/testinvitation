-- Reception Team Bride / Team Groom votes for MySQL/MariaDB

CREATE TABLE IF NOT EXISTS reception_votes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    voter_token CHAR(64) NOT NULL,
    team ENUM('bride', 'groom') NOT NULL,
    voted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_reception_voter_token (voter_token),
    INDEX idx_reception_votes_team (team)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

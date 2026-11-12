-- ----------------------------------------------------------
-- Database initialization script
-- ----------------------------------------------------------
--
-- The official MySQL Docker image automatically runs this
-- file the FIRST time the mysql_data volume is initialized.
-- ----------------------------------------------------------

USE studentdb;

CREATE TABLE IF NOT EXISTS messages (

    id INT AUTO_INCREMENT PRIMARY KEY,

    name VARCHAR(100) NOT NULL,

    message TEXT NOT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO messages (name, message)
VALUES
(
    'Docker',
    'Welcome to your first containerized PHP application!'
);

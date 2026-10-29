"use strict";

const mysql = require("mysql2/promise");
const { config } = require("./config");

const pool = mysql.createPool({
    ...config.db,
    waitForConnections: true,
    connectionLimit: 5,
    charset: "utf8mb4",
    timezone: "Z",
    dateStrings: false,
});

// Talk to MySQL in UTC, exactly like Laravel does (config/database.php -> 'timezone' => '+00:00').
// Without this, a Mac whose MySQL runs in IST would store times 5:30 off.
pool.pool.on("connection", (conn) => {
    conn.query("SET time_zone = '+00:00'");
});

async function ping() {
    const [rows] = await pool.query("SELECT 1 AS ok");
    return rows[0].ok === 1;
}

module.exports = { pool, ping };

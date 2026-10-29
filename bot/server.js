"use strict";

const { config, validate } = require("./src/config");
const log = require("./src/logger");

const problems = validate();
if (problems.length) {
    console.error("\nThe bot can't start. Fix these in bot/.env:\n");
    for (const p of problems) console.error("  • " + p);
    console.error("");
    process.exit(1);
}

const { createApp } = require("./src/app");
const { pool, ping } = require("./src/db");

async function main() {
    try {
        await ping();
    } catch (err) {
        console.error(`\nCan't connect to MySQL (${config.db.user}@${config.db.host}:${config.db.port}/${config.db.database}): ${err.message}`);
        console.error("Check DB_* values in bot/.env and that MySQL is running (brew services list).\n");
        process.exit(1);
    }

    const server = createApp().listen(config.port, config.host, () => {
        log.info(`White Messenger Restaurant Bot listening on http://${config.host}:${config.port}`);
        log.info(`Laravel API: ${config.laravel.apiUrl}`);
    });

    const shutdown = (signal) => {
        log.info(`${signal} received, shutting down`);
        server.close(() => pool.end().finally(() => process.exit(0)));
        setTimeout(() => process.exit(1), 5000).unref();
    };
    process.on("SIGINT", shutdown);
    process.on("SIGTERM", shutdown);
}

process.on("unhandledRejection", (err) => log.error("Unhandled rejection", { error: err?.message || String(err) }));

main();

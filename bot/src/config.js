"use strict";

require("dotenv").config({ quiet: true });

// Old .env names (VERIFY_TOKEN, APP_SECRET, ...) keep working; newer names are accepted too.
function pick(...names) {
    for (const name of names) {
        const value = process.env[name];
        if (value !== undefined && value.trim() !== "") return value.trim();
    }
    return undefined;
}

const config = {
    port: Number(pick("PORT") || 3000),
    host: pick("HOST") || "127.0.0.1",

    whatsapp: {
        verifyToken: pick("VERIFY_TOKEN", "WHATSAPP_VERIFY_TOKEN", "META_VERIFY_TOKEN"),
        appSecret: pick("APP_SECRET", "META_APP_SECRET", "WHATSAPP_APP_SECRET"),
        accessToken: pick("WHATSAPP_TOKEN", "WHATSAPP_ACCESS_TOKEN"),
        phoneNumberId: pick("PHONE_NUMBER_ID", "WHATSAPP_PHONE_NUMBER_ID"),
        graphVersion: pick("GRAPH_API_VERSION", "WHATSAPP_API_VERSION"),
        // Only change this for local testing with a fake Graph API.
        graphBaseUrl: (pick("GRAPH_API_BASE_URL") || "https://graph.facebook.com").replace(/\/+$/, ""),
    },

    db: {
        host: pick("DB_HOST") || "127.0.0.1",
        port: Number(pick("DB_PORT") || 3306),
        database: pick("DB_DATABASE") || "wm_restaurant",
        user: pick("DB_USERNAME", "DB_USER"),
        password: pick("DB_PASSWORD") || "",
    },

    laravel: {
        apiUrl: (pick("LARAVEL_API_URL") || "http://127.0.0.1:8000/internal/v1").replace(/\/+$/, ""),
    },

    internalToken: pick("WM_INTERNAL_TOKEN"),

    // Menu photos uploaded in the admin. Served at /media/menu/<file> so Meta can download them.
    mediaDir: pick("MEDIA_DIR") || require("path").resolve(__dirname, "../../admin/storage/app/public/menu"),
};

/** Returns a list of problems; empty means the config is usable. */
function validate(cfg = config) {
    const problems = [];
    const need = [
        ["whatsapp.verifyToken", "VERIFY_TOKEN"],
        ["whatsapp.appSecret", "APP_SECRET"],
        ["whatsapp.accessToken", "WHATSAPP_TOKEN"],
        ["whatsapp.phoneNumberId", "PHONE_NUMBER_ID"],
        ["whatsapp.graphVersion", "GRAPH_API_VERSION"],
        ["db.user", "DB_USERNAME"],
    ];
    for (const [path, envName] of need) {
        const value = path.split(".").reduce((o, k) => o?.[k], cfg);
        if (!value) problems.push(`${envName} is missing`);
    }
    if (!cfg.internalToken || cfg.internalToken.length < 32) {
        problems.push("WM_INTERNAL_TOKEN is missing or shorter than 32 characters (must match admin/.env)");
    }
    if (cfg.whatsapp.graphVersion && !/^v\d+\.\d+$/.test(cfg.whatsapp.graphVersion)) {
        problems.push(`GRAPH_API_VERSION should look like v23.0 (got "${cfg.whatsapp.graphVersion}")`);
    }
    return problems;
}

module.exports = { config, validate };

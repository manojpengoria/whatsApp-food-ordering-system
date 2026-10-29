"use strict";

const express = require("express");
const { ping } = require("./db");
const { config } = require("./config");
const api = require("./laravel");
const { router: webhookRouter } = require("./webhook");
const { router: internalRouter } = require("./internal");
const log = require("./logger");

const startedAt = Date.now();

function createApp() {
    const app = express();
    app.disable("x-powered-by");
    app.set("trust proxy", false);

    app.get("/", (req, res) => res.send("White Messenger Restaurant Bot is running."));

    app.get("/health", async (req, res) => {
        const result = { status: "ok", db: "ok", laravel: "ok", uptime_s: Math.round((Date.now() - startedAt) / 1000) };
        try {
            await ping();
        } catch {
            result.db = "down";
            result.status = "degraded";
        }
        try {
            await api.health();
        } catch {
            result.laravel = "down";
            result.status = "degraded";
        }
        res.status(result.db === "ok" ? 200 : 503).json(result);
    });

    // Menu photos for the WhatsApp catalog (Meta downloads them through the tunnel). Read-only, images only.
    app.use(
        "/media/menu",
        (req, res, next) => (/^\/[A-Za-z0-9]{8,64}\.(jpg|jpeg|png)$/.test(req.path) ? next() : res.sendStatus(404)),
        express.static(config.mediaDir, { index: false, dotfiles: "deny", fallthrough: true, maxAge: "1h" }),
    );

    // Razorpay → (tunnel) → bot → Laravel. Laravel checks the signature with the webhook secret.
    app.post("/webhooks/razorpay", express.raw({ type: "*/*", limit: "256kb" }), async (req, res) => {
        const raw = Buffer.isBuffer(req.body) ? req.body.toString("utf8") : "";
        try {
            const out = await api.forwardRazorpay(raw, req.get("x-razorpay-signature") || "");
            res.json(out);
        } catch (err) {
            const status = err instanceof api.ApiError ? err.status : 503;
            log.warn("Razorpay webhook not accepted", { status, error: err.message });
            res.status(status).json({ error: err.code || "unavailable" });
        }
    });

    app.use(webhookRouter);
    app.use(internalRouter);

    app.use((req, res) => res.sendStatus(404));
    // eslint-disable-next-line no-unused-vars
    app.use((err, req, res, next) => {
        log.error("Request error", { path: req.path, error: err.message });
        if (!res.headersSent) res.status(500).json({ error: "server_error", message: "Internal error." });
    });

    return app;
}

module.exports = { createApp };

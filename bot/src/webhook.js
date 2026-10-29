"use strict";

const crypto = require("crypto");
const express = require("express");
const { config } = require("./config");
const store = require("./store");
const flow = require("./flow");
const whatsapp = require("./whatsapp");
const msg = require("./messages");
const payments = require("./payments");
const log = require("./logger");

const router = express.Router();

// Meta webhook verification (GET)
router.get("/webhook", (req, res) => {
    const mode = req.query["hub.mode"];
    const token = req.query["hub.verify_token"];
    const challenge = req.query["hub.challenge"];

    if (mode === "subscribe" && typeof token === "string" && safeEqual(token, config.whatsapp.verifyToken)) {
        log.info("Webhook verified");
        return res.status(200).send(String(challenge ?? ""));
    }
    return res.sendStatus(403);
});

// Incoming messages and delivery receipts (POST)
router.post("/webhook", express.raw({ type: "application/json", limit: "1mb" }), (req, res) => {
    const signature = req.get("x-hub-signature-256");
    if (!signature || !Buffer.isBuffer(req.body) || !validSignature(req.body, signature)) {
        return res.sendStatus(401);
    }

    let payload;
    try {
        payload = JSON.parse(req.body.toString("utf8"));
    } catch {
        return res.sendStatus(400);
    }

    // Acknowledge fast; Meta retries if we take too long.
    res.sendStatus(200);

    if (payload.object !== "whatsapp_business_account") return;
    processPayload(payload).catch((err) => log.error("Webhook processing crashed", { error: err.message }));
});

function validSignature(rawBody, signature) {
    const expected = "sha256=" + crypto.createHmac("sha256", config.whatsapp.appSecret).update(rawBody).digest("hex");
    return safeEqual(signature, expected);
}

function safeEqual(a, b) {
    const ab = Buffer.from(String(a));
    const bb = Buffer.from(String(b ?? ""));
    return ab.length === bb.length && crypto.timingSafeEqual(ab, bb);
}

async function processPayload(payload) {
    const changes = (payload.entry || []).flatMap((e) => e.changes || []).map((c) => c.value || {});
    const messageCount = changes.reduce((n, v) => n + (v.messages?.length || 0), 0);
    const statusCount = changes.reduce((n, v) => n + (v.statuses?.length || 0), 0);

    const eventId = await store.recordWebhookEvent(payload, messageCount, statusCount);
    const errors = [];

    for (const value of changes) {
        for (const s of value.statuses || []) {
            try {
                if (s.type === "payment") {
                    await withUserLock(String(s.recipient_id || ""), () => payments.handlePaymentStatus(s));
                } else {
                    await store.applyStatus(s.id, s.status, s.errors?.[0]?.title || s.errors?.[0]?.message);
                }
            } catch (err) {
                errors.push(`status ${s.id}: ${err.message}`);
            }
        }

        const names = new Map((value.contacts || []).map((c) => [c.wa_id, c.profile?.name || null]));
        for (const m of value.messages || []) {
            try {
                await withUserLock(m.from, () => handleMessage(m, names.get(m.from) || null, eventId));
            } catch (err) {
                errors.push(`message ${m.id}: ${err.message}`);
                log.error("Message processing error", { from: log.maskPhone(m.from), error: err.message });
            }
        }
    }

    await store.finishWebhookEvent(eventId, errors.length ? errors.join("\n") : null);
}

async function handleMessage(m, profileName, eventId) {
    if (!/^\d{8,15}$/.test(String(m.from || ""))) return;

    const conv = await store.upsertConversation(m.from, profileName);
    const body = m.type === "text" ? m.text?.body ?? "" : m.type === "order" ? describeOrder(m.order) : null;

    const isNew = await store.insertInbound({
        waMessageId: m.id,
        conversationId: conv.id,
        webhookEventId: eventId,
        waId: m.from,
        type: m.type || "unknown",
        body,
    });
    if (!isNew) {
        log.info("Duplicate message ignored", { from: log.maskPhone(m.from) });
        return;
    }
    await store.touch(conv.id, "last_inbound_at");

    log.info("Incoming message", { from: log.maskPhone(m.from), type: m.type, length: body?.length ?? 0 });

    const reply = (text) => whatsapp.sendText(conv, text);

    if (m.type === "order") {
        const items = (m.order?.product_items || [])
            .map((p) => ({ retailer_id: String(p.product_retailer_id || ""), quantity: Number(p.quantity) || 1 }))
            .filter((p) => p.retailer_id);
        if (!items.length) return reply(msg.help);
        await flow.handleCatalogOrder({ conv, name: profileName, items, messageId: m.id, reply });
        return;
    }

    if (m.type !== "text") {
        await reply(msg.textOnly);
        return;
    }

    await flow.handleText({ conv, name: profileName, text: body, messageId: m.id, reply });
}

function describeOrder(order) {
    const lines = (order?.product_items || []).map((p) => `${p.quantity} × ${p.product_retailer_id}`);
    return `[Catalog cart] ${lines.join(", ")}${order?.text ? ` — "${order.text}"` : ""}`.slice(0, 2000);
}

// Messages from the same customer are handled one at a time, in order.
const chains = new Map();
function withUserLock(waId, fn) {
    const prev = chains.get(waId) || Promise.resolve();
    const next = prev.catch(() => {}).then(fn);
    chains.set(waId, next);
    next.finally(() => {
        if (chains.get(waId) === next) chains.delete(waId);
    }).catch(() => {});
    return next;
}

module.exports = { router, processPayload };

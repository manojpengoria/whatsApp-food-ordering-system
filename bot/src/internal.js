"use strict";

const crypto = require("crypto");
const express = require("express");
const { config } = require("./config");
const store = require("./store");
const whatsapp = require("./whatsapp");
const msg = require("./messages");
const log = require("./logger");

const router = express.Router();
const WINDOW_MS = 24 * 60 * 60 * 1000;

/** Only Laravel (same machine, shared token) may call /internal/*. */
function internalOnly(req, res, next) {
    // Requests that came in through a tunnel (ngrok / cloudflared) carry X-Forwarded-For. Refuse them.
    if (req.get("x-forwarded-for") || req.get("cf-connecting-ip")) {
        return res.status(403).json({ error: "forbidden", message: "Internal endpoint is not reachable from outside." });
    }
    const header = req.get("authorization") || "";
    const token = header.startsWith("Bearer ") ? header.slice(7) : "";
    const a = Buffer.from(token);
    const b = Buffer.from(config.internalToken);
    if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) {
        return res.status(401).json({ error: "unauthorized", message: "Invalid internal token." });
    }
    next();
}

router.post("/internal/notify", internalOnly, express.json({ limit: "32kb" }), async (req, res) => {
    const b = req.body || {};
    const order = b.order || {};

    if (!Number.isInteger(b.notification_id) || !/^\d{8,15}$/.test(String(b.wa_id || "")) || !order.number || !order.status) {
        return res.status(422).json({ error: "validation_failed", message: "notification_id, wa_id, order.number and order.status are required." });
    }
    const key =
        b.event === "order.payment_received" ? "payment_received"
        : b.event === "order.payment_expired" ? "payment_expired"
        : b.event === "order.status_changed" ? order.status
        : null;
    if (!key) {
        return res.status(422).json({ error: "unknown_event", message: `Unknown event "${b.event}".` });
    }
    const render = msg.STATUS_TEXT[key];
    if (!render) {
        return res.status(422).json({ error: "unknown_status", message: `No message for status "${order.status}".` });
    }

    const conv = await store.getConversation(b.wa_id);
    const lastIn = conv?.last_inbound_at ? new Date(conv.last_inbound_at).getTime() : 0;
    if (!conv || Date.now() - lastIn > WINDOW_MS) {
        // WhatsApp allows free-form messages only within 24h of the customer's last message.
        return res.status(409).json({
            error: "outside_24h_window",
            message: "Customer has not messaged in the last 24 hours; WhatsApp needs an approved template for this.",
        });
    }

    try {
        const clientRef = `notify:${b.notification_id}`;
        const text = render(order);
        // WhatsApp Pay orders: Meta wants order_status messages so the order page in WhatsApp stays correct.
        const waStatus = order.payment_method === "whatsapp_pay" && order.payment_reference ? orderStatusFor(key, order) : null;
        const { messageId, duplicate } = waStatus
            ? await whatsapp.sendInteractive(
                  conv,
                  {
                      type: "order_status",
                      body: { text: text.slice(0, 1024) },
                      action: { name: "review_order", parameters: { reference_id: order.payment_reference, order: { status: waStatus, ...(key === "payment_expired" ? { description: "Payment not received in time" } : {}) } } },
                  },
                  text,
                  "order_status",
                  { clientRef },
              )
            : await whatsapp.sendText(conv, text, { clientRef });
        log.info("Order update sent", { order: order.number, status: order.status, duplicate });
        return res.json({ ok: true, message_id: messageId, duplicate });
    } catch (err) {
        const status = err.permanent ? 422 : 503;
        return res.status(status).json({ error: err.permanent ? "send_rejected" : "send_failed", message: err.message });
    }
});

/** Maps our events to WhatsApp order_status values (pending → processing → completed / canceled). */
function orderStatusFor(key, order) {
    if (key === "payment_received") return "processing";
    if (key === "payment_expired") return "canceled";
    if (key === "completed") return "completed";
    return null; // other updates go as normal text
}

// Laravel asks before cancelling an unpaid order: was it paid after all?
router.post("/internal/payment-lookup", internalOnly, express.json({ limit: "8kb" }), async (req, res) => {
    const { reference_id: ref, configuration_name: cfg } = req.body || {};
    if (!ref || !cfg) return res.status(422).json({ error: "validation_failed", message: "reference_id and configuration_name are required." });
    try {
        res.json(await whatsapp.lookupPayment(cfg, ref));
    } catch (err) {
        res.status(err.permanent ? 422 : 503).json({ error: "lookup_failed", message: err.message });
    }
});

module.exports = { router };

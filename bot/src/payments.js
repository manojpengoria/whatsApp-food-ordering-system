"use strict";

/**
 * WhatsApp Pay (native "Review and Pay").
 * Meta sends a webhook status of type "payment". We never trust it alone: we confirm with
 * the payment lookup API, then tell Laravel, which makes the order visible to staff.
 */
const api = require("./laravel");
const store = require("./store");
const whatsapp = require("./whatsapp");
const msg = require("./messages");
const log = require("./logger");

async function handlePaymentStatus(s) {
    const ref = s.payment?.reference_id;
    if (!ref) return;
    const txStatus = s.payment?.transaction?.status;
    log.info("Payment webhook", { ref, status: s.status, tx: txStatus });

    if (s.status === "captured" || txStatus === "success") {
        const restaurant = await api.restaurant();
        const confirmed = await whatsapp.lookupPayment(restaurant.wa_pay_config_name, ref);
        if (confirmed.status !== "captured") {
            log.warn("Webhook said paid but lookup says otherwise", { ref, lookup: confirmed.status });
            return;
        }
        await api.confirmWhatsappPayment({
            reference_id: ref,
            status: "captured",
            amount_value: confirmed.amount_value,
            transaction_id: confirmed.transaction_id,
            pg_transaction_id: confirmed.pg_transaction_id,
        });
        return;
    }

    if (txStatus === "failed") {
        const conv = await store.getConversation(String(s.recipient_id || ""));
        if (conv) {
            const orderNumber = ref.replace(/-\d+$/, "");
            await whatsapp.sendText(conv, msg.payFailed(orderNumber, s.payment?.transaction?.error?.reason));
        }
    }
}

module.exports = { handlePaymentStatus };

"use strict";

/** All customer-facing text lives here so it is easy to edit in one place. */

const STATUS_TEXT = {
    confirmed: (o) => `✅ Your order *${o.number}* is confirmed. We'll start preparing it shortly.`,
    preparing: (o) => `👨‍🍳 Your order *${o.number}* is being prepared.`,
    ready: (o) =>
        o.order_type === "takeaway"
            ? `🛍️ Your order *${o.number}* is ready for pickup!`
            : `📦 Your order *${o.number}* is ready and will leave for delivery soon.`,
    out_for_delivery: (o) => `🛵 Your order *${o.number}* is on the way!`,
    completed: (o) =>
        o.order_type === "takeaway"
            ? `🎉 Order *${o.number}* picked up. Thank you, see you again!`
            : `🎉 Order *${o.number}* delivered. Enjoy your meal!`,
    // Not a status: Razorpay payment confirmation (event order.payment_received)
    payment_received: (o) => `✅ Payment of ${o.total} received for order *${o.number}*. Your order is now with the kitchen. 👨‍🍳`,
    payment_expired: (o) => `⌛ We didn't receive the payment for order *${o.number}* within ${o.payment_window_minutes || 15} minutes, so it has been cancelled. Reply *1* to order again.`,
    cancelled: (o) =>
        `❌ Sorry, your order *${o.number}* has been cancelled.` + (o.cancel_reason ? `\nReason: ${o.cancel_reason}` : ""),
};

function welcome(r, name) {
    const hello = name ? `Hi ${name.split(" ")[0]}! 👋` : "Hi! 👋";
    return (
        `${hello} Welcome to *${r.name}* 🍽️\n` +
        (r.accepting_orders ? "" : `\n⏸️ We're not taking orders right now (hours: ${r.hours}).\n`) +
        "\nReply with a number:\n\n" +
        "1. View menu\n" +
        "2. My cart\n" +
        "3. Track my order\n" +
        "4. Restaurant info\n" +
        "5. Talk to us"
    );
}

function menu(m, r) {
    if (!m.categories.length) return "Our menu is being updated. Please check back soon.";
    let out = `🍽️ *${r.name} Menu*\n`;
    for (const c of m.categories) {
        out += `\n*${c.name}*\n`;
        for (const i of c.items) out += `${i.code}. ${i.name} — ${i.price} ${i.is_veg ? "🟢" : "🔴"}\n`;
    }
    const ex = m.categories[0].items[0];
    out += `\n🟢 Veg  🔴 Non-veg\n\nTo order, reply *ADD code quantity*\nExample: *ADD ${ex.code} 2* for 2 × ${ex.name}`;
    return out;
}

function cartLines(cart) {
    return cart.items.map((i) => `${i.quantity} × ${i.name} — ${i.line_total}`).join("\n");
}

function totals(cart) {
    let out = `\nSubtotal: ${cart.subtotal}\n${cart.tax_label}: ${cart.tax}`;
    if (cart.delivery_fee_paise > 0) out += `\nDelivery: ${cart.delivery_fee}`;
    out += `\n*Total: ${cart.total}*`;
    return out;
}

function cart(c) {
    if (c.is_empty) {
        return "🛒 Your cart is empty.\n\nReply *1* to see the menu.";
    }
    let out = `🛒 *Your cart*\n${cartLines(c)}\n${totals(c)}`;
    if (c.unavailable_items.length) {
        out += `\n\n⚠️ Not available now: ${c.unavailable_items.map((i) => `${i.name} (${i.code})`).join(", ")}. Reply *REMOVE code* to remove.`;
    }
    out += "\n\nReply *CHECKOUT* to order\n*ADD code qty* to add more\n*REMOVE code* to remove an item\n*CLEAR* to empty the cart";
    return out;
}

function added(c, code) {
    const line = c.items.find((i) => i.code === code);
    const head = line ? `✅ Added. You now have ${line.quantity} × ${line.name}.` : "✅ Cart updated.";
    return `${head}\n\n${cart(c)}`;
}

const askOrderType = (r) =>
    r.delivery_enabled
        ? `How would you like to get your order?\n\n1. Home delivery (${r.delivery_fee} delivery fee)\n2. Takeaway (pick up from us)\n\nReply *1* or *2*. Reply *CANCEL* to stop.`
        : "We are offering takeaway only right now.\n\nReply *2* to continue with takeaway, or *CANCEL* to stop.";

const askAddress = (suggested) =>
    suggested
        ? `Deliver to your last address?\n\n📍 ${suggested}\n\nReply *YES* to use it, or type a new full address.`
        : "Please type your full delivery address (house no., street, area, landmark).";

const PAY_LABEL = {
    whatsapp_pay: "💳 Pay now in WhatsApp (UPI, card, netbanking)",
    razorpay: "🔗 Pay online with a link",
    cash: "💵 Cash on delivery / at pickup",
};

function askPayment(methods) {
    return "How would you like to pay?\n\n" + methods.map((m, i) => `${i + 1}. ${PAY_LABEL[m]}`).join("\n") + "\n\nReply with the number.";
}

function confirm(c, type, address, payment = "cash") {
    let out = `🧾 *Please confirm your order*\n\n${cartLines(c)}\n${totals(c)}\n\n`;
    out += type === "delivery" ? `🛵 Home delivery to:\n📍 ${address}\n` : "🛍️ Takeaway (pick up from us)\n";
    out += `Payment: ${PAY_LABEL[payment] || PAY_LABEL.cash}\n\nReply *YES* to place the order or *NO* to cancel.`;
    return out;
}

function payOnline(o, link) {
    return `💳 *Pay ${o.total} for order ${o.number}*\n\nTap to pay securely with UPI, card or netbanking:\n${link}\n\nPlease pay within ${o.payment?.window_minutes || 15} minutes. Your order goes to the kitchen as soon as the payment arrives.`;
}

const payStartFailed = () =>
    "⚠️ Sorry, online payment couldn't be started right now, so the order was not placed. Please order again and choose *Cash*, or try online payment in a few minutes.";

const orderDetailsBody = (o, minutes) =>
    `🧾 Order *${o.number}* — total *${o.total}*\n\nTap *Review and Pay* to pay with any UPI app, card or netbanking. Your order goes to the kitchen as soon as the payment is done.\n\n⏱️ Please pay within ${minutes} minutes.`;

const payFailed = (o, reason) =>
    `❌ The payment for order *${o}* didn't go through${reason ? ` (${reason.replace(/_/g, " ")})` : ""}. You can try again with the *Review and Pay* button above.`;

const awaitingPaymentPlaced = (o) =>
    `📝 Order *${o.number}* is ready. Complete the payment below to send it to the kitchen.`;

function placed(o) {
    if (o.status === "awaiting_payment") return awaitingPaymentPlaced(o);
    return (
        `🎉 Order *${o.number}* placed!\n\n` +
        `${o.items.map((i) => `${i.quantity} × ${i.name}`).join("\n")}\n\n` +
        `*Total: ${o.total}*` +
        (o.payment?.method === "cash" || !o.payment ? ` (pay ${o.order_type === "delivery" ? "cash on delivery" : "at pickup"})` : "") +
        "\n\n" +
        "We'll send you updates here. Reply *3* anytime to track it."
    );
}

function orders(list) {
    if (!list.length) return "You haven't placed any orders yet.\n\nReply *1* to see the menu.";
    return (
        "📋 *Your recent orders*\n\n" +
        list
            .map((o) => `*${o.number}* — ${o.status_label}\n${o.items.map((i) => `${i.quantity} × ${i.name}`).join(", ")}\nTotal ${o.total}`)
            .join("\n\n") +
        "\n\nReply *HI* for the main menu."
    );
}

function info(r) {
    let out = `🏪 *${r.name}*\n\n🕙 Hours: ${r.hours}`;
    out += r.is_open ? " (open now)" : " (closed now)";
    if (r.address) out += `\n📍 ${r.address}`;
    if (r.phone) out += `\n📞 ${r.phone}`;
    out += r.delivery_enabled ? `\n🛵 Home delivery: ${r.delivery_fee}` : "\n🛍️ Takeaway only right now";
    out += "\n💵 Payment: Cash\n\nReply *1* for the menu or *HI* for options.";
    return out;
}

const contact = (r) =>
    "👨‍💼 *White Messenger Private Limited*\n\n" +
    "This ordering bot is a demo by White Messenger. Want one for your restaurant?\n\n" +
    `📞 WhatsApp / Call: ${r.sales_contact || "8690992982"}\n\nReply *HI* for the main menu.`;

const catalogIntro = (r) =>
    `🍽️ *${r.name} Menu*\n\nTap *View catalog* below, add items with + and tap *Send cart*. We'll take it from there.\n\nPrefer a text list? Reply *LIST*.`;

function catalogCartReceived(c, rejected, reduced = []) {
    let out = "";
    if (rejected.length) out += `⚠️ Not available right now, so not added: ${rejected.join(", ")}\n\n`;
    for (const r of reduced) {
        out += r.given > 0
            ? `⚠️ Only ${r.given} × ${r.name} left, so I added ${r.given} (you asked for ${r.wanted}).\n`
            : `⚠️ ${r.name} just sold out, so it was not added.\n`;
    }
    if (reduced.length) out += "\n";
    if (c.is_empty) return out + "None of the items in your cart can be ordered right now. Reply *1* to see the menu.";
    return out + `🛒 *Got your cart*\n${cartLines(c)}\n${totals(c)}`;
}

const help =
    "Sorry, I didn't get that. 🤔\n\nTry:\n*1* menu  ·  *2* cart  ·  *3* track order\n*ADD 5 2* to add 2 of item 5\nOr reply *HI* for all options.";

const unavailable = "Sorry, we're having a technical problem right now. Please try again in a few minutes. 🙏";

const textOnly = "I can only read text messages for now. Please type your reply. ✍️\n\nReply *HI* for options.";

module.exports = {
    STATUS_TEXT,
    welcome,
    menu,
    cart,
    added,
    askOrderType,
    askAddress,
    confirm,
    placed,
    orders,
    info,
    contact,
    help,
    unavailable,
    textOnly,
    catalogIntro,
    catalogCartReceived,
    askPayment,
    payOnline,
    payStartFailed,
    orderDetailsBody,
    payFailed,
};

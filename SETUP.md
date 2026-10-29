# White Messenger — Restaurant WhatsApp Ordering

    wm-restaurant/
    ├── admin/   Laravel 13 admin panel + internal API (owns the database)
    └── bot/     Node WhatsApp bot

Har baar chalane ke liye 4 Terminal:
    cd admin && php artisan serve --port=8000
    cd admin && php artisan queue:work
    cd bot && npm start
    ngrok / cloudflared tunnel → http://127.0.0.1:3000

Update ke baad (har zip ke baad ek baar):
    cd admin && php artisan migrate && npm run build && php artisan test

Modules: Dashboard, Orders, Billing (counter/table bill + print), Tables, Kitchen,
Menu (+ photo, WhatsApp catalog sync), Inventory (simple stock), Customers,
Reports (+ CSV), Expenses, Staff (Admin / Staff roles), Settings.

Staff role: Orders, Billing, Tables, Kitchen, Inventory, Customers.
Admin only: Menu, Reports, Expenses, Staff, Settings.

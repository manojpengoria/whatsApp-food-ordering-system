<?php

namespace Database\Seeders;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use Illuminate\Database\Seeder;

/** Demo menu for Cafe Shala. Prices in rupees here, stored as paise. */
class MenuSeeder extends Seeder
{
    public function run(): void
    {
        $menu = [
            'Burgers & Sandwiches' => [
                ['1', 'Veg Burger', 'Crispy aloo-veg patty, lettuce, house mayo', 99, true],
                ['2', 'Paneer Tikka Burger', 'Tandoori paneer, onion, mint mayo', 149, true],
                ['3', 'Grilled Veg Sandwich', 'Cheese, capsicum, tomato, green chutney', 119, true],
                ['4', 'Chicken Club Sandwich', 'Grilled chicken, egg, lettuce, triple layer', 169, false],
            ],
            'Pizza' => [
                ['5', 'Margherita Pizza', 'Tomato sauce, mozzarella, basil', 179, true],
                ['6', 'Farmhouse Pizza', 'Onion, capsicum, mushroom, tomato', 249, true],
                ['7', 'Paneer Makhani Pizza', 'Makhani sauce, paneer, onion', 269, true],
            ],
            'Snacks & Pasta' => [
                ['8', 'French Fries', 'Salted, crispy', 79, true],
                ['9', 'Peri Peri Fries', 'Tossed in peri peri spice', 99, true],
                ['10', 'Masala Maggi', 'Cafe-style with veggies', 89, true],
                ['11', 'Pasta Alfredo', 'Penne in white sauce', 199, true],
            ],
            'Beverages' => [
                ['12', 'Cold Coffee', 'Thick, with ice cream', 89, true],
                ['13', 'Masala Chai', 'Kulhad style', 39, true],
                ['14', 'Fresh Lime Soda', 'Sweet or salted', 69, true],
                ['15', 'Mango Shake', 'Made with Alphonso pulp', 109, true],
            ],
            'Desserts' => [
                ['16', 'Chocolate Brownie', 'Warm, with chocolate sauce', 119, true],
                ['17', 'Gulab Jamun (2 pcs)', 'Served warm', 69, true],
            ],
        ];

        $catOrder = 0;
        foreach ($menu as $categoryName => $items) {
            $category = MenuCategory::query()->updateOrCreate(['name' => $categoryName], ['sort_order' => ++$catOrder, 'is_active' => true]);

            foreach ($items as $i => [$code, $name, $description, $rupees, $veg]) {
                MenuItem::query()->updateOrCreate(['code' => $code], [
                    'menu_category_id' => $category->id,
                    'name' => $name,
                    'description' => $description,
                    'price_paise' => $rupees * 100,
                    'is_veg' => $veg,
                    'is_available' => true,
                    'sort_order' => $i + 1,
                ]);
            }
        }

        $this->command?->info('Cafe Shala menu ready: '.MenuItem::query()->count().' items.');
    }
}

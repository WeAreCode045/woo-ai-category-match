# Woo AI Category Matcher

Tired of manually categorizing hundreds of WooCommerce products?
Let AI do the work for you by  

Automatically categorize uncategorized WooCommerce products using the power of OpenAI.


## 🧠 What it does

The **Woo AI Category Matcher** scans all products in your WooCommerce store that do not have a product category.  
Using AI (via OpenAI's API), it analyzes each product's title and description to determine and assign the most relevant product category.

## 🚀 Features

- Scans all uncategorized WooCommerce products
- Uses OpenAI to analyze product titles and descriptions
- Automatically assigns the best-matching product category
- Simple one-click matching interface

## ✅ Requirements

- WordPress 5.8+  
- WooCommerce 6.0+  
- PHP 7.4+  
- OpenAI API key

## 🔧 Installation

1. Download or clone this repository  
2. Upload the plugin folder to `/wp-content/plugins/`  
3. Activate the plugin in the WordPress admin panel

## 📝 Usage

1. Go to **Woo AI Category Matcher** in the WordPress admin menu  
2. Enter your **OpenAI API Key**  
3. Click the **“Start Matching”** button  
4. The plugin will scan and categorize all uncategorized products using AI

## 📦 Example

**Before:**  
- Product title: "Soft cotton baby blanket"  
- Product has no category

**After AI Matching:**  
- Category assigned: "Baby & Kids > Bedding"

## 🌐 Multilingual Support

- 🇬🇧 English  
- 🇳🇱 Nederlands  

## 🛠 Developer Notes

- The plugin uses the `openai.com` API endpoint  
- API calls are made securely via the WordPress HTTP API  
- Product categories are matched based on WooCommerce taxonomy `product_cat`

## 🔒 Privacy & Data

This plugin sends product titles and descriptions to OpenAI for categorization purposes. No personal customer data is shared.


## 🤝 Contributing

Pull requests are welcome. For major changes, please open an issue first to discuss what you would like to change.

---

**Made with ❤️ for WooCommerce**


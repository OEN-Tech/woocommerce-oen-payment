# WooCommerce OEN 金流付款外掛

整合 OEN 金流（應援科技）與 WooCommerce，讓商家可透過 OEN 託管結帳頁面收取信用卡及超商繳費付款。

## 功能特色

- **信用卡付款** — 導向 OEN 託管結帳頁面進行信用卡付款
- **超商繳費** — 產生超商繳費代碼，消費者至超商完成付款
- **OEN 電子發票自動開立** — 付款完成後由 OEN 自動開立電子發票，支援手機條碼、自然人憑證、統編及捐贈載具
- 每種付款方式可個別啟用/停用
- 可設定訂單編號前綴字
- 可選擇是否將商品明細傳送至 OEN
- 可在 WooCommerce 訂單通知信中加入付款資訊（金流編號、繳費代碼等）
- 支援測試環境（sandbox）/ 正式環境切換
- 支援繁體中文（zh_TW）及英文語系
- 相容 WooCommerce HPOS（高效能訂單儲存）

## 系統需求

- PHP 8.1 或更新版本
- WordPress 6.1 或更新版本
- WooCommerce 8.2 或更新版本

## 安裝方式

1. 將 `woocommerce-oen-payment` 資料夾上傳至 `/wp-content/plugins/`
2. 在 WordPress 後台「外掛」頁面啟用外掛
3. 前往 **WooCommerce > 設定 > OEN** 設定商店代碼（MerchantID）及 API 金鑰
4. 前往 **WooCommerce > 設定 > 付款方式** 啟用「OEN 信用卡」及/或「OEN 超商繳費」
5. 至 OEN CRM 後台設定 Webhook 網址：`https://你的網站網址/?wc-api=oen_payment`

## 設定說明

### WooCommerce > 設定 > OEN

| 設定項目 | 說明 |
|---------|------|
| 啟用 OEN 金流付款方式 | 主開關，關閉後所有 OEN 付款方式皆不顯示 |
| 訂單編號前綴 | 加在 WooCommerce 訂單編號前面的前綴字 |
| 顯示訂單商品名稱 | 開啟後將個別商品明細傳送至 OEN |
| 在 Email 中顯示付款資訊 | 在訂單通知信中加入金流編號、繳費代碼等資訊 |
| OEN 測試環境 | 勾選後使用 OEN 測試環境 API |
| 商店代碼 | OEN 商店代碼（MerchantID） |
| API 金鑰 | OEN API Token（Bearer 認證） |

### 付款流程

**信用卡：** 消費者選擇「OEN 信用卡」→ 導向 OEN 結帳頁面 → 完成付款 → 返回商店感謝頁 → Webhook 通知更新訂單狀態

**超商繳費：** 消費者選擇「OEN 超商繳費」→ 導向 OEN 結帳頁面取得繳費代碼 → 至超商繳費 → Webhook 通知更新訂單狀態

## 授權條款

本外掛採用 [GPL-3.0-or-later](https://www.gnu.org/licenses/gpl-3.0.html) 授權。

---

# WooCommerce OEN Payment Gateway

Integrates OEN Payment (應援科技) with WooCommerce, enabling merchants to accept credit card and convenience store (CVS) payments via OEN's hosted checkout page.

## Features

- **Credit Card** — Redirect to OEN's hosted checkout for credit card payment
- **CVS (Convenience Store)** — Generate payment codes for convenience store payment
- **OEN Invoice Issuance Built In** — E-invoices issued automatically by OEN after payment, supporting mobile barcode, citizen digital certificate, company tax ID, and charity donation carriers
- Separate enable/disable toggle for each payment method
- Configurable order number prefix
- Optional product detail line items sent to OEN
- Payment information in WooCommerce order emails (transaction ID, CVS payment code, etc.)
- Sandbox/production environment switching
- Traditional Chinese (zh_TW) and English language support
- WooCommerce HPOS compatible

## Requirements

- PHP 8.1+
- WordPress 6.1+
- WooCommerce 8.2+

## Installation

1. Upload the `woocommerce-oen-payment` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu in WordPress
3. Go to **WooCommerce > Settings > OEN** to configure your MerchantID and API Token
4. Go to **WooCommerce > Settings > Payments** to enable OEN Credit and/or OEN Cvs
5. Configure your webhook URL in OEN CRM backend: `https://yoursite.com/?wc-api=oen_payment`

## Settings

### WooCommerce > Settings > OEN

| Setting | Description |
|---------|-------------|
| Enable OEN gateway method | Master switch — disables all OEN methods when off |
| Order no prefix | Prefix prepended to WooCommerce order ID |
| Display order item name | Send individual product details to OEN |
| Show payment info in email | Add transaction ID, CVS payment code to order emails |
| OEN sandbox | Use OEN testing environment API |
| MerchantID | OEN store code |
| API Token | OEN API Token (Bearer authentication) |

### Payment Flow

**Credit Card:** Customer selects "OEN Credit" → Redirected to OEN checkout → Completes payment → Returns to thank-you page → Webhook updates order status

**CVS:** Customer selects "OEN Cvs" → Redirected to OEN checkout for payment code → Pays at convenience store → Webhook updates order status

## License

This plugin is licensed under [GPL-3.0-or-later](https://www.gnu.org/licenses/gpl-3.0.html).

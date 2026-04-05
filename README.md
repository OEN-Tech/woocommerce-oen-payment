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
3. 前往 **WooCommerce > 設定 > OEN** 設定商店代碼（MerchantID）、Secret Key 與 Webhook Secret
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
| Secret Key | OEN API Secret Key（Bearer 認證，用於 Hosted Checkout Session API） |
| Webhook Secret | 用於驗證 `OenPay-Signature` 的 HMAC 密鑰；留空則略過簽名驗證 |

### 付款流程

**信用卡：** 消費者選擇「OEN 信用卡」→ 導向 OEN 結帳頁面 → 完成付款 → 返回商店感謝頁 → Webhook 通知更新訂單狀態

**超商繳費：** 消費者選擇「OEN 超商繳費」→ 導向 OEN 結帳頁面取得繳費代碼 → 至超商繳費 → Webhook 通知更新訂單狀態

### Hosted Checkout Session API

外掛目前使用 OEN Hosted Checkout Session API：

- `POST /hosted-checkout/v1/sessions`：建立 checkout session，回傳非空 `id` 與 `checkoutUrl`
- `GET /hosted-checkout/v1/sessions/{sessionId}`：查詢單一 session 狀態

`Authorization` header 應使用 `Bearer <Secret Key>`。

建立 session 時，外掛會把回傳的 `id` 視為必要欄位；若 OEN API 未回傳非空 session id，結帳流程會直接失敗，避免將空的 `_oen_session_id` 寫入訂單後關閉 stale-attempt 保護。

### Webhook Signature 與 Event Envelope

Webhook request 會帶 `OenPay-Signature` header，格式如下：

```text
OenPay-Signature: t=1712345678,v1=<hex_hmac_sha256>
```

簽名內容為：

```text
{timestamp}.{raw_body}
```

其中 `timestamp` 來自 header 的 `t`，HMAC 演算法為 `sha256`，使用 **Webhook Secret** 驗證。
此外，外掛預設要求 `t` 必須落在目前時間前後 300 秒內，超過容忍範圍的簽名會被拒絕。

Webhook body 為 event envelope，業務欄位位於巢狀的 `data` 物件中，例如：

```json
{
  "id": "evt_test_123",
  "type": "payment.succeeded",
  "data": {
    "sessionId": "sess_123",
    "orderId": "wc_1001",
    "transactionId": "txn_123",
    "transactionHid": "txn_hid_123",
    "status": "charged",
    "paymentMethod": "card",
    "paymentProvider": "oenpay"
  }
}
```

Webhook handler 會先解析 envelope 並保留 `type` 與巢狀 `data`，再以 `transactionHid` 優先做 server-side verification；若 payload 缺少 `transactionHid` 但含有 `sessionId`，則會改用 `GET /hosted-checkout/v1/sessions/{sessionId}` 驗證。只有當 event `type` 與驗證後的 session/transaction 狀態同時指向相同的成功/失敗終態時才會更新訂單；若 webhook 的 `sessionId` 或 `transactionHid` 與目前訂單儲存的付款 attempt 不一致，尤其在訂單已保存 `_oen_session_id` 時 `sessionId` 缺失或不相符，handler 會記錄後安全忽略該舊事件。

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
3. Go to **WooCommerce > Settings > OEN** to configure your MerchantID, Secret Key, and Webhook Secret
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
| Secret Key | OEN API Secret Key used as the Bearer token for Hosted Checkout Session API calls |
| Webhook Secret | HMAC secret used to verify the `OenPay-Signature` header; leave empty to skip signature verification |

### Payment Flow

**Credit Card:** Customer selects "OEN Credit" → Redirected to OEN checkout → Completes payment → Returns to thank-you page → Webhook updates order status

**CVS:** Customer selects "OEN Cvs" → Redirected to OEN checkout for payment code → Pays at convenience store → Webhook updates order status

### Hosted Checkout Session API

The plugin uses the OEN Hosted Checkout Session API:

- `POST /hosted-checkout/v1/sessions` to create a checkout session and receive a non-empty `id` plus `checkoutUrl`
- `GET /hosted-checkout/v1/sessions/{sessionId}` to fetch a single session

Send `Authorization: Bearer <Secret Key>` when calling these endpoints.

The plugin treats the returned session `id` as required. If the Hosted Checkout create response omits or empties that field, checkout fails instead of saving an empty `_oen_session_id` and weakening stale-attempt protection.

### Webhook Signature and Event Envelope

Hosted checkout webhooks send an `OenPay-Signature` header in this format:

```text
OenPay-Signature: t=1712345678,v1=<hex_hmac_sha256>
```

The signed payload is:

```text
{timestamp}.{raw_body}
```

Where `timestamp` is the `t` value from the header. The HMAC algorithm is `sha256` and the secret is the configured **Webhook Secret**. The plugin also enforces a default freshness tolerance of 300 seconds for `t`.

Webhook bodies arrive as an event envelope, with the business payload nested under `data`, for example:

```json
{
  "id": "evt_test_123",
  "type": "payment.succeeded",
  "data": {
    "sessionId": "sess_123",
    "orderId": "wc_1001",
    "transactionId": "txn_123",
    "transactionHid": "txn_hid_123",
    "status": "charged",
    "paymentMethod": "card",
    "paymentProvider": "oenpay"
  }
}
```

The webhook handler preserves the event `type` plus nested `data`, verifies with `transactionHid` when present, falls back to `GET /hosted-checkout/v1/sessions/{sessionId}` when only `sessionId` is available, updates the order only when the event type and verified session/transaction status agree on the same terminal success/failure outcome, and safely ignores stale events whose `sessionId` or `transactionHid` no longer matches the order's current payment attempt. When `_oen_session_id` is stored on the order, a missing or mismatched incoming `sessionId` is treated as stale.

## License

This plugin is licensed under [GPL-3.0-or-later](https://www.gnu.org/licenses/gpl-3.0.html).

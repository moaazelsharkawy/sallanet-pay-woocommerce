# sallanet Pay for WooCommerce

[العربية](#العربية) · English

Accept **Pi** and **ST** payments in your WooCommerce store through the sallanet Pay hosted checkout.

**[Download the latest release](https://github.com/moaazelsharkawy/sallanet-pay-woocommerce/releases/latest)**

## Features

- Hosted checkout: the buyer pays on the secure sallanet Pay page, then returns to your store.
- Built-in success and cancel pages: success returns to the WooCommerce "Order received" page, cancel returns to the cart. Nothing to create in WordPress.
- Order status updates from the return page check and from a signed webhook (HMAC-SHA256), with duplicate protection.
- Refunds from the WooCommerce Refund button (one refund per order, full or partial, at the coin rate the buyer paid).
- Escrow: money is held until the buyer confirms or the hold period ends. Verified digital stores can release it when an order is marked Completed.
- Pricing: store currency in Pi or ST (added by the plugin), USD with live Pi price, or a fixed rate for any currency.
- Works with the classic and block checkout, and with HPOS.

## Requirements

- WordPress 6.2+, WooCommerce 8.0+, PHP 7.4+
- A sallanet Pay developer account with an API key
- A public https site for webhooks

## Install

1. Download `sallanet-pay-woocommerce-x.y.z.zip` from [Releases](https://github.com/moaazelsharkawy/sallanet-pay-woocommerce/releases/latest).
2. WordPress admin → Plugins → Add New → Upload Plugin → choose the zip → Activate.
3. WooCommerce → Settings → Payments → **sallanet Pay**.
4. Paste your **API key** (and **App ID** if your key has more than one app).
5. Copy the **webhook URL** shown in the settings into your sallanet Pay developer dashboard, and paste the dashboard **webhook secret** into the plugin.
6. Choose the coin (Pi or ST) and the price conversion, then enable the method.
7. Place a small test order and refund it.

## Payment flow

| Step | API |
| --- | --- |
| Place order | `extapi-create-checkout` (link valid 5 minutes, the buyer can pay again from the order page) |
| Return page | `extapi-transaction-status` |
| Webhook | `payment.success`, `payment.confirmed`, `payment.refunded`, `webhook.test` at `/wp-json/sallanet-pay/v1/webhook` |
| Refund button | `extapi-refund` |
| Order completed (optional) | `extapi-confirm` |

## Development

`tests/` holds a fake API (`mock_api.php`) and a wp-cli test script (`plugin_test.php`, 26 checks). See `tests/README.md`.

To publish a release, bump `Version:` in `sallanet-pay/sallanet-pay.php` and run the **Release** workflow (or push a `v*` tag). It builds the zip and attaches it to a GitHub release.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

---

## العربية

اضافة لمتاجر WooCommerce تخلي المتجر يستقبل الدفع بعملة **Pi** وعملة **ST** من خلال صفحة الدفع الامنة في سلة باي.

**[تحميل اخر اصدار](https://github.com/moaazelsharkawy/sallanet-pay-woocommerce/releases/latest)**

**المميزات**

1. المشتري يدفع في صفحة سلة باي الامنة ثم يرجع للمتجر.
2. صفحة النجاح وصفحة الالغاء مدمجة في الاضافة. النجاح يرجع لصفحة تم استلام الطلب والالغاء يرجع للسلة. مش محتاج تعمل اي صفحة في ووردبريس.
3. حالة الطلب تتحدث تلقائيا عند رجوع المشتري ومن خلال ويب هوك موقع ومحمي من التكرار.
4. الاسترداد من زرار الاسترداد في WooCommerce. مسموح باسترداد واحد لكل طلب كامل او جزئي بنفس سعر العملة وقت الدفع.
5. المبلغ يفضل في الضمان لحد ما المشتري يأكد الاستلام او تخلص مدة الضمان. المتاجر الرقمية الموثقة تقدر تحرر المبلغ لما الطلب يبقى مكتمل.
6. التسعير بعملة المتجر Pi او ST او بالدولار مع سعر Pi لحظي او بسعر ثابت لاي عملة.
7. تشتغل مع صفحة الدفع القديمة والجديدة في WooCommerce.

**المتطلبات**

1. ووردبريس 6.2 او احدث و WooCommerce 8.0 او احدث و PHP 7.4 او احدث.
2. حساب مطور في سلة باي ومعاه مفتاح API.
3. موقع على https علشان الويب هوك يوصل.

**طريقة التركيب**

1. حمل ملف الاضافة المضغوط من صفحة الاصدارات.
2. من لوحة ووردبريس افتح الاضافات ثم اضف جديد ثم رفع اضافة واختار الملف ثم فعل.
3. افتح WooCommerce ثم الاعدادات ثم المدفوعات ثم sallanet Pay.
4. حط مفتاح API. ولو المفتاح مربوط بكذا تطبيق حط معرف التطبيق.
5. انسخ رابط الويب هوك الظاهر في الاعدادات وحطه في حساب المطور في سلة باي. وبعدين حط سر الويب هوك في الاضافة.
6. اختار العملة وطريقة التسعير وفعل طريقة الدفع.
7. اعمل طلب تجربة بمبلغ صغير وبعدين جرب الاسترداد.

**الرخصة**

الاضافة مفتوحة المصدر برخصة GPL 2.0 او احدث.

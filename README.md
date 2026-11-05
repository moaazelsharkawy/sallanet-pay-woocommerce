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

## Settings

WooCommerce → Settings → Payments → **sallanet Pay**

| Setting | What it does |
| --- | --- |
| Enable | Shows sallanet Pay at checkout. |
| Title / Description | What the buyer sees at checkout. |
| API key | From your sallanet Pay developer dashboard (shown once when created). **Administrators only.** |
| App ID | Only if your key has more than one linked app. **Administrators only.** |
| Webhook secret | Copy the webhook URL shown under this field into your dashboard, then paste the dashboard secret here. **Administrators only.** |
| Charge in | The coin the buyer pays: Pi or ST. |
| Price conversion | How the order total becomes coins (see below). |
| Fixed rates | How many units of a currency equal 1 coin, one per line, for example `EGP=50` and `USD=1.5`. A number alone applies to the store currency. |
| Instant settlement | Releases escrow when an order is marked Completed. Only for verified digital stores with instant settlement enabled by sallanet. Leave it off otherwise. |
| API base URL | Leave empty. |

Shop managers can use the payment method and change the other settings, but cannot see or change the API key, App ID, webhook secret or API base URL.

### Price conversion

sallanet Pay charges in coins (Pi or ST), while your products may be priced in USD, EGP or any other currency. The plugin turns the **final order total** (after coupons, discounts, shipping and taxes) into coins, in this order:

1. If the order currency is the coin itself (the plugin adds **Pi** and **ST** as store currencies), no conversion.
2. If the order currency is listed in **Fixed rates**, that rate is used.
3. If **Price conversion** is "USD prices, convert with the live Pi price" and the order is in USD, the live Pi price is used.
4. Otherwise the method shows an error and the buyer can choose another payment method.

The coin amount is saved on the order and refunds use the same rate.

### Other pricing plugins

- **Discounts, dynamic pricing, bundles, coupons:** they change the order total before payment, and the plugin uses the final total, so nothing is needed.
- **Currency switchers / multi-currency:** the order is saved in the buyer's chosen currency, and the plugin converts from that currency. Add a fixed rate line for every currency the switcher offers (USD can use the live Pi price instead). A currency without a rate cannot be paid with sallanet Pay.

## Payment flow

| Step | API |
| --- | --- |
| Place order | `extapi-create-checkout` (link valid 5 minutes, the buyer can pay again from the order page) |
| Return page | `extapi-transaction-status` |
| Webhook | `payment.success`, `payment.confirmed`, `payment.refunded`, `webhook.test` at `/wp-json/sallanet-pay/v1/webhook` |
| Refund button | `extapi-refund` |
| Order completed (optional) | `extapi-confirm` |

## Development

`tests/` holds a fake API (`mock_api.php`) and a wp-cli test script (`plugin_test.php`, 32 checks). See `tests/README.md`.

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

**الاعدادات**

تلاقيها في WooCommerce ثم الاعدادات ثم المدفوعات ثم sallanet Pay.

1. **Enable** يظهر سلة باي في صفحة الدفع.
2. **Title و Description** الاسم والوصف اللي المشتري بيشوفهم في صفحة الدفع.
3. **API key** مفتاح الربط من حساب المطور في سلة باي. بيظهر مرة واحدة وقت انشائه. للمدير فقط.
4. **App ID** معرف التطبيق. مطلوب بس لو المفتاح مربوط باكتر من تطبيق. للمدير فقط.
5. **Webhook secret** سر الويب هوك. انسخ رابط الويب هوك الظاهر تحت الحقل ده وحطه في حساب المطور وبعدين حط السر هنا. للمدير فقط.
6. **Charge in** العملة اللي المشتري بيدفع بيها Pi او ST.
7. **Price conversion** طريقة تحويل اجمالي الطلب لعملة. الشرح تحت.
8. **Fixed rates** كام وحدة من العملة تساوي عملة واحدة. كل عملة في سطر زي EGP=50 و USD=1.5. ولو كتبت رقم لوحده بيتطبق على عملة المتجر.
9. **Instant settlement** بيحرر المبلغ من الضمان لما الطلب يبقى مكتمل. للمتاجر الرقمية الموثقة اللي سلة باي فعلت لها التحرير الفوري بس. غير كده سيبه مقفول.
10. **API base URL** سيبه فاضي.

مدير المتجر يقدر يستخدم طريقة الدفع ويغير باقي الاعدادات. لكن مش هيشوف ولا هيغير المفتاح ومعرف التطبيق وسر الويب هوك ورابط API. دول لمدير الموقع بس.

**يعني ايه تحويل السعر**

سلة باي بتحصل الفلوس بعملة Pi او ST. لكن منتجات المتجر ممكن تكون بالدولار او الجنيه او اي عملة. الاضافة بتحول **الاجمالي النهائي للطلب** بعد الكوبونات والخصومات والشحن والضرايب لعملة بالترتيب ده.

1. لو عملة الطلب هي نفس العملة Pi او ST مفيش تحويل. الاضافة بتضيف Pi و ST لقايمة عملات المتجر.
2. لو عملة الطلب مكتوبة في Fixed rates بيتستخدم السعر ده.
3. لو اخترت USD prices convert with the live Pi price والطلب بالدولار بيتستخدم سعر Pi اللحظي.
4. غير كده بتظهر رسالة للمشتري ويقدر يختار طريقة دفع تانية.

مبلغ العملة بيتحفظ في الطلب. والاسترداد بيتم بنفس السعر.

**لو المتجر فيه اضافات تسعير تانية**

1. اضافات الخصومات والعروض والباقات والكوبونات بتغير اجمالي الطلب قبل الدفع. والاضافة بتاخد الاجمالي النهائي فمش محتاج تعمل حاجة.
2. اضافات تعدد العملات بتحفظ الطلب بالعملة اللي المشتري اختارها. والاضافة بتحول من العملة دي. اكتب سطر في Fixed rates لكل عملة موجودة في اضافة تعدد العملات. والدولار ممكن يستخدم سعر Pi اللحظي بدل كده. اي عملة من غير سعر مش هينفع تدفع بسلة باي.

**الرخصة**

الاضافة مفتوحة المصدر برخصة GPL 2.0 او احدث.

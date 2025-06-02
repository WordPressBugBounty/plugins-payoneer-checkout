### TODOs
| Filename | line # | TODO |
|:------|:------:|:------|
| [modules.local/analytics/inc/services.php](modules.local/analytics/inc/services.php#L306) | 306 | replace with production value |
| [modules.local/analytics/inc/services.php](modules.local/analytics/inc/services.php#L309) | 309 | replace with production value |
| [modules.local/checkout/inc/services.php](modules.local/checkout/inc/services.php#L132) | 132 | use min.js when script debug is enabled |
| [modules.local/checkout/inc/services.php](modules.local/checkout/inc/services.php#L272) | 272 | consider adding our own hidden block to checkout for our custom fields, |
| [modules.local/checkout/src/CheckoutModule.php](modules.local/checkout/src/CheckoutModule.php#L474) | 474 | supply a proper css file for this. Rework markup into something more responsive |
| [modules.local/embedded-payment/src/EmbeddedPaymentModule.php](modules.local/embedded-payment/src/EmbeddedPaymentModule.php#L225) | 225 | Remove this check when support for these versions is dropped |
| [modules.local/embedded-payment/src/EmbeddedPaymentModule.php](modules.local/embedded-payment/src/EmbeddedPaymentModule.php#L300) | 300 | Refactor the environment detection to use the current WP options. |
| [modules.local/embedded-payment/src/EmbeddedPaymentModule.php](modules.local/embedded-payment/src/EmbeddedPaymentModule.php#L304) | 304 | Determine whether we can get SRI details (URL + hash) from the LIST response, and drop this service. |
| [modules.local/embedded-payment/src/EmbeddedPaymentModule.php](modules.local/embedded-payment/src/EmbeddedPaymentModule.php#L311) | 311 | Consider returning the full SDK URL instead of only the environment. We could return the final `websdk.assets.umd.url.template` here. |
| [modules.local/embedded-payment/src/ListLongIdPaymentRequestValidator.php](modules.local/embedded-payment/src/ListLongIdPaymentRequestValidator.php#L56) | 56 | Put this in a const/enum or make it a constructor argument |
| [modules.local/list-session/inc/services.php](modules.local/list-session/inc/services.php#L240) | 240 | consider doing the same with the WC_Session List provider |
| [modules.local/list-session/src/ListSessionModule.php](modules.local/list-session/src/ListSessionModule.php#L62) | 62 | check if we still need this after Fetch command is implemented |
| [modules.local/payment-methods/inc/services.php](modules.local/payment-methods/inc/services.php#L364) | 364 | consider refactoring, these callbacks are almost the same. |
| [modules.local/settings/src/SettingsModule.php](modules.local/settings/src/SettingsModule.php#L758) | 758 | Remove/Refactor this when WC 9.7+ releases the revamped Payment Settings UX |
| [modules.local/wp/inc/factories.php](modules.local/wp/inc/factories.php#L18) | 18 | make our order_pay request be detected as a checkout in the same way as |
| [modules.local/wp/inc/services.php](modules.local/wp/inc/services.php#L345) | 345 | check if this can return false because called too early |
| [modules.local/embedded-payment/src/PaymentFieldsRenderer/WidgetPlaceholderFieldRenderer.php](modules.local/embedded-payment/src/PaymentFieldsRenderer/WidgetPlaceholderFieldRenderer.php#L55) | 55 | Reuse another message here. The actual HPP flow uses a merchant-configurable string |
| [modules.local/list-session/src/ListSession/ListSessionManager.php](modules.local/list-session/src/ListSession/ListSessionManager.php#L45) | 45 | turn this global method into a service so that we can use proper dependencies from our container |
| [modules.local/payment-methods/src/GatewayIconsRenderer/DynamicIconProvider.php](modules.local/payment-methods/src/GatewayIconsRenderer/DynamicIconProvider.php#L77) | 77 | logging |
| [modules.local/api/tests/php/Integration/Gateway/PaymentTestCase.php](modules.local/api/tests/php/Integration/Gateway/PaymentTestCase.php#L22) | 22 | replace 'inpsyde_payment_gateway.payoneer' with a mocked PaymentProcessor/ChargeCommand |
| [modules.local/webhooks/tests/php/Integration/Controller/PayoneerWebhooksControllerTest.php](modules.local/webhooks/tests/php/Integration/Controller/PayoneerWebhooksControllerTest.php#L87) | 87 | we never return anything else than 200 with empty body, so this assert is useless |
| [modules.local/webhooks/tests/php/Integration/Controller/PayoneerWebhooksControllerTest.php](modules.local/webhooks/tests/php/Integration/Controller/PayoneerWebhooksControllerTest.php#L203) | 203 | we never return anything else than 200 with empty body, so this assert is useless |
| [modules.local/webhooks/tests/php/Integration/Controller/PayoneerWebhooksControllerTest.php](modules.local/webhooks/tests/php/Integration/Controller/PayoneerWebhooksControllerTest.php#L306) | 306 | we never return anything else than 200 with empty body, so this assert is useless |
| [modules.local/webhooks/tests/php/Integration/Controller/PayoneerWebhooksControllerTest.php](modules.local/webhooks/tests/php/Integration/Controller/PayoneerWebhooksControllerTest.php#L435) | 435 | we never return anything else than 200 with empty body, so this assert is useless |
| [modules.local/webhooks/tests/php/Integration/Controller/PayoneerWebhooksControllerTest.php](modules.local/webhooks/tests/php/Integration/Controller/PayoneerWebhooksControllerTest.php#L503) | 503 | we never return anything else than 200 with empty body, so this assert is useless |

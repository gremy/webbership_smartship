<?php
declare(strict_types=1);

namespace {
  // Run: php tests/smoke-awb-payload.php
  define( 'ABSPATH', __DIR__ );
  function __( $t, $d = 'default' ) { return $t; }
  function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : $s; }

  function assert_true( bool $c, string $m ): void { if ( ! $c ) { throw new RuntimeException( $m ); } }
  function assert_same( $e, $a, string $m ): void { if ( $e !== $a ) { throw new RuntimeException( $m . ': ' . var_export( $a, true ) ); } }
}

namespace Ovride\Smartship\Settings {
  final class Settings {
    public static function iban(): string {
      return '';
    }
  }
}

namespace {
  // Minimal WC_Order fake.
  class FakeOrder {
    private $d;
    public function __construct( $d ) { $this->d = $d; }
    public function get_shipping_first_name() { return $this->d['s_first'] ?? ''; }
    public function get_shipping_last_name() { return $this->d['s_last'] ?? ''; }
    public function get_billing_first_name() { return $this->d['b_first'] ?? ''; }
    public function get_billing_last_name() { return $this->d['b_last'] ?? ''; }
    public function get_shipping_address_1() { return $this->d['s_addr'] ?? ''; }
    public function get_billing_address_1() { return $this->d['b_addr'] ?? ''; }
    public function get_billing_email() { return $this->d['email'] ?? ''; }
    public function get_shipping_phone() { return $this->d['s_phone'] ?? ''; }
    public function get_billing_phone() { return $this->d['b_phone'] ?? ''; }
    public function get_total() { return $this->d['total'] ?? '0'; }
    public function get_payment_method() { return $this->d['pay'] ?? ''; }
    public function is_paid() { return $this->d['paid'] ?? false; }
    public function get_order_number() { return $this->d['num'] ?? '0'; }
    public function get_items() { return $this->d['items'] ?? []; }
  }
  class FakeItem {
    private $p; public function __construct( $p ) { $this->p = $p; }
    public function get_product() { return $this->p; }
  }
  class FakeProduct {
    private $w; public function __construct( $w ) { $this->w = $w; }
    public function get_weight() { return $this->w; }
  }

  require_once __DIR__ . '/../modules/awb/data/class-awb-payload.php';

  // recipient: shipping name preferred, billing phone fallback, resolved city id.
  $o = new FakeOrder( [ 's_first' => 'Ion', 's_last' => 'Pop', 's_addr' => 'Str. A 1', 'email' => 't@e.com', 's_phone' => '', 'b_phone' => '0720000000' ] );
  $rec = Ovride\Smartship\Modules\Awb\Data\AwbPayload::recipient_from_order( $o, [ 'city_id' => 263852 ] );
  assert_same( 'Ion Pop', $rec['name'], 'recipient name' );
  assert_same( '0720000000', $rec['phone'], 'phone fallback to billing' );
  assert_same( 263852, $rec['city'], 'recipient city id' );
  assert_same( 'RO', $rec['country'], 'recipient country' );

  // content: weight floor 1kg, COD from total when unpaid, package_content from order number.
  $o2 = new FakeOrder( [ 'num' => '1234', 'total' => '149.99', 'paid' => false, 'items' => [ new FakeItem( new FakeProduct( '0.2' ) ) ] ] );
  $c = Ovride\Smartship\Modules\Awb\Data\AwbPayload::content_from_order( $o2 );
  assert_same( 1.0, $c['weight'], 'weight floor 1kg' );
  assert_true( abs( $c['cash_on_delivery'] - 149.99 ) < 0.001, 'COD from total (unpaid)' );
  assert_true( strpos( $c['package_content'], '1234' ) !== false, 'package_content has order number' );

  // content: COD 0 when paid and not cod.
  $o3 = new FakeOrder( [ 'num' => '9', 'total' => '50', 'paid' => true, 'pay' => 'card', 'items' => [ new FakeItem( new FakeProduct( '2' ) ) ] ] );
  $c3 = Ovride\Smartship\Modules\Awb\Data\AwbPayload::content_from_order( $o3 );
  assert_same( 0.0, $c3['cash_on_delivery'], 'COD 0 when paid+card' );
  assert_same( 2.0, $c3['weight'], 'weight from items' );

  // content: paid + cod still ships COD (exercises the `|| 'cod' === payment_method` branch).
  $o4 = new FakeOrder( [ 'num' => '7', 'total' => '80', 'paid' => true, 'pay' => 'cod', 'items' => [ new FakeItem( new FakeProduct( '1' ) ) ] ] );
  $c4 = Ovride\Smartship\Modules\Awb\Data\AwbPayload::content_from_order( $o4 );
  assert_same( 80.0, $c4['cash_on_delivery'], 'COD from total when paid+cod' );

  // content: empty product weight contributes 0; 0.3 < 1.0 floors to 1kg.
  $o5 = new FakeOrder( [ 'num' => '5', 'total' => '10', 'paid' => true, 'pay' => 'card', 'items' => [ new FakeItem( new FakeProduct( '' ) ), new FakeItem( new FakeProduct( '0.3' ) ) ] ] );
  $c5 = Ovride\Smartship\Modules\Awb\Data\AwbPayload::content_from_order( $o5 );
  assert_same( 1.0, $c5['weight'], 'missing weight contributes 0, floors to 1kg' );

  echo "smoke-awb-payload: all assertions passed\n";
}

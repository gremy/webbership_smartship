<?php
declare(strict_types=1);

namespace {
  // Run: php tests/smoke-awb-payload.php
  define( 'ABSPATH', __DIR__ );
  function __( $t, $d = 'default' ) { return $t; }
  function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : $s; }

  function assert_true( bool $c, string $m ): void { if ( ! $c ) { throw new RuntimeException( $m ); } }
  function assert_same( $e, $a, string $m ): void { if ( $e !== $a ) { throw new RuntimeException( $m . ': ' . var_export( $a, true ) ); } }

  // wc_get_weight() stub: identity when the store unit is 'kg' (the default here,
  // so every OTHER assertion in this file is unaffected), real conversion otherwise
  // so the g/lbs/oz -> kg path (Fix 1) is exercised too.
  $GLOBALS['wc_weight_unit'] = 'kg';
  function wc_get_weight( $weight, $to_unit, $from_unit = '' ) {
    $from_unit = '' !== $from_unit ? $from_unit : $GLOBALS['wc_weight_unit'];
    if ( $from_unit === $to_unit ) { return $weight; }
    $to_kg = [ 'kg' => 1.0, 'g' => 0.001, 'lbs' => 0.453592, 'oz' => 0.0283495 ];
    return ( $weight * ( $to_kg[ $from_unit ] ?? 1.0 ) ) / ( $to_kg[ $to_unit ] ?? 1.0 );
  }
}

namespace Webbership\Smartship\Settings {
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
    public function get_shipping_address_2() { return $this->d['s_addr2'] ?? ''; }
    public function get_billing_address_2() { return $this->d['b_addr2'] ?? ''; }
    public function get_billing_email() { return $this->d['email'] ?? ''; }
    public function get_shipping_phone() { return $this->d['s_phone'] ?? ''; }
    public function get_billing_phone() { return $this->d['b_phone'] ?? ''; }
    public function get_shipping_country() { return $this->d['s_country'] ?? ''; }
    public function get_billing_country() { return $this->d['b_country'] ?? ''; }
    public function get_total() { return $this->d['total'] ?? '0'; }
    public function get_payment_method() { return $this->d['pay'] ?? ''; }
    public function is_paid() { return $this->d['paid'] ?? false; }
    public function get_order_number() { return $this->d['num'] ?? '0'; }
    public function get_items() { return $this->d['items'] ?? []; }
  }
  class FakeItem {
    private $p; private $q;
    public function __construct( $p, $q = 1 ) { $this->p = $p; $this->q = $q; }
    public function get_product() { return $this->p; }
    public function get_quantity() { return $this->q; }
  }
  class FakeProduct {
    private $w; public function __construct( $w ) { $this->w = $w; }
    public function get_weight() { return $this->w; }
  }

  require_once __DIR__ . '/../modules/awb/data/class-awb-payload.php';

  // recipient: shipping name preferred, billing phone fallback, resolved city id.
  $o = new FakeOrder( [ 's_first' => 'Ion', 's_last' => 'Pop', 's_addr' => 'Str. A 1', 'email' => 't@e.com', 's_phone' => '', 'b_phone' => '0720000000' ] );
  $rec = Webbership\Smartship\Modules\Awb\Data\AwbPayload::recipient_from_order( $o, [ 'city_id' => 263852 ] );
  assert_same( 'Ion Pop', $rec['name'], 'recipient name' );
  assert_same( '0720000000', $rec['phone'], 'phone fallback to billing' );
  assert_same( 263852, $rec['city'], 'recipient city id' );
  assert_same( 'RO', $rec['country'], 'recipient country' );

  // recipient: country comes from the order (shipping first, billing fallback), not
  // hardcoded RO — international destinations must be able to build an AWB payload.
  $ode = new FakeOrder( [ 's_first' => 'Max', 's_last' => 'Mustermann', 's_addr' => 'Str. B 2', 's_country' => 'DE' ] );
  $recde = Webbership\Smartship\Modules\Awb\Data\AwbPayload::recipient_from_order( $ode, [ 'city_id' => 1 ] );
  assert_same( 'DE', $recde['country'], 'recipient country: taken from shipping country' );

  $obill = new FakeOrder( [ 'b_first' => 'Jane', 'b_last' => 'Doe', 'b_addr' => 'Str. C 3', 'b_country' => 'FR' ] );
  $recbill = Webbership\Smartship\Modules\Awb\Data\AwbPayload::recipient_from_order( $obill, [ 'city_id' => 1 ] );
  assert_same( 'FR', $recbill['country'], 'recipient country: falls back to billing country' );

  // recipient: address_2 joined to address_1 from the same (shipping) source.
  $oa = new FakeOrder( [ 's_addr' => 'Str. A 1', 's_addr2' => 'Bl. 2 Ap. 3' ] );
  $reca = Webbership\Smartship\Modules\Awb\Data\AwbPayload::recipient_from_order( $oa, [ 'city_id' => 1 ] );
  assert_same( 'Str. A 1 Bl. 2 Ap. 3', $reca['address'], 'recipient address joins address_1 + address_2' );

  // content: weight floor 1kg, COD from total when unpaid, package_content from order number.
  $o2 = new FakeOrder( [ 'num' => '1234', 'total' => '149.99', 'paid' => false, 'items' => [ new FakeItem( new FakeProduct( '0.2' ) ) ] ] );
  $c = Webbership\Smartship\Modules\Awb\Data\AwbPayload::content_from_order( $o2 );
  assert_same( 1.0, $c['weight'], 'weight floor 1kg' );
  assert_true( abs( $c['cash_on_delivery'] - 149.99 ) < 0.001, 'COD from total (unpaid)' );
  assert_true( strpos( $c['package_content'], '1234' ) !== false, 'package_content has order number' );

  // content: COD 0 when paid and not cod.
  $o3 = new FakeOrder( [ 'num' => '9', 'total' => '50', 'paid' => true, 'pay' => 'card', 'items' => [ new FakeItem( new FakeProduct( '2' ) ) ] ] );
  $c3 = Webbership\Smartship\Modules\Awb\Data\AwbPayload::content_from_order( $o3 );
  assert_same( 0.0, $c3['cash_on_delivery'], 'COD 0 when paid+card' );
  assert_same( 2.0, $c3['weight'], 'weight from items' );

  // content: paid + cod still ships COD (exercises the `|| 'cod' === payment_method` branch).
  $o4 = new FakeOrder( [ 'num' => '7', 'total' => '80', 'paid' => true, 'pay' => 'cod', 'items' => [ new FakeItem( new FakeProduct( '1' ) ) ] ] );
  $c4 = Webbership\Smartship\Modules\Awb\Data\AwbPayload::content_from_order( $o4 );
  assert_same( 80.0, $c4['cash_on_delivery'], 'COD from total when paid+cod' );

  // content: empty product weight contributes 0; 0.3 < 1.0 floors to 1kg.
  $o5 = new FakeOrder( [ 'num' => '5', 'total' => '10', 'paid' => true, 'pay' => 'card', 'items' => [ new FakeItem( new FakeProduct( '' ) ), new FakeItem( new FakeProduct( '0.3' ) ) ] ] );
  $c5 = Webbership\Smartship\Modules\Awb\Data\AwbPayload::content_from_order( $o5 );
  assert_same( 1.0, $c5['weight'], 'missing weight contributes 0, floors to 1kg' );

  // content: weight is multiplied by item quantity, not just per-line product weight.
  $o6 = new FakeOrder( [ 'num' => '6', 'total' => '10', 'paid' => true, 'pay' => 'card', 'items' => [ new FakeItem( new FakeProduct( '2' ), 5 ) ] ] );
  $c6 = Webbership\Smartship\Modules\Awb\Data\AwbPayload::content_from_order( $o6 );
  assert_same( 10.0, $c6['weight'], 'weight multiplies per-unit weight by quantity' );

  // Fix 1: store weight unit 'g' -> summed weight is converted to kg before the 1kg floor.
  $GLOBALS['wc_weight_unit'] = 'g';
  $o7 = new FakeOrder( [ 'num' => '7', 'total' => '10', 'paid' => true, 'pay' => 'card', 'items' => [ new FakeItem( new FakeProduct( '250' ) ) ] ] );
  $c7 = Webbership\Smartship\Modules\Awb\Data\AwbPayload::content_from_order( $o7 );
  assert_same( 1.0, $c7['weight'], '250g floors to 1kg after conversion (not 250kg)' );
  $o8 = new FakeOrder( [ 'num' => '8', 'total' => '10', 'paid' => true, 'pay' => 'card', 'items' => [ new FakeItem( new FakeProduct( '2500' ) ) ] ] );
  $c8 = Webbership\Smartship\Modules\Awb\Data\AwbPayload::content_from_order( $o8 );
  assert_same( 2.5, $c8['weight'], '2500g converts to 2.5kg' );
  $GLOBALS['wc_weight_unit'] = 'kg';

  // Fix 1 fallback: to_kg() is a no-op when wc_get_weight() doesn't exist (standalone/no WP).
  assert_true( function_exists( 'wc_get_weight' ), 'sanity: stub is defined in this process' );
  assert_same( 3.0, Webbership\Smartship\Modules\Awb\Data\AwbPayload::to_kg( 3.0 ), 'to_kg is identity when store unit is kg' );

  // Fix 3: sector canonicalization at the payload boundary — '' (CityResolver's
  // "unknown Bucharest sector" internal signal) must never reach the API as ''.
  $rec_no_sector = Webbership\Smartship\Modules\Awb\Data\AwbPayload::recipient_from_order( $o, [ 'city_id' => 1, 'sector' => '' ] );
  assert_same( '0', $rec_no_sector['sector'], "recipient: '' sector canonicalizes to '0'" );
  $rec_with_sector = Webbership\Smartship\Modules\Awb\Data\AwbPayload::recipient_from_order( $o, [ 'city_id' => 1, 'sector' => '3' ] );
  assert_same( '3', $rec_with_sector['sector'], 'recipient: a real sector passes through' );
  $sender_no_sector = Webbership\Smartship\Modules\Awb\Data\AwbPayload::sender_from_account( [ 'sector' => '' ] );
  assert_same( '0', $sender_no_sector['sector'], "sender: '' sector canonicalizes to '0'" );
  assert_same( '0', Webbership\Smartship\Modules\Awb\Data\AwbPayload::canonical_sector( null ), 'canonical_sector: null -> 0' );
  assert_same( '0', Webbership\Smartship\Modules\Awb\Data\AwbPayload::canonical_sector( '' ), "canonical_sector: '' -> 0" );
  assert_same( '5', Webbership\Smartship\Modules\Awb\Data\AwbPayload::canonical_sector( '5' ), 'canonical_sector: real value passes through' );

  // order_weight_kg(): same computed-weight logic content_from_order() uses for its default.
  $o9 = new FakeOrder( [ 'items' => [ new FakeItem( new FakeProduct( '2' ), 3 ) ] ] );
  assert_same( 6.0, Webbership\Smartship\Modules\Awb\Data\AwbPayload::order_weight_kg( $o9 ), 'order_weight_kg: sums per-unit weight * qty' );
  $o10 = new FakeOrder( [ 'items' => [ new FakeItem( new FakeProduct( '0.2' ) ) ] ] );
  assert_same( 1.0, Webbership\Smartship\Modules\Awb\Data\AwbPayload::order_weight_kg( $o10 ), 'order_weight_kg: floors at 1.0kg' );

  // content_from_order() package overrides: a posted weight wins outright, no 1.0kg floor
  // (only the 0.05kg sanity floor) — the merchant is stating the real packed weight.
  $o11 = new FakeOrder( [ 'num' => '11', 'total' => '10', 'paid' => true, 'pay' => 'card', 'items' => [ new FakeItem( new FakeProduct( '2' ) ) ] ] );
  $c11 = Webbership\Smartship\Modules\Awb\Data\AwbPayload::content_from_order( $o11, [ 'weight' => 0.5 ] );
  assert_same( 0.5, $c11['weight'], 'posted weight override: no 1.0kg floor' );
  assert_same( 10, $c11['length'], 'no length override: falls back to 10' );

  // Dims honored when posted.
  $c12 = Webbership\Smartship\Modules\Awb\Data\AwbPayload::content_from_order( $o11, [ 'length' => 30, 'width' => 20, 'height' => 15 ] );
  assert_same( 30, $c12['length'], 'posted length honored' );
  assert_same( 20, $c12['width'], 'posted width honored' );
  assert_same( 15, $c12['height'], 'posted height honored' );
  assert_same( 2.0, $c12['weight'], 'no weight override: falls back to computed weight' );

  // Invalid/absent keys are simply absent from $package -> every default applies untouched.
  $c13 = Webbership\Smartship\Modules\Awb\Data\AwbPayload::content_from_order( $o11, [] );
  assert_same( 2.0, $c13['weight'], 'empty package: computed weight default' );
  assert_same( 10, $c13['length'], 'empty package: length default' );
  assert_same( 10, $c13['width'], 'empty package: width default' );
  assert_same( 10, $c13['height'], 'empty package: height default' );

  // build() forwards $package into content_from_order().
  $built = Webbership\Smartship\Modules\Awb\Data\AwbPayload::build( $o11, [ 'city_id' => 1 ], [], 5, [ 'weight' => 0.5, 'length' => 25 ] );
  assert_same( 0.5, $built['content']['weight'], 'build(): forwards weight override' );
  assert_same( 25, $built['content']['length'], 'build(): forwards length override' );
  assert_same( 10, $built['content']['width'], 'build(): unset dims still default' );

  echo "smoke-awb-payload: all assertions passed\n";
}

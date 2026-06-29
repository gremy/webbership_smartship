( function ( $ ) {
  function box() { return $( '.ovride-ss-awb' ); }
  function orderId() { return box().data( 'order' ); }
  $( document ).on( 'click', '.ovride-ss-estimate', function () {
    var $msg = $( '.ovride-ss-msg' ).text( 'Estimating…' );
    $.post( OvrideSmartShip.ajax, { action: 'ovride_smartship_estimate', _ajax_nonce: OvrideSmartShip.nonce, order_id: orderId() } )
      .done( function ( r ) {
        if ( ! r.success ) { $msg.text( r.data && r.data.message ? r.data.message : 'Failed' ); return; }
        $msg.text( '' );
        var $c = $( '.ovride-ss-couriers' ).empty();
        ( r.data.costs || [] ).forEach( function ( c ) {
          var id = 'ss-c-' + c.courier_id;
          $c.append( $( '<label/>' ).append(
            $( '<input type="radio" name="ss_courier"/>' ).val( c.courier_id ).attr( 'id', id ),
            ' ', document.createTextNode( c.courier_name + ' — ' + c.cost + ' lei (' + ( c.delivery_date || '' ) + ')' ), '<br/>'
          ) );
        } );
        $c.append( $( '<button type="button" class="button button-primary ovride-ss-issue">' ).text( 'Issue AWB' ) );
      } );
  } );
  $( document ).on( 'click', '.ovride-ss-issue', function () {
    var courier = $( 'input[name=ss_courier]:checked' ).val();
    if ( ! courier ) { $( '.ovride-ss-msg' ).text( 'Pick a courier.' ); return; }
    $( '.ovride-ss-msg' ).text( 'Issuing…' );
    $.post( OvrideSmartShip.ajax, { action: 'ovride_smartship_issue', _ajax_nonce: OvrideSmartShip.nonce, order_id: orderId(), courier_id: courier } )
      .done( function ( r ) {
        if ( ! r.success ) { $( '.ovride-ss-msg' ).text( r.data && r.data.message ? r.data.message : 'Failed' ); return; }
        window.location.reload();
      } );
  } );
} )( jQuery );

<?php
declare( strict_types = 1 );

test(
	'signature: a correct Stripe-Signature passes',
	static function (): void {
		$payload = '{"id":"evt_1","type":"payment_intent.succeeded"}';
		$header  = SMPW_Signature::header( $payload, 'test-secret', 1790000000 );
		assert_true( SMPW_Signature::verify( $payload, $header, 'test-secret', 1790000100 ) );
	}
);

test(
	'signature: any of several v1 signatures may match (secret rotation)',
	static function (): void {
		$payload = '{"id":"evt_2"}';
		$good    = SMPW_Signature::header( $payload, 'new-secret', 1790000000 );
		$header  = 't=1790000000,v1=' . str_repeat( 'a', 64 ) . ',' . substr( $good, strpos( $good, 'v1=' ) );
		assert_true( SMPW_Signature::verify( $payload, $header, 'new-secret', 1790000000 ) );
	}
);

test(
	'signature: wrong secret, changed payload, old timestamp, garbage and empty values fail',
	static function (): void {
		$payload = '{"id":"evt_3"}';
		$header  = SMPW_Signature::header( $payload, 'test-secret', 1790000000 );
		assert_same( false, SMPW_Signature::verify( $payload, $header, 'other-secret', 1790000000 ), 'wrong secret' );
		assert_same( false, SMPW_Signature::verify( $payload . ' ', $header, 'test-secret', 1790000000 ), 'changed payload' );
		assert_same( false, SMPW_Signature::verify( $payload, $header, 'test-secret', 1790000301 ), 'older than 5 minutes' );
		assert_same( false, SMPW_Signature::verify( $payload, 'garbage', 'test-secret', 1790000000 ), 'garbage header' );
		assert_same( false, SMPW_Signature::verify( $payload, 't=abc,v1=def', 'test-secret', 1790000000 ), 'non-numeric t' );
		assert_same( false, SMPW_Signature::verify( $payload, '', 'test-secret', 1790000000 ), 'empty header' );
		assert_same( false, SMPW_Signature::verify( $payload, $header, '', 1790000000 ), 'no secret configured' );
	}
);

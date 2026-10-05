/**
 * WeldPress bridge for cardano-easy-mint.
 *
 * The site nav (WeldPress) is the canonical, site-wide wallet connector. The
 * mint plugin ships its OWN connector (CardanoMintWallet) with its OWN saved
 * wallet key, `cardano_mint_last_wallet`. Nothing tied that key to the nav, so
 * a nav "disconnect" left the mint connector still holding the old wallet
 * (e.g. VESPR) — which then looked like the wallet "never logging out" and the
 * mint connector trying to reconnect it on its own.
 *
 * This bridge makes the mint connector defer to the nav connector:
 *
 *   1. Clears `cardano_mint_last_wallet` synchronously at script eval, before
 *      cardano-nft-mint.js initializes — so a stale wallet from a previous
 *      session can never be auto-reconnected.
 *
 *   2. Registers WeldPress.wallet.onDisconnect to re-clear the key — so a
 *      disconnect from anywhere (the nav, programmatic) truly logs the user
 *      out of the mint connector too, not just WeldPress.
 *
 * It deliberately does NOT call enable()/connect on page load: that was the
 * every-page Vespr takeover. The mint flow connects only when the user acts on
 * the actual mint page.
 */

// Synchronous clear at script eval — belt for the onDisconnect suspenders below,
// and it runs before cardano-nft-mint.js's init regardless of load order.
try { localStorage.removeItem( 'cardano_mint_last_wallet' ); } catch ( e ) {}

(function () {
	'use strict';

	function bridge() {
		if ( ! window.WeldPress || ! window.WeldPress.wallet ) return false;
		if ( window.__cmeWeldBridgeArmed ) return true;
		window.__cmeWeldBridgeArmed = true;

		// A disconnect from anywhere wipes the mint connector's saved wallet too,
		// so "log out" in the nav logs the user out of every connector.
		if ( typeof window.WeldPress.wallet.onDisconnect === 'function' ) {
			window.WeldPress.wallet.onDisconnect( function () {
				try { localStorage.removeItem( 'cardano_mint_last_wallet' ); } catch ( e ) {}
			} );
		}

		return true;
	}

	// WeldPress may load slightly after this script. Try now, then poll briefly.
	function attemptBridge() {
		if ( bridge() ) return;
		let attempts = 0;
		const timer = setInterval( function () {
			if ( bridge() || ++attempts > 30 ) clearInterval( timer );
		}, 200 );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', attemptBridge );
	} else {
		attemptBridge();
	}
})();

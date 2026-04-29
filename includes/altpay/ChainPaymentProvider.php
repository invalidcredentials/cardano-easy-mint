<?php
namespace CardanoMintPay\AltPay;

if (!defined('ABSPATH')) exit;

/**
 * ChainPaymentProvider
 *
 * Each chain (BTC / ETH / SOL) implements this interface so the rest of the
 * altpay system can stay chain-agnostic. AltPayService is the only thing
 * that resolves a provider; controllers and views never touch providers
 * directly.
 *
 * Amounts are passed as strings throughout because ETH wei (1e18 per ETH)
 * does not fit in a 64-bit int. Use GMP / BCMath / string math everywhere
 * downstream of this interface.
 */
interface ChainPaymentProvider {

    /** Lowercase chain code: 'btc' | 'eth' | 'sol'. */
    public function chainCode(): string;

    /**
     * Generate a brand new HD parent wallet on the given network.
     *
     * @param string $network 'mainnet' | 'testnet' | 'signet' | 'devnet' (chain-dependent)
     * @return array {
     *     @type string $xprv      Encoded extended private key (chain-native format)
     *     @type string $xpub      Encoded extended public key
     *     @type string $address0  Receive address at derivation index 0
     *     @type string $mnemonic  BIP39 mnemonic that produced the seed
     * }
     */
    public function generateParentWallet(string $network): array;

    /**
     * Import a parent wallet from either an xprv or a BIP39 mnemonic.
     * Returns the same shape as generateParentWallet, minus the mnemonic if
     * an xprv was supplied directly.
     */
    public function importParentWallet(string $xprvOrMnemonic, string $network): array;

    /**
     * Derive the child receive address at the given index for a stored parent.
     *
     * @param int $walletId Row id in wp_cm_chain_wallets
     * @param int $index    Hardened/non-hardened index per chain convention
     */
    public function deriveChildAddress(int $walletId, int $index): string;

    /**
     * Convert a USD price into the chain's smallest unit (sats / wei / lamports)
     * given a USD-per-unit rate.
     *
     * @param float  $usd  USD-denominated price
     * @param float  $rate USD value of 1 unit (1 BTC, 1 ETH, 1 SOL)
     * @return string      Amount in smallest unit, as a decimal string
     */
    public function expectedAmountMinor(float $usd, float $rate): string;

    /**
     * Query the chain RPC for an address's current balance + last seen tx.
     *
     * @return array {
     *     @type string  $balance_minor   Balance in smallest unit, as a string
     *     @type ?string $last_tx         Most recent inbound tx hash if any
     *     @type ?int    $confirmations   Confirmation count for last_tx, if known
     * }
     */
    public function checkAddressBalance(string $address, string $network): array;

    /**
     * Build, sign, and broadcast a refund / sweep tx FROM a derived child address
     * TO an external address. Decrypts the parent xprv, derives the child key,
     * builds a chain-native tx, signs it, and submits via the chain RPC.
     *
     * @param int    $walletId    Row id in wp_cm_chain_wallets
     * @param int    $childIndex  HD index used to derive the source key
     * @param string $toAddr      Destination address
     * @param string $amountMinor Amount in smallest unit, as a decimal string
     * @return array { @type string $tx_hash, @type string $raw_tx }
     */
    public function buildAndBroadcastRefund(
        int $walletId,
        int $childIndex,
        string $toAddr,
        string $amountMinor
    ): array;

    /** Public block-explorer URL for a given tx hash on the given network. */
    public function explorerTxUrl(string $hash, string $network): string;
}

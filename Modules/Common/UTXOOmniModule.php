<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*
 *  This module process Omni transfers. Requires an Omni Core node to function.
 *  It processes all known transfer types. Returns both event and currency data.
 *
 *  Omni documentation:
 *  *  https://github.com/OmniLayer/spec/blob/master/OmniSpecification.adoc
 *  *  https://github.com/OmniLayer/omnicore/blob/master/src/omnicore/tx.cpp
 *  *  https://github.com/OmniLayer/omnicore/blob/master/src/omnicore/doc/rpc-api.md
 *  *  https://github.com/OmniLayer/spec/blob/master/OmniSpecification.adoc#sec-initial-token-distribution-via-the-exodus-address
 *  *  https://github.com/OmniLayer/omnicore/blob/1c0ae8ae01ed79ae3715a16124e8acb98cb67800/src/omnicore/omnicore.cpp#L533
 *
 *  Transfer types (1025-1027 are 3xpl special types):
 *  *  1025 - Genesis for token ids 1 and 2
 *  *  0 - Simple Send (null for Simple Send, 1027 for Crowdsale Purchase)
 *  *  3 - Send To Owners
 *  *  4 - Send All
 *  *  5 - Send Non Fungible - not implemented and not used
 *  *  20 - DEx Sell Offer
 *  *  22 - DEx Accept Offer
 *  *  -1 - DEx Purchase (1026)
 *  *  25 - MetaDEx trade (25A for initiazlization, 25B for actual exchange)
 *  *  26 - MetaDEx cancel-price
 *  *  27 - MetaDEx cancel-pair
 *  *  28 - MetaDEx cancel-ecosystem
 *  *  50 - Create Property - Fixed
 *  *  51 - Create Property - Variable
 *  *  53 - Close Crowdsale
 *  *  54 - Create Property - Manual
 *  *  55 - Grant Property Tokens
 *  *  56 - Revoke Property Tokens
 *  *  70 - Change Issuer Address
 *  *  71 - Enable Freezing
 *  *  72 - Disable Freezing
 *  *  73 - AddDelegate
 *  *  74 - RemoveDelegate
 *  *  185 - Freeze Property Tokens
 *  *  186 - Unfreeze Property Tokens
 *  *  200 - AnyData
 *  *  201 - NonFungibleData
 *  *  65533 - Deactivation
 *  *  65534 - Feature Activation
 *  *  65535 - Alert
 *
 *  Note that this module doesn't take into account that there are three different balance types: balance, reserved, frozen.
 *  Note that Omni may start supporting bech32 addresses in future: https://github.com/OmniLayer/omnicore/issues/1211
 */

abstract class UTXOOmniModule extends CoreModule
{
    use UTXOTraits;

    //

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'currency', 'address', 'effect', 'failed', 'extra'];
    public ?array $events_table_nullable_fields = ['transaction', 'currency', 'extra']; // currency can be null here for some failed transactions (failed token creation)

    public ?array $currencies_table_fields = ['id', 'name', 'decimals', 'description'];
    public ?array $currencies_table_nullable_fields = [];

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Type; // `extra` contains transfer type
    public ?array $extra_data_details = [
        //'0'     => 'Simple Send', // We use `null` instead to save space
        '3'     => 'Send To Owners',
        '4'     => 'Send All',
        '5'     => 'Send Non Fungible',
        '20'    => 'DEx Sell Offer',
        '22'    => 'DEx Accept Offer',
        '25'    => 'DEx Trade',
        '26'    => 'DEx Cancel Price',
        '27'    => 'DEx Cancel Pair',
        '28'    => 'DEx Cancel Ecosystem',
        '50'    => 'Create Property Fixed',
        '51'    => 'Create Property Variable',
        '53'    => 'Close Crowdsale',
        '54'    => 'Create Property Manual',
        '55'    => 'Grant Property Tokens',
        '56'    => 'Revoke Property Tokens',
        '70'    => 'Change Issuer Address',
        '71'    => 'Enable Freezing',
        '72'    => 'Disable Freezing',
        '73'    => 'Add Delegate',
        '74'    => 'Remove Delegate',
        '185'   => 'Freeze Property Tokens',
        '186'   => 'Unfreeze Property Tokens',
        '200'   => 'Any Data',
        '201'   => 'Non-Fungible Data',
        '1025'  => 'Genesis',
        '1026'  => 'DEx Purchase',
        '65533' => 'Deactivation',
        '65534' => 'Feature Activation',
        '65535' => 'Alert',
    ];

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = true;
    public ?bool $allow_empty_return_events = true;
    public ?bool $allow_empty_return_currencies = true;

    public ?bool $mempool_implemented = true;
    public ?bool $forking_implemented = true;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWithout0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::EvenOrMixed;
    public ?CurrencyFormat $currency_format = CurrencyFormat::Numeric; // Currency ids are numbers in Omni
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None;
    public ?array $special_addresses = ['the-void'];
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?int $genesis_block = null;
    public ?string $genesis_json = null;

    //

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {
        if (is_null($this->genesis_block)) throw new DeveloperError("`genesis_block` is not set");
        if (is_null($this->genesis_json)) throw new DeveloperError("`genesis_json` is not set");
    }

    final public function pre_process_block($block_id)
    {
        $omni_data = [];
        $order_in_block = [];

        if ($block_id !== MEMPOOL)
        {
            // Block data

            $block = requester_single($this->select_node(), params: ['method' => 'getblock', 'params' => [$this->block_hash]],
                result_in: 'result', timeout: $this->timeout);

            $this->block_time = date('Y-m-d H:i:s', (int)$block['time']);

            // Omni transfers

            $transactions = requester_single($this->select_node(), params: ['method' => 'omni_listblocktransactions', 'params' => [(int)$block_id]],
                result_in: 'result', timeout: $this->timeout);

            $multi_curl = [];

            $ij = 0;

            foreach ($transactions as $transaction_hash)
            {
                $multi_curl[] = requester_multi_prepare($this->select_node(),
                    params: ['method' => 'omni_gettransaction', 'params' => [$transaction_hash]],
                    timeout: $this->timeout);

                $order_in_block[$transaction_hash] = $ij++;
            }

            $curl_results = requester_multi($multi_curl, limit: envm($this->module, 'REQUESTER_THREADS'), timeout: $this->timeout);

            foreach ($curl_results as $v)
            {
                $omni_data[] = requester_multi_process($v, result_in: 'result');
            }
        }
        else // Mempool
        {
            $omni_data_mempool = requester_single($this->select_node(), params: ['method' => 'omni_listpendingtransactions'],
                result_in: 'result', timeout: $this->timeout);

            foreach ($omni_data_mempool as $transaction)
            {
                if (!isset($this->processed_transactions[$transaction['txid']]))
                {
                    $omni_data[] = $transaction;
                    $order_in_block[$transaction['txid']] = 0;
                }
            }
        }

        // Processing events...

        $events = [];

        foreach ($omni_data as $omni_event)
        {
            if (!isset($omni_event['txid']))
            {
                throw new ModuleError('`txid` is not set');
            }

            if ($block_id === MEMPOOL) $omni_event['blocktime'] = time();
            if ($block_id === MEMPOOL) $omni_event['valid'] = false;

            if (!isset($omni_event['type_int']))
            {
                if (isset($omni_event['type']) && $omni_event['type'] === 'DEx Purchase')
                {
                    //
                }
                else
                {
                    if (isset($omni_event['type']))
                    {
                        throw new ModuleError("`type_int` is not set in {$omni_event['txid']}, but there is `type`: {$omni_event['type']}");
                    }
                    else
                    {
                        throw new ModuleError("`type_int` is not set in {$omni_event['txid']}");
                    }
                }
            }

            if (isset($omni_event['amount']) && $omni_event['amount'] === 'ErrorAmount')
            {
                if ($omni_event['valid'])
                    throw new ModuleError("`ErrorAmount` on a valid transfer in {$omni_event['txid']}");

                $omni_event['amount'] = '0.00000000';
            }

            if (!isset($omni_event['type_int']) && isset($omni_event['type']) && $omni_event['type'] === 'DEx Purchase')
            {
                $ij = 0;

                foreach ($omni_event['purchases'] as $purchase)
                {
                    $events[] = [
                        'transaction' => $omni_event['txid'],
                        'address' => $purchase['referenceaddress'],
                        'sort_in_block' => $order_in_block[($omni_event['txid'])],
                        'sort_in_transaction' => $ij++,
                        'currency' => $purchase['propertyid'],
                        'effect' => (strstr($purchase['amountbought'], '.')) ? '-' . satoshi($purchase['amountbought']) : '-' . $purchase['amountbought'],
                        'failed' => !$purchase['valid'],
                        'extra' => '1026',
                    ];

                    $events[] = [
                        'transaction' => $omni_event['txid'],
                        'address' => $omni_event['sendingaddress'],
                        'sort_in_block' => $order_in_block[($omni_event['txid'])],
                        'sort_in_transaction' => $ij++,
                        'currency' => $purchase['propertyid'],
                        'effect' => (strstr($purchase['amountbought'], '.')) ? satoshi($purchase['amountbought']) : $purchase['amountbought'],
                        'failed' => !$purchase['valid'],
                        'extra' => '1026',
                    ];
                }
            }
            elseif ($omni_event['type_int'] === '0' && $omni_event['type'] === 'Simple Send') // Simple Send
            {
                $events[] = [
                    'transaction' => $omni_event['txid'],
                    'address' => $omni_event['sendingaddress'],
                    'sort_in_block' => $order_in_block[($omni_event['txid'])],
                    'sort_in_transaction' => 0,
                    'currency' => $omni_event['propertyid'],
                    'effect' => ($omni_event['divisible']) ? '-' . satoshi($omni_event['amount']) : '-' . $omni_event['amount'],
                    'failed' => !$omni_event['valid'],
                    'extra' => null,
                ];

                $events[] = [
                    'transaction' => $omni_event['txid'],
                    'address' => $omni_event['referenceaddress'],
                    'sort_in_block' => $order_in_block[($omni_event['txid'])],
                    'sort_in_transaction' => 1,
                    'currency' => $omni_event['propertyid'],
                    'effect' => ($omni_event['divisible']) ? satoshi($omni_event['amount']) : $omni_event['amount'],
                    'failed' => !$omni_event['valid'],
                    'extra' => null,
                ];
            }
            elseif ($omni_event['type_int'] === '0' && $omni_event['type'] === 'Crowdsale Purchase') // Crowdsale Purchase
            {
                if (!$omni_event['valid'])
                {
                    throw new ModuleError("Omni Type 0B: no logic for invalid transfers in {$omni_event['txid']}");
                }

                $omni_event['type_int'] = '1027';

                $events[] = [
                    'transaction' => $omni_event['txid'],
                    'address' => $omni_event['sendingaddress'],
                    'sort_in_block' => $order_in_block[($omni_event['txid'])],
                    'sort_in_transaction' => 0,
                    'currency' => $omni_event['propertyid'],
                    'effect' => ($omni_event['divisible']) ? '-' . satoshi($omni_event['amount']) : '-' . $omni_event['amount'],
                    'failed' => !$omni_event['valid'],
                    'extra' => $omni_event['type_int'],
                ];

                $events[] = [
                    'transaction' => $omni_event['txid'],
                    'address' => $omni_event['referenceaddress'],
                    'sort_in_block' => $order_in_block[($omni_event['txid'])],
                    'sort_in_transaction' => 1,
                    'currency' => $omni_event['propertyid'],
                    'effect' => ($omni_event['divisible']) ? satoshi($omni_event['amount']) : $omni_event['amount'],
                    'failed' => !$omni_event['valid'],
                    'extra' => $omni_event['type_int'],
                ];

                $i1 = ($omni_event['purchasedpropertydivisible']) ? satoshi($omni_event['purchasedtokens']) : $omni_event['purchasedtokens'];
                $i2 = ($omni_event['purchasedpropertydivisible']) ? satoshi($omni_event['issuertokens']) : $omni_event['issuertokens'];

                $is = bcadd($i1, $i2);

                $events[] = [
                    'transaction' => $omni_event['txid'],
                    'address' => 'the-void',
                    'sort_in_block' => $order_in_block[($omni_event['txid'])],
                    'sort_in_transaction' => 2,
                    'currency' => $omni_event['purchasedpropertyid'],
                    'effect' => '-' . $is,
                    'failed' => !$omni_event['valid'],
                    'extra' => $omni_event['type_int'],
                ];

                $events[] = [
                    'transaction' => $omni_event['txid'],
                    'address' => $omni_event['sendingaddress'],
                    'sort_in_block' => $order_in_block[($omni_event['txid'])],
                    'sort_in_transaction' => 3,
                    'currency' => $omni_event['purchasedpropertyid'],
                    'effect' => $i1,
                    'failed' => !$omni_event['valid'],
                    'extra' => $omni_event['type_int'],
                ];

                $events[] = [
                    'transaction' => $omni_event['txid'],
                    'address' => $omni_event['referenceaddress'],
                    'sort_in_block' => $order_in_block[($omni_event['txid'])],
                    'sort_in_transaction' => 4,
                    'currency' => $omni_event['purchasedpropertyid'],
                    'effect' => $i2,
                    'failed' => !$omni_event['valid'],
                    'extra' => $omni_event['type_int'],
                ];
            }
            elseif ($omni_event['type_int'] === '3') // Send All
            {
                if ($omni_event['valid'])
                {
                    $total_sending_amount = ($omni_event['divisible']) ? '-' . satoshi($omni_event['amount']) : '-' . $omni_event['amount'];
                    $total_receiving_amount = '0';

                    $events[] = [
                        'transaction' => $omni_event['txid'],
                        'address' => $omni_event['sendingaddress'],
                        'sort_in_block' => $order_in_block[($omni_event['txid'])],
                        'sort_in_transaction' => 0,
                        'currency' => $omni_event['propertyid'],
                        'effect' => $total_sending_amount,
                        'failed' => false,
                        'extra' => $omni_event['type_int'],
                    ];

                    $ij = 1;

                    $sub_transfers = requester_single($this->select_node(), params: ['method' => 'omni_getsto', 'params' => [$omni_event['txid'], '*']],
                        result_in: 'result', timeout: $this->timeout);

                    foreach ($sub_transfers['recipients'] as $recipient)
                    {
                        $receiving_amount = ($omni_event['divisible']) ? satoshi($recipient['amount']) : $recipient['amount'];
                        $total_receiving_amount = bcadd($total_receiving_amount, $receiving_amount);

                        $events[] = [
                            'transaction' => $omni_event['txid'],
                            'address' => $recipient['address'],
                            'sort_in_block' => $order_in_block[($omni_event['txid'])],
                            'sort_in_transaction' => $ij++,
                            'currency' => $omni_event['propertyid'],
                            'effect' => $receiving_amount,
                            'failed' => false,
                            'extra' => $omni_event['type_int'],
                        ];
                    }

                    if ('-' . $total_receiving_amount !== $total_sending_amount)
                    {
                        throw new ModuleError("Omni Type 3: total receiving amount is not equal to total sending amount: {$total_receiving_amount} !== {$total_sending_amount} in {$omni_event['txid']}");
                    }

                    if ((int)$omni_event['propertyid'] === 1 || ((int)$omni_event['propertyid'] >= 3 && (int)$omni_event['propertyid'] <= 2147483647))
                    {
                        $fee_currency = '1';
                    }
                    else
                    {
                        $fee_currency = '2';
                    }

                    $events[] = [
                        'transaction' => $omni_event['txid'],
                        'address' => $omni_event['sendingaddress'],
                        'sort_in_block' => $order_in_block[($omni_event['txid'])],
                        'sort_in_transaction' => $ij++,
                        'currency' => $fee_currency,
                        'effect' => '-' . satoshi($sub_transfers['totalstofee']),
                        'failed' => false,
                        'extra' => $omni_event['type_int'],
                    ];

                    $events[] = [
                        'transaction' => $omni_event['txid'],
                        'address' => 'the-void',
                        'sort_in_block' => $order_in_block[($omni_event['txid'])],
                        'sort_in_transaction' => $ij,
                        'currency' => $fee_currency,
                        'effect' => satoshi($sub_transfers['totalstofee']),
                        'failed' => false,
                        'extra' => $omni_event['type_int'],
                    ];
                }
                else
                {
                    $events[] = [
                        'transaction' => $omni_event['txid'],
                        'address' => $omni_event['sendingaddress'],
                        'sort_in_block' => $order_in_block[($omni_event['txid'])],
                        'sort_in_transaction' => 0,
                        'currency' => $omni_event['propertyid'],
                        'effect' => '-0',
                        'failed' => true,
                        'extra' => $omni_event['type_int'],
                    ];
                }
            }
            elseif ($omni_event['type_int'] === '4') // Send All
            {
                if ($omni_event['valid'])
                {
                    if (!isset($omni_event['subsends']) || !$omni_event['subsends'])
                    {
                        throw new ModuleError("Omni Type 4: is `valid`, but no `subsends` in {$omni_event['txid']}");
                    }

                    $ij = 0;

                    foreach ($omni_event['subsends'] as $subsend)
                    {
                        $events[] = [
                            'transaction' => $omni_event['txid'],
                            'address' => $omni_event['sendingaddress'],
                            'sort_in_block' => $order_in_block[($omni_event['txid'])],
                            'sort_in_transaction' => $ij++,
                            'currency' => $subsend['propertyid'],
                            'effect' => ($subsend['divisible']) ? '-' . satoshi($subsend['amount']) : '-' . $subsend['amount'],
                            'failed' => false,
                            'extra' => $omni_event['type_int'],
                        ];

                        $events[] = [
                            'transaction' => $omni_event['txid'],
                            'address' => $omni_event['referenceaddress'],
                            'sort_in_block' => $order_in_block[($omni_event['txid'])],
                            'sort_in_transaction' => $ij++,
                            'currency' => $subsend['propertyid'],
                            'effect' => ($subsend['divisible']) ? satoshi($subsend['amount']) : $subsend['amount'],
                            'failed' => false,
                            'extra' => $omni_event['type_int'],
                        ];
                    }
                }
                else
                {
                    if (isset($omni_event['subsends']))
                    {
                        throw new ModuleError("Omni Type 4: is not `valid`, but has `subsends` in {$omni_event['txid']}");
                    }

                    if (!isset($omni_event['invalidreason']) || ($omni_event['invalidreason'] !== 'Sender has no tokens to send'))
                    {
                        if ($block_id !== MEMPOOL)
                            throw new ModuleError("Omni Type 4: unknown reason for invalid transaction in {$omni_event['txid']}");
                    }

                    $events[] = [
                        'transaction' => $omni_event['txid'],
                        'address' => $omni_event['sendingaddress'],
                        'sort_in_block' => $order_in_block[($omni_event['txid'])],
                        'sort_in_transaction' => 0,
                        'currency' => null,
                        'effect' => '-0',
                        'failed' => true,
                        'extra' => $omni_event['type_int'],
                    ];

                    $events[] = [
                        'transaction' => $omni_event['txid'],
                        'address' => $omni_event['referenceaddress'],
                        'sort_in_block' => $order_in_block[($omni_event['txid'])],
                        'sort_in_transaction' => 1,
                        'currency' => null,
                        'effect' => '0',
                        'failed' => true,
                        'extra' => $omni_event['type_int'],
                    ];
                }
            }
            elseif (in_array($omni_event['type_int'], ['20', '22', '26', '27', '28', '70', '71', '72', '185', '186',
                                                       '65533', '65534', '65535', '53', '73', '74', '200', '201']))
            {
                $events[] = [
                    'transaction' => $omni_event['txid'],
                    'address' => $omni_event['sendingaddress'],
                    'sort_in_block' => $order_in_block[($omni_event['txid'])],
                    'sort_in_transaction' => 0,
                    'currency' => $omni_event['propertyid'] ?? $omni_event['propertyidforsale'] ?? null,
                    'effect' => '-0',
                    'failed' => !$omni_event['valid'],
                    'extra' => $omni_event['type_int'],
                ];

                if (isset($omni_event['referenceaddress']))
                {
                    $events[] = [
                        'transaction' => $omni_event['txid'],
                        'address' => $omni_event['referenceaddress'],
                        'sort_in_block' => $order_in_block[($omni_event['txid'])],
                        'sort_in_transaction' => 1,
                        'currency' => $omni_event['propertyid'] ?? $omni_event['propertyidforsale'] ?? null,
                        'effect' => '0',
                        'failed' => !$omni_event['valid'],
                        'extra' => $omni_event['type_int'],
                    ];
                }
            }
            elseif ($omni_event['type_int'] === '25')
            {
                $ij = 0;

                $events[] = [
                    'transaction' => $omni_event['txid'],
                    'address' => $omni_event['sendingaddress'],
                    'sort_in_block' => $order_in_block[($omni_event['txid'])],
                    'sort_in_transaction' => $ij++,
                    'currency' => $omni_event['propertyidforsale'],
                    'effect' => '-0',
                    'failed' => !$omni_event['valid'],
                    'extra' => $omni_event['type_int'] . 'A', // 25A
                ];

                $omni_event['type_int'] = $omni_event['type_int'] . 'B'; // 25B

                if ($omni_event['valid'])
                {
                    $trade = requester_single($this->select_node(), params: ['method' => 'omni_gettrade', 'params' => [$omni_event['txid']]],
                        result_in: 'result', timeout: $this->timeout);

                    if (!$trade['matches'])
                    {
                        //
                    }
                    else
                    {
                        foreach ($trade['matches'] as $match)
                        {
                            if (isset($order_in_block[($match['txid'])]) && $order_in_block[($match['txid'])] > $order_in_block[($omni_event['txid'])])
                            {
                                $in_the_past = false;
                            }
                            elseif (isset($order_in_block[($match['txid'])]))
                            {
                                $in_the_past = true;
                            }
                            else
                            {
                                $itx = requester_single($this->select_node(), params: ['method' => 'omni_gettransaction', 'params' => [$match['txid']]],
                                    result_in: 'result', timeout: $this->timeout);

                                if ((int)$itx['block'] < (int)$omni_event['block'])
                                {
                                    $in_the_past = true;
                                }
                                else
                                {
                                    $in_the_past = false;
                                }
                            }

                            if ($in_the_past)
                            {
                                $events[] = [
                                    'transaction' => $omni_event['txid'],
                                    'address' => $omni_event['sendingaddress'],
                                    'sort_in_block' => $order_in_block[($omni_event['txid'])],
                                    'sort_in_transaction' => $ij++,
                                    'currency' => $omni_event['propertyidforsale'],
                                    'effect' => ($omni_event['propertyidforsaleisdivisible']) ? '-' . satoshi($match['amountsold']) : '-' . $match['amountsold'],
                                    'failed' => false,
                                    'extra' => $omni_event['type_int'],
                                ];

                                $events[] = [
                                    'transaction' => $omni_event['txid'],
                                    'address' => $match['address'],
                                    'sort_in_block' => $order_in_block[($omni_event['txid'])],
                                    'sort_in_transaction' => $ij++,
                                    'currency' => $omni_event['propertyidforsale'],
                                    'effect' => ($omni_event['propertyidforsaleisdivisible']) ? satoshi($match['amountsold']) : $match['amountsold'],
                                    'failed' => false,
                                    'extra' => $omni_event['type_int'],
                                ];

                                $events[] = [
                                    'transaction' => $omni_event['txid'],
                                    'address' => $match['address'],
                                    'sort_in_block' => $order_in_block[($omni_event['txid'])],
                                    'sort_in_transaction' => $ij++,
                                    'currency' => $omni_event['propertyiddesired'],
                                    'effect' => ($omni_event['propertyiddesiredisdivisible']) ? '-' . satoshi($match['amountreceived']) : '-' . $match['amountreceived'],
                                    'failed' => false,
                                    'extra' => $omni_event['type_int'],
                                ];

                                $events[] = [
                                    'transaction' => $omni_event['txid'],
                                    'address' => $omni_event['sendingaddress'],
                                    'sort_in_block' => $order_in_block[($omni_event['txid'])],
                                    'sort_in_transaction' => $ij++,
                                    'currency' => $omni_event['propertyiddesired'],
                                    'effect' => ($omni_event['propertyiddesiredisdivisible']) ? satoshi($match['amountreceived']) : $match['amountreceived'],
                                    'failed' => false,
                                    'extra' => $omni_event['type_int'],
                                ];
                            }
                        }
                    }
                }
            }
            elseif ($omni_event['type_int'] === '50') // Create Property - Fixed
            {
                if ($omni_event['valid'])
                {
                    $events[] = [
                        'transaction' => $omni_event['txid'],
                        'address' => 'the-void',
                        'sort_in_block' => $order_in_block[($omni_event['txid'])],
                        'sort_in_transaction' => 0,
                        'currency' => $omni_event['propertyid'],
                        'effect' => ($omni_event['divisible']) ? '-' . satoshi($omni_event['amount']) : '-' . $omni_event['amount'],
                        'failed' => false,
                        'extra' => $omni_event['type_int'],
                    ];

                    $events[] = [
                        'transaction' => $omni_event['txid'],
                        'address' => $omni_event['sendingaddress'],
                        'sort_in_block' => $order_in_block[($omni_event['txid'])],
                        'sort_in_transaction' => 1,
                        'currency' => $omni_event['propertyid'],
                        'effect' => ($omni_event['divisible']) ? satoshi($omni_event['amount']) : $omni_event['amount'],
                        'failed' => false,
                        'extra' => $omni_event['type_int'],
                    ];
                }
                else
                {
                    $events[] = [
                        'transaction' => $omni_event['txid'],
                        'address' => $omni_event['sendingaddress'],
                        'sort_in_block' => $order_in_block[($omni_event['txid'])],
                        'sort_in_transaction' => 0,
                        'currency' => null,
                        'effect' => '-0',
                        'failed' => true,
                        'extra' => $omni_event['type_int'],
                    ];
                }
            }
            elseif (in_array($omni_event['type_int'], ['51', '54'])) // Create Property - Variable (Crowdsale), Create Property - Manual
            {
                if (!$omni_event['valid'] && !isset($omni_event['propertyid']))
                {
                    $omni_event['propertyid'] = null;
                }

                $events[] = [
                    'transaction' => $omni_event['txid'],
                    'address' => 'the-void',
                    'sort_in_block' => $order_in_block[($omni_event['txid'])],
                    'sort_in_transaction' => 0,
                    'currency' => $omni_event['propertyid'],
                    'effect' => '-0',
                    'failed' => !$omni_event['valid'],
                    'extra' => $omni_event['type_int'],
                ];

                $events[] = [
                    'transaction' => $omni_event['txid'],
                    'address' => $omni_event['sendingaddress'],
                    'sort_in_block' => $order_in_block[($omni_event['txid'])],
                    'sort_in_transaction' => 1,
                    'currency' => $omni_event['propertyid'],
                    'effect' => '0',
                    'failed' => !$omni_event['valid'],
                    'extra' => $omni_event['type_int'],
                ];
            }
            elseif ($omni_event['type_int'] === '55') // Grant Property Tokens
            {
                $events[] = [
                    'transaction' => $omni_event['txid'],
                    'address' => 'the-void',
                    'sort_in_block' => $order_in_block[($omni_event['txid'])],
                    'sort_in_transaction' => 0,
                    'currency' => $omni_event['propertyid'],
                    'effect' => ($omni_event['divisible']) ? '-' . satoshi($omni_event['amount']) : '-' . $omni_event['amount'],
                    'failed' => !$omni_event['valid'],
                    'extra' => $omni_event['type_int'],
                ];

                $events[] = [
                    'transaction' => $omni_event['txid'],
                    'address' => $omni_event['sendingaddress'],
                    'sort_in_block' => $order_in_block[($omni_event['txid'])],
                    'sort_in_transaction' => 1,
                    'currency' => $omni_event['propertyid'],
                    'effect' => ($omni_event['divisible']) ? satoshi($omni_event['amount']) : $omni_event['amount'],
                    'failed' => !$omni_event['valid'],
                    'extra' => $omni_event['type_int'],
                ];

                $events[] = [
                    'transaction' => $omni_event['txid'],
                    'address' => $omni_event['sendingaddress'],
                    'sort_in_block' => $order_in_block[($omni_event['txid'])],
                    'sort_in_transaction' => 2,
                    'currency' => $omni_event['propertyid'],
                    'effect' => ($omni_event['divisible']) ? '-' . satoshi($omni_event['amount']) : '-' . $omni_event['amount'],
                    'failed' => !$omni_event['valid'],
                    'extra' => $omni_event['type_int'],
                ];

                $events[] = [
                    'transaction' => $omni_event['txid'],
                    'address' => $omni_event['referenceaddress'],
                    'sort_in_block' => $order_in_block[($omni_event['txid'])],
                    'sort_in_transaction' => 3,
                    'currency' => $omni_event['propertyid'],
                    'effect' => ($omni_event['divisible']) ? satoshi($omni_event['amount']) : $omni_event['amount'],
                    'failed' => !$omni_event['valid'],
                    'extra' => $omni_event['type_int'],
                ];
            }
            elseif ($omni_event['type_int'] === '56') // Revoke Property Tokens
            {
                $events[] = [
                    'transaction' => $omni_event['txid'],
                    'address' => $omni_event['sendingaddress'],
                    'sort_in_block' => $order_in_block[($omni_event['txid'])],
                    'sort_in_transaction' => 0,
                    'currency' => $omni_event['propertyid'],
                    'effect' => ($omni_event['divisible']) ? '-' . satoshi($omni_event['amount']) : '-' . $omni_event['amount'],
                    'failed' => !$omni_event['valid'],
                    'extra' => $omni_event['type_int'],
                ];

                $events[] = [
                    'transaction' => $omni_event['txid'],
                    'address' => 'the-void',
                    'sort_in_block' => $order_in_block[($omni_event['txid'])],
                    'sort_in_transaction' => 1,
                    'currency' => $omni_event['propertyid'],
                    'effect' => ($omni_event['divisible']) ? satoshi($omni_event['amount']) : $omni_event['amount'],
                    'failed' => !$omni_event['valid'],
                    'extra' => $omni_event['type_int'],
                ];
            }
            else
            {
                if (isset($omni_event['type_int']))
                {
                    throw new ModuleError("{$omni_event['type_int']} is not a supported type in {$omni_event['txid']}");
                }
                else
                {
                    throw new ModuleError("`type_int` is not set in {$omni_event['txid']}");
                }
            }
        }

        // Phew!

        // Special case for genesis transfers

        if ($block_id === $this->genesis_block)
        {
            if ($events) throw new DeveloperError('Genesis block has other events');

            $genesis_info = json_decode(file_get_contents(__DIR__ . '/../Genesis/' . $this->genesis_json), true);

            $genesis_sort = 0;

            foreach ($genesis_info as $address => $balances)
            {
                foreach ($balances as $currency => $balance)
                {
                    $events[] = [
                        'transaction' => null,
                        'address' => 'the-void',
                        'sort_in_block' => 0,
                        'sort_in_transaction' => $genesis_sort++,
                        'currency' => (string)$currency,
                        'effect' => '-' . $balance,
                        'failed' => false,
                        'extra' => '1025',
                    ];

                    $events[] = [
                        'transaction' => null,
                        'address' => $address,
                        'sort_in_block' => 0,
                        'sort_in_transaction' => $genesis_sort++,
                        'currency' => (string)$currency,
                        'effect' => $balance,
                        'failed' => false,
                        'extra' => '1025',
                    ];
                }
            }
        }

        if (!$events)
        {
            $this->set_return_events([]);
            if ($block_id !== MEMPOOL) $this->set_return_currencies([]);
            return;
        }

        // Sort

        if ($this->block_id !== MEMPOOL)
        {
            usort($events, function($a, $b) {
                return  [$a['sort_in_block'],
                         $a['sort_in_transaction'],
                    ]
                    <=>
                    [$b['sort_in_block'],
                     $b['sort_in_transaction'],
                    ];
            });
        }
        else
        {
            usort($events, function($a, $b) {
                return  [$a['transaction'],
                         $a['sort_in_transaction'],
                    ]
                    <=>
                    [$b['transaction'],
                     $b['sort_in_transaction'],
                    ];
            });
        }

        //

        if (isset($this->block_time) || isset($omni_event['blocktime']))
        {
            $timestamp = $this->block_time ?? date('Y-m-d H:i:s', (int)$omni_event['blocktime']);
        }
        else
        {
            throw new ModuleError('Timestamp is not set');
        }

        $sort_key = 0;
        $latest_tx_hash = ''; // For mempool

        foreach ($events as &$event)
        {
            if ($this->block_id === MEMPOOL && $event['transaction'] !== $latest_tx_hash)
            {
                $latest_tx_hash = $event['transaction'];
                $sort_key = 0;
            }

            $event['block'] = $block_id;
            $event['time'] = $timestamp;

            $event['sort_key'] = $sort_key++;
            unset($event['sort_in_block']);
            unset($event['sort_in_transaction']);
        }

        unset($event);

        $this->set_return_events($events);

        if ($block_id === MEMPOOL)
            return; // When processing mempool, we don't want to bother with currency data yet

        // Processing currencies...

        $currencies_to_process = [];

        foreach ($events as $event)
        {
            if (!is_null($event['currency']))
                $currencies_to_process[] = $event['currency'];
        }

        // Removing duplicates and already known currencies
        $currencies_to_process = array_unique($currencies_to_process);
        $currencies_to_process = check_existing_currencies($currencies_to_process, $this->currency_format);

        $multi_curl = [];
        $currency_data = [];

        // Getting data from the node

        foreach ($currencies_to_process as $currency_id)
        {
            $multi_curl[] = requester_multi_prepare($this->select_node(),
                params: ['method' => 'omni_getproperty', 'params' => [(int)$currency_id]],
                timeout: $this->timeout);
        }

        $curl_results = requester_multi($multi_curl,
            limit: envm($this->module, 'REQUESTER_THREADS'),
            timeout: $this->timeout,
            valid_codes: [0, 200, 500]);

        foreach ($curl_results as $v)
        {
            try
            {
                $currency_data[] = requester_multi_process($v, result_in: 'result');
            }
            catch (RequesterException)
            {
                if (!str_contains($v, 'Property identifier does not exist') && !str_contains($v, 'Property identifier is out of range'))
                {
                    throw new RequesterException('requester_multi_process(output:(scrapped), result_in:(result)) failed: no result key');
                }
            }
        }

        $currencies = [];

        foreach ($currency_data as $currency)
        {
            $currencies[] = [
                'id' => (int)$currency['propertyid'],
                'name' => $currency['name'],
                'description' => "Category: {$currency['category']}\nSubcategory: {$currency['subcategory']}\nData: {$currency['data']}\nURL: {$currency['url']}",
                'decimals' => ($currency['divisible']) ? 8 : 0, // It's always either 8 or 0 in Omni
            ];
        }

        $this->set_return_currencies($currencies);
    }
}
